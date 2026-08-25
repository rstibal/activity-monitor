<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Stats_Geo_Updater — downloads and imports MaxMind's GeoLite2-Country
 * CSV edition into am_stats_geo_ranges.
 *
 * Free "GeoLite" MaxMind accounts are rate-limited to 30 direct downloads
 * per 24 hours (confirmed against MaxMind's own docs, Aug 2026) -- tight
 * enough that the daily cron only ever does a HEAD request to check
 * Last-Modified, and only pulls the full ~1M-row dataset when it's actually
 * changed (MaxMind publishes new builds Tuesdays and Fridays).
 *
 * The import itself is a self-chunking background job, not one blocking
 * call: WordPress hosting commonly caps PHP execution well under the time a
 * ~1M-row parse+insert would take in a single request. Each cron tick does
 * a bounded amount of work (see BATCH_ROWS) and reschedules the next tick
 * via wp_schedule_single_event() until every stage finishes. Progress lives
 * in the am_stats_geo_import_progress option, not a class property, since
 * each tick is its own PHP process.
 *
 * Rows land in a staging table and are only swapped into the real
 * am_stats_geo_ranges via RENAME TABLE once the whole import succeeds --
 * AM_Stats_Geo's lookups never see a half-populated table.
 */
class AM_Stats_Geo_Updater {

	const DOWNLOAD_URL     = 'https://download.maxmind.com/geoip/databases/GeoLite2-Country-CSV/download?suffix=zip';
	const PROGRESS_OPTION  = 'am_stats_geo_import_progress';
	const LOCK_TRANSIENT   = 'am_stats_geo_import_lock';
	const CHECK_HOOK       = 'am_stats_geo_check';
	const TICK_HOOK        = 'am_stats_geo_import_tick';

	/** Rows processed per cron tick for the blocks CSVs. */
	const BATCH_ROWS = 5000;
	/** Rows per multi-row INSERT statement. */
	const INSERT_CHUNK = 500;

	public static function init() {
		add_action( self::CHECK_HOOK, array( __CLASS__, 'maybe_check_for_update' ) );
		add_action( self::TICK_HOOK, array( __CLASS__, 'process_tick' ) );
	}

	// ── Status, for the Settings screen ─────────────────────────────────

	/** @return array{configured:bool, enabled:bool, in_progress:bool, stage:string, error:string, last_updated:int, row_count:int} */
	public static function status(): array {
		global $wpdb;
		$progress = get_option( self::PROGRESS_OPTION, array() );
		$table    = $wpdb->prefix . AM_Stats_Schema::GEO_RANGES_TABLE;

		return array(
			'configured'   => self::has_credentials(),
			'enabled'      => (bool) get_option( 'am_stats_geo_enabled', 0 ),
			'in_progress'  => ! empty( $progress ) && 'error' !== ( $progress['stage'] ?? '' ),
			'stage'        => (string) ( $progress['stage'] ?? '' ),
			'error'        => (string) ( $progress['error'] ?? '' ),
			'last_updated' => (int) get_option( 'am_stats_geo_last_updated', 0 ),
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			'row_count'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
		);
	}

	public static function has_credentials(): bool {
		return '' !== (string) get_option( 'am_stats_geo_account_id', '' )
			&& '' !== (string) get_option( 'am_stats_geo_license_key', '' );
	}

	// ── Scheduled check ──────────────────────────────────────────────────

	/**
	 * Daily cron. A HEAD request costs nothing against the 30/day quota
	 * that a full download wouldn't also cost, so this runs every day even
	 * though MaxMind only ships new builds twice a week -- simpler than
	 * trying to track their publish schedule, and still nowhere near the
	 * quota (well under 30 requests/day either way).
	 */
	public static function maybe_check_for_update() {
		if ( ! (bool) get_option( 'am_stats_geo_enabled', 0 ) || ! self::has_credentials() ) {
			return;
		}
		if ( self::import_in_progress() ) {
			return; // Don't start a fresh check on top of a running import.
		}

		$response = wp_remote_head( self::DOWNLOAD_URL, array(
			'timeout' => 30,
			'headers' => self::auth_header(),
		) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return; // Transient failure -- tomorrow's check will retry.
		}

		$last_modified = wp_remote_retrieve_header( $response, 'last-modified' );
		$known         = (string) get_option( 'am_stats_geo_last_modified', '' );

		if ( '' !== $last_modified && $last_modified !== $known ) {
			self::start_import();
		}
	}

	/**
	 * Manual "Update Now". Bypasses the Last-Modified check since the admin
	 * explicitly asked, but a short cooldown (stored, not enforced by the
	 * button being merely hidden) stops an accidental double-click from
	 * spending download quota twice.
	 *
	 * @return true|string true on success, or an error message.
	 */
	public static function trigger_manual_update() {
		if ( ! self::has_credentials() ) {
			return __( 'Enter a MaxMind account ID and license key first.', 'activity-monitor' );
		}
		if ( self::import_in_progress() ) {
			return __( 'An import is already in progress.', 'activity-monitor' );
		}
		$last_trigger = (int) get_option( 'am_stats_geo_last_manual_trigger', 0 );
		if ( $last_trigger && ( time() - $last_trigger ) < HOUR_IN_SECONDS ) {
			return __( 'Please wait before triggering another manual update.', 'activity-monitor' );
		}

		update_option( 'am_stats_geo_last_manual_trigger', time(), false );
		self::start_import();
		return true;
	}

	private static function import_in_progress(): bool {
		$progress = get_option( self::PROGRESS_OPTION, array() );
		return ! empty( $progress ) && 'error' !== ( $progress['stage'] ?? '' );
	}

	private static function start_import() {
		self::cleanup_working_dir();
		update_option( self::PROGRESS_OPTION, array(
			'stage'      => 'download',
			'dir'        => '',
			'row_offset' => 0,
			'started_at' => time(),
		), false );
		wp_schedule_single_event( time(), self::TICK_HOOK );
	}

	private static function auth_header(): array {
		$account_id  = (string) get_option( 'am_stats_geo_account_id', '' );
		$license_key = (string) get_option( 'am_stats_geo_license_key', '' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic Auth encoding, not obfuscation.
		return array( 'Authorization' => 'Basic ' . base64_encode( $account_id . ':' . $license_key ) );
	}

	// ── Chunked import ───────────────────────────────────────────────────

	public static function process_tick() {
		if ( false !== get_transient( self::LOCK_TRANSIENT ) ) {
			return; // Another tick is already running.
		}
		set_transient( self::LOCK_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS );

		$progress = get_option( self::PROGRESS_OPTION, array() );
		if ( empty( $progress ) || 'error' === ( $progress['stage'] ?? '' ) ) {
			delete_transient( self::LOCK_TRANSIENT );
			return;
		}

		try {
			switch ( $progress['stage'] ) {
				case 'download':
					$progress = self::stage_download( $progress );
					break;
				case 'extract':
					$progress = self::stage_extract( $progress );
					break;
				case 'locations':
					$progress = self::stage_locations( $progress );
					break;
				case 'blocks_ipv4':
					$progress = self::stage_blocks( $progress, 4, 'GeoLite2-Country-Blocks-IPv4.csv', 'blocks_ipv6' );
					break;
				case 'blocks_ipv6':
					$progress = self::stage_blocks( $progress, 6, 'GeoLite2-Country-Blocks-IPv6.csv', 'swap' );
					break;
				case 'swap':
					self::stage_swap( $progress );
					delete_transient( self::LOCK_TRANSIENT );
					return;
				default:
					$progress['stage'] = 'error';
					$progress['error'] = 'Unknown import stage.';
			}
		} catch ( Exception $e ) {
			$progress['stage'] = 'error';
			$progress['error'] = $e->getMessage();
			self::cleanup_working_dir( $progress['dir'] ?? '' );
		}

		update_option( self::PROGRESS_OPTION, $progress, false );
		delete_transient( self::LOCK_TRANSIENT );

		if ( 'error' !== $progress['stage'] ) {
			wp_schedule_single_event( time(), self::TICK_HOOK );
		}
	}

	private static function stage_download( array $progress ): array {
		$dir = trailingslashit( get_temp_dir() ) . 'am-stats-geo-import';
		wp_mkdir_p( $dir );
		$zip_path = $dir . '/geolite2-country.zip';

		$response = wp_remote_get( self::DOWNLOAD_URL, array(
			'timeout'  => 300,
			'headers'  => self::auth_header(),
			'stream'   => true,
			'filename' => $zip_path,
		) );

		if ( is_wp_error( $response ) ) {
			throw new Exception( 'Download failed: ' . esc_html( $response->get_error_message() ) );
		}
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			throw new Exception( 'Download failed: HTTP ' . esc_html( (string) wp_remote_retrieve_response_code( $response ) ) );
		}

		$progress['dir']           = $dir;
		$progress['zip_path']      = $zip_path;
		$progress['last_modified'] = wp_remote_retrieve_header( $response, 'last-modified' );
		$progress['stage']         = 'extract';
		return $progress;
	}

	private static function stage_extract( array $progress ): array {
		if ( ! class_exists( 'ZipArchive' ) ) {
			// Not WP_Filesystem/unzip_file(): that can prompt for FTP
			// credentials on some hosts when the "direct" method isn't
			// available, which has no one to answer it in an unattended
			// WP-Cron request. ZipArchive is a standard PHP extension and
			// works unattended.
			throw new Exception( 'The PHP ZipArchive extension is required to import GeoLite2 data.' );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $progress['zip_path'] ) ) {
			throw new Exception( 'Could not open the downloaded GeoLite2 zip file.' );
		}

		$wanted = array(
			'GeoLite2-Country-Locations-en.csv',
			'GeoLite2-Country-Blocks-IPv4.csv',
			'GeoLite2-Country-Blocks-IPv6.csv',
		);
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive is a native PHP class; its property name isn't ours to rename.
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			$base = basename( $name );
			if ( in_array( $base, $wanted, true ) ) {
				$zip->extractTo( $progress['dir'], array( $name ) );
				// The CSVs live inside a version-stamped subdirectory
				// inside the zip (e.g. GeoLite2-Country-CSV_20260821/) --
				// move each wanted file up to the working dir root so
				// later stages can open it by a fixed name. Native
				// filesystem calls throughout this file (not WP_Filesystem)
				// are deliberate -- WP_Filesystem can prompt for FTP
				// credentials on hosts where the "direct" method isn't
				// available, which nothing can answer in an unattended
				// WP-Cron request. This only ever touches our own scratch
				// files in get_temp_dir(), never plugin or site content.
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- see comment above.
				rename( $progress['dir'] . '/' . $name, $progress['dir'] . '/' . $base );
			}
		}
		$zip->close();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- see stage_extract()'s comment above on native filesystem calls in this file.
		unlink( $progress['zip_path'] );

		$progress['stage'] = 'locations';
		return $progress;
	}

	private static function stage_locations( array $progress ): array {
		$path = $progress['dir'] . '/GeoLite2-Country-Locations-en.csv';
		// Native fopen()/fclose(), not WP_Filesystem, throughout this
		// class -- see stage_extract()'s comment on why: WP_Filesystem can
		// block waiting for FTP credentials on hosts where the "direct"
		// method isn't available, which nothing can answer in an
		// unattended WP-Cron request. Every file touched here is our own
		// scratch data in get_temp_dir(), never plugin or site content.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- see comment above.
		$fh = fopen( $path, 'r' );
		if ( ! $fh ) {
			throw new Exception( 'Could not read GeoLite2-Country-Locations-en.csv.' );
		}

		$header = fgetcsv( $fh );
		$idx    = array_flip( $header );
		$map    = array();
		// false !== ( $row = fgetcsv( $fh ) ), not a separate read-then-
		// compare: fgetcsv() returning false is the only EOF signal, so
		// the assignment has to happen inside the loop condition to test
		// it every iteration without reading each line twice.
		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- see comment above.
		while ( false !== ( $row = fgetcsv( $fh ) ) ) {
			$geoname_id = $row[ $idx['geoname_id'] ] ?? '';
			$iso        = $row[ $idx['country_iso_code'] ] ?? '';
			if ( '' !== $geoname_id && '' !== $iso ) {
				$map[ $geoname_id ] = $iso;
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see stage_locations()'s comment above.
		fclose( $fh );

		// Small (~250 rows) -- fine as its own option rather than re-parsing
		// this file on every blocks-CSV tick.
		update_option( 'am_stats_geo_locations_map', $map, false );

		self::create_staging_table();

		$progress['stage']      = 'blocks_ipv4';
		$progress['row_offset'] = 0;
		return $progress;
	}

	private static function stage_blocks( array $progress, int $ip_version, string $filename, string $next_stage ): array {
		$path = $progress['dir'] . '/' . $filename;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- see stage_locations()'s comment on native filesystem calls in this class.
		$fh = fopen( $path, 'r' );
		if ( ! $fh ) {
			throw new Exception( 'Could not read ' . esc_html( $filename ) . '.' );
		}

		$header = fgetcsv( $fh );
		$idx    = array_flip( $header );
		$map    = get_option( 'am_stats_geo_locations_map', array() );

		// Skip rows already processed in a previous tick. fgetcsv() line
		// reads are cheap; re-walking up to BATCH_ROWS-sized offsets each
		// tick is simpler and less error-prone than byte-exact fseek()
		// resumption, and the cost stays bounded by the same constant that
		// bounds each tick's actual work.
		for ( $i = 0; $i < $progress['row_offset']; $i++ ) {
			if ( false === fgetcsv( $fh ) ) {
				break;
			}
		}

		$batch     = array();
		$processed = 0;
		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- see stage_locations()'s comment on this same pattern.
		while ( $processed < self::BATCH_ROWS && false !== ( $row = fgetcsv( $fh ) ) ) {
			$network    = $row[ $idx['network'] ] ?? '';
			$geoname_id = $row[ $idx['geoname_id'] ] ?? '';
			if ( '' === $geoname_id ) {
				// Falls back to the registered country, per MaxMind's own
				// convention for anonymized/unresolvable geoname rows.
				$geoname_id = $row[ $idx['registered_country_geoname_id'] ] ?? '';
			}
			$country = $map[ $geoname_id ] ?? '';

			$range = ( '' !== $network && '' !== $country ) ? self::cidr_to_range( $network ) : null;
			if ( null !== $range ) {
				$batch[] = array( $ip_version, $range['start'], $range['end'], $country );
			}
			if ( count( $batch ) >= self::INSERT_CHUNK ) {
				self::insert_staging_batch( $batch );
				$batch = array();
			}
			++$processed;
		}
		if ( ! empty( $batch ) ) {
			self::insert_staging_batch( $batch );
		}

		$eof = feof( $fh );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see stage_locations()'s comment on native filesystem calls in this class.
		fclose( $fh );

		if ( $eof ) {
			$progress['stage']      = $next_stage;
			$progress['row_offset'] = 0;
		} else {
			$progress['row_offset'] += $processed;
		}
		return $progress;
	}

	private static function stage_swap( array $progress ) {
		global $wpdb;
		$real    = $wpdb->prefix . AM_Stats_Schema::GEO_RANGES_TABLE;
		$staging = $real . '_staging';
		$old     = $real . '_old';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are plugin constants.
		$wpdb->query( "DROP TABLE IF EXISTS `{$old}`" );
		$wpdb->query( "RENAME TABLE `{$real}` TO `{$old}`, `{$staging}` TO `{$real}`" );
		$wpdb->query( "DROP TABLE IF EXISTS `{$old}`" );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		update_option( 'am_stats_geo_last_modified', $progress['last_modified'] ?? '', false );
		update_option( 'am_stats_geo_last_updated', time(), false );
		delete_option( self::PROGRESS_OPTION );
		delete_option( 'am_stats_geo_locations_map' );
		self::cleanup_working_dir( $progress['dir'] ?? '' );
	}

	private static function create_staging_table() {
		global $wpdb;
		$staging = $wpdb->prefix . AM_Stats_Schema::GEO_RANGES_TABLE . '_staging';
		$charset = $wpdb->get_charset_collate();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
		$wpdb->query( "DROP TABLE IF EXISTS `{$staging}`" );
		$sql = "CREATE TABLE {$staging} (
			id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			ip_version   TINYINT(3) UNSIGNED  NOT NULL,
			start_ip     VARBINARY(16)        NOT NULL,
			end_ip       VARBINARY(16)        NOT NULL,
			country_code CHAR(2)              NOT NULL DEFAULT '',
			PRIMARY KEY (id),
			KEY ix_range (ip_version, start_ip)
		) {$charset};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/** @param array<int, array{0:int,1:string,2:string,3:string}> $batch */
	private static function insert_staging_batch( array $batch ) {
		global $wpdb;
		$staging = $wpdb->prefix . AM_Stats_Schema::GEO_RANGES_TABLE . '_staging';

		$placeholders = array();
		$values       = array();
		foreach ( $batch as $row ) {
			$placeholders[] = '(%d, %s, %s, %s)';
			array_push( $values, $row[0], $row[1], $row[2], $row[3] );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
		$sql = "INSERT INTO `{$staging}` (ip_version, start_ip, end_ip, country_code) VALUES " . implode( ', ', $placeholders );
		$wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built entirely from %-placeholders above.
	}

	/**
	 * Converts a CIDR block (an IPv4 form like "1.2.3.0/24", or IPv6) into
	 * packed start/end addresses -- the same fixed-length inet_pton()
	 * binary shape the am_stats_geo_ranges columns store. Uses the same
	 * bitmask math as AM_DB_Legacy_IP::ip_in_cidr(), which tests whether
	 * one address falls inside a CIDR block; this instead computes the
	 * first and last address the block spans.
	 *
	 * @return array{start:string,end:string}|null
	 */
	private static function cidr_to_range( string $cidr ): ?array {
		if ( false === strpos( $cidr, '/' ) ) {
			return null;
		}
		list( $subnet, $bits ) = explode( '/', $cidr, 2 );
		$bits    = (int) $bits;
		$network = inet_pton( $subnet );
		if ( false === $network ) {
			return null;
		}

		$len       = strlen( $network ); // 4 bytes for IPv4, 16 for IPv6.
		$total_bits = $len * 8;
		if ( $bits < 0 || $bits > $total_bits ) {
			return null;
		}

		$start = $network;
		$end   = $network;
		$full_bytes = intdiv( $bits, 8 );
		$rem_bits   = $bits % 8;

		for ( $i = $full_bytes; $i < $len; $i++ ) {
			if ( $i === $full_bytes && $rem_bits > 0 ) {
				$mask         = 0xFF & ( 0xFF << ( 8 - $rem_bits ) );
				$start[ $i ]  = chr( ord( $network[ $i ] ) & $mask );
				$end[ $i ]    = chr( ord( $network[ $i ] ) | ( ~$mask & 0xFF ) );
			} else {
				$start[ $i ] = ( $i < $full_bytes ) ? $network[ $i ] : chr( 0 );
				$end[ $i ]   = ( $i < $full_bytes ) ? $network[ $i ] : chr( 0xFF );
			}
		}

		return array( 'start' => $start, 'end' => $end );
	}

	private static function cleanup_working_dir( string $dir = '' ) {
		if ( '' === $dir ) {
			$dir = trailingslashit( get_temp_dir() ) . 'am-stats-geo-import';
		}
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( glob( $dir . '/*' ) ?: array() as $file ) {
			if ( is_file( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- see stage_locations()'s comment on native filesystem calls in this class.
				unlink( $file );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- see stage_locations()'s comment on native filesystem calls in this class.
		rmdir( $dir );
	}
}
