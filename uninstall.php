<?php
/**
 * Activity Monitor – Uninstall
 *
 * Fires when the plugin is deleted via Plugins > Delete, never on
 * deactivation. Removes the log tables and all plugin options so no data
 * is left behind -- unless the admin has opted out, see below.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Settings > When the plugin is deleted. On by default, so a site that has
// never opened the Settings screen behaves exactly as every version before
// 2.4.3 did. Unticked, this file does nothing at all: the tables, the
// options (including this one, so the choice survives), and the cron
// events are all left in place, and reinstalling picks the log back up
// where it left off.
if ( ! get_option( 'am_delete_data_on_uninstall', 1 ) ) {
	return;
}

global $wpdb;

// Drops am_events / am_event_context, plus the legacy am_activity_log
// table if it's still present. See AM_Schema::uninstall().
require_once __DIR__ . '/includes/schema/class-am-schema.php';
AM_Schema::uninstall();

// Active Installs hub feature was removed in 2.0.81; the table itself has
// no surviving class, so it's dropped here directly rather than via
// AM_Installs_Schema (same precedent as AM_Schema's legacy v1 table drop).
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}am_installs`" );
delete_option( 'am_installs_db_version' );

// Visitor/traffic stats subsystem (added 2.6.0). Kept in its own tables,
// never merged into am_events -- see AM_Stats_Schema's class doc.
require_once __DIR__ . '/includes/stats/class-am-stats-schema.php';
AM_Stats_Schema::uninstall();
delete_option( 'am_stats_enable_tracking' );
delete_option( 'am_stats_exclude_roles' );
delete_option( 'am_stats_retention_days' );

// Geolocation (added on top of Visitor Stats). am_stats_geo_ranges itself
// is already covered by AM_Stats_Schema::uninstall() above.
delete_option( 'am_stats_geo_account_id' );
delete_option( 'am_stats_geo_license_key' );
delete_option( 'am_stats_geo_enabled' );
delete_option( 'am_stats_geo_last_modified' );
delete_option( 'am_stats_geo_last_updated' );
delete_option( 'am_stats_geo_last_manual_trigger' );
delete_option( 'am_stats_geo_import_progress' );
delete_option( 'am_stats_geo_locations_map' );

// Page traffic was removed in 2.2.0, which drops these on upgrade via
// am_run_upgrade_cleanup(). Repeated here because uninstall must not
// assume that ran: a site deleting the plugin without ever loading the
// 2.2.0 bootstrap (or one where the cleanup bookkeeping was cleared)
// would otherwise leave both tables behind. Dropped directly rather than via
// AM_Traffic_Schema, whose class was removed with the feature -- same
// precedent as am_installs above. IF EXISTS makes this a no-op once the
// upgrade path has already run.
foreach ( array( 'am_traffic_log', 'am_traffic_daily' ) as $am_traffic_table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
	$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$am_traffic_table}`" );
}

// Remaining plugin options not covered by AM_Schema::uninstall().
delete_option( 'am_notification_channels' );
delete_option( 'am_retention_days' );
delete_option( 'am_disabled_loggers' );
delete_option( 'am_occasion_window_seconds' );
delete_option( 'am_datetime_format' );
delete_option( 'am_ip_storage' );
delete_option( 'am_ip_lookup_enabled' );
delete_option( 'am_delete_data_on_uninstall' );
delete_option( 'am_maintenance_mode_last_state' );
// Activity Log rows-per-page choice (2.8.20) and the Ledger Console theme
// toggle (2.9.0), both stored per user rather than as a plugin option --
// see AM_Admin::log_per_page() and AM_Admin::user_theme(). Unlike
// session_tokens below, these meta keys belong to the plugin, so they're
// removed for every user, not left in place.
delete_metadata( 'user', 0, 'am_log_per_page', '', true );
delete_metadata( 'user', 0, 'am_theme', '', true );

// Session management was removed in 2.4.0; these are its two leftover
// settings. Note what is deliberately NOT here: the session_tokens user
// meta. That is WordPress's own storage, which this plugin only ever read
// -- deleting it on uninstall would log out every user on the site.
delete_option( 'am_session_concurrent_limit' );
delete_option( 'am_session_active_threshold_minutes' );
delete_option( 'am_digest_configs' );
delete_option( 'am_digest_frequency' );
delete_option( 'am_digest_day_of_week' );
delete_option( 'am_digest_recipients' );
delete_option( 'am_digest_last_sent' );
delete_option( 'am_traffic_enabled' );
delete_option( 'am_traffic_retention_days' );
delete_option( 'am_traffic_live_poll_seconds' );
delete_option( 'am_traffic_live_feed_limit' );
delete_option( 'am_traffic_db_version' );
// Bookkeeping for am_run_upgrade_cleanup(). am_traffic_cleanup_done is the
// boolean it used before 2.2.2; still deleted here for anyone uninstalling
// from a version that predates the switch to a stored version number.
delete_option( 'am_cleanup_version' );
delete_option( 'am_traffic_cleanup_done' );

// Clear scheduled cron events.
$timestamp = wp_next_scheduled( 'am_log_prune' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'am_log_prune' );
}
$digest_timestamp = wp_next_scheduled( 'am_send_digest' );
if ( $digest_timestamp ) {
	wp_unschedule_event( $digest_timestamp, 'am_send_digest' );
}
$hub_checkin_timestamp = wp_next_scheduled( 'am_hub_checkin' );
if ( $hub_checkin_timestamp ) {
	wp_unschedule_event( $hub_checkin_timestamp, 'am_hub_checkin' );
}
$traffic_rollup_timestamp = wp_next_scheduled( 'am_traffic_rollup' );
if ( $traffic_rollup_timestamp ) {
	wp_unschedule_event( $traffic_rollup_timestamp, 'am_traffic_rollup' );
}
$stats_prune_timestamp = wp_next_scheduled( 'am_stats_prune' );
if ( $stats_prune_timestamp ) {
	wp_unschedule_event( $stats_prune_timestamp, 'am_stats_prune' );
}
$geo_check_timestamp = wp_next_scheduled( 'am_stats_geo_check' );
if ( $geo_check_timestamp ) {
	wp_unschedule_event( $geo_check_timestamp, 'am_stats_geo_check' );
}
$geo_tick_timestamp = wp_next_scheduled( 'am_stats_geo_import_tick' );
if ( $geo_tick_timestamp ) {
	wp_unschedule_event( $geo_tick_timestamp, 'am_stats_geo_import_tick' );
}
