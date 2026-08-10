<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_Themes — theme switch, Customizer saves, theme updates.
 *
 * Ported from v1.x AM_Hooks::on_theme_switch / on_customizer_save, and the
 * 'theme' branch of on_upgrader_complete(). AM_Logger_Plugins already
 * split off the 'plugin' branch of on_upgrader_complete in a prior
 * session; this logger takes the 'theme' branch, leaving only 'core' in
 * the legacy AM_Hooks::on_upgrader_complete() (see AM_Logger_Core, which
 * takes that last branch in the same session).
 *
 * Behavior change from v1.x: background/cron-triggered theme updates are
 * no longer silently dropped via is_automated_context() — tagged
 * initiator=wp_cron (or, as of 2.1.0, initiator=wp_auto_update
 * specifically for an unattended background update — see
 * on_upgrader_complete() below) and stay visible/filterable.
 *
 * See class-am-logger-posts.php for the template this follows.
 */
class AM_Logger_Themes extends AM_Logger_Base {

	public function register_hooks() {
		add_action( 'switch_theme', array( $this, 'on_theme_switch' ), 10, 3 );
		add_action( 'customize_save_after', array( $this, 'on_customizer_save' ) );
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrader_complete' ), 10, 2 );
	}

	public function on_theme_switch( string $new_name, WP_Theme $new_theme, WP_Theme $old_theme ) {
		$this->log(
			'theme',
			'switched',
			sprintf(
				/* translators: 1: old theme name, 2: new theme name */
				__( 'Theme switched from "%1$s" to "%2$s".', 'activity-monitor' ),
				$old_theme->get( 'Name' ),
				$new_name
			),
			array(
				'level'       => AM_Log_Levels::WARNING,
				'object_type' => 'theme',
				'object_name' => $new_name,
				'context'     => array(
					'diff' => array(
						'theme' => array( 'before' => $old_theme->get( 'Name' ), 'after' => $new_name ),
					),
				),
			)
		);
	}

	public function on_customizer_save( $manager ) {
		$this->log(
			'theme',
			'customized',
			__( 'Customizer settings saved.', 'activity-monitor' ),
			array(
				'level'       => AM_Log_Levels::NOTICE,
				'object_type' => 'theme',
				'object_name' => get_stylesheet(),
				// group defaults to true — a burst of autosaves while
				// actively working in the Customizer collapses into one row.
			)
		);
	}

	/**
	 * Only handles the theme-update branch. Plugin updates are handled by
	 * AM_Logger_Plugins; core updates by AM_Logger_Core. See class doc.
	 */
	public function on_upgrader_complete( $upgrader, array $data ) {
		if ( empty( $data['type'] ) || 'theme' !== $data['type'] || empty( $data['themes'] ) ) {
			return;
		}

		// See AM_Logger_Plugins::on_upgrader_complete() for why this skin
		// check is what distinguishes an unattended auto-update from any
		// other WP_CRON-context update.
		$is_auto_update = $upgrader->skin instanceof Automatic_Upgrader_Skin;

		foreach ( (array) $data['themes'] as $theme ) {
			$this->log(
				'theme',
				'updated',
				sprintf(
					/* translators: %s: theme name */
					__( 'Theme "%s" updated.', 'activity-monitor' ),
					$theme
				),
				array(
					'level'       => AM_Log_Levels::NOTICE,
					'object_type' => 'theme',
					'object_name' => $theme,
					'initiator'   => $is_auto_update ? AM_Initiator_Detector::AUTO_UPDATE : null,
				)
			);
		}
	}
}
