<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_Media — media library events (upload, update, delete).
 *
 * Ported from v1.x AM_Hooks::on_attachment_add / on_attachment_updated /
 * on_attachment_delete.
 *
 * Behavior changes from v1.x:
 *   - attachment_updated diffs title/caption/description into event
 *     context, same pattern as AM_Logger_Posts, instead of a generic
 *     "Media updated" message with no detail on what changed.
 *   - Cron-triggered updates (e.g. programmatic thumbnail regeneration)
 *     are no longer silently dropped — tagged initiator=wp_cron and stay
 *     visible/filterable, consistent with the rest of the v2.0 loggers.
 *
 * See class-am-logger-posts.php for the template this follows.
 */
class AM_Logger_Media extends AM_Logger_Base {

	public function slug(): string {
		return 'media';
	}

	public function label(): string {
		return __( 'Media library', 'activity-monitor' );
	}

	public function register_hooks() {
		add_action( 'add_attachment', array( $this, 'on_attachment_add' ) );
		add_action( 'attachment_updated', array( $this, 'on_attachment_updated' ), 10, 3 );
		add_action( 'delete_attachment', array( $this, 'on_attachment_delete' ) );
	}

	public function on_attachment_add( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$this->log(
			'media',
			'uploaded',
			sprintf(
				/* translators: %s: file/attachment title */
				__( 'File "%s" uploaded.', 'activity-monitor' ),
				$post->post_title
			),
			array(
				'level'       => AM_Log_Levels::INFO,
				'object_type' => 'media',
				'object_id'   => $post_id,
				'object_name' => $post->post_title,
			)
		);
	}

	public function on_attachment_updated( int $post_id, WP_Post $post_after, WP_Post $post_before ) {
		$diff = array();
		if ( $post_before->post_title !== $post_after->post_title ) {
			$diff['title'] = array( 'before' => $post_before->post_title, 'after' => $post_after->post_title );
		}
		if ( $post_before->post_excerpt !== $post_after->post_excerpt ) {
			$diff['caption'] = array( 'before' => $post_before->post_excerpt, 'after' => $post_after->post_excerpt );
		}
		if ( $post_before->post_content !== $post_after->post_content ) {
			$diff['description'] = array( 'before' => $post_before->post_content, 'after' => $post_after->post_content );
		}

		if ( empty( $diff ) ) {
			return;
		}

		$this->log(
			'media',
			'updated',
			sprintf(
				/* translators: 1: media title, 2: comma-separated list of changed fields */
				__( 'Media "%1$s" updated — %2$s.', 'activity-monitor' ),
				$post_after->post_title,
				implode( ', ', array_keys( $diff ) )
			),
			array(
				'level'       => AM_Log_Levels::INFO,
				'object_type' => 'media',
				'object_id'   => $post_id,
				'object_name' => $post_after->post_title,
				'context'     => array( 'diff' => $diff ),
				'group'       => false,
			)
		);
	}

	public function on_attachment_delete( int $post_id ) {
		$post = get_post( $post_id );
		$name = $post ? $post->post_title : "attachment-{$post_id}";

		$this->log(
			'media',
			'deleted',
			sprintf(
				/* translators: 1: media title, 2: attachment ID */
				__( 'Media "%1$s" (ID %2$d) permanently deleted.', 'activity-monitor' ),
				$name,
				$post_id
			),
			array(
				'level'       => AM_Log_Levels::WARNING,
				'object_type' => 'media',
				'object_id'   => $post_id,
				'object_name' => $name,
			)
		);
	}
}
