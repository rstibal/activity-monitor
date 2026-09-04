<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_Rest_Api — application password lifecycle and failed
 * application-password authentication.
 *
 * Deliberately scoped to application passwords rather than generic REST
 * traffic: logging every unauthenticated REST hit would just be bot noise,
 * the same reasoning AM_Logger_Security uses to watch a short list of
 * restricted admin pages rather than every denied request.
 *
 * wp_update_application_password is NOT hooked here. Core fires it both
 * when a password's name/permissions are edited AND every time
 * WP_Application_Passwords::record_application_password_usage() bumps
 * last_used/last_ip on a successful authenticated request — so hooking it
 * would log a row on every single REST call an integration makes. There's
 * no cheap way to tell the two apart from the hook's arguments alone, so
 * this logger only covers create/revoke/revoke-all, which are unambiguous.
 */
class AM_Logger_Rest_Api extends AM_Logger_Base {

	public function register_hooks() {
		add_action( 'wp_create_application_password', array( $this, 'on_created' ), 10, 3 );
		add_action( 'wp_delete_application_password', array( $this, 'on_revoked' ), 10, 2 );
		add_action( 'wp_delete_application_passwords', array( $this, 'on_revoked_all' ) );
		add_action( 'application_password_failed_authentication', array( $this, 'on_auth_failed' ) );
	}

	public function on_created( int $user_id, array $new_item, string $new_password ) {
		$user = get_userdata( $user_id );
		$name = $user ? $user->user_login : "user-{$user_id}";
		$app_name = isset( $new_item['name'] ) ? $new_item['name'] : '';

		$this->log(
			'user',
			'application_password_created',
			sprintf(
				/* translators: 1: application password name, 2: username */
				__( 'Application password "%1$s" created for user "%2$s".', 'activity-monitor' ),
				$app_name,
				$name
			),
			array(
				'level'       => AM_Log_Levels::NOTICE,
				'object_type' => 'user',
				'object_id'   => $user_id,
				'object_name' => $app_name,
				'group'       => false,
			)
		);
	}

	public function on_revoked( int $user_id, array $item ) {
		$user = get_userdata( $user_id );
		$name = $user ? $user->user_login : "user-{$user_id}";
		$app_name = isset( $item['name'] ) ? $item['name'] : '';

		$this->log(
			'user',
			'application_password_revoked',
			sprintf(
				/* translators: 1: application password name, 2: username */
				__( 'Application password "%1$s" revoked for user "%2$s".', 'activity-monitor' ),
				$app_name,
				$name
			),
			array(
				'level'       => AM_Log_Levels::WARNING,
				'object_type' => 'user',
				'object_id'   => $user_id,
				'object_name' => $app_name,
				'group'       => false,
			)
		);
	}

	public function on_revoked_all( int $user_id ) {
		$user = get_userdata( $user_id );
		$name = $user ? $user->user_login : "user-{$user_id}";

		$this->log(
			'user',
			'application_password_revoked_all',
			sprintf(
				/* translators: %s: username */
				__( 'All application passwords revoked for user "%s".', 'activity-monitor' ),
				$name
			),
			array(
				'level'       => AM_Log_Levels::WARNING,
				'object_type' => 'user',
				'object_id'   => $user_id,
				'object_name' => $name,
				'group'       => false,
			)
		);
	}

	public function on_auth_failed( WP_Error $error ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- read-only, sanitized below before use.
		$attempted_login = isset( $_SERVER['PHP_AUTH_USER'] ) ? sanitize_user( wp_unslash( $_SERVER['PHP_AUTH_USER'] ), true ) : '';

		$this->log(
			'security',
			'rest_auth_failed',
			'' !== $attempted_login
				? sprintf(
					/* translators: %s: attempted username */
					__( 'Failed application password authentication for "%s".', 'activity-monitor' ),
					$attempted_login
				)
				: __( 'Failed application password authentication.', 'activity-monitor' ),
			array(
				'level'       => AM_Log_Levels::WARNING,
				'object_type' => 'security',
				'object_name' => $attempted_login,
				// group defaults to true — a brute-force burst against the
				// same login collapses into one row with a rising
				// repeat_count, same as AM_Logger_Users::on_login_failed().
			)
		);
	}
}
