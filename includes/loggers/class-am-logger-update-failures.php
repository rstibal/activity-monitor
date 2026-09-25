<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_Update_Failures — plugin/theme installs and updates that failed.
 *
 * upgrader_process_complete only fires on success, so failures need their own
 * hooks:
 *  - upgrader_install_package_result: the copy into place failed.
 *  - upgrader_source_selection (late, so core's own package checks have run):
 *    the package was unpacked but wasn't a valid plugin/theme. Core returns
 *    that error straight away, so the two hooks never both see one failure.
 *  - automatic_updates_complete: unattended background updates, whose
 *    failures are otherwise silent.
 * A failed *download* has no hook and isn't covered.
 *
 * Both upgrader hooks are filters and must hand their first argument back
 * unchanged.
 */
class AM_Logger_Update_Failures extends AM_Logger_Base {

	/** @var array<string,true> "type|plugin file or theme slug" already logged this request. */
	private $logged = array();

	public function register_hooks() {
		add_filter( 'upgrader_install_package_result', array( $this, 'on_install_package_result' ), 999, 2 );
		add_filter( 'upgrader_source_selection', array( $this, 'on_source_selection' ), 999, 4 );
		add_action( 'automatic_updates_complete', array( $this, 'on_automatic_updates_complete' ) );
	}

	/**
	 * @param mixed $result
	 * @param mixed $hook_extra
	 * @return mixed
	 */
	public function on_install_package_result( $result, $hook_extra ) {
		if ( is_wp_error( $result ) && is_array( $hook_extra ) ) {
			$this->log_failure(
				(string) ( $hook_extra['type'] ?? '' ),
				(string) ( $hook_extra['action'] ?? '' ),
				(string) ( $hook_extra['plugin'] ?? $hook_extra['theme'] ?? '' ),
				$result->get_error_message()
			);
		}
		return $result;
	}

	/**
	 * @param mixed $source
	 * @param mixed $remote_source
	 * @param mixed $upgrader
	 * @param mixed $hook_extra
	 * @return mixed
	 */
	public function on_source_selection( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( ! is_wp_error( $source ) ) {
			return $source;
		}

		$hook_extra = is_array( $hook_extra ) ? $hook_extra : array();
		$type       = (string) ( $hook_extra['type'] ?? '' );
		if ( '' === $type ) {
			if ( $upgrader instanceof Plugin_Upgrader ) {
				$type = 'plugin';
			} elseif ( $upgrader instanceof Theme_Upgrader ) {
				$type = 'theme';
			}
		}

		$this->log_failure(
			$type,
			(string) ( $hook_extra['action'] ?? '' ),
			(string) ( $hook_extra['plugin'] ?? $hook_extra['theme'] ?? '' ),
			$source->get_error_message()
		);
		return $source;
	}

	/**
	 * @param mixed $update_results Keyed by type; each entry has item, result, name.
	 */
	public function on_automatic_updates_complete( $update_results ) {
		if ( ! is_array( $update_results ) ) {
			return;
		}

		foreach ( array( 'plugin', 'theme' ) as $type ) {
			foreach ( (array) ( $update_results[ $type ] ?? array() ) as $entry ) {
				if ( ! is_object( $entry ) || ( ! is_wp_error( $entry->result ?? null ) && false !== ( $entry->result ?? null ) ) ) {
					continue;
				}
				$name = (string) ( $entry->name ?? $entry->item->slug ?? '' );
				// A background run that failed at the copy step already hit the
				// upgrader hooks above under the same plugin file / theme slug.
				$key = (string) ( $entry->item->plugin ?? $entry->item->theme ?? '' );
				if ( '' !== $key && isset( $this->logged[ $type . '|' . $key ] ) ) {
					continue;
				}
				$this->log_failure(
					$type,
					'update',
					$name,
					is_wp_error( $entry->result ) ? $entry->result->get_error_message() : '',
					AM_Initiator_Detector::AUTO_UPDATE
				);
			}
		}
	}

	private function log_failure( string $type, string $action, string $name, string $reason, $initiator = null ) {
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			return;
		}

		$this->logged[ $type . '|' . $name ] = true;

		$event = 'install' === $action ? 'install_failed' : 'update_failed';
		if ( 'plugin' === $type ) {
			$message = 'install_failed' === $event
				/* translators: %s: plugin file path or empty */
				? __( 'Plugin install failed %s', 'activity-monitor' )
				/* translators: %s: plugin file path or empty */
				: __( 'Plugin update failed %s', 'activity-monitor' );
		} else {
			$message = 'install_failed' === $event
				/* translators: %s: theme name or empty */
				? __( 'Theme install failed %s', 'activity-monitor' )
				/* translators: %s: theme name or empty */
				: __( 'Theme update failed %s', 'activity-monitor' );
		}

		$detail = trim( ( '' !== $name ? '"' . $name . '"' : '' ) . ( '' !== $reason ? ': ' . $reason : '' ) );

		$this->log(
			$type,
			$event,
			rtrim( trim( sprintf( $message, $detail ) ), '.' ) . '.',
			array(
				'level'       => AM_Log_Levels::WARNING,
				'object_type' => $type,
				'object_name' => $name,
				'context'     => array( 'reason' => $reason ),
				'group'       => false,
				'initiator'   => $initiator,
			)
		);
	}
}
