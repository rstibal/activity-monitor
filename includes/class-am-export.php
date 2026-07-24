<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Export — log export in CSV, JSON, HTML, and TXT formats.
 *
 * Per activity-monitor-v2-spec.md §4 (build order item 6): "CSV and JSON
 * at minimum ... HTML/TXT as a stretch goal ... filterable by date
 * range, user, event type, action -- reuse the log screen's filter
 * component so export and the log view don't drift apart."
 *
 * Filtering reuses AM_Event_Query::get_events() directly (with
 * no_limit => true) rather than a separate export-specific query
 * builder, so any filter added to the log screen automatically becomes
 * available to export without duplicating WHERE-clause logic.
 *
 * Runs on admin-post.php (not AJAX) since a file download response
 * doesn't fit the wp_send_json_* pattern -- same approach v1.x used for
 * its export feature.
 */
class AM_Export {

	const NONCE_ACTION = 'am_export_log';

	/**
	 * Streams the export directly to the browser as a file download and
	 * exits. Call only from an admin-post handler that has already done
	 * its own nonce + capability check (see AM_Admin::handle_export).
	 */
	public static function stream( string $format, array $filters ) {
		$format = in_array( $format, array( 'csv', 'json', 'html', 'txt' ), true ) ? $format : 'csv';

		$result = AM_Event_Query::get_events( array_merge( $filters, array( 'no_limit' => true ) ) );
		$items  = $result['items'];

		$filename = 'activity-monitor-export-' . gmdate( 'Y-m-d' ) . '.' . $format;

		nocache_headers();
		header( 'Content-Type: ' . self::content_type( $format ) . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		switch ( $format ) {
			case 'json':
				echo self::to_json( $items ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- file download body, not HTML output.
				break;
			case 'html':
				echo self::to_html( $items ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped internally per-cell in to_html().
				break;
			case 'txt':
				echo self::to_txt( $items ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- file download body, not HTML output.
				break;
			case 'csv':
			default:
				self::stream_csv( $items );
				break;
		}

		exit;
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

	/** @return string[] Column order shared by every format for consistency. */
	private static function columns(): array {
		return array( 'date', 'level', 'initiator', 'event_type', 'action', 'user_login', 'user_role', 'ip_address', 'object_type', 'object_name', 'message', 'repeat_count' );
	}

	private static function row_to_assoc( $row ): array {
		$out = array();
		foreach ( self::columns() as $col ) {
			$out[ $col ] = $row->{$col} ?? '';
		}
		return $out;
	}

	private static function stream_csv( array $items ) {
		$out = fopen( 'php://output', 'w' );
		// UTF-8 BOM so Excel opens the file with correct encoding rather
		// than guessing (a common gotcha with plain UTF-8 CSVs on Windows).
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, self::columns() );
		foreach ( $items as $row ) {
			fputcsv( $out, self::row_to_assoc( $row ) );
		}
		fclose( $out );
	}

	private static function to_json( array $items ): string {
		$rows = array_map( array( __CLASS__, 'row_to_assoc' ), $items );
		return wp_json_encode( $rows, JSON_PRETTY_PRINT );
	}

	private static function to_html( array $items ): string {
		$site  = esc_html( get_bloginfo( 'name' ) );
		$title = esc_html__( 'Activity Monitor Export', 'activity-monitor' );
		$html  = '<!DOCTYPE html>' . "\n" . '<html><head><meta charset="utf-8"><title>' . $title . '</title>';
		$html .= '<style>body{font-family:sans-serif;font-size:13px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:4px 8px;text-align:left}th{background:#f0f0f0}</style>';
		/* translators: %s: site name */
		$heading = sprintf( esc_html__( 'Activity Monitor Export — %s', 'activity-monitor' ), $site );
		$html .= '</head><body>' . "\n" . "<h1>{$heading}</h1>" . "\n" . '<p>' . esc_html( gmdate( 'Y-m-d H:i:s' ) ) . ' UTC</p>' . "\n" . '<table><thead><tr>';
		foreach ( self::columns() as $col ) {
			$html .= '<th>' . esc_html( $col ) . '</th>';
		}
		$html .= "</tr></thead><tbody>\n";
		foreach ( $items as $row ) {
			$html .= '<tr>';
			foreach ( self::row_to_assoc( $row ) as $value ) {
				$html .= '<td>' . esc_html( (string) $value ) . '</td>';
			}
			$html .= "</tr>\n";
		}
		$html .= '</tbody></table></body></html>';
		return $html;
	}

	private static function to_txt( array $items ): string {
		$lines = array();
		foreach ( $items as $row ) {
			$assoc = self::row_to_assoc( $row );
			$parts = array();
			foreach ( $assoc as $key => $value ) {
				$parts[] = "{$key}: {$value}";
			}
			$lines[] = implode( ' | ', $parts );
		}
		return implode( "\n", $lines ) . "\n";
	}
}
