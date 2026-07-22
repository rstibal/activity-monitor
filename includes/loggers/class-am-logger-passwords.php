<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_Passwords — password reset, retrieve (forgot-password request),
 * and manual set events.
 *
 * Ported from v1.x AM_Hooks::on_password_reset / on_password_retrieve /
 * on_password_set. These stay WARNING level (as in v1.x) since a password
 * change is meaningful security signal regardless of who initiated it.
 *
 * See class-am-logger-posts.php for the template this follows.
 */
class AM_Logger_Passwords extends AM_Logger_Base {

	public function slug(): string {
		return 'passwords';
	}

	public function label(): string {
		return __( 'Password changes', 'activity-monitor' );
	}

	public function register_hooks() {
		add_action( 'password_reset', array( $this, 'on_password_reset' ), 10, 2 );
		add_action( 'retrieve_password', array( $this, 'on_password_retrieve' ) );
		add_action( 'wp_set_password', array( $this, 'on_password_set' ), 10, 2 );
	}

	public function on_password_reset( WP_User $user, string $new_password ) {
		$this->log(
			'user',
			'password_reset',
			sprintf(
				/* translators: %s: username */
				__( 'Password reset for user "%s".', 'activity-monitor' ),
				$user->user_login
			),
			array(
				'level'       => AM_Log_Levels::WARNING,
				'object_type' => 'user',
				'object_id'   => $user->ID,
				'object_name' => $user->user_login,
				'group'       => false,
			)
		);
	}

	public function on_password_retrieve( string $user_login ) {
		$this->log(
			'user',
			'password_retrieve_requested',
			sprintf(
				/* translators: %s: username */
				__( 'Password reset email requested for "%s".', 'activity-monitor' ),
				$user_login
			),
			array(
				'level'       => AM_Log_Levels::WARNING,
				'object_type' => 'user',
				'object_name' => $user_login,
				// group defaults to true — repeated reset-request clicks
				// (e.g. a user re-submitting the "forgot password" form)
				// collapse into one row.
			)
		);
	}

	public function on_password_set( string $password, int $user_id ) {
		$user = get_userdata( $user_id );
		$name = $user ? $user->user_login : "user-{$user_id}";

		$this->log(
			'user',
			'password_set',
			sprintf(
				/* translators: %s: username */
				__( 'Password manually set for user "%s".', 'activity-monitor' ),
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
}
