<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_Manager — instantiates every registered logger and wires up
 * its hooks, but only if that logger is enabled (per-logger toggle,
 * settings key `am_disabled_loggers`).
 *
 * Add new loggers to REGISTERED_LOGGER_CLASSES below. Each entry is a
 * class name extending AM_Logger_Base; registering one is the whole of
 * what's needed, since the Settings screen's Event sources list is built
 * from all() and a logger absent from am_disabled_loggers is on.
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
