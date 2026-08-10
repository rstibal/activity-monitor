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
 * different warning still gets its own row. Grouping alone isn't enough
 * though -- see $seen below for why, and $handling for what happens when
 * the log write itself trips a warning.
 */
class AM_Logger_Php_Warnings extends AM_Logger_Base {

	/** @var callable|null Previous error handler, chained to after logging. */
	private $previous_handler;

	/** @var bool Re-entrancy guard while a log write is in progress. See on_error(). */
	private $handling = false;

	/** @var array<string,true> Keys already written this request. See maybe_log(). */
	private $seen = array();

	public function register_hooks() {
		$this->previous_handler = set_error_handler( array( $this, 'on_error' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions -- this *is* the logging mechanism, not leftover debugging.
	}

	public function on_error( $errno, $errstr, $errfile = '', $errline = 0 ) {
		// The log write below can itself emit a warning or notice -- an
		// undefined index deep in IP resolution, a $wpdb warning, a
		// deprecation inside a core function it calls -- and this handler
		// is called synchronously, so that re-enters here immediately.
		// Without the guard it recurses until the stack is exhausted, and
		// the fatal that ends it is exactly the kind this plugin then
		// can't log. maybe_log()'s try/catch does not cover this: warnings
		// are not Throwable. Only the logging is guarded, never the chain
		// below -- whatever handler was already installed must still see
		// every error, guard or no guard.
		if ( ! $this->handling ) {
			$this->handling = true;
			try {
				$this->maybe_log( $errno, $errstr, $errfile, $errline );
			} finally {
				$this->handling = false;
			}
		}

		if ( $this->previous_handler ) {
			return call_user_func( $this->previous_handler, $errno, $errstr, $errfile, $errline );
		}

		return false; // Falls through to PHP's own handler, matching set_error_handler()'s no-previous-handler default.
	}

	private function maybe_log( $errno, $errstr, $errfile, $errline ) {
		// Honour suppression. The `@` operator drops $errno out of
		// error_reporting()'s mask for the duration of the call (it
		// returned a bare 0 before PHP 8), and a site's configured
		// error_reporting level means the same thing on purpose: the
		// author of that call knows it can fail and has handled it.
		// WordPress core suppresses heavily -- @fopen, @unlink,
		// @getimagesize, @ini_set -- so without this check the log fills
		// with warnings from code that is working exactly as written.
		//
		// Both sniffs below fire on any mention of error_reporting(); both
		// are about *setting* it (runtime configuration change, path
		// disclosure from turning display on). This only reads the mask.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- reading the mask, not setting it; this is the standard suppression check for an error handler.
		if ( ! ( error_reporting() & $errno ) ) {
			return;
		}

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

		// Occasion grouping collapses repeats in the *table*, but not the
		// work: every occurrence still costs a SELECT plus an UPDATE in
		// AM_Event_Writer::maybe_increment_existing(). A loop emitting the
		// same notice a few thousand times would spend that many round
		// trips on one page load. So the same warning is written at most
		// once per request; the row it wrote already says this warning
		// happened here, and cross-request repeats still collapse through
		// occasion grouping as normal. The tradeoff is that repeat_count
		// then counts requests in which the warning fired rather than raw
		// occurrences, which is the more actionable number anyway.
		//
		// Keyed on the action too, not just file+line: one line can emit
		// both a warning and a deprecation notice, and those are separate
		// rows downstream (occasion_id keys on event_type+action+object_id).
		$key = $action . '|' . $errfile . ':' . $errline;
		if ( isset( $this->seen[ $key ] ) ) {
			return;
		}
		$this->seen[ $key ] = true;

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
