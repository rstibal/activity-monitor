<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_Manager — instantiates every registered logger and wires up
 * its hooks, but only if that logger is enabled (per-logger toggle,
 * settings key `am_disabled_loggers`).
 *
 * Add new loggers here as they're ported from v1.x AM_Hooks (see
 * activity-monitor-v2-spec.md §9, build order item 2). Each entry is a
 * fully-qualified AM_Logger_* class name implementing AM_Logger_Base.
 */
class AM_Logger_Manager {

	/** @var AM_Logger_Base[] */
	private static $loggers = array();

	const REGISTERED_LOGGER_CLASSES = array(
		'AM_Logger_Posts',
		'AM_Logger_Users',
		'AM_Logger_Media',
		'AM_Logger_Comments',
		'AM_Logger_Plugins',
		'AM_Logger_Themes',
		'AM_Logger_Core',
		'AM_Logger_Terms',
		'AM_Logger_Menus',
		'AM_Logger_Widgets',
		'AM_Logger_Passwords',
		'AM_Logger_Sites',
		'AM_Logger_Security',
		// All 13 v1.x AM_Hooks event-source callbacks are now ported (dev.12).
		// AM_Hooks itself has been fully retired -- it had nothing left to run.
		// Session management (concurrent limit, emergency lockdown) is handled
		// separately by AM_Sessions, not a logger in this list, since sessions
		// are read/written via WP core's own session_tokens user meta rather
		// than events on this table -- see includes/class-am-sessions.php.
		//
		// Still legacy: admin/class-am-admin.php's "Activity Log" tab reads
		// AM_DB::get_events() directly and uses AM_Logger's severity constants
		// for display. AM_DB and AM_Logger (the v1.x classes, not this
		// AM_Logger_* family) stay required in activity-monitor.php until that
		// tab is either removed in favor of the v2.0 preview tab, or migrated
		// onto AM_Event_Query -- a UI decision, not made unilaterally here.
	);

	public static function init() {
		foreach ( self::REGISTERED_LOGGER_CLASSES as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}
			$logger = new $class();
			if ( $logger->is_enabled() ) {
				$logger->register_hooks();
			}
			self::$loggers[ $logger->slug() ] = $logger;
		}
	}

	/** @return AM_Logger_Base[] All registered loggers, for the settings UI. */
	public static function all(): array {
		return self::$loggers;
	}
}
