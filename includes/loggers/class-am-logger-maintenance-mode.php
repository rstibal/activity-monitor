<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_Maintenance_Mode — detects WordPress's maintenance mode
 * (the `.maintenance` file in the site root) being toggled on or off.
 *
 * WordPress itself creates this file automatically during core/plugin/
 * theme updates (and removes it when the update finishes), and some
 * maintenance-mode plugins use the same mechanism. There's no WP hook
 * for "maintenance mode changed" -- it's a plain file whose presence
 * WP_Maintenance() checks directly, very early in the request, before
 * most of WordPress (including the hook system) has loaded. That means
 * this can't be caught at the moment it happens; instead, this compares
 * the file's current presence against the last-known state (stored in
 * an option) on every admin_init, and logs only on a transition.
 *
 * Deliberately admin-side only (admin_init, not an earlier front-end
 * hook): while maintenance mode is active, WordPress serves a 503 page
 * to nearly everything before plugins even load, so front-end requests
 * during that window won't reach this class anyway. The transition
 * itself is still reliably caught here because admin_init fires for
 * the admin's own dashboard loads shortly before/after the toggle.
 */
class AM_Logger_Maintenance_Mode extends AM_Logger_Base {

	const STATE_OPTION = 'am_maintenance_mode_last_state';

	public function slug(): string {
		return 'maintenance_mode';
	}

	public function label(): string {
		return __( 'Maintenance mode', 'activity-monitor' );
	}

	public function register_hooks() {
		add_action( 'admin_init', array( $this, 'on_admin_init' ) );
	}

	public function on_admin_init() {
		$is_active  = file_exists( ABSPATH . '.maintenance' );
		$last_state = get_option( self::STATE_OPTION, false );

		if ( $is_active === $last_state ) {
			return;
		}

		update_option( self::STATE_OPTION, $is_active );

		$this->log(
			'system',
			$is_active ? 'maintenance_enabled' : 'maintenance_disabled',
			$is_active
				? __( 'Site entered maintenance mode.', 'activity-monitor' )
				: __( 'Site exited maintenance mode.', 'activity-monitor' ),
			array(
				'level'       => AM_Log_Levels::NOTICE,
				'object_type' => 'site',
			)
		);
	}
}
