<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_Capabilities — logs a capability granted or removed on a user
 * directly (WP_User::add_cap() / remove_cap()), independent of a role
 * change.
 *
 * AM_Logger_Users::on_role_change() (set_user_role) only catches a user's
 * *role* being swapped wholesale. A capability can also be granted or
 * revoked one at a time without touching the role at all -- e.g. a
 * compromised plugin (or a malicious admin) quietly granting themselves
 * `manage_options` while staying nominally an "Editor" -- and that path
 * left no trace anywhere in the log.
 *
 * Both paths write to the same place: the user's `{$wpdb->prefix}capabilities`
 * user meta, a serialized array of role-slug and/or capability-slug keys.
 * There's no dedicated "capability changed" hook, so this watches that meta
 * key directly via the update_user_meta action, which core fires just
 * before the row is written -- the old value can still be read from the
 * database at that point, which is what makes the diff possible.
 *
 * Scoping: a plain role reassignment rewrites this same meta key (WP_User::
 * set_role() calls update_user_meta() itself, then fires set_user_role) and
 * would otherwise double-log every role change here too. To avoid that,
 * this splits the before/after arrays into role-slug keys (from wp_roles())
 * and everything else, and only logs when the *non-role* keys differ.
 * A same-shape "assign exactly one role" write -- which is what a role
 * change and a raw meta write both look like on this hook alone -- is
 * indistinguishable from here anyway; scoping to non-role keys sidesteps
 * needing to tell them apart, rather than trying to suppress based on hook
 * ordering (set_user_role fires *after* this one, not before).
 */
class AM_Logger_Capabilities extends AM_Logger_Base {

	public function register_hooks() {
		add_action( 'update_user_meta', array( $this, 'on_update_user_meta' ), 10, 4 );
	}

	/** @param mixed $meta_value New value about to be written (already unserialized). */
	public function on_update_user_meta( $meta_id, $object_id, $meta_key, $meta_value ) {
		global $wpdb;

		if ( $meta_key !== $wpdb->prefix . 'capabilities' ) {
			return;
		}

		$old_caps = get_user_meta( $object_id, $meta_key, true );
		$old_caps = is_array( $old_caps ) ? $old_caps : array();
		$new_caps = is_array( $meta_value ) ? $meta_value : array();

		$role_slugs = array_keys( wp_roles()->roles );
		$old_extra  = array_diff_key( $old_caps, array_flip( $role_slugs ) );
		$new_extra  = array_diff_key( $new_caps, array_flip( $role_slugs ) );

		$added   = array_keys( array_diff_key( $new_extra, $old_extra ) );
		$removed = array_keys( array_diff_key( $old_extra, $new_extra ) );

		if ( ! $added && ! $removed ) {
			return;
		}

		$user = get_userdata( $object_id );
		$name = $user ? $user->user_login : "user-{$object_id}";

		$parts = array();
		if ( $added ) {
			/* translators: %s: comma-separated list of capability slugs */
			$parts[] = sprintf( __( 'added %s', 'activity-monitor' ), implode( ', ', $added ) );
		}
		if ( $removed ) {
			/* translators: %s: comma-separated list of capability slugs */
			$parts[] = sprintf( __( 'removed %s', 'activity-monitor' ), implode( ', ', $removed ) );
		}

		$this->log(
			'user',
			'capabilities_changed',
			sprintf(
				/* translators: 1: username, 2: added/removed capability summary */
				__( 'Capabilities changed for user "%1$s": %2$s.', 'activity-monitor' ),
				$name,
				implode( '; ', $parts )
			),
			array(
				'level'       => AM_Log_Levels::WARNING,
				'object_type' => 'user',
				'object_id'   => $object_id,
				'object_name' => $name,
				'context'     => array(
					'diff' => array(
						'capabilities' => array(
							'before' => implode( ', ', array_keys( $old_extra ) ),
							'after'  => implode( ', ', array_keys( $new_extra ) ),
						),
					),
				),
				'group'       => false,
			)
		);
	}
}
