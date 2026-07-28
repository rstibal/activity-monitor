<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Traffic_Schema — database layer for page traffic logging.
 *
 * Deliberately separate from AM_Schema (the audit-log schema): traffic
 * data has a different write volume (every front-end page view, vs.
 * occasional admin actions), a different retention need (raw hits are
 * typically only useful for a short window; the daily rollup is what's
 * worth keeping long-term), and a different query shape (aggregate
 * "views over time" / "top pages", not per-row filtering). Keeping this
 * versioned independently means a future change to the traffic tables
 * doesn't force a re-run of the audit-log migration path in AM_Schema,
 * and vice versa.
 *
 *   am_traffic_log    - one row per page view (raw hit).
 *   am_traffic_daily  - one row per (date, url) pair, incremented as
 *                       raw hits are rolled up. This is what the
 *                       Traffic tab's charts/tables read from, so
 *                       "top pages" and "views per day" don't require
 *                       scanning the full raw log.
 */
class AM_Traffic_Schema {

	const DB_VERSION_OPTION = 'am_traffic_db_version';
	const CURRENT_VERSION   = '1.1.0';

	const LOG_TABLE   = 'am_traffic_log';
	const DAILY_TABLE = 'am_traffic_daily';

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

		$log_table   = $wpdb->prefix . self::LOG_TABLE;
		$daily_table = $wpdb->prefix . self::DAILY_TABLE;

		// url_hash (md5 of the normalized path) keeps the daily rollup's
		// unique key short and indexable regardless of actual URL length
		// -- VARCHAR(2000) can't be a MySQL key on its own. page_title
		// (1.1.0) is the document title as WordPress itself resolved it
		// at the moment of the visit (wp_get_document_title(), called
		// from AM_Traffic::capture() on template_redirect) -- captured
		// live per hit rather than looked up afterward from the URL,
		// since a later lookup can only ever reconstruct a post's raw
		// title (get_the_title()), not whatever the page actually
		// displayed to the visitor at the time.
		$sql_log = "CREATE TABLE {$log_table} (
			id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			date          DATETIME             NOT NULL,
			url           VARCHAR(2000)        NOT NULL,
			url_hash      CHAR(32)             NOT NULL,
			page_title    VARCHAR(500)         NOT NULL DEFAULT '',
			referrer      VARCHAR(2000)        NOT NULL DEFAULT '',
			ip_address    VARCHAR(45)          NOT NULL DEFAULT '',
			user_agent    VARCHAR(255)         NOT NULL DEFAULT '',
			user_id       BIGINT(20) UNSIGNED  NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY ix_date     (date),
			KEY ix_url_hash (url_hash)
		) {$charset};";

		$sql_daily = "CREATE TABLE {$daily_table} (
			id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			date         DATE                 NOT NULL,
			url          VARCHAR(2000)        NOT NULL,
			url_hash     CHAR(32)             NOT NULL,
			page_title   VARCHAR(500)         NOT NULL DEFAULT '',
			views        INT(10) UNSIGNED     NOT NULL DEFAULT 0,
			unique_ips   INT(10) UNSIGNED     NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			UNIQUE KEY ix_date_url (date, url_hash),
			KEY ix_date            (date)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_log );
		dbDelta( $sql_daily );
	}

	/**
	 * Delete raw hit rows older than $retention_days. Mirrors
	 * AM_Schema::prune() -- 0/negative means "never prune". The daily
	 * rollup table is NOT pruned here; it's small (one row per URL per
	 * day) and is the long-term record once raw hits age out. If it
	 * ever needs its own retention, that's a separate setting.
	 */
	public static function prune_log( int $retention_days ) {
		global $wpdb;
		if ( $retention_days <= 0 ) {
			return;
		}

		$log_table = $wpdb->prefix . self::LOG_TABLE;

		$wpdb->query( $wpdb->prepare(
			"DELETE FROM `{$log_table}` WHERE date < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
			$retention_days
		) );
	}
}
