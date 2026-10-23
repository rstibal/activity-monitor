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
 * Preserves v1.x's CRITICAL level — a logged-in user hitting a restricted
 * admin page without the capability that page itself checks is a
 * meaningful security signal, same severity tier as multisite site deletion
 * (AM_Logger_Sites). Through 2.9.52 it tested manage_options on every page,
 * so a user who legitimately has list_users (users.php), or a plugin
 * settings form that lowers options.php's requirement through
 * option_page_capability_{page}, raised a CRITICAL alert.
 */
class AM_Logger_Security extends AM_Logger_Base {

	/**
	 * Each page and the capabilities core accepts for it (any one is
	 * enough), as checked in that page's own file. See required_caps() for
	 * the two that depend on the request.
	 */
	const RESTRICTED_PAGES = array(
		'options-general.php' => array( 'manage_options' ),
		'options.php'         => array( 'manage_options' ),
		'users.php'           => array( 'list_users' ),
		'user-new.php'        => array( 'create_users' ),
		'plugins.php'         => array( 'activate_plugins' ),
	);

	public function register_hooks() {
		add_action( 'admin_init', array( $this, 'on_admin_access' ) );
	}

	public function on_admin_access() {
		if ( ! is_admin() || wp_doing_ajax() ) {
			return;
		}

		$pagenow = isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : '';
		if ( ! isset( self::RESTRICTED_PAGES[ $pagenow ] ) ) {
			return;
		}

		foreach ( $this->required_caps( $pagenow ) as $cap ) {
			if ( current_user_can( $cap ) ) {
				return;
			}
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

	/**
	 * The capabilities that let this request through $pagenow.
	 *
	 * user-new.php on multisite also admits promote_users (adding an
	 * existing user to the site). options.php is every settings form's POST
	 * target, and core lets each form set its own requirement through
	 * option_page_capability_{$option_page}, so this applies the same filter
	 * to the same request field. The field only picks the capability;
	 * options.php checks the form's nonce itself.
	 */
	private function required_caps( $pagenow ) {
		$caps = self::RESTRICTED_PAGES[ $pagenow ];

		if ( 'user-new.php' === $pagenow && is_multisite() ) {
			$caps[] = 'promote_users';
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only, picks the capability; options.php verifies the nonce.
		if ( 'options.php' === $pagenow && isset( $_REQUEST['option_page'] ) && is_string( $_REQUEST['option_page'] ) ) {
			$option_page = sanitize_text_field( wp_unslash( $_REQUEST['option_page'] ) );
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			if ( '' !== $option_page ) {
				/** This filter is documented in wp-admin/options.php */
				$caps = array( apply_filters( "option_page_capability_{$option_page}", 'manage_options' ) );
			}
		}

		return $caps;
	}
}
