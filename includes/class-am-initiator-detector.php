<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Initiator_Detector — replaces v1.x's `is_automated_context()` (a
 * private one-liner wrapping wp_doing_cron(), used only to decide
 * skip-or-log) with a proper, filterable classification.
 *
 * v1.x behavior: `if ( is_automated_context() ) return;` — cron-triggered
 * events were silently dropped.
 *
 * v2.0 behavior: every event gets an initiator tag. Nothing is dropped by
 * default; admins filter/hide by initiator instead (see noise-control
 * settings), which is strictly more useful — "show me only what WP-CLI
 * touched" becomes a one-click filter instead of unrecoverable data loss.
 *
 * See activity-monitor-v2-spec.md §3, §6.
 */
class AM_Initiator_Detector {

	const WP_USER  = 'wp_user';   // A logged-in user performed the action directly.
	const WEB_USER = 'web_user';  // A non-authenticated front-end visitor (e.g. failed login, comment).
	const WP_CRON  = 'wp_cron';   // WP-Cron triggered the action.
	const WP_CLI   = 'wp_cli';    // WP-CLI command triggered the action.
	const SYSTEM   = 'system';    // Core/internal WordPress process with no clear human or cron origin.

	/**
	 * Determine the initiator for the current request context.
	 * Call this once per logged event, at write time.
	 */
	public static function detect(): string {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return self::WP_CLI;
		}

		if ( wp_doing_cron() ) {
			return self::WP_CRON;
		}

		if ( is_user_logged_in() ) {
			return self::WP_USER;
		}

		if ( ! empty( $_SERVER['REQUEST_METHOD'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return self::WEB_USER;
		}

		return self::SYSTEM;
	}

	public static function label( string $initiator ): string {
		$map = array(
			self::WP_USER  => __( 'User', 'activity-monitor' ),
			self::WEB_USER => __( 'Visitor', 'activity-monitor' ),
			self::WP_CRON  => __( 'Cron', 'activity-monitor' ),
			self::WP_CLI   => __( 'WP-CLI', 'activity-monitor' ),
			self::SYSTEM   => __( 'System', 'activity-monitor' ),
		);
		return $map[ $initiator ] ?? __( 'Unknown', 'activity-monitor' );
	}

	/** @return string[] All valid initiator values, for validation/filter dropdowns. */
	public static function all(): array {
		return array( self::WP_USER, self::WEB_USER, self::WP_CRON, self::WP_CLI, self::SYSTEM );
	}
}
