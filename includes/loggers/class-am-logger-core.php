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

	/** @var string New version from _core_updated_successfully, see on_core_updated(). */
	private $new_version = '';

	public function register_hooks() {
		add_action( '_core_updated_successfully', array( $this, 'on_core_updated' ) );
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrader_complete' ), 10, 2 );
	}

	/**
	 * update_core() loads the new release's version number into its own
	 * local scope only, so the global $wp_version still holds the *old*
	 * version for the rest of the request -- reading it in
	 * on_upgrader_complete() logged "updated to" the version just replaced.
	 * This action, fired by update_core() on success just before
	 * upgrader_process_complete, is the one place handed the new number.
	 *
	 * @param string $version
	 */
	public function on_core_updated( $version ) {
		$this->new_version = (string) $version;
	}

	public function on_upgrader_complete( $upgrader, array $data ) {
		if ( empty( $data['type'] ) || 'core' !== $data['type'] ) {
			return;
		}

		// Core_Upgrader::upgrade() fires upgrader_process_complete whether or
		// not update_core() succeeded; without the success action there was
		// no update to report.
		if ( '' === $this->new_version ) {
			return;
		}
		$version = $this->new_version;

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
				$version
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
