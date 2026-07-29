<?php
/**
 * AM_Admin – registers menus, renders all tabbed UI, handles form actions.
 *
 * Single top-level menu page with three tabs:
 *   1. Activity Log
 *   2. Active Sessions
 *   3. Settings  (notifications + general settings + clear-log)
 *
 * @package ActivityMonitor
 * @version 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AM_Admin {

	const TAB_PARAM = 'am_tab';

	// ── Bootstrap ──────────────────────────────────────────────────────

	public static function init() {
		$instance = new self();

		add_action( 'admin_menu',                             array( $instance, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts',                  array( $instance, 'enqueue_assets' ) );
		add_action( 'admin_init',                             array( $instance, 'register_settings' ) );
		add_action( 'admin_post_am_clear_log',                array( $instance, 'handle_clear_log' ) );
		add_action( 'admin_post_am_revoke_session',           array( $instance, 'handle_revoke_session' ) );
		add_action( 'admin_post_am_revoke_expired',           array( $instance, 'handle_revoke_expired' ) );
		add_action( 'admin_post_am_emergency_lockdown',       array( $instance, 'handle_emergency_lockdown' ) );
		add_action( 'admin_post_am_save_session_settings',    array( $instance, 'handle_save_session_settings' ) );
		add_action( 'admin_post_am_save_traffic_settings',    array( $instance, 'handle_save_traffic_settings' ) );
		add_action( 'admin_post_am_save_display_settings',    array( $instance, 'handle_save_display_settings' ) );
		add_action( 'admin_post_am_export_log',               array( $instance, 'handle_export' ) );
		add_action( 'admin_notices',                          array( $instance, 'show_notices' ) );
		add_action( 'wp_ajax_am_get_v2_event_detail',         array( $instance, 'ajax_v2_event_detail' ) );
		add_action( 'wp_ajax_am_digest_preview',              array( $instance, 'ajax_digest_preview' ) );
		add_action( 'wp_ajax_am_digest_send_test',            array( $instance, 'ajax_digest_send_test' ) );
		add_action( 'wp_ajax_am_digest_config_form',          array( $instance, 'ajax_digest_config_form' ) );
		add_action( 'wp_ajax_am_save_digest_config',          array( $instance, 'ajax_save_digest_config' ) );
		add_action( 'wp_ajax_am_delete_digest_config',        array( $instance, 'ajax_delete_digest_config' ) );
		add_action( 'wp_ajax_am_get_session_detail',          array( $instance, 'ajax_session_detail' ) );
		add_action( 'wp_ajax_am_get_live_traffic',            array( $instance, 'ajax_live_traffic' ) );
		add_action( 'wp_ajax_am_ip_lookup',                   array( $instance, 'ajax_ip_lookup' ) );
		add_action( 'wp_ajax_am_user_profile',                array( $instance, 'ajax_user_profile' ) );
		add_action( 'wp_ajax_am_traffic_hit_detail',          array( $instance, 'ajax_traffic_hit_detail' ) );
		add_action( 'wp_ajax_am_channel_form',                array( $instance, 'ajax_channel_form' ) );
		add_action( 'wp_ajax_am_save_channel',                array( $instance, 'ajax_save_channel' ) );
		add_action( 'wp_ajax_am_delete_channel',              array( $instance, 'ajax_delete_channel' ) );
	}

	// ── Menu ───────────────────────────────────────────────────────────

	public function register_menu() {
		add_menu_page(
			__( 'Activity Monitor', 'activity-monitor' ),
			__( 'Activity Monitor', 'activity-monitor' ),
			'manage_options',
			'activity-monitor',
			array( $this, 'render_page' ),
			'dashicons-shield-alt',
			2
		);
		add_submenu_page(
			'activity-monitor',
			__( 'Activity Monitor', 'activity-monitor' ),
			__( 'Activity Monitor', 'activity-monitor' ),
			'manage_options',
			'activity-monitor',
			array( $this, 'render_page' )
		);
	}

	// ── Assets ─────────────────────────────────────────────────────────

	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_activity-monitor' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'am-admin', AM_URL . 'assets/css/admin.css', array(), AM_VERSION );
		wp_enqueue_script( 'am-admin', AM_URL . 'assets/js/admin.js', array( 'jquery' ), AM_VERSION, true );
		wp_localize_script( 'am-admin', 'amData', array(
			'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
			'nonce'                => wp_create_nonce( 'am_ajax' ),
			'trafficLivePollMs'    => absint( get_option( 'am_traffic_live_poll_seconds', 10 ) ) * 1000,
			'trafficLiveFeedLimit' => absint( get_option( 'am_traffic_live_feed_limit', 25 ) ),
		) );
	}

	// ── Settings registration ────────────────────────────────────────────

	public function register_settings() {
		register_setting( 'am_notifications_group', 'am_notification_channels', array(
			'sanitize_callback' => array( $this, 'sanitize_channels' ),
			'default'           => array(),
		) );
	}

	public function sanitize_channels( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}
		$clean = array();
		foreach ( $input as $ch ) {
			$one = self::sanitize_one_channel( $ch );
			if ( null !== $one ) {
				$clean[] = $one;
			}
		}
		return $clean;
	}

	/**
	 * Validates and sanitizes a single channel's raw form data. Shared by
	 * sanitize_channels() (the whole-array options.php path, kept for
	 * back-compat) and ajax_save_channel() (the per-channel modal save
	 * path) so the validation rules only live in one place. Returns
	 * null for an invalid/unrecognized channel (unknown type, or a
	 * Slack webhook that doesn't point at hooks.slack.com).
	 */
	private static function sanitize_one_channel( array $ch ): ?array {
		$type = sanitize_key( $ch['type'] ?? '' );
		if ( ! in_array( $type, array( 'email', 'slack' ), true ) ) {
			return null;
		}

		$level = isset( $ch['level'] ) ? sanitize_key( $ch['level'] ) : AM_Log_Levels::CRITICAL;
		if ( ! AM_Log_Levels::is_valid( $level ) ) {
			$level = AM_Log_Levels::CRITICAL;
		}

		$name = sanitize_text_field( $ch['name'] ?? '' );

		if ( 'slack' === $type ) {
			$webhook_url = esc_url_raw( trim( $ch['webhook_url'] ?? '' ) );
			// Slack incoming webhooks are always posted to
			// hooks.slack.com -- validating the host catches a
			// pasted-wrong-URL mistake (a very plausible error for
			// a person copying from Slack's app setup page) rather
			// than silently accepting and later failing to POST
			// anywhere useful. Not a security boundary (this form
			// is already manage_options-gated) -- just a sanity
			// check on the one value this channel actually needs.
			$host = wp_parse_url( $webhook_url, PHP_URL_HOST );
			if ( 'hooks.slack.com' !== $host ) {
				return null;
			}
			return array(
				'type'        => 'slack',
				'name'        => $name,
				'level'       => $level,
				'webhook_url' => $webhook_url,
			);
		}

		$emails = array_filter( array_map( 'trim', explode( ',', $ch['recipients'] ?? '' ) ) );

		return array(
			'type'       => 'email',
			'name'       => $name,
			'level'      => $level,
			'recipients' => implode( ', ', array_filter( $emails, 'is_email' ) ),
		);
	}

	// ── Admin notices ────────────────────────────────────────────────────

	public function show_notices() {
		$screen = get_current_screen();
		if ( ! $screen || 'toplevel_page_activity-monitor' !== $screen->id ) {
			return;
		}
		if ( isset( $_GET['am_cleared'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Activity log cleared.', 'activity-monitor' ) . '</p></div>';
		}
		if ( isset( $_GET['am_revoked'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Session revoked.', 'activity-monitor' ) . '</p></div>';
		}
		if ( isset( $_GET['am_expired_revoked'] ) ) {
			$count = absint( $_GET['am_expired_revoked'] );
			echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(
				esc_html( _n( '%d expired session revoked.', '%d expired sessions revoked.', $count, 'activity-monitor' ) ),
				$count
			) . '</p></div>';
		}
		if ( isset( $_GET['am_lockdown'] ) ) {
			$count = absint( $_GET['am_lockdown'] );
			echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(
				esc_html( _n( 'Emergency lockdown complete: %d session terminated.', 'Emergency lockdown complete: %d sessions terminated.', $count, 'activity-monitor' ) ),
				$count
			) . '</p></div>';
		}
		if ( isset( $_GET['am_session_settings_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Session settings saved.', 'activity-monitor' ) . '</p></div>';
		}
		if ( isset( $_GET['am_display_settings_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Display settings saved.', 'activity-monitor' ) . '</p></div>';
		}
		if ( isset( $_GET['am_traffic_settings_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Traffic settings saved.', 'activity-monitor' ) . '</p></div>';
		}
	}

	// ── Action handlers ──────────────────────────────────────────────────

	public function handle_clear_log() {
		check_admin_referer( 'am_clear_log' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'activity-monitor' ) );
		}

		// Clears the v2.0 am_events/am_event_context tables -- the only
		// visible log now that AM_DB and the legacy "Activity Log" tab
		// are both fully retired. (Previously this also called
		// AM_DB::clear_all() on the legacy table; that class no longer
		// exists as of full legacy retirement.)
		AM_Schema::clear_all();

		AM_Event_Writer::log(
			'log',
			'cleared',
			__( 'Activity log was cleared by an administrator.', 'activity-monitor' ),
			array(
				'level'       => AM_Log_Levels::WARNING,
				'object_type' => 'log',
				'object_name' => 'activity-log',
				'group'       => false,
			)
		);

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'activity-monitor', self::TAB_PARAM => 'settings', 'am_cleared' => '1' ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	public function handle_revoke_session() {
		check_admin_referer( 'am_revoke_session' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'activity-monitor' ) );
		}

		$user_id    = absint( $_POST['session_user_id'] ?? 0 );
		$token_hash = sanitize_text_field( wp_unslash( $_POST['session_token_hash'] ?? '' ) );

		if ( $user_id && $token_hash ) {
			$current_token_hash = hash( 'sha256', wp_get_session_token() );
			$is_own_session     = ( $user_id === get_current_user_id() && hash_equals( $current_token_hash, $token_hash ) );

			if ( ! $is_own_session ) {
				$sessions = get_user_meta( $user_id, 'session_tokens', true );
				if ( is_array( $sessions ) && isset( $sessions[ $token_hash ] ) ) {
					unset( $sessions[ $token_hash ] );
					AM_Sessions::update_session_meta_quietly( $user_id, $sessions );

					$user = get_userdata( $user_id );
					AM_Event_Writer::log(
						'session',
						'revoked',
						sprintf(
							/* translators: 1: username, 2: first 12 chars of the session token hash */
							__( 'Session revoked for user "%1$s" (token: %2$s…).', 'activity-monitor' ),
							$user ? $user->user_login : $user_id,
							substr( $token_hash, 0, 12 )
						),
						array(
							'level'       => AM_Log_Levels::WARNING,
							'object_type' => 'user',
							'object_id'   => $user_id,
							'object_name' => $user ? $user->user_login : 'user-' . $user_id,
							'group'       => false,
						)
					);
				}
			}
		}

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'activity-monitor', self::TAB_PARAM => 'sessions', 'am_revoked' => '1' ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	public function handle_revoke_expired() {
		check_admin_referer( 'am_revoke_expired' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'activity-monitor' ) );
		}

		$now   = time();
		$count = 0;
		$users = get_users( array( 'fields' => array( 'ID', 'user_login' ) ) );

		foreach ( $users as $user ) {
			$sessions = get_user_meta( $user->ID, 'session_tokens', true );
			if ( ! is_array( $sessions ) || empty( $sessions ) ) {
				continue;
			}
			$updated = false;
			foreach ( $sessions as $token_hash => $session ) {
				$expiration = isset( $session['expiration'] ) ? (int) $session['expiration'] : 0;
				if ( $expiration > 0 && $expiration < $now ) {
					unset( $sessions[ $token_hash ] );
					$count++;
					$updated = true;
				}
			}
			if ( $updated ) {
				AM_Sessions::update_session_meta_quietly( $user->ID, $sessions );
			}
		}

		if ( $count > 0 ) {
			AM_Event_Writer::log(
				'session',
				'expired_revoked',
				sprintf(
					/* translators: %d: number of expired sessions revoked */
					_n( '%d expired session revoked across all users.', '%d expired sessions revoked across all users.', $count, 'activity-monitor' ),
					$count
				),
				array(
					'level'       => AM_Log_Levels::WARNING,
					'object_type' => 'user',
					'object_name' => 'all-users',
					'context'     => array( 'count' => $count ),
					'group'       => false,
				)
			);
		}

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'activity-monitor', self::TAB_PARAM => 'settings', 'am_expired_revoked' => $count ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * v2.0 (spec §5, issue #5): terminate every session except the
	 * caller's own. Confirmation happens client-side (the onsubmit
	 * confirm() dialog in render_tab_settings) before this ever runs --
	 * this handler does not prompt again, it acts immediately once called.
	 */
	public function handle_emergency_lockdown() {
		check_admin_referer( 'am_emergency_lockdown' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'activity-monitor' ) );
		}

		$count = AM_Sessions::emergency_lockdown();

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'activity-monitor', self::TAB_PARAM => 'settings', 'am_lockdown' => $count ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	public function handle_save_session_settings() {
		check_admin_referer( 'am_save_session_settings' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'activity-monitor' ) );
		}

		update_option( 'am_session_concurrent_limit', absint( $_POST['am_session_concurrent_limit'] ?? 0 ) );
		update_option( 'am_session_active_threshold_minutes', max( 1, absint( $_POST['am_session_active_threshold_minutes'] ?? 30 ) ) );

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'activity-monitor', self::TAB_PARAM => 'settings', 'am_session_settings_saved' => '1' ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	public function handle_save_display_settings() {
		check_admin_referer( 'am_save_display_settings' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'activity-monitor' ) );
		}

		// Whitelist against the known presets rather than storing whatever
		// was posted: the saved value is used to look up a format string,
		// so an unrecognized key would just silently fall back anyway --
		// better to never store one.
		$format = sanitize_key( $_POST[ AM_Date_Format::OPTION ] ?? '' );
		if ( ! isset( AM_Date_Format::FORMATS[ $format ] ) ) {
			$format = AM_Date_Format::DEFAULT_KEY;
		}
		update_option( AM_Date_Format::OPTION, $format );

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'activity-monitor', self::TAB_PARAM => 'settings', 'am_display_settings_saved' => '1' ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	public function handle_save_traffic_settings() {
		check_admin_referer( 'am_save_traffic_settings' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'activity-monitor' ) );
		}

		update_option( 'am_traffic_enabled', ! empty( $_POST['am_traffic_enabled'] ) ? '1' : '' );
		// 0 = never prune, matches the same convention as am_retention_days.
		update_option( 'am_traffic_retention_days', absint( $_POST['am_traffic_retention_days'] ?? 30 ) );
		// Floor of 3s so the live feed can't be configured into hammering
		// the DB every request; ceiling is just sanity (60s still "live"
		// enough to be useful, beyond that the feed isn't really live).
		update_option( 'am_traffic_live_poll_seconds', min( 60, max( 3, absint( $_POST['am_traffic_live_poll_seconds'] ?? 10 ) ) ) );
		update_option( 'am_traffic_live_feed_limit', min( 200, max( 5, absint( $_POST['am_traffic_live_feed_limit'] ?? 25 ) ) ) );

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'activity-monitor', self::TAB_PARAM => 'settings', 'am_traffic_settings_saved' => '1' ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Returns the add/edit modal's form HTML for one digest config. For
	 * "Add Digest" the request carries no id; for "Edit" it carries an
	 * id, and the config's current stored values are looked up
	 * server-side (not trusted from the client) to populate the form --
	 * same pattern as ajax_channel_form().
	 */
	public function ajax_digest_config_form() {
		check_ajax_referer( 'am_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '-1' );
		}

		$id     = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		$config = $id ? AM_Digest::get_config( $id ) : null;

		ob_start();
		$this->render_digest_modal_form( $config );
		wp_send_json_success( array(
			'html'  => ob_get_clean(),
			'title' => $config ? __( 'Edit Digest', 'activity-monitor' ) : __( 'Add Digest', 'activity-monitor' ),
		) );
	}

	/** Saves one digest config (add or edit) immediately, same AJAX-per-item pattern as notification channels. */
	public function ajax_save_digest_config() {
		check_ajax_referer( 'am_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '-1' );
		}

		$id        = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		$frequency = sanitize_key( wp_unslash( $_POST['frequency'] ?? '' ) );
		if ( ! in_array( $frequency, array( 'daily', 'weekly', 'monthly' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Choose a frequency.', 'activity-monitor' ) ) );
		}
		$day_of_week = absint( wp_unslash( $_POST['day_of_week'] ?? 1 ) ) % 7;

		$emails     = array_filter( array_map( 'trim', explode( ',', wp_unslash( $_POST['recipients'] ?? '' ) ) ) );
		$recipients = implode( ', ', array_filter( $emails, 'is_email' ) );
		if ( '' === $recipients ) {
			wp_send_json_error( array( 'message' => __( 'Enter at least one valid email address.', 'activity-monitor' ) ) );
		}

		if ( $id && AM_Digest::get_config( $id ) ) {
			AM_Digest::update_config( $id, $frequency, $day_of_week, $recipients );
		} else {
			AM_Digest::add_config( $frequency, $day_of_week, $recipients );
		}

		ob_start();
		foreach ( AM_Digest::get_configs() as $i => $config ) {
			$this->render_digest_table_row( $config );
		}
		wp_send_json_success( array( 'html' => ob_get_clean() ) );
	}

	/** Deletes one digest config and returns the refreshed table body HTML. */
	public function ajax_delete_digest_config() {
		check_ajax_referer( 'am_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '-1' );
		}

		$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		if ( $id ) {
			AM_Digest::delete_config( $id );
		}

		$configs = AM_Digest::get_configs();
		ob_start();
		foreach ( $configs as $config ) {
			$this->render_digest_table_row( $config );
		}
		wp_send_json_success( array(
			'html'  => ob_get_clean(),
			'empty' => empty( $configs ),
		) );
	}

	/**
	 * Streams a file download -- reads the same am_* filter params the log
	 * tab's filter form uses (see render_tab_v2_log()) so export always
	 * matches what's currently on screen. am_export_action maps to
	 * AM_Event_Query's 'action' filter key; 'action' itself is reserved by
	 * admin-post.php for dispatch routing (action=am_export_log) and can't
	 * double as the filter key here.
	 */
	public function handle_export() {
		check_admin_referer( AM_Export::NONCE_ACTION );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'activity-monitor' ) );
		}

		$format = sanitize_key( $_GET['am_format'] ?? 'csv' );

		// am_type is the same combined value the Activity Log's Type
		// filter uses -- either a bare event_type or "type|action" --
		// so it needs the same parsing here. sanitize_key() would strip
		// the separator and export a different (empty) set than the
		// screen the export was launched from.
		$export_type   = self::sanitize_type_filter( $_GET['am_type'] ?? '' );
		$export_action = sanitize_key( $_GET['am_export_action'] ?? '' );
		if ( false !== strpos( $export_type, '|' ) ) {
			list( $export_type, $export_action ) = explode( '|', $export_type, 2 );
		}

		$filters = array(
			'level'      => sanitize_key( $_GET['am_level'] ?? '' ),
			'initiator'  => sanitize_key( $_GET['am_initiator'] ?? '' ),
			'event_type' => $export_type,
			'action'     => $export_action,
			'user'       => sanitize_user( wp_unslash( $_GET['am_user'] ?? '' ) ),
			'date_from'  => sanitize_text_field( $_GET['am_from'] ?? '' ),
			'date_to'    => sanitize_text_field( $_GET['am_to'] ?? '' ),
			'search'     => sanitize_text_field( $_GET['am_search'] ?? '' ),
		);

		AM_Export::stream( $format, $filters );
		// AM_Export::stream() exits internally after writing the response.
	}

	// ── AJAX ─────────────────────────────────────────────────────────────

	public function ajax_digest_preview() {
		check_ajax_referer( 'am_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '-1' );
		}

		$frequency = sanitize_key( wp_unslash( $_POST['frequency'] ?? 'weekly' ) );
		if ( ! in_array( $frequency, array( 'daily', 'weekly', 'monthly' ), true ) ) {
			$frequency = 'weekly';
		}
		wp_send_json_success( array( 'html' => AM_Digest::build_html( $frequency, true ) ) );
	}

	public function ajax_digest_send_test() {
		check_ajax_referer( 'am_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '-1' );
		}

		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter a valid email address.', 'activity-monitor' ) ) );
		}
		$frequency = sanitize_key( wp_unslash( $_POST['frequency'] ?? 'weekly' ) );
		if ( ! in_array( $frequency, array( 'daily', 'weekly', 'monthly' ), true ) ) {
			$frequency = 'weekly';
		}

		$sent = AM_Digest::send_test( $email, $frequency );
		if ( $sent ) {
			wp_send_json_success( array( 'message' => __( 'Test email sent.', 'activity-monitor' ) ) );
		}
		wp_send_json_error( array( 'message' => __( 'Failed to send. Check your site\'s mail configuration.', 'activity-monitor' ) ) );
	}

	public function ajax_v2_event_detail() {
		check_ajax_referer( 'am_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '-1' );
		}

		global $wpdb;
		$id    = absint( $_POST['entry_id'] ?? 0 );
		$table = $wpdb->prefix . AM_Schema::EVENTS_TABLE;
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM `{$table}` WHERE id = %d",
			$id
		) );

		if ( ! $row ) {
			wp_send_json_error( 'Not found' );
		}

		$context = AM_Event_Query::get_context( $id );

		ob_start();
		?>
		<table class="am-detail-table">
			<tr><th><?php esc_html_e( 'ID', 'activity-monitor' ); ?></th><td><?php echo esc_html( $row->id ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Date / Time', 'activity-monitor' ); ?></th><td><?php echo esc_html( $row->date ); ?> UTC</td></tr>
			<tr>
				<th><?php esc_html_e( 'Level', 'activity-monitor' ); ?></th>
				<td><span class="am-badge am-<?php echo esc_attr( $row->level ); ?>"><?php echo esc_html( AM_Log_Levels::label( $row->level ) ); ?></span></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Initiator', 'activity-monitor' ); ?></th>
				<td><span class="am-badge am-init-<?php echo esc_attr( $row->initiator ); ?>"><?php echo esc_html( AM_Initiator_Detector::label( $row->initiator ) ); ?></span></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Event', 'activity-monitor' ); ?></th>
				<td>
					<span title="<?php echo esc_attr( AM_Event_Labels::raw( $row->event_type, $row->action ) ); ?>">
						<?php echo esc_html( AM_Event_Labels::label( $row->event_type, $row->action ) ); ?>
					</span>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'User', 'activity-monitor' ); ?></th>
				<td>
					<?php echo esc_html( $row->user_login ); ?>
					<?php if ( $row->user_role ) echo ' (' . esc_html( $row->user_role ) . ')'; ?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Name', 'activity-monitor' ); ?></th>
				<td><?php echo esc_html( self::real_name( (int) $row->user_id ) ?: '—' ); ?></td>
			</tr>
			<tr><th><?php esc_html_e( 'IP Address', 'activity-monitor' ); ?></th><td><a href="#" class="am-ip-lookup" data-ip="<?php echo esc_attr( $row->ip_address ); ?>"><?php echo esc_html( $row->ip_address ); ?></a></td></tr>
			<tr>
				<th><?php esc_html_e( 'Object', 'activity-monitor' ); ?></th>
				<td>
					<?php echo esc_html( $row->object_type ); ?>
					<?php if ( $row->object_name ) echo ' – ' . esc_html( $row->object_name ); ?>
					<?php if ( $row->object_id )   echo ' (ID: ' . esc_html( $row->object_id ) . ')'; ?>
				</td>
			</tr>
			<tr><th><?php esc_html_e( 'Message', 'activity-monitor' ); ?></th><td><?php echo esc_html( $row->message ); ?></td></tr>
			<?php if ( (int) $row->repeat_count > 1 ) : ?>
			<tr><th><?php esc_html_e( 'Repeated', 'activity-monitor' ); ?></th><td><?php printf( esc_html__( '%d times (occasion grouping)', 'activity-monitor' ), (int) $row->repeat_count ); ?></td></tr>
			<?php endif; ?>
			<?php if ( ! empty( $context['diff'] ) && is_array( $context['diff'] ) ) : ?>
			<tr>
				<th><?php esc_html_e( 'Changes', 'activity-monitor' ); ?></th>
				<td>
					<table class="am-diff-table">
						<?php foreach ( $context['diff'] as $field => $change ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $field ); ?></strong></td>
							<td class="am-diff-before"><?php echo esc_html( wp_trim_words( (string) ( $change['before'] ?? '' ), 20 ) ); ?></td>
							<td>&rarr;</td>
							<td class="am-diff-after"><?php echo esc_html( wp_trim_words( (string) ( $change['after'] ?? '' ), 20 ) ); ?></td>
						</tr>
						<?php endforeach; ?>
					</table>
				</td>
			</tr>
			<?php endif; ?>
		</table>
		<?php
		wp_send_json_success( array( 'html' => ob_get_clean() ) );
	}

	/**
	 * "First Name Last Name" for a user, looked up live via WP user meta
	 * — deliberately separate from user_display_name (the nickname
	 * field), which the person may have set identically to their login
	 * and which isn't a reliable real-name source. Falls back to
	 * whichever of first/last is set, then to '' if neither exists
	 * (e.g. user later deleted, or never filled in their profile).
	 */
	private static function real_name( int $user_id ): string {
		if ( ! $user_id ) {
			return '';
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return '';
		}
		$name = trim( $user->first_name . ' ' . $user->last_name );
		return $name;
	}

	/**
	 * FIX #2: Re-fetch session data from the database rather than trusting
	 * POST-supplied display values. Only user_id and token_hash are accepted
	 * from POST; everything else is re-derived server-side.
	 */
	public function ajax_session_detail() {
		check_ajax_referer( 'am_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '-1' );
		}

		$user_id    = absint( $_POST['user_id'] ?? 0 );
		$token_hash = sanitize_text_field( wp_unslash( $_POST['token_hash'] ?? '' ) );

		if ( ! $user_id || ! $token_hash ) {
			wp_send_json_error( 'Invalid request' );
		}

		// Re-fetch authoritative data from the database.
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			wp_send_json_error( 'User not found' );
		}

		$sessions = get_user_meta( $user_id, 'session_tokens', true );
		if ( ! is_array( $sessions ) || ! isset( $sessions[ $token_hash ] ) ) {
			wp_send_json_error( 'Session not found' );
		}

		$session = $sessions[ $token_hash ];

		// Derive values from authoritative session data – not from POST.
		$login_ts   = isset( $session['login'] )      ? (int) $session['login']      : 0;
		$expiry_ts  = isset( $session['expiration'] ) ? (int) $session['expiration'] : 0;
		$ip         = isset( $session['ip'] )         ? sanitize_text_field( $session['ip'] ) : '';
		$ua         = isset( $session['ua'] )         ? sanitize_text_field( $session['ua'] ) : '';

		$date_format = AM_Date_Format::combined();
		$login_text  = $login_ts  ? wp_date( $date_format, $login_ts )  : __( 'Unknown', 'activity-monitor' );
		$expiry_text = $expiry_ts ? wp_date( $date_format, $expiry_ts ) : __( 'Never',   'activity-monitor' );
		$browser     = $this->parse_user_agent( $ua );
		$now         = time();
		$is_expired  = ( $expiry_ts > 0 && $expiry_ts < $now );

		$current_token_hash = hash( 'sha256', wp_get_session_token() );
		$is_current = ( $user_id === get_current_user_id() && hash_equals( $current_token_hash, $token_hash ) );

		ob_start();
		?>
		<table class="am-detail-table">
			<tr>
				<th><?php esc_html_e( 'User', 'activity-monitor' ); ?></th>
				<td>
					<strong><?php echo esc_html( $user->user_login ); ?></strong>
					(ID: <?php echo esc_html( $user_id ); ?>)
					<?php if ( $is_current ) : ?>
						<span class="am-badge am-info"><?php esc_html_e( 'You', 'activity-monitor' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Name', 'activity-monitor' ); ?></th>
				<td><?php echo esc_html( self::real_name( $user_id ) ?: '—' ); ?></td>
			</tr>
			<tr><th><?php esc_html_e( 'Logged In', 'activity-monitor' ); ?></th><td><?php echo esc_html( $login_text ); ?></td></tr>
			<tr>
				<th><?php esc_html_e( 'Expiry', 'activity-monitor' ); ?></th>
				<td>
					<?php echo esc_html( $expiry_text ); ?>
					<?php if ( $is_expired ) : ?>
						<span class="am-badge am-warning"><?php esc_html_e( 'Expired', 'activity-monitor' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<tr><th><?php esc_html_e( 'IP Address', 'activity-monitor' ); ?></th><td><a href="#" class="am-ip-lookup" data-ip="<?php echo esc_attr( $ip ); ?>"><?php echo esc_html( $ip ); ?></a></td></tr>
			<tr><th><?php esc_html_e( 'Browser / UA', 'activity-monitor' ); ?></th><td><?php echo esc_html( $browser ); ?></td></tr>
			<tr><th><?php esc_html_e( 'User Agent', 'activity-monitor' ); ?></th><td><small><?php echo esc_html( $ua ); ?></small></td></tr>
			<tr><th><?php esc_html_e( 'Session ID', 'activity-monitor' ); ?></th><td><?php echo esc_html( $token_hash ); ?></td></tr>
		</table>
		<?php
		wp_send_json_success( array( 'html' => ob_get_clean() ) );
	}

	/**
	 * Returns recent raw page-view hits for the Traffic tab's live feed.
	 * Polled repeatedly from JS at an interval the person configures in
	 * Settings (am_traffic_live_poll_seconds); after_id lets each poll
	 * ask only for hits newer than what the client already has, so
	 * repeated polls don't re-send the same rows.
	 */
	public function ajax_live_traffic() {
		check_ajax_referer( 'am_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '-1' );
		}

		$after_id = absint( $_POST['after_id'] ?? 0 );
		$limit    = absint( get_option( 'am_traffic_live_feed_limit', 25 ) );

		$hits = AM_Traffic_Query::get_recent_hits( $limit, $after_id );

		$date_format = AM_Date_Format::time_format();
		$formatted   = array();
		foreach ( $hits as $hit ) {
			$formatted[] = array(
				'id'       => $hit['id'],
				'time'     => wp_date( $date_format, strtotime( $hit['date'] . ' UTC' ) ),
				'url'      => $hit['url'],
				'full_url' => home_url( $hit['url'] ),
				'ip'       => $hit['ip_address'],
				'user'     => $hit['user_id'] ? ( get_userdata( $hit['user_id'] )->user_login ?? '' ) : __( 'Guest', 'activity-monitor' ),
			);
		}

		wp_send_json_success( array(
			'hits' => $formatted,
		) );
	}

	/**
	 * Fetches geolocation/ISP data for an IP address from ipinfo.io's
	 * free, no-signup "legacy" endpoint (https://ipinfo.io/{ip}/json) and
	 * returns it as modal HTML, matching the look of the other detail
	 * modals in this plugin.
	 *
	 * Deliberately NOT an iframe embed of ipinfo.io's own page: that was
	 * the original approach considered, but sites in this category
	 * commonly send X-Frame-Options/frame-ancestors headers blocking
	 * exactly this kind of embedding (confirmed before building this),
	 * so an iframe would likely show a blank/refused frame. Fetching
	 * the JSON server-side and rendering our own markup sidesteps that
	 * entirely.
	 *
	 * The legacy endpoint requires no API token/account and is rate
	 * limited to 1,000 requests/day shared across everyone hitting it
	 * from this server's IP -- more than enough for occasional manual
	 * lookups from an admin page, and avoids requiring the person to
	 * sign up for and configure an API key just to click an IP.
	 */
	/**
	 * Profile card for a WordPress user, shown when a username in the
	 * Activity Log is clicked.
	 *
	 * Takes a user_id rather than the login stored on the log row,
	 * because logins can be changed and reused while the ID cannot --
	 * looking up by login could quietly show the wrong person's profile
	 * after a rename. The trade-off is that a user deleted since the
	 * event was logged has no profile left to show, which is reported
	 * plainly rather than dressed up: the log row keeps its own
	 * snapshot of who acted, and that snapshot is what remains true.
	 *
	 * Alongside the WordPress profile fields it shows this plugin's own
	 * view of the user -- how many events they've generated and when
	 * they were last seen -- since that's the question someone actually
	 * has when clicking a name inside an activity log, and it's not
	 * something the normal user-edit screen can answer.
	 */
	public function ajax_user_profile() {
		check_ajax_referer( 'am_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '-1' );
		}

		$user_id = absint( $_POST['user_id'] ?? 0 );
		$user    = $user_id ? get_userdata( $user_id ) : false;

		if ( ! $user ) {
			wp_send_json_error( array(
				'message' => __( 'That user no longer exists on this site. The activity log keeps its own record of who performed each action, so the entries themselves are unaffected.', 'activity-monitor' ),
			) );
		}

		// Role slugs -> display names, falling back to the raw slug for
		// any role registered by a plugin that has since gone away.
		$role_names = wp_roles()->get_names();
		$roles      = array();
		foreach ( (array) $user->roles as $role ) {
			$roles[] = $role_names[ $role ] ?? $role;
		}

		// This plugin's own view of the user. get_events() is ordered by
		// date DESC, so a single-row page gives both the total and the
		// most recent entry without a second query.
		$activity   = AM_Event_Query::get_events( array( 'user' => $user->user_login, 'per_page' => 1 ) );
		$last_seen  = $activity['items'][0]->date ?? '';
		$registered = strtotime( $user->user_registered . ' UTC' );

		ob_start();
		?>
		<div class="am-user-profile">
			<div class="am-user-profile-head">
				<?php echo get_avatar( $user->ID, 64 ); ?>
				<div>
					<strong class="am-user-profile-name"><?php echo esc_html( $user->display_name ); ?></strong>
					<span class="am-user-profile-login"><?php echo esc_html( $user->user_login ); ?></span>
				</div>
			</div>

			<table class="am-detail-table">
				<tr><th><?php esc_html_e( 'User ID', 'activity-monitor' ); ?></th><td><?php echo esc_html( $user->ID ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Email', 'activity-monitor' ); ?></th><td><a href="<?php echo esc_url( 'mailto:' . $user->user_email ); ?>"><?php echo esc_html( $user->user_email ); ?></a></td></tr>
				<tr>
					<th><?php esc_html_e( 'Role', 'activity-monitor' ); ?></th>
					<td><?php echo esc_html( $roles ? implode( ', ', $roles ) : __( 'None', 'activity-monitor' ) ); ?></td>
				</tr>
				<?php if ( $user->user_url ) : ?>
					<tr>
						<th><?php esc_html_e( 'Website', 'activity-monitor' ); ?></th>
						<td><a href="<?php echo esc_url( $user->user_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $user->user_url ); ?></a></td>
					</tr>
				<?php endif; ?>
				<tr>
					<th><?php esc_html_e( 'Registered', 'activity-monitor' ); ?></th>
					<td><?php echo $registered ? esc_html( wp_date( AM_Date_Format::combined(), $registered ) ) : '&mdash;'; ?></td>
				</tr>
				<?php if ( $user->description ) : ?>
					<tr><th><?php esc_html_e( 'Bio', 'activity-monitor' ); ?></th><td><?php echo esc_html( $user->description ); ?></td></tr>
				<?php endif; ?>
				<tr>
					<th><?php esc_html_e( 'Logged events', 'activity-monitor' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( (int) $activity['total'] ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Last activity', 'activity-monitor' ); ?></th>
					<td><?php echo $last_seen ? esc_html( wp_date( AM_Date_Format::combined(), strtotime( $last_seen . ' UTC' ) ) ) : '&mdash;'; ?></td>
				</tr>
				<tr>
					<td colspan="2" style="text-align:right;">
						<a href="<?php echo esc_url( add_query_arg(
							array( 'page' => 'activity-monitor', self::TAB_PARAM => 'log', 'am_user' => $user->user_login ),
							admin_url( 'admin.php' )
						) ); ?>" class="button button-small">
							<?php esc_html_e( 'View this user’s activity', 'activity-monitor' ); ?>
						</a>
						<?php $edit_link = get_edit_user_link( $user->ID ); ?>
						<?php if ( $edit_link ) : ?>
							<a href="<?php echo esc_url( $edit_link ); ?>" class="button button-small">
								<?php esc_html_e( 'Edit user', 'activity-monitor' ); ?>
							</a>
						<?php endif; ?>
					</td>
				</tr>
			</table>
		</div>
		<?php
		wp_send_json_success( array( 'html' => ob_get_clean() ) );
	}

	public function ajax_ip_lookup() {
		check_ajax_referer( 'am_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '-1' );
		}

		$ip = sanitize_text_field( wp_unslash( $_POST['ip'] ?? '' ) );
		if ( ! $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			wp_send_json_error( array( 'message' => __( 'Not a valid IP address.', 'activity-monitor' ) ) );
		}

		$response = wp_remote_get( 'https://ipinfo.io/' . rawurlencode( $ip ) . '/json', array( 'timeout' => 5 ) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			wp_send_json_error( array( 'message' => __( 'Lookup service unavailable. Try again in a moment.', 'activity-monitor' ) ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			wp_send_json_error( array( 'message' => __( 'Unexpected response from the lookup service.', 'activity-monitor' ) ) );
		}

		$rows = array(
			'ip'       => $data['ip']       ?? $ip,
			'hostname' => $data['hostname'] ?? '',
			'city'     => $data['city']     ?? '',
			'region'   => $data['region']   ?? '',
			'country'  => $data['country']  ?? '',
			'loc'      => $data['loc']      ?? '',
			'postal'   => $data['postal']   ?? '',
			'timezone' => $data['timezone'] ?? '',
			'org'      => $data['org']      ?? '',
		);

		ob_start();
		?>
		<table class="am-detail-table">
			<tr><th><?php esc_html_e( 'IP Address', 'activity-monitor' ); ?></th><td><?php echo esc_html( $rows['ip'] ); ?></td></tr>
			<?php if ( $rows['hostname'] ) : ?>
				<tr><th><?php esc_html_e( 'Hostname', 'activity-monitor' ); ?></th><td><?php echo esc_html( $rows['hostname'] ); ?></td></tr>
			<?php endif; ?>
			<?php if ( $rows['city'] || $rows['region'] || $rows['country'] ) : ?>
				<tr>
					<th><?php esc_html_e( 'Location', 'activity-monitor' ); ?></th>
					<td><?php echo esc_html( implode( ', ', array_filter( array( $rows['city'], $rows['region'], $rows['country'] ) ) ) ); ?></td>
				</tr>
			<?php endif; ?>
			<?php if ( $rows['postal'] ) : ?>
				<tr><th><?php esc_html_e( 'Postal Code', 'activity-monitor' ); ?></th><td><?php echo esc_html( $rows['postal'] ); ?></td></tr>
			<?php endif; ?>
			<?php if ( $rows['timezone'] ) : ?>
				<tr><th><?php esc_html_e( 'Timezone', 'activity-monitor' ); ?></th><td><?php echo esc_html( $rows['timezone'] ); ?></td></tr>
			<?php endif; ?>
			<?php if ( $rows['org'] ) : ?>
				<tr><th><?php esc_html_e( 'ISP / Organization', 'activity-monitor' ); ?></th><td><?php echo esc_html( $rows['org'] ); ?></td></tr>
			<?php endif; ?>
			<?php if ( $rows['loc'] ) : ?>
				<tr>
					<th><?php esc_html_e( 'Coordinates', 'activity-monitor' ); ?></th>
					<td>
						<?php echo esc_html( $rows['loc'] ); ?>
						&mdash;
						<a href="<?php echo esc_url( 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $rows['loc'] ) ); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'View on map', 'activity-monitor' ); ?>
						</a>
					</td>
				</tr>
			<?php endif; ?>
			<tr>
				<td colspan="2" style="text-align:right;">
					<small>
						<?php
						printf(
							/* translators: %s: ipinfo.io link */
							esc_html__( 'Data from %s', 'activity-monitor' ),
							'<a href="' . esc_url( 'https://ipinfo.io/' . rawurlencode( $rows['ip'] ) ) . '" target="_blank" rel="noopener noreferrer">ipinfo.io</a>'
						);
						?>
					</small>
				</td>
			</tr>
		</table>
		<?php
		wp_send_json_success( array( 'html' => ob_get_clean() ) );
	}

	/**
	 * Returns detail for a single traffic hit (referrer, full user agent,
	 * etc. -- fields the live feed's row doesn't show) for the page-detail
	 * modal opened by clicking a page/URL in the Traffic tab's live feed.
	 */
	public function ajax_traffic_hit_detail() {
		check_ajax_referer( 'am_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '-1' );
		}

		$id  = absint( $_POST['id'] ?? 0 );
		$hit = $id ? AM_Traffic_Query::get_hit( $id ) : null;

		if ( ! $hit ) {
			wp_send_json_error( array( 'message' => __( 'That page view could not be found — it may have been pruned.', 'activity-monitor' ) ) );
		}

		$browser = $hit['user_agent'] ? $this->parse_user_agent( $hit['user_agent'] ) : __( 'Unknown', 'activity-monitor' );
		$visitor = $hit['user_id'] ? ( get_userdata( $hit['user_id'] )->user_login ?? __( 'Unknown user', 'activity-monitor' ) ) : __( 'Guest', 'activity-monitor' );

		ob_start();
		?>
		<table class="am-detail-table">
			<tr><th><?php esc_html_e( 'Time', 'activity-monitor' ); ?></th><td><?php echo esc_html( wp_date( AM_Date_Format::combined(), strtotime( $hit['date'] . ' UTC' ) ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Title', 'activity-monitor' ); ?></th><td><?php echo esc_html( $hit['title'] ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Page', 'activity-monitor' ); ?></th><td><?php echo esc_html( $hit['url'] ); ?></td></tr>
			<tr>
				<th><?php esc_html_e( 'Referrer', 'activity-monitor' ); ?></th>
				<td>
					<?php if ( $hit['referrer'] ) : ?>
						<?php echo esc_html( $hit['referrer'] ); ?>
					<?php else : ?>
						<?php esc_html_e( 'Direct / none', 'activity-monitor' ); ?>
					<?php endif; ?>
				</td>
			</tr>
			<tr><th><?php esc_html_e( 'Visitor', 'activity-monitor' ); ?></th><td><?php echo esc_html( $visitor ); ?></td></tr>
			<tr><th><?php esc_html_e( 'IP Address', 'activity-monitor' ); ?></th><td><a href="#" class="am-ip-lookup" data-ip="<?php echo esc_attr( $hit['ip_address'] ); ?>"><?php echo esc_html( $hit['ip_address'] ); ?></a></td></tr>
			<tr>
				<th><?php esc_html_e( 'Browser', 'activity-monitor' ); ?></th>
				<td>
					<?php echo esc_html( $browser ); ?>
					<?php if ( $hit['user_agent'] ) : ?>
						<br><small class="am-role"><?php echo esc_html( $hit['user_agent'] ); ?></small>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
		wp_send_json_success( array( 'html' => ob_get_clean() ) );
	}

	/**
	 * Returns the add/edit modal's form HTML for a channel. For "Add X
	 * Channel" the request carries only a type and no index; for "Edit"
	 * it carries an index, and the channel's current stored values are
	 * looked up server-side (not trusted from the client) to populate
	 * the form -- matching the re-fetch-from-DB pattern used by the
	 * other detail modals in this plugin.
	 */
	public function ajax_channel_form() {
		check_ajax_referer( 'am_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '-1' );
		}

		$channels = get_option( 'am_notification_channels', array() );
		$index    = isset( $_POST['index'] ) && '' !== $_POST['index'] ? absint( $_POST['index'] ) : null;

		if ( null !== $index && isset( $channels[ $index ] ) ) {
			$ch   = $channels[ $index ];
			$type = ( 'slack' === ( $ch['type'] ?? '' ) ) ? 'slack' : 'email';
		} else {
			$index = null;
			$type  = ( 'slack' === ( $_POST['type'] ?? '' ) ) ? 'slack' : 'email';
			$ch    = array();
		}

		ob_start();
		$this->render_channel_modal_form( $type, $index, $ch );
		wp_send_json_success( array(
			'html'  => ob_get_clean(),
			'title' => 'slack' === $type
				? ( null === $index ? __( 'Add Slack Channel', 'activity-monitor' ) : __( 'Edit Slack Channel', 'activity-monitor' ) )
				: ( null === $index ? __( 'Add Email Channel', 'activity-monitor' ) : __( 'Edit Email Channel', 'activity-monitor' ) ),
		) );
	}

	/**
	 * Saves one channel (add or edit) immediately, per Rob's explicit
	 * choice for this to be an AJAX save from the modal rather than
	 * requiring the old page-level "Save Notification Channels" button.
	 * index absent/empty means add (appends); index present means edit
	 * (replaces that array offset in place, preserving its position in
	 * the table rather than moving it to the end).
	 */
	public function ajax_save_channel() {
		check_ajax_referer( 'am_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '-1' );
		}

		$raw = array(
			'type'        => sanitize_key( wp_unslash( $_POST['type'] ?? '' ) ),
			'name'        => wp_unslash( $_POST['name'] ?? '' ),
			'level'       => wp_unslash( $_POST['level'] ?? '' ),
			'recipients'  => wp_unslash( $_POST['recipients'] ?? '' ),
			'webhook_url' => wp_unslash( $_POST['webhook_url'] ?? '' ),
		);

		$clean = self::sanitize_one_channel( $raw );
		if ( null === $clean ) {
			$message = 'slack' === $raw['type']
				? __( 'Enter a valid Slack webhook URL (must start with https://hooks.slack.com/).', 'activity-monitor' )
				: __( 'Enter at least one valid email address.', 'activity-monitor' );
			wp_send_json_error( array( 'message' => $message ) );
		}
		// A valid Slack channel still needs a non-empty webhook (the
		// host-matches-hooks.slack.com check in sanitize_one_channel
		// would already reject a blank/malformed URL, but an empty
		// email recipients list can slip through as an empty string
		// rather than null -- catch that case explicitly so an email
		// channel can't be saved with nothing to actually notify).
		if ( 'email' === $clean['type'] && '' === $clean['recipients'] ) {
			wp_send_json_error( array( 'message' => __( 'Enter at least one valid email address.', 'activity-monitor' ) ) );
		}

		$channels = get_option( 'am_notification_channels', array() );
		$index    = isset( $_POST['index'] ) && '' !== $_POST['index'] ? absint( $_POST['index'] ) : null;

		if ( null !== $index && isset( $channels[ $index ] ) ) {
			$channels[ $index ] = $clean;
		} else {
			$channels[] = $clean;
		}
		$channels = array_values( $channels );
		update_option( 'am_notification_channels', $channels );

		ob_start();
		foreach ( $channels as $i => $ch ) {
			$this->render_channel_table_row( $i, $ch );
		}
		wp_send_json_success( array( 'html' => ob_get_clean() ) );
	}

	/** Deletes one channel by index and returns the refreshed table body HTML. */
	public function ajax_delete_channel() {
		check_ajax_referer( 'am_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '-1' );
		}

		$index    = absint( $_POST['index'] ?? -1 );
		$channels = get_option( 'am_notification_channels', array() );

		if ( isset( $channels[ $index ] ) ) {
			unset( $channels[ $index ] );
			$channels = array_values( $channels );
			update_option( 'am_notification_channels', $channels );
		}

		ob_start();
		foreach ( $channels as $i => $ch ) {
			$this->render_channel_table_row( $i, $ch );
		}
		wp_send_json_success( array(
			'html'  => ob_get_clean(),
			'empty' => empty( $channels ),
		) );
	}

	// ── Master page renderer ─────────────────────────────────────────────

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tabs = array(
			'stats'    => __( 'Dashboard',        'activity-monitor' ),
			'log'      => __( 'Activity Log',    'activity-monitor' ),
			'traffic'  => __( 'Traffic',          'activity-monitor' ),
			'sessions' => __( 'Active Sessions', 'activity-monitor' ),
		);

		$tabs['settings'] = __( 'Settings', 'activity-monitor' );

		$default_tab = array_key_first( $tabs );
		$active_tab  = sanitize_key( $_GET[ self::TAB_PARAM ] ?? $default_tab );
		if ( ! array_key_exists( $active_tab, $tabs ) ) {
			$active_tab = $default_tab;
		}

		$base_url = admin_url( 'admin.php?page=activity-monitor' );
		?>
		<div class="wrap am-wrap">

			<div class="am-header">
				<h1 class="am-title">
					<span class="dashicons dashicons-shield-alt"></span>
					<?php esc_html_e( 'Activity Monitor', 'activity-monitor' ); ?>
					<span class="am-version">v<?php echo esc_html( AM_VERSION ); ?></span>
				</h1>
			</div>

			<nav class="am-tab-nav nav-tab-wrapper wp-clearfix">
				<?php foreach ( $tabs as $slug => $label ) :
					$url       = add_query_arg( self::TAB_PARAM, $slug, $base_url );
					$is_active = ( $slug === $active_tab );
				?>
					<a href="<?php echo esc_url( $url ); ?>"
					   class="nav-tab<?php echo $is_active ? ' nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="am-tab-content">
				<?php
				switch ( $active_tab ) {
					case 'log':
						// Retired the old AM_DB-backed renderer in favor of the
						// v2.0 schema renderer, now that column parity is
						// complete (IP Address added) and every event source is
						// ported. See activity-monitor-v2-spec.md §9.
						$this->render_tab_v2_log();
						break;
					case 'stats':
						$this->render_tab_stats();
						break;
					case 'traffic':
						$this->render_tab_traffic();
						break;
					case 'sessions':
						$this->render_tab_sessions();
						break;
					case 'settings':
						$this->render_tab_settings();
						break;
				}
				?>
			</div>

		</div><!-- .am-wrap -->

		<!-- Event Detail Modal -->
		<div id="am-modal-overlay" class="am-modal-overlay" style="display:none;">
			<div class="am-modal">
				<div class="am-modal-header">
					<h2 id="am-modal-title"><?php esc_html_e( 'Details', 'activity-monitor' ); ?></h2>
					<button class="am-modal-close" id="am-modal-close">&times;</button>
				</div>
				<div class="am-modal-body" id="am-modal-body">
					<p class="am-loading"><?php esc_html_e( 'Loading…', 'activity-monitor' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	// ── Tab: Activity Log ─────────────────────────────────────────────────
	//
	// Reads from the am_events / am_event_context schema. This was
	// originally a "v2.0 preview" tab running alongside the legacy
	// AM_DB-backed "Activity Log" tab while loggers were ported one at a
	// time; that legacy tab (and its render_tab_log() method) has been
	// removed now that all 13 v1.x event sources are ported and this tab
	// has full column parity (including IP Address, added when the old
	// tab was retired). Method name (render_tab_v2_log) kept as-is to
	// avoid an unnecessary rename churn across this file.
	// See activity-monitor-v2-spec.md §9 and GitHub issue #4.

	private function render_tab_v2_log() {
		$per_page   = 50;
		$page       = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$level      = sanitize_key( $_GET['am_level'] ?? '' );
		$initiator  = sanitize_key( $_GET['am_initiator'] ?? '' );

		// The Type filter carries either a category ('media') or one
		// specific event ('media|uploaded'). The two halves are joined
		// with a pipe rather than a dot because event_type can itself
		// contain a dot on rows migrated from v1.x ('post.delete') --
		// splitting those on '.' would misread a stored slug as a
		// type/action pair and filter for something that never existed.
		// A pipe can't occur in either column.
		$type_filter = self::sanitize_type_filter( $_GET['am_type'] ?? '' );
		$event_type  = $type_filter;
		$type_action = '';
		if ( false !== strpos( $type_filter, '|' ) ) {
			list( $event_type, $type_action ) = explode( '|', $type_filter, 2 );
		}

		$action     = '' !== $type_action ? $type_action : sanitize_key( $_GET['am_action'] ?? '' );
		$user       = sanitize_user( wp_unslash( $_GET['am_user'] ?? '' ) );
		$date_from  = sanitize_text_field( $_GET['am_from'] ?? '' );
		$date_to    = sanitize_text_field( $_GET['am_to'] ?? '' );
		$search     = sanitize_text_field( $_GET['am_search'] ?? '' );

		$data      = AM_Event_Query::get_events( compact( 'per_page', 'page', 'level', 'initiator', 'event_type', 'action', 'user', 'date_from', 'date_to', 'search' ) );
		$items     = $data['items'];
		$total     = $data['total'];
		$num_pages = (int) ceil( $total / $per_page );

		// Grouped Type options: one entry per event_type, holding every
		// specific event seen under it. Types with a single entry render
		// as a plain option rather than a group of one -- that includes
		// migrated v1.x rows, where the action is empty and the category
		// and the specific event are the same thing.
		$type_groups = array();
		foreach ( AM_Event_Query::get_event_type_actions() as $pair ) {
			$pair_type   = (string) $pair['event_type'];
			$pair_action = (string) $pair['action'];
			if ( ! isset( $type_groups[ $pair_type ] ) ) {
				$type_groups[ $pair_type ] = array(
					'value'   => $pair_type,
					'label'   => AM_Event_Labels::type_label( $pair_type ),
					'options' => array(),
				);
			}
			$type_groups[ $pair_type ]['options'][] = array(
				'value' => '' === $pair_action ? $pair_type : $pair_type . '|' . $pair_action,
				'label' => AM_Event_Labels::label( $pair_type, $pair_action ),
			);
		}
		// Sorted by label, not slug -- 'core' displays as "WordPress
		// Core" and would otherwise sort under C.
		usort( $type_groups, static function ( $a, $b ) {
			return strcasecmp( $a['label'], $b['label'] );
		} );
		foreach ( $type_groups as &$group ) {
			usort( $group['options'], static function ( $a, $b ) {
				return strcasecmp( $a['label'], $b['label'] );
			} );
		}
		unset( $group );

		$base_url = add_query_arg(
			array( 'page' => 'activity-monitor', self::TAB_PARAM => 'log' ),
			admin_url( 'admin.php' )
		);

		$level_options = array();
		foreach ( AM_Log_Levels::ORDER as $lvl ) {
			$level_options[ $lvl ] = AM_Log_Levels::label( $lvl );
		}

		$initiator_options = array();
		foreach ( AM_Initiator_Detector::all() as $init ) {
			$initiator_options[ $init ] = AM_Initiator_Detector::label( $init );
		}
		?>

		<div class="am-stats-bar">
			<span class="am-stat">
				<strong><?php echo esc_html( number_format( $total ) ); ?></strong>
				<?php esc_html_e( 'Total Events', 'activity-monitor' ); ?>
			</span>
		</div>

		<?php if ( 0 === AM_Event_Query::total_count() ) : ?>
			<div class="notice notice-info inline">
				<p>
					<?php esc_html_e( 'No activity recorded yet. Try editing a post, logging in, or changing a setting, then check back here.', 'activity-monitor' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<div class="am-filter-bar">
			<form method="get" action="">
				<input type="hidden" name="page" value="activity-monitor">
				<input type="hidden" name="<?php echo esc_attr( self::TAB_PARAM ); ?>" value="log">
				<?php if ( '' !== $user ) : ?>
					<?php // The visible User box was removed, but the filter itself still
					      // works and is set from elsewhere -- the "View this user's
					      // activity" button in the user profile modal links with
					      // am_user. Without carrying it here, changing any other filter
					      // would submit this form without it and silently drop it. ?>
					<input type="hidden" name="am_user" value="<?php echo esc_attr( $user ); ?>">
				<?php endif; ?>

				<div class="am-filter-group">
					<span class="am-filter-label"><?php esc_html_e( 'Level:', 'activity-monitor' ); ?></span>
					<a href="<?php echo esc_url( remove_query_arg( 'am_level', $base_url ) ); ?>"
					   class="am-pill <?php echo '' === $level ? 'active' : ''; ?>">
						<?php esc_html_e( 'All', 'activity-monitor' ); ?>
					</a>
					<?php foreach ( $level_options as $lvl_val => $lvl_label ) :
						$url = add_query_arg( 'am_level', $lvl_val, $base_url );
					?>
					<a href="<?php echo esc_url( $url ); ?>"
					   class="am-pill am-pill-am-<?php echo esc_attr( $lvl_val ); ?> <?php echo ( $lvl_val === $level ) ? 'active' : ''; ?>">
						<?php echo esc_html( $lvl_label ); ?>
					</a>
					<?php endforeach; ?>
				</div>

				<div class="am-filter-group">
					<span class="am-filter-label"><?php esc_html_e( 'Initiator:', 'activity-monitor' ); ?></span>
					<select name="am_initiator" onchange="this.form.submit()">
						<option value=""><?php esc_html_e( '— All Initiators —', 'activity-monitor' ); ?></option>
						<?php foreach ( $initiator_options as $init_val => $init_label ) : ?>
							<option value="<?php echo esc_attr( $init_val ); ?>" <?php selected( $init_val, $initiator ); ?>>
								<?php echo esc_html( $init_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="am-filter-group">
					<span class="am-filter-label"><?php esc_html_e( 'Type:', 'activity-monitor' ); ?></span>
					<select name="am_type" onchange="this.form.submit()">
						<option value=""><?php esc_html_e( '— All Types —', 'activity-monitor' ); ?></option>
						<?php foreach ( $type_groups as $group ) : ?>
							<?php if ( 1 === count( $group['options'] ) ) : ?>
								<?php $only = $group['options'][0]; ?>
								<option value="<?php echo esc_attr( $only['value'] ); ?>" <?php selected( $only['value'], $type_filter ); ?>>
									<?php echo esc_html( $only['label'] ); ?>
								</option>
							<?php else : ?>
								<optgroup label="<?php echo esc_attr( $group['label'] ); ?>">
									<option value="<?php echo esc_attr( $group['value'] ); ?>" <?php selected( $group['value'], $type_filter ); ?>>
										<?php
										/* translators: %s: event type name, e.g. "Media" */
										printf( esc_html__( 'All %s', 'activity-monitor' ), esc_html( $group['label'] ) );
										?>
									</option>
									<?php foreach ( $group['options'] as $opt ) : ?>
										<option value="<?php echo esc_attr( $opt['value'] ); ?>" <?php selected( $opt['value'], $type_filter ); ?>>
											<?php echo esc_html( $opt['label'] ); ?>
										</option>
									<?php endforeach; ?>
								</optgroup>
							<?php endif; ?>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="am-filter-group">
					<span class="am-filter-label"><?php esc_html_e( 'From:', 'activity-monitor' ); ?></span>
					<input type="date" name="am_from" value="<?php echo esc_attr( $date_from ); ?>">
					<span class="am-filter-label"><?php esc_html_e( 'To:', 'activity-monitor' ); ?></span>
					<input type="date" name="am_to" value="<?php echo esc_attr( $date_to ); ?>">
				</div>

				<div class="am-filter-group am-filter-search">
					<input type="search" name="am_search"
					       value="<?php echo esc_attr( $search ); ?>"
					       placeholder="<?php esc_attr_e( 'Search message, user, object…', 'activity-monitor' ); ?>">
					<button type="submit" class="button"><?php esc_html_e( 'Search', 'activity-monitor' ); ?></button>
					<?php if ( $level || $initiator || $type_filter || $action || $user || $date_from || $date_to || $search ) : ?>
						<a href="<?php echo esc_url( $base_url ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Reset', 'activity-monitor' ); ?>
						</a>
					<?php endif; ?>
				</div>
			</form>

			<div class="am-export-bar">
				<span class="am-filter-label"><?php esc_html_e( 'Export filtered results:', 'activity-monitor' ); ?></span>
				<?php
				// Note: the event 'action' filter (e.g. 'created', 'deleted')
				// is passed as am_export_action here, not 'action' -- that key
				// is reserved by admin-post.php for its own dispatch routing
				// (action=am_export_log) and would collide otherwise.
				$export_filter_args = array(
					'am_level'      => $level,
					'am_initiator'  => $initiator,
					'am_type'       => $type_filter,
					'am_export_action' => $action,
					'am_user'       => $user,
					'am_from'       => $date_from,
					'am_to'         => $date_to,
					'am_search'     => $search,
				);
				foreach ( array( 'csv' => 'CSV', 'json' => 'JSON', 'html' => 'HTML', 'txt' => 'TXT' ) as $fmt => $label ) :
					$export_url = wp_nonce_url(
						add_query_arg(
							array_merge( $export_filter_args, array( 'action' => 'am_export_log', 'am_format' => $fmt ) ),
							admin_url( 'admin-post.php' )
						),
						AM_Export::NONCE_ACTION
					);
					?>
					<a href="<?php echo esc_url( $export_url ); ?>" class="button button-secondary button-small"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="am-table-wrap am-table-scroll">
			<?php if ( empty( $items ) ) : ?>
				<div class="am-empty">
					<span class="dashicons dashicons-info-outline"></span>
					<p><?php esc_html_e( 'No activity recorded yet.', 'activity-monitor' ); ?></p>
				</div>
			<?php else : ?>
			<table class="wp-list-table widefat am-log-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Level',      'activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'Type',       'activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'Date',       'activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'Initiator',  'activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'User',       'activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'IP Address', 'activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'Message',    'activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'Actions',    'activity-monitor' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $items as $row ) : ?>
					<tr class="am-row am-row-am-<?php echo esc_attr( $row->level ); ?>">
						<td><span class="am-badge am-<?php echo esc_attr( $row->level ); ?>"><?php echo esc_html( AM_Log_Levels::label( $row->level ) ); ?></span></td>
						<td class="am-type-cell" title="<?php echo esc_attr( AM_Event_Labels::raw( $row->event_type, $row->action ) ); ?>"><?php echo esc_html( AM_Event_Labels::label( $row->event_type, $row->action ) ); ?></td>
						<td>
							<span class="am-datetime-cell" title="<?php echo esc_attr( $row->date ); ?> UTC"><?php echo esc_html( wp_date( AM_Date_Format::combined(), strtotime( $row->date ) ) ); ?></span>
						</td>
						<td><span class="am-badge am-init-<?php echo esc_attr( $row->initiator ); ?>"><?php echo esc_html( AM_Initiator_Detector::label( $row->initiator ) ); ?></span></td>
						<td>
							<?php if ( (int) $row->user_id > 0 && '' !== $row->user_login ) : ?>
								<a href="#" class="am-user-profile-link" data-user-id="<?php echo esc_attr( (int) $row->user_id ); ?>"><?php echo esc_html( $row->user_login ); ?></a>
							<?php else : ?>
								<?php echo esc_html( '' !== $row->user_login ? $row->user_login : '—' ); ?>
							<?php endif; ?>
						</td>
						<td class="am-ip-cell" title="<?php echo esc_attr( $row->ip_address ); ?>"><a href="#" class="am-ip-lookup" data-ip="<?php echo esc_attr( $row->ip_address ); ?>"><?php echo esc_html( $row->ip_address ); ?></a></td>
						<td class="am-log-message-cell" title="<?php echo esc_attr( $row->message ); ?>"><span class="am-log-message-clamp"><?php echo esc_html( $row->message ); ?></span></td>
						<td>
							<button class="button button-small am-view-detail-v2"
							        data-id="<?php echo esc_attr( $row->id ); ?>">
								<?php esc_html_e( 'Details', 'activity-monitor' ); ?>
							</button>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $num_pages > 1 ) : ?>
			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<span class="displaying-num">
						<?php printf(
							esc_html( _n( '%s item', '%s items', $total, 'activity-monitor' ) ),
							number_format_i18n( $total )
						); ?>
					</span>
					<?php
					echo wp_kses_post( paginate_links( array(
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
						'total'     => $num_pages,
						'current'   => $page,
					) ) );
					?>
				</div>
			</div>
			<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── Tab: Dashboard (formerly "Stats & Insights", spec §4) ─────────────

	private function render_tab_stats() {
		$days            = absint( $_GET['am_days'] ?? 7 );
		$days            = in_array( $days, array( 7, 14, 30 ), true ) ? $days : 7;
		$base            = add_query_arg( array( 'page' => 'activity-monitor', self::TAB_PARAM => 'stats' ), admin_url( 'admin.php' ) );
		$traffic_enabled = '1' === get_option( 'am_traffic_enabled', '1' );

		$totals         = AM_Event_Query::get_totals_for_period( $days );
		$trend_by_level = AM_Event_Query::get_daily_trend_by_level( $days );
		$peak           = AM_Event_Query::get_peak_activity( $days );
		$by_level       = AM_Event_Query::get_breakdown_by_level( $days );
		$notable_events = AM_Event_Query::get_notable_events( $days, 4 );

		// Traffic data reuses the same period filter as the rest of this
		// tab, even though the Traffic tab itself defaults to 7/14/30 too --
		// sharing one selector keeps every widget on this page looking at
		// the same window rather than each picking its own range.
		$traffic_totals = AM_Traffic_Query::get_totals_for_period( $days );
		$traffic_trend  = AM_Traffic_Query::get_daily_trend( $days );
		$top_pages      = AM_Traffic_Query::get_top_pages( $days, 8 );

		// Traffic source can only come from raw hits (referrer isn't
		// carried into the daily rollup), so its real coverage is capped
		// by the raw-hit retention setting. Where the selected window is
		// longer than that, the card says so rather than presenting a
		// shorter period as if it were the full one. Retention 0 never
		// prunes, so there's nothing to warn about.
		$traffic_sources   = AM_Traffic_Query::get_traffic_sources( $days );
		$traffic_retention = absint( get_option( 'am_traffic_retention_days', 30 ) );
		$sources_truncated = ( $traffic_retention > 0 && $traffic_retention < $days );

		$delta        = $totals['current'] - $totals['previous'];
		$delta_str    = $delta >= 0 ? "+{$delta}" : (string) $delta;

		$traffic_delta        = $traffic_totals['current'] - $traffic_totals['previous'];
		$traffic_delta_str    = $traffic_delta >= 0 ? "+{$traffic_delta}" : (string) $traffic_delta;
		$max_in_traffic_trend = max( array_merge( $traffic_trend, array( 1 ) ) );

		// "Needs attention" KPI -- warning level and above, summed from the
		// level breakdown already fetched above rather than a second query.
		// Same level set get_notable_events() uses below, derived from the
		// WARNING constant's position in AM_Log_Levels::ORDER instead of a
		// second hardcoded list, so the two can't drift out of sync.
		$notable_levels  = array_slice( AM_Log_Levels::ORDER, array_search( AM_Log_Levels::WARNING, AM_Log_Levels::ORDER, true ) );
		$needs_attention = 0;
		foreach ( $notable_levels as $lvl ) {
			$needs_attention += $by_level[ $lvl ] ?? 0;
		}
		?>

		<div class="am-filter-bar">
			<div class="am-filter-group">
				<span class="am-filter-label"><?php esc_html_e( 'Period:', 'activity-monitor' ); ?></span>
				<?php foreach ( array( 7, 14, 30 ) as $option ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'am_days', $option, $base ) ); ?>"
					   class="am-pill <?php echo $option === $days ? 'active' : ''; ?>">
						<?php printf( esc_html( _n( 'Last %d day', 'Last %d days', $option, 'activity-monitor' ) ), $option ); ?>
					</a>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="am-stats-grid">
			<div class="am-stats-card">
				<div class="am-stats-card-value"><?php echo esc_html( number_format_i18n( $totals['current'] ) ); ?></div>
				<div class="am-stats-card-label"><?php esc_html_e( 'Total events', 'activity-monitor' ); ?></div>
				<div class="am-stats-card-delta"><?php echo esc_html( $delta_str ); ?> <?php esc_html_e( 'vs. previous period', 'activity-monitor' ); ?></div>
			</div>

			<?php if ( $peak['busiest_day'] && $peak['busiest_hour'] ) : ?>
			<div class="am-stats-card">
				<div class="am-stats-card-value"><?php echo esc_html( sprintf( '%1$s, %2$s', $peak['busiest_day']['name'], wp_date( 'g A', mktime( $peak['busiest_hour']['hour'], 0, 0 ) ) ) ); ?></div>
				<div class="am-stats-card-label"><?php esc_html_e( 'Peak activity', 'activity-monitor' ); ?></div>
				<div class="am-stats-card-delta"><?php echo esc_html( number_format_i18n( $peak['busiest_hour']['count'] ) ); ?> <?php esc_html_e( 'events at that hour', 'activity-monitor' ); ?></div>
			</div>
			<?php elseif ( $peak['busiest_day'] ) : ?>
			<div class="am-stats-card">
				<div class="am-stats-card-value"><?php echo esc_html( $peak['busiest_day']['name'] ); ?></div>
				<div class="am-stats-card-label"><?php esc_html_e( 'Busiest day', 'activity-monitor' ); ?></div>
				<div class="am-stats-card-delta"><?php echo esc_html( number_format_i18n( $peak['busiest_day']['count'] ) ); ?> <?php esc_html_e( 'events', 'activity-monitor' ); ?></div>
			</div>
			<?php elseif ( $peak['busiest_hour'] ) : ?>
			<div class="am-stats-card">
				<div class="am-stats-card-value"><?php echo esc_html( wp_date( 'g A', mktime( $peak['busiest_hour']['hour'], 0, 0 ) ) ); ?></div>
				<div class="am-stats-card-label"><?php esc_html_e( 'Busiest hour', 'activity-monitor' ); ?></div>
				<div class="am-stats-card-delta"><?php echo esc_html( number_format_i18n( $peak['busiest_hour']['count'] ) ); ?> <?php esc_html_e( 'events', 'activity-monitor' ); ?></div>
			</div>
			<?php endif; ?>

			<?php if ( $traffic_enabled ) : ?>
			<div class="am-stats-card">
				<div class="am-stats-card-value"><?php echo esc_html( number_format_i18n( $traffic_totals['current'] ) ); ?></div>
				<div class="am-stats-card-label"><?php esc_html_e( 'Page views', 'activity-monitor' ); ?></div>
				<div class="am-stats-card-delta"><?php echo esc_html( $traffic_delta_str ); ?> <?php esc_html_e( 'vs. previous period', 'activity-monitor' ); ?></div>
			</div>
			<?php endif; ?>

			<div class="am-stats-card">
				<div class="am-stats-card-value<?php echo $needs_attention > 0 ? ' am-attention' : ''; ?>"><?php echo esc_html( number_format_i18n( $needs_attention ) ); ?></div>
				<div class="am-stats-card-label"><?php esc_html_e( 'Needs attention', 'activity-monitor' ); ?></div>
				<div class="am-stats-card-delta"><?php esc_html_e( 'Warning level and above', 'activity-monitor' ); ?></div>
			</div>
		</div>

		<div class="am-chart-row">
			<div class="am-settings-section am-chart-row-item">
				<h2 class="am-section-title"><?php esc_html_e( 'Daily activity', 'activity-monitor' ); ?></h2>
				<?php $this->render_stacked_trend_chart( $trend_by_level ); ?>
			</div>

			<?php if ( $traffic_enabled ) : ?>
			<div class="am-settings-section am-chart-row-item">
				<h2 class="am-section-title"><?php esc_html_e( 'Top pages', 'activity-monitor' ); ?></h2>
				<?php if ( empty( $top_pages ) ) : ?>
					<p class="am-description"><?php esc_html_e( 'No traffic data for this period yet.', 'activity-monitor' ); ?></p>
				<?php else :
					$page_rows = array();
					foreach ( $top_pages as $page ) {
						$page_rows[] = array(
							'label'      => $page['url'],
							'count'      => $page['views'],
							'href'       => $page['full_url'],
							'title_attr' => $page['url'],
							'sublabel'   => sprintf(
								/* translators: %s: number of unique visitors, already formatted */
								__( '%s unique', 'activity-monitor' ),
								number_format_i18n( $page['unique_ips'] )
							),
						);
					}
					$this->render_ranked_bars( $page_rows );
				endif; ?>
			</div>
			<?php endif; ?>
		</div>

		<?php if ( $traffic_enabled ) : ?>
		<div class="am-chart-row">
			<div class="am-settings-section am-chart-row-item">
				<h2 class="am-section-title"><?php esc_html_e( 'Daily page views', 'activity-monitor' ); ?></h2>
				<div class="am-trend-chart">
					<?php foreach ( $traffic_trend as $date => $count ) :
						$height_pct = $max_in_traffic_trend > 0 ? round( ( $count / $max_in_traffic_trend ) * 100 ) : 0;
					?>
						<div class="am-trend-bar-wrap" title="<?php echo esc_attr( number_format_i18n( $count ) ); ?>">
							<div class="am-trend-bar" style="height: <?php echo esc_attr( max( 2, $height_pct ) ); ?>%;"></div>
							<div class="am-trend-bar-label"><?php echo esc_html( wp_date( 'M j', strtotime( $date ) ) ); ?></div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="am-settings-section am-chart-row-item">
				<h2 class="am-section-title"><?php esc_html_e( 'Traffic source', 'activity-monitor' ); ?></h2>
				<?php if ( array_sum( $traffic_sources ) <= 0 ) : ?>
					<p class="am-description"><?php esc_html_e( 'No referrer data for this period yet.', 'activity-monitor' ); ?></p>
				<?php else : ?>
					<?php $this->render_pie_chart( $traffic_sources, 'src-', array( 'AM_Traffic_Query', 'source_label' ) ); ?>
					<?php if ( $sources_truncated ) : ?>
						<p class="am-description am-pie-note">
							<?php
							printf(
								/* translators: %d: number of days of raw hits still retained */
								esc_html__( 'Referrers are only kept on raw hits, which are pruned after %d days — this breakdown covers that shorter window, not the full period selected above.', 'activity-monitor' ),
								absint( $traffic_retention )
							);
							?>
						</p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php endif; ?>

		<?php if ( ! $traffic_enabled ) : ?>
		<div class="am-settings-section">
			<h2 class="am-section-title"><?php esc_html_e( 'Traffic', 'activity-monitor' ); ?></h2>
			<p class="am-description">
				<?php esc_html_e( 'Traffic logging is currently disabled.', 'activity-monitor' ); ?>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'activity-monitor', self::TAB_PARAM => 'settings' ), admin_url( 'admin.php' ) ) ); ?>">
					<?php esc_html_e( 'Enable it in Settings.', 'activity-monitor' ); ?>
				</a>
			</p>
		</div>
		<?php endif; ?>

		<div class="am-settings-section">
			<h2 class="am-section-title"><?php esc_html_e( 'Recent notable events', 'activity-monitor' ); ?></h2>
			<?php if ( empty( $notable_events ) ) : ?>
				<p class="am-description"><?php esc_html_e( 'No notable events in this period.', 'activity-monitor' ); ?></p>
			<?php else : ?>
				<div class="am-table-wrap am-table-scroll">
					<table class="wp-list-table widefat am-log-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Level', 'activity-monitor' ); ?></th>
								<th><?php esc_html_e( 'Date', 'activity-monitor' ); ?></th>
								<th><?php esc_html_e( 'Message', 'activity-monitor' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $notable_events as $row ) : ?>
							<tr class="am-row am-row-am-<?php echo esc_attr( $row->level ); ?>">
								<td><span class="am-badge am-<?php echo esc_attr( $row->level ); ?>"><?php echo esc_html( AM_Log_Levels::label( $row->level ) ); ?></span></td>
								<td>
									<span title="<?php echo esc_attr( $row->date ); ?> UTC">
										<?php echo esc_html( wp_date( AM_Date_Format::combined(), strtotime( $row->date ) ) ); ?>
									</span>
								</td>
								<td><?php echo esc_html( $row->message ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
			<p class="am-recent-events-link">
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'activity-monitor', self::TAB_PARAM => 'log' ), admin_url( 'admin.php' ) ) ); ?>">
					<?php esc_html_e( 'View full activity log', 'activity-monitor' ); ?> &rarr;
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Renders the Dashboard's Daily activity chart: one stacked column
	 * per day, segmented by level, with a horizontal legend beneath.
	 *
	 * Why stacked rather than the plain single-value bars this used to
	 * be, and rather than the pie this replaced: log levels are an
	 * *ordinal* scale, and the --am-pie-* palette they use is a severity
	 * ramp -- adjacent levels deliberately resemble each other. A pie
	 * throws that ordering away and asks color to carry the entire
	 * identity burden alone, which is what made the ramp fight it. Here
	 * the x-axis carries time and the stack carries severity order, so
	 * the ramp reinforces position instead of competing with it, and one
	 * chart answers both "how much" and "how bad" at once.
	 *
	 * Segments are emitted most-severe-first so the severe levels sit at
	 * the *top* of each column, riding on a base of info/debug -- that's
	 * the thing you're scanning for, so it belongs at the silhouette edge
	 * rather than buried at the bottom.
	 *
	 * @param array<string, array<string,int>> $trend_by_level 'Y-m-d' => level => count,
	 *        zero-filled (see AM_Event_Query::get_daily_trend_by_level()).
	 */
	private function render_stacked_trend_chart( array $trend_by_level ) {
		$totals = array_map( 'array_sum', $trend_by_level );
		$max    = max( array_merge( array_values( $totals ), array( 1 ) ) ); // avoid div-by-zero

		// Period-wide per-level totals, for the legend counts below.
		$period = array();
		foreach ( AM_Log_Levels::ORDER as $level ) {
			$period[ $level ] = 0;
		}
		foreach ( $trend_by_level as $day_counts ) {
			foreach ( $day_counts as $level => $count ) {
				$period[ $level ] += $count;
			}
		}
		?>
		<div class="am-stack-chart">
			<?php foreach ( $trend_by_level as $date => $day_counts ) :
				$day_total  = $totals[ $date ];
				$col_height = $max > 0 ? round( ( $day_total / $max ) * 100 ) : 0;
				$day_label  = wp_date( 'M j', strtotime( $date ) );
			?>
				<div class="am-stack-col-wrap" title="<?php
					/* translators: 1: date, 2: number of events, already formatted */
					echo esc_attr( sprintf( __( '%1$s — %2$s events', 'activity-monitor' ), $day_label, number_format_i18n( $day_total ) ) );
				?>">
					<div class="am-stack-col-area">
						<?php if ( $day_total > 0 ) : ?>
							<div class="am-stack-col" style="height: <?php echo esc_attr( max( 2, $col_height ) ); ?>%;">
								<?php foreach ( array_reverse( AM_Log_Levels::ORDER ) as $level ) :
									$count = $day_counts[ $level ] ?? 0;
									if ( $count <= 0 ) {
										continue;
									}
								?>
									<div class="am-stack-seg"
										style="height: <?php echo esc_attr( round( ( $count / $day_total ) * 100, 2 ) ); ?>%; background: var(--am-pie-<?php echo esc_attr( $level ); ?>, #646970);"
										title="<?php
											/* translators: 1: date, 2: level label, 3: number of events, already formatted */
											echo esc_attr( sprintf( __( '%1$s · %2$s: %3$s', 'activity-monitor' ), $day_label, AM_Log_Levels::label( $level ), number_format_i18n( $count ) ) );
										?>"></div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
					<div class="am-stack-label"><?php echo esc_html( $day_label ); ?></div>
				</div>
			<?php endforeach; ?>
		</div>

		<?php
		// Only levels actually present in the window get a legend entry.
		// The old pie legend listed all eight regardless, which read fine
		// as a vertical list beside the chart; as a horizontal strip under
		// the columns, five permanently-zero entries would be pure noise.
		$present = array_filter( $period );
		if ( ! empty( $present ) ) :
		?>
			<ul class="am-stack-legend">
				<?php foreach ( AM_Log_Levels::ORDER as $level ) :
					if ( empty( $present[ $level ] ) ) {
						continue;
					}
				?>
					<li class="am-stack-legend-item">
						<span class="am-pie-swatch" style="background: var(--am-pie-<?php echo esc_attr( $level ); ?>, #646970);"></span>
						<?php echo esc_html( AM_Log_Levels::label( $level ) ); ?>
						<span class="am-stack-legend-count"><?php echo esc_html( number_format_i18n( $period[ $level ] ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php
		endif;
	}

	/**
	 * Sanitizes the Activity Log's am_type value, which may be a bare
	 * event_type or a "type|action" pair.
	 *
	 * Deliberately not sanitize_key(): that strips both the dot a
	 * migrated v1.x slug can contain and the pipe separating the two
	 * halves, either of which would quietly turn a valid filter into
	 * one matching nothing.
	 */
	private static function sanitize_type_filter( $raw ): string {
		return preg_replace( '/[^a-z0-9_.|\-]/', '', strtolower( (string) $raw ) );
	}

	/**
	 * Renders a pie chart + legend for a breakdown array. Currently used
	 * by the Dashboard's Traffic source card, via $prefix = 'src-'.
	 *
	 * It previously rendered Events by level, which was removed in
	 * 2.0.59 when Daily activity became a stacked column chart. That the
	 * second use works where the first didn't is the whole point:
	 * traffic sources are nominal, few in number, and a genuine whole,
	 * which is what a pie is for. Log levels are an ordinal severity
	 * ramp, which is what it isn't -- a pie discards the ordering and
	 * then asks a palette built to blend to carry identity by itself.
	 * Worth re-reading before putting anything else in here.
	 *
	 * $prefix keeps the CSS-var naming ("--am-pie-{prefix}{key}") and
	 * badge-class naming this file already uses elsewhere working
	 * across different breakdowns, so a new one needs only its own
	 * palette rather than any change here. It also fed an Events by
	 * initiator pie ($prefix = 'init-') once, removed for being ~all
	 * one slice in practice.
	 *
	 * The pie itself is plain server-rendered SVG (no JS/chart library,
	 * consistent with the rest of this plugin's hand-rolled charts) --
	 * each slice is a wedge path computed from cumulative percentages,
	 * filled from --am-pie-* (defined at the top of admin.css, which
	 * documents which tier of each level's filter-pill palette those
	 * take and why the other two were tried and rejected). The
	 * legend pairs each slice with a small color swatch in that same
	 * color rather than the actual badge pill, since a legend just
	 * needs "this color = this label", not the full badge treatment.
	 * Each wedge also gets a native SVG <title> child, so hovering it
	 * shows the browser's own tooltip with that slice's label, count,
	 * and percentage -- no JS or custom tooltip markup needed.
	 *
	 * @param array<string,int> $counts   key => count, zero-filled (see
	 *                                    AM_Event_Query::get_breakdown_by_level()).
	 * @param string            $prefix   '' for level; a future caller with its own
	 *                                    badge/color namespace would use its own prefix.
	 * @param callable          $label_fn Maps a key to its display label.
	 */
	private function render_pie_chart( array $counts, string $prefix, callable $label_fn ) {
		$total = array_sum( $counts );

		// Zero-count entries never get a slice (a 0% wedge is degenerate
		// SVG path data), but they still appear in the legend below --
		// same "always show every level/initiator" behavior as before.
		$slices = array();
		$angle  = -90; // Start at 12 o'clock; increasing angle sweeps clockwise since SVG y grows downward.
		foreach ( $counts as $key => $count ) {
			if ( $count <= 0 ) {
				continue;
			}
			$start_angle = $angle;
			$angle      += ( $count / $total ) * 360;
			$slices[]    = array(
				'key'   => $key,
				'start' => $start_angle,
				'end'   => $angle,
				'count' => $count,
			);
		}

		// Native SVG <title> child -- the browser's own hover tooltip,
		// no JS/CSS needed. Shared by both the single-slice circle and
		// the multi-slice path branches below.
		$tooltip = function ( $key, $count ) use ( $label_fn, $total ) {
			$pct = $total > 0 ? round( ( $count / $total ) * 100 ) : 0;
			return sprintf(
				/* translators: 1: level label, 2: event count, 3: percentage of total */
				__( '%1$s: %2$s (%3$s%%)', 'activity-monitor' ),
				$label_fn( $key ),
				number_format_i18n( $count ),
				$pct
			);
		};
		?>
		<div class="am-pie-row">
			<svg class="am-pie-svg" viewBox="0 0 100 100" role="img" aria-hidden="true">
				<?php if ( 0 === $total ) : ?>
					<circle cx="50" cy="50" r="48" fill="#f0f0f1"></circle>
				<?php elseif ( 1 === count( $slices ) ) : // A single non-zero category is a full circle -- a 360° wedge path degenerates to a point. ?>
					<circle cx="50" cy="50" r="48" fill="var(--am-pie-<?php echo esc_attr( $prefix . $slices[0]['key'] ); ?>, #646970)" class="am-pie-slice"><title><?php echo esc_html( $tooltip( $slices[0]['key'], $slices[0]['count'] ) ); ?></title></circle>
				<?php else : ?>
					<?php foreach ( $slices as $slice ) : ?>
						<path d="<?php echo esc_attr( self::pie_slice_path( $slice['start'], $slice['end'] ) ); ?>" fill="var(--am-pie-<?php echo esc_attr( $prefix . $slice['key'] ); ?>, #646970)" class="am-pie-slice"><title><?php echo esc_html( $tooltip( $slice['key'], $slice['count'] ) ); ?></title></path>
					<?php endforeach; ?>
				<?php endif; ?>
			</svg>
			<ul class="am-pie-legend">
				<?php foreach ( $counts as $key => $count ) :
					$pct = $total > 0 ? round( ( $count / $total ) * 100 ) : 0;
				?>
					<li class="am-pie-legend-row">
						<span class="am-pie-legend-label"><span class="am-pie-swatch" style="background: var(--am-pie-<?php echo esc_attr( $prefix . $key ); ?>, #646970);"></span><?php echo esc_html( $label_fn( $key ) ); ?></span>
						<span class="am-pie-legend-count"><?php echo esc_html( number_format_i18n( $count ) ); ?> <span class="am-pie-legend-pct">(<?php echo esc_html( $pct ); ?>%)</span></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * SVG path data for one pie wedge, as a "moveto center, lineto edge,
	 * arc, closepath" wedge (not just an arc) so it fills solid to the
	 * center like a real pie slice rather than a ring segment.
	 */
	private static function pie_slice_path( float $start_deg, float $end_deg ): string {
		$cx = 50;
		$cy = 50;
		$r  = 48;

		$x1 = $cx + $r * cos( deg2rad( $start_deg ) );
		$y1 = $cy + $r * sin( deg2rad( $start_deg ) );
		$x2 = $cx + $r * cos( deg2rad( $end_deg ) );
		$y2 = $cy + $r * sin( deg2rad( $end_deg ) );

		$large_arc = ( $end_deg - $start_deg ) > 180 ? 1 : 0;

		return sprintf(
			'M %d,%d L %s,%s A %d,%d 0 %d,1 %s,%s Z',
			$cx,
			$cy,
			number_format( $x1, 2, '.', '' ),
			number_format( $y1, 2, '.', '' ),
			$r,
			$r,
			$large_arc,
			number_format( $x2, 2, '.', '' ),
			number_format( $y2, 2, '.', '' )
		);
	}

	/**
	 * Renders a ranked horizontal bar list -- currently only used by
	 * Top pages (Top event types and Most active users, its former
	 * co-users, were removed from the Dashboard). Distinct from
	 * render_pie_chart() above: a ranked list is a magnitude ranking
	 * within a single series (page A got more views than page B), not a
	 * share of one whole, so every bar shares one accent color rather
	 * than a category color scheme that would wrongly imply they're
	 * parts of a pie.
	 *
	 * @param array<array{label:string,count:int,href?:string,title_attr?:string,sublabel?:string}> $rows
	 */
	private function render_ranked_bars( array $rows ) {
		if ( empty( $rows ) ) {
			return;
		}
		$max = max( array_merge( wp_list_pluck( $rows, 'count' ), array( 1 ) ) );
		?>
		<div class="am-rank-list">
			<?php foreach ( $rows as $row ) :
				$pct = $max > 0 ? round( ( $row['count'] / $max ) * 100 ) : 0;
			?>
				<div class="am-rank-row">
					<div class="am-rank-row-top">
						<span class="am-rank-label">
							<?php if ( ! empty( $row['href'] ) ) : ?>
								<a href="<?php echo esc_url( $row['href'] ); ?>" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr( $row['title_attr'] ?? $row['label'] ); ?>">
							<?php endif; ?>
							<?php echo esc_html( $row['label'] ); ?>
							<?php if ( ! empty( $row['href'] ) ) : ?>
								</a>
							<?php endif; ?>
						</span>
						<span class="am-rank-count">
							<?php echo esc_html( number_format_i18n( $row['count'] ) ); ?>
							<?php if ( ! empty( $row['sublabel'] ) ) : ?>
								<span class="am-rank-sublabel"><?php echo esc_html( $row['sublabel'] ); ?></span>
							<?php endif; ?>
						</span>
					</div>
					<div class="am-rank-track"><span class="am-rank-fill" style="width: <?php echo esc_attr( max( 2, $pct ) ); ?>%;"></span></div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	// ── Tab: Traffic ────────────────────────────────────────────────────

	private function render_tab_traffic() {
		?>

		<?php if ( '1' !== get_option( 'am_traffic_enabled', '1' ) ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php esc_html_e( 'Traffic logging is currently disabled.', 'activity-monitor' ); ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'activity-monitor', self::TAB_PARAM => 'settings' ), admin_url( 'admin.php' ) ) ); ?>">
						<?php esc_html_e( 'Enable it in Settings.', 'activity-monitor' ); ?>
					</a>
				</p>
			</div>
		<?php endif; ?>

		<h2 class="am-section-title">
			<span class="am-live-dot"></span>
			<?php esc_html_e( 'Live traffic', 'activity-monitor' ); ?>
		</h2>
		<div class="am-table-wrap am-table-scroll">
			<table class="wp-list-table widefat am-log-table" id="am-live-traffic-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'Page', 'activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'Visitor', 'activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'IP Address', 'activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'activity-monitor' ); ?></th>
					</tr>
				</thead>
				<tbody id="am-live-traffic-body">
					<tr><td colspan="5"><?php esc_html_e( 'Waiting for the next page view…', 'activity-monitor' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	// ── Tab: Active Sessions ──────────────────────────────────────────────

	private function render_tab_sessions() {
		$users = get_users( array( 'fields' => array( 'ID', 'user_login' ) ) );

		$sessions_data = array();

		foreach ( $users as $user ) {
			$raw      = get_user_meta( $user->ID, 'session_tokens', true );
			$sessions = is_array( $raw ) ? $raw : array();

			// Skip before the name lookup below: most users on a site have
			// no active session, and there's no reason to resolve a name
			// for a row that will never be rendered.
			if ( empty( $sessions ) ) {
				continue;
			}

			// Resolved once per user rather than once per session, since a
			// user can hold several at a time.
			$name = self::real_name( $user->ID );

			foreach ( $sessions as $token_hash => $session ) {
				$sessions_data[] = array(
					'user_id'      => $user->ID,
					'user_login'   => $user->user_login,
					'name'         => $name,
					'token_hash'   => $token_hash,
					'expiration'   => $session['expiration'] ?? 0,
					'login'        => $session['login']      ?? 0,
					'ip'           => $session['ip']         ?? __( 'Unknown', 'activity-monitor' ),
					'ua'           => $session['ua']         ?? '',
				);
			}
		}

		usort( $sessions_data, function ( $a, $b ) {
			return $b['login'] - $a['login'];
		} );

		$current_token_hash = hash( 'sha256', wp_get_session_token() );
		$now                = time();
		?>
		<div class="am-table-wrap am-table-scroll">
			<?php if ( empty( $sessions_data ) ) : ?>
				<div class="am-empty">
					<span class="dashicons dashicons-groups"></span>
					<p><?php esc_html_e( 'No active sessions found.', 'activity-monitor' ); ?></p>
				</div>
			<?php else : ?>
			<table class="wp-list-table widefat am-log-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'User',        'activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'Logged In',   'activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'Expiry',      'activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'IP Address',  'activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'Browser / UA','activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'Actions',     'activity-monitor' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $sessions_data as $s ) :
						$is_expired  = ( $s['expiration'] > 0 && $s['expiration'] < $now );
						$is_current  = ( (int) $s['user_id'] === (int) get_current_user_id() && hash_equals( $current_token_hash, $s['token_hash'] ) );
						$expiry_text = $s['expiration']
							? wp_date( AM_Date_Format::combined(), $s['expiration'] )
							: __( 'Never', 'activity-monitor' );
						$login_text  = $s['login']
							? wp_date( AM_Date_Format::combined(), $s['login'] )
							: __( 'Unknown', 'activity-monitor' );
						$browser     = $this->parse_user_agent( $s['ua'] );
						$row_class   = trim( ( $is_expired ? 'am-session-expired' : '' ) . ' ' . ( $is_current ? 'am-session-current' : '' ) );
					?>
					<tr<?php echo $row_class ? ' class="' . esc_attr( $row_class ) . '"' : ''; ?>>
						<td>
							<?php // real_name() returns '' whenever the user never filled in
							      // first/last name, which is common. Falling back to the
							      // login as the primary line avoids an empty <strong> above
							      // a lone gray username, and avoids printing the login twice. ?>
							<?php if ( '' !== $s['name'] ) : ?>
								<strong><?php echo esc_html( $s['name'] ); ?></strong>
								<small class="am-role"><?php echo esc_html( $s['user_login'] ); ?></small>
							<?php else : ?>
								<strong><?php echo esc_html( $s['user_login'] ); ?></strong>
							<?php endif; ?>
							<?php if ( $is_current ) : ?>
								<span class="am-badge am-info"><?php esc_html_e( 'You', 'activity-monitor' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $login_text ); ?></td>
						<td>
							<?php echo esc_html( $expiry_text ); ?>
							<?php if ( $is_expired ) : ?>
								<span class="am-badge am-warning"><?php esc_html_e( 'Expired', 'activity-monitor' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="am-ip-cell" title="<?php echo esc_attr( $s['ip'] ); ?>"><a href="#" class="am-ip-lookup" data-ip="<?php echo esc_attr( $s['ip'] ); ?>"><?php echo esc_html( $s['ip'] ); ?></a></td>
						<td>
							<span title="<?php echo esc_attr( $s['ua'] ); ?>">
								<?php echo esc_html( $browser ); ?>
							</span>
						</td>
						<td>
							<?php /* FIX #2: Only pass user_id + token_hash; the AJAX handler re-fetches everything else. */ ?>
							<button class="button button-small am-view-session-detail"
							        data-user-id="<?php echo esc_attr( $s['user_id'] ); ?>"
							        data-token-hash="<?php echo esc_attr( $s['token_hash'] ); ?>">
								<?php esc_html_e( 'Details', 'activity-monitor' ); ?>
							</button>
							<?php if ( $is_current ) : ?>
								<button class="button button-small" disabled
								        title="<?php esc_attr_e( 'Cannot revoke your own active session.', 'activity-monitor' ); ?>">
									<?php esc_html_e( 'Revoke', 'activity-monitor' ); ?>
								</button>
							<?php else : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
								      onsubmit="return confirm('<?php esc_attr_e( 'Revoke this session? The user will be logged out immediately.', 'activity-monitor' ); ?>')"
								      style="display:inline;">
									<?php wp_nonce_field( 'am_revoke_session' ); ?>
									<input type="hidden" name="action"              value="am_revoke_session">
									<input type="hidden" name="session_user_id"    value="<?php echo esc_attr( $s['user_id'] ); ?>">
									<input type="hidden" name="session_token_hash" value="<?php echo esc_attr( $s['token_hash'] ); ?>">
									<button type="submit" class="button button-small am-btn-danger">
										<?php esc_html_e( 'Revoke', 'activity-monitor' ); ?>
									</button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="am-sessions-note">
				<?php printf(
					esc_html__( '%d active session(s) across %d user(s).', 'activity-monitor' ),
					count( $sessions_data ),
					count( $users )
				); ?>
			</p>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── UA parser ─────────────────────────────────────────────────────────

	private function parse_user_agent( $ua ) {
		if ( empty( $ua ) ) {
			return __( 'Unknown', 'activity-monitor' );
		}
		$browsers = array(
			'Edg' => 'Edge', 'OPR' => 'Opera', 'Chrome' => 'Chrome',
			'Firefox' => 'Firefox', 'Safari' => 'Safari',
			'MSIE' => 'Internet Explorer', 'Trident' => 'Internet Explorer',
		);
		$os_map = array(
			'Windows NT 10' => 'Windows 10/11', 'Windows NT 6' => 'Windows',
			'Mac OS X' => 'macOS', 'Linux' => 'Linux',
			'Android' => 'Android', 'iPhone' => 'iOS', 'iPad' => 'iPadOS',
		);
		$browser = __( 'Other', 'activity-monitor' );
		foreach ( $browsers as $key => $name ) {
			if ( strpos( $ua, $key ) !== false ) { $browser = $name; break; }
		}
		$os = '';
		foreach ( $os_map as $key => $name ) {
			if ( strpos( $ua, $key ) !== false ) { $os = $name; break; }
		}
		return $os ? $browser . ' / ' . $os : $browser;
	}

	// ── Tab: Settings ─────────────────────────────────────────────────────

	private function render_tab_settings() {
		$channels = get_option( 'am_notification_channels', array() );
		?>

		<h2 class="am-settings-group-title"><?php esc_html_e( 'Alerts & Reports', 'activity-monitor' ); ?></h2>

		<div class="am-settings-section">
			<h2 class="am-section-title">
				<span class="dashicons dashicons-bell"></span>
				<?php esc_html_e( 'Notification Channels', 'activity-monitor' ); ?>
			</h2>
			<p class="am-description">
				<?php esc_html_e( 'Configure instant alerts. Each channel triggers when an event meets or exceeds its minimum level. Changes save immediately.', 'activity-monitor' ); ?>
			</p>

			<?php if ( empty( $channels ) ) : ?>
				<p class="am-description"><?php esc_html_e( 'No channels configured yet.', 'activity-monitor' ); ?></p>
				<div class="am-channel-add-buttons">
					<button type="button" class="button button-secondary am-add-channel-btn" data-type="email">
						<span class="dashicons dashicons-email-alt"></span>
						<?php esc_html_e( 'Add Email Channel', 'activity-monitor' ); ?>
					</button>
					<button type="button" class="button button-secondary am-add-channel-btn" data-type="slack">
						<span class="dashicons dashicons-format-chat"></span>
						<?php esc_html_e( 'Add Slack Channel', 'activity-monitor' ); ?>
					</button>
				</div>
			<?php else : ?>
				<div class="am-table-wrap am-table-scroll">
					<table class="wp-list-table widefat am-log-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Type', 'activity-monitor' ); ?></th>
								<th><?php esc_html_e( 'Name', 'activity-monitor' ); ?></th>
								<th><?php esc_html_e( 'Minimum Level', 'activity-monitor' ); ?></th>
								<th><?php esc_html_e( 'Target', 'activity-monitor' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'activity-monitor' ); ?></th>
							</tr>
						</thead>
						<tbody id="am-channels-table-body">
							<?php foreach ( $channels as $i => $ch ) : ?>
								<?php $this->render_channel_table_row( $i, $ch ); ?>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<div class="am-channel-add-buttons">
					<button type="button" class="button button-secondary am-add-channel-btn" data-type="email">
						<span class="dashicons dashicons-email-alt"></span>
						<?php esc_html_e( 'Add Email Channel', 'activity-monitor' ); ?>
					</button>
					<button type="button" class="button button-secondary am-add-channel-btn" data-type="slack">
						<span class="dashicons dashicons-format-chat"></span>
						<?php esc_html_e( 'Add Slack Channel', 'activity-monitor' ); ?>
					</button>
				</div>
			<?php endif; ?>
		</div>

		<hr class="am-section-divider">

		<?php $this->render_digest_section(); ?>

		<hr class="am-section-divider am-group-divider">

		<h2 class="am-settings-group-title"><?php esc_html_e( 'Activity Log', 'activity-monitor' ); ?></h2>

		<div class="am-settings-section am-danger-zone">
			<h2 class="am-section-title am-danger-title">
				<span class="dashicons dashicons-trash"></span>
				<?php esc_html_e( 'Clear Log', 'activity-monitor' ); ?>
			</h2>
			<p class="am-description">
				<?php esc_html_e( 'Permanently delete all entries from the activity log. This action cannot be undone.', 'activity-monitor' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			      onsubmit="return confirm('<?php esc_attr_e( 'Clear all log entries? This cannot be undone.', 'activity-monitor' ); ?>')">
				<?php wp_nonce_field( 'am_clear_log' ); ?>
				<input type="hidden" name="action" value="am_clear_log">
				<button type="submit" class="button am-btn-danger">
					<span class="dashicons dashicons-trash"></span>
					<?php esc_html_e( 'Clear Entire Log', 'activity-monitor' ); ?>
				</button>
			</form>
		</div>

		<hr class="am-section-divider am-group-divider">

		<h2 class="am-settings-group-title"><?php esc_html_e( 'Sessions', 'activity-monitor' ); ?></h2>

		<div class="am-settings-section">
			<h2 class="am-section-title">
				<span class="dashicons dashicons-groups"></span>
				<?php esc_html_e( 'Session Management (v2.0)', 'activity-monitor' ); ?>
			</h2>
			<p class="am-description">
				<?php esc_html_e( 'These settings apply on top of the Active Sessions tab. Sessions themselves are still WordPress\'s own session tokens -- this plugin does not maintain a separate copy.', 'activity-monitor' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'am_save_session_settings' ); ?>
				<input type="hidden" name="action" value="am_save_session_settings">

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="am_session_concurrent_limit"><?php esc_html_e( 'Concurrent session limit', 'activity-monitor' ); ?></label>
						</th>
						<td>
							<input type="number" min="0" id="am_session_concurrent_limit" name="am_session_concurrent_limit"
							       value="<?php echo esc_attr( absint( get_option( 'am_session_concurrent_limit', 0 ) ) ); ?>"
							       class="small-text">
							<p class="description">
								<?php esc_html_e( '0 = disabled. When a user logs in past this limit, their oldest sessions are revoked automatically (the new login always survives).', 'activity-monitor' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="am_session_active_threshold_minutes"><?php esc_html_e( 'Active session threshold', 'activity-monitor' ); ?></label>
						</th>
						<td>
							<input type="number" min="1" id="am_session_active_threshold_minutes" name="am_session_active_threshold_minutes"
							       value="<?php echo esc_attr( absint( get_option( 'am_session_active_threshold_minutes', 30 ) ) ); ?>"
							       class="small-text"> <?php esc_html_e( 'minutes', 'activity-monitor' ); ?>
							<p class="description">
								<?php esc_html_e( 'Display-only: sessions logged in within this window are shown as "active". Does not affect WordPress\'s own session expiration.', 'activity-monitor' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Session Settings', 'activity-monitor' ), 'secondary' ); ?>
			</form>
		</div>

		<hr class="am-section-divider">

		<div class="am-settings-section am-danger-zone">
			<h2 class="am-section-title am-danger-title">
				<span class="dashicons dashicons-warning"></span>
				<?php esc_html_e( 'Session Actions', 'activity-monitor' ); ?>
			</h2>

			<p class="am-description">
				<?php esc_html_e( 'Remove all expired sessions across every user account.', 'activity-monitor' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			      onsubmit="return confirm('<?php esc_attr_e( 'Revoke all expired sessions? This cannot be undone.', 'activity-monitor' ); ?>')">
				<?php wp_nonce_field( 'am_revoke_expired' ); ?>
				<input type="hidden" name="action" value="am_revoke_expired">
				<button type="submit" class="button am-btn-danger">
					<span class="dashicons dashicons-remove"></span>
					<?php esc_html_e( 'Revoke All Expired Sessions', 'activity-monitor' ); ?>
				</button>
			</form>

			<br>

			<p class="am-description">
				<?php esc_html_e( 'Immediately terminate every active session on the site except your own. Every other logged-in user will be logged out.', 'activity-monitor' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			      onsubmit="return confirm('<?php esc_attr_e( 'Emergency lockdown: terminate ALL other sessions immediately? Every other logged-in user will be signed out right now. This cannot be undone.', 'activity-monitor' ); ?>')">
				<?php wp_nonce_field( 'am_emergency_lockdown' ); ?>
				<input type="hidden" name="action" value="am_emergency_lockdown">
				<button type="submit" class="button am-btn-danger">
					<span class="dashicons dashicons-lock"></span>
					<?php esc_html_e( 'Emergency Lockdown', 'activity-monitor' ); ?>
				</button>
			</form>
		</div>

		<hr class="am-section-divider am-group-divider">

		<h2 class="am-settings-group-title"><?php esc_html_e( 'Page Traffic', 'activity-monitor' ); ?></h2>

		<div class="am-settings-section">
			<h2 class="am-section-title">
				<span class="dashicons dashicons-clock"></span>
				<?php esc_html_e( 'Date &amp; Time Display', 'activity-monitor' ); ?>
			</h2>
			<p class="am-description">
				<?php esc_html_e( 'How timestamps are shown throughout the plugin — the activity log, the sessions and traffic tables, the detail popups, and the times reported on this screen. Chart labels are excluded, since those are sized to fit their axis. CSV and JSON exports are also excluded: those keep raw UTC values so they stay machine-readable.', 'activity-monitor' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'am_save_display_settings' ); ?>
				<input type="hidden" name="action" value="am_save_display_settings">

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( AM_Date_Format::OPTION ); ?>"><?php esc_html_e( 'Format', 'activity-monitor' ); ?></label>
						</th>
						<td>
							<select id="<?php echo esc_attr( AM_Date_Format::OPTION ); ?>" name="<?php echo esc_attr( AM_Date_Format::OPTION ); ?>">
								<?php foreach ( AM_Date_Format::choices() as $format_key => $format_label ) : ?>
									<option value="<?php echo esc_attr( $format_key ); ?>" <?php selected( $format_key, AM_Date_Format::current_key() ); ?>>
										<?php echo esc_html( $format_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Each option shows how it renders right now. "Site default" follows Settings → General, so it stays in step if those change.', 'activity-monitor' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Display Settings', 'activity-monitor' ) ); ?>
			</form>
		</div>

		<div class="am-settings-section">
			<h2 class="am-section-title">
				<span class="dashicons dashicons-chart-line"></span>
				<?php esc_html_e( 'Page Traffic', 'activity-monitor' ); ?>
			</h2>
			<p class="am-description">
				<?php esc_html_e( 'Logs front-end page views (URL, referrer, IP, visitor) separately from the audit log above. Raw hits are rolled up into daily totals and then pruned; the daily totals themselves are kept indefinitely.', 'activity-monitor' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'am_save_traffic_settings' ); ?>
				<input type="hidden" name="action" value="am_save_traffic_settings">

				<table class="form-table">
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Enable traffic logging', 'activity-monitor' ); ?>
						</th>
						<td>
							<label>
								<input type="checkbox" name="am_traffic_enabled" value="1"
								       <?php checked( get_option( 'am_traffic_enabled', '1' ), '1' ); ?>>
								<?php esc_html_e( 'Log page views on the front end of the site', 'activity-monitor' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="am_traffic_retention_days"><?php esc_html_e( 'Raw hit retention', 'activity-monitor' ); ?></label>
						</th>
						<td>
							<input type="number" min="0" id="am_traffic_retention_days" name="am_traffic_retention_days"
							       value="<?php echo esc_attr( absint( get_option( 'am_traffic_retention_days', 30 ) ) ); ?>"
							       class="small-text"> <?php esc_html_e( 'days', 'activity-monitor' ); ?>
							<p class="description">
								<?php esc_html_e( '0 = never prune. Only affects individual raw hit records; the daily views/top-pages totals are not deleted.', 'activity-monitor' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="am_traffic_live_poll_seconds"><?php esc_html_e( 'Live feed refresh rate', 'activity-monitor' ); ?></label>
						</th>
						<td>
							<input type="number" min="3" max="60" id="am_traffic_live_poll_seconds" name="am_traffic_live_poll_seconds"
							       value="<?php echo esc_attr( absint( get_option( 'am_traffic_live_poll_seconds', 10 ) ) ); ?>"
							       class="small-text"> <?php esc_html_e( 'seconds', 'activity-monitor' ); ?>
							<p class="description">
								<?php esc_html_e( 'How often the live traffic feed checks for new page views while the Traffic tab is open. 3-60 seconds.', 'activity-monitor' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="am_traffic_live_feed_limit"><?php esc_html_e( 'Live feed size', 'activity-monitor' ); ?></label>
						</th>
						<td>
							<input type="number" min="5" max="200" id="am_traffic_live_feed_limit" name="am_traffic_live_feed_limit"
							       value="<?php echo esc_attr( absint( get_option( 'am_traffic_live_feed_limit', 25 ) ) ); ?>"
							       class="small-text"> <?php esc_html_e( 'hits', 'activity-monitor' ); ?>
							<p class="description">
								<?php esc_html_e( 'Number of most recent page views shown in the live feed. 5-200.', 'activity-monitor' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Traffic Settings', 'activity-monitor' ), 'secondary' ); ?>
			</form>
		</div>
		<?php
	}

	// ── Digest settings (spec §4) ──────────────────────────────────────────

	private function render_digest_section() {
		$configs = AM_Digest::get_configs();
		$next_run = wp_next_scheduled( AM_Digest::CRON_HOOK );
		?>
		<div class="am-settings-section">
			<h2 class="am-section-title">
				<span class="dashicons dashicons-email"></span>
				<?php esc_html_e( 'Email Digest', 'activity-monitor' ); ?>
			</h2>
			<p class="am-description">
				<?php esc_html_e( 'A scheduled summary of activity: totals, top event types, and notable (warning-and-above) events, with a link to the full log. Add as many digests as you need -- e.g. a daily summary to one address and a separate weekly one to another. Changes save immediately.', 'activity-monitor' ); ?>
			</p>

			<?php if ( empty( $configs ) ) : ?>
				<p class="am-description"><?php esc_html_e( 'No digests configured yet.', 'activity-monitor' ); ?></p>
				<div class="am-channel-add-buttons">
					<button type="button" class="button button-secondary am-add-digest-btn">
						<span class="dashicons dashicons-plus-alt2"></span>
						<?php esc_html_e( 'Add Digest', 'activity-monitor' ); ?>
					</button>
				</div>
			<?php else : ?>
				<div class="am-table-wrap am-table-scroll">
					<table class="wp-list-table widefat am-log-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Frequency', 'activity-monitor' ); ?></th>
								<th><?php esc_html_e( 'Recipients', 'activity-monitor' ); ?></th>
								<th><?php esc_html_e( 'Last Sent', 'activity-monitor' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'activity-monitor' ); ?></th>
							</tr>
						</thead>
						<tbody id="am-digest-table-body">
							<?php foreach ( $configs as $config ) : ?>
								<?php $this->render_digest_table_row( $config ); ?>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<p class="am-description">
					<?php if ( $next_run ) : ?>
						<?php
						printf(
							/* translators: %s: next scheduled check date/time */
							esc_html__( 'Next check: %s (each digest above sends independently once its own frequency is due).', 'activity-monitor' ),
							esc_html( wp_date( AM_Date_Format::combined(), $next_run ) )
						);
						?>
					<?php endif; ?>
				</p>
				<div class="am-channel-add-buttons">
					<button type="button" class="button button-secondary am-add-digest-btn">
						<span class="dashicons dashicons-plus-alt2"></span>
						<?php esc_html_e( 'Add Digest', 'activity-monitor' ); ?>
					</button>
				</div>
			<?php endif; ?>

			<hr class="am-section-divider">

			<h3 style="font-size: 13px; margin: 0 0 10px;"><?php esc_html_e( 'Preview & Test', 'activity-monitor' ); ?></h3>
			<p class="am-description">
				<?php esc_html_e( 'Not tied to any saved digest above -- pick a frequency to preview or test its content independently.', 'activity-monitor' ); ?>
			</p>
			<div class="am-channel-add-buttons">
				<select id="am-digest-preview-frequency">
					<option value="daily"><?php esc_html_e( 'Daily', 'activity-monitor' ); ?></option>
					<option value="weekly" selected><?php esc_html_e( 'Weekly', 'activity-monitor' ); ?></option>
					<option value="monthly"><?php esc_html_e( 'Monthly', 'activity-monitor' ); ?></option>
				</select>
				<button type="button" class="button button-secondary" id="am-digest-preview">
					<span class="dashicons dashicons-visibility"></span>
					<?php esc_html_e( 'Preview', 'activity-monitor' ); ?>
				</button>
				<input type="email" id="am-digest-test-email" placeholder="<?php esc_attr_e( 'test@example.com', 'activity-monitor' ); ?>" class="regular-text">
				<button type="button" class="button button-secondary" id="am-digest-send-test">
					<span class="dashicons dashicons-email-alt"></span>
					<?php esc_html_e( 'Send Test Email', 'activity-monitor' ); ?>
				</button>
			</div>
			<div id="am-digest-preview-frame-wrap" style="display:none; margin-top: 14px; border: 1px solid #c3c4c7; border-radius: 6px; overflow: hidden;">
				<iframe id="am-digest-preview-frame" style="width: 100%; height: 500px; border: 0;"></iframe>
			</div>
			<p id="am-digest-test-result" class="am-description"></p>
		</div>

		<?php
	}

	/** One row in the Email Digest table (display only — editing happens in the modal). */
	private function render_digest_table_row( array $config ) {
		$frequency  = $config['frequency'] ?? 'weekly';
		$last_sent  = $config['last_sent'] ?? '';
		$freq_labels = array(
			'daily'   => __( 'Daily', 'activity-monitor' ),
			'weekly'  => __( 'Weekly', 'activity-monitor' ),
			'monthly' => __( 'Monthly', 'activity-monitor' ),
		);
		$freq_label = $freq_labels[ $frequency ] ?? ucfirst( $frequency );
		if ( 'weekly' === $frequency ) {
			$days = array(
				0 => __( 'Sunday', 'activity-monitor' ), 1 => __( 'Monday', 'activity-monitor' ),
				2 => __( 'Tuesday', 'activity-monitor' ), 3 => __( 'Wednesday', 'activity-monitor' ),
				4 => __( 'Thursday', 'activity-monitor' ), 5 => __( 'Friday', 'activity-monitor' ),
				6 => __( 'Saturday', 'activity-monitor' ),
			);
			$day_name = $days[ absint( $config['day_of_week'] ?? 1 ) ] ?? '';
			/* translators: 1: frequency label, 2: day of week */
			$freq_label = sprintf( __( '%1$s (%2$s)', 'activity-monitor' ), $freq_label, $day_name );
		}
		?>
		<tr data-digest-id="<?php echo esc_attr( $config['id'] ); ?>">
			<td><?php echo esc_html( $freq_label ); ?></td>
			<td class="am-message-cell" title="<?php echo esc_attr( $config['recipients'] ?? '' ); ?>"><?php echo esc_html( $config['recipients'] ?? '' ); ?></td>
			<td>
				<?php
				echo esc_html(
					$last_sent
						? wp_date( AM_Date_Format::combined(), strtotime( $last_sent . ' UTC' ) )
						: __( 'Never', 'activity-monitor' )
				);
				?>
			</td>
			<td>
				<button type="button" class="button button-small am-edit-digest-btn" data-id="<?php echo esc_attr( $config['id'] ); ?>">
					<?php esc_html_e( 'Edit', 'activity-monitor' ); ?>
				</button>
			</td>
		</tr>
		<?php
	}

	/**
	 * The add/edit modal's form fields for one digest config. Shared by
	 * add-mode (null $config) and edit-mode (populated $config), same
	 * pattern as render_channel_modal_form().
	 */
	private function render_digest_modal_form( ?array $config ) {
		$frequency   = $config['frequency'] ?? 'weekly';
		$day         = absint( $config['day_of_week'] ?? 1 );
		$recipients  = $config['recipients'] ?? '';
		$days = array(
			0 => __( 'Sunday', 'activity-monitor' ), 1 => __( 'Monday', 'activity-monitor' ),
			2 => __( 'Tuesday', 'activity-monitor' ), 3 => __( 'Wednesday', 'activity-monitor' ),
			4 => __( 'Thursday', 'activity-monitor' ), 5 => __( 'Friday', 'activity-monitor' ),
			6 => __( 'Saturday', 'activity-monitor' ),
		);
		?>
		<form id="am-digest-modal-form">
			<input type="hidden" name="id" value="<?php echo esc_attr( $config['id'] ?? '' ); ?>">

			<div class="am-channel-fields">
				<div class="am-field-row">
					<label>
						<?php esc_html_e( 'Frequency', 'activity-monitor' ); ?>
						<select name="frequency" id="am-digest-modal-frequency">
							<option value="daily"   <?php selected( $frequency, 'daily' ); ?>><?php esc_html_e( 'Daily', 'activity-monitor' ); ?></option>
							<option value="weekly"  <?php selected( $frequency, 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'activity-monitor' ); ?></option>
							<option value="monthly" <?php selected( $frequency, 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'activity-monitor' ); ?></option>
						</select>
					</label>
				</div>

				<div class="am-field-row" id="am-digest-modal-day-row" <?php echo 'weekly' === $frequency ? '' : 'style="display:none;"'; ?>>
					<label>
						<?php esc_html_e( 'Day of week', 'activity-monitor' ); ?>
						<select name="day_of_week">
							<?php foreach ( $days as $val => $label ) : ?>
								<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $day, $val ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</div>

				<div class="am-field-row am-field-full">
					<label>
						<?php esc_html_e( 'Recipients', 'activity-monitor' ); ?>
						<input type="text" name="recipients"
						       value="<?php echo esc_attr( $recipients ); ?>"
						       placeholder="<?php esc_attr_e( 'admin@example.com, other@example.com', 'activity-monitor' ); ?>"
						       class="large-text">
						<p class="description"><?php esc_html_e( 'Separate multiple addresses with commas.', 'activity-monitor' ); ?></p>
					</label>
				</div>
			</div>

			<p id="am-digest-modal-error" class="am-description" style="color:#d63638; display:none;"></p>

			<div class="am-modal-actions">
				<?php if ( ! empty( $config['id'] ) ) : ?>
					<button type="button" class="button am-btn-danger" id="am-digest-delete-btn" data-id="<?php echo esc_attr( $config['id'] ); ?>">
						<?php esc_html_e( 'Delete Digest', 'activity-monitor' ); ?>
					</button>
				<?php endif; ?>
				<button type="submit" class="button button-primary" id="am-digest-save-btn">
					<?php esc_html_e( 'Save Digest', 'activity-monitor' ); ?>
				</button>
			</div>
		</form>
		<?php
	}

	// ── Notification channel card ────────────────────────────────────────

	/** One row in the Notification Channels table (display only — editing happens in the modal). */
	private function render_channel_table_row( $index, $ch ) {
		$type  = isset( $ch['type'] ) && 'slack' === $ch['type'] ? 'slack' : 'email';
		$name  = $ch['name'] ?? '';
		$level = isset( $ch['level'] ) ? (string) $ch['level'] : AM_Log_Levels::CRITICAL;
		if ( ! AM_Log_Levels::is_valid( $level ) ) {
			$level = AM_Log_Levels::CRITICAL;
		}
		$icon   = 'slack' === $type ? 'dashicons-format-chat' : 'dashicons-email-alt';
		$target = 'slack' === $type
			? __( 'Webhook configured', 'activity-monitor' )
			: ( $ch['recipients'] ?? '' );
		?>
		<tr data-channel-index="<?php echo esc_attr( $index ); ?>">
			<td>
				<span class="dashicons <?php echo esc_attr( $icon ); ?>"></span>
				<?php echo esc_html( 'slack' === $type ? __( 'Slack', 'activity-monitor' ) : __( 'Email', 'activity-monitor' ) ); ?>
			</td>
			<td><?php echo esc_html( $name ?: '—' ); ?></td>
			<td><span class="am-badge am-<?php echo esc_attr( $level ); ?>"><?php echo esc_html( AM_Log_Levels::label( $level ) ); ?></span></td>
			<td class="am-message-cell" title="<?php echo esc_attr( $target ); ?>"><?php echo esc_html( $target ); ?></td>
			<td>
				<button type="button" class="button button-small am-edit-channel-btn" data-index="<?php echo esc_attr( $index ); ?>">
					<?php esc_html_e( 'Edit', 'activity-monitor' ); ?>
				</button>
			</td>
		</tr>
		<?php
	}

	/**
	 * The add/edit modal's form fields, for a given type and (optionally)
	 * an existing channel's current values. Returned as AJAX HTML for
	 * both "Add X Channel" (empty $ch) and "Edit" (populated $ch)
	 * clicks -- one shared renderer rather than duplicating the field
	 * markup between an add-mode and edit-mode version. $index is null
	 * for add-mode (the Save button creates a new entry); an integer
	 * for edit-mode (Save updates that array offset in place).
	 */
	private function render_channel_modal_form( string $type, ?int $index, array $ch ) {
		$name  = $ch['name'] ?? '';
		$level = isset( $ch['level'] ) ? (string) $ch['level'] : AM_Log_Levels::CRITICAL;
		if ( ! AM_Log_Levels::is_valid( $level ) ) {
			$level = AM_Log_Levels::CRITICAL;
		}
		?>
		<form id="am-channel-modal-form">
			<input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>">
			<input type="hidden" name="index" value="<?php echo esc_attr( null === $index ? '' : $index ); ?>">

			<div class="am-channel-fields">
				<div class="am-field-row">
					<label>
						<?php esc_html_e( 'Channel Name', 'activity-monitor' ); ?>
						<input type="text" name="name" value="<?php echo esc_attr( $name ); ?>"
						       placeholder="<?php esc_attr_e( 'e.g. Security Alerts', 'activity-monitor' ); ?>"
						       class="regular-text">
					</label>
				</div>

				<div class="am-field-row">
					<label>
						<?php esc_html_e( 'Minimum Level', 'activity-monitor' ); ?>
						<select name="level">
							<?php foreach ( AM_Log_Levels::ORDER as $lvl ) : ?>
								<option value="<?php echo esc_attr( $lvl ); ?>" <?php selected( $level, $lvl ); ?>>
									<?php
									printf(
										/* translators: %s: log level label, e.g. "Warning" */
										esc_html__( '%s and above', 'activity-monitor' ),
										esc_html( AM_Log_Levels::label( $lvl ) )
									);
									?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>
				</div>

				<?php if ( 'slack' === $type ) : ?>
					<div class="am-field-row am-field-full">
						<label>
							<?php esc_html_e( 'Webhook URL', 'activity-monitor' ); ?>
							<input type="url" name="webhook_url"
							       value="<?php echo esc_attr( $ch['webhook_url'] ?? '' ); ?>"
							       placeholder="https://hooks.slack.com/services/…"
							       class="large-text">
							<p class="description">
								<?php
								printf(
									/* translators: %s: link text "Slack's incoming webhooks guide" */
									esc_html__( 'Create one at %s, then paste the URL here. Anyone with this URL can post to the channel it targets, so treat it like a password.', 'activity-monitor' ),
									'<a href="https://api.slack.com/messaging/webhooks" target="_blank" rel="noopener noreferrer">' . esc_html__( "Slack's incoming webhooks guide", 'activity-monitor' ) . '</a>'
								);
								?>
							</p>
						</label>
					</div>
				<?php else : ?>
					<div class="am-field-row am-field-full">
						<label>
							<?php esc_html_e( 'Recipients', 'activity-monitor' ); ?>
							<input type="text" name="recipients"
							       value="<?php echo esc_attr( $ch['recipients'] ?? '' ); ?>"
							       placeholder="<?php esc_attr_e( 'admin@example.com, other@example.com', 'activity-monitor' ); ?>"
							       class="large-text">
							<p class="description"><?php esc_html_e( 'Separate multiple addresses with commas.', 'activity-monitor' ); ?></p>
						</label>
					</div>
				<?php endif; ?>
			</div>

			<p id="am-channel-modal-error" class="am-description" style="color:#d63638; display:none;"></p>

			<div class="am-modal-actions">
				<?php if ( null !== $index ) : ?>
					<button type="button" class="button am-btn-danger" id="am-channel-delete-btn" data-index="<?php echo esc_attr( $index ); ?>">
						<?php esc_html_e( 'Delete Channel', 'activity-monitor' ); ?>
					</button>
				<?php endif; ?>
				<button type="submit" class="button button-primary" id="am-channel-save-btn">
					<?php esc_html_e( 'Save Channel', 'activity-monitor' ); ?>
				</button>
			</div>
		</form>
		<?php
	}
}
