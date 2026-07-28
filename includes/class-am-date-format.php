<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Date_Format — the date/time format used everywhere the plugin
 * shows a timestamp.
 *
 * Each preset is stored as a *pair* of format strings rather than one
 * combined string, because the time half is sometimes needed on its
 * own: the live traffic feed shows only the time, since every row in
 * it is from today and repeating the date on each would be noise.
 * A single combined string can't be split back into its halves
 * reliably, so the pair is the source of truth and combined() joins
 * them, not the other way round.
 *
 * (The Activity Log's Date column was the other reason for the split,
 * stacking date over time on two lines; it went to a single line in
 * 2.0.70 and now uses combined() like everywhere else.)
 *
 * Chart axis labels ('M j') and the peak-hour KPI ('g A') deliberately
 * do NOT use this. Those are compact axis annotations sized to fit a
 * column, not timestamps, and a user picking a long format shouldn't
 * blow out the charts.
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
	 * Private: callers want time_format() or combined(), and keeping
	 * the raw pair internal means the 'site' resolution below stays in
	 * one place.
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

	/** Time half only — for the live traffic feed. */
	public static function time_format(): string {
		return self::parts()['time'];
	}

	/** Both halves, for the single-line timestamps used most places. */
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
