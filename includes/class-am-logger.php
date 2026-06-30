<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Severity levels (stored as TINYINT):
 *   1 = Info
 *   2 = Notice
 *   3 = Warning
 *   4 = Critical
 */
class AM_Logger {

	const INFO     = 1;
	const NOTICE   = 2;
	const WARNING  = 3;
	const CRITICAL = 4;

	public static function severity_label( int $level ): string {
		$map = array(
			self::INFO     => __( 'Info',     'activity-monitor' ),
			self::NOTICE   => __( 'Notice',   'activity-monitor' ),
			self::WARNING  => __( 'Warning',  'activity-monitor' ),
			self::CRITICAL => __( 'Critical', 'activity-monitor' ),
		);
		return $map[ $level ] ?? __( 'Unknown', 'activity-monitor' );
	}

	public static function severity_class( int $level ): string {
		$map = array(
			self::INFO     => 'am-info',
			self::NOTICE   => 'am-notice',
			self::WARNING  => 'am-warning',
			self::CRITICAL => 'am-critical',
		);
		return $map[ $level ] ?? '';
	}

	/**
	 * Write an event to the log.
	 *
	 * @param string $event_type  Dot-namespaced key, e.g. "post.update"
	 * @param string $message     Human-readable description
	 * @param array  $args {
	 *   severity    (int)    default NOTICE
	 *   object_type (string) 'post','user','option', etc.
	 *   object_id   (int)
	 *   object_name (string)
	 *   meta        (array)  arbitrary extra data
	 *   user_id     (int)    override; defaults to current user
	 * }
	 */
	public static function log( string $event_type, string $message, array $args = array() ) {
		$user = wp_get_current_user();

		$defaults = array(
			'severity'    => self::NOTICE,
			'object_type' => '',
			'object_id'   => 0,
			'object_name' => '',
			'meta'        => array(),
			'user_id'     => (int) $user->ID,
			'user_name'   => $user->user_login ?: __( '(not logged in)', 'activity-monitor' ),
			'user_role'   => implode( ', ', (array) ( $user->roles ?? array() ) ),
		);

		$args = wp_parse_args( $args, $defaults );

		$row_id = AM_DB::insert( array(
			'severity'    => absint( $args['severity'] ),
			'event_type'  => sanitize_key( $event_type ),
			'object_type' => sanitize_text_field( $args['object_type'] ),
			'object_id'   => absint( $args['object_id'] ),
			'object_name' => sanitize_text_field( $args['object_name'] ),
			'user_id'     => absint( $args['user_id'] ),
			'user_name'   => sanitize_text_field( $args['user_name'] ),
			'user_role'   => sanitize_text_field( $args['user_role'] ),
			'message'     => sanitize_textarea_field( $message ),
			'meta'        => $args['meta'],
		) );

		// Fire notifications if row was saved
		if ( $row_id ) {
			AM_Notifications::maybe_notify( $args['severity'], $event_type, $message, $args );
		}
	}
}
