<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_Php_Warnings — catches PHP warnings, notices and deprecation
 * notices via set_error_handler(), as the non-fatal counterpart to
 * AM_Logger_Fatal_Errors.
 *
 * A different plumbing mechanism from the fatal-error catcher on purpose:
 * there is no shutdown-time signal for "a warning was emitted", only the
 * error handler itself, called synchronously at the point of the error
 * while execution continues normally.
 *
 * Unlike fatal errors, warnings/notices can fire many times per request on
 * a site with a noisy plugin or theme, so this logger relies on real
 * occasion grouping rather than disabling it: object_id is a hash of
 * file+line, so repeats of the *same* warning collapse into one row via
 * AM_Event_Writer's existing repeat_count mechanism, while a genuinely
 * different warning still gets its own row.
 */
class AM_Logger_Php_Warnings extends AM_Logger_Base {

	/** @var callable|null Previous error handler, chained to after logging. */
	private $previous_handler;

	public function slug(): string {
		return 'php_warnings';
	}

	public function label(): string {
		return __( 'PHP warnings & notices', 'activity-monitor' );
	}

	public function register_hooks() {
		$this->previous_handler = set_error_handler( array( $this, 'on_error' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions -- this *is* the logging mechanism, not leftover debugging.
	}

	public function on_error( $errno, $errstr, $errfile = '', $errline = 0 ) {
		$this->maybe_log( $errno, $errstr, $errfile, $errline );

		if ( $this->previous_handler ) {
			return call_user_func( $this->previous_handler, $errno, $errstr, $errfile, $errline );
		}

		return false; // Falls through to PHP's own handler, matching set_error_handler()'s no-previous-handler default.
	}

	private function maybe_log( $errno, $errstr, $errfile, $errline ) {
		$action = null;
		$level  = null;
		$label  = null;

		switch ( $errno ) {
			case E_WARNING:
			case E_USER_WARNING:
				$action = 'php_warning';
				$level  = AM_Log_Levels::WARNING;
				$label  = __( 'PHP Warning', 'activity-monitor' );
				break;
			case E_NOTICE:
			case E_USER_NOTICE:
				$action = 'php_notice';
				$level  = AM_Log_Levels::NOTICE;
				$label  = __( 'PHP Notice', 'activity-monitor' );
				break;
			case E_DEPRECATED:
			case E_USER_DEPRECATED:
				$action = 'php_deprecated';
				$level  = AM_Log_Levels::NOTICE;
				$label  = __( 'PHP Deprecated', 'activity-monitor' );
				break;
			default:
				return;
		}

		// Wrapped defensively, same rationale as AM_Logger_Fatal_Errors: a
		// failure here must never itself throw or output, which would
		// compound whatever the original warning already disrupted.
		try {
			$this->log(
				'system',
				$action,
				sprintf(
					/* translators: 1: "PHP Warning"/"PHP Notice"/"PHP Deprecated", 2: error message, 3: file path, 4: line number */
					__( '%1$s: %2$s in %3$s on line %4$d.', 'activity-monitor' ),
					$label,
					$errstr,
					$errfile,
					$errline
				),
				array(
					'level'       => $level,
					'object_type' => 'php',
					'object_id'   => crc32( $errfile . ':' . $errline ) & 0x7FFFFFFF,
					'object_name' => basename( $errfile ),
					'context'     => array(
						'file' => $errfile,
						'line' => $errline,
					),
				)
			);
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- intentionally silent; a failure here must not compound the warning being logged. See class doc.
			// Intentionally silent -- see class doc.
		}
	}
}
