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
		'AM_Logger_Fatal_Errors',
		'AM_Logger_File_Editor',
		'AM_Logger_Maintenance_Mode',
		'AM_Logger_Mail_Failures',
		// All 13 v1.x AM_Hooks event-source callbacks are ported. AM_Hooks,
		// AM_DB, and legacy AM_Logger have all been fully retired (dev.12
		// through dev.14) -- the "Activity Log" admin tab now reads
		// exclusively from AM_Event_Query against this schema, and email
		// notifications (AM_Notifications) are wired directly into
		// AM_Event_Writer rather than the old AM_Logger::log() call path.
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
