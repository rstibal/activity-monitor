<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_Login_Locations — a login from a country or device (browser +
 * OS) the user hasn't used before (2.9.58).
 *
 * The signal for a stolen password: the user's own logins look like each
 * other, an attacker's usually don't. Two independent checks on wp_login,
 * each its own row next to AM_Logger_Users' "User Logged In":
 *   - user.login_new_country at WARNING, so alert channels at WARNING+ hear
 *     about it;
 *   - user.login_new_device at NOTICE -- noisier (a browser switch, a new
 *     phone), so it's on record without reaching a WARNING channel.
 *
 * What a user has used is kept in KNOWN_META usermeta: country codes and
 * "browser|OS" keys, never an IP. The IP is only read here, for the local
 * AM_Stats_Geo lookup (no outside request); the row's own ip_address still
 * follows am_ip_storage through AM_Event_Writer.
 *
 * Learned silently: a list that's still empty records its first value
 * without logging, so upgrading, or turning geolocation on, doesn't flag
 * every user once. The price is that whoever logs in first after that sets
 * the baseline. Unknown values (geolocation off or not imported, a local
 * address, a browser or OS the parser calls "Other") are skipped, not
 * stored -- "unknown" changing is not a signal.
 *
 * wp_login fires for interactive logins only (wp-login.php, wp_signon());
 * application passwords and XML-RPC don't reach it.
 */
class AM_Logger_Login_Locations extends AM_Logger_Base {

	const KNOWN_META = 'am_known_logins';

	/** Most recently used values kept per list; older ones count as new again. */
	const MAX_KNOWN = 20;

	public function register_hooks() {
		// After AM_Logger_Users::on_login() (priority 10), so the plain login
		// row comes first.
		add_action( 'wp_login', array( $this, 'on_login' ), 20, 2 );
	}

	public function on_login( string $user_login, WP_User $user ) {
		$known = get_user_meta( $user->ID, self::KNOWN_META, true );
		$known = is_array( $known ) ? $known : array();

		$country = AM_Stats_Geo::country_for( AM_Client_IP::resolve() );

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) && is_string( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '';
		$device = '';
		$label  = '';
		if ( '' !== $user_agent ) {
			$ua    = AM_Stats_UA_Parser::parse( $user_agent );
			$other = __( 'Other', 'activity-monitor' );
			if ( $other !== $ua['browser'] && $other !== $ua['os'] ) {
				$device = $ua['browser'] . '|' . $ua['os'];
				/* translators: 1: browser name, 2: operating system */
				$label = sprintf( __( '%1$s on %2$s', 'activity-monitor' ), $ua['browser'], $ua['os'] );
			}
		}

		$changed = false;

		if ( '' !== $country ) {
			$list = $known['countries'] ?? array();
			if ( $list && ! in_array( $country, $list, true ) ) {
				$this->log(
					'user',
					'login_new_country',
					sprintf(
						/* translators: 1: username, 2: two-letter country code */
						__( 'User "%1$s" logged in from a new country: %2$s.', 'activity-monitor' ),
						$user_login,
						$country
					),
					$this->args( $user, AM_Log_Levels::WARNING, array( 'country' => $country ) )
				);
			}
			$known['countries'] = self::remember( $list, $country );
			$changed            = true;
		}

		if ( '' !== $device ) {
			$list = $known['devices'] ?? array();
			if ( $list && ! in_array( $device, $list, true ) ) {
				$this->log(
					'user',
					'login_new_device',
					sprintf(
						/* translators: 1: username, 2: browser and operating system, e.g. "Firefox on Linux" */
						__( 'User "%1$s" logged in from a new device: %2$s.', 'activity-monitor' ),
						$user_login,
						$label
					),
					$this->args( $user, AM_Log_Levels::NOTICE, array( 'device' => $label ) )
				);
			}
			$known['devices'] = self::remember( $list, $device );
			$changed          = true;
		}

		if ( $changed ) {
			update_user_meta( $user->ID, self::KNOWN_META, $known );
		}
	}

	/**
	 * Explicit user_id: during wp_login the auth cookie has only just been
	 * sent, so wp_get_current_user() can't be relied on to be this user yet.
	 */
	private function args( WP_User $user, string $level, array $context ): array {
		return array(
			'level'       => $level,
			'object_type' => 'user',
			'object_id'   => $user->ID,
			'object_name' => $user->user_login,
			'user_id'     => $user->ID,
			'context'     => $context,
			'group'       => false,
		);
	}

	/** $value moved to the front of $values, capped at MAX_KNOWN. */
	private static function remember( array $values, string $value ): array {
		$values = array_values( array_diff( $values, array( $value ) ) );
		array_unshift( $values, $value );
		return array_slice( $values, 0, self::MAX_KNOWN );
	}
}
