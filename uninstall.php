<?php
/**
 * Activity Monitor – Uninstall
 *
 * FIX #9: Fires when the plugin is deleted via Plugins > Delete (not on deactivation).
 * Removes the log table and all plugin options so no data or credentials
 * (Slack webhook URLs) are left behind.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop the log table.
$table = $wpdb->prefix . 'am_activity_log';
$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore

// Remove plugin options.
delete_option( 'am_db_version' );
delete_option( 'am_notification_channels' );
delete_option( 'am_retention_days' );

// Clear the scheduled cron event.
$timestamp = wp_next_scheduled( 'am_log_prune' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'am_log_prune' );
}
