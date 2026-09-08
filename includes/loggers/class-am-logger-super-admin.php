<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_Super_Admin — multisite network super admin grant/revoke.
 *
 * Super admin is the highest privilege level in a multisite network,
 * above any single site's Administrator role, so a change here is a
 * CRITICAL event — same tier as AM_Logger_Sites' site deletion.
 *
 * Only registers on multisite installs, same guard as AM_Logger_Sites.
 * See class-am-logger-posts.php for the template this follows.
 */
class AM_Logger_Super_Admin extends AM_Logger_Base {

	public function register_hooks() {
		if ( ! is_multisite() ) {
			return;
		}
		add_action( 'grant_super_admin', array( $this, 'on_granted' ) );
		add_action( 'revoke_super_admin', array( $this, 'on_revoked' ) );
	}

	public function on_granted( int $user_id ) {
		$user = get_userdata( $user_id );
		$name = $user ? $user->user_login : "user-{$user_id}";

		$this->log(
			'user',
			'super_admin_granted',
			sprintf(
				/* translators: %s: username */
				__( 'Super admin granted to "%s".', 'activity-monitor' ),
				$name
			),
			array(
				'level'       => AM_Log_Levels::CRITICAL,
				'object_type' => 'user',
				'object_id'   => $user_id,
				'object_name' => $name,
			)
		);
	}

	public function on_revoked( int $user_id ) {
		$user = get_userdata( $user_id );
		$name = $user ? $user->user_login : "user-{$user_id}";

		$this->log(
			'user',
			'super_admin_revoked',
			sprintf(
				/* translators: %s: username */
				__( 'Super admin revoked from "%s".', 'activity-monitor' ),
				$name
			),
			array(
				'level'       => AM_Log_Levels::CRITICAL,
				'object_type' => 'user',
				'object_id'   => $user_id,
				'object_name' => $name,
			)
		);
	}
}
