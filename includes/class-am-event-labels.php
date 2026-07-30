<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Event_Labels — human-readable labels for event_type + action pairs.
 *
 * Events are stored as two columns (event_type, action) — e.g.
 * 'plugin' + 'deactivated' — rather than v1.x's single concatenated
 * slug. The admin UI previously displayed these raw, e.g.
 * "plugin.deactivated" or "media.deleted". This class maps each known
 * pair to a readable string for display, while the raw value remains
 * available (as a tooltip/subtext) for anyone who wants the technical
 * form.
 *
 * The map covers every event_type.action pair currently emitted by the
 * AM_Logger_* classes (see includes/loggers/). Add an entry here
 * whenever a new logger or action is introduced — AM_Event_Labels::label()
 * falls back to a readable auto-formatted guess so nothing renders as a
 * raw, un-humanized slug even if this map lags behind new loggers.
 */
class AM_Event_Labels {

	const MAP = array(
		'comment.created'                    => 'Comment Posted',
		'comment.deleted'                     => 'Comment Deleted',
		'comment.edited'                      => 'Comment Edited',
		'comment.status_changed'              => 'Comment Status Changed',
		'core.updated'                        => 'WordPress Core Updated',
		'media.deleted'                        => 'Media Deleted',
		'media.updated'                        => 'Media Updated',
		'media.uploaded'                       => 'Media Uploaded',
		'menu.deleted'                          => 'Menu Deleted',
		'menu.updated'                          => 'Menu Updated',
		'plugin.activated'                     => 'Plugin Activated',
		'plugin.deactivated'                   => 'Plugin Deactivated',
		'plugin.deleted'                        => 'Plugin Deleted',
		'plugin.installed'                      => 'Plugin Installed',
		'plugin.updated'                        => 'Plugin Updated',
		'post.deleted'                           => 'Post Deleted',
		'post.published'                        => 'Post Published',
		'post.restored'                          => 'Post Restored from Trash',
		'post.trashed'                           => 'Post Moved to Trash',
		'post.updated'                           => 'Post Updated',
		'security.access_denied'                => 'Access Denied',
		// Session management was removed in 2.4.0 and nothing writes these
		// any more, but an upgraded site still has session.* rows in
		// am_events and they have to keep rendering. Dropping these would
		// degrade a logged "Emergency Session Lockdown" to the generic
		// fallback "Session emergency lockdown" -- same reason LEGACY_MAP
		// exists for v1.x slugs. Keep them.
		'session.emergency_lockdown'            => 'Emergency Session Lockdown',
		'session.limit_enforced'                => 'Session Limit Enforced',
		'site.created'                           => 'Site Created',
		'site.deleted'                           => 'Site Deleted',
		'system.fatal_error'                     => 'PHP Fatal Error',
		'system.file_edit_attempted'             => 'File Editor Used',
		'system.maintenance_enabled'             => 'Maintenance Mode Enabled',
		'system.maintenance_disabled'            => 'Maintenance Mode Disabled',
		'system.mail_failed'                     => 'Email Delivery Failed',
		'system.slack_notification_failed'       => 'Slack Notification Failed',
		'term.created'                           => 'Term Created',
		'term.deleted'                           => 'Term Deleted',
		'term.updated'                           => 'Term Updated',
		'theme.customized'                      => 'Theme Customized',
		'theme.switched'                         => 'Theme Switched',
		'theme.updated'                          => 'Theme Updated',
		'user.added_to_site'                    => 'User Added to Site',
		'user.auth_error'                        => 'Authentication Error',
		'user.deleted'                            => 'User Deleted',
		'user.login'                              => 'User Logged In',
		'user.login_failed'                      => 'Failed Login Attempt',
		'user.logout'                             => 'User Logged Out',
		'user.password_reset'                    => 'Password Reset',
		'user.password_retrieve_requested'      => 'Password Reset Requested',
		'user.password_set'                      => 'Password Set',
		'user.registered'                        => 'User Registered',
		'user.role_changed'                      => 'User Role Changed',
		'user.updated'                            => 'User Profile Updated',
	);

	/**
	 * Human-readable label for an event_type + action pair.
	 *
	 * Falls back to an auto-formatted guess (e.g. "post" + "archived"
	 * -> "Post Archived") for any pair not yet in MAP, so a newly added
	 * logger action never renders as a raw slug even before this map
	 * is updated.
	 *
	 * Rows migrated from v1.x have no action at all -- AM_Schema's
	 * migrate_legacy_row() sets it to '' because v1 folded the action
	 * into event_type. Those are handed to type_label(), which knows
	 * how to unpack the combined slug; without that they'd render with
	 * a stray trailing space, e.g. "Pluginupdate ".
	 */
	public static function label( string $event_type, string $action ): string {
		if ( '' === trim( $action ) ) {
			return self::type_label( $event_type );
		}
		$key = $event_type . '.' . $action;
		if ( isset( self::MAP[ $key ] ) ) {
			return self::MAP[ $key ];
		}
		return ucfirst( str_replace( '_', ' ', $event_type ) ) . ' ' . str_replace( '_', ' ', $action );
	}

	/**
	 * Legacy v1.x slugs mapped to the phrasing their v2 equivalent
	 * uses, so a site holding both eras of data reads consistently --
	 * a migrated 'authlogin' row and a new 'user.login' row both say
	 * "User Logged In" rather than differing by an accident of which
	 * version recorded them. The raw slug stays available via raw()
	 * for anyone who needs to tell them apart.
	 */
	const LEGACY_MAP = array(
		'authlogin'    => 'User Logged In',
		'authlogout'   => 'User Logged Out',
		'autherror'    => 'Authentication Error',
		'optionupdate' => 'Option Updated',
	);

	/**
	 * Type names that only ever existed in v1.x -- v2 has no 'auth'
	 * type (those events are filed under 'user') and no 'option' type.
	 * Kept separate from TYPE_MAP so that stays an honest list of the
	 * current vocabulary; both are consulted when splitting a legacy
	 * slug.
	 */
	const LEGACY_TYPE_MAP = array(
		'auth'   => 'Authentication',
		'option' => 'Option',
	);

	/**
	 * Action words v1.x appended to a type name. Used as a last resort
	 * to split a legacy slug whose *type* half isn't in either type map
	 * -- 'widgetsave' still resolves to "Widget save" without anyone
	 * having to add 'widget' anywhere first. Both halves must be
	 * substantial enough to be real (see split_on_action()), so short
	 * coincidental endings don't trigger a bogus split.
	 *
	 * Order here doesn't matter; split_on_action() sorts longest-first
	 * at match time, so 'updated' can't lose to 'update'. Add new words
	 * wherever they read best.
	 */
	const LEGACY_ACTIONS = array(
		'activate', 'activated', 'change', 'changed', 'create', 'created',
		'customized', 'deactivate', 'deactivated', 'delete', 'deleted',
		'denied', 'edit', 'edited', 'error', 'failed', 'install',
		'installed', 'login', 'logout', 'publish', 'published', 'register',
		'registered', 'remove', 'removed', 'requested', 'reset', 'restore',
		'restored', 'save', 'switch', 'switched', 'trash', 'trashed',
		'uninstalled', 'update', 'updated', 'upload', 'uploaded',
	);

	/**
	 * Display labels for a bare event_type, as stored in the column of
	 * that name and listed in the Activity Log's Type filter.
	 *
	 * Distinct from MAP above, which keys on a full "event_type.action"
	 * pair. The filter dropdown only has the type half to work with, so
	 * it needs its own map.
	 */
	const TYPE_MAP = array(
		'comment'  => 'Comment',
		'core'     => 'WordPress Core',
		'media'    => 'Media',
		'menu'     => 'Menu',
		'plugin'   => 'Plugin',
		'post'     => 'Post',
		'security' => 'Security',
		'session'  => 'Session', // Retained for pre-2.4.0 rows -- see MAP above.
		'site'     => 'Site',
		'system'   => 'System',
		'term'     => 'Term',
		'theme'    => 'Theme',
		'user'     => 'User',
	);

	/**
	 * Human-readable label for a bare event_type value.
	 *
	 * Has to cope with several shapes, because a site upgraded from
	 * v1.x ends up with a mix of them in one column. AM_Schema's
	 * migrate_legacy_row() copies v1's event_type across verbatim and
	 * sets action to '', and v1 folded the action into that single
	 * slug -- so alongside clean v2 types the column can hold legacy
	 * values in either a dotted or an undelimited form:
	 *
	 *   'plugin'        -> Plugin                (v2, TYPE_MAP)
	 *   'authlogin'     -> User Logged In        (v1, LEGACY_MAP)
	 *   'post.deleted'  -> Post Deleted          (v1 dotted; MAP if known)
	 *   'pluginupdate'  -> Plugin update         (v1, split on type name)
	 *   'widgetsave'    -> Widget save           (v1, split on action word)
	 *   'whatever_new'  -> Whatever new          (unknown; humanized)
	 *
	 * Resolution runs most-specific first: exact maps, then the dotted
	 * form, then splitting on a known type name (longest first, so a
	 * longer name always wins over a shorter one it contains), then
	 * splitting on a known trailing action word. That last step is what
	 * keeps an unmapped legacy slug readable -- the type half doesn't
	 * have to be known to anything. Anything still unrecognized gets
	 * underscores and capitalization cleaned up rather than shown raw.
	 */
	public static function type_label( string $event_type ): string {
		$slug = trim( $event_type );
		if ( '' === $slug ) {
			return '';
		}

		if ( isset( self::TYPE_MAP[ $slug ] ) ) {
			return self::TYPE_MAP[ $slug ];
		}
		if ( isset( self::LEGACY_MAP[ $slug ] ) ) {
			return self::LEGACY_MAP[ $slug ];
		}

		// Legacy dotted form. Prefer the full-pair map where the exact
		// slug is known, so these read identically to a v2 event.
		if ( false !== strpos( $slug, '.' ) ) {
			if ( isset( self::MAP[ $slug ] ) ) {
				return self::MAP[ $slug ];
			}
			list( $type, $action ) = explode( '.', $slug, 2 );
			return self::type_label( $type ) . ' ' . str_replace( '_', ' ', $action );
		}

		// Legacy undelimited form: known type name glued to an action.
		$types = array_merge( self::TYPE_MAP, self::LEGACY_TYPE_MAP );
		foreach ( self::by_length_desc( array_keys( $types ) ) as $prefix ) {
			if ( 0 === strpos( $slug, $prefix ) && strlen( $slug ) > strlen( $prefix ) ) {
				return $types[ $prefix ] . ' ' . str_replace( '_', ' ', substr( $slug, strlen( $prefix ) ) );
			}
		}

		// Type half unknown -- try splitting on a trailing action word.
		$split = self::split_on_action( $slug );
		if ( null !== $split ) {
			return $split;
		}

		return ucfirst( str_replace( array( '_', '-' ), ' ', $slug ) );
	}

	/**
	 * Splits a slug ending in a known action word, e.g. 'widgetsave'
	 * -> "Widget save". Returns null when nothing matches.
	 *
	 * Both halves must be long enough to be plausible: a 4-character
	 * minimum on the action and 3 on the type keeps a short word that
	 * merely happens to end a longer one from forcing a nonsense split
	 * (without the floor, 'asset' would become "As set").
	 */
	private static function split_on_action( string $slug ): ?string {
		foreach ( self::by_length_desc( self::LEGACY_ACTIONS ) as $action ) {
			if ( strlen( $action ) < 4 ) {
				continue;
			}
			$rest = strlen( $slug ) - strlen( $action );
			if ( $rest < 3 || substr( $slug, $rest ) !== $action ) {
				continue;
			}
			return ucfirst( substr( $slug, 0, $rest ) ) . ' ' . $action;
		}
		return null;
	}

	/** Sorts strings longest-first, for greedy prefix/suffix matching. */
	private static function by_length_desc( array $values ): array {
		usort( $values, static function ( $a, $b ) {
			return strlen( $b ) <=> strlen( $a );
		} );
		return $values;
	}

	/**
	 * The raw "event_type.action" slug, for tooltips/technical display.
	 * Migrated v1.x rows have no action, so those return the stored
	 * slug alone rather than gaining a trailing dot.
	 */
	public static function raw( string $event_type, string $action ): string {
		if ( '' === trim( $action ) ) {
			return $event_type;
		}
		return $event_type . '.' . $action;
	}
}
