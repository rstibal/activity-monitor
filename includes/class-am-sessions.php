<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Sessions — v2.0 session management.
 *
 * IMPORTANT ARCHITECTURAL NOTE: WordPress already tracks logged-in
 * sessions natively via WP_Session_Tokens, stored in the `session_tokens`
 * user meta key. v1.x's Active Sessions tab (AM_Admin::render_tab_sessions
 * et al.) already reads/writes this directly -- there was never a
 * separate am_sessions table, and this class does not introduce one. A
 * parallel table would just drift out of sync with the session state
 * WordPress itself enforces on every request. This class is a v2.0 LAYER
 * on top of that same storage, not a replacement for it.
 *
 * What v2.0 adds on top of v1.x's read/revoke capability (per
 * activity-monitor-v2-spec.md §5, GitHub issue #5):
 *   - Concurrent-session limit enforcement (benchmarked against Activity
 *     Log Pro in the competitive audit)
 *   - Active-threshold setting (a session idle longer than N minutes is
 *     treated as inactive for limit-counting purposes, though WordPress
 *     itself still honors it until actual expiration)
 *   - Emergency lockdown (terminate every session except the caller's own)
 *   - Session events logged through AM_Event_Writer onto the new
 *     am_events schema instead of the legacy AM_Logger::log() calls in
 *     AM_Admin
 *
 * Settings (all under the 'sessions' logger toggle in the noise-control
 * settings, once that UI exists):
 *   am_session_concurrent_limit  (int, 0 = disabled, default 0)
 *   am_session_active_threshold_minutes (int, default 30)
 */
class AM_Sessions {

	const OPTION_CONCURRENT_LIMIT   = 'am_session_concurrent_limit';
	const OPTION_ACTIVE_THRESHOLD   = 'am_session_active_threshold_minutes';

	/**
	 * Enforce the concurrent-session limit for one user by revoking their
	 * oldest sessions first. Call this after a new session is created
	 * (wp_login) so the newest login always survives the trim.
	 *
	 * @return int Number of sessions revoked.
	 */
	public static function enforce_concurrent_limit( int $user_id ): int {
		$limit = absint( get_option( self::OPTION_CONCURRENT_LIMIT, 0 ) );
		if ( $limit <= 0 ) {
			return 0; // Disabled.
		}

		$sessions = self::get_raw_sessions( $user_id );
		if ( count( $sessions ) <= $limit ) {
			return 0;
		}

		// Oldest login first, so the newest survive.
		uasort( $sessions, function ( $a, $b ) {
			return ( $a['login'] ?? 0 ) - ( $b['login'] ?? 0 );
		} );

		$to_revoke = array_slice( $sessions, 0, count( $sessions ) - $limit, true );

		foreach ( $to_revoke as $token_hash => $session ) {
			unset( $sessions[ $token_hash ] );
		}
		update_user_meta( $user_id, 'session_tokens', $sessions );

		$user = get_userdata( $user_id );
		AM_Event_Writer::log(
			'session',
			'limit_enforced',
			sprintf(
				/* translators: 1: number of sessions revoked, 2: username, 3: concurrent session limit */
				__( '%1$d session(s) for user "%2$s" revoked to enforce the %3$d-session concurrent limit.', 'activity-monitor' ),
				count( $to_revoke ),
				$user ? $user->user_login : $user_id,
				$limit
			),
			array(
				'level'       => AM_Log_Levels::NOTICE,
				'object_type' => 'user',
				'object_id'   => $user_id,
				'object_name' => $user ? $user->user_login : "user-{$user_id}",
				'group'       => false,
			)
		);

		return count( $to_revoke );
	}

	/**
	 * Revoke every session on the site except the caller's own.
	 * This is destructive and immediate -- callers (the admin UI action)
	 * are responsible for a confirmation step before invoking this; this
	 * method itself does not prompt.
	 *
	 * @return int Number of sessions revoked, across all users.
	 */
	public static function emergency_lockdown(): int {
		$current_user_id    = get_current_user_id();
		$current_token_hash = hash( 'sha256', wp_get_session_token() );

		$users = get_users( array( 'fields' => array( 'ID', 'user_login' ) ) );
		$total_revoked = 0;

		foreach ( $users as $user ) {
			$sessions = self::get_raw_sessions( $user->ID );
			if ( empty( $sessions ) ) {
				continue;
			}

			$keep = array();
			foreach ( $sessions as $token_hash => $session ) {
				$is_caller_own_session = (
					(int) $user->ID === (int) $current_user_id &&
					hash_equals( $current_token_hash, $token_hash )
				);
				if ( $is_caller_own_session ) {
					$keep[ $token_hash ] = $session;
				} else {
					++$total_revoked;
				}
			}

			if ( count( $keep ) !== count( $sessions ) ) {
				update_user_meta( $user->ID, 'session_tokens', $keep );
			}
		}

		if ( $total_revoked > 0 ) {
			AM_Event_Writer::log(
				'session',
				'emergency_lockdown',
				sprintf(
					/* translators: %d: number of sessions revoked */
					__( 'Emergency lockdown: %d session(s) terminated across all users.', 'activity-monitor' ),
					$total_revoked
				),
				array(
					'level'       => AM_Log_Levels::CRITICAL,
					'object_type' => 'security',
					'object_name' => 'all-users',
					'context'     => array( 'sessions_revoked' => $total_revoked ),
					'group'       => false,
				)
			);
		}

		return $total_revoked;
	}

	/**
	 * Whether a session counts as "active" under the configured threshold,
	 * based on last-activity time falling within the window. WordPress
	 * itself still honors the session until its actual expiration
	 * regardless of this setting -- this is a display/reporting concept
	 * only (e.g. "N of your M sessions are currently active"), not an
	 * enforcement mechanism, since WP_Session_Tokens has no last-activity
	 * field, only login time and expiration.
	 */
	public static function is_within_active_threshold( array $session ): bool {
		$threshold_minutes = absint( get_option( self::OPTION_ACTIVE_THRESHOLD, 30 ) );
		$login             = (int) ( $session['login'] ?? 0 );
		if ( ! $login ) {
			return false;
		}
		return ( time() - $login ) <= ( $threshold_minutes * MINUTE_IN_SECONDS );
	}

	private static function get_raw_sessions( int $user_id ): array {
		$raw = get_user_meta( $user_id, 'session_tokens', true );
		return is_array( $raw ) ? $raw : array();
	}
}
