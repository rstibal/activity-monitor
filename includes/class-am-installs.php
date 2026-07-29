<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Installs — data access for the am_installs table (Active Installs hub
 * feature). Plain $wpdb helper, same shape as AM_Traffic_Query.
 */
class AM_Installs {

	/**
	 * Insert or update a site's row, keyed on site_url_hash. $data must
	 * contain site_url, plugin_version, wp_version, php_version.
	 */
	public static function upsert( array $data ) {
		global $wpdb;
		$table = $wpdb->prefix . AM_Installs_Schema::TABLE;

		$site_url = (string) ( $data['site_url'] ?? '' );
		if ( '' === $site_url ) {
			return;
		}

		$hash = md5( $site_url );
		$now  = current_time( 'mysql', true );

		$wpdb->query( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
			"INSERT INTO `{$table}` (site_url, site_url_hash, plugin_version, wp_version, php_version, last_checkin)
			 VALUES (%s, %s, %s, %s, %s, %s)
			 ON DUPLICATE KEY UPDATE
			   site_url       = VALUES(site_url),
			   plugin_version = VALUES(plugin_version),
			   wp_version     = VALUES(wp_version),
			   php_version    = VALUES(php_version),
			   last_checkin   = VALUES(last_checkin)",
			$site_url,
			$hash,
			(string) ( $data['plugin_version'] ?? '' ),
			(string) ( $data['wp_version'] ?? '' ),
			(string) ( $data['php_version'] ?? '' ),
			$now
		) );
	}

	/** All known installs, most recently checked-in first. */
	public static function get_all() {
		global $wpdb;
		$table = $wpdb->prefix . AM_Installs_Schema::TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
		return $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY last_checkin DESC" );
	}

	/**
	 * Registers this site's own row, so a hub always sees itself in its
	 * own Active Installs list without a self-referential HTTP round trip.
	 */
	public static function self_register() {
		self::upsert( array(
			'site_url'       => home_url(),
			'plugin_version' => AM_VERSION,
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
		) );
	}
}
