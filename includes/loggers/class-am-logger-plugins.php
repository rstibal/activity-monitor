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

	public function slug(): string {
		return 'plugins';
	}

	public function label(): string {
		return __( 'Plugins', 'activity-monitor' );
	}

	public function register_hooks() {
		add_action( 'activated_plugin', array( $this, 'on_plugin_activated' ) );
		add_action( 'deactivated_plugin', array( $this, 'on_plugin_deactivated' ) );
		add_action( 'delete_plugin', array( $this, 'on_plugin_delete' ) );
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrader_complete' ), 10, 2 );
	}

	public function on_plugin_activated( string $plugin ) {
		$this->log(
			'plugin',
			'activated',
			sprintf(
				/* translators: %s: plugin file path */
				__( 'Plugin "%s" activated.', 'activity-monitor' ),
				$plugin
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
				/* translators: %s: plugin file path */
				__( 'Plugin "%s" deactivated.', 'activity-monitor' ),
				$plugin
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
				/* translators: %s: plugin file path */
				__( 'Plugin "%s" deleted.', 'activity-monitor' ),
				$plugin
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
					/* translators: %s: plugin file path */
					__( 'Plugin "%s" installed.', 'activity-monitor' ),
					$plugin
				),
				array(
					'level'       => AM_Log_Levels::NOTICE,
					'object_type' => 'plugin',
					'object_name' => $plugin,
				)
			);
			return;
		}

		if ( empty( $data['plugins'] ) ) {
			return;
		}

		// Automatic_Upgrader_Skin is the skin WP core's own
		// wp_maybe_auto_update() uses -- present only for an unattended
		// background update, never a manual "Update now" click -- so its
		// presence is the one signal available here that distinguishes
		// AUTO_UPDATE from the generic WP_CRON a manual update triggered
		// via WP-CLI or another cron-context caller would still get.
		$is_auto_update = $upgrader->skin instanceof Automatic_Upgrader_Skin;

		foreach ( (array) $data['plugins'] as $plugin ) {
			$this->log(
				'plugin',
				'updated',
				sprintf(
					/* translators: %s: plugin file path */
					__( 'Plugin "%s" updated.', 'activity-monitor' ),
					$plugin
				),
				array(
					'level'       => AM_Log_Levels::NOTICE,
					'object_type' => 'plugin',
					'object_name' => $plugin,
					'initiator'   => $is_auto_update ? AM_Initiator_Detector::AUTO_UPDATE : null,
				)
			);
		}
	}
}
