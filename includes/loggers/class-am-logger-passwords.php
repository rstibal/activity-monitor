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

	/**
	 * User ID whose reset is in progress this request -- see
	 * on_password_set() for why.
	 *
	 * @var int
	 */
	private $resetting_user_id = 0;

	public function register_hooks() {
		add_action( 'password_reset', array( $this, 'on_password_reset' ), 10, 2 );
		add_action( 'retrieve_password', array( $this, 'on_password_retrieve' ) );
		add_action( 'wp_set_password', array( $this, 'on_password_set' ), 10, 3 );
		add_action( 'profile_update', array( $this, 'on_profile_update' ), 10, 2 );
	}

	public function on_password_reset( WP_User $user, string $new_password ) {
		$this->resetting_user_id = (int) $user->ID;

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

	/**
	 * wp_set_password() is called in three situations, and only one of
	 * them is a password being set by hand:
	 *
	 *  - reset_password() fires password_reset and then calls
	 *    wp_set_password() for the same user. on_password_reset() has
	 *    already logged that, so it's skipped here -- otherwise every reset
	 *    is two rows.
	 *  - Since WordPress 6.8, a successful login whose stored hash uses an
	 *    outdated algorithm is re-hashed by calling wp_set_password() with
	 *    the password just typed. The password hasn't changed, so if it
	 *    still matches the previous hash, nothing is logged.
	 *    $old_user_data only exists on WordPress versions that have that
	 *    rehash; older ones don't pass it and never rehash, so a missing
	 *    value just means "log it".
	 *  - Anything else (a plugin or WP-CLI calling it directly) is a real
	 *    change and is logged.
	 *
	 * @param WP_User|null $old_user_data
	 */
	public function on_password_set( string $password, int $user_id, $old_user_data = null ) {
		if ( $user_id === $this->resetting_user_id ) {
			$this->resetting_user_id = 0;
			return;
		}

		// Empty third argument on purpose: given a user ID,
		// wp_check_password() upgrades a legacy MD5 hash by calling
		// wp_set_password() itself, which would re-enter this method.
		if ( $old_user_data instanceof WP_User && wp_check_password( $password, $old_user_data->user_pass, '' ) ) {
			return;
		}

		$this->log_password_set( $user_id );
	}

	/**
	 * A password changed through wp_update_user() -- the Profile and Edit
	 * User screens, or any plugin calling it -- never reaches
	 * on_password_set(): wp_update_user() hashes the new password itself
	 * and never calls wp_set_password(). A different stored hash is the
	 * only trace it leaves, so it's caught here.
	 */
	public function on_profile_update( int $user_id, $old_user_data ) {
		$new_user_data = get_userdata( $user_id );
		if ( ! $old_user_data instanceof WP_User || ! $new_user_data ) {
			return;
		}
		if ( $old_user_data->user_pass !== $new_user_data->user_pass ) {
			$this->log_password_set( $user_id );
		}
	}

	private function log_password_set( int $user_id ) {
		$user = get_userdata( $user_id );
		$name = $user ? $user->user_login : "user-{$user_id}";

		$this->log(
			'user',
			'password_set',
			sprintf(
				/* translators: %s: username */
				__( 'Password changed for user "%s".', 'activity-monitor' ),
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
