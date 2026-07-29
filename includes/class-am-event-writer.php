<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Event_Writer — the single write path every AM_Logger_* class uses.
 *
 * Centralizes what used to be duplicated per call-site in v1.x's
 * AM_Logger::log():
 *   - occasion grouping (new in v2.0 — collapses repeat events into one
 *     row with a counter, generalizing v1's cron-only skip logic)
 *   - level defaulting (via AM_Log_Levels)
 *   - initiator tagging (via AM_Initiator_Detector)
 *   - user snapshotting
 *   - context (key/value) persistence
 *
 * See activity-monitor-v2-spec.md §3 (noise control) and §6 (architecture).
 */
class AM_Event_Writer {

	/** Repeat events within this window collapse into one row. Filterable. */
	const DEFAULT_OCCASION_WINDOW_SECONDS = 300;

	/**
	 * Log one event.
	 *
	 * @param string $event_type e.g. 'post', 'user', 'plugin', 'session'.
	 * @param string $action     e.g. 'created', 'updated', 'deleted', 'login_failed'.
	 * @param string $message    Human-readable summary.
	 * @param array  $args {
	 *     Optional.
	 *     @type string $level        One of AM_Log_Levels::*. Defaults per event_type via AM_Log_Levels.
	 *     @type string $object_type
	 *     @type int    $object_id
	 *     @type string $object_name
	 *     @type array  $context      Key/value pairs stored in am_event_context (diffs, before/after, etc).
	 *     @type bool   $group        Whether this event type participates in occasion grouping. Default true.
	 * }
	 * @return int|false The event id, or false if the write only incremented an existing occasion row.
	 */
	public static function log( string $event_type, string $action, string $message, array $args = array() ) {
		global $wpdb;

		$defaults = array(
			'level'       => AM_Log_Levels::default_for_event_type( $event_type ),
			'object_type' => '',
			'object_id'   => 0,
			'object_name' => '',
			'context'     => array(),
			'group'       => true,
			// Set true only when logging a notification-delivery failure
			// itself (see AM_Notifications::log_slack_failure and
			// AM_Logger_Mail_Failures) -- without this, a failing
			// channel logging its own failure could re-trigger
			// maybe_notify() on the very same (still-failing) channel,
			// which logs another failure, which triggers another
			// attempt, and so on for every event that would otherwise
			// have notified. Every other caller leaves this at the
			// default false and behaves exactly as before.
			'skip_notify' => false,
		);
		$args = wp_parse_args( $args, $defaults );

		if ( ! AM_Log_Levels::is_valid( $args['level'] ) ) {
			$args['level'] = AM_Log_Levels::INFO;
		}

		$initiator = AM_Initiator_Detector::detect();
		$user      = wp_get_current_user();
		$events_table = $wpdb->prefix . AM_Schema::EVENTS_TABLE;

		$occasion_id = null;
		if ( $args['group'] ) {
			$occasion_id = self::compute_occasion_id( $event_type, $action, (int) $args['object_id'], $initiator );

			if ( self::maybe_increment_existing( $occasion_id ) ) {
				return false; // Collapsed into an existing row — no new event id, nothing further to write.
			}
		}

		$row = array(
			'date'              => current_time( 'mysql', true ),
			'level'             => $args['level'],
			'initiator'         => $initiator,
			'user_id'           => (int) $user->ID,
			'user_login'        => $user->exists() ? $user->user_login : '',
			'user_display_name' => $user->exists() ? $user->display_name : '',
			'user_role'         => $user->exists() ? implode( ', ', (array) $user->roles ) : '',
			'ip_address'        => self::get_ip(),
			'event_type'        => sanitize_key( $event_type ),
			'action'            => sanitize_key( $action ),
			'object_type'       => sanitize_text_field( $args['object_type'] ),
			'object_id'         => absint( $args['object_id'] ),
			'object_name'       => sanitize_text_field( $args['object_name'] ),
			'message'           => sanitize_textarea_field( $message ),
			'occasion_id'       => $occasion_id,
			'repeat_count'      => 1,
		);

		$wpdb->insert( $events_table, $row );
		$event_id = $wpdb->insert_id;

		if ( $event_id && ! empty( $args['context'] ) ) {
			self::write_context( $event_id, $args['context'] );
		}

		// BUGFIX: notifications were only ever wired to the legacy
		// AM_Logger::log() call path, which every event source stopped
		// using once ported onto this writer (dev.1-dev.12) -- silently
		// making notifications dead for every ported event. Wired here,
		// on genuine new-row inserts only (an occasion-grouped repeat
		// returns early above and never reaches this point, so a
		// brute-force burst doesn't spam a notification per attempt).
		if ( $event_id && ! $args['skip_notify'] ) {
			AM_Notifications::maybe_notify(
				$row['level'],
				$row['event_type'],
				$row['action'],
				$row['message'],
				array(
					'user_login'  => $row['user_login'],
					'ip_address'  => $row['ip_address'],
					'object_name' => $row['object_name'],
					'event_id'    => $event_id,
				)
			);
		}

		return $event_id;
	}

	/**
	 * If a row with this occasion_id was written within the grouping
	 * window, bump its repeat_count and timestamp instead of inserting.
	 *
	 * @return bool True if an existing row was incremented (caller should stop).
	 */
	private static function maybe_increment_existing( string $occasion_id ): bool {
		global $wpdb;
		$events_table = $wpdb->prefix . AM_Schema::EVENTS_TABLE;
		$window       = (int) apply_filters( 'am_occasion_window_seconds', self::DEFAULT_OCCASION_WINDOW_SECONDS );

		$existing_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM `{$events_table}`
			 WHERE occasion_id = %s
			   AND date >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d SECOND)
			 ORDER BY id DESC LIMIT 1",
			$occasion_id,
			$window
		) );

		if ( ! $existing_id ) {
			return false;
		}

		$wpdb->query( $wpdb->prepare(
			"UPDATE `{$events_table}` SET repeat_count = repeat_count + 1, date = %s WHERE id = %d",
			current_time( 'mysql', true ),
			$existing_id
		) );

		return true;
	}

	private static function compute_occasion_id( string $event_type, string $action, int $object_id, string $initiator ): string {
		return md5( $event_type . '|' . $action . '|' . $object_id . '|' . $initiator );
	}

	private static function write_context( int $event_id, array $context ) {
		global $wpdb;
		$context_table = $wpdb->prefix . AM_Schema::CONTEXT_TABLE;

		foreach ( $context as $key => $value ) {
			$wpdb->insert( $context_table, array(
				'event_id' => $event_id,
				'key'      => sanitize_key( (string) $key ),
				'value'    => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
			) );
		}
	}

	/**
	 * IP resolution — ported as-is from v1.x AM_DB::get_ip() (Cloudflare
	 * CIDR-validated, X-Forwarded-For intentionally not trusted). Kept
	 * unchanged; this logic was already security-reviewed in v1.3.0.
	 */
	private static function get_ip(): string {
		return AM_DB_Legacy_IP::resolve();
	}
}
