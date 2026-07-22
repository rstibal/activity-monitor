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
			'search'    => '',
			'orderby'   => 'date',
			'order'     => 'DESC',
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
}
