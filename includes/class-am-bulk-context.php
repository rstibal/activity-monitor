<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Bulk_Context — detects when the current request is processing a
 * WP_List_Table bulk action (Posts/Pages, Media, Comments), so the
 * individual per-item events those actions already trigger can note that
 * they were part of one bulk operation.
 *
 * This does not change *what* gets logged or introduce a new event type —
 * AM_Logger_Posts/Media/Comments still write one row per object, which is
 * the correct level of audit granularity. It only appends a short note to
 * the existing message string (the one field every display surface —
 * table, Details modal, CSV/JSON export — already renders), since event
 * context beyond the 'diff' shape isn't shown anywhere. See
 * AM_Event_Writer::log()'s $args['context'] doc for that shape; this
 * deliberately doesn't use context.
 *
 * Scoped to the three screens WordPress core routes through the shared
 * handle_bulk_actions-{screen} filter (introduced 4.7). Users.php and
 * Plugins.php process their bulk actions inline instead of through that
 * filter, so they're not covered here.
 */
class AM_Bulk_Context {

	const WATCHED_SCREEN_BASES = array( 'edit', 'upload', 'edit-comments' );

	/** @var array{action: string, total: int, ids: int[]}|null */
	private static $active = null;

	public static function init() {
		add_action( 'current_screen', array( __CLASS__, 'watch_screen' ) );
	}

	public static function watch_screen( $screen ) {
		if ( ! is_object( $screen ) || ! in_array( $screen->base, self::WATCHED_SCREEN_BASES, true ) ) {
			return;
		}
		add_filter( "handle_bulk_actions-{$screen->id}", array( __CLASS__, 'capture' ), 10, 3 );
	}

	/**
	 * @param string $redirect_to Passed through untouched — this only observes.
	 * @param string $action
	 * @param int[]  $ids
	 */
	public static function capture( $redirect_to, $action, $ids ) {
		$ids = array_map( 'absint', (array) $ids );

		// A row-level "Trash"/"Delete" link on a single item goes through
		// this same filter with one id -- only count it as bulk when more
		// than one object is actually affected.
		if ( count( $ids ) > 1 ) {
			self::$active = array(
				'action' => (string) $action,
				'total'  => count( $ids ),
				'ids'    => $ids,
			);
		}

		return $redirect_to;
	}

	/**
	 * A short " (bulk "<action>", N items)" note, or '' when the given
	 * object isn't part of an active bulk action in this request.
	 */
	public static function suffix_for( int $object_id ): string {
		if ( ! self::$active || ! in_array( $object_id, self::$active['ids'], true ) ) {
			return '';
		}

		return ' ' . sprintf(
			/* translators: 1: bulk action name (e.g. "trash"), 2: number of items */
			__( '(bulk "%1$s", %2$d items)', 'activity-monitor' ),
			self::$active['action'],
			self::$active['total']
		);
	}
}
