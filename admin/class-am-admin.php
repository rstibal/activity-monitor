<?php
/**
 * AM_Admin – registers menus, renders every admin screen, handles form
 * actions.
 *
 * One top-level menu with two submenu pages:
 *   1. Activity Log  (the default screen, keeps the bare plugin slug)
 *   2. Settings      (notifications, event sources, display, clear-log)
 *
 * @package ActivityMonitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AM_Admin {

	/** Page slugs. The log keeps the bare plugin slug so existing links
	 *  into it -- the plain-text alert, any bookmark -- keep working
	 *  unchanged. */
	const PAGE_LOG      = 'activity-monitor';
	const PAGE_SETTINGS = 'activity-monitor-settings';
	const PAGE_STATS    = 'activity-monitor-stats';

	/** Option group posted to options.php by the Settings screen. */
	const SETTINGS_GROUP = 'am_settings';

	/** Fixed page size for every paginated table this plugin renders,
	 *  except the Activity Log -- see LOG_PER_PAGE_CHOICES below. */
	const PER_PAGE = 10;

	/** Rows-per-page choices for the Activity Log's own dropdown. */
	const LOG_PER_PAGE_CHOICES = array( 10, 15, 20, 50, 100 );

	/**
	 * Hook suffixes returned by add_menu_page()/add_submenu_page(), used
	 * by enqueue_assets() and show_notices() to recognize this plugin's
	 * screens.
	 *
	 * Collected from the return values rather than written out as literals
	 * ('toplevel_page_activity-monitor' and friends): WordPress builds a
	 * submenu's hook from the *sanitized parent menu slug*, so the literal
	 * form is easy to get subtly wrong and fails silently -- assets simply
	 * never enqueue on that screen, with no error to notice.
	 *
	 * @var string[]
	 */
	private static $screen_hooks = array();

	// ── Bootstrap ──────────────────────────────────────────────────────

	public static function init() {
		$instance = new self();

		add_action( 'admin_menu',                             array( $instance, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts',                  array( $instance, 'enqueue_assets' ) );
		add_action( 'admin_init',                             array( $instance, 'register_settings' ) );
		add_action( 'admin_post_am_clear_log',                array( $instance, 'handle_clear_log' ) );
		add_action( 'admin_post_am_export_log',               array( $instance, 'handle_export' ) );
		add_action( 'admin_post_am_stats_geo_update_now',     array( $instance, 'handle_stats_geo_update_now' ) );
		add_action( 'admin_notices',                          array( $instance, 'show_notices' ) );
		add_action( 'wp_ajax_am_get_v2_event_detail',         array( $instance, 'ajax_v2_event_detail' ) );
		add_action( 'wp_ajax_am_ip_lookup',                   array( $instance, 'ajax_ip_lookup' ) );
		add_action( 'wp_ajax_am_user_profile',                array( $instance, 'ajax_user_profile' ) );
		add_action( 'wp_ajax_am_channel_form',                array( $instance, 'ajax_channel_form' ) );
		add_action( 'wp_ajax_am_save_channel',                array( $instance, 'ajax_save_channel' ) );
		add_action( 'wp_ajax_am_delete_channel',              array( $instance, 'ajax_delete_channel' ) );
		add_action( 'wp_ajax_am_log_table',                   array( $instance, 'ajax_log_table' ) );
		add_action( 'wp_ajax_am_stats_content',               array( $instance, 'ajax_stats_content' ) );
	}

	// ── Menu ───────────────────────────────────────────────────────────

	public function register_menu() {
		$log_hook = add_menu_page(
			__( 'Activity Monitor', 'activity-monitor' ),
			__( 'Activity Monitor', 'activity-monitor' ),
			'manage_options',
			self::PAGE_LOG,
			array( $this, 'render_page_log' ),
			'dashicons-shield-alt',
			2
		);
		self::$screen_hooks[] = $log_hook;

		self::$screen_hooks[] = add_submenu_page(
			self::PAGE_LOG,
			__( 'Visitor Stats', 'activity-monitor' ),
			__( 'Visitor Stats', 'activity-monitor' ),
			'manage_options',
			self::PAGE_STATS,
			array( $this, 'render_page_stats' )
		);

		self::$screen_hooks[] = add_submenu_page(
			self::PAGE_LOG,
			__( 'Settings', 'activity-monitor' ),
			__( 'Settings', 'activity-monitor' ),
			'manage_options',
			self::PAGE_SETTINGS,
			array( $this, 'render_page_settings' )
		);

		self::$screen_hooks = array_filter( self::$screen_hooks );
	}

	// ── Shared cell rendering ────────────────────────────────────────────

	/** Whether the ipinfo.io lookup modal is available (Settings → Privacy). */
	private static function ip_lookup_enabled(): bool {
		return (bool) get_option( 'am_ip_lookup_enabled', 1 );
	}

	/**
	 * One stored IP address, as a lookup link or as plain text.
	 *
	 * Three states share this: a normal address, an address on a site where
	 * lookups are turned off (plain text -- nothing to click if clicking
	 * can't reach the service), and no address at all, which is what
	 * am_ip_storage => 'none' writes. That last case is why this exists as
	 * a helper rather than inline markup in two places: an empty <a> is an
	 * invisible click target, and both the log table and the event detail
	 * modal have to handle it the same way.
	 */
	private static function ip_cell_html( string $ip ): string {
		if ( '' === $ip ) {
			return '<span aria-hidden="true">&mdash;</span><span class="screen-reader-text">'
				. esc_html__( 'Not recorded', 'activity-monitor' ) . '</span>';
		}

		if ( ! self::ip_lookup_enabled() ) {
			return esc_html( $ip );
		}

		return '<a href="#" class="am-ip-lookup" data-ip="' . esc_attr( $ip ) . '">' . esc_html( $ip ) . '</a>';
	}

	/**
	 * One am_events row as a <tr>. Extracted in 2.4.5 when a second screen
	 * rendered the same shape; that screen was folded back into the log in
	 * 2.4.7 and this now has a single caller, but it stays split out to keep
	 * the row's escaping in one readable block rather than buried in the
	 * middle of render_log_screen(). Echoes directly rather than returning a
	 * string, matching every other render_*() method on this class.
	 *
	 * Sits inside the screen's #am-filter-form, so the Details button below
	 * needs its explicit type="button" -- see the class doc for why.
	 */
	private static function render_event_row( $row ) {
		?>
		<tr class="am-row am-row-am-<?php echo esc_attr( $row->level ); ?>">
			<td><span class="am-badge am-<?php echo esc_attr( $row->level ); ?>"><?php echo esc_html( AM_Log_Levels::label( $row->level ) ); ?></span></td>
			<td class="am-type-cell" title="<?php echo esc_attr( AM_Event_Labels::raw( $row->event_type, $row->action ) ); ?>"><?php echo esc_html( AM_Event_Labels::label( $row->event_type, $row->action ) ); ?></td>
			<td>
				<span class="am-datetime-cell" title="<?php echo esc_attr( $row->date ); ?> UTC"><?php echo esc_html( wp_date( AM_Date_Format::combined(), strtotime( $row->date . ' UTC' ) ) ); ?></span>
			</td>
			<td><span class="am-badge am-init-<?php echo esc_attr( $row->initiator ); ?>"><?php echo esc_html( AM_Initiator_Detector::label( $row->initiator ) ); ?></span></td>
			<td>
				<?php if ( (int) $row->user_id > 0 && '' !== $row->user_login ) : ?>
					<a href="#" class="am-user-profile-link" data-user-id="<?php echo esc_attr( (int) $row->user_id ); ?>"><strong><?php echo esc_html( $row->user_login ); ?></strong></a>
				<?php else : ?>
					<?php echo esc_html( $row->user_login ); ?>
				<?php endif; ?>
			</td>
			<td class="am-ip-cell" title="<?php echo esc_attr( $row->ip_address ); ?>"><?php echo self::ip_cell_html( (string) $row->ip_address ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per-part in ip_cell_html(). ?></td>
			<td class="am-log-message-cell" title="<?php echo esc_attr( $row->message ); ?>"><span class="am-log-message-clamp"><?php echo esc_html( $row->message ); ?></span></td>
			<td>
				<?php
				// type="button" is required, not cosmetic: this row sits
				// inside the filter form, and a <button> with no type
				// defaults to type="submit", which submits that form and
				// reloads the page the instant the modal opens.
				?>
				<button type="button" class="button button-small am-view-detail-v2"
				        data-id="<?php echo esc_attr( $row->id ); ?>">
					<?php esc_html_e( 'Details', 'activity-monitor' ); ?>
				</button>
			</td>
		</tr>
		<?php
	}

	// ── Assets ─────────────────────────────────────────────────────────

	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, self::$screen_hooks, true ) ) {
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
	//
	// Every scalar setting on the Settings screen goes through the Settings
	// API and posts to options.php as one group: one nonce, one Save
	// Changes button, one "Settings saved." notice, and core's own
	// form-table markup for free. Before 2.4.3 each block had its own
	// admin-post handler, its own submit button, its own redirect and its
	// own custom success notice, which is why the screen had four different
	// save behaviours on it at once.
	//
	// What is deliberately NOT here: notification channels. Those are a
	// list of records, not fields -- they are added, edited and deleted
	// one at a time through a modal that saves over AJAX immediately, so
	// they have nothing to contribute to a page-level Save button. They
	// render below it for that reason.

	public function register_settings() {
		$this->register_options();
		$this->register_sections_and_fields();
	}

	private function register_options() {
		register_setting( self::SETTINGS_GROUP, 'am_retention_days', array(
			'type'              => 'integer',
			'sanitize_callback' => array( $this, 'sanitize_retention_days' ),
			'default'           => 90,
		) );

		register_setting( self::SETTINGS_GROUP, 'am_occasion_window_seconds', array(
			'type'              => 'integer',
			'sanitize_callback' => array( $this, 'sanitize_occasion_window' ),
			'default'           => AM_Event_Writer::DEFAULT_OCCASION_WINDOW_SECONDS,
		) );

		register_setting( self::SETTINGS_GROUP, AM_Date_Format::OPTION, array(
			'sanitize_callback' => array( $this, 'sanitize_datetime_format' ),
			'default'           => AM_Date_Format::DEFAULT_KEY,
		) );

		register_setting( self::SETTINGS_GROUP, 'am_ip_storage', array(
			'sanitize_callback' => array( $this, 'sanitize_ip_storage' ),
			'default'           => 'full',
		) );

		register_setting( self::SETTINGS_GROUP, 'am_ip_lookup_enabled', array(
			'type'              => 'boolean',
			'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
			'default'           => 1,
		) );

		register_setting( self::SETTINGS_GROUP, 'am_delete_data_on_uninstall', array(
			'type'              => 'boolean',
			'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
			'default'           => 1,
		) );

		register_setting( self::SETTINGS_GROUP, 'am_stats_enable_tracking', array(
			'type'              => 'boolean',
			'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
			'default'           => 1,
		) );

		register_setting( self::SETTINGS_GROUP, 'am_stats_exclude_roles', array(
			'sanitize_callback' => array( $this, 'sanitize_stats_exclude_roles' ),
			'default'           => array( 'administrator' ),
		) );

		register_setting( self::SETTINGS_GROUP, 'am_stats_retention_days', array(
			'type'              => 'integer',
			'sanitize_callback' => array( $this, 'sanitize_retention_days' ),
			'default'           => 90,
		) );

		register_setting( self::SETTINGS_GROUP, 'am_stats_geo_account_id', array(
			'sanitize_callback' => array( $this, 'sanitize_stats_geo_account_id' ),
			'default'           => '',
		) );

		register_setting( self::SETTINGS_GROUP, 'am_stats_geo_license_key', array(
			'sanitize_callback' => array( $this, 'sanitize_stats_geo_license_key' ),
			'default'           => '',
		) );

		register_setting( self::SETTINGS_GROUP, 'am_stats_geo_enabled', array(
			'type'              => 'boolean',
			'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
			'default'           => 0,
		) );
	}

	private function register_sections_and_fields() {
		add_settings_section(
			'am_logging',
			__( 'Logging', 'activity-monitor' ),
			array( $this, 'section_intro_logging' ),
			self::PAGE_SETTINGS
		);
		add_settings_field( 'am_field_retention', __( 'Keep entries for', 'activity-monitor' ), array( $this, 'field_retention' ), self::PAGE_SETTINGS, 'am_logging' );
		add_settings_field( 'am_field_grouping', __( 'Group repeat events', 'activity-monitor' ), array( $this, 'field_grouping' ), self::PAGE_SETTINGS, 'am_logging' );

		add_settings_section(
			'am_display',
			__( 'Display', 'activity-monitor' ),
			'__return_false',
			self::PAGE_SETTINGS
		);
		add_settings_field(
			'am_field_datetime',
			__( 'Date and time format', 'activity-monitor' ),
			array( $this, 'field_datetime_format' ),
			self::PAGE_SETTINGS,
			'am_display',
			array( 'label_for' => AM_Date_Format::OPTION )
		);

		add_settings_section(
			'am_privacy',
			__( 'Privacy', 'activity-monitor' ),
			array( $this, 'section_intro_privacy' ),
			self::PAGE_SETTINGS
		);
		add_settings_field( 'am_field_ip_storage', __( 'IP addresses', 'activity-monitor' ), array( $this, 'field_ip_storage' ), self::PAGE_SETTINGS, 'am_privacy' );
		add_settings_field( 'am_field_ip_lookup', __( 'IP address lookups', 'activity-monitor' ), array( $this, 'field_ip_lookup' ), self::PAGE_SETTINGS, 'am_privacy' );

		add_settings_section(
			'am_stats',
			__( 'Visitor Stats', 'activity-monitor' ),
			array( $this, 'section_intro_stats' ),
			self::PAGE_SETTINGS
		);
		add_settings_field( 'am_field_stats_enable', __( 'Tracking', 'activity-monitor' ), array( $this, 'field_stats_enable' ), self::PAGE_SETTINGS, 'am_stats' );
		add_settings_field( 'am_field_stats_exclude_roles', __( 'Exclude roles', 'activity-monitor' ), array( $this, 'field_stats_exclude_roles' ), self::PAGE_SETTINGS, 'am_stats' );
		add_settings_field( 'am_field_stats_retention', __( 'Keep stats for', 'activity-monitor' ), array( $this, 'field_stats_retention' ), self::PAGE_SETTINGS, 'am_stats' );

		add_settings_section(
			'am_stats_geo',
			__( 'Geolocation', 'activity-monitor' ),
			array( $this, 'section_intro_stats_geo' ),
			self::PAGE_SETTINGS
		);
		add_settings_field( 'am_field_stats_geo_account_id', __( 'MaxMind account ID', 'activity-monitor' ), array( $this, 'field_stats_geo_account_id' ), self::PAGE_SETTINGS, 'am_stats_geo' );
		add_settings_field( 'am_field_stats_geo_license_key', __( 'MaxMind license key', 'activity-monitor' ), array( $this, 'field_stats_geo_license_key' ), self::PAGE_SETTINGS, 'am_stats_geo' );
		add_settings_field( 'am_field_stats_geo_enabled', __( 'Country lookups', 'activity-monitor' ), array( $this, 'field_stats_geo_enabled' ), self::PAGE_SETTINGS, 'am_stats_geo' );
		add_settings_field( 'am_field_stats_geo_status', __( 'Database status', 'activity-monitor' ), array( $this, 'field_stats_geo_status' ), self::PAGE_SETTINGS, 'am_stats_geo' );

		add_settings_section(
			'am_data',
			__( 'When the plugin is deleted', 'activity-monitor' ),
			'__return_false',
			self::PAGE_SETTINGS
		);
		add_settings_field( 'am_field_uninstall', __( 'Stored data', 'activity-monitor' ), array( $this, 'field_delete_on_uninstall' ), self::PAGE_SETTINGS, 'am_data' );
	}

	// ── Setting sanitizers ───────────────────────────────────────────────

	/** Whitelisted against the offered choices -- 0 means "keep forever". */
	public function sanitize_retention_days( $input ): int {
		$days = absint( $input );
		return array_key_exists( $days, self::retention_choices() ) ? $days : 90;
	}

	public function sanitize_occasion_window( $input ): int {
		$seconds = absint( $input );
		return array_key_exists( $seconds, self::grouping_choices() ) ? $seconds : AM_Event_Writer::DEFAULT_OCCASION_WINDOW_SECONDS;
	}

	/**
	 * Whitelisted against the known presets rather than storing whatever
	 * was posted: the saved value is used to look up a format string, so an
	 * unrecognized key would silently fall back anyway -- better to never
	 * store one.
	 */
	public function sanitize_datetime_format( $input ): string {
		$format = sanitize_key( $input );
		return isset( AM_Date_Format::FORMATS[ $format ] ) ? $format : AM_Date_Format::DEFAULT_KEY;
	}

	public function sanitize_ip_storage( $input ): string {
		$mode = sanitize_key( $input );
		return in_array( $mode, array( 'full', 'anonymized', 'none' ), true ) ? $mode : 'full';
	}

	/** Unchecked boxes post nothing, which reaches this as null. */
	public function sanitize_checkbox( $input ): int {
		return empty( $input ) ? 0 : 1;
	}

	/** Whitelisted against roles that actually exist on this site. */
	public function sanitize_stats_exclude_roles( $input ): array {
		$known = array_keys( wp_roles()->get_names() );
		$roles = array_map( 'sanitize_key', (array) $input );
		return array_values( array_intersect( $roles, $known ) );
	}

	/**
	 * These two credential fields render as empty password inputs (see
	 * field_stats_geo_account_id()) -- a saved value never gets echoed back
	 * into the page, so a blank submission means "leave it as-is", not
	 * "clear it". Only the paired *_clear checkbox actually clears it.
	 * Reading the sibling checkbox from $_POST here rather than through
	 * register_setting() is safe: this callback only ever runs inside the
	 * options.php POST that settings_fields() already nonce-protects.
	 */
	public function sanitize_stats_geo_account_id( $input ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see method doc.
		if ( ! empty( $_POST['am_stats_geo_account_id_clear'] ) ) {
			return '';
		}
		$input = sanitize_text_field( (string) $input );
		return '' !== $input ? $input : (string) get_option( 'am_stats_geo_account_id', '' );
	}

	/** See sanitize_stats_geo_account_id() -- same clear-checkbox pattern. */
	public function sanitize_stats_geo_license_key( $input ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see sanitize_stats_geo_account_id().
		if ( ! empty( $_POST['am_stats_geo_license_key_clear'] ) ) {
			return '';
		}
		$input = sanitize_text_field( (string) $input );
		return '' !== $input ? $input : (string) get_option( 'am_stats_geo_license_key', '' );
	}

	/** Retention periods offered, keyed by days. 0 = keep forever. */
	private static function retention_choices(): array {
		return array(
			30  => __( '30 days', 'activity-monitor' ),
			60  => __( '60 days', 'activity-monitor' ),
			90  => __( '90 days', 'activity-monitor' ),
			180 => __( '6 months', 'activity-monitor' ),
			365 => __( '1 year', 'activity-monitor' ),
			730 => __( '2 years', 'activity-monitor' ),
			0   => __( 'Forever', 'activity-monitor' ),
		);
	}

	/** Grouping windows offered, keyed by seconds. 0 = don't group. */
	private static function grouping_choices(): array {
		return array(
			0    => __( 'Don’t group — record every occurrence separately', 'activity-monitor' ),
			60   => __( 'Repeats within 1 minute', 'activity-monitor' ),
			300  => __( 'Repeats within 5 minutes', 'activity-monitor' ),
			900  => __( 'Repeats within 15 minutes', 'activity-monitor' ),
			3600 => __( 'Repeats within 1 hour', 'activity-monitor' ),
		);
	}

	/**
	 * Validates and sanitizes a single channel's raw form data, for
	 * ajax_save_channel() (the per-channel modal save path). Returns null
	 * for an invalid/unrecognized channel (unknown type, or a Slack webhook
	 * that doesn't point at hooks.slack.com).
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
		if ( ! $screen || ! in_array( $screen->id, self::$screen_hooks, true ) ) {
			return;
		}
		// Saving settings no longer needs a notice of its own: those all
		// post to options.php now, which comes back with settings-updated
		// and core's own "Settings saved." message (emitted by the
		// settings_errors() call in render_settings_screen()). Clearing the
		// log is not a setting -- it stays an admin-post action, so it
		// still reports itself here.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence-only display flag, set by this plugin's own post-action redirect, which did verify a nonce.
		if ( isset( $_GET['am_cleared'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Activity log cleared.', 'activity-monitor' ) . '</p></div>';
		}

		$geo_key    = 'am_stats_geo_notice_' . get_current_user_id();
		$geo_notice = get_transient( $geo_key );
		if ( false !== $geo_notice ) {
			delete_transient( $geo_key );
			// An empty string means success here, matching
			// AM_Stats_Geo_Updater::trigger_manual_update()'s return
			// contract -- deliberately not a boolean check. A transient
			// round-trips through wp_options, which stringifies scalars,
			// so a stored boolean true would come back as the string "1"
			// and fail a strict true-comparison, misreading success as an
			// error (this is exactly what happened before that method's
			// return type was changed).
			if ( '' === $geo_notice ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'GeoLite2 import started. This runs in the background and can take a few minutes.', 'activity-monitor' ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( (string) $geo_notice ) . '</p></div>';
			}
		}
	}

	// ── Action handlers ──────────────────────────────────────────────────

	public function handle_clear_log() {
		check_admin_referer( 'am_clear_log' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'activity-monitor' ) );
		}

		// Clears am_events/am_event_context, which is the whole of the
		// visible log. Deliberately does not touch the v1.x
		// am_activity_log table: that one is only ever dropped on
		// uninstall, so a site that has migrated keeps its pre-2.0
		// history even after clearing.
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
			array( 'page' => self::PAGE_SETTINGS, 'am_cleared' => '1' ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Streams a file download -- reads the same am_* filter params the log
	 * screen's filter form uses (see render_log_screen()) so export always
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

	/**
	 * "Update Now" on Settings -> Visitor Stats -> Geolocation. Result is
	 * handed to show_notices() via a per-user transient rather than a query
	 * arg, since the message is dynamic text (an error, or "started") and
	 * not just a fixed flag like am_cleared.
	 */
	public function handle_stats_geo_update_now() {
		check_admin_referer( 'am_stats_geo_update_now' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'activity-monitor' ) );
		}

		$result = AM_Stats_Geo_Updater::trigger_manual_update();
		set_transient( 'am_stats_geo_notice_' . get_current_user_id(), $result, MINUTE_IN_SECONDS );

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SETTINGS ), admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── AJAX ─────────────────────────────────────────────────────────────

	public function ajax_v2_event_detail() {
		check_ajax_referer( 'am_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '-1' );
		}

		global $wpdb;
		$id    = absint( $_POST['entry_id'] ?? 0 );
		$table = $wpdb->prefix . AM_Schema::EVENTS_TABLE;
		$row   = $wpdb->get_row( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
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
			<?php if ( '' !== $row->user_login ) : ?>
			<tr>
				<th><?php esc_html_e( 'Username', 'activity-monitor' ); ?></th>
				<td>
					<?php if ( (int) $row->user_id > 0 ) : ?>
						<a href="#" class="am-user-profile-link" data-user-id="<?php echo esc_attr( (int) $row->user_id ); ?>"><?php echo esc_html( $row->user_login ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $row->user_login ); ?>
					<?php endif; ?>
					<?php if ( $row->user_role ) echo ' (' . esc_html( $row->user_role ) . ')'; ?>
				</td>
			</tr>
			<?php endif; ?>
			<tr><th><?php esc_html_e( 'IP Address', 'activity-monitor' ); ?></th><td><?php echo self::ip_cell_html( (string) $row->ip_address ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per-part in ip_cell_html(). ?></td></tr>
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
			<tr><th><?php esc_html_e( 'Repeated', 'activity-monitor' ); ?></th><td><?php
				/* translators: %d: number of times this event was recorded within the grouping window */
				printf( esc_html__( '%d times (occasion grouping)', 'activity-monitor' ), (int) $row->repeat_count );
			?></td></tr>
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
			<table class="am-detail-table">
				<tr><th><?php esc_html_e( 'User ID', 'activity-monitor' ); ?></th><td><?php echo esc_html( $user->ID ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Username', 'activity-monitor' ); ?></th><td><?php echo esc_html( $user->user_login ); ?></td></tr>
				<?php
				// display_name is a row here rather than a heading above the
				// table. It used to be the modal's avatar-and-name header and
				// also its title; the title is now the fixed "User Details",
				// so without this row the display name would be the one
				// identifying field the modal no longer showed at all.
				?>
				<tr><th><?php esc_html_e( 'Display Name', 'activity-monitor' ); ?></th><td><?php echo esc_html( $user->display_name ); ?></td></tr>
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
							array( 'page' => self::PAGE_LOG, 'am_user' => $user->user_login ),
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
	public function ajax_ip_lookup() {
		check_ajax_referer( 'am_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '-1' );
		}

		// Re-checked here, not just in the markup: the addresses in the log
		// stop being clickable when lookups are off, but this endpoint is
		// what actually reaches the third-party service, so the setting has
		// to hold at the point of the outbound request.
		if ( ! self::ip_lookup_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'IP address lookups are turned off in Activity Monitor’s settings.', 'activity-monitor' ) ) );
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

	// ── Screen renderers ─────────────────────────────────────────────────
	//
	// One public callback per registered submenu page. Each wraps its
	// screen body in the shared chrome: the .wrap/header opener, and the
	// modal overlay closer. The overlay markup has to be emitted on every
	// screen, not just the log -- Settings opens modals into it too (IP
	// lookup and channel forms), and without it in the DOM those clicks
	// do nothing.

	public function render_page_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$this->render_screen_open( __( 'Activity Monitor', 'activity-monitor' ) );
		$this->render_log_screen();
		$this->render_screen_close();
	}

	public function render_page_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$this->render_screen_open( __( 'Activity Monitor', 'activity-monitor' ) );
		$this->render_settings_screen();
		$this->render_screen_close();
	}

	public function render_page_stats() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$this->render_screen_open( __( 'Activity Monitor', 'activity-monitor' ) );
		$this->render_stats_screen();
		$this->render_screen_close();
	}

	/**
	 * Opens the shared page chrome, following wp-admin's own page header
	 * convention: an .wp-heading-inline <h1> naming just this screen (the
	 * menu already says which plugin it belongs to), then .wp-header-end,
	 * which is the marker WordPress relocates admin notices to. Without
	 * that marker notices land wherever they were echoed, which on a
	 * plugin screen usually means above the heading.
	 *
	 * There is deliberately no wrapper panel around the content. Core
	 * admin screens put their tables and form sections straight onto the
	 * gray body background; boxing the whole screen in white made every
	 * inner container lose its own contrast against the page.
	 */
	private function render_screen_open( string $title ) {
		?>
		<div class="wrap am-wrap">

			<h1 class="wp-heading-inline"><?php echo esc_html( $title ); ?></h1>
			<span class="am-version">v<?php echo esc_html( AM_VERSION ); ?></span>
			<hr class="wp-header-end">
		<?php
	}

	/** Closes the chrome opened above and emits the shared modal overlay. */
	private function render_screen_close() {
		?>
		</div><!-- .am-wrap -->

		<!-- Shared modal overlay: one per screen, reused by every modal. -->
		<div id="am-modal-overlay" class="am-modal-overlay" style="display:none;">
			<div class="am-modal">
				<div class="am-modal-header">
					<h2 id="am-modal-title"><?php esc_html_e( 'Details', 'activity-monitor' ); ?></h2>
					<button type="button" class="am-modal-close" id="am-modal-close">&times;</button>
				</div>
				<div class="am-modal-body" id="am-modal-body">
					<p class="am-loading"><?php esc_html_e( 'Loading…', 'activity-monitor' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	// ── Screen: Activity Log ──────────────────────────────────────────────
	//
	// Reads from the am_events / am_event_context schema through
	// AM_Event_Query. The whole screen is one <form id="am-filter-form">
	// wrapping both the filter controls and the rows, matching core's
	// list-table layout -- which is why every <button> in a row needs an
	// explicit type="button". See the Details button below.

	/**
	 * Renders everything inside the #am-log-app container: the level
	 * status links plus the filter form, table and pagination. Called both
	 * on a normal page load (from $_GET, via render_log_screen() below) and
	 * from ajax_log_table() (from a parsed query string sent over AJAX) --
	 * $raw is already wp_unslash()'d by the caller in both cases, so every
	 * value here still goes through the same sanitizers as before.
	 */
	/**
	 * Rows-per-page for the Activity Log table specifically -- everything
	 * else this plugin paginates stays fixed at PER_PAGE. Persisted per
	 * user in usermeta rather than a plugin option, since it's a display
	 * preference tied to who's looking, not something to configure once
	 * for the whole site.
	 *
	 * $raw carrying a valid am_per_page (from the dropdown's own change,
	 * or from a pagination/filter link built with it still in the query
	 * string, see $current_url below) both applies and re-saves it, so it
	 * stays in effect for every link generated off this render without a
	 * meta lookup. A request with no such value -- the very first load,
	 * or Reset, which deliberately drops filters but not this -- falls
	 * back to the stored preference, defaulting to PER_PAGE.
	 */
	private static function log_per_page( array $raw ): int {
		$user_id = get_current_user_id();
		if ( isset( $raw['am_per_page'] ) && in_array( (int) $raw['am_per_page'], self::LOG_PER_PAGE_CHOICES, true ) ) {
			$per_page = (int) $raw['am_per_page'];
			if ( $user_id ) {
				update_user_meta( $user_id, 'am_log_per_page', $per_page );
			}
			return $per_page;
		}
		$stored = $user_id ? (int) get_user_meta( $user_id, 'am_log_per_page', true ) : 0;
		return in_array( $stored, self::LOG_PER_PAGE_CHOICES, true ) ? $stored : self::PER_PAGE;
	}

	private function render_log_content( array $raw ) {
		$per_page   = self::log_per_page( $raw );
		$page       = max( 1, absint( $raw['paged'] ?? 1 ) );
		// Normalized to '' when it isn't a real level, matching what
		// AM_Event_Query does with it anyway. Without this, ?am_level=xyz
		// leaves a value that filters nothing yet still counts as "not
		// All", so the status links highlight neither All nor anything else.
		$level      = sanitize_key( $raw['am_level'] ?? '' );
		$level      = AM_Log_Levels::is_valid( $level ) ? $level : '';
		$initiator  = sanitize_key( $raw['am_initiator'] ?? '' );

		// The Type filter carries either a category ('media') or one
		// specific event ('media|uploaded'). The two halves are joined
		// with a pipe rather than a dot because event_type can itself
		// contain a dot on rows migrated from v1.x ('post.delete') --
		// splitting those on '.' would misread a stored slug as a
		// type/action pair and filter for something that never existed.
		// A pipe can't occur in either column.
		$type_filter = self::sanitize_type_filter( $raw['am_type'] ?? '' );
		$event_type  = $type_filter;
		$type_action = '';
		if ( false !== strpos( $type_filter, '|' ) ) {
			list( $event_type, $type_action ) = explode( '|', $type_filter, 2 );
		}

		$action     = '' !== $type_action ? $type_action : sanitize_key( $raw['am_action'] ?? '' );
		$user       = sanitize_user( $raw['am_user'] ?? '' );
		$date_from  = sanitize_text_field( $raw['am_from'] ?? '' );
		$date_to    = sanitize_text_field( $raw['am_to'] ?? '' );
		$search     = sanitize_text_field( $raw['am_search'] ?? '' );

		// Base for this render's own pagination links. Built explicitly
		// from $raw rather than left to paginate_links()'s implicit
		// current-URL default: that default is $_SERVER['REQUEST_URI'],
		// which during an AJAX refresh is admin-ajax.php, not the log
		// screen's URL, so pagination links generated there would point
		// to the wrong place. $raw already carries 'page' (from the
		// filter form's hidden input, or from the previous pagination
		// link's own href), so this reproduces the log screen's URL
		// exactly for both the initial page load and every AJAX refresh.
		$current_url = add_query_arg( $raw, admin_url( 'admin.php' ) );

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
			array( 'page' => self::PAGE_LOG ),
			admin_url( 'admin.php' )
		);

		// Status links are built from what the table actually holds under
		// the *other* active filters, not from AM_Log_Levels::ORDER. Eight
		// PSR-3 levels rendered unconditionally meant a row of eight links
		// on a site whose log was three levels deep, five of them landing
		// on "No activity found." -- offering a filter that cannot match
		// anything is worse than not offering it.
		$level_counts = AM_Event_Query::get_level_counts(
			compact( 'initiator', 'event_type', 'action', 'user', 'date_from', 'date_to', 'search' )
		);
		$level_total  = array_sum( $level_counts );

		// The selected level stays listed even when the other filters leave
		// it empty. It is the one link that must not disappear: dropping it
		// would strand the screen showing "No activity found." with nothing
		// marked current and no visible way back. Appended at zero rather
		// than reordered, since it's the exception to the list's own rule.
		if ( '' !== $level && ! isset( $level_counts[ $level ] ) ) {
			$level_counts[ $level ] = 0;
		}

		// Every status link carries the other filters forward. Without this
		// they rebuild from $base_url alone, so narrowing to one event type
		// and then clicking a level silently drops the type -- and the
		// counts, which *are* filter-aware, would disagree with what you
		// landed on.
		$level_link_args = array_filter( array(
			'am_initiator' => $initiator,
			'am_type'      => $type_filter,
			'am_user'      => $user,
			'am_from'      => $date_from,
			'am_to'        => $date_to,
			'am_search'    => $search,
		) );
		$level_base_url  = add_query_arg( $level_link_args, $base_url );

		$initiator_options = array();
		foreach ( AM_Initiator_Detector::all() as $init ) {
			$initiator_options[ $init ] = AM_Initiator_Detector::label( $init );
		}
		$pagination_html = '';
		if ( $num_pages > 1 ) {
			$pagination_html = wp_kses_post( paginate_links( array(
				'base'      => add_query_arg( 'paged', '%#%', $current_url ),
				'format'    => '',
				'prev_text' => '&laquo;',
				'next_text' => '&raquo;',
				'total'     => $num_pages,
				'current'   => $page,
			) ) );
		}
		// Built once and reused in both the top and bottom tablenav rows,
		// same as $pagination_html above -- avoids two copies of the same
		// _n()/number_format_i18n() call drifting apart.
		$displaying_num_html = sprintf(
			/* translators: %s: formatted number of matching log entries */
			esc_html( _n( '%s item', '%s items', $total, 'activity-monitor' ) ),
			number_format_i18n( $total )
		);
		?>

		<?php if ( 0 === AM_Event_Query::total_count() ) : ?>
			<div class="notice notice-info inline">
				<p>
					<?php esc_html_e( 'No activity recorded yet. Try editing a post, logging in, or changing a setting, then check back here.', 'activity-monitor' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<?php
		// Level filter as core's own status-link list -- the same control
		// the Plugins screen uses for All / Active / Inactive, counts
		// included. Was a row of colored pills; the severity colors still
		// carry meaning in the table's own Level badges, where they mark
		// actual rows rather than filter buttons.
		//
		// Suppressed entirely when one level or none is present: a lone
		// "All (12)" next to nothing is a control with no choice in it.
		// Always shown while a level filter is on, though, or turning it
		// on could remove the only control that turns it back off.
		if ( count( $level_counts ) > 1 || '' !== $level ) :
		?>
		<ul class="subsubsub">
			<li>
				<a href="<?php echo esc_url( $level_base_url ); ?>"
				   class="<?php echo '' === $level ? 'current' : ''; ?>">
					<?php esc_html_e( 'All', 'activity-monitor' ); ?>
					<span class="count">(<?php echo esc_html( number_format_i18n( $level_total ) ); ?>)</span>
				</a>
			</li>
			<?php foreach ( $level_counts as $lvl_val => $lvl_count ) : ?>
				<li>
					| <a href="<?php echo esc_url( add_query_arg( 'am_level', $lvl_val, $level_base_url ) ); ?>"
					     class="<?php echo ( $lvl_val === $level ) ? 'current' : ''; ?>">
						<?php echo esc_html( AM_Log_Levels::label( $lvl_val ) ); ?>
						<span class="count">(<?php echo esc_html( number_format_i18n( $lvl_count ) ); ?>)</span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php endif; ?>

		<form method="get" action="" id="am-filter-form">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_LOG ); ?>">
			<?php if ( '' !== $level ) : ?>
				<?php // Set by the status links above, not by any control in this
				      // form -- carried so changing a dropdown doesn't drop it. ?>
				<input type="hidden" name="am_level" value="<?php echo esc_attr( $level ); ?>">
			<?php endif; ?>
			<?php if ( '' !== $user ) : ?>
				<?php // The visible User box was removed, but the filter itself still
				      // works and is set from elsewhere -- the "View this user's
				      // activity" button in the user profile modal links with
				      // am_user. Without carrying it here, changing any other filter
				      // would submit this form without it and silently drop it. The
				      // chip rendered below is what makes it visible; this input
				      // only keeps it alive across submissions. ?>
				<input type="hidden" name="am_user" value="<?php echo esc_attr( $user ); ?>">
			<?php endif; ?>

			<div class="tablenav top">
				<div class="alignleft actions">
					<label class="screen-reader-text" for="am-filter-initiator"><?php esc_html_e( 'Filter by initiator', 'activity-monitor' ); ?></label>
					<select name="am_initiator" id="am-filter-initiator">
						<option value=""><?php esc_html_e( 'All initiators', 'activity-monitor' ); ?></option>
						<?php foreach ( $initiator_options as $init_val => $init_label ) : ?>
							<option value="<?php echo esc_attr( $init_val ); ?>" <?php selected( $init_val, $initiator ); ?>>
								<?php echo esc_html( $init_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<label class="screen-reader-text" for="am-filter-type"><?php esc_html_e( 'Filter by event type', 'activity-monitor' ); ?></label>
					<select name="am_type" id="am-filter-type">
						<option value=""><?php esc_html_e( 'All types', 'activity-monitor' ); ?></option>
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

					<label class="screen-reader-text" for="am-filter-from"><?php esc_html_e( 'From date', 'activity-monitor' ); ?></label>
					<input type="date" id="am-filter-from" name="am_from" value="<?php echo esc_attr( $date_from ); ?>">
					<label class="screen-reader-text" for="am-filter-to"><?php esc_html_e( 'To date', 'activity-monitor' ); ?></label>
					<input type="date" id="am-filter-to" name="am_to" value="<?php echo esc_attr( $date_to ); ?>">

					<?php
					// Empty $name, explicit id -- the same call shape
					// WP_List_Table::search_box() uses, which keeps the button
					// out of the resulting query string.
					submit_button( __( 'Filter', 'activity-monitor' ), '', '', false, array( 'id' => 'am-filter-submit' ) );
					?>

					<?php if ( $level || $initiator || $type_filter || $action || $user || $date_from || $date_to || $search ) : ?>
						<a href="<?php echo esc_url( $base_url ); ?>" id="am-log-reset" class="button"><?php esc_html_e( 'Reset', 'activity-monitor' ); ?></a>
					<?php endif; ?>
				</div>

				<p class="search-box">
					<label class="screen-reader-text" for="am-search-input"><?php esc_html_e( 'Search log:', 'activity-monitor' ); ?></label>
					<input type="search" id="am-search-input" name="am_search" value="<?php echo esc_attr( $search ); ?>">
					<?php submit_button( __( 'Search Log', 'activity-monitor' ), '', '', false, array( 'id' => 'search-submit' ) ); ?>
				</p>
				<br class="clear">
			</div>

			<?php
			// The user filter has no visible control of its own -- it's set
			// from the profile modal's "View this user's activity" link, not
			// from this bar -- so without this chip the log silently shows a
			// single user's rows with nothing on screen saying so. Only
			// rendered when the filter is actually on.
			if ( '' !== $user ) :
				$filtered_user  = get_user_by( 'login', $user );
				$filtered_label = $filtered_user
					? sprintf( '%s (%s)', $filtered_user->display_name, $filtered_user->user_login )
					: $user;
				// Built explicitly from the other filters rather than via
				// remove_query_arg() on the current URI, matching how every
				// other URL on this screen is composed.
				$clear_user_url = add_query_arg(
					array_filter( array(
						'am_level'     => $level,
						'am_initiator' => $initiator,
						'am_type'      => $type_filter,
						'am_from'      => $date_from,
						'am_to'        => $date_to,
						'am_search'    => $search,
					) ),
					$base_url
				);
			?>
			<div class="am-active-filter">
				<span class="am-filter-chip">
					<?php
					/* translators: %s: a user, shown as "Display Name (login)" */
					printf( esc_html__( 'Showing activity for %s', 'activity-monitor' ), esc_html( $filtered_label ) );
					?>
					<a href="<?php echo esc_url( $clear_user_url ); ?>"
					   class="am-filter-chip-remove"
					   aria-label="<?php esc_attr_e( 'Remove the user filter', 'activity-monitor' ); ?>">&times;</a>
				</span>
			</div>
			<?php endif; ?>

			<div class="am-table-scroll">
			<table class="wp-list-table widefat striped am-log-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Level',      'activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'Type',       'activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'Date',       'activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'Initiator',  'activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'Username',   'activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'IP Address', 'activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'Message',    'activity-monitor' ); ?></th>
						<th><?php esc_html_e( 'Actions',    'activity-monitor' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $items ) ) : ?>
						<tr class="no-items">
							<td class="colspanchange" colspan="8"><?php esc_html_e( 'No activity found.', 'activity-monitor' ); ?></td>
						</tr>
					<?php endif; ?>
					<?php foreach ( $items as $row ) : ?>
						<?php self::render_event_row( $row ); ?>
					<?php endforeach; ?>
					<?php
					// A page short of a full PER_PAGE only happens on the
					// last page of a multi-page result -- pad it out with
					// blank rows so that page doesn't render shorter than
					// every page before it. A lone under-full page (no
					// other page to jump against) is left alone.
					for ( $i = count( $items ); $num_pages > 1 && $i < $per_page; $i++ ) :
					?>
						<tr class="am-blank-row"><td colspan="8">&nbsp;</td></tr>
					<?php endfor; ?>
				</tbody>
			</table>
			</div><!-- .am-table-scroll -->

			<div class="tablenav bottom">
				<div class="alignleft actions">
					<span class="am-export-label"><?php esc_html_e( 'Export these results:', 'activity-monitor' ); ?></span>
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
						<a href="<?php echo esc_url( $export_url ); ?>" class="button"><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</div>

				<div class="tablenav-pages am-log-tablenav-pages<?php echo $num_pages > 1 ? '' : ' one-page'; ?>">
					<span class="displaying-num"><?php echo $displaying_num_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built via esc_html() above. ?></span>
					<span class="am-per-page">
						<label class="screen-reader-text" for="am-per-page-select"><?php esc_html_e( 'Rows per page', 'activity-monitor' ); ?></label>
						<select name="am_per_page" id="am-per-page-select">
							<?php foreach ( self::LOG_PER_PAGE_CHOICES as $choice ) : ?>
								<option value="<?php echo esc_attr( $choice ); ?>" <?php selected( $choice, $per_page ); ?>><?php echo esc_html( $choice ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php esc_html_e( 'rows', 'activity-monitor' ); ?>
					</span>
					<?php if ( $num_pages > 1 ) : ?>
						<span class="pagination-links"><?php echo $pagination_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already wp_kses_post()'d above. ?></span>
					<?php endif; ?>
				</div>
				<br class="clear">
			</div>
		</form>
		<?php
	}

	/**
	 * The Activity Log's actual entry point. #am-log-app is the whole
	 * boundary the AJAX refresh replaces: level links, filter form, table
	 * and pagination, together, so ajax_log_table() below can return
	 * exactly this container's inner HTML both for a page-turn/filter
	 * change and for a browser back/forward that lands here.
	 */
	private function render_log_screen() {
		// The filter bar is a GET form that only narrows what this screen
		// displays -- it changes no state, so there is nothing for a nonce to
		// protect. The screen itself is already manage_options-gated, and
		// every value reaching render_log_content() is sanitized before it
		// reaches AM_Event_Query, which binds it as a placeholder.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display filters, see above.
		$raw = wp_unslash( $_GET );
		echo '<div id="am-log-app">';
		$this->render_log_content( $raw );
		echo '</div>';
	}

	/**
	 * AJAX counterpart of render_log_screen(): re-renders #am-log-app's
	 * inner HTML from a query string posted by the JS pagination/filter
	 * handlers in admin.js, instead of a full page reload. $_POST['qs'] is
	 * the exact string the browser would otherwise have navigated to
	 * (built from the filter form's own serialize(), or from a clicked
	 * pagination link's href) -- parse_str() turns it back into the same
	 * shape $_GET would have been, so render_log_content() needs no
	 * AJAX-specific branch of its own.
	 */
	public function ajax_log_table() {
		check_ajax_referer( 'am_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '-1' );
		}

		parse_str( wp_unslash( (string) ( $_POST['qs'] ?? '' ) ), $raw );

		ob_start();
		$this->render_log_content( $raw );
		$html = ob_get_clean();

		wp_send_json_success( array( 'html' => $html ) );
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

	// ── Screen: Visitor Stats ────────────────────────────────────────────
	//
	// Reads from the am_stats_* tables through AM_Stats_Query -- entirely
	// separate from am_events / AM_Event_Query. Plain wp-admin list tables,
	// no charting library, matching how the rest of this plugin builds on
	// core's own furniture rather than restyling it.

	/** Date-range choices for the stats screen, keyed by days back. */
	private static function stats_range_choices(): array {
		return array(
			1   => __( 'Today', 'activity-monitor' ),
			7   => __( 'Last 7 days', 'activity-monitor' ),
			30  => __( 'Last 30 days', 'activity-monitor' ),
			90  => __( 'Last 90 days', 'activity-monitor' ),
			365 => __( 'Last 12 months', 'activity-monitor' ),
		);
	}

	/** Per-table page numbers this screen tracks, keyed the same way $pages is passed around below. */
	const STATS_PAGE_PARAMS = array(
		'hits'    => 'am_hits_page',
		'top'     => 'am_top_page',
		'ref'     => 'am_ref_page',
		'country' => 'am_country_page',
		'browser' => 'am_browser_page',
		'os'      => 'am_os_page',
		'device'  => 'am_device_page',
	);

	/** Reads and validates every per-table page number from a $_GET-shaped array. */
	private static function stats_pages_from_request( array $raw ): array {
		$pages = array();
		foreach ( self::STATS_PAGE_PARAMS as $key => $param ) {
			$pages[ $key ] = max( 1, absint( $raw[ $param ] ?? 1 ) );
		}
		return $pages;
	}

	/**
	 * How many rows a table with $total matching rows should render on its
	 * *current* page, before any row-leveling against sibling cards in the
	 * same row (see render_stats_content()'s two am-stats-breakdowns
	 * groups). PER_PAGE once there's more than one page to turn to --
	 * otherwise a short last page would render at a different height than
	 * the full ones before it -- and otherwise just $total, since a table
	 * that only ever has one page has nothing to level against on its own.
	 * A solo table (Top Pages, Recent Hits) uses this value as its final
	 * $pad_to directly; a table sharing a card row takes the max of this
	 * across every table in that row instead.
	 */
	private static function stats_own_target( int $total ): int {
		$num_pages = (int) ceil( $total / self::PER_PAGE );
		return $num_pages > 1 ? self::PER_PAGE : $total;
	}

	private function render_stats_screen() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display filter, sanitized below.
		$days = absint( $_GET['am_range'] ?? 30 );
		if ( ! array_key_exists( $days, self::stats_range_choices() ) ) {
			$days = 30;
		}

		if ( ! (bool) get_option( 'am_stats_enable_tracking', 1 ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Tracking is currently turned off in Settings → Visitor Stats. Figures below reflect only what was already recorded.', 'activity-monitor' ) . '</p></div>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination, sanitized in stats_pages_from_request().
		$pages = self::stats_pages_from_request( wp_unslash( $_GET ) );
		echo '<div id="am-stats-content">';
		$this->render_stats_content( $days, $pages );
		echo '</div>';
	}

	/**
	 * AJAX counterpart of render_stats_screen(): re-renders #am-stats-content's
	 * inner HTML for a range change or a page-turn on any one of its seven
	 * tables, from a query string posted by admin.js -- same qs-round-trip
	 * pattern as ajax_log_table().
	 */
	public function ajax_stats_content() {
		check_ajax_referer( 'am_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '-1' );
		}

		parse_str( wp_unslash( (string) ( $_POST['qs'] ?? '' ) ), $raw );

		$days = absint( $raw['am_range'] ?? 30 );
		if ( ! array_key_exists( $days, self::stats_range_choices() ) ) {
			$days = 30;
		}
		$pages = self::stats_pages_from_request( $raw );

		ob_start();
		$this->render_stats_content( $days, $pages );
		$html = ob_get_clean();

		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * The totals line plus all seven tables (Recent Hits, Top Pages,
	 * Referrers, Countries, Browsers, OS, Devices). $pages holds each
	 * table's own current page (see STATS_PAGE_PARAMS) -- a page-turn on
	 * one table doesn't reset any of the others, only a range change does
	 * (see admin.js's am-stats-filter-form submit handler).
	 */
	private function render_stats_content( int $days, array $pages ) {
		// Explicit base for every table's pagination links below, for the
		// same reason render_log_content() builds $current_url: the
		// implicit current-URL default paginate_links() would otherwise
		// use is admin-ajax.php during an AJAX refresh, not this screen's
		// own URL.
		$current_url = add_query_arg(
			array_merge(
				array( 'page' => self::PAGE_STATS, 'am_range' => $days ),
				array_combine( array_values( self::STATS_PAGE_PARAMS ), $pages )
			),
			admin_url( 'admin.php' )
		);

		$totals = AM_Stats_Query::get_totals( $days );
		?>
		<form method="get" id="am-stats-filter-form">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_STATS ); ?>">
			<ul class="am-stats-totals">
				<li>
					<?php
					printf(
						/* translators: %s: formatted visit count */
						esc_html__( '%s visits', 'activity-monitor' ),
						'<strong>' . esc_html( number_format_i18n( $totals['visits'] ) ) . '</strong>'
					);
					?>
				</li>
				<li>
					<?php
					printf(
						/* translators: %s: formatted unique-visitor count */
						esc_html__( '%s unique visitors', 'activity-monitor' ),
						'<strong>' . esc_html( number_format_i18n( $totals['unique_visitors'] ) ) . '</strong>'
					);
					?>
				</li>
				<li class="am-stats-range">
					<?php
					// No onchange="this.form.submit()" here -- see admin.js's
					// 'change' handler on this select for why that native
					// DOM method is the wrong tool once a JS refresh needs
					// to intercept the change (it doesn't fire a 'submit'
					// event, so nothing could catch it). The <noscript>
					// button below is what makes this form still work with
					// JS off.
					?>
					<select name="am_range">
						<?php foreach ( self::stats_range_choices() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $days ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<noscript><button type="submit" class="button"><?php esc_html_e( 'Apply', 'activity-monitor' ); ?></button></noscript>
				</li>
			</ul>
		</form>

		<?php $this->render_recent_hits_section( $days, $pages['hits'], $current_url ); ?>

		<h2><?php esc_html_e( 'Top Pages', 'activity-monitor' ); ?></h2>
		<?php
		// Top Pages is the one full-width table with no card-row siblings
		// to level against, so its own target (see stats_own_target()) is
		// also its final $pad_to.
		$top_data = AM_Stats_Query::get_top_urls( $days, $pages['top'], self::PER_PAGE );
		$this->render_paginated_stats_table(
			$top_data['items'],
			$top_data['total'],
			$pages['top'],
			self::STATS_PAGE_PARAMS['top'],
			$current_url,
			array( __( 'Title', 'activity-monitor' ), __( 'URL', 'activity-monitor' ), __( 'Visits', 'activity-monitor' ), __( 'Unique Visitors', 'activity-monitor' ) ),
			static function ( $row ) {
				// Title/URL are the two columns here that can run long, so
				// the anchor itself gets am-stats-truncate (wrap + 2-line
				// clamp, same as the Activity Log's Message column) -- not
				// the <td>, which the clamp's -webkit-box display would
				// pull out of table-cell layout. Both link to the live
				// page in a new tab -- the stored title is shown stripped
				// of its " » Site Name" suffix (see display_title()),
				// while the stored title itself is left untouched since
				// AM_Stats_Tracker keeps it current with document.title
				// verbatim.
				$full_url = home_url( $row->url );
				$title    = self::display_title( (string) $row->title );
				return array(
					'<a class="am-stats-truncate" href="' . esc_url( $full_url ) . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr( $title ) . '">' . esc_html( $title ?: '—' ) . '</a>',
					'<a class="am-stats-truncate" href="' . esc_url( $full_url ) . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr( $row->url ) . '">' . esc_html( $row->url ) . '</a>',
					esc_html( number_format_i18n( (int) $row->visits ) ),
					esc_html( number_format_i18n( (int) $row->unique_visitors ) ),
				);
			},
			self::stats_own_target( $top_data['total'] )
		);

		// Referrers + Countries share a card row, so both tables render at
		// the same row count -- the greater of the two's own targets
		// (stats_own_target()). Both queries have to run before either
		// renders, since neither side knows what to pad to until it knows
		// the other's total. Countries doesn't query at all (and doesn't
		// enter the max) while geolocation is off -- there's nothing to
		// level against but Referrers itself in that case.
		$ref_data     = AM_Stats_Query::get_referrers( $days, $pages['ref'], self::PER_PAGE );
		$geo_enabled  = (bool) get_option( 'am_stats_geo_enabled', 0 );
		$country_data = $geo_enabled ? AM_Stats_Query::get_breakdown( 'country_code', $days, $pages['country'], self::PER_PAGE ) : null;
		$row1_target  = max(
			self::stats_own_target( $ref_data['total'] ),
			$country_data ? self::stats_own_target( $country_data['total'] ) : 0
		);
		?>

		<div class="am-stats-breakdowns">
			<div>
				<h2><?php esc_html_e( 'Referrers', 'activity-monitor' ); ?></h2>
				<?php
				$this->render_paginated_stats_table(
					$ref_data['items'],
					$ref_data['total'],
					$pages['ref'],
					self::STATS_PAGE_PARAMS['ref'],
					$current_url,
					array( __( 'Host', 'activity-monitor' ), __( 'Visits', 'activity-monitor' ) ),
					static function ( $row ) {
						return array(
							esc_html( $row->referrer_host ),
							esc_html( number_format_i18n( (int) $row->visits ) ),
						);
					},
					$row1_target
				);
				?>
			</div>
			<div>
				<h2><?php esc_html_e( 'Countries', 'activity-monitor' ); ?></h2>
				<?php if ( ! $geo_enabled ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: link to Settings -> Visitor Stats */
							esc_html__( 'Turn on geolocation under %s to see visits by country.', 'activity-monitor' ),
							'<a href="' . esc_url( add_query_arg( array( 'page' => self::PAGE_SETTINGS ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Settings', 'activity-monitor' ) . '</a>'
						);
						?>
					</p>
				<?php else : ?>
					<?php $this->render_breakdown_table( 'country_code', $country_data, $pages['country'], $current_url, $row1_target, __( 'Country', 'activity-monitor' ) ); ?>
				<?php endif; ?>
			</div>
		</div>

		<?php
		// Browsers + Operating Systems + Devices share a three-up card
		// row -- same row-leveling as Referrers/Countries above, just
		// across three tables instead of two.
		$browser_data = AM_Stats_Query::get_breakdown( 'browser', $days, $pages['browser'], self::PER_PAGE );
		$os_data      = AM_Stats_Query::get_breakdown( 'os', $days, $pages['os'], self::PER_PAGE );
		$device_data  = AM_Stats_Query::get_breakdown( 'device_type', $days, $pages['device'], self::PER_PAGE );
		$row2_target  = max(
			self::stats_own_target( $browser_data['total'] ),
			self::stats_own_target( $os_data['total'] ),
			self::stats_own_target( $device_data['total'] )
		);
		?>

		<div class="am-stats-breakdowns">
			<div>
				<h2><?php esc_html_e( 'Browsers', 'activity-monitor' ); ?></h2>
				<?php $this->render_breakdown_table( 'browser', $browser_data, $pages['browser'], $current_url, $row2_target ); ?>
			</div>
			<div>
				<h2><?php esc_html_e( 'Operating Systems', 'activity-monitor' ); ?></h2>
				<?php $this->render_breakdown_table( 'os', $os_data, $pages['os'], $current_url, $row2_target ); ?>
			</div>
			<div>
				<h2><?php esc_html_e( 'Devices', 'activity-monitor' ); ?></h2>
				<?php $this->render_breakdown_table( 'device_type', $device_data, $pages['device'], $current_url, $row2_target ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Recent Hits: the history view, shown first (above the aggregated
	 * sections below it) since it's the most concrete answer to "what's
	 * actually happening." Every recorded pageview in the selected range,
	 * newest first, paginated the same way as every other table on this
	 * screen -- see render_paginated_stats_table(). $page and $current_url
	 * come from render_stats_content() rather than $_GET so this renders
	 * identically on a page load and on an AJAX refresh.
	 */
	private function render_recent_hits_section( int $days, int $page, string $current_url ) {
		$data        = AM_Stats_Query::get_hits( $days, $page, self::PER_PAGE );
		$geo_enabled = (bool) get_option( 'am_stats_geo_enabled', 0 );
		?>
		<h2><?php esc_html_e( 'Recent Hits', 'activity-monitor' ); ?></h2>
		<?php
		$columns = array( __( 'Date / Time', 'activity-monitor' ), __( 'Page', 'activity-monitor' ), __( 'Browser', 'activity-monitor' ), __( 'OS', 'activity-monitor' ), __( 'Device', 'activity-monitor' ) );
		if ( $geo_enabled ) {
			$columns[] = __( 'Country', 'activity-monitor' );
		}
		$columns[] = __( 'Referrer', 'activity-monitor' );

		$this->render_paginated_stats_table(
			$data['items'],
			$data['total'],
			$page,
			self::STATS_PAGE_PARAMS['hits'],
			$current_url,
			$columns,
			static function ( $row ) use ( $geo_enabled ) {
				$full_url = home_url( $row->url );
				$label    = self::display_title( (string) $row->title ) ?: $row->url;
				$cells    = array(
					esc_html( wp_date( AM_Date_Format::combined(), strtotime( $row->date . ' UTC' ) ) ),
					'<a class="am-stats-truncate" href="' . esc_url( $full_url ) . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr( $label ) . '">' . esc_html( $label ) . '</a>',
					esc_html( $row->browser ),
					esc_html( $row->os ),
					esc_html( ucfirst( $row->device_type ) ),
				);
				if ( $geo_enabled ) {
					$cells[] = esc_html( $row->country_code ?: '—' );
				}
				$cells[] = esc_html( $row->referrer_host ?: '—' );
				return $cells;
			},
			self::stats_own_target( $data['total'] )
		);
	}

	/**
	 * Strips a trailing " {separator} Site Name" suffix for display only --
	 * the stored am_stats_urls.title stays document.title verbatim (see
	 * AM_Stats_Tracker::get_or_create_url_id()), since that's the value
	 * that keeps itself current as a post's title changes. The separator
	 * isn't assumed to be any particular character (themes vary -- »,-,|,:),
	 * so this matches "any run of non-word characters immediately before
	 * the site name" rather than one hardcoded symbol. Falls back to the
	 * untouched title if the site name isn't found at the end, e.g. a
	 * cached hit from before the site's name changed.
	 */
	private static function display_title( string $title ): string {
		$site = get_bloginfo( 'name' );
		if ( '' === $title || '' === $site ) {
			return $title;
		}
		$stripped = preg_replace( '/\s*[^\w\s]+\s*' . preg_quote( $site, '/' ) . '\s*$/iu', '', $title );
		$stripped = trim( (string) $stripped );
		return '' !== $stripped ? $stripped : $title;
	}

	/**
	 * Renders one $rows result set as a wp-list-table, in the exact same
	 * shape as the Activity Log's grid: same wrapper (am-table-scroll),
	 * same table classes (widefat striped am-log-table -- auto layout,
	 * nowrap cells), same core styling for borders/header weight/row
	 * striping. $columns are the header labels; $row_cells maps one row
	 * object to an array of already-escaped cell HTML, in the same order
	 * as $columns. A truncated cell's own markup carries the
	 * am-stats-truncate class on an inner element (see the Top Pages/
	 * Recent Hits callers) -- never on the <td> itself, which the
	 * -webkit-box display the clamp needs would knock out of table-cell
	 * layout entirely (see .am-stats-truncate's comment).
	 *
	 * $pad_to, when greater than count( $rows ), appends blank rows until
	 * the table reaches that many -- see render_paginated_stats_table()'s
	 * doc for why (keeping a short last page, or a card sitting next to a
	 * taller one, from rendering at a different height than its
	 * neighbors). A single spanning <td> per blank row rather than one
	 * per column: nothing needs the per-column widths a real row's cells
	 * establish, since it's blank either way.
	 */
	private function render_stats_table( array $rows, array $columns, callable $row_cells, int $pad_to = 0 ) {
		if ( empty( $rows ) && $pad_to <= 0 ) {
			echo '<p>' . esc_html__( 'No data yet for this range.', 'activity-monitor' ) . '</p>';
			return;
		}
		?>
		<div class="am-table-scroll">
			<table class="wp-list-table widefat striped am-log-table">
				<thead>
					<tr>
						<?php foreach ( $columns as $column ) : ?>
							<th><?php echo esc_html( $column ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<?php foreach ( $row_cells( $row ) as $cell ) : ?>
								<td><?php echo $cell; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped by the caller's $row_cells callback. ?></td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
					<?php for ( $i = count( $rows ); $i < $pad_to; $i++ ) : ?>
						<tr class="am-blank-row"><td colspan="<?php echo count( $columns ); ?>">&nbsp;</td></tr>
					<?php endfor; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * $column is one of AM_Stats_Query::get_breakdown()'s whitelisted
	 * columns ('browser', 'os', 'device_type', 'country_code'). $data is
	 * pre-fetched by the caller (rather than queried in here, as it used
	 * to be) because render_stats_content() needs every table in a
	 * breakdown row's own total *before* it can decide $pad_to for any of
	 * them -- see stats_own_target() and the row-leveling comment there.
	 */
	private function render_breakdown_table( string $column, array $data, int $page, string $current_url, int $pad_to, string $value_label = '' ) {
		$param_key = array( 'country_code' => 'country', 'browser' => 'browser', 'os' => 'os', 'device_type' => 'device' )[ $column ];

		$this->render_paginated_stats_table(
			$data['items'],
			$data['total'],
			$page,
			self::STATS_PAGE_PARAMS[ $param_key ],
			$current_url,
			array( '' !== $value_label ? $value_label : __( 'Value', 'activity-monitor' ), __( 'Visits', 'activity-monitor' ) ),
			static function ( $row ) {
				return array(
					esc_html( $row->value ?: __( 'Other', 'activity-monitor' ) ),
					esc_html( number_format_i18n( (int) $row->visits ) ),
				);
			},
			$pad_to
		);
	}

	/**
	 * Wraps render_stats_table() with the same tablenav/pagination markup
	 * the Activity Log and Recent Hits use, capped at PER_PAGE (10) rows.
	 * $page_param is the query-string key this particular table's page
	 * number lives under (see STATS_PAGE_PARAMS) -- every table on the
	 * Visitor Stats screen paginates independently, so each needs its own
	 * key rather than sharing one 'paged'. No tablenav is rendered when
	 * there's nothing to page through and nothing to pad to, matching
	 * render_stats_table()'s own "no data" short-circuit. $pad_to is just
	 * handed to render_stats_table() -- see stats_own_target() for how
	 * callers arrive at it.
	 */
	private function render_paginated_stats_table( array $items, int $total, int $page, string $page_param, string $current_url, array $columns, callable $row_cells, int $pad_to = 0 ) {
		$this->render_stats_table( $items, $columns, $row_cells, $pad_to );

		if ( empty( $items ) && $pad_to <= 0 ) {
			return;
		}

		$per_page  = self::PER_PAGE;
		$num_pages = (int) ceil( $total / $per_page );

		$pagination_html = '';
		if ( $num_pages > 1 ) {
			$pagination_html = wp_kses_post( paginate_links( array(
				'base'      => add_query_arg( $page_param, '%#%', $current_url ),
				'format'    => '',
				'prev_text' => '&laquo;',
				'next_text' => '&raquo;',
				'total'     => $num_pages,
				'current'   => $page,
			) ) );
		}
		$displaying_num_html = sprintf(
			/* translators: %s: formatted number of matching rows */
			esc_html( _n( '%s item', '%s items', $total, 'activity-monitor' ) ),
			number_format_i18n( $total )
		);
		?>
		<div class="tablenav bottom">
			<div class="tablenav-pages<?php echo $num_pages > 1 ? '' : ' one-page'; ?>">
				<span class="displaying-num"><?php echo $displaying_num_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built via esc_html() above. ?></span>
				<?php if ( $num_pages > 1 ) : ?>
					<span class="pagination-links"><?php echo $pagination_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already wp_kses_post()'d above. ?></span>
				<?php endif; ?>
			</div>
			<br class="clear">
		</div>
		<?php
	}

	// ── Screen: Settings ──────────────────────────────────────────────────

	/**
	 * The Settings screen, top to bottom:
	 *
	 *   1. one options.php form holding every field (Logging, Display,
	 *      Privacy, uninstall), ending in a single Save Changes button
	 *   2. the notification channels list, added and edited through
	 *      modals that save over AJAX as you go, so it has nothing to
	 *      save at page level
	 *   3. Clear Log, an action rather than a setting, last because it is
	 *      destructive
	 *
	 * Everything above the Save button is a field; everything below it
	 * carries its own control. That ordering is the point: before 2.4.3
	 * this screen mixed two immediate-save tables in between three separate
	 * per-section save buttons, and nothing on it said which was which.
	 */
	private function render_settings_screen() {
		// This screen hangs off a custom top-level menu, so core doesn't
		// call settings_errors() for it the way it does on the Settings
		// menu's own pages. Without this, a successful save would redirect
		// back here silently.
		settings_errors();
		?>

		<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
			<?php
			settings_fields( self::SETTINGS_GROUP );
			do_settings_sections( self::PAGE_SETTINGS );
			submit_button();
			?>
		</form>

		<?php
		$this->render_geo_update_now_section();
		$this->render_channels_section();
		$this->render_clear_log_section();
	}

	// ── Settings fields ──────────────────────────────────────────────────

	public function section_intro_logging() {
		echo '<p>' . esc_html__( 'What gets recorded, and how long it is kept.', 'activity-monitor' ) . '</p>';
	}

	public function section_intro_privacy() {
		echo '<p>' . esc_html__( 'The activity log necessarily records who did what. These control how much of that is about the person rather than the action.', 'activity-monitor' ) . '</p>';
	}

	public function field_retention() {
		$current = (int) get_option( 'am_retention_days', 90 );
		?>
		<select id="am_retention_days" name="am_retention_days">
			<?php foreach ( self::retention_choices() as $days => $label ) : ?>
				<option value="<?php echo esc_attr( $days ); ?>" <?php selected( $days, $current ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php echo esc_html( self::retention_status_text() ); ?></p>
		<?php
	}

	/**
	 * The sentence under the retention control: how much is in the log,
	 * how far back it reaches, and when the next cleanup runs.
	 *
	 * A retention period is an abstract number on its own -- what makes it
	 * decidable is knowing that shortening it would take real entries with
	 * it. Kept as one plain sentence rather than a stat block; this is a
	 * field description, not a dashboard.
	 */
	private static function retention_status_text(): string {
		$total  = AM_Event_Query::total_count();
		$oldest = AM_Event_Query::oldest_date();

		if ( 0 === $total ) {
			return __( 'The log is empty. Older entries are deleted automatically once a day.', 'activity-monitor' );
		}

		if ( '' !== $oldest ) {
			$text = sprintf(
				/* translators: 1: number of entries, 2: date of the oldest entry */
				__( '%1$s in the log, reaching back to %2$s.', 'activity-monitor' ),
				sprintf(
					/* translators: %s: formatted number of entries */
					_n( '%s entry', '%s entries', $total, 'activity-monitor' ),
					number_format_i18n( $total )
				),
				wp_date( AM_Date_Format::combined(), strtotime( $oldest . ' UTC' ) )
			);
		} else {
			$text = sprintf(
				/* translators: %s: formatted number of entries */
				_n( '%s entry in the log.', '%s entries in the log.', $total, 'activity-monitor' ),
				number_format_i18n( $total )
			);
		}

		$next_prune = wp_next_scheduled( 'am_log_prune' );
		if ( $next_prune ) {
			$text .= ' ' . sprintf(
				/* translators: %s: next scheduled cleanup date/time */
				__( 'Next cleanup: %s.', 'activity-monitor' ),
				wp_date( AM_Date_Format::combined(), $next_prune )
			);
		}

		return $text;
	}

	public function field_grouping() {
		$current = (int) get_option( 'am_occasion_window_seconds', AM_Event_Writer::DEFAULT_OCCASION_WINDOW_SECONDS );
		?>
		<select id="am_occasion_window_seconds" name="am_occasion_window_seconds">
			<?php foreach ( self::grouping_choices() as $seconds => $label ) : ?>
				<option value="<?php echo esc_attr( $seconds ); ?>" <?php selected( $seconds, $current ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'The same action, repeated on the same thing by the same person within this window, becomes one entry with a count beside it instead of many. It stops a burst of near-identical activity burying everything else.', 'activity-monitor' ); ?>
		</p>
		<?php
	}

	public function field_datetime_format() {
		?>
		<select id="<?php echo esc_attr( AM_Date_Format::OPTION ); ?>" name="<?php echo esc_attr( AM_Date_Format::OPTION ); ?>">
			<?php foreach ( AM_Date_Format::choices() as $format_key => $format_label ) : ?>
				<option value="<?php echo esc_attr( $format_key ); ?>" <?php selected( $format_key, AM_Date_Format::current_key() ); ?>>
					<?php echo esc_html( $format_label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Each option shows how it renders right now. “Site default” follows Settings → General, so it stays in step if those change. CSV and JSON exports are excluded either way — those keep raw UTC values so they stay machine-readable.', 'activity-monitor' ); ?>
		</p>
		<?php
	}

	public function field_ip_storage() {
		$current = (string) get_option( 'am_ip_storage', 'full' );
		$modes   = array(
			'full'       => array(
				__( 'Store the full address', 'activity-monitor' ),
				__( 'What an audit trail normally wants: the address is exact, so repeat activity from one machine is identifiable.', 'activity-monitor' ),
			),
			'anonymized' => array(
				__( 'Store an anonymised address', 'activity-monitor' ),
				__( 'Masks the last part of the address using WordPress’s own anonymisation, so entries can still be grouped by network without identifying a device.', 'activity-monitor' ),
			),
			'none'       => array(
				__( 'Don’t store IP addresses', 'activity-monitor' ),
				__( 'The column stays empty. Everything else about each entry is recorded as usual.', 'activity-monitor' ),
			),
		);
		?>
		<fieldset>
			<legend class="screen-reader-text"><span><?php esc_html_e( 'IP addresses', 'activity-monitor' ); ?></span></legend>
			<?php foreach ( $modes as $mode => $copy ) : ?>
				<label>
					<input type="radio" name="am_ip_storage" value="<?php echo esc_attr( $mode ); ?>" <?php checked( $mode, $current ); ?>>
					<?php echo esc_html( $copy[0] ); ?>
				</label>
				<p class="description am-radio-description"><?php echo esc_html( $copy[1] ); ?></p>
			<?php endforeach; ?>
			<p class="description">
				<?php esc_html_e( 'Applies to entries recorded from now on — this changes what reaches the database, so entries already in the log are unaffected.', 'activity-monitor' ); ?>
			</p>
		</fieldset>
		<?php
	}

	public function field_ip_lookup() {
		?>
		<fieldset>
			<legend class="screen-reader-text"><span><?php esc_html_e( 'IP address lookups', 'activity-monitor' ); ?></span></legend>
			<label>
				<input type="checkbox" name="am_ip_lookup_enabled" value="1" <?php checked( self::ip_lookup_enabled() ); ?>>
				<?php esc_html_e( 'Allow IP addresses in the log to be looked up', 'activity-monitor' ); ?>
			</label>
			<p class="description">
				<?php
				printf(
					/* translators: %s: link to ipinfo.io */
					esc_html__( 'Clicking an address in the log opens its approximate location and network operator. Doing that sends the one address you clicked to %s, and nothing else about your site. Turn this off to keep the plugin entirely self-contained — addresses then show as plain text.', 'activity-monitor' ),
					'<a href="' . esc_url( 'https://ipinfo.io' ) . '" target="_blank" rel="noopener noreferrer">ipinfo.io</a>'
				);
				?>
			</p>
		</fieldset>
		<?php
	}

	public function field_delete_on_uninstall() {
		?>
		<fieldset>
			<legend class="screen-reader-text"><span><?php esc_html_e( 'Stored data', 'activity-monitor' ); ?></span></legend>
			<label>
				<input type="checkbox" name="am_delete_data_on_uninstall" value="1" <?php checked( (bool) get_option( 'am_delete_data_on_uninstall', 1 ) ); ?>>
				<?php esc_html_e( 'Delete the activity log and all settings when the plugin is deleted', 'activity-monitor' ); ?>
			</label>
			<p class="description">
				<?php esc_html_e( 'Deactivating never deletes anything. Deleting the plugin from the Plugins screen does — untick this to leave the log and its settings in the database instead, so reinstalling picks up where it left off.', 'activity-monitor' ); ?>
			</p>
		</fieldset>
		<?php
	}

	public function section_intro_stats() {
		echo '<p>' . esc_html__( 'Real-time visitor/traffic stats — visits, top pages, referrers, and browser/OS/device breakdown. Kept in its own tables, separate from the activity log.', 'activity-monitor' ) . '</p>';
	}

	public function field_stats_enable() {
		?>
		<fieldset>
			<legend class="screen-reader-text"><span><?php esc_html_e( 'Tracking', 'activity-monitor' ); ?></span></legend>
			<label>
				<input type="checkbox" name="am_stats_enable_tracking" value="1" <?php checked( (bool) get_option( 'am_stats_enable_tracking', 1 ) ); ?>>
				<?php esc_html_e( 'Record visits to the front end of this site', 'activity-monitor' ); ?>
			</label>
			<p class="description">
				<?php esc_html_e( 'No cookies are set and no IP address is stored — visitors are identified only by a one-way hash that rotates daily.', 'activity-monitor' ); ?>
			</p>
		</fieldset>
		<?php
	}

	public function field_stats_exclude_roles() {
		$excluded = (array) get_option( 'am_stats_exclude_roles', array( 'administrator' ) );
		?>
		<fieldset>
			<legend class="screen-reader-text"><span><?php esc_html_e( 'Exclude roles', 'activity-monitor' ); ?></span></legend>
			<?php foreach ( wp_roles()->get_names() as $role_slug => $role_label ) : ?>
				<label style="display:block;">
					<input type="checkbox" name="am_stats_exclude_roles[]" value="<?php echo esc_attr( $role_slug ); ?>" <?php checked( in_array( $role_slug, $excluded, true ) ); ?>>
					<?php echo esc_html( translate_user_role( $role_label ) ); ?>
				</label>
			<?php endforeach; ?>
			<p class="description">
				<?php esc_html_e( 'Logged-in users with a checked role are never recorded — useful for keeping your own admin visits out of the numbers.', 'activity-monitor' ); ?>
			</p>
		</fieldset>
		<?php
	}

	public function field_stats_retention() {
		$current = (int) get_option( 'am_stats_retention_days', 90 );
		?>
		<select id="am_stats_retention_days" name="am_stats_retention_days">
			<?php foreach ( self::retention_choices() as $days => $label ) : ?>
				<option value="<?php echo esc_attr( $days ); ?>" <?php selected( $days, $current ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'Separate from the activity log’s own retention setting above — visit data is far higher volume and lower forensic value, so it doesn’t need to be kept as long.', 'activity-monitor' ); ?></p>
		<?php
	}

	public function section_intro_stats_geo() {
		?>
		<p>
			<?php
			printf(
				/* translators: %s: link to MaxMind's sign-up page */
				esc_html__( 'Adds a Country column to Visitor Stats, resolved entirely on this server from a local database — no visitor IP is ever sent anywhere. Requires a free MaxMind account: %s.', 'activity-monitor' ),
				'<a href="' . esc_url( 'https://www.maxmind.com/en/geolite2/signup' ) . '" target="_blank" rel="noopener noreferrer">maxmind.com/en/geolite2/signup</a>'
			);
			?>
		</p>
		<?php
	}

	public function field_stats_geo_account_id() {
		$has_value = '' !== (string) get_option( 'am_stats_geo_account_id', '' );
		?>
		<input type="password" id="am_stats_geo_account_id" name="am_stats_geo_account_id" value="" autocomplete="new-password" class="regular-text"
			placeholder="<?php echo $has_value ? esc_attr__( 'Saved — leave blank to keep it', 'activity-monitor' ) : ''; ?>">
		<?php if ( $has_value ) : ?>
			<label><input type="checkbox" name="am_stats_geo_account_id_clear" value="1"> <?php esc_html_e( 'Clear', 'activity-monitor' ); ?></label>
		<?php endif; ?>
		<?php
	}

	/**
	 * autocomplete="new-password" here and on the account ID field above,
	 * not "off" -- browsers and password-manager extensions widely ignore
	 * "off" on a password-type input and offer to fill it from a saved
	 * credential anyway. "new-password" is the value the autofill spec
	 * actually defines for "this is a fresh secret, not a login to
	 * autofill or offer to save." This matters more here than on an
	 * ordinary login form: this field never echoes its saved value back
	 * (see the description below), so a credential silently autofilled by
	 * a password manager is visually indistinguishable from an empty
	 * field with its placeholder -- the wrong value would get saved and
	 * sent to MaxMind with nothing in the UI hinting why, surfacing only
	 * as an opaque HTTP 401 from stage_download().
	 */
	public function field_stats_geo_license_key() {
		$has_value = '' !== (string) get_option( 'am_stats_geo_license_key', '' );
		?>
		<input type="password" id="am_stats_geo_license_key" name="am_stats_geo_license_key" value="" autocomplete="new-password" class="regular-text"
			placeholder="<?php echo $has_value ? esc_attr__( 'Saved — leave blank to keep it', 'activity-monitor' ) : ''; ?>">
		<?php if ( $has_value ) : ?>
			<label><input type="checkbox" name="am_stats_geo_license_key_clear" value="1"> <?php esc_html_e( 'Clear', 'activity-monitor' ); ?></label>
		<?php endif; ?>
		<p class="description"><?php esc_html_e( 'Neither field is ever displayed again once saved, including here — only whether one is currently set.', 'activity-monitor' ); ?></p>
		<?php
	}

	public function field_stats_geo_enabled() {
		?>
		<label>
			<input type="checkbox" name="am_stats_geo_enabled" value="1" <?php checked( (bool) get_option( 'am_stats_geo_enabled', 0 ) ); ?>>
			<?php esc_html_e( 'Resolve visits to a country', 'activity-monitor' ); ?>
		</label>
		<?php
	}

	public function field_stats_geo_status() {
		$status = AM_Stats_Geo_Updater::status();
		?>
		<p>
			<?php if ( ! $status['configured'] ) : ?>
				<?php esc_html_e( 'Enter an account ID and license key above to enable database updates.', 'activity-monitor' ); ?>
			<?php elseif ( $status['in_progress'] ) : ?>
				<?php
				printf(
					/* translators: %s: current import stage, e.g. "blocks_ipv4" */
					esc_html__( 'Import in progress (stage: %s) — this runs in the background across several page loads and can take a few minutes.', 'activity-monitor' ),
					'<code>' . esc_html( $status['stage'] ) . '</code>'
				);
				?>
			<?php elseif ( '' !== $status['error'] ) : ?>
				<span style="color:#d63638;"><?php echo esc_html( sprintf(
					/* translators: %s: error message */
					__( 'Last import failed: %s', 'activity-monitor' ),
					$status['error']
				) ); ?></span>
			<?php elseif ( $status['last_updated'] > 0 ) : ?>
				<?php
				printf(
					/* translators: 1: number of ranges, 2: last updated date/time */
					esc_html__( '%1$s IP ranges loaded, last updated %2$s.', 'activity-monitor' ),
					esc_html( number_format_i18n( $status['row_count'] ) ),
					esc_html( wp_date( AM_Date_Format::combined(), $status['last_updated'] ) )
				);
				?>
			<?php else : ?>
				<?php esc_html_e( 'No database imported yet.', 'activity-monitor' ); ?>
			<?php endif; ?>
		</p>
		<?php
		// No "Update Now" form here, deliberately: this field renders
		// inside do_settings_sections(), which the Settings screen's own
		// <form action="options.php"> already wraps. A second <form>
		// nested inside it is invalid HTML -- the browser collapses it
		// into the outer one, so the button submitted the whole settings
		// form to options.php instead of posting to admin-post.php. See
		// render_geo_update_now_section(), called after that form closes,
		// same fix render_clear_log_section() already uses for Clear Log.
	}

	/**
	 * "Update Now" button for GeoLite2, as its own top-level form -- see
	 * field_stats_geo_status()'s comment on why it can't live inside a
	 * settings field. Rendered right after the settings section it
	 * logically belongs to (Geolocation is the last field on that
	 * section), before Notification Channels.
	 */
	private function render_geo_update_now_section() {
		$status = AM_Stats_Geo_Updater::status();
		if ( ! $status['configured'] || $status['in_progress'] ) {
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'am_stats_geo_update_now' ); ?>
			<input type="hidden" name="action" value="am_stats_geo_update_now">
			<?php submit_button( __( 'Update Now', 'activity-monitor' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	// ── Notification channels ────────────────────────────────────────────

	private function render_channels_section() {
		$channels = get_option( 'am_notification_channels', array() );
		?>
		<h2><?php esc_html_e( 'Notification Channels', 'activity-monitor' ); ?></h2>
		<p>
			<?php esc_html_e( 'Instant alerts. Each channel fires when an event meets or exceeds its minimum level. Channels save as you add or edit them — the Save Changes button above does not apply to them.', 'activity-monitor' ); ?>
		</p>

		<?php if ( empty( $channels ) ) : ?>
			<p class="description"><?php esc_html_e( 'No channels configured yet.', 'activity-monitor' ); ?></p>
		<?php else : ?>
			<div class="am-table-scroll">
				<table class="wp-list-table widefat striped am-log-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Type', 'activity-monitor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Name', 'activity-monitor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Minimum Level', 'activity-monitor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Target', 'activity-monitor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Actions', 'activity-monitor' ); ?></th>
						</tr>
					</thead>
					<tbody id="am-channels-table-body">
						<?php foreach ( $channels as $i => $ch ) : ?>
							<?php $this->render_channel_table_row( $i, $ch ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>

		<p>
			<button type="button" class="button am-add-channel-btn" data-type="email">
				<?php esc_html_e( 'Add Email Channel', 'activity-monitor' ); ?>
			</button>
			<button type="button" class="button am-add-channel-btn" data-type="slack">
				<?php esc_html_e( 'Add Slack Channel', 'activity-monitor' ); ?>
			</button>
		</p>
		<?php
	}

	// ── Clear log ────────────────────────────────────────────────────────

	private function render_clear_log_section() {
		?>
		<h2><?php esc_html_e( 'Clear Log', 'activity-monitor' ); ?></h2>
		<p>
			<?php esc_html_e( 'Permanently delete every entry in the activity log. This cannot be undone, and it is separate from the retention setting above, which only removes entries once they age out.', 'activity-monitor' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
		      onsubmit="return confirm('<?php echo esc_js( __( 'Clear all log entries? This cannot be undone.', 'activity-monitor' ) ); ?>')">
			<?php wp_nonce_field( 'am_clear_log' ); ?>
			<input type="hidden" name="action" value="am_clear_log">
			<?php submit_button( __( 'Clear Entire Log', 'activity-monitor' ), 'am-btn-danger', 'submit', true ); ?>
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

			<p id="am-channel-modal-error" class="am-modal-error" style="display:none;"></p>

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
