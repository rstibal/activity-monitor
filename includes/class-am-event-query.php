<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Event_Query — read-side query layer for the v2.0 schema
 * (am_events + am_event_context). This is the only query layer left in
 * the plugin -- the legacy AM_DB class it was originally kept separate
 * from has been fully retired, and the "Activity Log" admin tab reads
 * exclusively through this class now.
 *
 * This is the minimal query surface needed for the "New admin log screen"
 * (v2.0 build order item 5, moved earlier to make occasion grouping /
 * initiators / levels visible while the rest of the loggers are ported).
 */
class AM_Event_Query {

	public static function get_events( array $args = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . AM_Schema::EVENTS_TABLE;

		$defaults = array(
			'per_page'  => 50,
			'page'      => 1,
			'level'     => '',
			'initiator' => '',
			'event_type'=> '',
			'action'    => '',
			'user'      => '',   // user_login, exact match
			'date_from' => '',   // Y-m-d, inclusive, site-local interpreted as UTC start-of-day
			'date_to'   => '',   // Y-m-d, inclusive, site-local interpreted as UTC end-of-day
			'search'    => '',
			'orderby'   => 'date',
			'order'     => 'DESC',
			'no_limit'  => false, // export mode: ignore per_page/page, return every matching row
		);
		$args   = wp_parse_args( $args, $defaults );
		$offset = ( absint( $args['page'] ) - 1 ) * absint( $args['per_page'] );

		$where  = array( '1=1' );
		$values = array();

		if ( '' !== $args['level'] && AM_Log_Levels::is_valid( $args['level'] ) ) {
			$where[]  = 'level = %s';
			$values[] = $args['level'];
		}
		if ( '' !== $args['initiator'] && in_array( $args['initiator'], AM_Initiator_Detector::all(), true ) ) {
			$where[]  = 'initiator = %s';
			$values[] = $args['initiator'];
		}
		if ( '' !== $args['event_type'] ) {
			$where[]  = 'event_type = %s';
			$values[] = sanitize_key( $args['event_type'] );
		}
		if ( '' !== $args['action'] ) {
			$where[]  = 'action = %s';
			$values[] = sanitize_key( $args['action'] );
		}
		if ( '' !== $args['user'] ) {
			$where[]  = 'user_login = %s';
			$values[] = sanitize_user( $args['user'] );
		}
		if ( '' !== $args['date_from'] && false !== strtotime( $args['date_from'] ) ) {
			$where[]  = 'date >= %s';
			$values[] = gmdate( 'Y-m-d 00:00:00', strtotime( $args['date_from'] ) );
		}
		if ( '' !== $args['date_to'] && false !== strtotime( $args['date_to'] ) ) {
			$where[]  = 'date <= %s';
			$values[] = gmdate( 'Y-m-d 23:59:59', strtotime( $args['date_to'] ) );
		}
		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '( message LIKE %s OR user_login LIKE %s OR object_name LIKE %s )';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}

		$allowed_order   = array( 'ASC', 'DESC' );
		$allowed_orderby = array( 'date', 'level', 'event_type', 'user_login', 'id' );
		$order   = in_array( strtoupper( $args['order'] ), $allowed_order, true ) ? strtoupper( $args['order'] ) : 'DESC';
		$orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'date';

		$where_sql = implode( ' AND ', $where );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/column names are plugin constants.
		$count_sql = "SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/column names are plugin constants.
		$data_sql  = "SELECT * FROM `{$table}` WHERE {$where_sql} ORDER BY {$orderby} {$order}";
		if ( ! $args['no_limit'] ) {
			$data_sql .= ' LIMIT %d OFFSET %d';
		}

		if ( $args['no_limit'] ) {
			$total = empty( $values ) ? (int) $wpdb->get_var( $count_sql ) : (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $values ) );
			$items = empty( $values ) ? $wpdb->get_results( $data_sql ) : $wpdb->get_results( $wpdb->prepare( $data_sql, $values ) );
		} elseif ( ! empty( $values ) ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $values ) );
			$items = $wpdb->get_results( $wpdb->prepare( $data_sql, array_merge( $values, array( absint( $args['per_page'] ), $offset ) ) ) );
		} else {
			$total = (int) $wpdb->get_var( $count_sql );
			$items = $wpdb->get_results( $wpdb->prepare( $data_sql, absint( $args['per_page'] ), $offset ) );
		}

		return compact( 'items', 'total' );
	}

	public static function get_context( int $event_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . AM_Schema::CONTEXT_TABLE;
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT `key`, value FROM `{$table}` WHERE event_id = %d",
			$event_id
		) );

		$context = array();
		foreach ( $rows as $row ) {
			$decoded = json_decode( $row->value, true );
			$context[ $row->key ] = ( null !== $decoded || 'null' === $row->value ) ? $decoded : $row->value;
		}
		return $context;
	}

	public static function get_event_types(): array {
		global $wpdb;
		$table = $wpdb->prefix . AM_Schema::EVENTS_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
		return $wpdb->get_col( "SELECT DISTINCT event_type FROM `{$table}` ORDER BY event_type ASC" );
	}

	/** Row count in am_events — used to distinguish "no events yet" from "no v2.0 loggers active yet". */
	public static function total_count(): int {
		global $wpdb;
		$table = $wpdb->prefix . AM_Schema::EVENTS_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
	}

	// ── Stats & Insights (spec §4) ────────────────────────────────────────
	//
	// All stats methods take $days (7/14/30 typical) and query only
	// am_events -- none of this needs am_event_context. Each returns plain
	// arrays/counts ready for the admin screen or the digest email to
	// render directly, no further processing needed by the caller.

	/**
	 * Total event count within the last $days, and the count for the
	 * $days before that (for a "vs. previous period" comparison).
	 *
	 * @return array{current: int, previous: int}
	 */
	public static function get_totals_for_period( int $days ): array {
		global $wpdb;
		$table = $wpdb->prefix . AM_Schema::EVENTS_TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
		$current = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM `{$table}` WHERE date >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
			$days
		) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
		$previous = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM `{$table}`
			 WHERE date >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
			   AND date <  DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
			$days * 2,
			$days
		) );

		return array( 'current' => $current, 'previous' => $previous );
	}

	/**
	 * Daily event counts for the last $days, oldest first, with every day
	 * present (zero-filled) even if no events occurred -- so a chart
	 * doesn't have gaps.
	 *
	 * @return array<string, int> date (Y-m-d) => count
	 */
	public static function get_daily_trend( int $days ): array {
		global $wpdb;
		$table = $wpdb->prefix . AM_Schema::EVENTS_TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(date) AS day, COUNT(*) AS total
			 FROM `{$table}`
			 WHERE date >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
			 GROUP BY DATE(date)
			 ORDER BY day ASC",
			$days
		), ARRAY_A );

		$by_day = array();
		foreach ( $rows as $row ) {
			$by_day[ $row['day'] ] = (int) $row['total'];
		}

		// Zero-fill every day in the window so the chart has no gaps.
		$trend = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$date = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
			$trend[ $date ] = $by_day[ $date ] ?? 0;
		}
		return $trend;
	}

	/**
	 * Event counts grouped by event_type, descending, for the last $days.
	 *
	 * @return array<string, int> event_type => count
	 */
	public static function get_breakdown_by_event_type( int $days, int $limit = 10 ): array {
		global $wpdb;
		$table = $wpdb->prefix . AM_Schema::EVENTS_TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT event_type, COUNT(*) AS total
			 FROM `{$table}`
			 WHERE date >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
			 GROUP BY event_type
			 ORDER BY total DESC
			 LIMIT %d",
			$days,
			$limit
		), ARRAY_A );

		$out = array();
		foreach ( $rows as $row ) {
			$out[ $row['event_type'] ] = (int) $row['total'];
		}
		return $out;
	}

	/**
	 * Peak activity: busiest day-of-week and busiest hour-of-day within
	 * the window, each with its count. Hour is in the site's configured
	 * timezone (matches how the log table itself displays times), not UTC.
	 *
	 * @return array{busiest_day: array{name:string,count:int}|null, busiest_hour: array{hour:int,count:int}|null}
	 */
	public static function get_peak_activity( int $days ): array {
		global $wpdb;
		$table  = $wpdb->prefix . AM_Schema::EVENTS_TABLE;
		$offset = self::get_gmt_offset_sql();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/offset are plugin-controlled.
		$day_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT DAYNAME(DATE_ADD(date, INTERVAL {$offset} SECOND)) AS day_name, COUNT(*) AS total
			 FROM `{$table}`
			 WHERE date >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
			 GROUP BY day_name
			 ORDER BY total DESC
			 LIMIT 1",
			$days
		), ARRAY_A );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/offset are plugin-controlled.
		$hour_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT HOUR(DATE_ADD(date, INTERVAL {$offset} SECOND)) AS hour, COUNT(*) AS total
			 FROM `{$table}`
			 WHERE date >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
			 GROUP BY hour
			 ORDER BY total DESC
			 LIMIT 1",
			$days
		), ARRAY_A );

		return array(
			'busiest_day'  => $day_row ? array( 'name' => $day_row['day_name'], 'count' => (int) $day_row['total'] ) : null,
			'busiest_hour' => $hour_row ? array( 'hour' => (int) $hour_row['hour'], 'count' => (int) $hour_row['total'] ) : null,
		);
	}

	/**
	 * Most active users by event count within the window. Excludes
	 * non-authenticated initiators (web_user/wp_cron/wp_cli/system) since
	 * "most active user" is only meaningful for actual logged-in users.
	 *
	 * @return array<array{user_login:string, count:int}>
	 */
	public static function get_most_active_users( int $days, int $limit = 5 ): array {
		global $wpdb;
		$table = $wpdb->prefix . AM_Schema::EVENTS_TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT user_login, COUNT(*) AS total
			 FROM `{$table}`
			 WHERE date >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
			   AND initiator = %s
			   AND user_login != ''
			 GROUP BY user_login
			 ORDER BY total DESC
			 LIMIT %d",
			$days,
			AM_Initiator_Detector::WP_USER,
			$limit
		), ARRAY_A );

		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array( 'user_login' => $row['user_login'], 'count' => (int) $row['total'] );
		}
		return $out;
	}

	/**
	 * Notable/high-severity events within the window -- warning level or
	 * above. Used by the digest email's "notable security events" section
	 * (spec §4).
	 *
	 * @return array<object> up to $limit am_events rows, most recent first
	 */
	public static function get_notable_events( int $days, int $limit = 10 ): array {
		global $wpdb;
		$table = $wpdb->prefix . AM_Schema::EVENTS_TABLE;

		$notable_levels = array( AM_Log_Levels::WARNING, AM_Log_Levels::ERROR, AM_Log_Levels::CRITICAL, AM_Log_Levels::ALERT, AM_Log_Levels::EMERGENCY );
		$placeholders   = implode( ',', array_fill( 0, count( $notable_levels ), '%s' ) );

		$query_args   = $notable_levels;
		$query_args[] = $days;
		$query_args[] = $limit;

		return $wpdb->get_results( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant, placeholders are a fixed count of %s.
			"SELECT * FROM `{$table}`
			 WHERE level IN ({$placeholders})
			   AND date >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
			 ORDER BY date DESC
			 LIMIT %d",
			$query_args
		) );
	}

	/**
	 * SQL fragment for converting a UTC datetime to the site's configured
	 * timezone offset, in seconds. WordPress stores gmt_offset as a
	 * (possibly fractional, e.g. 5.5 for India) number of hours.
	 */
	private static function get_gmt_offset_sql(): string {
		$hours = (float) get_option( 'gmt_offset', 0 );
		return (string) (int) round( $hours * HOUR_IN_SECONDS );
	}
}
