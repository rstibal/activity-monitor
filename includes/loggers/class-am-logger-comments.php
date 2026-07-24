<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_Comments — comment lifecycle (create, edit, delete, status
 * change — approve/spam/trash).
 *
 * Ported from v1.x AM_Hooks::on_comment_insert / on_comment_edit /
 * on_comment_delete / on_comment_status. Status changes (approve → spam,
 * etc.) participate in occasion grouping by default, since a spam wave
 * hitting transition_comment_status repeatedly for the same comment is
 * noise, not signal — see activity-monitor-v2-spec.md §3.
 *
 * See class-am-logger-posts.php for the template this follows.
 */
class AM_Logger_Comments extends AM_Logger_Base {

	public function slug(): string {
		return 'comments';
	}

	public function label(): string {
		return __( 'Comments', 'activity-monitor' );
	}

	public function register_hooks() {
		add_action( 'wp_insert_comment', array( $this, 'on_comment_insert' ), 10, 2 );
		add_action( 'edit_comment', array( $this, 'on_comment_edit' ) );
		add_action( 'delete_comment', array( $this, 'on_comment_delete' ) );
		add_action( 'transition_comment_status', array( $this, 'on_comment_status' ), 10, 3 );
	}

	public function on_comment_insert( int $id, WP_Comment $comment ) {
		$this->log(
			'comment',
			'created',
			sprintf(
				/* translators: 1: post ID, 2: comment author */
				__( 'New comment on post ID %1$d by "%2$s".', 'activity-monitor' ),
				$comment->comment_post_ID,
				$comment->comment_author
			),
			array(
				'level'       => AM_Log_Levels::INFO,
				'object_type' => 'comment',
				'object_id'   => $id,
				'object_name' => 'Comment on post ' . $comment->comment_post_ID,
			)
		);
	}

	public function on_comment_edit( int $id ) {
		$comment = get_comment( $id );
		if ( ! $comment ) {
			return;
		}

		$this->log(
			'comment',
			'edited',
			sprintf(
				/* translators: 1: comment ID, 2: post ID */
				__( 'Comment (ID %1$d) on post ID %2$d edited.', 'activity-monitor' ),
				$id,
				$comment->comment_post_ID
			),
			array(
				'level'       => AM_Log_Levels::INFO,
				'object_type' => 'comment',
				'object_id'   => $id,
				'object_name' => 'Comment on post ' . $comment->comment_post_ID,
				'group'       => false,
			)
		);
	}

	public function on_comment_delete( int $id ) {
		$this->log(
			'comment',
			'deleted',
			sprintf(
				/* translators: %d: comment ID */
				__( 'Comment (ID %d) permanently deleted.', 'activity-monitor' ),
				$id
			),
			array(
				'level'       => AM_Log_Levels::NOTICE,
				'object_type' => 'comment',
				'object_id'   => $id,
				'object_name' => "comment-{$id}",
			)
		);
	}

	public function on_comment_status( string $new_status, string $old_status, WP_Comment $comment ) {
		if ( $new_status === $old_status ) {
			return;
		}

		$this->log(
			'comment',
			'status_changed',
			sprintf(
				/* translators: 1: comment ID, 2: old status, 3: new status */
				__( 'Comment (ID %1$d) status changed from "%2$s" to "%3$s".', 'activity-monitor' ),
				$comment->comment_ID,
				$old_status,
				$new_status
			),
			array(
				'level'       => AM_Log_Levels::INFO,
				'object_type' => 'comment',
				'object_id'   => (int) $comment->comment_ID,
				'object_name' => "comment-{$comment->comment_ID}",
				// group defaults to true — repeated spam-classification churn
				// on the same comment collapses into one row.
			)
		);
	}
}
