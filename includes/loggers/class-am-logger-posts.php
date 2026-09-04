<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_Posts — posts/pages/CPT events.
 *
 * Ported from v1.x AM_Hooks::on_post_updated / on_post_status_change /
 * on_post_delete / on_post_trash / on_post_untrash. Behavior changes from
 * v1.x:
 *
 *   - Cron-triggered events (e.g. WP-Cron publishing a scheduled post) are
 *     no longer silently dropped via is_automated_context() — they're
 *     tagged initiator=wp_cron by AM_Event_Writer and stay filterable.
 *     This is a deliberate behavior change; if noise from scheduled
 *     publishing turns out to be unwanted in practice, it's a filter
 *     setting now, not a code change.
 *   - Field-level diffs go into event context as structured before/after
 *     pairs (not just a "fields_changed" list), modelled on Simple
 *     History's diff feature.
 *
 * This class is the template the other AM_Logger_* subclasses follow.
 */
class AM_Logger_Posts extends AM_Logger_Base {

	public function register_hooks() {
		add_action( 'post_updated', array( $this, 'on_post_updated' ), 10, 3 );
		add_action( 'transition_post_status', array( $this, 'on_post_status_change' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'on_post_delete' ) );
		add_action( 'wp_trash_post', array( $this, 'on_post_trash' ) );
		add_action( 'untrash_post', array( $this, 'on_post_untrash' ) );
	}

	private function skip_post( WP_Post $post ): bool {
		return in_array( $post->post_status, array( 'auto-draft', 'inherit' ), true ) || 'revision' === $post->post_type;
	}

	public function on_post_updated( int $post_id, WP_Post $post_after, WP_Post $post_before ) {
		if ( $this->skip_post( $post_after ) ) {
			return;
		}

		$diff = array();
		if ( $post_before->post_title !== $post_after->post_title ) {
			$diff['title'] = array( 'before' => $post_before->post_title, 'after' => $post_after->post_title );
		}
		if ( $post_before->post_content !== $post_after->post_content ) {
			$diff['content'] = array( 'before' => $post_before->post_content, 'after' => $post_after->post_content );
		}
		if ( $post_before->post_status !== $post_after->post_status ) {
			$diff['status'] = array( 'before' => $post_before->post_status, 'after' => $post_after->post_status );
		}
		if ( $post_before->post_name !== $post_after->post_name ) {
			$diff['slug'] = array( 'before' => $post_before->post_name, 'after' => $post_after->post_name );
		}

		if ( empty( $diff ) ) {
			return;
		}

		$this->log(
			'post',
			'updated',
			sprintf(
				/* translators: 1: post title, 2: post type, 3: comma-separated list of changed fields */
				__( '"%1$s" (%2$s) updated — %3$s.', 'activity-monitor' ),
				$post_after->post_title,
				$post_after->post_type,
				implode( ', ', array_keys( $diff ) )
			),
			array(
				'level'       => AM_Log_Levels::NOTICE,
				'object_type' => $post_after->post_type,
				'object_id'   => $post_id,
				'object_name' => $post_after->post_title,
				'context'     => array( 'diff' => $diff ),
				'group'       => false, // Content edits are individually meaningful; don't collapse them.
			)
		);
	}

	public function on_post_status_change( string $new_status, string $old_status, WP_Post $post ) {
		if ( $this->skip_post( $post ) || $new_status === $old_status ) {
			return;
		}

		if ( 'publish' === $new_status && 'publish' !== $old_status ) {
			$this->log(
				'post',
				'published',
				sprintf(
					/* translators: 1: post title, 2: post type */
					__( '"%1$s" (%2$s) published.', 'activity-monitor' ),
					$post->post_title,
					$post->post_type
				),
				array(
					'level'       => AM_Log_Levels::NOTICE,
					'object_type' => $post->post_type,
					'object_id'   => $post->ID,
					'object_name' => $post->post_title,
				)
			);
		}
	}

	public function on_post_delete( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || $this->skip_post( $post ) ) {
			return;
		}

		$this->log(
			'post',
			'deleted',
			sprintf(
				/* translators: 1: post title, 2: post type, 3: post ID */
				__( '"%1$s" (%2$s, ID %3$d) permanently deleted.', 'activity-monitor' ),
				$post->post_title,
				$post->post_type,
				$post_id
			) . AM_Bulk_Context::suffix_for( $post_id ),
			array(
				'level'       => AM_Log_Levels::WARNING,
				'object_type' => $post->post_type,
				'object_id'   => $post_id,
				'object_name' => $post->post_title,
			)
		);
	}

	public function on_post_trash( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || $this->skip_post( $post ) ) {
			return;
		}

		$this->log(
			'post',
			'trashed',
			sprintf(
				/* translators: 1: post title, 2: post type */
				__( '"%1$s" (%2$s) moved to Trash.', 'activity-monitor' ),
				$post->post_title,
				$post->post_type
			) . AM_Bulk_Context::suffix_for( $post_id ),
			array(
				'level'       => AM_Log_Levels::NOTICE,
				'object_type' => $post->post_type,
				'object_id'   => $post_id,
				'object_name' => $post->post_title,
			)
		);
	}

	public function on_post_untrash( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$this->log(
			'post',
			'restored',
			sprintf(
				/* translators: 1: post title, 2: post type */
				__( '"%1$s" (%2$s) restored from Trash.', 'activity-monitor' ),
				$post->post_title,
				$post->post_type
			) . AM_Bulk_Context::suffix_for( $post_id ),
			array(
				'level'       => AM_Log_Levels::INFO,
				'object_type' => $post->post_type,
				'object_id'   => $post_id,
				'object_name' => $post->post_title,
			)
		);
	}
}
