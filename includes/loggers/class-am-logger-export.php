<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_Export — logs a run of WordPress's built-in content exporter
 * (Tools -> Export). Core fires 'export_wp' with the export args just
 * before writing the XML file, whether the request came from the browser
 * or WP-CLI's `wp export`.
 *
 * This is the one built-in way to pull the whole site's content out as a
 * downloadable file -- 'content' => 'all' walks posts, pages, and every
 * other post type in one file -- so it's worth its own WARNING-level row
 * even though nothing about it is inherently wrong.
 */
class AM_Logger_Export extends AM_Logger_Base {

	public function register_hooks() {
		add_action( 'export_wp', array( $this, 'on_export' ) );
	}

	/** @param array $args See export_wp() in wp-admin/includes/export.php: content, author, category, start_date, end_date, status. */
	public function on_export( $args ) {
		if ( ! is_array( $args ) ) {
			$args = array();
		}

		$content = isset( $args['content'] ) ? (string) $args['content'] : 'all';

		$this->log(
			'system',
			'export',
			sprintf(
				/* translators: %s: exported content type, e.g. "all content", "posts", "pages" */
				__( 'Site content exported (%s).', 'activity-monitor' ),
				'all' === $content
					? __( 'all content', 'activity-monitor' )
					: $content
			),
			array(
				'level'       => AM_Log_Levels::WARNING,
				'object_type' => 'site',
				// Each export run is its own discrete event, not noise to
				// collapse -- same reasoning as password_reset/password_set.
				'group'       => false,
			)
		);
	}
}
