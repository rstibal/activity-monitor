<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_Widgets — widget added to / removed from a sidebar.
 *
 * Ported from v1.x AM_Hooks::on_widget_save(). Same POST-inspection
 * approach as v1.x (the sidebar_admin_setup action doesn't pass which
 * widget/sidebar changed as arguments, so the legacy code reads $_POST
 * directly) — kept as-is rather than reworked, since it's already
 * read-only sanitized input, not a behavior this port needs to change.
 *
 * See class-am-logger-posts.php for the template this follows.
 */
class AM_Logger_Widgets extends AM_Logger_Base {

	public function slug(): string {
		return 'widgets';
	}

	public function label(): string {
		return __( 'Widgets', 'activity-monitor' );
	}

	public function register_hooks() {
		add_action( 'sidebar_admin_setup', array( $this, 'on_widget_save' ) );
	}

	public function on_widget_save() {
		if ( ! isset( $_POST['savewidget'] ) && ! isset( $_POST['removefromwidget'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		$removed = isset( $_POST['removefromwidget'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$sidebar = isset( $_POST['sidebar'] ) ? sanitize_text_field( wp_unslash( $_POST['sidebar'] ) ) : 'unknown'; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$this->log(
			'widget',
			$removed ? 'removed' : 'saved',
			sprintf(
				/* translators: 1: "removed from" or "saved to", 2: sidebar name */
				__( 'Widget %1$s sidebar "%2$s".', 'activity-monitor' ),
				$removed ? __( 'removed from', 'activity-monitor' ) : __( 'saved to', 'activity-monitor' ),
				$sidebar
			),
			array(
				'level'       => AM_Log_Levels::INFO,
				'object_type' => 'widget',
				'object_name' => $sidebar,
			)
		);
	}
}
