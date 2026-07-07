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
		add_action( 'admin_notices',                          array( $instance, 'show_notices' ) );
		add_action( 'wp_ajax_am_get_event_detail',            array( $instance, 'ajax_event_detail' ) );
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
			$type = sanitize_key( $ch['type'] ?? '' );
			if ( ! in_array( $type, array( 'email', 'slack' ), true ) ) {
				continue;
			}
			$entry = array(
				'type'     => $type,
				'name'     => sanitize_text_field( $ch['name'] ?? '' ),
				'severity' => absint( $ch['severity'] ?? AM_Logger::CRITICAL ),
			);
			if ( $type === 'email' ) {
				$emails = array_filter( array_map( 'trim', explode( ',', $ch['recipients'] ?? '' ) ) );
				$entry['recipients'] = implode( ', ', array_filter( $emails, 'is_email' ) );
			} elseif ( $type === 'slack' ) {
				$entry['webhook_url'] = esc_url_raw( $ch['webhook_url'] ?? '' );
			}
			$clean[] = $entry;
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
	}

	// ── Action handlers ──────────────────────────────────────────────────

	public function handle_clear_log() {
		check_admin_referer( 'am_clear_log' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'activity-monitor' ) );
		}
		AM_DB::clear_all();
		AM_Logger::log( 'log.clear', 'Activity log was cleared by an administrator.', array(
			'severity'    => AM_Logger::WARNING,
			'object_type' => 'log',
			'object_name' => 'activity-log',
		) );
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
					update_user_meta( $user_id, 'session_tokens', $sessions );

					$user = get_userdata( $user_id );
					AM_Logger::log( 'user.session_revoked', sprintf(
						'Session revoked for user "%s" (token: %s…).',
						$user ? $user->user_login : $user_id,
						substr( $token_hash, 0, 12 )
					), array(
						'severity'    => AM_Logger::WARNING,
						'object_type' => 'user',
						'object_id'   => $user_id,
						'object_name' => $user ? $user->user_login : 'user-' . $user_id,
					) );
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
				update_user_meta( $user->ID, 'session_tokens', $sessions );
			}
		}

		if ( $count > 0 ) {
			AM_Logger::log( 'user.expired_sessions_revoked', sprintf(
				'%d expired session(s) revoked across all users.', $count
			), array(
				'severity'    => AM_Logger::WARNING,
				'object_type' => 'user',
				'object_name' => 'all-users',
				'meta'        => array( 'count' => $count ),
			) );
		}

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'activity-monitor', self::TAB_PARAM => 'settings', 'am_expired_revoked' => $count ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	// ── AJAX ─────────────────────────────────────────────────────────────

	public function ajax_event_detail() {
		check_ajax_referer( 'am_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '-1' );
		}

		global $wpdb;
		$id  = absint( $_POST['entry_id'] ?? 0 );
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . $wpdb->prefix . AM_TABLE . ' WHERE id = %d',
			$id
		) );

		if ( ! $row ) {
			wp_send_json_error( 'Not found' );
		}

		$meta = ! empty( $row->meta ) ? json_decode( $row->meta, true ) : array();

		ob_start();
		?>
		<table class="am-detail-table">
			<tr><th><?php esc_html_e( 'ID', 'activity-monitor' ); ?></th><td><?php echo esc_html( $row->id ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Date / Time', 'activity-monitor' ); ?></th><td><?php echo esc_html( $row->created_at ); ?> UTC</td></tr>
			<tr>
				<th><?php esc_html_e( 'Severity', 'activity-monitor' ); ?></th>
				<td><span class="am-badge <?php echo esc_attr( AM_Logger::severity_class( (int) $row->severity ) ); ?>"><?php echo esc_html( AM_Logger::severity_label( (int) $row->severity ) ); ?></span></td>
			</tr>
			<tr><th><?php esc_html_e( 'Event Type', 'activity-monitor' ); ?></th><td><?php echo esc_html( $row->event_type ); ?></td></tr>
			<tr>
				<th><?php esc_html_e( 'User', 'activity-monitor' ); ?></th>
				<td><?php echo esc_html( $row->user_name ); ?><?php if ( $row->user_role ) echo ' (' . esc_html( $row->user_role ) . ')'; ?></td>
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
			<?php if ( ! empty( $meta ) ) : ?>
			<tr><th><?php esc_html_e( 'Meta', 'activity-monitor' ); ?></th><td><pre><?php echo esc_html( wp_json_encode( $meta, JSON_PRETTY_PRINT ) ); ?></pre></td></tr>
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
						$this->render_tab_log();
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

	// ── Tab: Activity Log ────────────────────────────────────────────────

	private function render_tab_log() {
		$per_page   = 50;
		$page       = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$severity   = sanitize_text_field( $_GET['am_severity'] ?? '' );
		$event_type = sanitize_key( $_GET['am_type'] ?? '' );
		$search     = sanitize_text_field( $_GET['am_search'] ?? '' );

		$data        = AM_DB::get_events( compact( 'per_page', 'page', 'severity', 'event_type', 'search' ) );
		$items       = $data['items'];
		$total       = $data['total'];
		$num_pages   = (int) ceil( $total / $per_page );
		$event_types = AM_DB::get_event_types();

		$base_url = add_query_arg(
			array( 'page' => 'activity-monitor', self::TAB_PARAM => 'log' ),
			admin_url( 'admin.php' )
		);

		$severities = array(
			AM_Logger::INFO     => __( 'Info',     'activity-monitor' ),
			AM_Logger::NOTICE   => __( 'Notice',   'activity-monitor' ),
			AM_Logger::WARNING  => __( 'Warning',  'activity-monitor' ),
			AM_Logger::CRITICAL => __( 'Critical', 'activity-monitor' ),
		);
		?>

		<div class="am-stats-bar">
			<span class="am-stat">
				<strong><?php echo esc_html( number_format( $total ) ); ?></strong>
				<?php esc_html_e( 'Total Events', 'activity-monitor' ); ?>
			</span>
		</div>

		<div class="am-filter-bar">
			<form method="get" action="">
				<input type="hidden" name="page" value="activity-monitor">
				<input type="hidden" name="<?php echo esc_attr( self::TAB_PARAM ); ?>" value="log">

				<div class="am-filter-group">
					<span class="am-filter-label"><?php esc_html_e( 'Severity:', 'activity-monitor' ); ?></span>
					<a href="<?php echo esc_url( remove_query_arg( 'am_severity', $base_url ) ); ?>"
					   class="am-pill <?php echo '' === $severity ? 'active' : ''; ?>">
						<?php esc_html_e( 'All', 'activity-monitor' ); ?>
					</a>
					<?php foreach ( $severities as $sev_val => $sev_label ) :
						$url = add_query_arg( 'am_severity', $sev_val, $base_url );
					?>
					<a href="<?php echo esc_url( $url ); ?>"
					   class="am-pill am-pill-<?php echo esc_attr( AM_Logger::severity_class( $sev_val ) ); ?> <?php echo ( (string) $sev_val === $severity ) ? 'active' : ''; ?>">
						<?php echo esc_html( $sev_label ); ?>
					</a>
					<?php endforeach; ?>
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

				<div class="am-filter-group am-filter-search">
					<input type="search" name="am_search"
					       value="<?php echo esc_attr( $search ); ?>"
					       placeholder="<?php esc_attr_e( 'Search message, user, object…', 'activity-monitor' ); ?>">
					<button type="submit" class="button"><?php esc_html_e( 'Search', 'activity-monitor' ); ?></button>
					<?php if ( $severity || $event_type || $search ) : ?>
						<a href="<?php echo esc_url( $base_url ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Reset', 'activity-monitor' ); ?>
						</a>
					<?php endif; ?>
				</div>
			</form>
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
						<th><?php esc_html_e( 'Severity',   'activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'Date',       'activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'Event Type', 'activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'User',       'activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'IP Address', 'activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'Object',     'activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'Actions',    'activity-monitor' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $items as $row ) :
						$sev_class = AM_Logger::severity_class( (int) $row->severity );
						$sev_label = AM_Logger::severity_label( (int) $row->severity );
					?>
					<tr class="am-row am-row-<?php echo esc_attr( $sev_class ); ?>">
						<td><span class="am-badge <?php echo esc_attr( $sev_class ); ?>"><?php echo esc_html( $sev_label ); ?></span></td>
						<td>
							<span title="<?php echo esc_attr( $row->created_at ); ?> UTC">
								<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $row->created_at ) ) ); ?>
							</span>
						</td>
						<td><code class="am-event-type"><?php echo esc_html( $row->event_type ); ?></code></td>
						<td>
							<?php echo esc_html( $row->user_name ); ?>
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
							<button class="button button-small am-view-detail"
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
				<?php esc_html_e( 'Configure instant alerts. Each channel triggers when an event meets or exceeds its minimum severity threshold.', 'activity-monitor' ); ?>
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
					<button type="button" class="button button-secondary" id="am-add-slack">
						<span class="dashicons dashicons-admin-links"></span>
						<?php esc_html_e( 'Add Slack Channel', 'activity-monitor' ); ?>
					</button>
				</div>

				<?php submit_button( __( 'Save Notification Channels', 'activity-monitor' ) ); ?>
			</form>

			<div style="display:none;">
				<div id="am-template-email">
					<?php $this->render_channel_row( '__INDEX__', array( 'type' => 'email', 'name' => '', 'severity' => AM_Logger::CRITICAL, 'recipients' => '' ) ); ?>
				</div>
				<div id="am-template-slack">
					<?php $this->render_channel_row( '__INDEX__', array( 'type' => 'slack', 'name' => '', 'severity' => AM_Logger::CRITICAL, 'webhook_url' => '' ) ); ?>
				</div>
			</div>
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

	// ── Notification channel card ────────────────────────────────────────

	private function render_channel_row( $index, $ch ) {
		$type   = isset( $ch['type'] )     ? $ch['type']     : 'email';
		$name   = isset( $ch['name'] )     ? $ch['name']     : '';
		$sev    = isset( $ch['severity'] ) ? $ch['severity'] : AM_Logger::CRITICAL;
		$prefix = 'am_notification_channels[' . $index . ']';
		?>
		<div class="am-channel-card am-channel-<?php echo esc_attr( $type ); ?>">
			<div class="am-channel-card-header">
				<span class="am-channel-icon dashicons <?php echo 'slack' === $type ? 'dashicons-admin-links' : 'dashicons-email-alt'; ?>"></span>
				<strong class="am-channel-type-label"><?php echo 'slack' === $type ? 'Slack' : 'Email'; ?></strong>
				<button type="button" class="am-remove-channel button-link">
					&times; <?php esc_html_e( 'Remove', 'activity-monitor' ); ?>
				</button>
			</div>

			<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[type]" value="<?php echo esc_attr( $type ); ?>">

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
						<?php esc_html_e( 'Minimum Severity', 'activity-monitor' ); ?>
						<select name="<?php echo esc_attr( $prefix ); ?>[severity]">
							<option value="<?php echo AM_Logger::INFO; ?>"     <?php selected( $sev, AM_Logger::INFO ); ?>><?php esc_html_e( 'Info and above',    'activity-monitor' ); ?></option>
							<option value="<?php echo AM_Logger::NOTICE; ?>"   <?php selected( $sev, AM_Logger::NOTICE ); ?>><?php esc_html_e( 'Notice and above',  'activity-monitor' ); ?></option>
							<option value="<?php echo AM_Logger::WARNING; ?>"  <?php selected( $sev, AM_Logger::WARNING ); ?>><?php esc_html_e( 'Warning and above', 'activity-monitor' ); ?></option>
							<option value="<?php echo AM_Logger::CRITICAL; ?>" <?php selected( $sev, AM_Logger::CRITICAL ); ?>><?php esc_html_e( 'Critical only',     'activity-monitor' ); ?></option>
						</select>
					</label>
				</div>

				<?php if ( 'email' === $type ) : ?>
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
				<?php elseif ( 'slack' === $type ) : ?>
				<div class="am-field-row am-field-full">
					<label>
						<?php esc_html_e( 'Webhook URL', 'activity-monitor' ); ?>
						<input type="url"
						       name="<?php echo esc_attr( $prefix ); ?>[webhook_url]"
						       value="<?php echo esc_attr( isset( $ch['webhook_url'] ) ? $ch['webhook_url'] : '' ); ?>"
						       placeholder="https://hooks.slack.com/services/…"
						       class="large-text">
						<p class="description"><?php esc_html_e( 'Create an Incoming Webhook in your Slack app settings.', 'activity-monitor' ); ?></p>
					</label>
				</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
