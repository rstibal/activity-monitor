<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Digest — scheduled email digest summarizing recent activity.
 *
 * Per activity-monitor-v2-spec.md §4:
 *   - Configurable frequency: daily / weekly / monthly, day-of-week
 *     picker for weekly
 *   - Recipient list (multiple), preview in-browser, send-test-email
 *   - Content: totals, top event types, notable security events, link
 *     to full log
 *
 * Settings (options):
 *   am_digest_frequency        'daily' | 'weekly' | 'monthly' | '' (off)
 *   am_digest_day_of_week      0-6 (Sunday=0), only used when weekly
 *   am_digest_recipients       comma-separated email list
 *   am_digest_last_sent        Y-m-d H:i:s UTC, set after each send
 */
class AM_Digest {

	const CRON_HOOK = 'am_send_digest';

	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'maybe_send' ) );
	}

	/**
	 * (Re)schedule the digest cron to match the configured frequency.
	 * Call this whenever the frequency setting changes, and once on
	 * plugin load to catch a setting that was changed while the cron
	 * event coincidentally didn't fire.
	 */
	public static function reschedule() {
		$existing = wp_next_scheduled( self::CRON_HOOK );
		if ( $existing ) {
			wp_unschedule_event( $existing, self::CRON_HOOK );
		}

		$frequency = get_option( 'am_digest_frequency', '' );
		if ( '' === $frequency ) {
			return; // Digest disabled.
		}

		// All frequencies check daily and self-limit via last-sent
		// comparison in maybe_send() -- this avoids WP-Cron's built-in
		// schedules not offering a native 'monthly' recurrence, and
		// keeps the "day of week" setting meaningful for weekly without
		// fighting cron's own day-of-week scheduling.
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
	 * Cron callback -- checks whether a digest is actually due (per the
	 * configured frequency and last-sent time) before sending, since the
	 * underlying cron event always fires daily regardless of frequency.
	 */
	public static function maybe_send() {
		$frequency = get_option( 'am_digest_frequency', '' );
		if ( '' === $frequency ) {
			return;
		}

		if ( ! self::is_due( $frequency ) ) {
			return;
		}

		self::send();
	}

	private static function is_due( string $frequency ): bool {
		$last_sent = get_option( 'am_digest_last_sent', '' );
		if ( '' === $last_sent ) {
			return true; // Never sent -- send now regardless of frequency.
		}

		$last_sent_ts = strtotime( $last_sent . ' UTC' );
		$now          = time();

		switch ( $frequency ) {
			case 'daily':
				return ( $now - $last_sent_ts ) >= DAY_IN_SECONDS;
			case 'weekly':
				if ( ( $now - $last_sent_ts ) < WEEK_IN_SECONDS ) {
					return false;
				}
				$configured_day = absint( get_option( 'am_digest_day_of_week', 1 ) ); // Default Monday.
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

	/** Send the digest now, to the configured recipients, and record the send time. */
	public static function send(): bool {
		$frequency  = get_option( 'am_digest_frequency', 'weekly' );
		$recipients = self::get_recipients();
		if ( empty( $recipients ) ) {
			return false;
		}

		$html = self::build_html( $frequency );
		$site = wp_strip_all_tags( get_bloginfo( 'name' ) );

		$sent = wp_mail(
			$recipients,
			/* translators: %s: site name */
			sprintf( __( '[%s] Activity Monitor Digest', 'activity-monitor' ), $site ),
			$html,
			array( 'Content-Type: text/html; charset=UTF-8' )
		);

		if ( $sent ) {
			update_option( 'am_digest_last_sent', current_time( 'mysql', true ) );
		}

		return $sent;
	}

	/** Send a test digest to one address, without affecting the last-sent timestamp. */
	public static function send_test( string $to_email ): bool {
		if ( ! is_email( $to_email ) ) {
			return false;
		}
		$frequency = get_option( 'am_digest_frequency', 'weekly' );
		$html      = self::build_html( $frequency, true );
		$site      = wp_strip_all_tags( get_bloginfo( 'name' ) );

		return wp_mail(
			$to_email,
			/* translators: %s: site name */
			sprintf( __( '[%s] Activity Monitor Digest (test)', 'activity-monitor' ), $site ),
			$html,
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}

	private static function get_recipients(): array {
		$raw = get_option( 'am_digest_recipients', '' );
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
					<td style="padding: 6px 0; border-bottom: 1px solid #f0f0f1;"><code><?php echo esc_html( $type ); ?></code></td>
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
