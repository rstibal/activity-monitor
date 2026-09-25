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
	 * blog_public: "discourage search engines" — silently de-indexes the site.
	 * users_can_register / admin_email / permalink_structure /
	 * timezone_string / WPLANG: lower severity, still worth a record.
	 */
	const WATCHED_OPTIONS = array(
		'siteurl'            => AM_Log_Levels::WARNING,
		'home'               => AM_Log_Levels::WARNING,
		'default_role'       => AM_Log_Levels::WARNING,
		'blog_public'        => AM_Log_Levels::WARNING,
		'users_can_register' => AM_Log_Levels::NOTICE,
		'admin_email'        => AM_Log_Levels::NOTICE,
		'permalink_structure' => AM_Log_Levels::NOTICE,
		'timezone_string'    => AM_Log_Levels::NOTICE,
		'WPLANG'             => AM_Log_Levels::NOTICE,
		'new_admin_email'    => AM_Log_Levels::WARNING,
		'blogname'           => AM_Log_Levels::NOTICE,
		'blogdescription'    => AM_Log_Levels::NOTICE,
		'site_icon'          => AM_Log_Levels::NOTICE,
		'show_on_front'      => AM_Log_Levels::NOTICE,
		'page_on_front'      => AM_Log_Levels::NOTICE,
		'page_for_posts'     => AM_Log_Levels::NOTICE,
		'date_format'        => AM_Log_Levels::NOTICE,
		'time_format'        => AM_Log_Levels::NOTICE,
		'default_comment_status'     => AM_Log_Levels::NOTICE,
		'comment_moderation'         => AM_Log_Levels::NOTICE,
		'comment_registration'       => AM_Log_Levels::NOTICE,
		'comment_previously_approved' => AM_Log_Levels::NOTICE,
		'require_name_email'         => AM_Log_Levels::NOTICE,
		'close_comments_for_old_posts' => AM_Log_Levels::NOTICE,

		// This plugin's own settings. Shortening retention or turning off IP
		// storage / channels is how someone quietly blunts the audit trail.
		'am_retention_days'           => AM_Log_Levels::WARNING,
		'am_ip_storage'               => AM_Log_Levels::WARNING,
		'am_notification_channels'    => AM_Log_Levels::WARNING,
		'am_occasion_window_seconds'  => AM_Log_Levels::NOTICE,
		'am_log_cron_changes'         => AM_Log_Levels::NOTICE,
		'am_log_cron_background'      => AM_Log_Levels::NOTICE,
		'am_datetime_format'          => AM_Log_Levels::NOTICE,
		'am_ip_lookup_enabled'        => AM_Log_Levels::NOTICE,
		'am_delete_data_on_uninstall' => AM_Log_Levels::NOTICE,
		'am_stats_enable_tracking'    => AM_Log_Levels::NOTICE,
		'am_stats_exclude_roles'      => AM_Log_Levels::NOTICE,
		'am_stats_retention_days'     => AM_Log_Levels::NOTICE,
		'am_stats_geo_enabled'        => AM_Log_Levels::NOTICE,
		'am_stats_geo_account_id'     => AM_Log_Levels::NOTICE,
		'am_stats_geo_license_key'    => AM_Log_Levels::NOTICE,
	);

	/**
	 * Options whose values are credentials or contain them (webhook URLs, a
	 * license key): the change is logged, the values are not.
	 */
	const HIDDEN_VALUES = array( 'am_notification_channels', 'am_stats_geo_license_key' );

	/**
	 * Network (multisite) options, which live in sitemeta and never reach
	 * updated_option. Same idea: the few that change who can sign up, what can
	 * be uploaded, or who is on the network.
	 */
	const NETWORK_OPTIONS = array(
		'registration'            => AM_Log_Levels::WARNING,
		'add_new_users'           => AM_Log_Levels::WARNING,
		'upload_filetypes'        => AM_Log_Levels::WARNING,
		'menu_items'              => AM_Log_Levels::WARNING,
		'limited_email_domains'   => AM_Log_Levels::NOTICE,
		'banned_email_domains'    => AM_Log_Levels::NOTICE,
		'illegal_names'           => AM_Log_Levels::NOTICE,
		'fileupload_maxk'         => AM_Log_Levels::NOTICE,
		'registrationnotification' => AM_Log_Levels::NOTICE,
		'site_name'               => AM_Log_Levels::NOTICE,
	);

	public function register_hooks() {
		add_action( 'updated_option', array( $this, 'on_option_updated' ), 10, 3 );
		if ( is_multisite() ) {
			add_action( 'update_site_option', array( $this, 'on_network_option_updated' ), 10, 3 );
		}
	}

	/**
	 * Unlike updated_option, update_site_option passes ( $option, $new, $old ).
	 *
	 * @param mixed $value
	 * @param mixed $old_value
	 */
	public function on_network_option_updated( string $option, $value, $old_value ) {
		if ( isset( self::NETWORK_OPTIONS[ $option ] ) ) {
			$this->record_change( 'network:' . $option, self::NETWORK_OPTIONS[ $option ], $old_value, $value );
		}
	}

	/**
	 * @param mixed $old_value
	 * @param mixed $value
	 */
	public function on_option_updated( string $option, $old_value, $value ) {
		if ( isset( self::WATCHED_OPTIONS[ $option ] ) ) {
			$this->record_change( $option, self::WATCHED_OPTIONS[ $option ], $old_value, $value );
		}
	}

	/**
	 * @param mixed $old_value
	 * @param mixed $value
	 */
	private function record_change( string $option, string $level, $old_value, $value ) {
		$old = is_scalar( $old_value ) ? (string) $old_value : wp_json_encode( $old_value );
		$new = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );

		if ( $old === $new ) {
			return;
		}

		if ( in_array( $option, self::HIDDEN_VALUES, true ) ) {
			$old = __( '(hidden)', 'activity-monitor' );
			$new = $old;
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
				'level'       => $level,
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
