<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Schema — v2.0 database layer.
 *
 * Replaces the single wide `am_activity_log` table from v1.x with two
 * normalized tables:
 *
 *   am_events         - one row per (grouped) event; the columns admins
 *                        actually filter/sort on live here, indexed.
 *   am_event_context  - arbitrary key/value pairs per event (diffs,
 *                        before/after values, extra metadata) so the
 *                        event row itself stays lean and queryable.
 *
 * Migration from v1.x is additive and non-destructive: the old
 * `am_activity_log` table is left in place after migration until the
 * admin explicitly confirms removal (see AM_Schema::drop_legacy_table()),
 * which is only ever called from an explicit admin action, never
 * automatically.
 *
 * See activity-monitor-v2-spec.md §2.
 */
class AM_Schema {

	const DB_VERSION_OPTION = 'am_db_version';
	const CURRENT_VERSION   = '2.0.0';

	const EVENTS_TABLE  = 'am_events';
	const CONTEXT_TABLE = 'am_event_context';

	/** Registered on register_activation_hook(). */
	public static function install() {
		self::create_or_upgrade_tables();
		self::maybe_migrate_from_v1();
		update_option( self::DB_VERSION_OPTION, self::CURRENT_VERSION );
	}

	/**
	 * Safe to call on every plugins_loaded — dbDelta() is idempotent and
	 * this only runs the (cheap) version check, not a table scan.
	 */
	public static function maybe_upgrade() {
		$installed = get_option( self::DB_VERSION_OPTION, '0.0.0' );
		if ( version_compare( $installed, self::CURRENT_VERSION, '<' ) ) {
			self::install();
		}
	}

	private static function create_or_upgrade_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		$events_table  = $wpdb->prefix . self::EVENTS_TABLE;
		$context_table = $wpdb->prefix . self::CONTEXT_TABLE;

		$sql_events = "CREATE TABLE {$events_table} (
			id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			date                DATETIME             NOT NULL,
			level               VARCHAR(20)          NOT NULL DEFAULT 'info',
			initiator           VARCHAR(20)          NOT NULL DEFAULT 'wp_user',
			user_id             BIGINT(20) UNSIGNED  NOT NULL DEFAULT 0,
			user_login          VARCHAR(60)          NOT NULL DEFAULT '',
			user_display_name   VARCHAR(250)         NOT NULL DEFAULT '',
			user_role           VARCHAR(100)         NOT NULL DEFAULT '',
			ip_address          VARCHAR(45)          NOT NULL DEFAULT '',
			event_type          VARCHAR(100)         NOT NULL DEFAULT '',
			action              VARCHAR(100)         NOT NULL DEFAULT '',
			object_type         VARCHAR(100)         NOT NULL DEFAULT '',
			object_id           BIGINT(20) UNSIGNED  NOT NULL DEFAULT 0,
			object_name         VARCHAR(250)         NOT NULL DEFAULT '',
			message             VARCHAR(255)         NOT NULL DEFAULT '',
			occasion_id         VARCHAR(32)          DEFAULT NULL,
			repeat_count        INT(10) UNSIGNED     NOT NULL DEFAULT 1,
			PRIMARY KEY (id),
			KEY ix_date        (date),
			KEY ix_level       (level),
			KEY ix_initiator   (initiator),
			KEY ix_event_type  (event_type),
			KEY ix_user_id     (user_id),
			KEY ix_occasion    (occasion_id, date)
		) {$charset};";

		$sql_context = "CREATE TABLE {$context_table} (
			context_id  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id    BIGINT(20) UNSIGNED NOT NULL,
			`key`       VARCHAR(255)        NOT NULL,
			value       LONGTEXT            DEFAULT NULL,
			PRIMARY KEY (context_id),
			KEY ix_event_id (event_id),
			KEY ix_key      (`key`(191))
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_events );
		dbDelta( $sql_context );
	}

	/**
	 * One-time backfill from the v1.x `{prefix}am_activity_log` table into
	 * the new schema. Runs once, guarded by an option flag. Never deletes
	 * or truncates the legacy table — see class doc.
	 */
	private static function maybe_migrate_from_v1() {
		if ( get_option( 'am_v1_migrated' ) ) {
			return;
		}

		global $wpdb;
		$legacy_table = $wpdb->prefix . 'am_activity_log';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy_table ) );
		if ( $exists !== $legacy_table ) {
			// Fresh install, nothing to migrate.
			update_option( 'am_v1_migrated', true );
			return;
		}

		$events_table = $wpdb->prefix . self::EVENTS_TABLE;
		$batch_size   = 500;
		$offset       = 0;

		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM `{$legacy_table}` ORDER BY id ASC LIMIT %d OFFSET %d",
				$batch_size,
				$offset
			), ARRAY_A );

			foreach ( $rows as $row ) {
				self::migrate_legacy_row( $row, $events_table );
			}

			$offset += $batch_size;
		} while ( count( $rows ) === $batch_size );

		update_option( 'am_v1_migrated', true );
	}

	private static function migrate_legacy_row( array $row, string $events_table ) {
		global $wpdb;

		$level_map = array(
			1 => 'info',
			2 => 'notice',
			3 => 'warning',
			4 => 'critical',
		);

		$wpdb->insert( $events_table, array(
			'date'              => $row['created_at'],
			'level'             => $level_map[ (int) $row['severity'] ] ?? 'notice',
			'initiator'         => 'wp_user', // v1.x didn't track this distinctly; safe default.
			'user_id'           => absint( $row['user_id'] ),
			'user_login'        => $row['user_name'],
			'user_display_name' => $row['user_name'],
			'user_role'         => $row['user_role'],
			'ip_address'        => $row['ip_address'],
			'event_type'        => $row['event_type'],
			'action'            => '', // v1.x folded action into event_type (e.g. "post.delete").
			'object_type'       => $row['object_type'],
			'object_id'         => absint( $row['object_id'] ),
			'object_name'       => $row['object_name'],
			'message'           => $row['message'],
			'repeat_count'      => 1,
		) );

		$new_id = $wpdb->insert_id;

		if ( ! empty( $row['meta'] ) && $new_id ) {
			$meta = json_decode( $row['meta'], true );
			if ( is_array( $meta ) ) {
				$context_table = $wpdb->prefix . self::CONTEXT_TABLE;
				foreach ( $meta as $key => $value ) {
					$wpdb->insert( $context_table, array(
						'event_id' => $new_id,
						'key'      => sanitize_key( (string) $key ),
						'value'    => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
					) );
				}
			}
		}
	}

	/**
	 * Explicit, admin-triggered removal of the v1.x legacy table.
	 * Never called automatically — wire this to a confirmed admin action only.
	 */
	public static function drop_legacy_table() {
		global $wpdb;
		$legacy_table = $wpdb->prefix . 'am_activity_log';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
		$wpdb->query( "DROP TABLE IF EXISTS `{$legacy_table}`" );
	}

	/** Full removal — called from uninstall.php only. */
	public static function uninstall() {
		global $wpdb;
		foreach ( array( self::EVENTS_TABLE, self::CONTEXT_TABLE ) as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
			$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$table}`" );
		}
		self::drop_legacy_table();
		delete_option( self::DB_VERSION_OPTION );
		delete_option( 'am_v1_migrated' );
	}

	/**
	 * Truncate both v2.0 tables. Used by the admin "Clear Entire Log"
	 * action -- added when the old AM_DB-backed "Activity Log" tab was
	 * retired in favor of this schema being the sole visible log, since
	 * the clear-log button previously only cleared the (now invisible)
	 * legacy am_activity_log table and left the actually-displayed data
	 * untouched.
	 */
	public static function clear_all() {
		global $wpdb;
		foreach ( array( self::EVENTS_TABLE, self::CONTEXT_TABLE ) as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
			$wpdb->query( "TRUNCATE TABLE `{$wpdb->prefix}{$table}`" );
		}
	}
}
