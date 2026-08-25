<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Stats_Geo — country-code lookup against am_stats_geo_ranges.
 *
 * One indexed query per call, no external request -- the table is built
 * entirely offline by AM_Stats_Geo_Updater. Deliberately just a lookup: it
 * has no idea whether the table is populated, current, or empty, and
 * returns '' in all of those cases rather than distinguishing them, since a
 * caller only ever wants either a country code or nothing to show.
 */
class AM_Stats_Geo {

	/** '' if geolocation is off, the IP doesn't parse, or nothing matches. */
	public static function country_for( string $ip ): string {
		if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		$packed = inet_pton( $ip );
		if ( false === $packed ) {
			return '';
		}
		$version = ( false !== strpos( $ip, ':' ) ) ? 6 : 4;

		global $wpdb;
		$table = $wpdb->prefix . AM_Stats_Schema::GEO_RANGES_TABLE;

		// Ranges never overlap, so the highest start_ip at or below the
		// address is the only candidate row -- end_ip is still checked
		// rather than assumed, in case the address falls in a gap the
		// dataset doesn't cover at all.
		$row = $wpdb->get_row( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
			"SELECT end_ip, country_code FROM `{$table}`
			 WHERE ip_version = %d AND start_ip <= %s
			 ORDER BY start_ip DESC
			 LIMIT 1",
			$version,
			$packed
		) );

		// strcmp(), not <, deliberately -- both operands are raw binary
		// strings, and PHP's < falls back to numeric comparison when a
		// string happens to look like a number, which would misread a
		// packed address as a decimal value instead of comparing bytes.
		if ( ! $row || strcmp( (string) $row->end_ip, $packed ) < 0 ) {
			return '';
		}

		return (string) $row->country_code;
	}
}
