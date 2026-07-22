<?php
/**
 * Plugin Name: Activity Monitor
 * Plugin URI:  https://robstibal.com
 * Description: Comprehensive WordPress audit log – tracks logins, content changes, settings updates, security events, and more.
 * Version:     2.0.0-dev.10
 * Author:      Rob Stibal
 * Author URI:  http://robstibal.com
 * License:     GPL-2.0+
 * Text Domain: activity-monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AM_VERSION',     '2.0.0-dev.10' );
define( 'AM_FILE',        __FILE__ );
define( 'AM_DIR',         plugin_dir_path( __FILE__ ) );
define( 'AM_URL',         plugin_dir_url( __FILE__ ) );
define( 'AM_TABLE',       'am_activity_log' ); // Legacy v1.x table name — retained until migration UI (issue #2) is confirmed by the admin.

// ── v2.0 core (schema, event writer, logger architecture) ────────────────
// See activity-monitor-v2-spec.md §9 for build order. Files below are the
// v2.0 scaffold; v1.x files (class-am-db.php, class-am-logger.php,
// class-am-hooks.php) remain temporarily for reference during the port
// and are removed once AM_Logger_Manager::REGISTERED_LOGGER_CLASSES covers
// full event parity (spec §9 item 2).
require_once AM_DIR . 'includes/schema/class-am-schema.php';
require_once AM_DIR . 'includes/class-am-db-legacy-ip.php';
require_once AM_DIR . 'includes/class-am-log-levels.php';
require_once AM_DIR . 'includes/class-am-initiator-detector.php';
require_once AM_DIR . 'includes/class-am-event-writer.php';
require_once AM_DIR . 'includes/class-am-event-query.php';
require_once AM_DIR . 'includes/class-am-sessions.php';
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
require_once AM_DIR . 'includes/class-am-logger-manager.php';

// ── v1.x legacy (still present during the port; see TODOs above) ─────────
require_once AM_DIR . 'includes/class-am-db.php';
require_once AM_DIR . 'includes/class-am-logger.php';
require_once AM_DIR . 'includes/class-am-hooks.php';
require_once AM_DIR . 'includes/class-am-notifications.php';
require_once AM_DIR . 'admin/class-am-admin.php';

// ── Activation / deactivation ────────────────────────────────────────────
/**
 * BUGFIX (dev.5 → dev.6): activation previously called only
 * AM_Schema::install(), which creates am_events/am_event_context and
 * migrates FROM the legacy table if present -- but never CREATES the
 * legacy table itself. AM_DB::install() is what always created
 * wp_am_activity_log. Swapping the hook silently stopped creating it on
 * fresh installs, breaking the still-active v1.x "Activity Log" tab
 * (which several loggers -- comments, themes, core, etc. -- still write
 * to) with "table doesn't exist" errors. Both must run on activation
 * until the legacy table and v1.x admin screens are fully retired.
 */
function am_activate() {
	AM_DB::install();
	AM_Schema::install();
}
register_activation_hook( AM_FILE, 'am_activate' );
register_deactivation_hook( AM_FILE, array( 'AM_DB', 'deactivate' ) );

// ── Bootstrap ─────────────────────────────────────────────────────────────
function am_init() {
	// Self-healing safety net: if the legacy table is somehow missing
	// (e.g. this exact activation-hook bug from dev.5, or a manual DB
	// change), recreate it. dbDelta() is idempotent and cheap to call on
	// every load -- this runs once via the 'am_legacy_table_checked'
	// transient so it isn't a query on every single page load.
	if ( ! get_transient( 'am_legacy_table_checked' ) ) {
		global $wpdb;
		$legacy_table = $wpdb->prefix . AM_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy_table ) );
		if ( $exists !== $legacy_table ) {
			AM_DB::install();
		}
		set_transient( 'am_legacy_table_checked', 1, DAY_IN_SECONDS );
	}

	AM_Schema::maybe_upgrade();
	AM_Logger_Manager::init();

	// Legacy v1.x hooks remain active for event types not yet ported
	// (see AM_Logger_Manager::REGISTERED_LOGGER_CLASSES TODO list).
	// Ported loggers (registered above) supersede their corresponding
	// callbacks in AM_Hooks; the rest of AM_Hooks still runs until ported.
	AM_Hooks::init();
	AM_Admin::init();
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
 * FIX #3: Use $wpdb->prepare() for the DELETE query.
 * The INTERVAL value cannot be parameterised via %d in MySQL's DATE_SUB,
 * so we validate the retention days from an option (default 90) and
 * cast it to absint before interpolation – safe because no user input
 * reaches the query string.
 */
function am_run_prune() {
	global $wpdb;
	$days  = absint( get_option( 'am_retention_days', 90 ) );
	$table = $wpdb->prefix . AM_TABLE;
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM `{$table}` WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
		$days
	) );
}
add_action( 'am_log_prune', 'am_run_prune' );
