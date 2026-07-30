<?php
/**
 * Plugin Name: Activity Monitor
 * Plugin URI:  https://robstibal.com
 * Description: Comprehensive WordPress audit log – tracks logins, content changes, settings updates, security events, and more.
 * Version:     2.2.0
 * Author:      Rob Stibal
 * Author URI:  http://robstibal.com
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: activity-monitor
 * Requires at least: 5.3
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AM_VERSION', '2.2.0' );
define( 'AM_FILE',    __FILE__ );
define( 'AM_DIR',     plugin_dir_path( __FILE__ ) );
define( 'AM_URL',     plugin_dir_url( __FILE__ ) );

// ── Core (schema, event writer, logger architecture) ─────────────────────
// Full v1.x legacy retirement (dev.14): AM_Hooks (dev.12), AM_DB, and
// AM_Logger (this build) are all gone. The am_activity_log table itself
// is left in place on existing installs -- see AM_Schema's class doc --
// but nothing in this plugin creates, writes to, reads from, or prunes
// it anymore as of this version. AM_Schema::maybe_migrate_from_v1() still
// runs its one-time backfill from that table if it exists (untouched,
// no reason to remove a working non-destructive migration), but no
// longer needs a sibling AM_DB::install() call to create the table in
// the first place, since there's no legacy admin screen left that reads
// from it. See activity-monitor-v2-spec.md §9.
require_once AM_DIR . 'includes/schema/class-am-schema.php';
require_once AM_DIR . 'includes/class-am-db-legacy-ip.php';
require_once AM_DIR . 'includes/class-am-log-levels.php';
require_once AM_DIR . 'includes/class-am-date-format.php';
require_once AM_DIR . 'includes/class-am-event-labels.php';
require_once AM_DIR . 'includes/class-am-initiator-detector.php';
require_once AM_DIR . 'includes/class-am-event-writer.php';
require_once AM_DIR . 'includes/class-am-event-query.php';
require_once AM_DIR . 'includes/class-am-sessions.php';
require_once AM_DIR . 'includes/class-am-notifications.php';
require_once AM_DIR . 'includes/class-am-digest.php';
require_once AM_DIR . 'includes/class-am-export.php';
require_once AM_DIR . 'includes/loggers/class-am-logger-base.php';
require_once AM_DIR . 'includes/loggers/class-am-logger-posts.php';
require_once AM_DIR . 'includes/loggers/class-am-logger-users.php';
require_once AM_DIR . 'includes/loggers/class-am-logger-media.php';
require_once AM_DIR . 'includes/loggers/class-am-logger-comments.php';
require_once AM_DIR . 'includes/loggers/class-am-logger-plugins.php';
require_once AM_DIR . 'includes/loggers/class-am-logger-themes.php';
require_once AM_DIR . 'includes/loggers/class-am-logger-core.php';
require_once AM_DIR . 'includes/loggers/class-am-logger-terms.php';
require_once AM_DIR . 'includes/loggers/class-am-logger-menus.php';
require_once AM_DIR . 'includes/loggers/class-am-logger-widgets.php';
require_once AM_DIR . 'includes/loggers/class-am-logger-passwords.php';
require_once AM_DIR . 'includes/loggers/class-am-logger-sites.php';
require_once AM_DIR . 'includes/loggers/class-am-logger-security.php';
require_once AM_DIR . 'includes/loggers/class-am-logger-fatal-errors.php';
require_once AM_DIR . 'includes/loggers/class-am-logger-file-editor.php';
require_once AM_DIR . 'includes/loggers/class-am-logger-maintenance-mode.php';
require_once AM_DIR . 'includes/loggers/class-am-logger-mail-failures.php';
require_once AM_DIR . 'includes/class-am-logger-manager.php';
require_once AM_DIR . 'admin/class-am-admin.php';

// ── Activation / deactivation ────────────────────────────────────────────
register_activation_hook( AM_FILE, array( 'AM_Schema', 'install' ) );
// No deactivation cleanup needed -- v2.0 data is intentionally kept on
// deactivation (only uninstall.php removes it), same policy v1.x had.

// ── Bootstrap ─────────────────────────────────────────────────────────────
function am_init() {
	AM_Schema::maybe_upgrade();
	am_maybe_cleanup_traffic();
	AM_Logger_Manager::init();
	AM_Admin::init();
	AM_Digest::init();

	// Cheap idempotent check -- wp_next_scheduled() is a single option
	// read, so safe on every load. Ensures a config change from the
	// settings modal (which already calls reschedule() directly) is
	// also caught if it was ever changed by any other means (WP-CLI,
	// direct DB edit, etc.).
	if ( ! wp_next_scheduled( AM_Digest::CRON_HOOK ) && ! empty( AM_Digest::get_configs() ) ) {
		AM_Digest::reschedule();
	}
}
add_action( 'plugins_loaded', 'am_init' );

/**
 * One-time removal of the page-traffic subsystem, dropped in 2.2.0.
 *
 * Everything it owned goes: both tables, its five options, its own DB
 * version option, and the nightly rollup cron -- which would otherwise
 * stay scheduled forever against a hook no longer registered anywhere.
 *
 * The tables are dropped outright rather than left in place. That is a
 * deliberate departure from how the v1.x am_activity_log table was
 * handled (kept until an explicit admin action, see AM_Schema's class
 * doc): that table held data being migrated into the schema that
 * replaced it, so keeping it cost nothing and losing it would have been
 * unrecoverable. Page-view data has no successor here -- nothing in the
 * plugin will ever read it again -- so the choice is between deleting it
 * on upgrade and leaving two orphan tables on every site indefinitely.
 * This is destructive and irreversible for anyone who was relying on
 * that history; it is called out in the 2.2.0 changelog rather than
 * done quietly.
 *
 * Guarded by an option flag so the DROPs run exactly once, matching the
 * am_v1_migrated pattern. The guard costs a single option read per load,
 * which is already in cache by the time this runs.
 */
function am_maybe_cleanup_traffic() {
	if ( get_option( 'am_traffic_cleanup_done' ) ) {
		return;
	}

	global $wpdb;

	// Dropped directly rather than through AM_Traffic_Schema, which no
	// longer exists -- same precedent as the am_installs table in
	// uninstall.php, whose class was likewise removed with its feature.
	foreach ( array( 'am_traffic_log', 'am_traffic_daily' ) as $am_traffic_table ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$am_traffic_table}`" );
	}

	foreach ( array(
		'am_traffic_enabled',
		'am_traffic_retention_days',
		'am_traffic_live_poll_seconds',
		'am_traffic_live_feed_limit',
		'am_traffic_db_version',
	) as $am_traffic_option ) {
		delete_option( $am_traffic_option );
	}

	$am_rollup_timestamp = wp_next_scheduled( 'am_traffic_rollup' );
	if ( $am_rollup_timestamp ) {
		wp_unschedule_event( $am_rollup_timestamp, 'am_traffic_rollup' );
	}

	update_option( 'am_traffic_cleanup_done', true );
}

// ── Log retention cron ───────────────────────────────────────────────────
function am_schedule_prune() {
	if ( ! wp_next_scheduled( 'am_log_prune' ) ) {
		wp_schedule_event( time(), 'daily', 'am_log_prune' );
	}
}
add_action( 'wp', 'am_schedule_prune' );

/**
 * Retention pruning, now against the v2.0 schema via AM_Schema::prune().
 * Previously ran a raw DELETE against the legacy am_activity_log table
 * directly in this file -- moved into AM_Schema alongside clear_all() so
 * all schema-level maintenance operations live in one place, and so this
 * plugin doesn't retain a dangling reference to a table nothing else
 * touches anymore.
 */
function am_run_prune() {
	AM_Schema::prune( absint( get_option( 'am_retention_days', 90 ) ) );
}
add_action( 'am_log_prune', 'am_run_prune' );
