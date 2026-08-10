<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_Mail_Failures — catches wp_mail() delivery failures via the
 * wp_mail_failed action, so a silently-failing notification/digest
 * email becomes visible in the Activity Log instead of just vanishing.
 *
 * This exists because of a real, confirmed gap: wp_mail() returns
 * false on failure, but AM_Notifications::send_email() (the instant
 * per-event email alerts) never checked that return value at all --
 * meaning even a hard PHPMailer failure produced no record anywhere.
 * AM_Digest::send()/send_test() DO check the return value correctly,
 * but neither wp_mail() nor this plugin can tell the difference
 * between "actually delivered" and "handed off to the mail transport,
 * which then silently dropped/bounced/spam-filtered it" -- wp_mail()
 * returning true only means the handoff succeeded, not that the
 * message reached an inbox. That second failure mode is invisible to
 * any code running inside WordPress and can't be fixed here; it needs
 * checking at the mail-provider/server level (SMTP logs, spam
 * folder, a dedicated SMTP plugin instead of PHP's mail()).
 *
 * What this DOES fix: the first failure mode, where wp_mail() itself
 * throws/returns false and nothing was recording why.
 */
class AM_Logger_Mail_Failures extends AM_Logger_Base {

	public function register_hooks() {
		add_action( 'wp_mail_failed', array( $this, 'on_mail_failed' ) );
	}

	/** @param WP_Error $error */
	public function on_mail_failed( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return;
		}

		$data = $error->get_error_data();
		$to   = isset( $data['to'] ) ? ( is_array( $data['to'] ) ? implode( ', ', $data['to'] ) : (string) $data['to'] ) : '';

		$this->log(
			'system',
			'mail_failed',
			sprintf(
				/* translators: 1: recipient(s), 2: PHPMailer error message */
				__( 'Failed to send email to %1$s: %2$s', 'activity-monitor' ),
				$to ?: __( '(unknown recipient)', 'activity-monitor' ),
				$error->get_error_message()
			),
			array(
				'level'       => AM_Log_Levels::ERROR,
				'object_type' => 'email',
				'object_name' => $to,
				// Different failures (different recipients, different
				// underlying errors) shouldn't collapse into one row --
				// same reasoning as AM_Logger_File_Editor/Fatal_Errors,
				// see those classes for the fuller explanation of why
				// occasion grouping needs object_id to work correctly
				// and why this logger can't supply one.
				'group'       => false,
				// Prevents a failing email notification channel from
				// re-triggering maybe_notify() on itself via this very
				// failure event -- same reasoning as
				// AM_Notifications::log_slack_failure(). wp_mail_failed
				// firing from inside wp_mail() (rather than this
				// plugin's own re-entrant call, as with the Slack case)
				// makes an actual infinite loop less likely here, but
				// leaving this unset would still mean every event that
				// would have notified the broken channel logs a second,
				// redundant mail_failed entry alongside it.
				'skip_notify' => true,
			)
		);
	}
}
