<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_Base — one subclass per event source (posts, users, plugins,
 * sessions, etc.), each registering its own WordPress hooks. Replaces
 * v1.x's monolithic AM_Hooks class, where every hook callback lived in one
 * file and noise control meant a single flat settings checklist.
 *
 * This is what lets noise control (§3 of the spec) become "which loggers
 * are on" rather than a hardcoded per-event-type list, and is the seam
 * where third-party plugin integrations (WooCommerce, Yoast, etc. — out
 * of scope for v2.0 per spec §8) would attach later without touching
 * core loggers.
 *
 * See activity-monitor-v2-spec.md §6.
 */
abstract class AM_Logger_Base {

	/**
	 * Unique slug for this logger, used as the settings key for the
	 * per-logger enable/disable toggle (e.g. 'posts', 'users', 'plugins').
	 */
	abstract public function slug(): string;

	/** Human-readable name shown in the noise-control settings UI. */
	abstract public function label(): string;

	/** Register this logger's WordPress hooks. Only called if enabled(). */
	abstract public function register_hooks();

	/** Whether this logger is currently active, per the enable/disable setting. */
	public function is_enabled(): bool {
		$disabled = (array) get_option( 'am_disabled_loggers', array() );
		return ! in_array( $this->slug(), $disabled, true );
	}

	/** Convenience wrapper so logger subclasses don't call AM_Event_Writer directly. */
	protected function log( string $event_type, string $action, string $message, array $args = array() ) {
		return AM_Event_Writer::log( $event_type, $action, $message, $args );
	}
}
