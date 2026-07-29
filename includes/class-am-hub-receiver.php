<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Hub_Receiver — REST endpoint a hub install uses to receive check-ins
 * from other sites running Activity Monitor (see AM_Hub_Reporter, the
 * sending side).
 *
 * This is the plugin's first REST route. Every other cross-request
 * interaction in this plugin is either an admin-post form or a logged-in
 * wp_ajax_* action, because those all originate from an authenticated
 * browser session in this site's own wp-admin. A check-in is different:
 * it's an unauthenticated, cross-site, server-to-server POST, so a WP
 * nonce (session-bound) can't apply. REST's permission_callback is the
 * correct primitive for that -- a shared-secret header, checked with
 * hash_equals() -- rather than a bare wp_ajax_nopriv_* action gated only
 * by ad-hoc logic in the callback body.
 */
class AM_Hub_Receiver {

	const REST_NAMESPACE = 'activity-monitor/v1';
	const REST_ROUTE     = '/checkin';
	const SECRET_HEADER  = 'x-am-hub-secret';

	public static function register_routes() {
		register_rest_route( self::REST_NAMESPACE, self::REST_ROUTE, array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_checkin' ),
			'permission_callback' => array( __CLASS__, 'permission_check' ),
		) );
	}

	/**
	 * Requires hub mode to be enabled and a secret header matching the
	 * configured am_hub_secret. Deliberately does not log rejected
	 * attempts to the Activity Log -- a public REST route draws scanner
	 * and bot noise, and logging every bad request would flood the log
	 * with nothing an admin can act on (see CLAUDE.md's "noise control,
	 * not noise" principle).
	 */
	public static function permission_check( WP_REST_Request $request ) {
		if ( ! get_option( 'am_hub_enabled' ) ) {
			return false;
		}

		$secret = (string) get_option( 'am_hub_secret', '' );
		if ( '' === $secret ) {
			return false;
		}

		$given = (string) $request->get_header( self::SECRET_HEADER );
		if ( '' === $given ) {
			return false;
		}

		return hash_equals( $secret, $given );
	}

	public static function handle_checkin( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$site_url = esc_url_raw( (string) ( $params['site_url'] ?? '' ) );
		if ( '' === $site_url ) {
			return new WP_Error( 'am_invalid_site_url', __( 'Missing or invalid site_url.', 'activity-monitor' ), array( 'status' => 400 ) );
		}

		AM_Installs::upsert( array(
			'site_url'       => $site_url,
			'plugin_version' => sanitize_text_field( substr( (string) ( $params['plugin_version'] ?? '' ), 0, 20 ) ),
			'wp_version'     => sanitize_text_field( substr( (string) ( $params['wp_version'] ?? '' ), 0, 20 ) ),
			'php_version'    => sanitize_text_field( substr( (string) ( $params['php_version'] ?? '' ), 0, 20 ) ),
		) );

		return rest_ensure_response( array( 'success' => true ) );
	}
}
