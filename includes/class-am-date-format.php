<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Date_Format — the date/time format used everywhere the plugin
 * shows a timestamp.
 *
 * Each preset is stored as a *pair* of format strings rather than one
 * combined string, and combined() joins them rather than the pair being
 * derived from a combined string -- a single string can't be split back
 * into its halves reliably, so the pair has to be the source of truth
 * if the halves are ever wanted separately.
 *
 * Nothing currently asks for a half on its own. Both callers that did
 * are gone: the Activity Log's Date column stacked date over time until
 * it went single-line in 2.0.70, and the live traffic feed showed
 * time-only until page traffic was removed in 2.2.0 (its time_format()
 * accessor went with it). The pair is kept anyway -- it costs nothing,
 * it's the shape the FORMATS table is already written in, and it's the
 * only shape that can be split later without re-deriving every preset.
 *
 * CSV and JSON export deliberately do NOT use this: those keep raw UTC
 * values so they stay machine-readable regardless of the display
 * preset.
 */
class AM_Date_Format {

	/** Option holding the selected preset key. */
	const OPTION = 'am_datetime_format';

	/** Preset used when nothing is saved: follow the site's own settings. */
	const DEFAULT_KEY = 'site';

	/**
	 * Available presets. The 'site' entry carries empty strings and is
	 * resolved at runtime from WordPress's own date_format/time_format
	 * options, so a site that changes those keeps this in step without
	 * anyone revisiting this screen.
	 */
	const FORMATS = array(
		'site'        => array( 'date' => '',          'time' => ''      ),
		'med_12'      => array( 'date' => 'M j, Y',    'time' => 'g:i a' ),
		'long_12'     => array( 'date' => 'F j, Y',    'time' => 'g:i a' ),
		'weekday_12'  => array( 'date' => 'D, M j, Y', 'time' => 'g:i a' ),
		'us_12'       => array( 'date' => 'm/d/Y',     'time' => 'g:i a' ),
		'us_short_12' => array( 'date' => 'n/j/y',     'time' => 'g:i a' ),
		'euro_24'     => array( 'date' => 'd/m/Y',     'time' => 'H:i'   ),
		'iso_24'      => array( 'date' => 'Y-m-d',     'time' => 'H:i'   ),
		'iso_24_sec'  => array( 'date' => 'Y-m-d',     'time' => 'H:i:s' ),
	);

	/** The saved preset key, falling back to the default if unrecognized. */
	public static function current_key(): string {
		$key = (string) get_option( self::OPTION, self::DEFAULT_KEY );
		return isset( self::FORMATS[ $key ] ) ? $key : self::DEFAULT_KEY;
	}

	/**
	 * Resolved date and time format strings for the current preset.
	 * Private: callers want combined(), and keeping the raw pair
	 * internal means the 'site' resolution below stays in one place.
	 *
	 * @return array{date:string, time:string}
	 */
	private static function parts(): array {
		$preset = self::FORMATS[ self::current_key() ];
		return array(
			'date' => '' !== $preset['date'] ? $preset['date'] : (string) get_option( 'date_format', 'F j, Y' ),
			'time' => '' !== $preset['time'] ? $preset['time'] : (string) get_option( 'time_format', 'g:i a' ),
		);
	}

	/** Both halves, for the single-line timestamps used everywhere. */
	public static function combined(): string {
		$parts = self::parts();
		return $parts['date'] . ' ' . $parts['time'];
	}

	/**
	 * Preset key => label for the settings dropdown, each showing how
	 * it renders right now. Live examples rather than raw format
	 * strings, since "M j, Y g:i a" tells you much less at a glance
	 * than seeing the actual output.
	 *
	 * @return array<string, string>
	 */
	public static function choices(): array {
		$now     = time();
		$choices = array();
		foreach ( self::FORMATS as $key => $preset ) {
			if ( self::DEFAULT_KEY === $key ) {
				$sample = wp_date(
					get_option( 'date_format', 'F j, Y' ) . ' ' . get_option( 'time_format', 'g:i a' ),
					$now
				);
				$choices[ $key ] = sprintf(
					/* translators: %s: an example timestamp in the site's own configured format */
					__( 'Site default — %s', 'activity-monitor' ),
					$sample
				);
				continue;
			}
			$choices[ $key ] = wp_date( $preset['date'] . ' ' . $preset['time'], $now );
		}
		return $choices;
	}
}
