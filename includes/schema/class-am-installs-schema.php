<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Installs_Schema — database layer for the Active Installs hub feature.
 *
 * Deliberately separate from AM_Schema and AM_Traffic_Schema, same
 * reasoning as the traffic schema: independent versioning means a future
 * change here never forces a migration replay of the audit-log or traffic
 * tables, and vice versa.
 *
 *   am_installs - one row per site that has checked in with this install
 *                 while it's acting as a hub, upserted by site URL.
 */
class AM_Installs_Schema {

	const DB_VERSION_OPTION = 'am_installs_db_version';
	const CURRENT_VERSION   = '1.0.0';

	const TABLE = 'am_installs';

	/** Registered on register_activation_hook(). */
	public static function install() {
		self::create_or_upgrade_tables();
		update_option( self::DB_VERSION_OPTION, self::CURRENT_VERSION );
	}

	/** Safe to call on every plugins_loaded -- dbDelta() is idempotent. */
	public static function maybe_upgrade() {
		$installed = get_option( self::DB_VERSION_OPTION, '0.0.0' );
		if ( version_compare( $installed, self::CURRENT_VERSION, '<' ) ) {
			self::install();
		}
	}

	private static function create_or_upgrade_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		$table = $wpdb->prefix . self::TABLE;

		// site_url_hash (md5 of the site URL) is the upsert key -- same
		// hash-key-for-upsert idiom as am_traffic_daily's (date, url_hash)
		// unique key, since VARCHAR(255) alone makes an awkward/oversized
		// MySQL key.
		$sql = "CREATE TABLE {$table} (
			id             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			site_url       VARCHAR(255)        NOT NULL,
			site_url_hash  CHAR(32)             NOT NULL,
			plugin_version VARCHAR(20)          NOT NULL DEFAULT '',
			wp_version     VARCHAR(20)          NOT NULL DEFAULT '',
			php_version    VARCHAR(20)          NOT NULL DEFAULT '',
			last_checkin   DATETIME             NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY ix_site_url_hash (site_url_hash)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/** Registered from uninstall.php. Drops the table and its version option. */
	public static function uninstall() {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		delete_option( self::DB_VERSION_OPTION );
	}
}
