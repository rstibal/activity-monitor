<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Traffic_Rollup — daily aggregation of am_traffic_log into
 * am_traffic_daily, plus raw-log pruning.
 *
 * Runs once a day via WP-Cron (see activity-monitor.php), same pattern
 * as the existing am_log_prune cron for the audit-log schema. Rolling
 * up daily rather than querying am_traffic_log directly for the Traffic
 * tab's charts/tables keeps those reads fast regardless of how much raw
 * history has accumulated (or been pruned).
 *
 * Idempotent by design: re-running for a date that's already rolled up
 * recomputes and overwrites that date's rows (INSERT ... ON DUPLICATE
 * KEY UPDATE against the (date, url_hash) unique key), so a missed or
 * re-triggered cron tick can't double-count.
 */
class AM_Traffic_Rollup {

	const CRON_HOOK = 'am_traffic_rollup';

	public static function reschedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Run a few hours after midnight UTC so a full prior day's
			// hits (from any timezone the server itself might be in)
			// are settled before rolling up "yesterday".
			$next = strtotime( 'tomorrow 03:00 UTC' );
			wp_schedule_event( $next, 'daily', self::CRON_HOOK );
		}
	}

	/** Roll up yesterday's raw hits, then prune the raw log per retention setting. */
	public static function run() {
		self::rollup_date( gmdate( 'Y-m-d', strtotime( '-1 day' ) ) );
		AM_Traffic_Schema::prune_log( absint( get_option( 'am_traffic_retention_days', 30 ) ) );
	}

	/**
	 * Aggregate all raw hits for one UTC date into am_traffic_daily.
	 * Public (not just called from run()) so a manual "recompute" admin
	 * action or a backfill routine can target a specific date.
	 */
	public static function rollup_date( string $date ) {
		global $wpdb;
		$log_table   = $wpdb->prefix . AM_Traffic_Schema::LOG_TABLE;
		$daily_table = $wpdb->prefix . AM_Traffic_Schema::DAILY_TABLE;

		// MAX(page_title) picks one representative title per URL for the
		// day -- if the title genuinely changed mid-day (post edited,
		// SEO setting changed), this may not be the very latest one, but
		// that's a rare edge case not worth a more complex query for.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT url, url_hash, MAX(page_title) AS page_title, COUNT(*) AS views, COUNT(DISTINCT ip_address) AS unique_ips
			 FROM `{$log_table}`
			 WHERE DATE(date) = %s
			 GROUP BY url_hash, url",
			$date
		) );

		if ( ! $rows ) {
			return;
		}

		foreach ( $rows as $row ) {
			$wpdb->query( $wpdb->prepare(
				"INSERT INTO `{$daily_table}` (date, url, url_hash, page_title, views, unique_ips)
				 VALUES (%s, %s, %s, %s, %d, %d)
				 ON DUPLICATE KEY UPDATE page_title = VALUES(page_title), views = VALUES(views), unique_ips = VALUES(unique_ips)",
				$date,
				$row->url,
				$row->url_hash,
				$row->page_title,
				(int) $row->views,
				(int) $row->unique_ips
			) );
		}
	}
}
