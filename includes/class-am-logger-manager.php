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
		// TODO (spec §9 item 2): port the remaining v1.x AM_Hooks callbacks:
		// 'AM_Logger_Media',    - upload/update/delete
		// 'AM_Logger_Comments', - create/edit/delete/status
		// 'AM_Logger_Plugins',  - activate/deactivate/update/delete
		// 'AM_Logger_Themes',   - switch/customizer save
		// 'AM_Logger_Terms',    - created/edited/deleted (categories, tags)
		// 'AM_Logger_Menus',    - update/delete
		// 'AM_Logger_Widgets',  - save
		// 'AM_Logger_Passwords',- reset/retrieve/set
		// 'AM_Logger_Sites',    - multisite create/delete
		// 'AM_Logger_Sessions', - see issue #5, ported onto am_sessions table separately
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
