<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Timeline — the Activity Log's events-per-day strip (2.9.57).
 *
 * A glance, not an investigation tool: one bar per day (per week past
 * MAX_DAILY days), stacked by level in AM_Log_Levels::ORDER so the most
 * severe sits on top, sized against the busiest bucket. Plain inline SVG,
 * no charting library; colours are the --am-sev-* tokens the row spines
 * use, so it follows the light/dark toggle and every style for free.
 *
 * It takes the same filter args as the table and counts through
 * AM_Event_Query::get_timeline_counts(), which shares build_where(), so the
 * bars can't disagree with the rows below them. It counts rows, not
 * repeat_count, for the same reason. Rendered inside #am-log-app by
 * render_log_content(), so AJAX paging/filtering redraws it with no JS of
 * its own. Hover detail is an SVG <title> per bar.
 */
class AM_Timeline {

	/** Days shown when no date filter is set. */
	const DEFAULT_DAYS = 30;

	/** Longest range still drawn one bar per day; longer ones bar per week. */
	const MAX_DAILY = 90;

	/** viewBox units per bar, and bar height. The SVG stretches to fit. */
	const SLOT   = 10;
	const HEIGHT = 80;

	/** A non-empty segment never draws thinner than this, so one critical among hundreds of info rows stays visible. */
	const MIN_SEGMENT = 2;

	/**
	 * Echoes the strip for $args (get_events()-shaped filter args), or
	 * nothing when the date filter is an empty range (From after To).
	 */
	public static function render( array $args ) {
		$range = self::range( (string) ( $args['date_from'] ?? '' ), (string) ( $args['date_to'] ?? '' ) );
		if ( ! $range ) {
			return;
		}

		$edges  = $range['edges'];
		$counts = AM_Event_Query::get_timeline_counts( $args, $edges );
		$slots  = count( $edges ) - 1;
		$totals = array();
		for ( $i = 0; $i < $slots; $i++ ) {
			$totals[ $i ] = array_sum( $counts[ $i ] ?? array() );
		}
		$total = array_sum( $totals );
		$peak  = max( $totals );

		if ( $range['default'] ) {
			/* translators: %s: number of days */
			$caption = sprintf( __( 'Last %s days', 'activity-monitor' ), number_format_i18n( self::DEFAULT_DAYS ) );
		} else {
			$caption = sprintf(
				/* translators: 1: first day of the range, 2: last day of the range */
				__( '%1$s – %2$s', 'activity-monitor' ),
				wp_date( 'M j, Y', $edges[0] ),
				wp_date( 'M j, Y', end( $edges ) - DAY_IN_SECONDS )
			);
		}
		/* translators: %s: formatted number of log entries */
		$events_text = sprintf( _n( '%s event', '%s events', $total, 'activity-monitor' ), number_format_i18n( $total ) );
		$per_text    = 7 === $range['step']
			/* translators: %s: highest number of log entries in one week */
			? __( 'busiest week %s', 'activity-monitor' )
			/* translators: %s: highest number of log entries in one day */
			: __( 'busiest day %s', 'activity-monitor' );
		?>
		<div class="am-card am-timeline">
			<div class="am-timeline-caption">
				<strong><?php echo esc_html( $caption ); ?></strong>
				<span>&middot; <?php echo esc_html( $events_text ); ?></span>
				<?php if ( $total > 0 ) : ?>
					<span>&middot; <?php echo esc_html( sprintf( $per_text, number_format_i18n( $peak ) ) ); ?></span>
				<?php endif; ?>
			</div>
			<?php if ( 0 === $total ) : ?>
				<p class="am-timeline-empty"><?php esc_html_e( 'No events in this period.', 'activity-monitor' ); ?></p>
			<?php else : ?>
				<svg class="am-timeline-chart" viewBox="0 0 <?php echo esc_attr( $slots * self::SLOT ); ?> <?php echo esc_attr( self::HEIGHT ); ?>"
				     preserveAspectRatio="none" role="img" aria-label="<?php echo esc_attr( $caption . ', ' . $events_text ); ?>">
					<?php
					for ( $i = 0; $i < $slots; $i++ ) {
						self::render_bar( $i, $counts[ $i ] ?? array(), $totals[ $i ], $peak, $edges[ $i ], $range['step'] );
					}
					?>
				</svg>
				<div class="am-timeline-axis">
					<?php foreach ( self::label_slots( $slots ) as $i ) : ?>
						<span style="left:<?php echo esc_attr( round( ( $i + 0.5 ) / $slots * 100, 2 ) ); ?>%"><?php echo esc_html( wp_date( 'M j', $edges[ $i ] ) ); ?></span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The buckets to draw, in the site's timezone, matching what the date
	 * filter means (AM_Event_Query::local_day_bound()): From and To are whole
	 * local days. Only From runs to today; only To shows the DEFAULT_DAYS
	 * ending there; neither shows the DEFAULT_DAYS ending today.
	 *
	 * Edges are each bucket's local midnight as a Unix timestamp, plus the
	 * midnight after the last day. Stepping a DateTimeImmutable in the site
	 * timezone keeps them on midnight across a DST change, where adding
	 * 86400 seconds would drift an hour.
	 *
	 * @return array{edges: int[], step: int, default: bool}|null Null for an
	 *         empty range.
	 */
	private static function range( string $date_from, string $date_to ) {
		$tz    = wp_timezone();
		$today = new DateTimeImmutable( 'today', $tz );
		$from  = self::local_day( $date_from, $tz );
		$to    = self::local_day( $date_to, $tz );

		if ( $from && $to ) {
			$start = $from;
			$end   = $to;
		} elseif ( $from ) {
			$start = $from;
			$end   = $from > $today ? $from : $today;
		} else {
			$end   = $to ? $to : $today;
			$start = $end->modify( '-' . ( self::DEFAULT_DAYS - 1 ) . ' days' );
		}
		if ( $start > $end ) {
			return null;
		}

		$days  = (int) $start->diff( $end )->days + 1;
		$step  = $days > self::MAX_DAILY ? 7 : 1;
		$edges = array();
		for ( $day = $start; $day <= $end; $day = $day->modify( "+{$step} days" ) ) {
			$edges[] = $day->getTimestamp();
		}
		$edges[] = $end->modify( '+1 day' )->getTimestamp();

		return array(
			'edges'   => $edges,
			'step'    => $step,
			'default' => ! $from && ! $to,
		);
	}

	/** A Y-m-d as that day's local midnight, or null if it isn't a real date. */
	private static function local_day( string $date, DateTimeZone $tz ) {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}
		$day = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $tz );
		return $day ? $day : null;
	}

	/**
	 * One bucket: a stacked bar (or a faint baseline tick when empty) inside
	 * a <g> whose <title> is the hover text.
	 */
	private static function render_bar( int $i, array $levels, int $total, int $peak, int $start, int $step ) {
		$x     = $i * self::SLOT + 1;
		$width = self::SLOT - 2;

		$when = 7 === $step
			/* translators: %s: first day of a week */
			? sprintf( __( 'Week of %s', 'activity-monitor' ), wp_date( 'M j', $start ) )
			: wp_date( 'D, M j', $start );
		/* translators: %s: formatted number of log entries */
		$title = $when . ': ' . sprintf( _n( '%s event', '%s events', $total, 'activity-monitor' ), number_format_i18n( $total ) );

		$parts = array();
		foreach ( array_reverse( AM_Log_Levels::ORDER ) as $level ) {
			if ( ! empty( $levels[ $level ] ) ) {
				$parts[] = number_format_i18n( $levels[ $level ] ) . ' ' . AM_Log_Levels::label( $level );
			}
		}
		if ( $parts ) {
			$title .= ' — ' . implode( ', ', $parts );
		}

		echo '<g><title>' . esc_html( $title ) . '</title>';
		if ( 0 === $total ) {
			echo '<rect class="am-tl-zero" x="' . esc_attr( $x ) . '" y="' . esc_attr( self::HEIGHT - 1 ) . '" width="' . esc_attr( $width ) . '" height="1"></rect>';
		} else {
			$heights = array();
			foreach ( AM_Log_Levels::ORDER as $level ) {
				if ( ! empty( $levels[ $level ] ) ) {
					$heights[ $level ] = max( self::MIN_SEGMENT, $levels[ $level ] / $peak * self::HEIGHT );
				}
			}
			// The minimums can push the busiest bar past the top. Shrink the
			// whole stack rather than clip it, since clipping would cut the
			// most severe segment, which is the one on top.
			$scale = min( 1, self::HEIGHT / array_sum( $heights ) );

			$y = self::HEIGHT;
			foreach ( $heights as $level => $h ) {
				$h *= $scale;
				$y -= $h;
				echo '<rect class="am-tl-' . esc_attr( $level ) . '" x="' . esc_attr( $x ) . '" y="' . esc_attr( round( $y, 2 ) ) . '" width="' . esc_attr( $width ) . '" height="' . esc_attr( round( $h, 2 ) ) . '"></rect>';
			}
		}
		echo '</g>';
	}

	/** Up to five evenly spread bucket indexes to label, always the first and last. */
	private static function label_slots( int $slots ): array {
		if ( $slots <= 5 ) {
			return range( 0, $slots - 1 );
		}
		$out = array();
		for ( $k = 0; $k < 5; $k++ ) {
			$out[] = (int) round( $k * ( $slots - 1 ) / 4 );
		}
		return array_unique( $out );
	}
}
