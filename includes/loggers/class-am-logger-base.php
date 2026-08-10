<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_Base — one subclass per event source (posts, users, plugins,
 * comments, etc.), each registering its own WordPress hooks. Replaces
 * v1.x's monolithic AM_Hooks class, where every hook callback lived in one
 * file.
 *
 * This is the seam where a third-party plugin integration (WooCommerce,
 * Yoast, and so on) would attach without touching the core loggers.
 */
abstract class AM_Logger_Base {

	/** Register this logger's WordPress hooks. */
	abstract public function register_hooks();

	/** Convenience wrapper so logger subclasses don't call AM_Event_Writer directly. */
	protected function log( string $event_type, string $action, string $message, array $args = array() ) {
		return AM_Event_Writer::log( $event_type, $action, $message, $args );
	}
}
