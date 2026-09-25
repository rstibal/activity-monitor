<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_Post_Details — the parts of a post that aren't columns on
 * wp_posts: page template, featured image, sticky flag, and terms
 * (categories, tags, any taxonomy). The columns themselves are diffed by
 * AM_Logger_Posts.
 *
 * These are written by separate calls after the post row is saved, so each
 * change is its own row (post.details_changed) rather than folded into the
 * "updated" one.
 *
 * A post being created gets its default category, template and any tags
 * assigned in the same wp_insert_post() call; that isn't an edit, so changes
 * are ignored from the moment a *new* post starts saving until it finishes
 * (wp_insert_post_data fires first, the wp_insert_post action last).
 */
class AM_Logger_Post_Details extends AM_Logger_Base {

	const META_KEYS = array(
		'_wp_page_template' => 'template',
		'_thumbnail_id'     => 'featured_image',
	);

	const SKIPPED_TAXONOMIES = array( 'nav_menu', 'link_category', 'post_format' );

	/** @var bool */
	private $creating = false;

	public function register_hooks() {
		add_filter( 'wp_insert_post_data', array( $this, 'on_insert_data' ), 10, 2 );
		add_action( 'wp_insert_post', array( $this, 'on_insert_done' ) );
		add_action( 'added_post_meta', array( $this, 'on_meta_added' ), 10, 4 );
		add_action( 'update_post_meta', array( $this, 'on_meta_updating' ), 10, 4 );
		add_action( 'deleted_post_meta', array( $this, 'on_meta_deleted' ), 10, 4 );
		add_action( 'set_object_terms', array( $this, 'on_terms_set' ), 10, 6 );
		add_action( 'updated_option', array( $this, 'on_option_updated' ), 10, 3 );
	}

	/**
	 * @param array $data
	 * @param array $postarr
	 * @return array
	 */
	public function on_insert_data( $data, $postarr ) {
		$this->creating = empty( $postarr['ID'] );
		return $data;
	}

	public function on_insert_done() {
		$this->creating = false;
	}

	public function on_meta_added( $meta_id, $post_id, $key, $value ) {
		$this->handle_meta( (int) $post_id, (string) $key, '', $value );
	}

	public function on_meta_updating( $meta_id, $post_id, $key, $value ) {
		if ( isset( self::META_KEYS[ $key ] ) ) {
			$this->handle_meta( (int) $post_id, (string) $key, get_post_meta( $post_id, $key, true ), $value );
		}
	}

	public function on_meta_deleted( $meta_ids, $post_id, $key, $value ) {
		if ( isset( self::META_KEYS[ $key ] ) ) {
			$this->handle_meta( (int) $post_id, (string) $key, $value, '' );
		}
	}

	/**
	 * @param mixed $before
	 * @param mixed $after
	 */
	private function handle_meta( int $post_id, string $key, $before, $after ) {
		if ( ! isset( self::META_KEYS[ $key ] ) ) {
			return;
		}

		$before = is_scalar( $before ) ? (string) $before : '';
		$after  = is_scalar( $after ) ? (string) $after : '';
		if ( $before === $after ) {
			return;
		}

		$label = self::META_KEYS[ $key ];
		$this->record( $post_id, array( $label => array( 'before' => $this->or_none( $before ), 'after' => $this->or_none( $after ) ) ) );
	}

	/**
	 * @param array $terms
	 * @param array $tt_ids
	 * @param array $old_tt_ids
	 */
	public function on_terms_set( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
		if ( in_array( $taxonomy, self::SKIPPED_TAXONOMIES, true ) ) {
			return;
		}

		$old = array_map( 'intval', (array) $old_tt_ids );
		$new = array_map( 'intval', (array) $tt_ids );
		$added   = array_diff( $new, $old );
		$removed = $append ? array() : array_diff( $old, $new );
		if ( empty( $added ) && empty( $removed ) ) {
			return;
		}

		$after = $append ? array_unique( array_merge( $old, $new ) ) : $new;
		$this->record(
			(int) $object_id,
			array(
				$taxonomy => array(
					'before' => $this->or_none( $this->term_names( $old, $taxonomy ) ),
					'after'  => $this->or_none( $this->term_names( $after, $taxonomy ) ),
				),
			)
		);
	}

	/**
	 * @param mixed $old_value
	 * @param mixed $value
	 */
	public function on_option_updated( string $option, $old_value, $value ) {
		if ( 'sticky_posts' !== $option ) {
			return;
		}

		$before = array_map( 'intval', (array) $old_value );
		$after  = array_map( 'intval', (array) $value );
		foreach ( array_diff( $after, $before ) as $post_id ) {
			$this->record( $post_id, array( 'sticky' => array( 'before' => 'no', 'after' => 'yes' ) ) );
		}
		foreach ( array_diff( $before, $after ) as $post_id ) {
			$this->record( $post_id, array( 'sticky' => array( 'before' => 'yes', 'after' => 'no' ) ) );
		}
	}

	private function or_none( string $value ): string {
		return '' === $value ? __( 'none', 'activity-monitor' ) : $value;
	}

	/**
	 * @param int[] $tt_ids
	 */
	private function term_names( array $tt_ids, string $taxonomy ): string {
		$names = array();
		foreach ( $tt_ids as $tt_id ) {
			$term = get_term_by( 'term_taxonomy_id', $tt_id, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				$names[] = $term->name;
			}
		}
		sort( $names );
		return implode( ', ', $names );
	}

	private function record( int $post_id, array $diff ) {
		if ( $this->creating ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || in_array( $post->post_status, array( 'auto-draft', 'inherit' ), true ) || in_array( $post->post_type, array( 'revision', 'user_request' ), true ) ) {
			return;
		}

		$this->log(
			'post',
			'details_changed',
			sprintf(
				/* translators: 1: post title, 2: post type, 3: comma-separated list of changed details */
				__( '"%1$s" (%2$s) details changed — %3$s.', 'activity-monitor' ),
				$post->post_title,
				$post->post_type,
				implode( ', ', array_keys( $diff ) )
			),
			array(
				'level'       => AM_Log_Levels::NOTICE,
				'object_type' => $post->post_type,
				'object_id'   => $post_id,
				'object_name' => $post->post_title,
				'context'     => array( 'diff' => $diff ),
				'group'       => false,
			)
		);
	}
}
