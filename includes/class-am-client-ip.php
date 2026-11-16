<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Client_IP — the visitor's address for a request, including behind a
 * reverse proxy or load balancer the site owner has named (2.9.80).
 *
 * AM_DB_Legacy_IP::resolve() is still what decides by default, and is still
 * untouched: REMOTE_ADDR, or Cloudflare's header when REMOTE_ADDR is a
 * Cloudflare address. That leaves every other proxy -- an nginx or Varnish
 * front end, a cloud load balancer, a host's own edge -- where REMOTE_ADDR
 * is the proxy, so every row carried the same address, IP lookups pointed
 * at the host, and AM_Logger_Login_Locations never saw a new country.
 *
 * Forwarding headers are typed by whoever sends the request, so one is only
 * believed when both settings are filled in and the request really came
 * from a listed proxy:
 *   - am_proxy_header: which header the proxy sets (a key of HEADERS).
 *   - am_trusted_proxies: the proxy's own addresses, as IPs or CIDR ranges.
 * With either missing, or REMOTE_ADDR not on the list, this returns exactly
 * what AM_DB_Legacy_IP::resolve() does. A visitor connecting straight to
 * the server can therefore send any header they like and change nothing.
 *
 * From X-Forwarded-For, which each proxy appends to, the address used is
 * the rightmost one that isn't itself a trusted proxy: everything to the
 * left of that was supplied by the client and can't be trusted.
 *
 * Every caller that used AM_DB_Legacy_IP::resolve() goes through here
 * instead, so the log, alerts, new-country detection and Visitor Stats all
 * agree on who the visitor is.
 */
class AM_Client_IP {

	const HEADER_OPTION  = 'am_proxy_header';
	const PROXIES_OPTION = 'am_trusted_proxies';

	/** Setting value => the $_SERVER key that header arrives under. '' (not in this list) means none. */
	const HEADERS = array(
		'x-forwarded-for' => 'HTTP_X_FORWARDED_FOR',
		'x-real-ip'       => 'HTTP_X_REAL_IP',
	);

	public static function resolve(): string {
		$ip = AM_DB_Legacy_IP::resolve();

		// Explicit defaults: most log writes happen outside the admin, where
		// register_setting()'s defaults aren't registered.
		$header  = (string) get_option( self::HEADER_OPTION, '' );
		$proxies = self::proxies();
		if ( ! isset( self::HEADERS[ $header ] ) || ! $proxies ) {
			return $ip;
		}

		$remote = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : '';
		// A different answer already means Cloudflare supplied the address.
		if ( $ip !== $remote || ! self::is_trusted( $remote, $proxies ) ) {
			return $ip;
		}

		$key = self::HEADERS[ $header ];
		$raw = isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) : '';
		if ( '' === $raw ) {
			return $ip;
		}

		foreach ( array_reverse( explode( ',', $raw ) ) as $candidate ) {
			$candidate = self::strip_port( trim( $candidate ) );
			if ( ! filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				return $ip; // A malformed chain isn't something to guess from.
			}
			if ( ! self::is_trusted( $candidate, $proxies ) ) {
				return $candidate;
			}
		}

		return $ip; // Nothing but proxies in the header.
	}

	/**
	 * The saved proxy list, already validated by sanitize_proxies().
	 *
	 * @return string[]
	 */
	public static function proxies(): array {
		$stored = get_option( self::PROXIES_OPTION, array() );
		return is_array( $stored ) ? array_values( array_filter( $stored, 'is_string' ) ) : array();
	}

	/**
	 * One entry per line or comma: an IPv4/IPv6 address or a CIDR range.
	 * Anything else is dropped, so resolve() never meets an entry it can't
	 * compare against.
	 *
	 * @param mixed $input The textarea's text (or an already-saved array).
	 * @return string[]
	 */
	public static function sanitize_proxies( $input ): array {
		if ( is_array( $input ) ) {
			$input = implode( "\n", array_filter( $input, 'is_string' ) );
		}
		$entries = preg_split( '/[\s,]+/', (string) $input, -1, PREG_SPLIT_NO_EMPTY );

		$clean = array();
		foreach ( (array) $entries as $entry ) {
			$entry = strtolower( trim( $entry ) );
			if ( self::is_valid_range( $entry ) ) {
				$clean[] = $entry;
			}
		}
		return array_values( array_unique( $clean ) );
	}

	private static function is_valid_range( string $entry ): bool {
		if ( false === strpos( $entry, '/' ) ) {
			return (bool) filter_var( $entry, FILTER_VALIDATE_IP );
		}
		list( $subnet, $bits ) = explode( '/', $entry, 2 );
		if ( ! filter_var( $subnet, FILTER_VALIDATE_IP ) || ! ctype_digit( $bits ) ) {
			return false;
		}
		return (int) $bits <= ( false !== strpos( $subnet, ':' ) ? 128 : 32 );
	}

	/** @param string[] $proxies */
	private static function is_trusted( string $ip, array $proxies ): bool {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		foreach ( $proxies as $range ) {
			if ( self::in_range( $ip, $range ) ) {
				return true;
			}
		}
		return false;
	}

	/** Whether $ip (already validated) is $range, or inside it when it's a CIDR. Never matches across IPv4/IPv6. */
	private static function in_range( string $ip, string $range ): bool {
		if ( ! self::is_valid_range( $range ) ) {
			return false;
		}
		$bits = null;
		if ( false !== strpos( $range, '/' ) ) {
			list( $range, $bits ) = explode( '/', $range, 2 );
		}

		$ip_bin  = inet_pton( $ip );
		$net_bin = inet_pton( $range );
		if ( false === $ip_bin || false === $net_bin || strlen( $ip_bin ) !== strlen( $net_bin ) ) {
			return false;
		}
		if ( null === $bits ) {
			return $ip_bin === $net_bin;
		}

		$bits  = (int) $bits;
		$whole = $bits >> 3;
		$rest  = $bits & 7;
		if ( substr( $ip_bin, 0, $whole ) !== substr( $net_bin, 0, $whole ) ) {
			return false;
		}
		if ( 0 === $rest ) {
			return true;
		}
		$mask = 0xFF & ( 0xFF << ( 8 - $rest ) );
		return ( ord( $ip_bin[ $whole ] ) & $mask ) === ( ord( $net_bin[ $whole ] ) & $mask );
	}

	/** "203.0.113.4:51234" and "[2001:db8::1]:443" without the port; anything else as given. */
	private static function strip_port( string $value ): string {
		if ( preg_match( '/^\[([0-9a-fA-F:.]+)\](?::\d+)?$/', $value, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $value, $m ) ) {
			return $m[1];
		}
		return $value;
	}
}
