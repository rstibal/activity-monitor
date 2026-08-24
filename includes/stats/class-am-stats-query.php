<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Stats_Query — read-side query layer for the stats admin screen.
 * Plain $wpdb queries against the three am_stats_* tables, matching the
 * style of AM_Event_Query (no ORM). Every method takes a $days window
 * (calendar days back from now, UTC) rather than arbitrary date filters --
 * the stats screen only ever needs a handful of fixed ranges.
 */
class AM_Stats_Query {

	/** @return array{visits:int, unique_visitors:int} */
	public static function get_totals( int $days ): array {
		global $wpdb;
		$hits_table = $wpdb->prefix . AM_Stats_Schema::HITS_TABLE;
		$since      = self::since( $days );

		$row = $wpdb->get_row( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
			"SELECT COUNT(*) AS visits, COUNT(DISTINCT visitor_hash) AS unique_visitors FROM `{$hits_table}`
			 WHERE date >= %s",
			$since
		) );

		return array(
			'visits'          => $row ? (int) $row->visits : 0,
			'unique_visitors' => $row ? (int) $row->unique_visitors : 0,
		);
	}

	/** @return array<int, object{url:string,title:string,visits:int,unique_visitors:int}> */
	public static function get_top_urls( int $days, int $limit = 20 ): array {
		global $wpdb;
		$hits_table = $wpdb->prefix . AM_Stats_Schema::HITS_TABLE;
		$urls_table = $wpdb->prefix . AM_Stats_Schema::URLS_TABLE;
		$since      = self::since( $days );

		return $wpdb->get_results( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are plugin constants.
			"SELECT u.url, u.title, COUNT(*) AS visits, COUNT(DISTINCT h.visitor_hash) AS unique_visitors FROM `{$hits_table}` h INNER JOIN `{$urls_table}` u ON u.id = h.url_id
			 WHERE h.date >= %s
			 GROUP BY h.url_id
			 ORDER BY visits DESC
			 LIMIT %d",
			$since,
			$limit
		) );
	}

	/** @return array<int, object{referrer_host:string,visits:int}> */
	public static function get_referrers( int $days, int $limit = 20 ): array {
		global $wpdb;
		$hits_table = $wpdb->prefix . AM_Stats_Schema::HITS_TABLE;
		$since      = self::since( $days );

		return $wpdb->get_results( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
			"SELECT referrer_host, COUNT(*) AS visits FROM `{$hits_table}`
			 WHERE date >= %s AND referrer_host != ''
			 GROUP BY referrer_host
			 ORDER BY visits DESC
			 LIMIT %d",
			$since,
			$limit
		) );
	}

	/** @return array<int, object{value:string,visits:int}> */
	public static function get_breakdown( string $column, int $days ): array {
		if ( ! in_array( $column, array( 'browser', 'os', 'device_type' ), true ) ) {
			return array();
		}

		global $wpdb;
		$hits_table = $wpdb->prefix . AM_Stats_Schema::HITS_TABLE;
		$since      = self::since( $days );

		// $column is whitelisted above, not user input -- safe to interpolate.
		// GROUP BY refers to the "value" alias rather than repeating the
		// column interpolation a second time (MySQL allows grouping by a
		// SELECT-list alias).
		return $wpdb->get_results( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/column names are plugin constants, whitelisted above.
			"SELECT `{$column}` AS value, COUNT(*) AS visits FROM `{$hits_table}`
			 WHERE date >= %s
			 GROUP BY value
			 ORDER BY visits DESC",
			$since
		) );
	}

	private static function since( int $days ): string {
		return gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
	}
}
