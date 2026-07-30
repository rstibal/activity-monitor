<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Traffic_Query — read-side queries for the Traffic tab's live feed
 * and page-detail modal.
 *
 * get_recent_hits() and get_hit() read am_traffic_log directly, since the
 * live feed needs individual hits, not an aggregate. The rollup-backed
 * aggregate methods this class used to also provide (totals, daily trend,
 * top pages, traffic sources by referrer) existed only for the Dashboard
 * tab, removed in 2.1.0, and were deleted with it.
 */
class AM_Traffic_Query {

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
