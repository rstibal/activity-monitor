<?php
/**
 * Activity Monitor – Uninstall
 *
 * FIX #9: Fires when the plugin is deleted via Plugins > Delete (not on deactivation).
 * Removes the log tables and all plugin options so no data is left behind.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// v2.0: drop am_events / am_event_context, plus the legacy am_activity_log
// table if it's still present, and all v1.x + v2.0 options.
// See includes/schema/class-am-schema.php AM_Schema::uninstall() and
// activity-monitor-v2-spec.md §7 (public-release uninstall bar).
require_once __DIR__ . '/includes/schema/class-am-schema.php';
AM_Schema::uninstall();

// Remaining plugin options not covered by AM_Schema::uninstall().
delete_option( 'am_notification_channels' );
delete_option( 'am_retention_days' );
delete_option( 'am_disabled_loggers' );
delete_option( 'am_session_concurrent_limit' );
delete_option( 'am_session_active_threshold_minutes' );
delete_option( 'am_digest_configs' );
delete_option( 'am_digest_frequency' );
delete_option( 'am_digest_day_of_week' );
delete_option( 'am_digest_recipients' );
delete_option( 'am_digest_last_sent' );

// Clear both scheduled cron events.
$timestamp = wp_next_scheduled( 'am_log_prune' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'am_log_prune' );
}
$digest_timestamp = wp_next_scheduled( 'am_send_digest' );
if ( $digest_timestamp ) {
	wp_unschedule_event( $digest_timestamp, 'am_send_digest' );
}
