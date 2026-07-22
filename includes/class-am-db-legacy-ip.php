<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_DB_Legacy_IP — IP resolution logic ported unchanged from v1.x
 * AM_DB::get_ip() / is_cloudflare_ip() / ip_in_cidr(). This code was
 * security-reviewed as part of the v1.3.0 patch (Cloudflare CIDR
 * validation, X-Forwarded-For intentionally not trusted) — extracted
 * into its own class so AM_Event_Writer can reuse it without depending
 * on the legacy AM_DB class, but the logic itself is untouched.
 */
class AM_DB_Legacy_IP {

	public static function resolve(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore

		if (
			! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && // phpcs:ignore
			self::is_cloudflare_ip( $remote )
		) {
			$ip = trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) ); // phpcs:ignore
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}

		// REMOTE_ADDR is the TCP-level address – cannot be spoofed.
		$ip = trim( sanitize_text_field( wp_unslash( $remote ) ) );
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return $ip;
		}

		return '0.0.0.0';
	}

	private static function is_cloudflare_ip( string $ip ): bool {
		if ( empty( $ip ) ) return false;
		static $cf_ranges = array(
			'103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
			'104.16.0.0/13',   '104.24.0.0/14',   '108.162.192.0/18',
			'131.0.72.0/22',   '141.101.64.0/18',  '162.158.0.0/15',
			'172.64.0.0/13',   '173.245.48.0/20',  '188.114.96.0/20',
			'190.93.240.0/20', '197.234.240.0/22', '198.41.128.0/17',
			'2400:cb00::/32',  '2405:8100::/32',   '2405:b500::/32',
			'2606:4700::/32',  '2803:f800::/32',   '2a06:98c0::/29',
			'2c0f:f248::/32',
		);
		foreach ( $cf_ranges as $cidr ) {
			if ( self::ip_in_cidr( $ip, $cidr ) ) return true;
		}
		return false;
	}

	private static function ip_in_cidr( string $ip, string $cidr ): bool {
		list( $subnet, $bits ) = explode( '/', $cidr );
		$bits = (int) $bits;

		// IPv6
		if ( strpos( $subnet, ':' ) !== false ) {
			if ( strpos( $ip, ':' ) === false ) return false;
			$ip_bin  = inet_pton( $ip );
			$net_bin = inet_pton( $subnet );
			if ( false === $ip_bin || false === $net_bin ) return false;
			$fb = $bits >> 3;
			$rb = $bits & 7;
			if ( substr( $ip_bin, 0, $fb ) !== substr( $net_bin, 0, $fb ) ) return false;
			if ( $rb ) {
				$mask = 0xFF & ( 0xFF << ( 8 - $rb ) );
				if ( ( ord( $ip_bin[ $fb ] ) & $mask ) !== ( ord( $net_bin[ $fb ] ) & $mask ) ) return false;
			}
			return true;
		}

		// IPv4
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) return false;
		$mask = $bits ? ( ~0 << ( 32 - $bits ) ) : 0;
		return ( ip2long( $ip ) & $mask ) === ( ip2long( $subnet ) & $mask );
	}
}
