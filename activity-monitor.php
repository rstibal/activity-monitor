<?php
/**
 * Plugin Name: Activity Monitor
 * Plugin URI:  https://robstibal.com
 * Description: Comprehensive WordPress audit log — tracks logins, content changes, settings updates, security events, and more.
 * Version:     1.1.6
 * Author:      Rob Stibal
 * Author URI:  http://robstibal.com
 * License:     GPL-2.0+
 * Text Domain: activity-monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AM_VERSION',     '1.1.6' );
define( 'AM_FILE',        __FILE__ );
define( 'AM_DIR',         plugin_dir_path( __FILE__ ) );
define( 'AM_URL',         plugin_dir_url( __FILE__ ) );
define( 'AM_TABLE',       'am_activity_log' );

// ── Autoload core files ──────────────────────────────────────────────────────
require_once AM_DIR . 'includes/class-am-db.php';
require_once AM_DIR . 'includes/class-am-logger.php';
require_once AM_DIR . 'includes/class-am-hooks.php';
require_once AM_DIR . 'includes/class-am-notifications.php';
require_once AM_DIR . 'admin/class-am-admin.php';

// ── Activation / deactivation ────────────────────────────────────────────────
register_activation_hook( AM_FILE,   array( 'AM_DB', 'install' ) );
register_deactivation_hook( AM_FILE, array( 'AM_DB', 'deactivate' ) );

// ── Bootstrap ────────────────────────────────────────────────────────────────
function am_init() {
	AM_Hooks::init();
	AM_Admin::init();
}
add_action( 'plugins_loaded', 'am_init' );

// ── Log retention cron ────────────────────────────────────────────────────────
function am_schedule_prune() {
	if ( ! wp_next_scheduled( 'am_log_prune' ) ) {
		wp_schedule_event( time(), 'daily', 'am_log_prune' );
	}
}
add_action( 'wp', 'am_schedule_prune' );

function am_run_prune() {
	global $wpdb;
	$settings = get_option( 'am_settings', array( 'retention_days' => 90 ) );
	$days     = absint( $settings['retention_days'] ?? 90 );
	if ( $days < 1 ) return;
	$wpdb->query( $wpdb->prepare(
		'DELETE FROM ' . $wpdb->prefix . AM_TABLE . ' WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)',
		$days
	) );
}
add_action( 'am_log_prune', 'am_run_prune' );
