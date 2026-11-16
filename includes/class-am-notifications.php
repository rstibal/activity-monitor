<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Notifications — alerts (email and Slack) for events meeting a
 * configured minimum level.
 *
 * maybe_notify() is called from AM_Event_Writer::log(), which is the
 * single write path. It used to be called from the legacy AM_Logger::log()
 * instead; every event source was ported onto AM_Event_Writer without
 * anything calling notifications from the new path, which left them
 * silently non-functional for every ported event type. Keep the call
 * where the rows are actually written.
 *
 * Slack support: previously removed at Rob's request (email-only for
 * a while); re-added per a later request for a delivery-confirmed
 * alternative to email, since wp_mail() success only means "handed
 * off to the mail transport," not "actually delivered" -- a Slack
 * incoming webhook POST gets an immediate, unambiguous HTTP
 * status/error string back, with no separate transport layer that can
 * silently swallow it the way SMTP/spam-filtering can with email.
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

			$type = $channel['type'] ?? 'email';
			if ( 'slack' === $type ) {
				$user_login = (string) ( $args['user_login'] ?? '' );
				$ip         = $args['ip_address'] ?? AM_DB_Legacy_IP::resolve();
				self::send_slack( $channel, $message, $user_login, (string) $ip, (int) ( $args['event_id'] ?? 0 ) );
			} else {
				self::send_email( $channel, $level, $event_type, $action, $message, $args );
			}
		}
	}

	/**
	 * "Display Name (login)" for a notification's acting user -- display
	 * name first since that's the name a person actually recognizes, login
	 * alongside it since that's the account of record. Empty when there's
	 * no logged-in user (system-initiated events), so callers can fall
	 * back to their own "unknown"/no-"by"-clause handling.
	 */
	private static function format_user_label( string $display_name, string $user_login ): string {
		if ( '' === $user_login ) {
			return '';
		}
		return '' !== $display_name ? "{$display_name} ({$user_login})" : $user_login;
	}

	/** @return string '' when the email was handed to the mail transport, otherwise why not. */
	private static function send_email( array $channel, string $level, string $event_type, string $action, string $message, array $args ): string {
		$raw_recipients = $channel['recipients'] ?? '';
		$recipients     = array_filter( array_map( 'trim', explode( ',', $raw_recipients ) ) );
		if ( empty( $recipients ) ) {
			return __( 'This channel has no recipients.', 'activity-monitor' );
		}

		$site    = get_bloginfo( 'name' );
		$label   = AM_Log_Levels::label( $level );
		$user    = self::format_user_label( $args['user_display_name'] ?? '', $args['user_login'] ?? '' ) ?: 'unknown';
		$ip      = $args['ip_address'] ?? AM_DB_Legacy_IP::resolve();
		/* translators: 1: site name, 2: log level label, 3: event type, 4: event action */
		$subject = sprintf( __( '[%1$s] Activity Monitor Alert – %2$s: %3$s.%4$s', 'activity-monitor' ), $site, $label, $event_type, $action );

		// Strip tags from every user-derived value before interpolating it
		// into the plain-text email body. Prevents a crafted log message or
		// username from injecting header-like content or misleading text.
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
		// Site time first, in the format the log itself uses, so the email
		// and the row it points at read the same; UTC kept alongside.
		$now   = time();
		/* translators: 1: date and time in the site's timezone, 2: the same moment in UTC */
		$body .= sprintf( __( 'Time:         %1$s (%2$s UTC)', 'activity-monitor' ), wp_date( AM_Date_Format::combined(), $now ), gmdate( 'Y-m-d H:i:s', $now ) ) . "\n";
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
		// Straight to this event, the same deep link the Slack alert carries
		// (admin.js opens its Details on arrival), then the log as a whole.
		$log_url  = admin_url( 'admin.php?page=' . AM_Admin::PAGE_LOG );
		$event_id = (int) ( $args['event_id'] ?? 0 );
		if ( $event_id > 0 ) {
			/* translators: %s: URL that opens this event in the activity log */
			$body .= sprintf( __( 'View this event: %s', 'activity-monitor' ), add_query_arg( 'am_event_id', $event_id, $log_url ) ) . "\n";
		}
		/* translators: %s: URL to the full activity log */
		$body .= sprintf( __( 'View full log: %s', 'activity-monitor' ), $log_url ) . "\n";

		$sent = wp_mail( $recipients, $subject, $body );

		// wp_mail_failed (see AM_Logger_Mail_Failures) fires independently
		// and covers the "why" in detail; this check just means a caller
		// inspecting maybe_notify()'s behavior (or a future admin-facing
		// status indicator) has something to act on rather than the
		// return value being silently discarded, which was the case
		// before this fix -- notification failures were previously
		// invisible everywhere, including in the Activity Log itself.
		if ( ! $sent ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- best-effort fallback; wp_mail_failed is the primary record.
			error_log( 'Activity Monitor: notification email to ' . $raw_recipients . ' failed to send.' );
			return __( 'WordPress could not send the email. The reason is in the Activity Log, under Email Delivery Failed.', 'activity-monitor' );
		}
		return '';
	}

	/**
	 * Sends one test alert through $channel, for the channel form's Send
	 * Test button, and says how it went: '' on success, otherwise the
	 * reason. Goes through the same send_email()/send_slack() a real alert
	 * does, so a pass here means the real path works -- for email, as far as
	 * "handed to the mail transport", which is all wp_mail() can ever say.
	 */
	public static function send_test( array $channel ): string {
		$user    = wp_get_current_user();
		$message = __( 'This is a test alert from Activity Monitor. If you can read it, this channel works.', 'activity-monitor' );

		if ( 'slack' === ( $channel['type'] ?? 'email' ) ) {
			return self::send_slack( $channel, $message, (string) $user->user_login, '', 0 );
		}

		$level = isset( $channel['level'] ) && AM_Log_Levels::is_valid( (string) $channel['level'] ) ? (string) $channel['level'] : AM_Log_Levels::CRITICAL;
		return self::send_email( $channel, $level, 'system', 'test', $message, array(
			'user_login'        => (string) $user->user_login,
			'user_display_name' => (string) $user->display_name,
			'ip_address'        => '',
		) );
	}

	/**
	 * Posts a formatted alert to a Slack incoming webhook: the event
	 * message read as one sentence, with the site's domain itself
	 * linked to that specific event -- deep-linking straight to the
	 * Activity Log screen with an am_event_id query arg that admin.js
	 * picks up on page load to auto-open the Details modal for that
	 * event (see the am_event_id handling next to the
	 * .am-view-detail-v2 click handler), rather than just landing on
	 * the log and leaving the reader to find the row themselves.
	 * 'text' is also set as the required plain-text fallback (Slack
	 * rejects a payload with blocks but no text, and 'text' is what
	 * shows in notifications/previews where blocks don't render) --
	 * it carries the same sentence without the link, since plain text
	 * can't express one.
	 *
	 * Unlike wp_mail(), a webhook POST gets an immediate, checkable
	 * result: HTTP 200 means Slack accepted and posted the message;
	 * anything else comes with a specific error string in the response
	 * body (e.g. 'channel_not_found', 'invalid_payload') -- both
	 * checked here and logged as a genuine, informative failure rather
	 * than assumed success, which is the exact gap Slack support is
	 * meant to close relative to email.
	 *
	 * The message is read as one sentence rather than "message + fields":
	 * " by {user_login} on {domain}" and " from {ip}" clauses are appended
	 * after stripping the message's own trailing period, e.g.
	 * 'File "x" uploaded.' becomes 'File "x" uploaded by rstibal on
	 * injurylawyers.com from 203.0.113.4.' System-initiated events with no
	 * logged-in user (WP-Cron, WP-CLI, core, failed logins) drop the "by"
	 * clause and read '... on injurylawyers.com from 203.0.113.4.' instead.
	 * The "from {ip}" clause itself drops out entirely when ip_address is
	 * '' (am_ip_storage set to anonymised/none -- see AM_Admin::ip_cell_html()
	 * for the same case handled on the Activity Log screen).
	 *
	 * user_login only, not "display_name (user_login)" -- unlike the email
	 * body and every other single-line context, display_name is dropped
	 * here on purpose: when a user's display name is left at its default
	 * (equal to their login), the combined form reads as the redundant
	 * "rstibal (rstibal)", and Rob wants this line uniform regardless of
	 * whether a given user bothered to set a distinct display name.
	 */
	/** @return string '' when Slack accepted the message, otherwise why not. */
	private static function send_slack( array $channel, string $message, string $user_login, string $ip, int $event_id ): string {
		$webhook_url = trim( $channel['webhook_url'] ?? '' );
		if ( '' === $webhook_url ) {
			return __( 'This channel has no webhook URL.', 'activity-monitor' );
		}

		$domain = wp_strip_all_tags( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$user   = wp_strip_all_tags( $user_login );
		$ip     = wp_strip_all_tags( $ip );
		$base   = rtrim( wp_strip_all_tags( (string) $message ), '.' );

		$log_url = add_query_arg(
			// No event to open for a test alert (event 0): link to the log.
			$event_id > 0 ? array( 'page' => AM_Admin::PAGE_LOG, 'am_event_id' => $event_id ) : array( 'page' => AM_Admin::PAGE_LOG ),
			admin_url( 'admin.php' )
		);
		$domain_link = "<{$log_url}|{$domain}>";
		$ip_clause   = ( '' !== $ip ) ? " from {$ip}" : '';

		$mrkdwn_msg = $base . ( ( '' !== $user ) ? " by {$user} on {$domain_link}" : " on {$domain_link}" ) . "{$ip_clause}.";
		$plain_msg  = $base . ( ( '' !== $user ) ? " by {$user} on {$domain}" : " on {$domain}" ) . "{$ip_clause}.";

		$blocks = array(
			array(
				'type' => 'section',
				'text' => array( 'type' => 'mrkdwn', 'text' => $mrkdwn_msg ),
			),
		);

		$response = wp_remote_post( $webhook_url, array(
			'timeout' => 5,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				// Required fallback text -- Slack rejects a blocks-only
				// payload with no top-level 'text', and this is also
				// what appears in notification previews where blocks
				// don't render.
				'text'   => $plain_msg,
				'blocks' => $blocks,
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			self::log_slack_failure( $webhook_url, $response->get_error_message() );
			return $response->get_error_message();
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			$reason = 'HTTP ' . $code . ': ' . wp_strip_all_tags( (string) wp_remote_retrieve_body( $response ) );
			self::log_slack_failure( $webhook_url, $reason );
			return $reason;
		}
		return '';
	}

	/**
	 * Records a Slack delivery failure to the Activity Log itself (not
	 * just error_log), matching the visibility AM_Logger_Mail_Failures
	 * gives email failures via wp_mail_failed -- there's no equivalent
	 * WP action for this since the webhook POST is this plugin's own
	 * code, not a WordPress core mail function, so it's logged
	 * directly here instead of via a separate hook-driven logger class.
	 */
	private static function log_slack_failure( string $webhook_url, string $reason ) {
		// Never log the webhook URL itself -- it's a bearer credential
		// (anyone with it can post to the channel), so only a masked
		// hint is kept for identifying which channel failed.
		$masked = substr( $webhook_url, 0, 40 ) . '…';

		AM_Event_Writer::log(
			'system',
			'slack_notification_failed',
			sprintf(
				/* translators: 1: masked webhook URL, 2: error reason */
				__( 'Slack notification failed (webhook %1$s): %2$s', 'activity-monitor' ),
				$masked,
				$reason
			),
			array(
				'level'       => AM_Log_Levels::ERROR,
				'object_type' => 'slack',
				'group'       => false,
				'skip_notify' => true,
			)
		);
	}
}
