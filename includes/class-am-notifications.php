<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Notifications — email alerts for events meeting a configured
 * minimum level.
 *
 * BUGFIX: v1.x's AM_Notifications::maybe_notify() was only ever called
 * from the legacy AM_Logger::log() (see the old class-am-logger.php).
 * Every event source was ported onto AM_Event_Writer over several
 * builds (dev.1 through dev.12), and nothing was ever added to call
 * notifications from the new write path -- so notifications have been
 * silently non-functional for every ported event type since the first
 * logger (posts) was converted. This rewrite wires the call into
 * AM_Event_Writer::log() directly, on the new schema, so it actually
 * fires again.
 *
 * Also removed at Rob's request: Slack channel support. Email only.
 *
 * Severity scale: v1.x used a 4-value int (Info/Notice/Warning/Critical)
 * with a simple ">=" comparison. This uses AM_Log_Levels' 8-value PSR-3
 * scale via AM_Log_Levels::meets_threshold().
 */
class AM_Notifications {

	/**
	 * Call this from AM_Event_Writer::log() after a new event row is
	 * written (not on an occasion-grouping repeat-count increment --
	 * see AM_Event_Writer, which only calls this on genuine new rows).
	 */
	public static function maybe_notify( string $level, string $event_type, string $action, string $message, array $args ) {
		$channels = get_option( 'am_notification_channels', array() );
		if ( empty( $channels ) ) {
			return;
		}

		foreach ( $channels as $channel ) {
			$min_level = isset( $channel['level'] ) ? (string) $channel['level'] : AM_Log_Levels::CRITICAL;
			if ( ! AM_Log_Levels::is_valid( $min_level ) ) {
				$min_level = AM_Log_Levels::CRITICAL;
			}
			if ( ! AM_Log_Levels::meets_threshold( $level, $min_level ) ) {
				continue;
			}

			self::send_email( $channel, $level, $event_type, $action, $message, $args );
		}
	}

	private static function send_email( array $channel, string $level, string $event_type, string $action, string $message, array $args ) {
		$raw_recipients = $channel['recipients'] ?? '';
		$recipients     = array_filter( array_map( 'trim', explode( ',', $raw_recipients ) ) );
		if ( empty( $recipients ) ) {
			return;
		}

		$site    = get_bloginfo( 'name' );
		$label   = AM_Log_Levels::label( $level );
		$user    = $args['user_login'] ?? 'unknown';
		$ip      = $args['ip_address'] ?? AM_DB_Legacy_IP::resolve();
		/* translators: 1: site name, 2: log level label, 3: event type, 4: event action */
		$subject = sprintf( __( '[%1$s] Activity Monitor Alert – %2$s: %3$s.%4$s', 'activity-monitor' ), $site, $label, $event_type, $action );

		// FIX #7 (carried forward from v1.x): strip tags from all
		// user-derived values before interpolating into the plain-text
		// email body. Prevents crafted log messages or usernames from
		// injecting header-like content or misleading text.
		$safe_site    = wp_strip_all_tags( $site );
		$safe_label   = wp_strip_all_tags( $label );
		$safe_type    = wp_strip_all_tags( $event_type . '.' . $action );
		$safe_user    = wp_strip_all_tags( (string) $user );
		$safe_ip      = wp_strip_all_tags( (string) $ip );
		$safe_message = wp_strip_all_tags( (string) $message );
		$safe_object  = wp_strip_all_tags( (string) ( $args['object_name'] ?? '' ) );

		$body  = __( 'Activity Monitor Alert', 'activity-monitor' ) . "\n";
		$body .= str_repeat( '─', 50 ) . "\n\n";
		/* translators: %s: site name */
		$body .= sprintf( __( 'Site:         %s', 'activity-monitor' ), $safe_site ) . "\n";
		/* translators: %s: log level label */
		$body .= sprintf( __( 'Level:        %s', 'activity-monitor' ), $safe_label ) . "\n";
		/* translators: %s: event type and action, e.g. "post.updated" */
		$body .= sprintf( __( 'Event:        %s', 'activity-monitor' ), $safe_type ) . "\n";
		/* translators: %s: date and time in UTC */
		$body .= sprintf( __( 'Time:         %s (UTC)', 'activity-monitor' ), current_time( 'Y-m-d H:i:s' ) ) . "\n";
		/* translators: %s: WordPress username */
		$body .= sprintf( __( 'User:         %s', 'activity-monitor' ), $safe_user ) . "\n";
		/* translators: %s: IP address */
		$body .= sprintf( __( 'IP Address:   %s', 'activity-monitor' ), $safe_ip ) . "\n";
		if ( '' !== $safe_object ) {
			/* translators: %s: name of the object the event happened to, e.g. a post title */
			$body .= sprintf( __( 'Object:       %s', 'activity-monitor' ), $safe_object ) . "\n";
		}
		/* translators: %s: the event's human-readable log message */
		$body .= "\n" . sprintf( __( "Message:\n%s", 'activity-monitor' ), $safe_message ) . "\n\n";
		$body .= str_repeat( '─', 50 ) . "\n";
		/* translators: %s: URL to the full activity log */
		$body .= sprintf( __( 'View full log: %s', 'activity-monitor' ), admin_url( 'admin.php?page=activity-monitor' ) ) . "\n";

		wp_mail( $recipients, $subject, $body );
	}
}
