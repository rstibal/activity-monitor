<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Notifications — alerts (email and Slack) for events meeting a
 * configured minimum level.
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
				$user_label = self::format_user_label( $args['user_display_name'] ?? '', $args['user_login'] ?? '' );
				self::send_slack( $channel, $message, $user_label, (int) ( $args['event_id'] ?? 0 ) );
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

	private static function send_email( array $channel, string $level, string $event_type, string $action, string $message, array $args ) {
		$raw_recipients = $channel['recipients'] ?? '';
		$recipients     = array_filter( array_map( 'trim', explode( ',', $raw_recipients ) ) );
		if ( empty( $recipients ) ) {
			return;
		}

		$site    = get_bloginfo( 'name' );
		$label   = AM_Log_Levels::label( $level );
		$user    = self::format_user_label( $args['user_display_name'] ?? '', $args['user_login'] ?? '' ) ?: 'unknown';
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
		}
	}

	/**
	 * Posts a formatted alert to a Slack incoming webhook: the event
	 * message read as one sentence, with the site's domain itself
	 * linked to that specific event -- deep-linking straight to the
	 * Activity Log tab with an am_event_id query arg that admin.js
	 * picks up on page load to auto-open the Details modal for that
	 * event (see the am_event_id handling next to the
	 * .am-view-detail-v2 click handler), rather than just landing on
	 * the log tab and leaving the reader to find the row themselves.
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
	 * a " by {user} on {domain}." clause is appended after stripping the
	 * message's own trailing period, e.g. 'File "x" uploaded.' becomes
	 * 'File "x" uploaded by Rob Stibal (rstibal) on injurylawyers.com.'
	 * System-initiated events with no logged-in user (WP-Cron, WP-CLI,
	 * core, failed logins) drop the "by" clause and read '... on
	 * injurylawyers.com.' instead.
	 */
	private static function send_slack( array $channel, string $message, string $user_label, int $event_id ) {
		$webhook_url = trim( $channel['webhook_url'] ?? '' );
		if ( '' === $webhook_url ) {
			return;
		}

		$domain = wp_strip_all_tags( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$user   = wp_strip_all_tags( $user_label );
		$base   = rtrim( wp_strip_all_tags( (string) $message ), '.' );

		$log_url = add_query_arg(
			array( 'page' => 'activity-monitor', 'am_tab' => 'log', 'am_event_id' => $event_id ),
			admin_url( 'admin.php' )
		);
		$domain_link = "<{$log_url}|{$domain}>";

		$mrkdwn_msg = $base . ( ( '' !== $user ) ? " by {$user} on {$domain_link}." : " on {$domain_link}." );
		$plain_msg  = $base . ( ( '' !== $user ) ? " by {$user} on {$domain}." : " on {$domain}." );

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
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			self::log_slack_failure( $webhook_url, 'HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response ) );
		}
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
