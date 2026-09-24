<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_Sites — multisite network site creation and deletion.
 *
 * Ported from v1.x AM_Hooks::on_site_created / on_site_deleted. Only
 * registers its hooks on multisite installs, matching v1.x's
 * `if ( is_multisite() )` guard around these same two registrations.
 *
 * Preserves v1.x's CRITICAL level for site deletion — the highest
 * severity used anywhere in the legacy plugin — since deleting an entire
 * site is the most consequential single action this plugin can observe.
 *
 * See class-am-logger-posts.php for the template this follows.
 */
class AM_Logger_Sites extends AM_Logger_Base {

	public function register_hooks() {
		if ( ! is_multisite() ) {
			return;
		}
		// wp_initialize_site / wp_delete_site, not wpmu_new_blog /
		// delete_blog: both of those have been deprecated since 5.1 and are
		// fired through do_action_deprecated(), so merely listening to them
		// raised a deprecation notice on every site create or delete --
		// which AM_Logger_Php_Warnings then logged as a row of its own.
		// Priority 100 on wp_initialize_site so core's own initialization
		// (hooked at the default 10) has already run when this logs.
		add_action( 'wp_initialize_site', array( $this, 'on_site_created' ), 100 );
		add_action( 'wp_delete_site', array( $this, 'on_site_deleted' ) );
	}

	/**
	 * Domain plus path: on a subdirectory network every site shares one
	 * domain, so the domain alone would name every site identically.
	 */
	private static function site_name( WP_Site $site ): string {
		return untrailingslashit( $site->domain . $site->path );
	}

	public function on_site_created( WP_Site $site ) {
		$blog_id = (int) $site->blog_id;
		$name    = self::site_name( $site );

		$this->log(
			'site',
			'created',
			sprintf(
				/* translators: 1: site address, 2: site/blog ID */
				__( 'New site created: %1$s (ID %2$d).', 'activity-monitor' ),
				$name,
				$blog_id
			),
			array(
				'level'       => AM_Log_Levels::NOTICE,
				'object_type' => 'site',
				'object_id'   => $blog_id,
				'object_name' => $name,
			)
		);
	}

	/** Fires after the site is gone, with the deleted site's last state. */
	public function on_site_deleted( WP_Site $site ) {
		$blog_id = (int) $site->blog_id;
		$name    = self::site_name( $site );

		$this->log(
			'site',
			'deleted',
			sprintf(
				/* translators: 1: site address, 2: site/blog ID */
				__( 'Site %1$s (ID %2$d) deleted.', 'activity-monitor' ),
				$name,
				$blog_id
			),
			array(
				'level'       => AM_Log_Levels::CRITICAL,
				'object_type' => 'site',
				'object_id'   => $blog_id,
				'object_name' => $name,
			)
		);
	}
}
