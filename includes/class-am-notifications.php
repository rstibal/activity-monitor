<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AM_Notifications {

	/**
	 * Called by the logger after every event is written.
	 * Checks each configured channel to see if severity threshold is met.
	 */
	public static function maybe_notify( int $severity, string $event_type, string $message, array $args ) {
		$channels = get_option( 'am_notification_channels', array() );
		if ( empty( $channels ) ) return;

		foreach ( $channels as $channel ) {
			$min_severity = absint( $channel['severity'] ?? AM_Logger::CRITICAL );
			if ( $severity < $min_severity ) continue;

			$type = $channel['type'] ?? '';

			if ( $type === 'email' ) {
				self::send_email( $channel, $severity, $event_type, $message, $args );
			} elseif ( $type === 'slack' ) {
				self::send_slack( $channel, $severity, $event_type, $message, $args );
			}
		}
	}

	// ── Email ─────────────────────────────────────────────────────────────────

	private static function send_email( array $channel, int $severity, string $event_type, string $message, array $args ) {
		$raw_recipients = $channel['recipients'] ?? '';
		$recipients = array_filter( array_map( 'trim', explode( ',', $raw_recipients ) ) );
		if ( empty( $recipients ) ) return;

		$site    = get_bloginfo( 'name' );
		$label   = AM_Logger::severity_label( $severity );
		$user    = $args['user_name'] ?? 'unknown';
		$ip      = $args['ip_address'] ?? AM_DB::get_ip();
		$subject = "[{$site}] Activity Monitor Alert — {$label}: {$event_type}";

		$body  = "Activity Monitor Alert\n";
		$body .= str_repeat( '─', 50 ) . "\n\n";
		$body .= "Site:        {$site}\n";
		$body .= "Severity:    {$label}\n";
		$body .= "Event:       {$event_type}\n";
		$body .= "Time:        " . current_time( 'Y-m-d H:i:s' ) . " (UTC)\n";
		$body .= "User:        {$user}\n";
		$body .= "IP Address:  {$ip}\n";
		if ( ! empty( $args['object_name'] ) ) {
			$body .= "Object:      {$args['object_name']}\n";
		}
		$body .= "\nMessage:\n{$message}\n\n";
		$body .= str_repeat( '─', 50 ) . "\n";
		$body .= "View full log: " . admin_url( 'admin.php?page=activity-monitor' ) . "\n";

		wp_mail( $recipients, $subject, $body );
	}

	// ── Slack ─────────────────────────────────────────────────────────────────

	private static function send_slack( array $channel, int $severity, string $event_type, string $message, array $args ) {
		$webhook = $channel['webhook_url'] ?? '';
		if ( empty( $webhook ) ) return;

		$label   = AM_Logger::severity_label( $severity );
		$site    = get_bloginfo( 'name' );
		$user    = $args['user_name'] ?? 'unknown';
		$ip      = $args['ip_address'] ?? AM_DB::get_ip();
		$object  = $args['object_name'] ?? '';

		$color_map = array(
			AM_Logger::INFO     => '#36a64f',
			AM_Logger::NOTICE   => '#2196f3',
			AM_Logger::WARNING  => '#ff9800',
			AM_Logger::CRITICAL => '#f44336',
		);
		$color = $color_map[ $severity ] ?? '#9e9e9e';

		$payload = array(
			'text'        => "*Activity Monitor Alert* — {$site}",
			'attachments' => array(
				array(
					'color'  => $color,
					'fields' => array(
						array( 'title' => 'Severity',   'value' => $label,       'short' => true ),
						array( 'title' => 'Event',      'value' => $event_type,  'short' => true ),
						array( 'title' => 'User',       'value' => $user,        'short' => true ),
						array( 'title' => 'IP Address', 'value' => $ip,          'short' => true ),
						array( 'title' => 'Object',     'value' => $object,      'short' => true ),
						array( 'title' => 'Time',       'value' => current_time( 'Y-m-d H:i:s' ) . ' UTC', 'short' => true ),
						array( 'title' => 'Message',    'value' => $message,     'short' => false ),
					),
					'footer' => 'Activity Monitor | ' . admin_url( 'admin.php?page=activity-monitor' ),
				),
			),
		);

		wp_remote_post( esc_url_raw( $webhook ), array(
			'headers'     => array( 'Content-Type' => 'application/json' ),
			'body'        => wp_json_encode( $payload ),
			'timeout'     => 10,
			'blocking'    => false,
		) );
	}
}
