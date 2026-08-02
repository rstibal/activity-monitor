<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Event_Query — read-side query layer for the v2.0 schema
 * (am_events + am_event_context), and the only query layer in the plugin.
 * The Activity Log screen, export, and the email digest all read through
 * it; nothing else queries those tables directly.
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
			// Not sanitize_key() here: that strips dots, and rows migrated
			// from v1.x can hold a dotted slug in this column (e.g.
			// 'post.delete'). Stripping would silently turn the filter
			// into a search for 'postdelete' and match nothing.
			$where[]  = 'event_type = %s';
			$values[] = preg_replace( '/[^a-z0-9_.\-]/', '', strtolower( $args['event_type'] ) );
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

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- $count_sql/$data_sql are built above from plugin constants plus a fixed set of literal WHERE fragments; every caller-supplied value travels separately in $values as a %s placeholder.
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
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		return compact( 'items', 'total' );
	}

	public static function get_context( int $event_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . AM_Schema::CONTEXT_TABLE;
		$rows  = $wpdb->get_results( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
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

	/**
	 * Distinct event_type + action pairs present in the log, for the
	 * Activity Log's Type filter, which uses them to offer both whole
	 * categories ("Media") and the specific events within them
	 * ("Media Uploaded").
	 *
	 * Rows migrated from v1.x have an empty action (see AM_Schema's
	 * migrate_legacy_row()), so those come back as a single pair with
	 * action = '' -- for them the category and the specific event are
	 * the same thing, and the caller renders one option rather than a
	 * group.
	 *
	 * @return array<array{event_type:string, action:string}>
	 */
	public static function get_event_type_actions(): array {
		global $wpdb;
		$table = $wpdb->prefix . AM_Schema::EVENTS_TABLE;
		return $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
			"SELECT DISTINCT event_type, action FROM `{$table}`
			 ORDER BY event_type ASC, action ASC",
			ARRAY_A
		);
	}

	/** Row count in am_events — used to distinguish "no events yet" from "no v2.0 loggers active yet". */
	public static function total_count(): int {
		global $wpdb;
		$table = $wpdb->prefix . AM_Schema::EVENTS_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
	}

	/**
	 * Date of the oldest surviving entry, as a UTC 'Y-m-d H:i:s' string, or
	 * '' when the log is empty. Read by the retention setting, which is
	 * otherwise an abstract number of days -- pairing it with how far back
	 * the log actually reaches is what tells an admin whether shortening
	 * retention would delete anything they still have.
	 */
	public static function oldest_date(): string {
		global $wpdb;
		$table = $wpdb->prefix . AM_Schema::EVENTS_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
		return (string) $wpdb->get_var( "SELECT MIN(date) FROM `{$table}`" );
	}

	// ── Period-summary queries (email digest) ──────────────────────────────
	//
	// These take $days (7/14/30 typical) and query only am_events -- none
	// of this needs am_event_context. Each returns plain arrays/counts
	// ready for AM_Digest::build_html() to render directly, and the digest
	// is now their only consumer: the Dashboard admin tab went in 2.1.0,
	// and the three queries only it had used (daily trend, breakdown by
	// initiator, most active users) were deleted in 2.2.1 once nothing
	// called them. Anything added here needs a caller, or it is dead on
	// arrival.

	/**
	 * Total event count within the last $days, and the count for the
	 * $days before that (for a "vs. previous period" comparison).
	 *
	 * @return array{current: int, previous: int}
	 */
	public static function get_totals_for_period( int $days ): array {
		global $wpdb;
		$table = $wpdb->prefix . AM_Schema::EVENTS_TABLE;

		$current = (int) $wpdb->get_var( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
			"SELECT COUNT(*) FROM `{$table}` WHERE date >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
			$days
		) );

		$previous = (int) $wpdb->get_var( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
			"SELECT COUNT(*) FROM `{$table}`
			 WHERE date >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
			   AND date <  DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
			$days * 2,
			$days
		) );

		return array( 'current' => $current, 'previous' => $previous );
	}

	/**
	 * Event counts grouped by event_type, descending, for the last $days.
	 *
	 * @return array<string, int> event_type => count
	 */
	public static function get_breakdown_by_event_type( int $days, int $limit = 10 ): array {
		global $wpdb;
		$table = $wpdb->prefix . AM_Schema::EVENTS_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
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
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array();
		foreach ( $rows as $row ) {
			$out[ $row['event_type'] ] = (int) $row['total'];
		}
		return $out;
	}

	/**
	 * Notable/high-severity events within the window -- warning level or
	 * above. Used by the digest email's "notable events" section.
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

		// $placeholders is a fixed count of %s built from count( $notable_levels ),
		// never from input; the level values themselves travel in $query_args. The
		// placeholder-count sniff can't see through the single-array form of
		// prepare() and reads the one argument as one replacement.
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- see above.
		return $wpdb->get_results( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant; see above.
			"SELECT * FROM `{$table}` WHERE level IN ({$placeholders})
			   AND date >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
			 ORDER BY date DESC
			 LIMIT %d",
			$query_args
		) );
	}
}
