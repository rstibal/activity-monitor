<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_Privacy — WordPress's personal data export / erase requests
 * (Tools → Export/Erase Personal Data, and the GDPR-style request flow).
 *
 * A request is a `user_request` post whose status moves request-pending →
 * request-confirmed → request-completed (or request-failed); post_name says
 * which kind ('export_personal_data' / 'remove_personal_data') and post_title
 * is the requester's email. Those transitions are the request's lifecycle, so
 * that's what is logged. The two actions that actually do the work — an
 * export archive being generated and personal data being erased — have their
 * own core hooks and get their own rows, since "completed" alone doesn't say
 * data left the site or was destroyed.
 *
 * AM_Logger_Posts and AM_Logger_Post_Details skip user_request posts, or each
 * transition would be logged a second time as a generic post edit.
 */
class AM_Logger_Privacy extends AM_Logger_Base {

	const STATUS_ACTIONS = array(
		'request-pending'   => 'request_created',
		'request-confirmed' => 'request_confirmed',
		'request-completed' => 'request_completed',
		'request-failed'    => 'request_failed',
	);

	public function register_hooks() {
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( 'wp_privacy_personal_data_export_file_created', array( $this, 'on_export_created' ), 10, 4 );
		add_action( 'wp_privacy_personal_data_erased', array( $this, 'on_erased' ) );
	}

	public function on_transition( string $new_status, string $old_status, WP_Post $post ) {
		if ( 'user_request' !== $post->post_type || $new_status === $old_status || ! isset( self::STATUS_ACTIONS[ $new_status ] ) ) {
			return;
		}

		$is_erase = 'remove_personal_data' === $post->post_name;
		$action   = self::STATUS_ACTIONS[ $new_status ];
		$kind     = $is_erase ? __( 'erase', 'activity-monitor' ) : __( 'export', 'activity-monitor' );

		$labels = array(
			/* translators: 1: "export" or "erase", 2: requester email */
			'request_created'   => __( 'Personal data %1$s request created for "%2$s".', 'activity-monitor' ),
			/* translators: 1: "export" or "erase", 2: requester email */
			'request_confirmed' => __( 'Personal data %1$s request confirmed for "%2$s".', 'activity-monitor' ),
			/* translators: 1: "export" or "erase", 2: requester email */
			'request_completed' => __( 'Personal data %1$s request completed for "%2$s".', 'activity-monitor' ),
			/* translators: 1: "export" or "erase", 2: requester email */
			'request_failed'    => __( 'Personal data %1$s request failed for "%2$s".', 'activity-monitor' ),
		);

		$level = AM_Log_Levels::NOTICE;
		if ( 'request_failed' === $action || ( $is_erase && 'request_completed' === $action ) ) {
			$level = AM_Log_Levels::WARNING;
		}

		$this->log(
			'privacy',
			$action,
			sprintf( $labels[ $action ], $kind, $post->post_title ),
			array(
				'level'       => $level,
				'object_type' => 'privacy_request',
				'object_id'   => (int) $post->ID,
				'object_name' => $post->post_title,
				'group'       => false,
			)
		);
	}

	/**
	 * @param string $archive_pathname
	 * @param string $archive_url
	 * @param string $html_report_pathname
	 * @param int    $request_id
	 */
	public function on_export_created( $archive_pathname, $archive_url, $html_report_pathname, $request_id ) {
		/* translators: %s: requester email */
		$this->log_work( 'export_generated', (int) $request_id, AM_Log_Levels::NOTICE, __( 'Personal data export file generated for "%s".', 'activity-monitor' ) );
	}

	public function on_erased( $request_id ) {
		/* translators: %s: requester email */
		$this->log_work( 'data_erased', (int) $request_id, AM_Log_Levels::WARNING, __( 'Personal data erased for "%s".', 'activity-monitor' ) );
	}

	private function log_work( string $action, int $request_id, string $level, string $format ) {
		$request = get_post( $request_id );
		$email   = $request ? $request->post_title : '';

		$this->log(
			'privacy',
			$action,
			sprintf( $format, $email ),
			array(
				'level'       => $level,
				'object_type' => 'privacy_request',
				'object_id'   => $request_id,
				'object_name' => $email,
				'group'       => false,
			)
		);
	}
}
