<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_Plugins — plugin lifecycle (activate, deactivate, delete,
 * update).
 *
 * Ported from v1.x AM_Hooks::on_plugin_activated / on_plugin_deactivated /
 * on_plugin_delete, and the plugin branch of on_upgrader_complete().
 *
 * v1.x's on_upgrader_complete() handled plugin, theme, AND core updates in
 * one callback with a shared is_automated_context() guard that silently
 * dropped background/cron-triggered auto-updates. Split here: this logger
 * owns only the plugin-update branch. Theme updates and core updates are
 * NOT yet ported (still handled by the legacy on_upgrader_complete() in
 * AM_Hooks) — see AM_Logger_Manager TODO list. Do not remove
 * on_upgrader_complete() from AM_Hooks until AM_Logger_Themes exists,
 * or theme/core updates will silently stop being logged entirely.
 *
 * Behavior change from v1.x: background/cron-triggered plugin updates are
 * no longer dropped -- tagged initiator=wp_cron (or, as of 2.1.0,
 * initiator=wp_auto_update specifically for an unattended background
 * update -- see on_upgrader_complete() below) and stay visible/filterable.
 *
 * See class-am-logger-posts.php for the template this follows.
 */
class AM_Logger_Plugins extends AM_Logger_Base {

	/** @var array<string,bool> Plugin file => whether its copy into place succeeded, this request. */
	private $results = array();

	/** @var array<string,string> Plugin file => the version it had before this request's update. */
	private $old_versions = array();

	/**
	 * A plugin's name and version, read from its main file's header.
	 *
	 * Messages name the plugin this way ("Akismet Anti-spam 5.3") rather than
	 * by its file ("akismet/akismet.php"), which stays as the row's
	 * object_name: it is what identifies the plugin across renames and what
	 * occasion grouping keys on. get_file_data() rather than
	 * get_plugin_data(), which only exists once wp-admin's includes are
	 * loaded. Falls back to the file path when the header can't be read.
	 *
	 * @return array{name:string, version:string}
	 */
	private static function header( string $plugin ): array {
		$out  = array( 'name' => $plugin, 'version' => '' );
		$file = WP_PLUGIN_DIR . '/' . $plugin;
		if ( 0 !== validate_file( $plugin ) || ! is_file( $file ) || ! is_readable( $file ) ) {
			return $out;
		}

		$data = get_file_data( $file, array( 'name' => 'Plugin Name', 'version' => 'Version' ), 'plugin' );
		$name = trim( wp_strip_all_tags( (string) $data['name'] ) );
		if ( '' !== $name ) {
			$out['name'] = $name;
		}
		$out['version'] = trim( wp_strip_all_tags( (string) $data['version'] ) );
		return $out;
	}

	/** "Name 1.2.3", or just the name when no version is known. */
	private static function describe( string $plugin ): string {
		$header = self::header( $plugin );
		return '' !== $header['version'] ? $header['name'] . ' ' . $header['version'] : $header['name'];
	}

	/**
	 * Notes the version about to be replaced, while the old files are still
	 * there. A filter: $response goes back unchanged.
	 *
	 * @param mixed $response
	 * @param mixed $hook_extra
	 * @return mixed
	 */
	public function on_pre_install( $response, $hook_extra ) {
		if ( is_array( $hook_extra ) && ! empty( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) ) {
			$this->old_versions[ $hook_extra['plugin'] ] = self::header( $hook_extra['plugin'] )['version'];
		}
		return $response;
	}

	public function register_hooks() {
		add_filter( 'upgrader_pre_install', array( $this, 'on_pre_install' ), 10, 2 );
		add_filter( 'upgrader_install_package_result', array( $this, 'on_install_package_result' ), 999, 2 );
		add_action( 'activated_plugin', array( $this, 'on_plugin_activated' ) );
		add_action( 'deactivated_plugin', array( $this, 'on_plugin_deactivated' ) );
		add_action( 'delete_plugin', array( $this, 'on_plugin_delete' ) );
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrader_complete' ), 10, 2 );
	}

	/**
	 * Notes how each plugin's update ended. upgrader_process_complete fires
	 * whether or not the copy into place worked, and for a bulk run it lists
	 * every plugin attempted, so on its own it logged a failed update as
	 * "updated" (next to AM_Logger_Update_Failures' row saying it failed).
	 * A filter: $result goes back unchanged.
	 *
	 * @param mixed $result
	 * @param mixed $hook_extra
	 * @return mixed
	 */
	public function on_install_package_result( $result, $hook_extra ) {
		if ( is_array( $hook_extra ) && ! empty( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) ) {
			$this->results[ $hook_extra['plugin'] ] = $result && ! is_wp_error( $result );
		}
		return $result;
	}

	public function on_plugin_activated( string $plugin ) {
		$this->log(
			'plugin',
			'activated',
			sprintf(
				/* translators: %s: plugin name and version */
				__( 'Plugin "%s" activated.', 'activity-monitor' ),
				self::describe( $plugin )
			),
			array(
				'level'       => AM_Log_Levels::NOTICE,
				'object_type' => 'plugin',
				'object_name' => $plugin,
			)
		);
	}

	public function on_plugin_deactivated( string $plugin ) {
		$this->log(
			'plugin',
			'deactivated',
			sprintf(
				/* translators: %s: plugin name and version */
				__( 'Plugin "%s" deactivated.', 'activity-monitor' ),
				self::describe( $plugin )
			),
			array(
				'level'       => AM_Log_Levels::WARNING,
				'object_type' => 'plugin',
				'object_name' => $plugin,
			)
		);
	}

	public function on_plugin_delete( string $plugin ) {
		$this->log(
			'plugin',
			'deleted',
			sprintf(
				/* translators: %s: plugin name and version */
				__( 'Plugin "%s" deleted.', 'activity-monitor' ),
				self::describe( $plugin )
			),
			array(
				'level'       => AM_Log_Levels::WARNING,
				'object_type' => 'plugin',
				'object_name' => $plugin,
			)
		);
	}

	/**
	 * Handles both the plugin-update branch (existing) and, as of this
	 * change, the plugin-install branch. Theme and core branches are
	 * intentionally left to AM_Logger_Themes / AM_Logger_Core.
	 *
	 * The install and update branches use different shapes: update
	 * provides $data['plugins'] (an array, since bulk updates are
	 * possible); a fresh single-plugin install provides no such key at
	 * all -- the installed plugin's identity has to be read from
	 * $upgrader->plugin_info(), which WP core itself uses for this
	 * exact purpose (see Plugin_Upgrader::plugin_info() -- resolves
	 * $upgrader->result['destination_name'] to the actual main plugin
	 * file via get_plugins()).
	 */
	public function on_upgrader_complete( $upgrader, array $data ) {
		if ( empty( $data['type'] ) || 'plugin' !== $data['type'] ) {
			return;
		}

		if ( 'install' === ( $data['action'] ?? '' ) ) {
			$plugin = ( $upgrader instanceof Plugin_Upgrader ) ? $upgrader->plugin_info() : false;
			if ( ! $plugin ) {
				return;
			}
			$this->log(
				'plugin',
				'installed',
				sprintf(
					/* translators: %s: plugin name and version */
					__( 'Plugin "%s" installed.', 'activity-monitor' ),
					self::describe( $plugin )
				),
				array(
					'level'       => AM_Log_Levels::NOTICE,
					'object_type' => 'plugin',
					'object_name' => $plugin,
				)
			);
			return;
		}

		// Plugin_Upgrader::bulk_upgrade() (the Plugins and Updates screens)
		// reports a 'plugins' list; upgrade() -- background auto-updates,
		// update.php's own "update now" link, WP-CLI -- reports one 'plugin'.
		// Reading only the list meant none of the latter were ever logged.
		if ( ! empty( $data['plugins'] ) ) {
			$plugins = (array) $data['plugins'];
		} elseif ( ! empty( $data['plugin'] ) ) {
			$plugins = array( $data['plugin'] );
		} else {
			return;
		}

		// Automatic_Upgrader_Skin is the skin WP core's own
		// wp_maybe_auto_update() uses -- present only for an unattended
		// background update, never a manual "Update now" click -- so its
		// presence is the one signal available here that distinguishes
		// AUTO_UPDATE from the generic WP_CRON a manual update triggered
		// via WP-CLI or another cron-context caller would still get.
		$is_auto_update = $upgrader->skin instanceof Automatic_Upgrader_Skin;

		foreach ( $plugins as $plugin ) {
			// Only a known failure is skipped: an updater that never went
			// through WP_Upgrader::run() leaves no result, and is logged as
			// before.
			if ( ! is_string( $plugin ) || false === ( $this->results[ $plugin ] ?? true ) ) {
				continue;
			}
			$header  = self::header( $plugin );
			$old     = $this->old_versions[ $plugin ] ?? '';
			$context = array();
			if ( '' !== $old && '' !== $header['version'] && $old !== $header['version'] ) {
				$message = sprintf(
					/* translators: 1: plugin name, 2: old version, 3: new version */
					__( 'Plugin "%1$s" updated from %2$s to %3$s.', 'activity-monitor' ),
					$header['name'],
					$old,
					$header['version']
				);
				$context = array( 'diff' => array( 'version' => array( 'before' => $old, 'after' => $header['version'] ) ) );
			} else {
				$message = sprintf(
					/* translators: %s: plugin name and version */
					__( 'Plugin "%s" updated.', 'activity-monitor' ),
					self::describe( $plugin )
				);
			}

			$this->log(
				'plugin',
				'updated',
				$message,
				array(
					'level'       => AM_Log_Levels::NOTICE,
					'object_type' => 'plugin',
					'object_name' => $plugin,
					'context'     => $context,
					'initiator'   => $is_auto_update ? AM_Initiator_Detector::AUTO_UPDATE : null,
				)
			);
		}
	}
}
