<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Log_Levels — PSR-3 levels, replacing v1.x's 4-value TINYINT severity
 * (Info/Notice/Warning/Critical) with the full 8-level scale used by
 * Simple History and most external log platforms. Widening now avoids a
 * second breaking schema change if Log Channels / SIEM forwarding (out of
 * scope for v2.0, see spec §8) is added later.
 *
 * v1.x → v2.0 default mapping is handled in AM_Schema::migrate_legacy_row().
 */
class AM_Log_Levels {

	const EMERGENCY = 'emergency';
	const ALERT     = 'alert';
	const CRITICAL  = 'critical';
	const ERROR     = 'error';
	const WARNING   = 'warning';
	const NOTICE    = 'notice';
	const INFO      = 'info';
	const DEBUG     = 'debug';

	/** Ordered lowest → highest severity, for range comparisons. */
	const ORDER = array(
		self::DEBUG,
		self::INFO,
		self::NOTICE,
		self::WARNING,
		self::ERROR,
		self::CRITICAL,
		self::ALERT,
		self::EMERGENCY,
	);

	/**
	 * Per-event-type default level. Admin-overridable via settings
	 * (see activity-monitor-v2-spec.md §3) — this is just the shipped default.
	 */
	const EVENT_TYPE_DEFAULTS = array(
		'login_failed'    => self::WARNING,
		'user.delete'     => self::WARNING,
		'plugin.delete'   => self::NOTICE,
		'plugin.update'   => self::NOTICE,
		'theme.update'    => self::NOTICE,
		'core.update'     => self::NOTICE,
		'session.destroy' => self::NOTICE,
		'post.delete'     => self::WARNING,
		'post.trash'      => self::NOTICE,
		'media.update'    => self::INFO,
		'option.update'   => self::INFO,
	);

	public static function default_for_event_type( string $event_type ): string {
		return self::EVENT_TYPE_DEFAULTS[ $event_type ] ?? self::INFO;
	}

	public static function label( string $level ): string {
		$map = array(
			self::EMERGENCY => __( 'Emergency', 'activity-monitor' ),
			self::ALERT     => __( 'Alert',     'activity-monitor' ),
			self::CRITICAL  => __( 'Critical',  'activity-monitor' ),
			self::ERROR     => __( 'Error',     'activity-monitor' ),
			self::WARNING   => __( 'Warning',   'activity-monitor' ),
			self::NOTICE    => __( 'Notice',    'activity-monitor' ),
			self::INFO      => __( 'Info',      'activity-monitor' ),
			self::DEBUG     => __( 'Debug',     'activity-monitor' ),
		);
		return $map[ $level ] ?? __( 'Unknown', 'activity-monitor' );
	}

	public static function is_valid( string $level ): bool {
		return in_array( $level, self::ORDER, true );
	}
}
