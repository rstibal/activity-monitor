<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Stats_UA_Parser — minimal user-agent parsing for the stats subsystem.
 *
 * Deliberately not a bundled UA database (the reference plugin this feature
 * was scoped from ships fixed browser/OS/toolbar lookup tables it joins
 * against) -- a handful of substring checks, run once at write time, is
 * enough to bucket a hit into a readable browser/OS/device_type and avoids
 * carrying a table that needs updating as new UA strings appear.
 */
class AM_Stats_UA_Parser {

	/**
	 * @return array{browser:string, os:string, device_type:string}
	 */
	public static function parse( string $user_agent ): array {
		return array(
			'browser'     => self::browser( $user_agent ),
			'os'          => self::os( $user_agent ),
			'device_type' => self::device_type( $user_agent ),
		);
	}

	/** True if the UA string matches a known bot/crawler pattern. */
	public static function is_bot( string $user_agent ): bool {
		if ( '' === $user_agent ) {
			return true; // No UA at all is never a real browser hit.
		}
		static $needles = array(
			'bot', 'spider', 'crawl', 'slurp', 'facebookexternalhit',
			'mediapartners-google', 'adsbot', 'pingdom', 'uptimerobot',
			'headlesschrome', 'phantomjs', 'wp_rocket', 'curl/', 'wget/',
		);
		$haystack = strtolower( $user_agent );
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $haystack, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	private static function browser( string $ua ): string {
		// Order matters: Edge/Opera/Chrome UAs all also contain "Safari",
		// and Chrome/Edge UAs also contain "Chrome" or "CriOS", so the
		// more specific tokens are checked first.
		$map = array(
			'Edg/'     => 'Edge',
			'Edge/'    => 'Edge',
			'OPR/'     => 'Opera',
			'Opera'    => 'Opera',
			'YaBrowser'=> 'Yandex',
			'Firefox/' => 'Firefox',
			'FxiOS/'   => 'Firefox',
			'CriOS/'   => 'Chrome',
			'Chrome/'  => 'Chrome',
			'Safari/'  => 'Safari',
			'MSIE'     => 'Internet Explorer',
			'Trident/' => 'Internet Explorer',
		);
		foreach ( $map as $needle => $label ) {
			if ( false !== strpos( $ua, $needle ) ) {
				return $label;
			}
		}
		return __( 'Other', 'activity-monitor' );
	}

	private static function os( string $ua ): string {
		$map = array(
			'Windows NT 10.0' => 'Windows 10/11',
			'Windows NT 6.3'  => 'Windows 8.1',
			'Windows NT 6.2'  => 'Windows 8',
			'Windows NT 6.1'  => 'Windows 7',
			'Windows'         => 'Windows',
			'Android'         => 'Android',
			'iPhone'          => 'iOS',
			'iPad'            => 'iOS',
			'iPod'            => 'iOS',
			'Mac OS X'        => 'macOS',
			'CrOS'            => 'Chrome OS',
			'Linux'           => 'Linux',
		);
		foreach ( $map as $needle => $label ) {
			if ( false !== strpos( $ua, $needle ) ) {
				return $label;
			}
		}
		return __( 'Other', 'activity-monitor' );
	}

	private static function device_type( string $ua ): string {
		if ( false !== strpos( $ua, 'iPad' ) || false !== strpos( $ua, 'Tablet' ) ) {
			return 'tablet';
		}
		if ( false !== strpos( $ua, 'Mobi' ) || false !== strpos( $ua, 'Android' ) || false !== strpos( $ua, 'iPhone' ) ) {
			return 'mobile';
		}
		return 'desktop';
	}
}
