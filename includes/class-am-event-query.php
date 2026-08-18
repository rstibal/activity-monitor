<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Event_Query — read-side query layer for the v2.0 schema
 * (am_events + am_event_context), and the only query layer in the plugin.
 * The Activity Log screen, export, and the email digest all read through
 * it; nothing else queries those tables directly.
 */
class AM_Event_Query {

	/** Argument defaults shared by get_events() and get_level_counts(). */
	private static function query_defaults(): array {
		return array(
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
	}

	/**
	 * Build the WHERE clause shared by get_events() and get_level_counts().
	 *
	 * $skip names filters to leave out. get_level_counts() passes 'level',
	 * because a count *per level* has to be taken across everything the
	 * other filters allow -- applying the level filter to its own tally
	 * would return one row every time.
	 *
	 * @return array{sql: string, values: array} SQL with %s placeholders,
	 *         and the values to feed $wpdb->prepare() in the same order.
	 */
	private static function build_where( array $args, array $skip = array() ): array {
		global $wpdb;

		$where  = array( '1=1' );
		$values = array();

		if ( ! in_array( 'level', $skip, true ) && '' !== $args['level'] && AM_Log_Levels::is_valid( $args['level'] ) ) {
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

		return array(
			'sql'    => implode( ' AND ', $where ),
			'values' => $values,
		);
	}

	/**
	 * The log. Every event type is reachable from here, PHP errors
	 * included -- there is no hidden exclusion, and what this returns is
	 * exactly what the Activity Log screen shows and the export writes.
	 */
	public static function get_events( array $args = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . AM_Schema::EVENTS_TABLE;

		$args   = wp_parse_args( $args, self::query_defaults() );
		$offset = ( absint( $args['page'] ) - 1 ) * absint( $args['per_page'] );

		$built  = self::build_where( $args );
		$values = $built['values'];

		$allowed_order   = array( 'ASC', 'DESC' );
		$allowed_orderby = array( 'date', 'level', 'event_type', 'user_login', 'id' );
		$order   = in_array( strtoupper( $args['order'] ), $allowed_order, true ) ? strtoupper( $args['order'] ) : 'DESC';
		$orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'date';

		$where_sql = $built['sql'];
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

	/**
	 * Row counts per level, for the Activity Log's status links.
	 *
	 * Takes the same $args as get_events() and honours all of them *except*
	 * level, so the numbers describe what the currently-applied type,
	 * initiator, user, date and search filters actually contain. That is
	 * what makes the links trustworthy: a level with no rows under the
	 * current filters is dropped from the list entirely rather than
	 * offering a click that lands on "No activity found."
	 *
	 * Filter-aware rather than site-wide on purpose. A site-wide tally is
	 * one cheaper query, but then the counts and the screen disagree the
	 * moment any other filter is on -- the list would advertise
	 * "Warning (40)" while the filtered view holds three.
	 *
	 * @return array<string, int> level => count, only levels with rows,
	 *         ordered by AM_Log_Levels::ORDER.
	 */
	public static function get_level_counts( array $args = array() ): array {
		global $wpdb;
		$table = $wpdb->prefix . AM_Schema::EVENTS_TABLE;

		$args  = wp_parse_args( $args, self::query_defaults() );
		$built = self::build_where( $args, array( 'level' ) );

		$where_sql = $built['sql'];
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant; $where_sql is built by build_where(), where every caller value is a %s placeholder carried in $built['values'].
		$sql = "SELECT level, COUNT(*) AS total FROM `{$table}` WHERE {$where_sql} GROUP BY level";

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- see above.
		$rows = empty( $built['values'] )
			? $wpdb->get_results( $sql, ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( $sql, $built['values'] ), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['level'] ] = (int) $row['total'];
		}

		// Reordered into severity order, and silently dropping any level
		// string in the table that AM_Log_Levels no longer knows about --
		// the status links can only render levels that have a label.
		$ordered = array();
		foreach ( AM_Log_Levels::ORDER as $level ) {
			if ( ! empty( $counts[ $level ] ) ) {
				$ordered[ $level ] = $counts[ $level ];
			}
		}
		return $ordered;
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
	 * Everything in the table is offered, PHP errors included: the dropdown
	 * is built from what is actually stored, so it can only ever offer a
	 * filter that matches something. Selecting the "System" group is what
	 * replaced the separate Debug Log screen.
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
}
