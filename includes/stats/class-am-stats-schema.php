<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Stats_Schema — database layer for visitor/traffic stats.
 *
 * A subsystem deliberately kept separate from AM_Schema/am_events. Page
 * traffic was removed from this plugin entirely in 2.2.0, and CLAUDE.md is
 * explicit that if it ever comes back it must not become data inside the
 * audit log -- different volume, different question being answered
 * ("how much and from where" vs "who did what"), different retention needs.
 * Three tables, own DB version option, own prune(), own uninstall() -- never
 * touches am_events / am_event_context.
 */
class AM_Stats_Schema {

	const DB_VERSION_OPTION = 'am_stats_db_version';
	const CURRENT_VERSION   = '1.1.0';

	const URLS_TABLE        = 'am_stats_urls';
	const HITS_TABLE        = 'am_stats_hits';
	const VISITORS_TABLE    = 'am_stats_visitors';
	const GEO_RANGES_TABLE  = 'am_stats_geo_ranges';

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

		$urls_table     = $wpdb->prefix . self::URLS_TABLE;
		$hits_table     = $wpdb->prefix . self::HITS_TABLE;
		$visitors_table = $wpdb->prefix . self::VISITORS_TABLE;

		$sql_urls = "CREATE TABLE {$urls_table} (
			id       BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			url_hash CHAR(32)             NOT NULL,
			url      VARCHAR(255)         NOT NULL DEFAULT '',
			title    VARCHAR(255)         NOT NULL DEFAULT '',
			PRIMARY KEY (id),
			UNIQUE KEY ix_hash (url_hash)
		) {$charset};";

		$sql_hits = "CREATE TABLE {$hits_table} (
			id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			date          DATETIME             NOT NULL,
			visitor_hash  CHAR(32)             NOT NULL,
			url_id        BIGINT(20) UNSIGNED  NOT NULL,
			referrer_host VARCHAR(255)         NOT NULL DEFAULT '',
			browser       VARCHAR(40)          NOT NULL DEFAULT '',
			os            VARCHAR(40)          NOT NULL DEFAULT '',
			device_type   VARCHAR(20)          NOT NULL DEFAULT '',
			country_code  CHAR(2)              NOT NULL DEFAULT '',
			user_id       BIGINT(20) UNSIGNED  NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY ix_date (date),
			KEY ix_url_date (url_id, date),
			KEY ix_visitor (visitor_hash, date)
		) {$charset};";

		$sql_visitors = "CREATE TABLE {$visitors_table} (
			visitor_hash CHAR(32)         NOT NULL,
			first_seen   DATETIME         NOT NULL,
			last_seen    DATETIME         NOT NULL,
			visit_count  INT(10) UNSIGNED NOT NULL DEFAULT 1,
			PRIMARY KEY (visitor_hash)
		) {$charset};";

		// Built and swapped by AM_Stats_Geo_Updater, not written here directly
		// -- this just guarantees the table exists (empty) so AM_Stats_Geo's
		// lookup query never has to special-case a missing table before the
		// first import has run.
		$geo_ranges_table = $wpdb->prefix . self::GEO_RANGES_TABLE;
		$sql_geo_ranges   = "CREATE TABLE {$geo_ranges_table} (
			id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			ip_version   TINYINT(3) UNSIGNED  NOT NULL,
			start_ip     VARBINARY(16)        NOT NULL,
			end_ip       VARBINARY(16)        NOT NULL,
			country_code CHAR(2)              NOT NULL DEFAULT '',
			PRIMARY KEY (id),
			KEY ix_range (ip_version, start_ip)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_urls );
		dbDelta( $sql_hits );
		dbDelta( $sql_visitors );
		dbDelta( $sql_geo_ranges );
	}

	/**
	 * Retention pruning. am_stats_urls is deliberately left alone here --
	 * it's bounded by distinct URL count, not visit volume, so it doesn't
	 * grow the way the hit log does and needs no pruning of its own.
	 */
	public static function prune( int $retention_days ) {
		global $wpdb;
		if ( $retention_days <= 0 ) {
			return; // 0/negative = "never".
		}

		$hits_table     = $wpdb->prefix . self::HITS_TABLE;
		$visitors_table = $wpdb->prefix . self::VISITORS_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are plugin constants.
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM `{$hits_table}` WHERE date < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
			$retention_days
		) );

		$wpdb->query( $wpdb->prepare(
			"DELETE FROM `{$visitors_table}` WHERE last_seen < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
			$retention_days
		) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Full removal — called from uninstall.php only. */
	public static function uninstall() {
		global $wpdb;
		$tables = array(
			self::URLS_TABLE,
			self::HITS_TABLE,
			self::VISITORS_TABLE,
			self::GEO_RANGES_TABLE,
			// Staging/backup tables from an import that never finished
			// swapping -- see AM_Stats_Geo_Updater::run_import(). IF EXISTS
			// makes these no-ops on the (normal) case where no import was
			// interrupted mid-swap.
			self::GEO_RANGES_TABLE . '_staging',
			self::GEO_RANGES_TABLE . '_old',
		);
		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
			$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$table}`" );
		}
		delete_option( self::DB_VERSION_OPTION );
	}
}
