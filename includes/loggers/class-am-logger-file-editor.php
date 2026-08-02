<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_File_Editor — detects use of WordPress's built-in
 * Theme/Plugin file editor (Appearance/Plugins → Editor, or Tools →
 * Theme/Plugin File Editor on block themes).
 *
 * This is a common attack vector once an attacker has any admin
 * access: editing a theme/plugin file directly is enough to plant a
 * backdoor with no FTP/SSH access needed. It's also just useful to
 * know about for legitimate use, since a file edited outside version
 * control here is easy to lose track of on the next plugin/theme
 * update.
 *
 * No WordPress hook fires specifically "a file was just saved" by this
 * feature -- the actual write happens inside wp_edit_theme_plugin_file(),
 * a plain function (not a hook), called both from the classic editor
 * page's own POST handling and from the wp_ajax_edit-theme-plugin-file
 * AJAX action introduced when the editor moved to AJAX submission.
 * Reliably hooking "after a successful save" would mean re-implementing
 * WP core's own nonce construction ('edit-theme_{stylesheet}_{file}' /
 * 'edit-plugin_{file}', built dynamically per file) to verify it
 * ourselves -- fragile to keep in sync with a WP-internal detail that
 * isn't a documented public API.
 *
 * Instead, this logs on *detecting the save attempt* (matching POST
 * shape: file + newcontent present, with action either 'update' or
 * 'edit-theme-plugin-file') via admin_init, which fires for both the
 * classic page load and admin-ajax.php requests. The two action
 * values cover the two paths: the classic form's hidden field is
 * literally action=update, but admin-ajax.php requires
 * action=edit-theme-plugin-file to dispatch to
 * wp_ajax_edit_theme_plugin_file() at all, so the JS submitting via
 * AJAX (the default since WP 5.9) must overwrite the form's own
 * action value with that dispatch key before sending -- an earlier
 * version of this check only matched 'update' and so silently missed
 * the AJAX path entirely, which is the actual editor behavior on
 * every current WP install. This means a rejected save (bad nonce,
 * insufficient capability) may still produce a log entry even though
 * no file changed -- an accepted tradeoff: "someone attempted to edit
 * a core file" is itself useful security information, and avoiding
 * false negatives (silently missing a real edit) matters more here
 * than avoiding rare false positives on failed attempts.
 */
class AM_Logger_File_Editor extends AM_Logger_Base {

	public function slug(): string {
		return 'file_editor';
	}

	public function label(): string {
		return __( 'Theme/plugin file editor usage', 'activity-monitor' );
	}

	public function register_hooks() {
		add_action( 'admin_init', array( $this, 'on_admin_init' ) );
	}

	public function on_admin_init() {
		// See class doc for why both action values are accepted and
		// why file+newcontent (not action) is the primary signal.
		if ( empty( $_POST['file'] ) || ! isset( $_POST['newcontent'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- detection only, see class doc; not acted on.
			return;
		}
		$posted_action = (string) ( $_POST['action'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! in_array( $posted_action, array( 'update', 'edit-theme-plugin-file' ), true ) ) {
			return;
		}

		$file = sanitize_text_field( wp_unslash( $_POST['file'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! empty( $_POST['plugin'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- detection only, see class doc; not acted on.
			$plugin = sanitize_text_field( wp_unslash( $_POST['plugin'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$this->log_edit( 'plugin', $plugin, $file );
		} elseif ( ! empty( $_POST['theme'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- detection only, see class doc; not acted on.
			$theme = sanitize_text_field( wp_unslash( $_POST['theme'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$this->log_edit( 'theme', $theme, $file );
		}
	}

	private function log_edit( string $context_type, string $context_name, string $file ) {
		$this->log(
			'system',
			'file_edit_attempted',
			sprintf(
				/* translators: 1: file path, 2: theme or plugin slug */
				__( 'File editor: "%1$s" edited in %2$s.', 'activity-monitor' ),
				$file,
				$context_name
			),
			array(
				'level'       => AM_Log_Levels::ERROR,
				'object_type' => $context_type,
				'object_name' => $context_name . '/' . $file,
				// Occasion grouping keys on event_type+action+object_id
				// (see AM_Event_Writer::compute_occasion_id) -- object_id
				// is a bigint and can't carry a file path, so without this
				// every file_edit_attempted event within the grouping
				// window collapses into one row regardless of *which*
				// file was edited, silently freezing that first row's
				// level/message even for edits to a completely different
				// file. Disabled here since each edit is a distinct,
				// security-relevant action worth its own row, not noise
				// to collapse.
				'group'       => false,
			)
		);
	}
}
