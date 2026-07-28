<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Digest — scheduled email digest(s) summarizing recent activity.
 *
 * Per activity-monitor-v2-spec.md §4, extended to support MULTIPLE
 * independent digest configs (e.g. a daily digest to one address and a
 * separate weekly digest to another) -- originally a single
 * frequency/day/recipients/last-sent set of scalar options, changed to
 * a list per a later request once it became clear a single config
 * couldn't represent "daily to ops@, weekly to owner@" at the same time.
 *
 * Storage: am_digest_configs, an array of:
 *   array(
 *     'id'           => string  -- stable ID (uniqid-based), used as
 *                                  the array key so edits/deletes/cron
 *                                  checks target a specific config even
 *                                  after others are added/removed
 *     'frequency'    => 'daily' | 'weekly' | 'monthly'
 *     'day_of_week'  => 0-6 (Sunday=0), only used when weekly
 *     'recipients'   => comma-separated email string
 *     'last_sent'    => Y-m-d H:i:s UTC, or '' if never sent
 *   )
 *
 * Migration: the old scalar options (am_digest_frequency/
 * am_digest_day_of_week/am_digest_recipients/am_digest_last_sent) are
 * read once, on demand, by get_configs() if am_digest_configs doesn't
 * exist yet -- converting any existing single config into the first
 * entry of the new array rather than silently discarding it. The old
 * options are left in place afterward (harmless, just unused) rather
 * than deleted, since there's no reason to risk losing data on a
 * migration that doesn't need to be destructive.
 *
 * Cron: still one single daily WP-Cron tick (there's no benefit to N
 * separate scheduled events when the "is this one actually due today"
 * check already has to happen per-config regardless of how many exist
 * -- see maybe_send(), which now loops every config and sends whichever
 * ones are due).
 */
class AM_Digest {

	const CRON_HOOK = 'am_send_digest';

	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'maybe_send' ) );
	}

	/**
	 * Ensures the daily cron tick is scheduled whenever at least one
	 * config exists. Call this after any config is added, and once on
	 * plugin load to catch a config that was added while the cron event
	 * coincidentally didn't fire.
	 */
	public static function reschedule() {
		$existing = wp_next_scheduled( self::CRON_HOOK );
		if ( $existing ) {
			wp_unschedule_event( $existing, self::CRON_HOOK );
		}

		if ( empty( self::get_configs() ) ) {
			return; // No digests configured at all.
		}

		// All frequencies check daily and self-limit via last-sent
		// comparison in is_due() -- this avoids WP-Cron's built-in
		// schedules not offering a native 'monthly' recurrence, and
		// keeps "day of week" meaningful for weekly without fighting
		// cron's own day-of-week scheduling. One tick serves every
		// config; each is checked independently in maybe_send().
		wp_schedule_event( self::next_daily_check_time(), 'daily', self::CRON_HOOK );
	}

	private static function next_daily_check_time(): int {
		// Run early morning site-local time so the digest reflects a full
		// prior day/week/month rather than a partial one.
		$offset_seconds = (int) round( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );
		$local_now      = time() + $offset_seconds;
		$local_midnight = strtotime( 'today 06:00', $local_now );
		if ( $local_midnight <= $local_now ) {
			$local_midnight = strtotime( 'tomorrow 06:00', $local_now );
		}
		return $local_midnight - $offset_seconds;
	}

	/**
	 * Cron callback -- checks every configured digest independently and
	 * sends whichever ones are actually due, since the underlying cron
	 * event fires once daily regardless of any individual config's
	 * frequency.
	 */
	public static function maybe_send() {
		foreach ( self::get_configs() as $config ) {
			if ( self::is_due( $config ) ) {
				self::send( $config['id'] );
			}
		}
	}

	private static function is_due( array $config ): bool {
		$last_sent = $config['last_sent'] ?? '';
		if ( '' === $last_sent ) {
			return true; // Never sent -- send now regardless of frequency.
		}

		$last_sent_ts = strtotime( $last_sent . ' UTC' );
		$now          = time();
		$frequency    = $config['frequency'] ?? '';

		switch ( $frequency ) {
			case 'daily':
				return ( $now - $last_sent_ts ) >= DAY_IN_SECONDS;
			case 'weekly':
				if ( ( $now - $last_sent_ts ) < WEEK_IN_SECONDS ) {
					return false;
				}
				$configured_day = absint( $config['day_of_week'] ?? 1 ); // Default Monday.
				$offset_seconds = (int) round( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );
				$today_local    = (int) gmdate( 'w', $now + $offset_seconds );
				return $today_local === $configured_day;
			case 'monthly':
				return ( $now - $last_sent_ts ) >= 28 * DAY_IN_SECONDS;
			default:
				return false;
		}
	}

	/**
	 * Days covered by the digest, matching the configured frequency --
	 * used both for the actual send and for the in-browser preview so
	 * they show identical content.
	 */
	public static function period_days_for_frequency( string $frequency ): int {
		switch ( $frequency ) {
			case 'weekly':
				return 7;
			case 'monthly':
				return 30;
			case 'daily':
			default:
				return 1;
		}
	}

	/**
	 * All configured digests. Migrates the old single-config scalar
	 * options into the new array format on first access if
	 * am_digest_configs doesn't exist yet -- see class doc.
	 *
	 * @return array<int, array{id:string, frequency:string, day_of_week:int, recipients:string, last_sent:string}>
	 */
	public static function get_configs(): array {
		$configs = get_option( 'am_digest_configs', null );
		if ( is_array( $configs ) ) {
			return array_values( $configs );
		}

		// Not yet migrated -- check for a pre-existing single config
		// under the old option names and carry it forward as-is rather
		// than starting empty and silently dropping someone's existing
		// digest setup.
		$old_frequency = get_option( 'am_digest_frequency', '' );
		if ( '' === $old_frequency ) {
			update_option( 'am_digest_configs', array() );
			return array();
		}

		$migrated = array(
			array(
				'id'          => 'digest_' . uniqid(),
				'frequency'   => $old_frequency,
				'day_of_week' => absint( get_option( 'am_digest_day_of_week', 1 ) ),
				'recipients'  => (string) get_option( 'am_digest_recipients', '' ),
				'last_sent'   => (string) get_option( 'am_digest_last_sent', '' ),
			),
		);
		update_option( 'am_digest_configs', $migrated );
		return $migrated;
	}

	public static function get_config( string $id ): ?array {
		foreach ( self::get_configs() as $config ) {
			if ( $config['id'] === $id ) {
				return $config;
			}
		}
		return null;
	}

	private static function save_configs( array $configs ) {
		update_option( 'am_digest_configs', array_values( $configs ) );
	}

	/** Adds a new digest config and returns its generated id. */
	public static function add_config( string $frequency, int $day_of_week, string $recipients ): string {
		$configs = self::get_configs();
		$id      = 'digest_' . uniqid();
		$configs[] = array(
			'id'          => $id,
			'frequency'   => $frequency,
			'day_of_week' => $day_of_week,
			'recipients'  => $recipients,
			'last_sent'   => '',
		);
		self::save_configs( $configs );
		self::reschedule();
		return $id;
	}

	/** Updates an existing config's settings in place, preserving its last_sent history. */
	public static function update_config( string $id, string $frequency, int $day_of_week, string $recipients ): bool {
		$configs = self::get_configs();
		foreach ( $configs as $i => $config ) {
			if ( $config['id'] === $id ) {
				$configs[ $i ]['frequency']   = $frequency;
				$configs[ $i ]['day_of_week'] = $day_of_week;
				$configs[ $i ]['recipients']  = $recipients;
				self::save_configs( $configs );
				self::reschedule();
				return true;
			}
		}
		return false;
	}

	public static function delete_config( string $id ) {
		$configs = array_values( array_filter( self::get_configs(), function ( $c ) use ( $id ) {
			return $c['id'] !== $id;
		} ) );
		self::save_configs( $configs );
		self::reschedule();
	}

	/** Send one specific configured digest now, and record its send time. */
	public static function send( string $config_id ): bool {
		$config = self::get_config( $config_id );
		if ( ! $config ) {
			return false;
		}

		$recipients = self::get_recipients( $config['recipients'] );
		if ( empty( $recipients ) ) {
			return false;
		}

		$html = self::build_html( $config['frequency'] );
		$site = wp_strip_all_tags( get_bloginfo( 'name' ) );

		$sent = wp_mail(
			$recipients,
			/* translators: %s: site name */
			sprintf( __( '[%s] Activity Monitor Digest', 'activity-monitor' ), $site ),
			$html,
			array( 'Content-Type: text/html; charset=UTF-8' )
		);

		if ( $sent ) {
			$configs = self::get_configs();
			foreach ( $configs as $i => $c ) {
				if ( $c['id'] === $config_id ) {
					$configs[ $i ]['last_sent'] = current_time( 'mysql', true );
					break;
				}
			}
			self::save_configs( $configs );
		}

		return $sent;
	}

	/**
	 * Send a test digest to one address using a given frequency (not
	 * tied to any stored config -- a test picks its own frequency
	 * ad-hoc in the modal), without affecting any config's last-sent
	 * timestamp.
	 */
	public static function send_test( string $to_email, string $frequency = 'weekly' ): bool {
		if ( ! is_email( $to_email ) ) {
			return false;
		}
		$html = self::build_html( $frequency, true );
		$site = wp_strip_all_tags( get_bloginfo( 'name' ) );

		return wp_mail(
			$to_email,
			/* translators: %s: site name */
			sprintf( __( '[%s] Activity Monitor Digest (test)', 'activity-monitor' ), $site ),
			$html,
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}

	private static function get_recipients( string $raw ): array {
		return array_filter( array_map( 'trim', explode( ',', $raw ) ), 'is_email' );
	}

	/**
	 * Build the digest HTML. Shared by send(), send_test(), and the
	 * in-browser preview (AM_Admin::render_tab_settings) so all three
	 * show identical content for a given frequency.
	 */
	public static function build_html( string $frequency, bool $is_test = false ): string {
		$days = self::period_days_for_frequency( $frequency );

		$totals   = AM_Event_Query::get_totals_for_period( $days );
		$by_type  = AM_Event_Query::get_breakdown_by_event_type( $days, 5 );
		$notable  = AM_Event_Query::get_notable_events( $days, 10 );
		$site     = esc_html( get_bloginfo( 'name' ) );
		$log_url  = esc_url( admin_url( 'admin.php?page=activity-monitor' ) );
		$period   = self::period_label( $frequency );

		$delta        = $totals['current'] - $totals['previous'];
		$delta_str    = $delta >= 0 ? "+{$delta}" : (string) $delta;
		$delta_color  = $delta > 0 ? '#b32d2e' : ( $delta < 0 ? '#2271b1' : '#646970' );

		ob_start();
		?>
		<div style="font-family: -apple-system, Segoe UI, Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #1d2327;">
			<?php if ( $is_test ) : ?>
			<div style="background: #fff3cd; border: 1px solid #ffe69c; padding: 10px 14px; border-radius: 4px; margin-bottom: 16px; font-size: 13px;">
				<?php esc_html_e( 'This is a test send. It does not affect the scheduled digest timing.', 'activity-monitor' ); ?>
			</div>
			<?php endif; ?>

			<h1 style="font-size: 20px; margin-bottom: 4px;"><?php esc_html_e( 'Activity Monitor Digest', 'activity-monitor' ); ?></h1>
			<p style="color: #646970; margin-top: 0;"><?php echo esc_html( $site ); ?> &middot; <?php echo esc_html( $period ); ?></p>

			<table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
				<tr>
					<td style="padding: 12px; background: #f6f7f7; border-radius: 4px;">
						<div style="font-size: 28px; font-weight: 600;"><?php echo esc_html( number_format_i18n( $totals['current'] ) ); ?></div>
						<div style="font-size: 12px; color: #646970;">
							<?php esc_html_e( 'Total events', 'activity-monitor' ); ?>
							&nbsp;<span style="color: <?php echo esc_attr( $delta_color ); ?>;"><?php echo esc_html( $delta_str ); ?></span>
							<?php esc_html_e( 'vs. previous period', 'activity-monitor' ); ?>
						</div>
					</td>
				</tr>
			</table>

			<?php if ( ! empty( $by_type ) ) : ?>
			<h2 style="font-size: 15px; margin-bottom: 8px;"><?php esc_html_e( 'Top event types', 'activity-monitor' ); ?></h2>
			<table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
				<?php foreach ( $by_type as $type => $count ) : ?>
				<tr>
					<td style="padding: 6px 0; border-bottom: 1px solid #f0f0f1;"><?php echo esc_html( AM_Event_Labels::type_label( $type ) ); ?></td>
					<td style="padding: 6px 0; border-bottom: 1px solid #f0f0f1; text-align: right;"><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
				</tr>
				<?php endforeach; ?>
			</table>
			<?php endif; ?>

			<?php if ( ! empty( $notable ) ) : ?>
			<h2 style="font-size: 15px; margin-bottom: 8px;"><?php esc_html_e( 'Notable events (warning and above)', 'activity-monitor' ); ?></h2>
			<table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
				<?php foreach ( $notable as $row ) : ?>
				<tr>
					<td style="padding: 6px 0; border-bottom: 1px solid #f0f0f1; font-size: 13px;">
						<strong><?php echo esc_html( AM_Log_Levels::label( $row->level ) ); ?></strong>
						— <?php echo esc_html( $row->message ); ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</table>
			<?php else : ?>
			<p style="font-size: 13px; color: #646970;"><?php esc_html_e( 'No warning-level or higher events during this period.', 'activity-monitor' ); ?></p>
			<?php endif; ?>

			<p style="margin-top: 24px;">
				<a href="<?php echo $log_url; ?>" style="color: #2271b1;"><?php esc_html_e( 'View full activity log', 'activity-monitor' ); ?> &rarr;</a>
			</p>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function period_label( string $frequency ): string {
		switch ( $frequency ) {
			case 'daily':
				return __( 'Last 24 hours', 'activity-monitor' );
			case 'monthly':
				return __( 'Last 30 days', 'activity-monitor' );
			case 'weekly':
			default:
				return __( 'Last 7 days', 'activity-monitor' );
		}
	}
}
