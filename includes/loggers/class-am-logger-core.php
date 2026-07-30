<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_Core — WordPress core updates.
 *
 * Ported from the 'core' branch of v1.x AM_Hooks::on_upgrader_complete().
 * This is the last of the three branches that method originally handled
 * (plugin → AM_Logger_Plugins, theme → AM_Logger_Themes, core → here) —
 * once this logger is registered, the legacy on_upgrader_complete() in
 * AM_Hooks has no remaining branches to handle and its
 * upgrader_process_complete registration can be safely removed (done in
 * this same change; see class-am-hooks.php).
 *
 * Behavior change from v1.x: background/cron-triggered core auto-updates
 * (WordPress's own security auto-update mechanism runs via wp-cron) are
 * no longer silently dropped — tagged initiator=wp_auto_update as of
 * 2.1.0 (previously the generic wp_cron) and stay visible/filterable.
 * This is arguably more useful than v1.x's behavior: knowing exactly when
 * an automatic security update landed is valuable audit information, not
 * noise.
 */
class AM_Logger_Core extends AM_Logger_Base {

	public function slug(): string {
		return 'core';
	}

	public function label(): string {
		return __( 'WordPress core updates', 'activity-monitor' );
	}

	public function register_hooks() {
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrader_complete' ), 10, 2 );
	}

	public function on_upgrader_complete( $upgrader, array $data ) {
		if ( empty( $data['type'] ) || 'core' !== $data['type'] ) {
			return;
		}

		global $wp_version;

		// See AM_Logger_Plugins::on_upgrader_complete() for why this skin
		// check is what distinguishes an unattended auto-update from any
		// other WP_CRON-context update.
		$is_auto_update = $upgrader->skin instanceof Automatic_Upgrader_Skin;

		$this->log(
			'core',
			'updated',
			sprintf(
				/* translators: %s: WordPress version number */
				__( 'WordPress core updated to %s.', 'activity-monitor' ),
				$wp_version
			),
			array(
				'level'       => AM_Log_Levels::NOTICE,
				'object_type' => 'core',
				'object_name' => 'WordPress',
				'initiator'   => $is_auto_update ? AM_Initiator_Detector::AUTO_UPDATE : null,
			)
		);
	}
}
