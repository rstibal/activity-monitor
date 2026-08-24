<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Stats_Tracker — records pageviews via a front-end JS beacon.
 *
 * Deliberately not a template_redirect/wp hook: most WordPress sites run a
 * page cache, so a PHP-side hook only fires on cache misses and undercounts
 * badly. The beacon fires from the browser on every real pageview instead,
 * POSTing to admin-ajax.php -- which always executes fresh PHP even when the
 * page itself was served from cache -- so bot/role filtering happens there,
 * on every hit, rather than at enqueue time where caching would make it
 * unreliable.
 *
 * Uses the existing wp_ajax_ / wp_ajax_nopriv_ + admin-ajax.php convention
 * this plugin already has (see AM_Admin's am_ajax nonce), rather than
 * introducing a REST route -- there is no register_rest_route() precedent
 * anywhere in this codebase.
 */
class AM_Stats_Tracker {

	const NONCE_ACTION = 'am_stats_track';

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_beacon' ) );
		add_action( 'wp_ajax_am_stats_track', array( __CLASS__, 'handle_track' ) );
		add_action( 'wp_ajax_nopriv_am_stats_track', array( __CLASS__, 'handle_track' ) );
	}

	/**
	 * Enqueued unconditionally on the front end. Whether a given hit ends
	 * up recorded is decided server-side in handle_track(), which always
	 * runs fresh PHP even on a cached page -- see class doc.
	 */
	public static function enqueue_beacon() {
		if ( is_admin() ) {
			return;
		}
		wp_enqueue_script( 'am-stats-beacon', AM_URL . 'assets/js/stats-beacon.js', array(), AM_VERSION, true );
		wp_localize_script( 'am-stats-beacon', 'amStatsData', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
		) );
	}

	public static function handle_track() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! (bool) get_option( 'am_stats_enable_tracking', 1 ) ) {
			wp_send_json_success();
		}

		$user_id = get_current_user_id();
		if ( $user_id && self::is_excluded_user( $user_id ) ) {
			wp_send_json_success();
		}

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		if ( AM_Stats_UA_Parser::is_bot( $user_agent ) ) {
			wp_send_json_success();
		}

		$url   = self::sanitize_path( wp_unslash( $_POST['url'] ?? '' ) );
		$title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		if ( '' === $url ) {
			wp_send_json_success();
		}

		$referrer_host = '';
		$referrer      = esc_url_raw( wp_unslash( $_POST['referrer'] ?? '' ) );
		if ( '' !== $referrer ) {
			$host = wp_parse_url( $referrer, PHP_URL_HOST );
			// Don't record the site's own host as a "referrer" -- that's
			// just internal navigation, not an incoming referral.
			if ( $host && $host !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
				$referrer_host = $host;
			}
		}

		$ua_parts    = AM_Stats_UA_Parser::parse( $user_agent );
		$visitor_hash = self::visitor_hash( $user_agent );

		global $wpdb;
		$url_id = self::get_or_create_url_id( $url, $title );

		$hits_table = $wpdb->prefix . AM_Stats_Schema::HITS_TABLE;
		$wpdb->insert( $hits_table, array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			'date'          => current_time( 'mysql', true ),
			'visitor_hash'  => $visitor_hash,
			'url_id'        => $url_id,
			'referrer_host' => $referrer_host,
			'browser'       => $ua_parts['browser'],
			'os'            => $ua_parts['os'],
			'device_type'   => $ua_parts['device_type'],
			'user_id'       => $user_id,
		) );

		self::upsert_visitor( $visitor_hash );

		wp_send_json_success();
	}

	/** Strips scheme/host/query, keeping just the path -- never store a full URL with query string. */
	private static function sanitize_path( string $raw ): string {
		$path = (string) wp_parse_url( $raw, PHP_URL_PATH );
		$path = sanitize_text_field( $path );
		return substr( $path, 0, 255 );
	}

	/**
	 * Cookieless, daily-rotating visitor identity: hash(ip . ua . day . salt).
	 * No cookie, so no consent-banner obligation.
	 *
	 * Respects am_ip_storage the same way AM_Event_Writer does for the audit
	 * log: 'none' means the site owner opted out of IP use entirely, so the
	 * IP is dropped from the hash input rather than getting a second,
	 * stats-only privacy toggle. The IP itself is never persisted either
	 * way -- only this one-way hash is stored.
	 */
	private static function visitor_hash( string $user_agent ): string {
		$ip_storage = (string) get_option( 'am_ip_storage', 'full' );
		$ip         = 'none' === $ip_storage ? '' : AM_DB_Legacy_IP::resolve();

		return md5( $ip . '|' . $user_agent . '|' . gmdate( 'Ymd' ) . '|' . wp_salt() );
	}

	private static function get_or_create_url_id( string $url, string $title ): int {
		global $wpdb;
		$table    = $wpdb->prefix . AM_Stats_Schema::URLS_TABLE;
		$url_hash = md5( $url );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE url_hash = %s", $url_hash ) );
		if ( $id ) {
			// Title can change (post edited, etc.) -- keep it current.
			if ( '' !== $title ) {
				$wpdb->update( $table, array( 'title' => $title ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			}
			return (int) $id;
		}

		$wpdb->insert( $table, array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			'url_hash' => $url_hash,
			'url'      => $url,
			'title'    => $title,
		) );
		return (int) $wpdb->insert_id;
	}

	private static function upsert_visitor( string $visitor_hash ) {
		global $wpdb;
		$table = $wpdb->prefix . AM_Stats_Schema::VISITORS_TABLE;
		$now   = current_time( 'mysql', true );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO `{$table}` (visitor_hash, first_seen, last_seen, visit_count)
			 VALUES (%s, %s, %s, 1)
			 ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen), visit_count = visit_count + 1",
			$visitor_hash,
			$now,
			$now
		) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function is_excluded_user( int $user_id ): bool {
		$excluded = (array) get_option( 'am_stats_exclude_roles', array( 'administrator' ) );
		if ( empty( $excluded ) ) {
			return false;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		return (bool) array_intersect( $excluded, (array) $user->roles );
	}
}
