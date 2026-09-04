<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_Manager — instantiates every registered logger and wires up
 * its hooks.
 *
 * Add new loggers to REGISTERED_LOGGER_CLASSES below. Each entry is a
 * class name extending AM_Logger_Base; registering one is the whole of
 * what's needed.
 */
class AM_Logger_Manager {

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
		'AM_Logger_Php_Warnings',
		'AM_Logger_File_Editor',
		'AM_Logger_Maintenance_Mode',
		'AM_Logger_Mail_Failures',
		'AM_Logger_Rest_Api',
		'AM_Logger_Options',
	);

	public static function init() {
		foreach ( self::REGISTERED_LOGGER_CLASSES as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}
			$logger = new $class();
			$logger->register_hooks();
		}
	}
}
