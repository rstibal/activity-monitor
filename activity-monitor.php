<?php
/**
 * Plugin Name: Activity Monitor
 * Plugin URI:  https://robstibal.com
 * Description: Comprehensive WordPress audit log – tracks logins, content changes, settings updates, security events, and more. Includes real-time visitor/traffic stats with optional country-level geolocation.
 * Version:     2.9.14
 * Author:      Rob Stibal
 * Author URI:  http://robstibal.com
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: activity-monitor
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AM_VERSION', '2.9.14' );
define( 'AM_FILE',    __FILE__ );
define( 'AM_DIR',     plugin_dir_path( __FILE__ ) );
define( 'AM_URL',     plugin_dir_url( __FILE__ ) );

// ── Core (schema, event writer, logger architecture) ─────────────────────
// The v1.x classes (AM_Hooks, AM_DB, AM_Logger) are all gone. The
// am_activity_log table they used is still left in place on existing
// installs -- see AM_Schema's class doc -- but nothing here creates,
// writes to, reads from, or prunes it. The one exception is
// AM_Schema::maybe_migrate_from_v1(), which still runs its one-time
// backfill from that table when it exists; it is non-destructive and
// works, so there is no reason to remove it.
require_once AM_DIR . 'includes/schema/class-am-schema.php';
require_once AM_DIR . 'includes/class-am-db-legacy-ip.php';
require_once AM_DIR . 'includes/class-am-log-levels.php';
require_once AM_DIR . 'includes/class-am-date-format.php';
require_once AM_DIR . 'includes/class-am-event-labels.php';
require_once AM_DIR . 'includes/class-am-initiator-detector.php';
require_once AM_DIR . 'includes/class-am-bulk-context.php';
require_once AM_DIR . 'includes/class-am-event-writer.php';
require_once AM_DIR . 'includes/class-am-event-query.php';
require_once AM_DIR . 'includes/class-am-notifications.php';
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
require_once AM_DIR . 'includes/loggers/class-am-logger-php-warnings.php';
require_once AM_DIR . 'includes/loggers/class-am-logger-file-editor.php';
require_once AM_DIR . 'includes/loggers/class-am-logger-maintenance-mode.php';
require_once AM_DIR . 'includes/loggers/class-am-logger-mail-failures.php';
require_once AM_DIR . 'includes/loggers/class-am-logger-mail-sent.php';
require_once AM_DIR . 'includes/loggers/class-am-logger-rest-api.php';
require_once AM_DIR . 'includes/loggers/class-am-logger-options.php';
require_once AM_DIR . 'includes/class-am-logger-manager.php';
require_once AM_DIR . 'admin/class-am-admin.php';

// ── Visitor/traffic stats ─────────────────────────────────────────────────
// A subsystem deliberately kept separate from the schema above -- see
// AM_Stats_Schema's class doc. Never writes to am_events.
require_once AM_DIR . 'includes/stats/class-am-stats-schema.php';
require_once AM_DIR . 'includes/stats/class-am-stats-ua-parser.php';
require_once AM_DIR . 'includes/stats/class-am-stats-geo.php';
require_once AM_DIR . 'includes/stats/class-am-stats-geo-updater.php';
require_once AM_DIR . 'includes/stats/class-am-stats-tracker.php';
require_once AM_DIR . 'includes/stats/class-am-stats-query.php';

// ── Activation / deactivation ────────────────────────────────────────────
register_activation_hook( AM_FILE, array( 'AM_Schema', 'install' ) );
register_activation_hook( AM_FILE, array( 'AM_Stats_Schema', 'install' ) );
// No deactivation cleanup needed -- v2.0 data is intentionally kept on
// deactivation (only uninstall.php removes it), same policy v1.x had.

// ── Bootstrap ─────────────────────────────────────────────────────────────
function am_init() {
	AM_Schema::maybe_upgrade();
	AM_Stats_Schema::maybe_upgrade();
	am_run_upgrade_cleanup();
	AM_Bulk_Context::init();
	AM_Logger_Manager::init();
	AM_Stats_Tracker::init();
	AM_Stats_Geo_Updater::init();
	AM_Admin::init();
}
add_action( 'plugins_loaded', 'am_init' );

/**
 * Removes what past versions left behind: tables, options, and cron
 * events belonging to features that no longer exist.
 *
 * Keyed on a stored version rather than a boolean flag per removal. The
 * first of these (page traffic, 2.2.0) used its own am_traffic_cleanup_done
 * flag; the second (2.2.1) would have meant a second flag, and every
 * removal after that another one -- a row of dead bookkeeping accumulating
 * to track the removal of dead bookkeeping. A single am_cleanup_version
 * gives each future removal an obvious home: add a block, guard it with
 * the version that dropped the feature.
 *
 * Every step must be idempotent, because the boolean-to-version switchover
 * re-runs the 2.2.0 block once on sites that already did it. DROP TABLE IF
 * EXISTS, delete_option(), and the wp_next_scheduled() guard all are, so
 * that second pass is a no-op rather than an error.
 *
 * Normal loads cost one option read and stop at the first compare.
 */
function am_run_upgrade_cleanup() {
	$done = (string) get_option( 'am_cleanup_version', '0' );
	if ( version_compare( $done, AM_VERSION, '>=' ) ) {
		return;
	}

	global $wpdb;

	// 2.2.0 -- page traffic removed entirely.
	//
	// The tables go rather than being left in place. That's a deliberate
	// departure from how the v1.x am_activity_log table was handled (kept
	// until an explicit admin action, see AM_Schema's class doc): that
	// table held data being migrated into the schema that replaced it, so
	// keeping it cost nothing and losing it would have been unrecoverable.
	// Page-view data has no successor -- nothing will ever read it again --
	// so the choice was between deleting it on upgrade and leaving two
	// orphan tables on every site forever. Destructive and irreversible for
	// anyone relying on that history, which is why the 2.2.0 changelog says
	// so outright instead of letting it happen quietly.
	if ( version_compare( $done, '2.2.0', '<' ) ) {
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

		// Would otherwise stay scheduled forever against a hook that is no
		// longer registered anywhere.
		$am_rollup_timestamp = wp_next_scheduled( 'am_traffic_rollup' );
		if ( $am_rollup_timestamp ) {
			wp_unschedule_event( $am_rollup_timestamp, 'am_traffic_rollup' );
		}
	}

	// 2.2.1 -- the "Active session threshold" setting was removed. It was
	// saved but never read, so nothing depended on the value; this just
	// stops the row sitting in wp_options, where it would otherwise be
	// autoloaded on every request until uninstall.
	if ( version_compare( $done, '2.2.1', '<' ) ) {
		delete_option( 'am_session_active_threshold_minutes' );
	}

	// 2.2.2 -- retires the per-removal boolean this function used to use.
	if ( version_compare( $done, '2.2.2', '<' ) ) {
		delete_option( 'am_traffic_cleanup_done' );
	}

	// 2.4.0 -- session management removed. Only the setting is dropped:
	// sessions themselves live in WordPress's own session_tokens user
	// meta, which this plugin never owned and must not touch on the way
	// out. Deleting those would log every user on the site out.
	if ( version_compare( $done, '2.4.0', '<' ) ) {
		delete_option( 'am_session_concurrent_limit' );
	}

	// 2.4.11 -- the per-logger Event Sources toggle removed. Every logger
	// runs unconditionally now; a stale disabled-list would otherwise sit
	// in wp_options forever with nothing left to read it.
	if ( version_compare( $done, '2.4.11', '<' ) ) {
		delete_option( 'am_disabled_loggers' );
	}

	// 2.5.0 -- email digest removed entirely (AM_Digest, its settings UI,
	// and the am_send_digest cron tick). Options and the scheduled event
	// would otherwise sit around forever with nothing left to read or fire
	// them.
	if ( version_compare( $done, '2.5.0', '<' ) ) {
		foreach ( array(
			'am_digest_configs',
			'am_digest_frequency',
			'am_digest_day_of_week',
			'am_digest_recipients',
			'am_digest_last_sent',
		) as $am_digest_option ) {
			delete_option( $am_digest_option );
		}

		$am_digest_timestamp = wp_next_scheduled( 'am_send_digest' );
		if ( $am_digest_timestamp ) {
			wp_unschedule_event( $am_digest_timestamp, 'am_send_digest' );
		}
	}

	update_option( 'am_cleanup_version', AM_VERSION );
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

// ── Stats retention cron ─────────────────────────────────────────────────
// Separate cron/option/prune from the audit log's: high-volume, low
// forensic-value data doesn't belong on the same retention clock.
function am_schedule_stats_prune() {
	if ( ! wp_next_scheduled( 'am_stats_prune' ) ) {
		wp_schedule_event( time(), 'daily', 'am_stats_prune' );
	}
}
add_action( 'wp', 'am_schedule_stats_prune' );

function am_run_stats_prune() {
	AM_Stats_Schema::prune( absint( get_option( 'am_stats_retention_days', 90 ) ) );
}
add_action( 'am_stats_prune', 'am_run_stats_prune' );

// ── Geolocation update check ─────────────────────────────────────────────
// Daily HEAD-only check, not a full download -- see AM_Stats_Geo_Updater's
// class doc for the GeoLite free-account download quota this is designed
// around. The actual import (AM_Stats_Geo_Updater::TICK_HOOK) schedules
// itself chunk by chunk and isn't a recurring event.
function am_schedule_stats_geo_check() {
	if ( ! wp_next_scheduled( AM_Stats_Geo_Updater::CHECK_HOOK ) ) {
		wp_schedule_event( time(), 'daily', AM_Stats_Geo_Updater::CHECK_HOOK );
	}
}
add_action( 'wp', 'am_schedule_stats_geo_check' );
