<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Hub_Reporter — the sending side of the Active Installs hub feature.
 * When "Report to a Hub" is configured, this site POSTs its own site URL
 * and version info to another install's AM_Hub_Receiver endpoint once an
 * hour via WP-Cron.
 *
 * Outbound HTTP shape mirrors AM_Notifications::send_slack(): wp_remote_post()
 * with a short timeout, is_wp_error() then response-code checks, and a
 * masked-URL failure logged via AM_Event_Writer on either failure path.
 * Unlike the hub-receiver side, failures here ARE logged to the Activity
 * Log -- this is a genuine problem on this site's own outbound action, not
 * background internet noise.
 */
class AM_Hub_Reporter {

	const CRON_HOOK = 'am_hub_checkin';

	/**
	 * Schedules the hourly check-in if reporting is enabled and nothing is
	 * scheduled yet; unschedules it if reporting was turned off but a
	 * cron event is still pending. Unlike AM_Traffic_Rollup::reschedule()
	 * (which only ever schedules), this feature has a real on/off switch
	 * and this plugin has no deactivation hook, so toggling it off has to
	 * actively clear the schedule here.
	 */
	public static function reschedule() {
		$enabled   = (bool) get_option( 'am_report_enabled' );
		$scheduled = wp_next_scheduled( self::CRON_HOOK );

		if ( $enabled && ! $scheduled ) {
			wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
		} elseif ( ! $enabled && $scheduled ) {
			wp_unschedule_event( $scheduled, self::CRON_HOOK );
		}
	}

	/** Sends one check-in. Returns true on success, false otherwise. */
	public static function run() {
		$hub_url = trim( (string) get_option( 'am_report_hub_url', '' ) );
		$secret  = trim( (string) get_option( 'am_report_secret', '' ) );

		if ( '' === $hub_url || '' === $secret ) {
			return false;
		}

		$payload = array(
			'site_url'       => home_url(),
			'plugin_version' => AM_VERSION,
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
		);

		$response = wp_remote_post( $hub_url, array(
			'timeout' => 5,
			'headers' => array(
				'Content-Type'                  => 'application/json',
				AM_Hub_Receiver::SECRET_HEADER  => $secret,
			),
			'body'    => wp_json_encode( $payload ),
		) );

		update_option( 'am_report_last_attempt', current_time( 'mysql', true ) );

		if ( is_wp_error( $response ) ) {
			$reason = $response->get_error_message();
			update_option( 'am_report_last_status', 'error' );
			update_option( 'am_report_last_message', $reason );
			self::log_failure( $hub_url, $reason );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			$reason = 'HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response );
			update_option( 'am_report_last_status', 'error' );
			update_option( 'am_report_last_message', $reason );
			self::log_failure( $hub_url, $reason );
			return false;
		}

		update_option( 'am_report_last_status', 'success' );
		update_option( 'am_report_last_message', '' );
		return true;
	}

	/**
	 * Records a hub check-in failure to the Activity Log, matching
	 * AM_Notifications::log_slack_failure()'s shape. Never logs the
	 * secret -- it's a bearer credential -- only a masked hint of the
	 * hub URL for identifying which hub failed.
	 */
	private static function log_failure( $hub_url, $reason ) {
		$masked = substr( $hub_url, 0, 40 ) . '…';

		AM_Event_Writer::log(
			'system',
			'hub_checkin_failed',
			sprintf(
				/* translators: 1: masked hub URL, 2: error reason */
				__( 'Hub check-in failed (hub %1$s): %2$s', 'activity-monitor' ),
				$masked,
				$reason
			),
			array(
				'level'       => AM_Log_Levels::ERROR,
				'object_type' => 'hub',
				'group'       => false,
				'skip_notify' => true,
			)
		);
	}
}
