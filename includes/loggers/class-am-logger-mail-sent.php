<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_Mail_Sent — logs every successful wp_mail() handoff via the
 * wp_mail_succeeded action (core since 5.9, so always present at this
 * plugin's 6.0 floor). Companion to AM_Logger_Mail_Failures, which only
 * covers the failure side.
 *
 * "Succeeded" here means the same thing it means to wp_mail() itself: the
 * message was handed off to the configured mail transport without
 * PHPMailer erroring. It is not proof of inbox delivery -- see the longer
 * explanation on AM_Logger_Mail_Failures, which applies equally here.
 *
 * skip_notify is required, not optional: AM_Notifications::send_email()
 * sends its own alert emails through wp_mail(), so a successful alert
 * send would otherwise fire this logger, which (without skip_notify)
 * could trigger maybe_notify() again for any channel watching 'system'
 * events, sending another email, succeeding, and logging again --
 * unbounded. Same class of re-entrancy AM_Notifications::log_slack_failure()
 * and AM_Logger_Mail_Failures already guard against.
 *
 * Volume note: this fires for every plugin on the site that calls
 * wp_mail() -- WooCommerce order emails, form-plugin notifications, etc.
 * -- not just this plugin's own alerts or WordPress's own transactional
 * mail. There's no per-source filter here, by design (see the removed
 * per-logger Event Sources toggle in CLAUDE.md); a high-mail-volume site
 * will see a correspondingly high volume of these rows.
 */
class AM_Logger_Mail_Sent extends AM_Logger_Base {

	public function register_hooks() {
		add_action( 'wp_mail_succeeded', array( $this, 'on_mail_succeeded' ) );
	}

	/** @param array $mail_data compact('to','subject','message','headers','attachments') from wp_mail(). */
	public function on_mail_succeeded( $mail_data ) {
		if ( ! is_array( $mail_data ) ) {
			return;
		}

		$to      = isset( $mail_data['to'] ) ? ( is_array( $mail_data['to'] ) ? implode( ', ', $mail_data['to'] ) : (string) $mail_data['to'] ) : '';
		$subject = isset( $mail_data['subject'] ) ? (string) $mail_data['subject'] : '';

		$this->log(
			'system',
			'mail_sent',
			sprintf(
				/* translators: 1: recipient(s), 2: email subject */
				__( 'Email sent to %1$s: "%2$s"', 'activity-monitor' ),
				$to ?: __( '(unknown recipient)', 'activity-monitor' ),
				$subject
			),
			array(
				'level'       => AM_Log_Levels::INFO,
				'object_type' => 'email',
				'object_name' => $to,
				// Different sends (different recipients/subjects) shouldn't
				// collapse into one row -- same reasoning as
				// AM_Logger_Mail_Failures/File_Editor/Fatal_Errors.
				'group'       => false,
				'skip_notify' => true,
			)
		);
	}
}
