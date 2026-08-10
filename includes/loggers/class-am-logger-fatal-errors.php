<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_Fatal_Errors — catches PHP fatal errors (E_ERROR, E_PARSE,
 * E_COMPILE_ERROR, E_CORE_ERROR) via a shutdown handler.
 *
 * This is the one logger in this codebase not driven by a WordPress
 * action hook -- there's no WP hook for "a fatal error just happened"
 * (by definition, execution has already stopped by the time WP could
 * fire one). register_shutdown_function() runs even after a fatal
 * error, which is what makes this catchable at all.
 *
 * Deliberately narrow to fatal-class errors only (not E_WARNING,
 * E_NOTICE, E_DEPRECATED, etc.) -- those fire constantly on most real
 * sites from third-party plugins/themes and would flood the log with
 * noise unrelated to "something broke". A fatal error, by contrast, is
 * always worth knowing about: it's the WordPress equivalent of a
 * white-screen-of-death, most often surfacing right after a plugin or
 * theme update.
 *
 * Best-effort on the DB write: if the fatal error was itself caused by
 * exhausted memory or a broken DB connection, the log write may fail
 * silently. That's an acceptable tradeoff -- there's no reliable way to
 * guarantee logging survives every possible fatal-error cause, and a
 * failed write here must never itself throw or output anything (that
 * would compound the very error page this is trying to log).
 */
class AM_Logger_Fatal_Errors extends AM_Logger_Base {

	const FATAL_ERROR_TYPES = array( E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR, E_USER_ERROR );

	public function register_hooks() {
		register_shutdown_function( array( $this, 'on_shutdown' ) );
	}

	public function on_shutdown() {
		$error = error_get_last();
		if ( ! $error || ! in_array( $error['type'], self::FATAL_ERROR_TYPES, true ) ) {
			return;
		}

		// Wrapped defensively: this runs at the very end of the request
		// lifecycle, potentially after something has already gone
		// seriously wrong (OOM, broken DB connection). A failure here
		// must never surface its own error/output.
		try {
			$this->log(
				'system',
				'fatal_error',
				sprintf(
					/* translators: 1: error message, 2: file path, 3: line number */
					__( 'PHP fatal error: %1$s in %2$s on line %3$d.', 'activity-monitor' ),
					$error['message'],
					$error['file'],
					$error['line']
				),
				array(
					'level'       => AM_Log_Levels::ERROR,
					'object_type' => 'php',
					'object_name' => basename( $error['file'] ),
					'context'     => array(
						'file' => $error['file'],
						'line' => $error['line'],
					),
					// Same reasoning as AM_Logger_File_Editor: grouping
					// keys on event_type+action+object_id, and object_id
					// can't carry a file/line, so without this a second,
					// genuinely different fatal error within the window
					// would silently collapse into the first one's row.
					'group'       => false,
				)
			);
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- intentionally silent; a failure here must not compound the fatal error being logged. See class doc.
			// Intentionally silent -- see class doc.
		}
	}
}
