<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_Cron — WP-Cron events scheduled or unscheduled by a logged-in user.
 *
 * Plugins and core schedule and reschedule cron events constantly, mostly
 * from unattended cron runs, so those are skipped: only a change made in a
 * request with a real user behind it is logged. That still includes a
 * plugin doing its own housekeeping during an admin request (activation,
 * settings saves), which is why Settings → Logging can switch this off.
 * This plugin's own am_* hooks are skipped so it never logs itself.
 *
 * All five hooks are filters: each must hand its first argument back
 * unchanged, or it would short-circuit or alter the scheduling call.
 */
class AM_Logger_Cron extends AM_Logger_Base {

	public function register_hooks() {
		add_filter( 'schedule_event', array( $this, 'on_schedule' ) );
		add_filter( 'pre_unschedule_event', array( $this, 'on_unschedule_event' ), 10, 4 );
		add_filter( 'pre_unschedule_hook', array( $this, 'on_unschedule_hook' ), 10, 2 );
	}

	/**
	 * @param object|false $event
	 * @return object|false
	 */
	public function on_schedule( $event ) {
		if ( ! is_object( $event ) || empty( $event->hook ) || ! $this->should_log( $event->hook ) ) {
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
	 * Covers wp_unschedule_event() and, since wp_clear_scheduled_hook() calls
	 * it once per event, that too.
	 *
	 * @param mixed  $pre
	 * @param int    $timestamp
	 * @param string $hook
	 * @return mixed
	 */
	public function on_unschedule_event( $pre, $timestamp, $hook, $args ) {
		$this->log_unscheduled( $hook );
		return $pre;
	}

	/**
	 * wp_unschedule_hook() strips every event for a hook directly, without
	 * calling wp_unschedule_event().
	 *
	 * @param mixed  $pre
	 * @param string $hook
	 * @return mixed
	 */
	public function on_unschedule_hook( $pre, $hook ) {
		$this->log_unscheduled( $hook );
		return $pre;
	}

	private function log_unscheduled( $hook ) {
		if ( ! is_string( $hook ) || ! $this->should_log( $hook ) ) {
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
		// Explicit default: cron changes happen on requests that never load the admin.
		if ( ! get_option( 'am_log_cron_changes', 1 ) ) {
			return false;
		}
		if ( wp_doing_cron() || 0 === get_current_user_id() ) {
			return false;
		}
		return 0 !== strpos( (string) $hook, 'am_' );
	}
}
