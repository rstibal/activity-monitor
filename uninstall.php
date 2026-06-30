<?php
// Runs only when the plugin is deleted via the WP admin (not deactivated)
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop the log table
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'am_activity_log' );

// Remove all plugin options
delete_option( 'am_db_version' );
delete_option( 'am_settings' );
delete_option( 'am_notification_channels' );

// Clear scheduled cron
wp_clear_scheduled_hook( 'am_log_prune' );
