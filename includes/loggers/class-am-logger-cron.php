<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_Cron — WP-Cron events scheduled or unscheduled.
 *
 * Two independent Settings → Logging switches:
 *  - am_log_cron_changes (default on): a change made in a request with a
 *    real user behind it.
 *  - am_log_cron_background (default on): a change made with nobody logged
 *    in — WordPress or a plugin scheduling itself, on a cron run or a visitor
 *    request. Higher volume, hence its own switch.
 * This plugin's own am_* hooks are always skipped so it never logs itself.
 *
 * The cron runner (wp-cron.php) does two things to every job it executes:
 * wp_reschedule_event() for a recurring one (which ends by calling
 * wp_schedule_event(), so it hits the schedule_event filter) and then
 * wp_unschedule_event() for the run itself. Neither is a schedule change,
 * so inside a cron request they're skipped: reschedules by marking the hook
 * from pre_reschedule_event (which fires first), unschedules outright. What
 * remains in the background are tasks plugins genuinely schedule for
 * themselves; a plugin cancelling a task from inside a cron callback is
 * indistinguishable from the runner's unschedule and isn't logged.
 *
 * All hooks are filters: each must hand its first argument back unchanged,
 * or it would short-circuit or alter the scheduling call. wp_clear_scheduled_hook()
 * calls wp_unschedule_event() per event, so pre_clear_scheduled_hook is
 * deliberately not hooked; wp_unschedule_hook() doesn't, so it has its own.
 */
class AM_Logger_Cron extends AM_Logger_Base {

	/** @var array<string,true> Hooks the runner is rescheduling right now. */
	private $rescheduling = array();

	public function register_hooks() {
		add_filter( 'pre_reschedule_event', array( $this, 'on_pre_reschedule' ), 10, 2 );
		add_filter( 'schedule_event', array( $this, 'on_schedule' ) );
		add_filter( 'pre_unschedule_event', array( $this, 'on_unschedule_event' ), 10, 4 );
		add_filter( 'pre_unschedule_hook', array( $this, 'on_unschedule_hook' ), 10, 2 );
	}

	/**
	 * @param mixed  $pre
	 * @param object $event
	 * @return mixed
	 */
	public function on_pre_reschedule( $pre, $event ) {
		if ( wp_doing_cron() && is_object( $event ) && ! empty( $event->hook ) ) {
			$this->rescheduling[ $event->hook ] = true;
		}
		return $pre;
	}

	/**
	 * @param object|false $event
	 * @return object|false
	 */
	public function on_schedule( $event ) {
		if ( ! is_object( $event ) || empty( $event->hook ) ) {
			return $event;
		}

		if ( isset( $this->rescheduling[ $event->hook ] ) ) {
			unset( $this->rescheduling[ $event->hook ] );
			return $event;
		}

		if ( ! $this->should_log( $event->hook ) ) {
			return $event;
		}

		$this->log(
			'cron',
			'scheduled',
			sprintf(
				/* translators: 1: cron hook name, 2: recurrence such as "hourly" or "once" */
				__( 'Cron event "%1$s" scheduled (%2$s).', 'activity-monitor' ),
				$event->hook,
				empty( $event->schedule ) ? __( 'once', 'activity-monitor' ) : $event->schedule
			),
			array(
				'level'       => AM_Log_Levels::NOTICE,
				'object_type' => 'cron',
				'object_name' => $event->hook,
			)
		);

		return $event;
	}

	/**
	 * @param mixed  $pre
	 * @param int    $timestamp
	 * @param string $hook
	 * @return mixed
	 */
	public function on_unschedule_event( $pre, $timestamp, $hook, $args ) {
		// The filter fires whether or not the event exists, so a plugin
		// that "makes sure" a task is gone on every request would log every time.
		if ( is_string( $hook ) && is_array( $args ) && wp_get_scheduled_event( $hook, $args, (int) $timestamp ) ) {
			$this->log_unscheduled( $hook );
		}
		return $pre;
	}

	/**
	 * @param mixed  $pre
	 * @param string $hook
	 * @return mixed
	 */
	public function on_unschedule_hook( $pre, $hook ) {
		if ( is_string( $hook ) && $this->hook_has_events( $hook ) ) {
			$this->log_unscheduled( $hook );
		}
		return $pre;
	}

	/** True if anything is currently scheduled under $hook (this filter runs before the removal). */
	private function hook_has_events( string $hook ): bool {
		foreach ( (array) _get_cron_array() as $events ) {
			if ( is_array( $events ) && isset( $events[ $hook ] ) ) {
				return true;
			}
		}
		return false;
	}

	private function log_unscheduled( $hook ) {
		if ( wp_doing_cron() || ! is_string( $hook ) || ! $this->should_log( $hook ) ) {
			return;
		}

		$this->log(
			'cron',
			'unscheduled',
			sprintf(
				/* translators: %s: cron hook name */
				__( 'Cron event "%s" unscheduled.', 'activity-monitor' ),
				$hook
			),
			array(
				'level'       => AM_Log_Levels::NOTICE,
				'object_type' => 'cron',
				'object_name' => $hook,
			)
		);
	}

	private function should_log( $hook ) {
		if ( 0 === strpos( (string) $hook, 'am_' ) ) {
			return false;
		}

		// Explicit defaults: cron changes happen on requests that never load the admin.
		if ( 0 !== get_current_user_id() && ! wp_doing_cron() ) {
			return (bool) get_option( 'am_log_cron_changes', 1 );
		}
		return (bool) get_option( 'am_log_cron_background', 1 );
	}
}
