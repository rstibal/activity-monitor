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
		add_action( 'admin_post_am_save_digest_settings',     array( $instance, 'handle_save_digest_settings' ) );
		add_action( 'admin_post_am_export_log',               array( $instance, 'handle_export' ) );
		add_action( 'admin_notices',                          array( $instance, 'show_notices' ) );
		add_action( 'wp_ajax_am_get_v2_event_detail',         array( $instance, 'ajax_v2_event_detail' ) );
		add_action( 'wp_ajax_am_digest_preview',              array( $instance, 'ajax_digest_preview' ) );
		add_action( 'wp_ajax_am_digest_send_test',            array( $instance, 'ajax_digest_send_test' ) );
		add_action( 'wp_ajax_am_get_session_detail',          array( $instance, 'ajax_session_detail' ) );
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
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'am_ajax' ),
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
			// Slack support removed -- email-only going forward. Any
			// stored 'slack' entries from a prior version are dropped on
			// next save rather than migrated, since there's no webhook
			// data worth preserving without the feature that used it.
			$type = sanitize_key( $ch['type'] ?? '' );
			if ( 'email' !== $type ) {
				continue;
			}

			$level = isset( $ch['level'] ) ? sanitize_key( $ch['level'] ) : AM_Log_Levels::CRITICAL;
			if ( ! AM_Log_Levels::is_valid( $level ) ) {
				$level = AM_Log_Levels::CRITICAL;
			}

			$emails = array_filter( array_map( 'trim', explode( ',', $ch['recipients'] ?? '' ) ) );

			$clean[] = array(
				'type'       => 'email',
				'name'       => sanitize_text_field( $ch['name'] ?? '' ),
				'level'      => $level,
				'recipients' => implode( ', ', array_filter( $emails, 'is_email' ) ),
			);
		}
		return $clean;
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
		if ( isset( $_GET['am_digest_settings_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Digest settings saved.', 'activity-monitor' ) . '</p></div>';
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

	public function handle_save_digest_settings() {
		check_admin_referer( 'am_save_digest_settings' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'activity-monitor' ) );
		}

		$frequency = sanitize_key( $_POST['am_digest_frequency'] ?? '' );
		if ( ! in_array( $frequency, array( '', 'daily', 'weekly', 'monthly' ), true ) ) {
			$frequency = '';
		}

		update_option( 'am_digest_frequency', $frequency );
		update_option( 'am_digest_day_of_week', absint( $_POST['am_digest_day_of_week'] ?? 1 ) % 7 );

		$emails = array_filter( array_map( 'trim', explode( ',', $_POST['am_digest_recipients'] ?? '' ) ) );
		update_option( 'am_digest_recipients', implode( ', ', array_filter( $emails, 'is_email' ) ) );

		// Reschedule to pick up the new frequency immediately rather than
		// waiting for the next daily cron tick to notice the option changed.
		AM_Digest::reschedule();

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'activity-monitor', self::TAB_PARAM => 'settings', 'am_digest_settings_saved' => '1' ),
			admin_url( 'admin.php' )
		) );
		exit;
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

		$format  = sanitize_key( $_GET['am_format'] ?? 'csv' );
		$filters = array(
			'level'      => sanitize_key( $_GET['am_level'] ?? '' ),
			'initiator'  => sanitize_key( $_GET['am_initiator'] ?? '' ),
			'event_type' => sanitize_key( $_GET['am_type'] ?? '' ),
			'action'     => sanitize_key( $_GET['am_export_action'] ?? '' ),
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

		$frequency = get_option( 'am_digest_frequency', '' ) ?: 'weekly';
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

		$sent = AM_Digest::send_test( $email );
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
				<td><span class="am-badge"><?php echo esc_html( AM_Log_Levels::label( $row->level ) ); ?></span></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Initiator', 'activity-monitor' ); ?></th>
				<td><span class="am-badge"><?php echo esc_html( AM_Initiator_Detector::label( $row->initiator ) ); ?></span></td>
			</tr>
			<tr><th><?php esc_html_e( 'Event', 'activity-monitor' ); ?></th><td><?php echo esc_html( $row->event_type . '.' . $row->action ); ?></td></tr>
			<tr>
				<th><?php esc_html_e( 'User', 'activity-monitor' ); ?></th>
				<td><?php echo esc_html( $row->user_login ); ?><?php if ( $row->user_role ) echo ' (' . esc_html( $row->user_role ) . ')'; ?></td>
			</tr>
			<tr><th><?php esc_html_e( 'IP Address', 'activity-monitor' ); ?></th><td><?php echo esc_html( $row->ip_address ); ?></td></tr>
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

		$date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
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
					<strong><?php echo esc_html( $user->display_name ); ?></strong>
					(<?php echo esc_html( $user->user_login ); ?>, ID: <?php echo esc_html( $user_id ); ?>)
					<?php if ( $is_current ) : ?>
						<span class="am-badge am-info"><?php esc_html_e( 'You', 'activity-monitor' ); ?></span>
					<?php endif; ?>
				</td>
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
			<tr><th><?php esc_html_e( 'IP Address', 'activity-monitor' ); ?></th><td><code><?php echo esc_html( $ip ); ?></code></td></tr>
			<tr><th><?php esc_html_e( 'Browser / UA', 'activity-monitor' ); ?></th><td><?php echo esc_html( $browser ); ?></td></tr>
			<tr><th><?php esc_html_e( 'User Agent', 'activity-monitor' ); ?></th><td><small><?php echo esc_html( $ua ); ?></small></td></tr>
			<tr><th><?php esc_html_e( 'Session ID', 'activity-monitor' ); ?></th><td><code><?php echo esc_html( $token_hash ); ?></code></td></tr>
		</table>
		<?php
		wp_send_json_success( array( 'html' => ob_get_clean() ) );
	}

	// ── Master page renderer ─────────────────────────────────────────────

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tabs = array(
			'log'      => __( 'Activity Log',    'activity-monitor' ),
			'stats'    => __( 'Stats & Insights', 'activity-monitor' ),
			'sessions' => __( 'Active Sessions', 'activity-monitor' ),
			'settings' => __( 'Settings',        'activity-monitor' ),
		);

		$active_tab = sanitize_key( $_GET[ self::TAB_PARAM ] ?? 'log' );
		if ( ! array_key_exists( $active_tab, $tabs ) ) {
			$active_tab = 'log';
		}

		$base_url = admin_url( 'admin.php?page=activity-monitor' );
		?>
		<div class="wrap am-wrap">

			<div class="am-header">
				<h1 class="am-title">
					<span class="dashicons dashicons-shield-alt"></span>
					<?php esc_html_e( 'Activity Monitor', 'activity-monitor' ); ?>
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
					<h2><?php esc_html_e( 'Event Details', 'activity-monitor' ); ?></h2>
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
		$event_type = sanitize_key( $_GET['am_type'] ?? '' );
		$action     = sanitize_key( $_GET['am_action'] ?? '' );
		$user       = sanitize_user( wp_unslash( $_GET['am_user'] ?? '' ) );
		$date_from  = sanitize_text_field( $_GET['am_from'] ?? '' );
		$date_to    = sanitize_text_field( $_GET['am_to'] ?? '' );
		$search     = sanitize_text_field( $_GET['am_search'] ?? '' );

		$data        = AM_Event_Query::get_events( compact( 'per_page', 'page', 'level', 'initiator', 'event_type', 'action', 'user', 'date_from', 'date_to', 'search' ) );
		$items       = $data['items'];
		$total       = $data['total'];
		$num_pages   = (int) ceil( $total / $per_page );
		$event_types = AM_Event_Query::get_event_types();

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
					   class="am-pill <?php echo ( $lvl_val === $level ) ? 'active' : ''; ?>">
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
						<?php foreach ( $event_types as $et ) : ?>
							<option value="<?php echo esc_attr( $et ); ?>" <?php selected( $et, $event_type ); ?>>
								<?php echo esc_html( $et ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="am-filter-group">
					<span class="am-filter-label"><?php esc_html_e( 'User:', 'activity-monitor' ); ?></span>
					<input type="text" name="am_user" value="<?php echo esc_attr( $user ); ?>"
					       placeholder="<?php esc_attr_e( 'username', 'activity-monitor' ); ?>" class="am-filter-input-small">
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
					<?php if ( $level || $initiator || $event_type || $action || $user || $date_from || $date_to || $search ) : ?>
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
					'am_type'       => $event_type,
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
			<table class="wp-list-table widefat striped am-log-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Level',      'activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'Date',       'activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'Initiator',  'activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'Event',      'activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'User',       'activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'IP Address', 'activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'Object',     'activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'Repeats',    'activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'Actions',    'activity-monitor' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $items as $row ) : ?>
					<tr class="am-row">
						<td><span class="am-badge"><?php echo esc_html( AM_Log_Levels::label( $row->level ) ); ?></span></td>
						<td>
							<span title="<?php echo esc_attr( $row->date ); ?> UTC">
								<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $row->date ) ) ); ?>
							</span>
						</td>
						<td><span class="am-badge"><?php echo esc_html( AM_Initiator_Detector::label( $row->initiator ) ); ?></span></td>
						<td>
							<code class="am-event-type"><?php echo esc_html( $row->event_type . '.' . $row->action ); ?></code>
						</td>
						<td>
							<?php echo esc_html( $row->user_login ); ?>
							<?php if ( $row->user_role ) : ?>
								<small class="am-role"><?php echo esc_html( $row->user_role ); ?></small>
							<?php endif; ?>
						</td>
						<td><code><?php echo esc_html( $row->ip_address ); ?></code></td>
						<td>
							<?php if ( $row->object_type ) : ?>
								<small class="am-object-type"><?php echo esc_html( $row->object_type ); ?></small>
							<?php endif; ?>
							<?php echo esc_html( $row->object_name ); ?>
						</td>
						<td>
							<?php if ( (int) $row->repeat_count > 1 ) : ?>
								<span class="am-badge">&times;<?php echo esc_html( $row->repeat_count ); ?></span>
							<?php endif; ?>
						</td>
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

	// ── Tab: Stats & Insights (spec §4) ───────────────────────────────────

	private function render_tab_stats() {
		$days   = absint( $_GET['am_days'] ?? 7 );
		$days   = in_array( $days, array( 7, 14, 30 ), true ) ? $days : 7;
		$base   = add_query_arg( array( 'page' => 'activity-monitor', self::TAB_PARAM => 'stats' ), admin_url( 'admin.php' ) );

		$totals   = AM_Event_Query::get_totals_for_period( $days );
		$trend    = AM_Event_Query::get_daily_trend( $days );
		$by_type  = AM_Event_Query::get_breakdown_by_event_type( $days, 10 );
		$peak     = AM_Event_Query::get_peak_activity( $days );
		$top_users = AM_Event_Query::get_most_active_users( $days, 5 );

		$delta       = $totals['current'] - $totals['previous'];
		$delta_str   = $delta >= 0 ? "+{$delta}" : (string) $delta;
		$max_in_trend = max( array_merge( $trend, array( 1 ) ) ); // avoid div-by-zero in the bar chart
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

			<?php if ( $peak['busiest_day'] ) : ?>
			<div class="am-stats-card">
				<div class="am-stats-card-value"><?php echo esc_html( $peak['busiest_day']['name'] ); ?></div>
				<div class="am-stats-card-label"><?php esc_html_e( 'Busiest day', 'activity-monitor' ); ?></div>
				<div class="am-stats-card-delta"><?php echo esc_html( number_format_i18n( $peak['busiest_day']['count'] ) ); ?> <?php esc_html_e( 'events', 'activity-monitor' ); ?></div>
			</div>
			<?php endif; ?>

			<?php if ( $peak['busiest_hour'] ) : ?>
			<div class="am-stats-card">
				<div class="am-stats-card-value"><?php echo esc_html( wp_date( 'g A', mktime( $peak['busiest_hour']['hour'], 0, 0 ) ) ); ?></div>
				<div class="am-stats-card-label"><?php esc_html_e( 'Busiest hour', 'activity-monitor' ); ?></div>
				<div class="am-stats-card-delta"><?php echo esc_html( number_format_i18n( $peak['busiest_hour']['count'] ) ); ?> <?php esc_html_e( 'events', 'activity-monitor' ); ?></div>
			</div>
			<?php endif; ?>
		</div>

		<div class="am-settings-section">
			<h2 class="am-section-title"><?php esc_html_e( 'Daily activity', 'activity-monitor' ); ?></h2>
			<div class="am-trend-chart">
				<?php foreach ( $trend as $date => $count ) :
					$height_pct = $max_in_trend > 0 ? round( ( $count / $max_in_trend ) * 100 ) : 0;
				?>
					<div class="am-trend-bar-wrap" title="<?php echo esc_attr( $date . ': ' . $count ); ?>">
						<div class="am-trend-bar" style="height: <?php echo esc_attr( max( 2, $height_pct ) ); ?>%;"></div>
						<div class="am-trend-bar-label"><?php echo esc_html( wp_date( 'M j', strtotime( $date ) ) ); ?></div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="am-settings-section">
			<h2 class="am-section-title"><?php esc_html_e( 'Top event types', 'activity-monitor' ); ?></h2>
			<?php if ( empty( $by_type ) ) : ?>
				<p class="am-description"><?php esc_html_e( 'No events in this period.', 'activity-monitor' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat striped">
					<thead><tr><th><?php esc_html_e( 'Event type', 'activity-monitor' ); ?></th><th><?php esc_html_e( 'Count', 'activity-monitor' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $by_type as $type => $count ) : ?>
						<tr><td><code><?php echo esc_html( $type ); ?></code></td><td><?php echo esc_html( number_format_i18n( $count ) ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div class="am-settings-section">
			<h2 class="am-section-title"><?php esc_html_e( 'Most active users', 'activity-monitor' ); ?></h2>
			<?php if ( empty( $top_users ) ) : ?>
				<p class="am-description"><?php esc_html_e( 'No user activity in this period.', 'activity-monitor' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat striped">
					<thead><tr><th><?php esc_html_e( 'User', 'activity-monitor' ); ?></th><th><?php esc_html_e( 'Events', 'activity-monitor' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $top_users as $u ) : ?>
						<tr><td><?php echo esc_html( $u['user_login'] ); ?></td><td><?php echo esc_html( number_format_i18n( $u['count'] ) ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── Tab: Active Sessions ──────────────────────────────────────────────

	private function render_tab_sessions() {
		$users = get_users( array( 'fields' => array( 'ID', 'user_login', 'display_name' ) ) );

		$sessions_data = array();

		foreach ( $users as $user ) {
			$raw      = get_user_meta( $user->ID, 'session_tokens', true );
			$sessions = is_array( $raw ) ? $raw : array();

			foreach ( $sessions as $token_hash => $session ) {
				$sessions_data[] = array(
					'user_id'      => $user->ID,
					'user_login'   => $user->user_login,
					'display_name' => $user->display_name,
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
			<table class="wp-list-table widefat striped am-log-table">
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
							? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $s['expiration'] )
							: __( 'Never', 'activity-monitor' );
						$login_text  = $s['login']
							? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $s['login'] )
							: __( 'Unknown', 'activity-monitor' );
						$browser     = $this->parse_user_agent( $s['ua'] );
						$row_class   = trim( ( $is_expired ? 'am-session-expired' : '' ) . ' ' . ( $is_current ? 'am-session-current' : '' ) );
					?>
					<tr<?php echo $row_class ? ' class="' . esc_attr( $row_class ) . '"' : ''; ?>>
						<td>
							<strong><?php echo esc_html( $s['display_name'] ); ?></strong>
							<small class="am-role"><?php echo esc_html( $s['user_login'] ); ?></small>
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
						<td><code><?php echo esc_html( $s['ip'] ); ?></code></td>
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

		<div class="am-settings-section">
			<h2 class="am-section-title">
				<span class="dashicons dashicons-bell"></span>
				<?php esc_html_e( 'Notification Channels', 'activity-monitor' ); ?>
			</h2>
			<p class="am-description">
				<?php esc_html_e( 'Configure instant email alerts. Each channel triggers when an event meets or exceeds its minimum level.', 'activity-monitor' ); ?>
			</p>
			<form method="post" action="options.php" id="am-notifications-form">
				<?php settings_fields( 'am_notifications_group' ); ?>

				<div id="am-channels-list">
					<?php foreach ( $channels as $i => $ch ) : ?>
						<?php $this->render_channel_row( $i, $ch ); ?>
					<?php endforeach; ?>
				</div>

				<div class="am-channel-add-buttons">
					<button type="button" class="button button-secondary" id="am-add-email">
						<span class="dashicons dashicons-email-alt"></span>
						<?php esc_html_e( 'Add Email Channel', 'activity-monitor' ); ?>
					</button>
				</div>

				<?php submit_button( __( 'Save Notification Channels', 'activity-monitor' ) ); ?>
			</form>

			<div style="display:none;">
				<div id="am-template-email">
					<?php $this->render_channel_row( '__INDEX__', array( 'type' => 'email', 'name' => '', 'level' => AM_Log_Levels::CRITICAL, 'recipients' => '' ) ); ?>
				</div>
			</div>
		</div>

		<hr class="am-section-divider">

		<?php $this->render_digest_section(); ?>

		<hr class="am-section-divider">

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
				<?php esc_html_e( 'Danger Zone', 'activity-monitor' ); ?>
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

			<br>

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
		<?php
	}

	// ── Digest settings (spec §4) ──────────────────────────────────────────

	private function render_digest_section() {
		$frequency  = get_option( 'am_digest_frequency', '' );
		$day        = absint( get_option( 'am_digest_day_of_week', 1 ) );
		$recipients = get_option( 'am_digest_recipients', '' );
		$last_sent  = get_option( 'am_digest_last_sent', '' );
		$next_run   = wp_next_scheduled( AM_Digest::CRON_HOOK );
		$days       = array(
			0 => __( 'Sunday', 'activity-monitor' ),
			1 => __( 'Monday', 'activity-monitor' ),
			2 => __( 'Tuesday', 'activity-monitor' ),
			3 => __( 'Wednesday', 'activity-monitor' ),
			4 => __( 'Thursday', 'activity-monitor' ),
			5 => __( 'Friday', 'activity-monitor' ),
			6 => __( 'Saturday', 'activity-monitor' ),
		);
		?>
		<div class="am-settings-section">
			<h2 class="am-section-title">
				<span class="dashicons dashicons-email"></span>
				<?php esc_html_e( 'Email Digest', 'activity-monitor' ); ?>
			</h2>
			<p class="am-description">
				<?php esc_html_e( 'A scheduled summary of activity: totals, top event types, and notable (warning-and-above) events, with a link to the full log.', 'activity-monitor' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'am_save_digest_settings' ); ?>
				<input type="hidden" name="action" value="am_save_digest_settings">

				<table class="form-table">
					<tr>
						<th scope="row"><label for="am_digest_frequency"><?php esc_html_e( 'Frequency', 'activity-monitor' ); ?></label></th>
						<td>
							<select id="am_digest_frequency" name="am_digest_frequency">
								<option value=""        <?php selected( $frequency, '' ); ?>><?php esc_html_e( 'Off', 'activity-monitor' ); ?></option>
								<option value="daily"    <?php selected( $frequency, 'daily' ); ?>><?php esc_html_e( 'Daily', 'activity-monitor' ); ?></option>
								<option value="weekly"   <?php selected( $frequency, 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'activity-monitor' ); ?></option>
								<option value="monthly"  <?php selected( $frequency, 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'activity-monitor' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="am_digest_day_of_week"><?php esc_html_e( 'Day of week (weekly only)', 'activity-monitor' ); ?></label></th>
						<td>
							<select id="am_digest_day_of_week" name="am_digest_day_of_week">
								<?php foreach ( $days as $val => $label ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $day, $val ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="am_digest_recipients"><?php esc_html_e( 'Recipients', 'activity-monitor' ); ?></label></th>
						<td>
							<input type="text" id="am_digest_recipients" name="am_digest_recipients"
							       value="<?php echo esc_attr( $recipients ); ?>"
							       placeholder="<?php esc_attr_e( 'admin@example.com, other@example.com', 'activity-monitor' ); ?>"
							       class="large-text">
							<p class="description"><?php esc_html_e( 'Separate multiple addresses with commas.', 'activity-monitor' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Digest Settings', 'activity-monitor' ) ); ?>
			</form>

			<p class="am-description">
				<?php if ( $next_run ) : ?>
					<?php
					printf(
						/* translators: 1: next scheduled run date/time, 2: last-sent date/time or "never" */
						esc_html__( 'Next check: %1$s. Last sent: %2$s.', 'activity-monitor' ),
						esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_run ) ),
						esc_html( $last_sent ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $last_sent . ' UTC' ) ) : __( 'never', 'activity-monitor' ) )
					);
					?>
				<?php else : ?>
					<?php esc_html_e( 'Digest is currently off.', 'activity-monitor' ); ?>
				<?php endif; ?>
			</p>

			<div class="am-channel-add-buttons">
				<button type="button" class="button button-secondary" id="am-digest-preview">
					<span class="dashicons dashicons-visibility"></span>
					<?php esc_html_e( 'Preview Digest', 'activity-monitor' ); ?>
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

	// ── Notification channel card ────────────────────────────────────────

	private function render_channel_row( $index, $ch ) {
		$name   = isset( $ch['name'] )  ? $ch['name']  : '';
		$level  = isset( $ch['level'] ) ? (string) $ch['level'] : AM_Log_Levels::CRITICAL;
		if ( ! AM_Log_Levels::is_valid( $level ) ) {
			$level = AM_Log_Levels::CRITICAL;
		}
		$prefix = 'am_notification_channels[' . $index . ']';
		?>
		<div class="am-channel-card am-channel-email">
			<div class="am-channel-card-header">
				<span class="am-channel-icon dashicons dashicons-email-alt"></span>
				<strong class="am-channel-type-label">Email</strong>
				<button type="button" class="am-remove-channel button-link">
					&times; <?php esc_html_e( 'Remove', 'activity-monitor' ); ?>
				</button>
			</div>

			<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[type]" value="email">

			<div class="am-channel-fields">
				<div class="am-field-row">
					<label>
						<?php esc_html_e( 'Channel Name', 'activity-monitor' ); ?>
						<input type="text"
						       name="<?php echo esc_attr( $prefix ); ?>[name]"
						       value="<?php echo esc_attr( $name ); ?>"
						       placeholder="<?php esc_attr_e( 'e.g. Security Alerts', 'activity-monitor' ); ?>"
						       class="regular-text">
					</label>
				</div>

				<div class="am-field-row">
					<label>
						<?php esc_html_e( 'Minimum Level', 'activity-monitor' ); ?>
						<select name="<?php echo esc_attr( $prefix ); ?>[level]">
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

				<div class="am-field-row am-field-full">
					<label>
						<?php esc_html_e( 'Recipients', 'activity-monitor' ); ?>
						<input type="text"
						       name="<?php echo esc_attr( $prefix ); ?>[recipients]"
						       value="<?php echo esc_attr( isset( $ch['recipients'] ) ? $ch['recipients'] : '' ); ?>"
						       placeholder="<?php esc_attr_e( 'admin@example.com, other@example.com', 'activity-monitor' ); ?>"
						       class="large-text">
						<p class="description"><?php esc_html_e( 'Separate multiple addresses with commas.', 'activity-monitor' ); ?></p>
					</label>
				</div>
			</div>
		</div>
		<?php
	}
}
