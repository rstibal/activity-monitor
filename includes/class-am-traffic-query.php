<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Traffic_Query — read-side queries for the Traffic tab.
 *
 * get_totals_for_period, get_daily_trend, and get_top_pages read
 * primarily from am_traffic_daily (the rollup table), so they stay
 * fast regardless of how much raw history exists or has been pruned.
 * But the nightly rollup (AM_Traffic_Rollup) only ever processes
 * "yesterday", once, at 3 AM UTC -- so the rollup table never has a
 * row for "today", and may not have "yesterday" either depending on
 * the time of day. Left alone, that showed up as a false zero for the
 * most recent day(s) on the Dashboard/Traffic tab. All three methods
 * now patch that gap by merging in live counts for the last two days
 * from get_live_recent_traffic() below; the rollup table still carries
 * everything older than that. Method shapes deliberately mirror
 * AM_Event_Query so the Traffic tab can reuse the same chart/card
 * rendering conventions as the Stats tab.
 *
 * get_recent_hits() remains a simpler special case: it reads
 * am_traffic_log directly and doesn't touch the rollup at all, since
 * the live feed needs individual hits, not a merge.
 */
class AM_Traffic_Query {

	/**
	 * Total page views within the last $days, and the count for the
	 * $days before that, for a "vs. previous period" comparison.
	 *
	 * @return array{current: int, previous: int}
	 */
	public static function get_totals_for_period( int $days ): array {
		global $wpdb;
		$table = $wpdb->prefix . AM_Traffic_Schema::DAILY_TABLE;
		$live  = self::get_live_recent_traffic();

		// Only count live dates that actually fall within the window --
		// always true for today/yesterday given the $days this is
		// really called with (7/14/30), but kept correct in general.
		$live_total   = 0;
		$window_start = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		foreach ( $live['by_date'] as $date => $count ) {
			if ( $date >= $window_start ) {
				$live_total += $count;
			}
		}

		// Excludes the last 2 days -- covered by $live_total instead --
		// so a day the rollup has already caught up on isn't counted
		// twice.
		$current_rollup = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(views), 0) FROM `{$table}`
			 WHERE date >= DATE_SUB(UTC_DATE(), INTERVAL %d DAY)
			   AND date <  DATE_SUB(UTC_DATE(), INTERVAL 1 DAY)",
			$days
		) );

		$previous = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(views), 0) FROM `{$table}`
			 WHERE date >= DATE_SUB(UTC_DATE(), INTERVAL %d DAY)
			   AND date <  DATE_SUB(UTC_DATE(), INTERVAL %d DAY)",
			$days * 2,
			$days
		) );

		return array( 'current' => $current_rollup + $live_total, 'previous' => $previous );
	}

	/**
	 * Daily page view totals for the last $days, oldest first,
	 * zero-filled for days with no data at all. Today (and possibly
	 * yesterday) come from the raw log rather than the rollup table --
	 * see the class doc comment above.
	 *
	 * @return array<string, int> date (Y-m-d) => views
	 */
	public static function get_daily_trend( int $days ): array {
		global $wpdb;
		$table = $wpdb->prefix . AM_Traffic_Schema::DAILY_TABLE;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT date, SUM(views) AS total
			 FROM `{$table}`
			 WHERE date >= DATE_SUB(UTC_DATE(), INTERVAL %d DAY)
			 GROUP BY date
			 ORDER BY date ASC",
			$days
		), ARRAY_A );

		$by_day = array();
		foreach ( $rows as $row ) {
			$by_day[ $row['date'] ] = (int) $row['total'];
		}

		// Overwrite (not add to) today/yesterday with live counts --
		// whatever the rollup query above found for those two dates,
		// if anything, is stale or incomplete by definition.
		$live = self::get_live_recent_traffic();
		foreach ( $live['by_date'] as $date => $count ) {
			$by_day[ $date ] = $count;
		}

		$trend = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$date = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
			$trend[ $date ] = $by_day[ $date ] ?? 0;
		}
		return $trend;
	}

	/**
	 * Top pages by total views within the last $days. Merges in live
	 * counts for the last two days per-URL -- see the class doc
	 * comment above -- rather than relying on the rollup table alone.
	 *
	 * @return array<int, array{url: string, full_url: string, title: string, views: int, unique_ips: int}>
	 */
	public static function get_top_pages( int $days, int $limit = 10 ): array {
		global $wpdb;
		$table = $wpdb->prefix . AM_Traffic_Schema::DAILY_TABLE;

		// Excludes the last 2 days -- merged in per-URL below instead --
		// so a day the rollup has already caught up on isn't double
		// counted. MAX(page_title) picks one representative stored
		// title across the date range summed here, same reasoning as
		// AM_Traffic_Rollup::rollup_date().
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT url, url_hash, MAX(page_title) AS page_title, SUM(views) AS views, SUM(unique_ips) AS unique_ips
			 FROM `{$table}`
			 WHERE date >= DATE_SUB(UTC_DATE(), INTERVAL %d DAY)
			   AND date <  DATE_SUB(UTC_DATE(), INTERVAL 1 DAY)
			 GROUP BY url_hash, url",
			$days
		), ARRAY_A );

		$totals = array();
		foreach ( $rows as $row ) {
			$totals[ $row['url_hash'] ] = array(
				'url'        => $row['url'],
				'page_title' => $row['page_title'],
				'views'      => (int) $row['views'],
				'unique_ips' => (int) $row['unique_ips'],
			);
		}

		// unique_ips ends up as a sum of each source's own distinct-IP
		// count, not a true distinct count across the whole window --
		// that's not a new inaccuracy introduced here, though: the
		// rollup itself already only stores each day's own distinct
		// IPs, so summing unique_ips across several days was already
		// an approximation before this merge existed.
		$live = self::get_live_recent_traffic();
		foreach ( $live['by_url'] as $hash => $live_row ) {
			if ( isset( $totals[ $hash ] ) ) {
				$totals[ $hash ]['views']      += $live_row['views'];
				$totals[ $hash ]['unique_ips'] += $live_row['unique_ips'];
			} else {
				$totals[ $hash ] = $live_row;
			}
		}

		uasort( $totals, static function ( $a, $b ) {
			return $b['views'] <=> $a['views'];
		} );

		$top = array();
		foreach ( array_slice( $totals, 0, $limit, true ) as $row ) {
			$top[] = array(
				'url'        => $row['url'],
				'full_url'   => home_url( $row['url'] ),
				'title'      => self::display_title( $row['url'], $row['page_title'] ),
				'views'      => $row['views'],
				'unique_ips' => $row['unique_ips'],
			);
		}
		return $top;
	}

	/**
	 * Picks the best available title for a traffic URL: the actual
	 * document title captured live at the moment of the visit (stored
	 * page_title, added in 1.1.0), if present; a url_to_postid() lookup
	 * as a fallback for rows logged before that column existed; the raw
	 * path itself if neither resolves to anything.
	 */
	private static function display_title( string $path, string $stored_title ): string {
		if ( '' !== trim( $stored_title ) ) {
			return $stored_title;
		}
		return self::resolve_page_title( $path );
	}

	/**
	 * Search-engine hosts, matched exactly or as a parent domain
	 * (so "uk.search.yahoo.com" matches "yahoo.com"). Families with
	 * per-country TLDs -- Google, Yahoo, Yandex -- are handled by
	 * regex in classify_referrer() instead, since listing every
	 * ccTLD here would be endless.
	 */
	const SEARCH_HOSTS = array(
		'bing.com', 'duckduckgo.com', 'baidu.com', 'ecosia.org',
		'startpage.com', 'search.brave.com', 'qwant.com', 'naver.com',
		'ask.com', 'aol.com', 'seznam.cz', 'search.marimba.com',
	);

	/** Social hosts, matched the same way as SEARCH_HOSTS above. */
	const SOCIAL_HOSTS = array(
		'facebook.com', 'fb.com', 'instagram.com', 'twitter.com', 'x.com',
		't.co', 'linkedin.com', 'lnkd.in', 'reddit.com', 'pinterest.com',
		'tiktok.com', 'youtube.com', 'youtu.be', 'threads.net', 'tumblr.com',
		'quora.com', 'snapchat.com', 'nextdoor.com', 'whatsapp.com',
		'bsky.app', 'mastodon.social',
	);

	/**
	 * True if $host is exactly $needle or a subdomain of it. The
	 * dot guard matters: a plain substring test would let
	 * "notgoogle.com" match "google.com".
	 */
	private static function host_matches( string $host, array $needles ): bool {
		foreach ( $needles as $needle ) {
			$suffix = '.' . $needle;
			if ( $host === $needle || substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Buckets a raw referrer URL into one of five traffic sources.
	 *
	 * Public and separate from the aggregation below so the same rules
	 * can be reused (e.g. by a future top-referrers list) and so the
	 * classification can be reasoned about on its own.
	 *
	 * Buckets, in the order they're tested:
	 *   internal — the site linking to itself; not really a "source",
	 *              but worth seeing separately rather than silently
	 *              inflating the referral slice.
	 *   search   — organic search engines.
	 *   social   — social networks and their link shorteners (t.co,
	 *              lnkd.in, youtu.be), which would otherwise land in
	 *              referral and undercount social.
	 *   referral — any other site linking in.
	 *   direct   — no referrer at all: typed URLs, bookmarks, most app
	 *              and email clients, and anything stripped by a
	 *              referrer policy. Worth remembering that "direct" is
	 *              really "unattributed" rather than a positive signal.
	 *
	 * @param string $referrer  Raw referrer as stored on the hit row.
	 * @param string $site_host This site's host, lowercased, no "www.".
	 */
	public static function classify_referrer( string $referrer, string $site_host ): string {
		$referrer = trim( $referrer );
		if ( '' === $referrer ) {
			return 'direct';
		}

		$host = (string) wp_parse_url( $referrer, PHP_URL_HOST );
		if ( '' === $host ) {
			return 'direct'; // Unparseable referrer -- treat as unattributed.
		}
		$host = preg_replace( '/^www\./', '', strtolower( $host ) );

		if ( '' !== $site_host && ( $host === $site_host || self::host_matches( $host, array( $site_host ) ) ) ) {
			return 'internal';
		}

		// Country-TLD search families: google.co.uk, news.google.de,
		// uk.search.yahoo.com, yandex.ru, and so on.
		if ( preg_match( '/(^|\.)(google|yahoo|yandex)\.[a-z]{2,3}(\.[a-z]{2})?$/', $host ) ) {
			return 'search';
		}
		if ( self::host_matches( $host, self::SEARCH_HOSTS ) ) {
			return 'search';
		}
		if ( self::host_matches( $host, self::SOCIAL_HOSTS ) ) {
			return 'social';
		}
		return 'referral';
	}

	/** Display label for a bucket returned by classify_referrer(). */
	public static function source_label( string $key ): string {
		$map = array(
			'direct'   => __( 'Direct', 'activity-monitor' ),
			'search'   => __( 'Search', 'activity-monitor' ),
			'social'   => __( 'Social', 'activity-monitor' ),
			'referral' => __( 'Referral', 'activity-monitor' ),
			'internal' => __( 'Internal', 'activity-monitor' ),
		);
		return $map[ $key ] ?? __( 'Unknown', 'activity-monitor' );
	}

	/**
	 * Page views in the last $days grouped into traffic source buckets.
	 *
	 * IMPORTANT: unlike the rollup-backed methods above, this can only
	 * read am_traffic_log -- referrer isn't carried into am_traffic_daily,
	 * which stores per-URL totals only. That means this method's real
	 * coverage is min($days, am_traffic_retention_days): if raw hits are
	 * pruned at 7 days and a 30-day window is selected, only 7 days of
	 * referrers still exist. Callers should compare the window against
	 * that option and say so rather than presenting a short period as a
	 * full one (the Dashboard does). A retention of 0 never prunes, so
	 * coverage is complete.
	 *
	 * Grouping by referrer in SQL rather than fetching every hit keeps
	 * the result set to distinct referrer strings, but note there's no
	 * index on that column -- the ix_date range scan is what keeps this
	 * bounded, so it stays proportional to the window, not to total
	 * history.
	 *
	 * @return array<string, int> bucket => views, always all five keys.
	 */
	public static function get_traffic_sources( int $days ): array {
		global $wpdb;
		$table = $wpdb->prefix . AM_Traffic_Schema::LOG_TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT referrer, COUNT(*) AS total
			 FROM `{$table}`
			 WHERE date >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
			 GROUP BY referrer",
			$days
		), ARRAY_A );

		$site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$site_host = preg_replace( '/^www\./', '', strtolower( $site_host ) );

		$out = array(
			'direct'   => 0,
			'search'   => 0,
			'social'   => 0,
			'referral' => 0,
			'internal' => 0,
		);
		foreach ( $rows as $row ) {
			$bucket           = self::classify_referrer( (string) $row['referrer'], $site_host );
			$out[ $bucket ] += (int) $row['total'];
		}
		return $out;
	}

	/**
	 * Resolves a stored (path-only) traffic URL to the actual page/post
	 * title via a live lookup -- fallback only, for rows logged before
	 * page_title existed (see display_title() above). Live-captured
	 * page_title is preferred wherever available since it reflects
	 * what the visitor actually saw at the time, not a title
	 * reconstructed afterward from the URL.
	 *
	 * url_to_postid() only resolves an actual post/page/CPT (or the
	 * static front page); a hit against anything else -- a custom
	 * template, an archive, a 404 that slipped through the bot filter,
	 * a query-string-only match, etc. -- returns 0, meaning there's no
	 * post to title. In that case this falls back to the raw path
	 * rather than showing nothing, since the path itself is still
	 * useful information even when no title exists for it.
	 */
	public static function resolve_page_title( string $path ): string {
		$post_id = url_to_postid( home_url( $path ) );
		if ( $post_id ) {
			$title = get_the_title( $post_id );
			if ( '' !== trim( (string) $title ) ) {
				return $title;
			}
		}
		return $path;
	}

	/**
	 * Live traffic for the last two UTC calendar days (today and
	 * yesterday) -- the window AM_Traffic_Rollup may not have caught up
	 * on yet, since it only ever processes "yesterday", once, at 3 AM
	 * UTC (see the class doc comment above). Reads am_traffic_log
	 * directly so get_totals_for_period(), get_daily_trend(), and
	 * get_top_pages() can patch that gap in with real data instead of a
	 * false zero, while still reading everything older from the
	 * (much smaller) rollup table.
	 *
	 * @return array{by_date: array<string,int>, by_url: array<string, array{url:string, page_title:string, views:int, unique_ips:int}>}
	 */
	private static function get_live_recent_traffic(): array {
		global $wpdb;
		$table = $wpdb->prefix . AM_Traffic_Schema::LOG_TABLE;

		$rows = $wpdb->get_results(
			"SELECT DATE(date) AS day, url, url_hash, page_title, ip_address
			 FROM `{$table}`
			 WHERE DATE(date) >= DATE_SUB(UTC_DATE(), INTERVAL 1 DAY)"
		);

		$by_date  = array();
		$by_url   = array();
		$ips_seen = array(); // url_hash => [ip_address => true], to derive unique_ips below.

		foreach ( $rows as $row ) {
			$by_date[ $row->day ] = ( $by_date[ $row->day ] ?? 0 ) + 1;

			if ( ! isset( $by_url[ $row->url_hash ] ) ) {
				$by_url[ $row->url_hash ] = array(
					'url'        => $row->url,
					'page_title' => $row->page_title,
					'views'      => 0,
					'unique_ips' => 0,
				);
			}
			++$by_url[ $row->url_hash ]['views'];
			$ips_seen[ $row->url_hash ][ $row->ip_address ] = true;
		}
		foreach ( $ips_seen as $hash => $ips ) {
			$by_url[ $hash ]['unique_ips'] = count( $ips );
		}

		return array( 'by_date' => $by_date, 'by_url' => $by_url );
	}

	/**
	 * The $limit most recent raw hits, newest first. Backs the live
	 * feed's initial load and each poll tick (see admin JS in
	 * render_tab_traffic). Reads am_traffic_log directly since this is
	 * inherently a "what just happened" view, not an aggregate.
	 *
	 * @return array<int, array{id:int, date:string, url:string, ip_address:string, user_id:int}>
	 */
	public static function get_recent_hits( int $limit, int $after_id = 0 ): array {
		global $wpdb;
		$table = $wpdb->prefix . AM_Traffic_Schema::LOG_TABLE;

		if ( $after_id > 0 ) {
			// Poll mode: only hits newer than the last one the client
			// already has, so repeated polls don't re-send the same rows.
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, date, url, page_title, ip_address, user_id FROM `{$table}`
				 WHERE id > %d
				 ORDER BY id DESC
				 LIMIT %d",
				$after_id,
				$limit
			), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, date, url, page_title, ip_address, user_id FROM `{$table}`
				 ORDER BY id DESC
				 LIMIT %d",
				$limit
			), ARRAY_A );
		}

		$hits = array();
		foreach ( $rows as $row ) {
			$hits[] = array(
				'id'         => (int) $row['id'],
				'date'       => $row['date'],
				'url'        => $row['url'],
				'title'      => self::display_title( $row['url'], $row['page_title'] ),
				'ip_address' => $row['ip_address'],
				'user_id'    => (int) $row['user_id'],
			);
		}
		return $hits;
	}

	/**
	 * A single raw hit by ID, with every column (including referrer and
	 * user_agent, which get_recent_hits() above doesn't select -- those
	 * aren't needed for the live feed's row display, only for this
	 * detail view). Backs the page-detail modal opened by clicking a
	 * page/URL in the live feed.
	 *
	 * @return array{id:int, date:string, url:string, title:string, referrer:string, ip_address:string, user_agent:string, user_id:int}|null
	 */
	public static function get_hit( int $id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . AM_Traffic_Schema::LOG_TABLE;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, date, url, page_title, referrer, ip_address, user_agent, user_id FROM `{$table}` WHERE id = %d",
			$id
		), ARRAY_A );

		if ( ! $row ) {
			return null;
		}

		return array(
			'id'         => (int) $row['id'],
			'date'       => $row['date'],
			'url'        => $row['url'],
			'title'      => self::display_title( $row['url'], $row['page_title'] ),
			'referrer'   => $row['referrer'],
			'ip_address' => $row['ip_address'],
			'user_agent' => $row['user_agent'],
			'user_id'    => (int) $row['user_id'],
		);
	}
}
