<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_Users — login/logout, registration, profile updates, role
 * changes, deletion, multisite membership.
 *
 * Ported from v1.x AM_Hooks::on_login / on_login_failed / on_authenticate /
 * on_logout / on_user_register / on_profile_update / on_user_delete /
 * on_role_change / on_add_user_to_blog.
 *
 * Behavior changes from v1.x:
 *   - Profile-field changes go into event context as structured
 *     before/after pairs (email, display_name), same as AM_Logger_Posts,
 *     instead of a flat "changes" string array.
 *   - Failed logins participate in occasion grouping by default (a burst
 *     of failed logins for the same username collapses into one row with
 *     a repeat_count) — this replaces v1.x's Login Flood Guard concept
 *     from the competitive audit (Activity Log Pro) without needing a
 *     separate threshold setting; see activity-monitor-v2-spec.md §3.
 *
 * See class-am-logger-posts.php for the template this follows.
 */
class AM_Logger_Users extends AM_Logger_Base {

	public function slug(): string {
		return 'users';
	}

	public function label(): string {
		return __( 'Users & authentication', 'activity-monitor' );
	}

	public function register_hooks() {
		add_action( 'wp_login', array( $this, 'on_login' ), 10, 2 );
		add_action( 'wp_login_failed', array( $this, 'on_login_failed' ) );
		add_action( 'wp_logout', array( $this, 'on_logout' ) );
		add_action( 'user_register', array( $this, 'on_user_register' ) );
		add_action( 'profile_update', array( $this, 'on_profile_update' ), 10, 2 );
		add_action( 'delete_user', array( $this, 'on_user_delete' ) );
		add_action( 'set_user_role', array( $this, 'on_role_change' ), 10, 3 );
		add_action( 'add_user_to_blog', array( $this, 'on_add_user_to_blog' ), 10, 3 );
	}

	public function on_login( string $user_login, WP_User $user ) {
		$this->log(
			'user',
			'login',
			sprintf(
				/* translators: %s: username */
				__( 'User "%s" logged in.', 'activity-monitor' ),
				$user_login
			),
			array(
				'level'       => AM_Log_Levels::INFO,
				'object_type' => 'user',
				'object_id'   => $user->ID,
				'object_name' => $user_login,
				'group'       => false, // Each login is individually meaningful.
			)
		);
	}

	public function on_login_failed( string $username ) {
		$this->log(
			'user',
			'login_failed',
			sprintf(
				/* translators: %s: username */
				__( 'Failed login attempt for username "%s".', 'activity-monitor' ),
				$username
			),
			array(
				'level'       => AM_Log_Levels::WARNING,
				'object_type' => 'user',
				'object_name' => $username,
				// group defaults to true — a brute-force burst against the
				// same username collapses into one row with repeat_count.
			)
		);
	}

	public function on_logout() {
		$user = wp_get_current_user();
		if ( ! $user->exists() ) {
			return;
		}
		$this->log(
			'user',
			'logout',
			sprintf(
				/* translators: %s: username */
				__( 'User "%s" logged out.', 'activity-monitor' ),
				$user->user_login
			),
			array(
				'level'       => AM_Log_Levels::INFO,
				'object_type' => 'user',
				'object_id'   => $user->ID,
				'object_name' => $user->user_login,
				'group'       => false,
			)
		);
	}

	public function on_user_register( int $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$this->log(
			'user',
			'registered',
			sprintf(
				/* translators: %s: username */
				__( 'New user registered: "%s".', 'activity-monitor' ),
				$user->user_login
			),
			array(
				'level'       => AM_Log_Levels::NOTICE,
				'object_type' => 'user',
				'object_id'   => $user_id,
				'object_name' => $user->user_login,
			)
		);
	}

	public function on_profile_update( int $user_id, WP_User $old_data ) {
		$new_data = get_userdata( $user_id );
		if ( ! $new_data ) {
			return;
		}

		$diff = array();
		if ( $old_data->user_email !== $new_data->user_email ) {
			$diff['email'] = array( 'before' => $old_data->user_email, 'after' => $new_data->user_email );
		}
		if ( $old_data->display_name !== $new_data->display_name ) {
			$diff['display_name'] = array( 'before' => $old_data->display_name, 'after' => $new_data->display_name );
		}

		if ( empty( $diff ) ) {
			return;
		}

		$this->log(
			'user',
			'updated',
			sprintf(
				/* translators: 1: username, 2: comma-separated list of changed fields */
				__( 'User "%1$s" profile updated — %2$s.', 'activity-monitor' ),
				$new_data->user_login,
				implode( ', ', array_keys( $diff ) )
			),
			array(
				'level'       => AM_Log_Levels::NOTICE,
				'object_type' => 'user',
				'object_id'   => $user_id,
				'object_name' => $new_data->user_login,
				'context'     => array( 'diff' => $diff ),
				'group'       => false,
			)
		);
	}

	public function on_user_delete( int $user_id ) {
		$user = get_userdata( $user_id );
		$name = $user ? $user->user_login : "user-{$user_id}";

		$this->log(
			'user',
			'deleted',
			sprintf(
				/* translators: 1: username, 2: user ID */
				__( 'User "%1$s" (ID %2$d) deleted.', 'activity-monitor' ),
				$name,
				$user_id
			),
			array(
				'level'       => AM_Log_Levels::WARNING,
				'object_type' => 'user',
				'object_id'   => $user_id,
				'object_name' => $name,
			)
		);
	}

	public function on_role_change( int $user_id, string $role, array $old_roles ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$this->log(
			'user',
			'role_changed',
			sprintf(
				/* translators: 1: username, 2: old roles, 3: new role */
				__( 'User "%1$s" role changed from "%2$s" to "%3$s".', 'activity-monitor' ),
				$user->user_login,
				implode( ', ', $old_roles ),
				$role
			),
			array(
				'level'       => AM_Log_Levels::WARNING,
				'object_type' => 'user',
				'object_id'   => $user_id,
				'object_name' => $user->user_login,
				'context'     => array( 'diff' => array( 'role' => array( 'before' => implode( ', ', $old_roles ), 'after' => $role ) ) ),
				'group'       => false,
			)
		);
	}

	public function on_add_user_to_blog( int $user_id, string $role, int $blog_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$this->log(
			'user',
			'added_to_site',
			sprintf(
				/* translators: 1: username, 2: site ID, 3: role */
				__( 'User "%1$s" added to site ID %2$d with role "%3$s".', 'activity-monitor' ),
				$user->user_login,
				$blog_id,
				$role
			),
			array(
				'level'       => AM_Log_Levels::NOTICE,
				'object_type' => 'user',
				'object_id'   => $user_id,
				'object_name' => $user->user_login,
			)
		);
	}
}
