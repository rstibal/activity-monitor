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

	public function slug(): string {
		return 'sites';
	}

	public function label(): string {
		return __( 'Multisite network sites', 'activity-monitor' );
	}

	public function register_hooks() {
		if ( ! is_multisite() ) {
			return;
		}
		add_action( 'wpmu_new_blog', array( $this, 'on_site_created' ), 10, 6 );
		add_action( 'delete_blog', array( $this, 'on_site_deleted' ) );
	}

	public function on_site_created( int $blog_id, int $user_id, string $domain ) {
		$this->log(
			'site',
			'created',
			sprintf(
				/* translators: 1: domain, 2: site/blog ID */
				__( 'New site created: %1$s (ID %2$d).', 'activity-monitor' ),
				$domain,
				$blog_id
			),
			array(
				'level'       => AM_Log_Levels::NOTICE,
				'object_type' => 'site',
				'object_id'   => $blog_id,
				'object_name' => $domain,
			)
		);
	}

	public function on_site_deleted( int $blog_id ) {
		$this->log(
			'site',
			'deleted',
			sprintf(
				/* translators: %d: site/blog ID */
				__( 'Site ID %d deleted.', 'activity-monitor' ),
				$blog_id
			),
			array(
				'level'       => AM_Log_Levels::CRITICAL,
				'object_type' => 'site',
				'object_id'   => $blog_id,
				'object_name' => "site-{$blog_id}",
			)
		);
	}
}
