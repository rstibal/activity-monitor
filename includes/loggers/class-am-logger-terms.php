<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_Terms — taxonomy term events (categories, tags, and custom
 * taxonomies): create, edit, delete.
 *
 * Ported from v1.x AM_Hooks::on_term_created / on_term_edited /
 * on_term_deleted.
 *
 * See class-am-logger-posts.php for the template this follows.
 */
class AM_Logger_Terms extends AM_Logger_Base {

	public function slug(): string {
		return 'terms';
	}

	public function label(): string {
		return __( 'Categories, tags & taxonomies', 'activity-monitor' );
	}

	public function register_hooks() {
		add_action( 'created_term', array( $this, 'on_term_created' ), 10, 3 );
		add_action( 'edited_term', array( $this, 'on_term_edited' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'on_term_deleted' ), 10, 4 );
	}

	public function on_term_created( int $term_id, int $tt_id, string $taxonomy ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}

		$this->log(
			'term',
			'created',
			sprintf(
				/* translators: 1: term name, 2: taxonomy name */
				__( 'Term "%1$s" created in "%2$s".', 'activity-monitor' ),
				$term->name,
				$taxonomy
			),
			array(
				'level'       => AM_Log_Levels::INFO,
				'object_type' => 'term',
				'object_id'   => $term_id,
				'object_name' => $term->name,
			)
		);
	}

	public function on_term_edited( int $term_id, int $tt_id, string $taxonomy ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}

		$this->log(
			'term',
			'updated',
			sprintf(
				/* translators: 1: term name, 2: taxonomy name */
				__( 'Term "%1$s" in "%2$s" updated.', 'activity-monitor' ),
				$term->name,
				$taxonomy
			),
			array(
				'level'       => AM_Log_Levels::INFO,
				'object_type' => 'term',
				'object_id'   => $term_id,
				'object_name' => $term->name,
				'group'       => false,
			)
		);
	}

	public function on_term_deleted( int $term_id, int $tt_id, string $taxonomy, $deleted_term ) {
		$name = is_object( $deleted_term ) ? $deleted_term->name : "term-{$term_id}";

		$this->log(
			'term',
			'deleted',
			sprintf(
				/* translators: 1: term name, 2: taxonomy name */
				__( 'Term "%1$s" deleted from "%2$s".', 'activity-monitor' ),
				$name,
				$taxonomy
			),
			array(
				'level'       => AM_Log_Levels::NOTICE,
				'object_type' => 'term',
				'object_id'   => $term_id,
				'object_name' => $name,
			)
		);
	}
}
