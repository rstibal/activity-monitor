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
 */
class AM_Event_Writer {

	/**
	 * Repeat events within this window collapse into one row. The window is
	 * configurable on the Settings screen (am_occasion_window_seconds, where
	 * 0 turns grouping off entirely) and still filterable on top of that --
	 * this constant is only the fallback for a site that has never saved
	 * the setting.
	 */
	const DEFAULT_OCCASION_WINDOW_SECONDS = 300;

	/**
	 * Character limits of the am_events VARCHAR columns written from
	 * caller-supplied text (see AM_Schema::create_or_upgrade_tables()).
	 * Enforced here because $wpdb->insert() doesn't truncate an over-long
	 * value -- it refuses the whole row and returns false, so a fatal
	 * error's stack trace or a long post title would otherwise silently
	 * lose the event entirely.
	 */
	const COLUMN_LIMITS = array(
		'user_login'        => 60,
		'user_display_name' => 250,
		'user_role'         => 100,
		'ip_address'        => 45,
		'event_type'        => 100,
		'action'            => 100,
		'object_type'       => 100,
		'object_name'       => 250,
		'message'           => 255,
	);

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
			// Every event that isn't routine says so for itself, by passing
			// 'level' explicitly -- that is the only mechanism there is.
			// There used to be an AM_Log_Levels::default_for_event_type()
			// consulted here, backed by a table keyed on 'post.delete',
			// 'plugin.update' and the like; those keys are type.action pairs,
			// but $event_type is only ever the type half ('post', 'plugin'),
			// so not one of them could ever match and the lookup always
			// returned INFO. Removed rather than re-keyed: the loggers
			// already set their own levels, and a second place to define
			// them would just be somewhere for the two to disagree.
			'level'       => AM_Log_Levels::INFO,
			'object_type' => '',
			'object_id'   => 0,
			'object_name' => '',
			'context'     => array(),
			'group'       => true,
			// Explicit override for the rare case where the caller knows
			// something ambient detection can't -- currently only the
			// plugin/theme/core update loggers, which alone can see
			// whether an upgrader run was unattended (see
			// AM_Initiator_Detector::AUTO_UPDATE). Null means "detect as
			// usual"; every other caller leaves this at the default.
			'initiator'   => null,
			// Explicit acting user (a user ID), for the rare hook that
			// fires after core has already cleared the current user --
			// currently only wp_logout (see AM_Logger_Users::on_logout()).
			// Null means "whoever is logged in"; every other caller leaves
			// this at the default.
			'user_id'     => null,
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

		$initiator = ( null !== $args['initiator'] && in_array( $args['initiator'], AM_Initiator_Detector::all(), true ) )
			? $args['initiator']
			: AM_Initiator_Detector::detect();
		$user = null !== $args['user_id'] ? get_userdata( absint( $args['user_id'] ) ) : wp_get_current_user();
		if ( ! $user ) {
			$user = new WP_User( 0 );
		}
		$events_table = $wpdb->prefix . AM_Schema::EVENTS_TABLE;
		$full_message = sanitize_textarea_field( $message );

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
			'message'           => $full_message,
			'occasion_id'       => null,
			'repeat_count'      => 1,
		);
		foreach ( self::COLUMN_LIMITS as $column => $limit ) {
			$row[ $column ] = self::fit( $row[ $column ], $limit );
		}

		// Nothing is lost by the truncation above: the untruncated message
		// goes into context, where the Details modal reads it back.
		if ( $row['message'] !== $full_message ) {
			$args['context']['full_message'] = $full_message;
		}

		if ( $args['group'] ) {
			$row['occasion_id'] = self::compute_occasion_id( $row );

			if ( self::maybe_increment_existing( $row['occasion_id'] ) ) {
				return false; // Collapsed into an existing row — no new event id, nothing further to write.
			}
		}

		$wpdb->insert( $events_table, $row );
		$event_id = $wpdb->insert_id;

		if ( $event_id && ! empty( $args['context'] ) ) {
			self::write_context( $event_id, $args['context'] );
		}

		// Notifications fire from here, and only here. They were once
		// wired to the legacy AM_Logger::log() path instead, which every
		// event source stopped using as it was ported onto this writer --
		// silently making notifications dead for every ported event. Only
		// genuine new-row inserts reach this point: an occasion-grouped
		// repeat returns early above, so a brute-force burst doesn't spam
		// a notification per attempt.
		if ( $event_id && ! $args['skip_notify'] ) {
			AM_Notifications::maybe_notify(
				$row['level'],
				$row['event_type'],
				$row['action'],
				$full_message,
				array(
					'user_login'        => $row['user_login'],
					'user_display_name' => $row['user_display_name'],
					'ip_address'        => $row['ip_address'],
					'object_name'       => $row['object_name'],
					'event_id'          => $event_id,
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

		// Explicit default in the get_option() call rather than relying on
		// register_setting()'s: that only registers its default_option_*
		// filter from admin_init, and plenty of events (failed logins,
		// comments, cron) are written on requests that never reach the
		// admin at all.
		$window = (int) apply_filters(
			'am_occasion_window_seconds',
			(int) get_option( 'am_occasion_window_seconds', self::DEFAULT_OCCASION_WINDOW_SECONDS )
		);

		if ( $window <= 0 ) {
			return false; // Grouping turned off -- every occurrence gets its own row.
		}

		$existing_id = $wpdb->get_var( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
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
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
			"UPDATE `{$events_table}` SET repeat_count = repeat_count + 1, date = %s WHERE id = %d",
			current_time( 'mysql', true ),
			$existing_id
		) );

		return true;
	}

	/**
	 * What counts as "the same event again". object_name and user_id are
	 * part of the key, not just object_id: many loggers have no object id
	 * to give (plugins, themes, failed logins, access-denied), so keying on
	 * object_id alone collapsed every one of those into whichever row came
	 * first -- a bulk update of ten plugins read as the first plugin
	 * updated ten times, and failed logins for different usernames, or
	 * denied pages hit by different users, merged into one row naming only
	 * the first. The IP is deliberately left out, so a distributed
	 * brute-force burst against one username still collapses to one row.
	 */
	private static function compute_occasion_id( array $row ): string {
		return md5( implode( '|', array(
			$row['event_type'],
			$row['action'],
			$row['object_id'],
			$row['object_name'],
			$row['user_id'],
			$row['initiator'],
		) ) );
	}

	/** Truncates to a column's character limit, marking the cut with an ellipsis. */
	private static function fit( string $value, int $limit ): string {
		if ( mb_strlen( $value ) <= $limit ) {
			return $value;
		}
		return mb_substr( $value, 0, $limit - 1 ) . '…';
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
	 * IP resolution — AM_DB_Legacy_IP::resolve() is ported as-is from v1.x
	 * AM_DB::get_ip() (Cloudflare CIDR-validated, X-Forwarded-For
	 * intentionally not trusted) and is deliberately still untouched; this
	 * logic was already security-reviewed in v1.3.0. What's layered on top
	 * is the am_ip_storage privacy setting, applied to the resolved address
	 * on the way into the row:
	 *
	 *   'full'       store the address as resolved (the default, and what
	 *                every version before this one did unconditionally)
	 *   'anonymized' mask the host part via WordPress's own
	 *                wp_privacy_anonymize_ip(), so entries stay groupable
	 *                by network without identifying a device
	 *   'none'       store nothing at all
	 *
	 * Applied at write time, not at display time -- the point of the
	 * setting is that the address never reaches the database, so an
	 * existing log is unaffected by changing it.
	 */
	private static function get_ip(): string {
		// Explicit default for the same reason as the grouping window above:
		// most log writes happen outside the admin, where register_setting()
		// has not run.
		$mode = (string) get_option( 'am_ip_storage', 'full' );

		if ( 'none' === $mode ) {
			return '';
		}

		$ip = AM_DB_Legacy_IP::resolve();

		return 'anonymized' === $mode ? (string) wp_privacy_anonymize_ip( $ip ) : $ip;
	}
}
