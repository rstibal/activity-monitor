<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_Options — a watched allowlist of site-configuration options,
 * not a blanket hook on every option write.
 *
 * Most of wp_options is transient/cache churn that means nothing to an
 * audit log; hooking updated_option unconditionally would flood the table.
 * WATCHED_OPTIONS is the deliberately small set where a change is itself
 * a meaningful security or configuration event.
 */
class AM_Logger_Options extends AM_Logger_Base {

	/**
	 * option name => log level.
	 *
	 * siteurl/home: a classic compromise indicator (silent redirect/hijack).
	 * default_role: changing this to 'administrator' is a known
	 * self-registration privilege-escalation trick.
	 * users_can_register / admin_email: lower severity, still worth a record.
	 */
	const WATCHED_OPTIONS = array(
		'siteurl'           => AM_Log_Levels::WARNING,
		'home'              => AM_Log_Levels::WARNING,
		'default_role'      => AM_Log_Levels::WARNING,
		'users_can_register' => AM_Log_Levels::NOTICE,
		'admin_email'       => AM_Log_Levels::NOTICE,
	);

	public function register_hooks() {
		add_action( 'updated_option', array( $this, 'on_option_updated' ), 10, 3 );
	}

	/**
	 * @param mixed $old_value
	 * @param mixed $value
	 */
	public function on_option_updated( string $option, $old_value, $value ) {
		if ( ! isset( self::WATCHED_OPTIONS[ $option ] ) ) {
			return;
		}

		$old = is_scalar( $old_value ) ? (string) $old_value : wp_json_encode( $old_value );
		$new = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );

		if ( $old === $new ) {
			return;
		}

		$this->log(
			'option',
			'changed',
			sprintf(
				/* translators: 1: option name, 2: old value, 3: new value */
				__( 'Option "%1$s" changed from "%2$s" to "%3$s".', 'activity-monitor' ),
				$option,
				$old,
				$new
			),
			array(
				'level'       => self::WATCHED_OPTIONS[ $option ],
				'object_type' => 'option',
				'object_name' => $option,
				'context'     => array(
					'old' => $old,
					'new' => $new,
				),
				'group'       => false,
			)
		);
	}
}
