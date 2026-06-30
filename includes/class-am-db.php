<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AM_DB {

	/** Create or upgrade the log table */
	public static function install() {
		global $wpdb;
		$table   = $wpdb->prefix . AM_TABLE;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at   DATETIME            NOT NULL,
			severity     TINYINT(1)          NOT NULL DEFAULT 2,
			event_type   VARCHAR(60)         NOT NULL DEFAULT '',
			object_type  VARCHAR(60)         NOT NULL DEFAULT '',
			object_id    BIGINT(20)          NOT NULL DEFAULT 0,
			object_name  VARCHAR(255)        NOT NULL DEFAULT '',
			user_id      BIGINT(20)          NOT NULL DEFAULT 0,
			user_name    VARCHAR(100)        NOT NULL DEFAULT '',
			user_role    VARCHAR(100)        NOT NULL DEFAULT '',
			ip_address   VARCHAR(45)         NOT NULL DEFAULT '',
			message      TEXT                NOT NULL,
			meta         LONGTEXT                     DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY          ix_severity   (severity),
			KEY          ix_event_type (event_type),
			KEY          ix_created_at (created_at),
			KEY          ix_user_id    (user_id)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		add_option( 'am_db_version', AM_VERSION );
	}

	public static function deactivate() {
		// Intentionally keep data on deactivation; only remove on uninstall.
	}

	// ── Queries ──────────────────────────────────────────────────────────────

	/**
	 * Insert a log row.
	 *
	 * @param array $data {
	 *   severity, event_type, object_type, object_id, object_name,
	 *   user_id, user_name, user_role, ip_address, message, meta
	 * }
	 */
	public static function insert( array $data ) {
		global $wpdb;
		$defaults = array(
			'created_at'  => current_time( 'mysql', true ),
			'severity'    => 2,
			'event_type'  => '',
			'object_type' => '',
			'object_id'   => 0,
			'object_name' => '',
			'user_id'     => 0,
			'user_name'   => '',
			'user_role'   => '',
			'ip_address'  => self::get_ip(),
			'message'     => '',
			'meta'        => null,
		);

		$row = wp_parse_args( $data, $defaults );

		if ( is_array( $row['meta'] ) ) {
			$row['meta'] = wp_json_encode( $row['meta'] );
		}

		$wpdb->insert( $wpdb->prefix . AM_TABLE, $row );
		return $wpdb->insert_id;
	}

	/**
	 * Fetch log entries.
	 *
	 * @param array $args {
	 *   per_page, page, severity, event_type, search, orderby, order
	 * }
	 * @return array { items, total }
	 */
	public static function get_events( array $args = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . AM_TABLE;

		$defaults = array(
			'per_page'   => 50,
			'page'       => 1,
			'severity'   => '',
			'event_type' => '',
			'search'     => '',
			'orderby'    => 'created_at',
			'order'      => 'DESC',
		);
		$args   = wp_parse_args( $args, $defaults );
		$offset = ( absint( $args['page'] ) - 1 ) * absint( $args['per_page'] );

		$where  = array( '1=1' );
		$values = array();

		if ( '' !== $args['severity'] ) {
			$where[]  = 'severity = %d';
			$values[] = absint( $args['severity'] );
		}

		if ( '' !== $args['event_type'] ) {
			$where[]  = 'event_type = %s';
			$values[] = sanitize_key( $args['event_type'] );
		}

		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '( message LIKE %s OR user_name LIKE %s OR object_name LIKE %s )';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}

		$allowed_order   = array( 'ASC', 'DESC' );
		$allowed_orderby = array( 'created_at', 'severity', 'event_type', 'user_name', 'id' );
		$order   = in_array( strtoupper( $args['order'] ), $allowed_order, true )   ? strtoupper( $args['order'] )   : 'DESC';
		$orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';

		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$data_sql  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

		if ( ! empty( $values ) ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $values ) );
			$items = $wpdb->get_results( $wpdb->prepare( $data_sql, array_merge( $values, array( absint( $args['per_page'] ), $offset ) ) ) );
		} else {
			$total = (int) $wpdb->get_var( $count_sql );
			$items = $wpdb->get_results( $wpdb->prepare( $data_sql, absint( $args['per_page'] ), $offset ) );
		}

		return compact( 'items', 'total' );
	}

	/** Return distinct event_type values for the filter dropdown */
	public static function get_event_types() {
		global $wpdb;
		$table = $wpdb->prefix . AM_TABLE;
		return $wpdb->get_col( "SELECT DISTINCT event_type FROM {$table} ORDER BY event_type ASC" );
	}

	/** Delete all log entries */
	public static function clear_all() {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . AM_TABLE );
	}

	/** Delete a single entry */
	public static function delete( int $id ) {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . AM_TABLE, array( 'id' => $id ), array( '%d' ) );
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	public static function get_ip() {
		$keys = array(
			'HTTP_CF_CONNECTING_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		);
		foreach ( $keys as $k ) {
			if ( ! empty( $_SERVER[ $k ] ) ) {
				$ip = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $k ] ) ) )[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '0.0.0.0';
	}
}
