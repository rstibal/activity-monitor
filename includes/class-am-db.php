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

	// ── Queries ──────────────────────────────────────────────────────────

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
		$order   = in_array( strtoupper( $args['order'] ), $allowed_order, true ) ? strtoupper( $args['order'] ) : 'DESC';
		$orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';

		$where_sql = implode( ' AND ', $where );
		// Table name and column names are constants/whitelisted – safe to interpolate.
		$count_sql = "SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}";
		$data_sql  = "SELECT * FROM `{$table}` WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

		if ( ! empty( $values ) ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $values ) );
			$items = $wpdb->get_results( $wpdb->prepare( $data_sql, array_merge( $values, array( absint( $args['per_page'] ), $offset ) ) ) );
		} else {
			$total = (int) $wpdb->get_var( $count_sql );
			$items = $wpdb->get_results( $wpdb->prepare( $data_sql, absint( $args['per_page'] ), $offset ) );
		}

		return compact( 'items', 'total' );
	}

	/** FIX #4: Table name is a plugin constant – no user input reaches this query. */
	public static function get_event_types() {
		global $wpdb;
		$table = $wpdb->prefix . AM_TABLE;
		return $wpdb->get_col( "SELECT DISTINCT event_type FROM `{$table}` ORDER BY event_type ASC" );
	}

	public static function clear_all() {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . AM_TABLE );
	}

	public static function delete( int $id ) {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . AM_TABLE, array( 'id' => $id ), array( '%d' ) );
	}

	// ── IP helpers ──────────────────────────────────────────────────────

	/**
	 * FIX #1: Hardened IP resolution.
	 *
	 * Trust HTTP_CF_CONNECTING_IP only when REMOTE_ADDR is a verified
	 * Cloudflare edge node. This prevents log poisoning when the origin
	 * is accessed directly (Cloudflare bypassed).
	 * HTTP_X_FORWARDED_FOR is intentionally NOT trusted.
	 *
	 * Cloudflare IP ranges: https://www.cloudflare.com/ips/ (2025-06)
	 * Refresh periodically or hook into the CF /ips-v4 + /ips-v6 API.
	 */
	public static function get_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore

		if (
			! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && // phpcs:ignore
			self::is_cloudflare_ip( $remote )
		) {
			$ip = trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) ); // phpcs:ignore
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}

		// REMOTE_ADDR is the TCP-level address – cannot be spoofed.
		$ip = trim( sanitize_text_field( wp_unslash( $remote ) ) );
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return $ip;
		}

		return '0.0.0.0';
	}

	private static function is_cloudflare_ip( string $ip ): bool {
		if ( empty( $ip ) ) return false;
		static $cf_ranges = array(
			'103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
			'104.16.0.0/13',   '104.24.0.0/14',   '108.162.192.0/18',
			'131.0.72.0/22',   '141.101.64.0/18',  '162.158.0.0/15',
			'172.64.0.0/13',   '173.245.48.0/20',  '188.114.96.0/20',
			'190.93.240.0/20', '197.234.240.0/22', '198.41.128.0/17',
			'2400:cb00::/32',  '2405:8100::/32',   '2405:b500::/32',
			'2606:4700::/32',  '2803:f800::/32',   '2a06:98c0::/29',
			'2c0f:f248::/32',
		);
		foreach ( $cf_ranges as $cidr ) {
			if ( self::ip_in_cidr( $ip, $cidr ) ) return true;
		}
		return false;
	}

	private static function ip_in_cidr( string $ip, string $cidr ): bool {
		list( $subnet, $bits ) = explode( '/', $cidr );
		$bits = (int) $bits;

		// IPv6
		if ( strpos( $subnet, ':' ) !== false ) {
			if ( strpos( $ip, ':' ) === false ) return false;
			$ip_bin  = inet_pton( $ip );
			$net_bin = inet_pton( $subnet );
			if ( false === $ip_bin || false === $net_bin ) return false;
			$fb = $bits >> 3;
			$rb = $bits & 7;
			if ( substr( $ip_bin, 0, $fb ) !== substr( $net_bin, 0, $fb ) ) return false;
			if ( $rb ) {
				$mask = 0xFF & ( 0xFF << ( 8 - $rb ) );
				if ( ( ord( $ip_bin[ $fb ] ) & $mask ) !== ( ord( $net_bin[ $fb ] ) & $mask ) ) return false;
			}
			return true;
		}

		// IPv4
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) return false;
		$mask = $bits ? ( ~0 << ( 32 - $bits ) ) : 0;
		return ( ip2long( $ip ) & $mask ) === ( ip2long( $subnet ) & $mask );
	}
}
