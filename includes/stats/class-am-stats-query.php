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

	/**
	 * @return array{items: array<int, object{url:string,title:string,visits:int,unique_visitors:int}>, total:int}
	 */
	public static function get_top_urls( int $days, int $page = 1, int $per_page = 10 ): array {
		global $wpdb;
		$hits_table = $wpdb->prefix . AM_Stats_Schema::HITS_TABLE;
		$urls_table = $wpdb->prefix . AM_Stats_Schema::URLS_TABLE;
		$since      = self::since( $days );
		$page       = max( 1, $page );
		$offset     = ( $page - 1 ) * $per_page;

		$total = (int) $wpdb->get_var( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
			"SELECT COUNT(DISTINCT h.url_id) FROM `{$hits_table}` h
			 WHERE h.date >= %s",
			$since
		) );

		$items = $wpdb->get_results( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are plugin constants.
			"SELECT u.url, u.title, COUNT(*) AS visits, COUNT(DISTINCT h.visitor_hash) AS unique_visitors FROM `{$hits_table}` h INNER JOIN `{$urls_table}` u ON u.id = h.url_id
			 WHERE h.date >= %s
			 GROUP BY h.url_id
			 ORDER BY visits DESC
			 LIMIT %d OFFSET %d",
			$since,
			$per_page,
			$offset
		) );

		return array( 'items' => $items, 'total' => $total );
	}

	/** @return array{items: array<int, object{referrer_host:string,visits:int}>, total:int} */
	public static function get_referrers( int $days, int $page = 1, int $per_page = 10 ): array {
		global $wpdb;
		$hits_table = $wpdb->prefix . AM_Stats_Schema::HITS_TABLE;
		$since      = self::since( $days );
		$page       = max( 1, $page );
		$offset     = ( $page - 1 ) * $per_page;

		$total = (int) $wpdb->get_var( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
			"SELECT COUNT(DISTINCT referrer_host) FROM `{$hits_table}`
			 WHERE date >= %s AND referrer_host != ''",
			$since
		) );

		$items = $wpdb->get_results( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
			"SELECT referrer_host, COUNT(*) AS visits FROM `{$hits_table}`
			 WHERE date >= %s AND referrer_host != ''
			 GROUP BY referrer_host
			 ORDER BY visits DESC
			 LIMIT %d OFFSET %d",
			$since,
			$per_page,
			$offset
		) );

		return array( 'items' => $items, 'total' => $total );
	}

	/** @return array{items: array<int, object{value:string,visits:int}>, total:int} */
	public static function get_breakdown( string $column, int $days, int $page = 1, int $per_page = 10 ): array {
		if ( ! in_array( $column, array( 'browser', 'os', 'device_type', 'country_code' ), true ) ) {
			return array( 'items' => array(), 'total' => 0 );
		}

		global $wpdb;
		$hits_table = $wpdb->prefix . AM_Stats_Schema::HITS_TABLE;
		$since      = self::since( $days );
		$page       = max( 1, $page );
		$offset     = ( $page - 1 ) * $per_page;

		// $column is whitelisted above, not user input -- safe to interpolate.
		// GROUP BY refers to the "value" alias rather than repeating the
		// column interpolation a second time (MySQL allows grouping by a
		// SELECT-list alias).
		$total = (int) $wpdb->get_var( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/column names are plugin constants, whitelisted above.
			"SELECT COUNT(DISTINCT `{$column}`) FROM `{$hits_table}`
			 WHERE date >= %s",
			$since
		) );

		$items = $wpdb->get_results( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/column names are plugin constants, whitelisted above.
			"SELECT `{$column}` AS value, COUNT(*) AS visits FROM `{$hits_table}`
			 WHERE date >= %s
			 GROUP BY value
			 ORDER BY visits DESC
			 LIMIT %d OFFSET %d",
			$since,
			$per_page,
			$offset
		) );

		return array( 'items' => $items, 'total' => $total );
	}

	/**
	 * Raw hits, newest first -- one row per pageview, paginated. This is
	 * the history view; get_top_urls() is the aggregated one.
	 *
	 * @return array{items: array<int, object{date:string,browser:string,os:string,device_type:string,country_code:string,referrer_host:string,url:string,title:string}>, total:int}
	 */
	public static function get_hits( int $days, int $page = 1, int $per_page = 50 ): array {
		global $wpdb;
		$hits_table = $wpdb->prefix . AM_Stats_Schema::HITS_TABLE;
		$urls_table = $wpdb->prefix . AM_Stats_Schema::URLS_TABLE;
		$since      = self::since( $days );
		$page       = max( 1, $page );
		$offset     = ( $page - 1 ) * $per_page;

		$total = (int) $wpdb->get_var( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
			"SELECT COUNT(*) FROM `{$hits_table}` WHERE date >= %s",
			$since
		) );

		$items = $wpdb->get_results( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are plugin constants.
			"SELECT h.date, h.browser, h.os, h.device_type, h.country_code, h.referrer_host, u.url, u.title FROM `{$hits_table}` h INNER JOIN `{$urls_table}` u ON u.id = h.url_id
			 WHERE h.date >= %s
			 ORDER BY h.id DESC
			 LIMIT %d OFFSET %d",
			$since,
			$per_page,
			$offset
		) );

		return array( 'items' => $items, 'total' => $total );
	}

	private static function since( int $days ): string {
		return gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
	}
}
