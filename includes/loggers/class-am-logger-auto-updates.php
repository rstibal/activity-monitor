<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_Auto_Updates — per-plugin / per-theme auto-update switched on or off.
 *
 * Core keeps each list in a single option (auto_update_plugins holding plugin
 * files, auto_update_themes holding stylesheets), so the change is a diff of
 * old against new. The option is created on the first toggle, which fires
 * added_option rather than updated_option, hence both. On multisite the same
 * lists are network options with their own hooks.
 *
 * An item that vanished from the list *and* from disk is core's own pruning
 * after a delete, not someone switching updates off, so it isn't logged.
 * Turning updates off is the more security-relevant direction (WARNING).
 */
class AM_Logger_Auto_Updates extends AM_Logger_Base {

	const OPTIONS = array(
		'auto_update_plugins' => 'plugin',
		'auto_update_themes'  => 'theme',
	);

	public function register_hooks() {
		add_action( 'updated_option', array( $this, 'on_updated_option' ), 10, 3 );
		add_action( 'added_option', array( $this, 'on_added_option' ), 10, 2 );

		if ( is_multisite() ) {
			add_action( 'update_site_option', array( $this, 'on_updated_option' ), 10, 3 );
			add_action( 'add_site_option', array( $this, 'on_added_option' ), 10, 2 );
		}
	}

	/**
	 * updated_option passes ( $option, $old, $new ); update_site_option passes
	 * ( $option, $new, $old ), so the site variant is swapped by its arity.
	 *
	 * @param mixed $a
	 * @param mixed $b
	 */
	public function on_updated_option( string $option, $a, $b ) {
		if ( ! isset( self::OPTIONS[ $option ] ) ) {
			return;
		}
		if ( 'update_site_option' === current_action() ) {
			$this->diff( $option, $b, $a );
			return;
		}
		$this->diff( $option, $a, $b );
	}

	/**
	 * @param mixed $value
	 */
	public function on_added_option( string $option, $value ) {
		if ( isset( self::OPTIONS[ $option ] ) ) {
			$this->diff( $option, array(), $value );
		}
	}

	/**
	 * @param mixed $before
	 * @param mixed $after
	 */
	private function diff( string $option, $before, $after ) {
		$before = is_array( $before ) ? $before : array();
		$after  = is_array( $after ) ? $after : array();
		$type   = self::OPTIONS[ $option ];

		foreach ( array_diff( $after, $before ) as $item ) {
			$this->log_toggle( $type, (string) $item, true );
		}
		foreach ( array_diff( $before, $after ) as $item ) {
			if ( $this->still_installed( $type, (string) $item ) ) {
				$this->log_toggle( $type, (string) $item, false );
			}
		}
	}

	private function still_installed( string $type, string $item ): bool {
		if ( 'theme' === $type ) {
			return wp_get_theme( $item )->exists();
		}
		return file_exists( WP_PLUGIN_DIR . '/' . $item );
	}

	private function log_toggle( string $type, string $item, bool $enabled ) {
		if ( 'theme' === $type ) {
			$message = $enabled
				/* translators: %s: theme stylesheet */
				? __( 'Auto-updates turned on for theme "%s".', 'activity-monitor' )
				/* translators: %s: theme stylesheet */
				: __( 'Auto-updates turned off for theme "%s".', 'activity-monitor' );
		} else {
			$message = $enabled
				/* translators: %s: plugin file path */
				? __( 'Auto-updates turned on for plugin "%s".', 'activity-monitor' )
				/* translators: %s: plugin file path */
				: __( 'Auto-updates turned off for plugin "%s".', 'activity-monitor' );
		}

		$this->log(
			$type,
			$enabled ? 'auto_update_enabled' : 'auto_update_disabled',
			sprintf( $message, $item ),
			array(
				'level'       => $enabled ? AM_Log_Levels::NOTICE : AM_Log_Levels::WARNING,
				'object_type' => $type,
				'object_name' => $item,
				'group'       => false,
			)
		);
	}
}
