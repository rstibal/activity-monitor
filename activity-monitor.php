<?php
/**
 * Plugin Name: Activity Monitor
 * Plugin URI:  https://robstibal.com
 * Description: Comprehensive WordPress audit log – tracks logins, content changes, settings updates, security events, and more.
 * Version:     2.0.78
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

define( 'AM_VERSION', '2.0.78' );
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
require_once AM_DIR . 'includes/schema/class-am-traffic-schema.php';
require_once AM_DIR . 'includes/schema/class-am-installs-schema.php';
require_once AM_DIR . 'includes/class-am-db-legacy-ip.php';
require_once AM_DIR . 'includes/class-am-log-levels.php';
require_once AM_DIR . 'includes/class-am-date-format.php';
require_once AM_DIR . 'includes/class-am-event-labels.php';
require_once AM_DIR . 'includes/class-am-initiator-detector.php';
require_once AM_DIR . 'includes/class-am-event-writer.php';
require_once AM_DIR . 'includes/class-am-event-query.php';
require_once AM_DIR . 'includes/class-am-traffic.php';
require_once AM_DIR . 'includes/class-am-traffic-query.php';
require_once AM_DIR . 'includes/class-am-traffic-rollup.php';
require_once AM_DIR . 'includes/class-am-sessions.php';
require_once AM_DIR . 'includes/class-am-notifications.php';
require_once AM_DIR . 'includes/class-am-installs.php';
require_once AM_DIR . 'includes/class-am-hub-receiver.php';
require_once AM_DIR . 'includes/class-am-hub-reporter.php';
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
register_activation_hook( AM_FILE, array( 'AM_Traffic_Schema', 'install' ) );
register_activation_hook( AM_FILE, array( 'AM_Installs_Schema', 'install' ) );
// No deactivation cleanup needed -- v2.0 data is intentionally kept on
// deactivation (only uninstall.php removes it), same policy v1.x had.

// ── Bootstrap ─────────────────────────────────────────────────────────────
function am_init() {
	AM_Schema::maybe_upgrade();
	AM_Traffic_Schema::maybe_upgrade();
	AM_Installs_Schema::maybe_upgrade();
	AM_Logger_Manager::init();
	AM_Traffic::init();
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

	AM_Traffic_Rollup::reschedule();
	AM_Hub_Reporter::reschedule();
}
add_action( 'plugins_loaded', 'am_init' );

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

// ── Traffic rollup + retention cron ──────────────────────────────────────
add_action( AM_Traffic_Rollup::CRON_HOOK, array( 'AM_Traffic_Rollup', 'run' ) );

// ── Active Installs hub feature ──────────────────────────────────────────
add_action( 'rest_api_init', array( 'AM_Hub_Receiver', 'register_routes' ) );
add_action( AM_Hub_Reporter::CRON_HOOK, array( 'AM_Hub_Reporter', 'run' ) );
