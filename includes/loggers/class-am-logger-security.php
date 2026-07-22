<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_Security — unauthorized admin-page access attempts.
 *
 * Ported from v1.x AM_Hooks::on_admin_access(). This callback was missed
 * in the original AM_Logger_Manager TODO list (which was built from the
 * competitive-audit-derived event inventory, not a direct line-by-line
 * read of every AM_Hooks method) — caught while removing the last of the
 * password/multisite registrations and confirming nothing else remained
 * registered in the legacy class besides this and on_authenticate().
 *
 * Preserves v1.x's CRITICAL level — a logged-in user without
 * manage_options hitting a restricted admin page is a meaningful security
 * signal, same severity tier as multisite site deletion (AM_Logger_Sites).
 */
class AM_Logger_Security extends AM_Logger_Base {

	const RESTRICTED_PAGES = array(
		'options-general.php',
		'options.php',
		'users.php',
		'user-new.php',
		'plugins.php',
	);

	public function slug(): string {
		return 'security';
	}

	public function label(): string {
		return __( 'Unauthorized access attempts', 'activity-monitor' );
	}

	public function register_hooks() {
		add_action( 'admin_init', array( $this, 'on_admin_access' ) );
	}

	public function on_admin_access() {
		if ( ! is_admin() || current_user_can( 'manage_options' ) || wp_doing_ajax() ) {
			return;
		}

		$pagenow = isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : '';
		if ( ! in_array( $pagenow, self::RESTRICTED_PAGES, true ) ) {
			return;
		}

		$this->log(
			'security',
			'access_denied',
			sprintf(
				/* translators: %s: admin page filename */
				__( 'Unauthorized access attempt to "%s".', 'activity-monitor' ),
				$pagenow
			),
			array(
				'level'       => AM_Log_Levels::CRITICAL,
				'object_type' => 'security',
				'object_name' => $pagenow,
				// group defaults to true — repeated attempts against the
				// same restricted page from the same user collapse into
				// one row rather than flooding the log.
			)
		);
	}
}
