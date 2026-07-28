<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Traffic — front-end page view capture.
 *
 * Parallel to AM_Sessions rather than an AM_Logger_Base subclass: page
 * traffic isn't an audit event (it doesn't go through AM_Event_Writer
 * or the am_events table), has a completely different write volume,
 * and needs a front-end hook rather than an admin-side WP action.
 *
 * Hooks template_redirect, which fires on every front-end request after
 * WP has resolved the query but before template loading -- it already
 * excludes wp-admin, AJAX, REST, and cron requests, so no separate
 * is_admin() check is needed. Deliberately does NOT hook something
 * earlier (like 'init') to avoid firing on requests that never resolve
 * to an actual page.
 *
 * Per explicit decision: logs all front-end visitors (including logged-in
 * users) with no exclusion by role. The only filtering is bot user-agent
 * sniffing, which is a data-quality measure (bots would otherwise flood
 * the table with non-visitor hits), not a "who counts" policy decision.
 */
class AM_Traffic {

	/**
	 * Common bot/crawler substrings. Not exhaustive (no user-agent list
	 * ever is) -- this catches the high-volume, well-behaved crawlers
	 * that would otherwise dominate the traffic log. Filterable via
	 * am_traffic_bot_patterns for sites that need to add/remove entries.
	 */
	const DEFAULT_BOT_PATTERNS = array(
		'bot', 'spider', 'crawl', 'slurp', 'facebookexternalhit',
		'pingdom', 'uptimerobot', 'ahrefs', 'semrush', 'mj12bot',
		'yandex', 'baiduspider',
	);

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'capture' ) );
	}

	public static function capture() {
		if ( '1' !== get_option( 'am_traffic_enabled', '1' ) ) {
			return;
		}

		// Skip feeds, sitemaps rendered through WP, and anything already
		// a 404 -- a page that doesn't exist isn't "traffic" in the
		// sense the top-pages report cares about, and would otherwise
		// clutter it with typo'd/scanned URLs.
		if ( is_feed() || is_404() ) {
			return;
		}

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( self::is_bot( $user_agent ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . AM_Traffic_Schema::LOG_TABLE;

		$url      = self::current_url();
		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, not a form submission.

		// wp_get_document_title() reflects whatever WordPress core (and
		// any theme/plugin hooking the standard document_title_parts /
		// pre_get_document_title filters) resolved the title to be, at
		// the actual moment of this visit -- not a title looked up
		// afterward from the URL, which could only ever reconstruct a
		// post's raw stored title, not whatever the page actually
		// displayed. Known limitation, by explicit choice: some SEO
		// plugins (Yoast and others) build the final <title> tag later,
		// via their own separate mechanism, bypassing WP core's title
		// filters entirely -- in that case this captures WP core's
		// title, not that plugin's overridden one. Capturing the
		// literal rendered <title> tag regardless of how it was built
		// would require buffering page output, which was deliberately
		// not the approach taken here.
		$page_title = wp_get_document_title();

		$wpdb->insert( $table, array(
			'date'       => current_time( 'mysql', true ),
			'url'        => $url,
			'url_hash'   => md5( $url ),
			'page_title' => mb_substr( $page_title, 0, 500 ),
			'referrer'   => $referrer,
			'ip_address' => AM_DB_Legacy_IP::resolve(),
			'user_agent' => mb_substr( $user_agent, 0, 255 ),
			'user_id'    => get_current_user_id(),
		) );
	}

	/**
	 * The path being viewed, without scheme/host or query string --
	 * query strings (utm_*, session params, etc.) would otherwise
	 * fragment "top pages" into many near-duplicate rows for what's
	 * really the same page.
	 */
	private static function current_url(): string {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '/'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
		$path = is_string( $path ) && $path !== '' ? $path : '/';
		return sanitize_text_field( $path );
	}

	private static function is_bot( string $user_agent ): bool {
		if ( '' === $user_agent ) {
			// Most real browsers send a UA; treat an empty one as
			// suspicious/non-visitor traffic rather than a real page view.
			return true;
		}

		$patterns = apply_filters( 'am_traffic_bot_patterns', self::DEFAULT_BOT_PATTERNS );
		$ua_lower = strtolower( $user_agent );
		foreach ( $patterns as $pattern ) {
			if ( false !== strpos( $ua_lower, $pattern ) ) {
				return true;
			}
		}
		return false;
	}
}
