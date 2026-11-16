<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Export — log export in CSV, JSON, HTML, and TXT formats.
 *
 * Filterable by date range, user, event type and action, using the same
 * filters the log screen itself offers, so the two can't drift apart.
 *
 * Filtering reuses AM_Event_Query::get_events() directly (in batches, see
 * stream()) rather than a separate export-specific query builder, so any
 * filter added to the log screen automatically becomes available to export
 * without duplicating WHERE-clause logic.
 *
 * Runs on admin-post.php (not AJAX) since a file download response
 * doesn't fit the wp_send_json_* pattern -- same approach v1.x used for
 * its export feature.
 */
class AM_Export {

	const NONCE_ACTION = 'am_export_log';

	/** Rows fetched, written and flushed at a time, so a large log is never held in memory whole. */
	const BATCH = 500;

	/**
	 * Streams the export directly to the browser as a file download and
	 * exits. Call only from an admin-post handler that has already done
	 * its own nonce + capability check (see AM_Admin::handle_export).
	 *
	 * Written in batches of BATCH rows (2.9.75): it used to load every
	 * matching row into one array first, which ran a big log out of memory.
	 * Batches walk down by id ("rows below the last one written") rather
	 * than by OFFSET, so each is one index range read and rows logged while
	 * the export runs -- which have higher ids -- can't shift it. That also
	 * makes the order newest-created first, by id, not by date.
	 */
	public static function stream( string $format, array $filters ) {
		$format = in_array( $format, array( 'csv', 'json', 'html', 'txt' ), true ) ? $format : 'csv';

		$filename = 'activity-monitor-export-' . gmdate( 'Y-m-d' ) . '.' . $format;

		nocache_headers();
		header( 'Content-Type: ' . self::content_type( $format ) . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		// The WP_Filesystem sniffs are suppressed rather than satisfied here
		// and below: this is php://output, the response body itself, not a
		// file on disk, and WP_Filesystem has nothing for streaming one.
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://output, see above.
		self::write( $out, self::opening( $format ) );
		if ( 'csv' === $format ) {
			fputcsv( $out, self::columns( $format ), ',', '"', '\\' );
		}

		$count     = 0;
		$before_id = 0;
		do {
			$result = AM_Event_Query::get_events( array_merge( $filters, array(
				'per_page'  => self::BATCH,
				'page'      => 1,
				'orderby'   => 'id',
				'order'     => 'DESC',
				'before_id' => $before_id,
				'count'     => false,
				'no_limit'  => false,
			) ) );
			$batch  = (array) $result['items'];
			if ( ! $batch ) {
				break;
			}

			$contexts = AM_Event_Query::get_contexts( wp_list_pluck( $batch, 'id' ) );
			foreach ( $batch as $row ) {
				$assoc = self::row_to_assoc( $row, $contexts[ (int) $row->id ] ?? array(), $format );
				switch ( $format ) {
					case 'json':
						self::write( $out, ( $count ? ",\n" : '' ) . wp_json_encode( $assoc, JSON_PRETTY_PRINT ) );
						break;
					case 'html':
						self::write( $out, self::html_row( $assoc ) );
						break;
					case 'txt':
						self::write( $out, self::txt_row( $assoc ) );
						break;
					default:
						fputcsv( $out, array_map( array( __CLASS__, 'csv_safe' ), $assoc ), ',', '"', '\\' );
				}
				++$count;
			}

			$before_id = (int) end( $batch )->id;
			flush();
		} while ( count( $batch ) === self::BATCH );

		self::write( $out, self::closing( $format ) );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output, see above.

		// The audit trail leaving the site as a file is itself an audit
		// event, same tier as core's Tools -> Export (AM_Logger_Export).
		// Written after the last batch, so the export doesn't contain its
		// own row.
		AM_Event_Writer::log(
			'log',
			'exported',
			sprintf(
				/* translators: 1: file format, e.g. CSV, 2: number of entries, e.g. "1,204 entries" */
				__( 'Activity log exported as %1$s (%2$s).', 'activity-monitor' ),
				strtoupper( $format ),
				sprintf(
					/* translators: %s: formatted number of entries */
					_n( '%s entry', '%s entries', $count, 'activity-monitor' ),
					number_format_i18n( $count )
				)
			),
			array(
				'level'       => AM_Log_Levels::WARNING,
				'object_type' => 'log',
				'object_name' => 'activity-log',
				'group'       => false,
			)
		);

		exit;
	}

	/** @param resource $out */
	private static function write( $out, string $text ) {
		if ( '' !== $text ) {
			fwrite( $out, $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- php://output, see stream().
		}
	}

	private static function content_type( string $format ): string {
		switch ( $format ) {
			case 'json': return 'application/json';
			case 'html': return 'text/html';
			case 'txt':  return 'text/plain';
			case 'csv':
			default:     return 'text/csv';
		}
	}

	/**
	 * Column order shared by every format. The ids (2.9.75) are what let an
	 * exported row be tied back to its entry, its user and its object. CSV
	 * and JSON, the machine-readable two, also carry each entry's context --
	 * the before/after values behind "Changes" in its details -- which the
	 * read-by-eye formats leave out, since it can be a whole post's content.
	 *
	 * @return string[]
	 */
	private static function columns( string $format ): array {
		$columns = array( 'id', 'date', 'level', 'initiator', 'event_type', 'action', 'user_id', 'user_login', 'user_role', 'ip_address', 'object_type', 'object_id', 'object_name', 'message', 'repeat_count' );
		if ( 'csv' === $format || 'json' === $format ) {
			$columns[] = 'context';
		}
		return $columns;
	}

	/**
	 * One row in column order. The message is the full one where the column
	 * had to cut it (the full_message context key, see
	 * AM_Event_Writer::COLUMN_LIMITS). Context is an object in JSON and a
	 * JSON string in a CSV cell, empty when the entry has none.
	 */
	private static function row_to_assoc( $row, array $context, string $format ): array {
		$out = array();
		foreach ( self::columns( $format ) as $col ) {
			$out[ $col ] = $row->{$col} ?? '';
		}

		if ( isset( $context['full_message'] ) && is_string( $context['full_message'] ) ) {
			$out['message'] = $context['full_message'];
		}
		unset( $context['full_message'] );

		if ( array_key_exists( 'context', $out ) ) {
			if ( 'json' === $format ) {
				$out['context'] = $context ? $context : new stdClass();
			} else {
				$out['context'] = $context ? (string) wp_json_encode( $context ) : '';
			}
		}
		return $out;
	}

	/**
	 * Neutralizes spreadsheet formula injection. Several columns hold text
	 * typed by anonymous visitors -- a failed login's username, a comment
	 * author's name -- and Excel/Sheets/LibreOffice run a cell beginning
	 * with = + - @ (or a tab/CR ahead of one) as a formula when the CSV is
	 * opened. A leading apostrophe makes the cell plain text. CSV only:
	 * JSON, HTML and TXT exports are never evaluated, so they keep the
	 * stored value exactly.
	 */
	private static function csv_safe( $value ) {
		$value = (string) $value;
		if ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/** What a file starts with, before its first row. */
	private static function opening( string $format ): string {
		switch ( $format ) {
			case 'csv':
				// UTF-8 BOM so Excel opens the file with correct encoding rather
				// than guessing (a common gotcha with plain UTF-8 CSVs on Windows).
				return "\xEF\xBB\xBF";
			case 'json':
				return "[\n";
			case 'html':
				$site  = esc_html( get_bloginfo( 'name' ) );
				$title = esc_html__( 'Activity Monitor Export', 'activity-monitor' );
				$html  = '<!DOCTYPE html>' . "\n" . '<html><head><meta charset="utf-8"><title>' . $title . '</title>';
				$html .= '<style>body{font-family:sans-serif;font-size:13px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:4px 8px;text-align:left}th{background:#f0f0f0}</style>';
				/* translators: %s: site name */
				$heading = sprintf( esc_html__( 'Activity Monitor Export — %s', 'activity-monitor' ), $site );
				$html   .= '</head><body>' . "\n" . "<h1>{$heading}</h1>" . "\n" . '<p>' . esc_html( gmdate( 'Y-m-d H:i:s' ) ) . ' UTC</p>' . "\n" . '<table><thead><tr>';
				foreach ( self::columns( $format ) as $col ) {
					$html .= '<th>' . esc_html( $col ) . '</th>';
				}
				return $html . "</tr></thead><tbody>\n";
		}
		return '';
	}

	/** What a file ends with, after its last row. */
	private static function closing( string $format ): string {
		switch ( $format ) {
			case 'json':
				return "\n]\n";
			case 'html':
				return '</tbody></table></body></html>';
		}
		return '';
	}

	private static function html_row( array $assoc ): string {
		$html = '<tr>';
		foreach ( $assoc as $value ) {
			$html .= '<td>' . esc_html( (string) $value ) . '</td>';
		}
		return $html . "</tr>\n";
	}

	private static function txt_row( array $assoc ): string {
		$parts = array();
		foreach ( $assoc as $key => $value ) {
			$parts[] = "{$key}: {$value}";
		}
		return implode( ' | ', $parts ) . "\n";
	}
}
