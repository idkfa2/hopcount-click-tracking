<?php
/**
 * CSV export of the report (post-v1 feature, added 0.2.0).
 *
 * The export honors the same filters as the Report screen - date preset or
 * custom range, destination host, all/source/destination search, link or page
 * grouping - passed as GET params to
 * admin-post.php. Rows come from OCTK_Reporting::export_rows() in bounded
 * batches inside one consistent snapshot, spooled to a bounded temp stream,
 * then formatted and streamed (1.3.2, octk_export_select()). Same column
 * policy as the report: no public_token, identity_hash, or internal
 * database ids.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const OCTK_EXPORT_BATCH       = 1000;
const OCTK_EXPORT_MAX_BATCHES = 100;

add_action( 'admin_post_octk_export_csv', 'octk_handle_export_csv' );
function octk_handle_export_csv(): void {
	if ( ! current_user_can( octk_report_capability() ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'hopcount-click-tracking' ), '', 403 );
	}
	check_admin_referer( 'octk_export_csv' );

	if ( ! OCTK_Reporting::schema_ready() ) {
		wp_die( esc_html__( 'The tracking tables are unavailable.', 'hopcount-click-tracking' ), '', 500 );
	}

	$request = octk_report_request();
	if ( '' !== $request['search_error'] ) {
		// Before any CSV header: a rejected search must never export
		// unfiltered rows.
		wp_die(
			/* translators: %d: maximum search length in characters. */
			esc_html( sprintf( __( 'Search is too long (max %d characters).', 'hopcount-click-tracking' ), OCTK_Reporting::SEARCH_MAX ) ),
			'',
			400
		);
	}
	// Selection runs before any header, so a failed query or spool still
	// ends in a proper error page rather than a half-written download.
	$selected = octk_export_select( $request['args'] );
	if ( is_wp_error( $selected ) ) {
		wp_die( esc_html__( 'The export could not be prepared. Please try again.', 'hopcount-click-tracking' ), '', 500 );
	}
	$filename = octk_export_filename( $request );

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	header( 'X-Content-Type-Options: nosniff' );

	octk_export_csv_write( $request['args'], $selected );
	exit;
}

/**
 * Download filename; page-grouped exports get their own name because their
 * columns differ from the link export.
 */
function octk_export_filename( array $request ): string {
	$format = ( 'page' === ( $request['group'] ?? 'link' ) ) ? 'clicks-by-page_%s_%s.csv' : 'clicks_%s_%s.csv';
	return sprintf( $format, $request['from'], $request['to'] );
}

/**
 * Select and stream in one call (headers not included, so tests can capture
 * output). A failed selection still yields the header row plus an
 * "aborted" row, never a silent partial file.
 */
function octk_export_csv_stream( array $args ): void {
	$selected = octk_export_select( $args );
	if ( is_wp_error( $selected ) ) {
		$out = fopen( 'php://output', 'w' );
		octk_export_csv_header( $out, 'page' === ( $args['group'] ?? 'link' ) );
		octk_csv_row( $out, array( __( '(export aborted: report query failed)', 'hopcount-click-tracking' ) ) );
		return;
	}
	octk_export_csv_write( $args, $selected );
}

/**
 * Selection phase (1.3.2). The export pages with OFFSET, so a click, a
 * title or label edit, or a retention prune landing between two batch
 * queries could shift a row across a batch edge and repeat or skip it.
 * All batch queries therefore run inside one consistent snapshot, and the
 * raw rows are spooled to php://temp (2 MB in memory, then a temp file that
 * PHP deletes itself), so memory stays bounded however large the export.
 *
 * Nothing but raw SQL runs while the snapshot is open: loading posts here
 * would write snapshot-era post data into a shared persistent object cache.
 * Titles and permalinks are resolved after COMMIT, so the row set, order
 * and totals are frozen at the snapshot while displayed titles and
 * permalinks are current.
 *
 * Without a snapshot (filtered off, the database refuses one, or a
 * transaction is already open on this connection, which is never
 * committed from here) the export is the same batched read it was before
 * 1.3.2.
 *
 * @param array $args    Validated report args.
 * @param array $options Internal and test seams: 'batch' (rows per query),
 *                       'open_spool' (callable returning a stream or false),
 *                       'after_batch' (callable run inside the snapshot
 *                       after each batch; must not touch WordPress caches).
 * @return array{spool: resource, truncated: bool, snapshot: bool}|WP_Error
 */
function octk_export_select( array $args, array $options = array() ) {
	global $wpdb;

	$batch       = max( 1, (int) ( $options['batch'] ?? OCTK_EXPORT_BATCH ) );
	$max_rows    = OCTK_EXPORT_BATCH * OCTK_EXPORT_MAX_BATCHES;
	$open_spool  = $options['open_spool'] ?? static fn() => fopen( 'php://temp/maxmemory:' . ( 2 * MB_IN_BYTES ), 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- in-memory/temp spool, not a site file.
	$after_batch = $options['after_batch'] ?? null;
	$by_page     = 'page' === ( $args['group'] ?? 'link' );

	$spool = $open_spool();
	if ( ! is_resource( $spool ) ) {
		octk_log( 'CSV export: spool could not be opened' );
		return new WP_Error( 'octk_export_spool', 'spool open failed' );
	}

	$snapshot = octk_export_begin_snapshot();
	$error    = null;
	$selected = 0;
	$full     = false;
	try {
		while ( $selected < $max_rows ) {
			$limit = min( $batch, $max_rows - $selected );
			$rows  = OCTK_Reporting::export_rows( $args, $limit, $selected );
			if ( null === $rows ) {
				$error = new WP_Error( 'octk_export_query', 'report query failed' );
				break;
			}
			foreach ( $rows as $row ) {
				$record = $by_page
					? array( $row->source_id, $row->source_url, $row->links, $row->clicks, $row->last_bucket )
					: array( $row->source_id, $row->source_url, $row->destination_host, $row->destination_url, $row->label ?? '', $row->clicks, $row->last_bucket );
				if ( ! fputcsv( $spool, array_map( 'strval', $record ), ',', '"', '' ) ) {
					octk_log( 'CSV export: spool write failed (temp directory full or not writable?)' );
					$error = new WP_Error( 'octk_export_spool', 'spool write failed' );
					break 2;
				}
			}
			$selected += count( $rows );
			$full      = count( $rows ) === $limit;
			if ( null !== $after_batch ) {
				$after_batch( $selected );
			}
			if ( ! $full ) {
				break;
			}
		}
	} finally {
		if ( $snapshot ) {
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- ends the read-only snapshot opened above; no data is written.
		}
	}

	if ( null !== $error ) {
		fclose( $spool ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- temp spool.
		return $error;
	}
	$truncated = $full && $selected >= $max_rows;
	if ( $truncated ) {
		octk_log( 'CSV export truncated at ' . $max_rows . ' rows' );
	}
	return array(
		'spool'     => $spool,
		'truncated' => $truncated,
		'snapshot'  => $snapshot,
	);
}

/**
 * Open a consistent-snapshot read transaction for the export. False (and
 * nothing started) when filtered off or refused. SET TRANSACTION fails
 * while a transaction is already open on this connection; START
 * TRANSACTION would then silently commit someone else's work, so it is
 * never issued in that case.
 */
function octk_export_begin_snapshot(): bool {
	global $wpdb;
	if ( ! (bool) apply_filters( 'octk_export_snapshot', true ) ) {
		return false;
	}
	$suppress = $wpdb->suppress_errors( true );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- transaction control statements, no table data.
	$ok = false !== $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' )
		&& false !== $wpdb->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' );
	// phpcs:enable
	$wpdb->suppress_errors( $suppress );
	if ( ! $ok ) {
		octk_log( 'CSV export: no consistent snapshot (' . $wpdb->last_error . '); exporting without one' );
	}
	return $ok;
}

/**
 * Formatting phase: read the spool back in batches, resolve titles and
 * permalinks with the normal WordPress APIs (the snapshot is closed by
 * now), and stream the CSV. Always closes the spool.
 *
 * @param array $selected Result of octk_export_select().
 */
function octk_export_csv_write( array $args, array $selected ): void {
	$spool   = $selected['spool'];
	$by_page = 'page' === ( $args['group'] ?? 'link' );
	try {
		$out = fopen( 'php://output', 'w' );
		octk_export_csv_header( $out, $by_page );
		$site_tz = wp_timezone();

		rewind( $spool );
		do {
			$records = array();
			while ( count( $records ) < OCTK_EXPORT_BATCH ) {
				$record = fgetcsv( $spool, null, ',', '"', '' );
				if ( ! is_array( $record ) || array( null ) === $record ) {
					break;
				}
				$records[] = $record;
			}
			if ( empty( $records ) ) {
				break;
			}

			if ( function_exists( '_prime_post_caches' ) ) {
				_prime_post_caches( array_unique( array_map( static fn( $r ) => (int) $r[0], $records ) ), false, false );
			}

			foreach ( $records as $record ) {
				$source_id = (int) $record[0];
				$post      = get_post( $source_id );
				/* translators: %d: post ID. */
				$title = $post ? get_the_title( $post ) : sprintf( __( '(deleted post #%d)', 'hopcount-click-tracking' ), $source_id );
				// get_the_title() is HTML (texturized, "&" as "&#038;"); a CSV cell
				// wants the text. Decoded before octk_csv_row() applies the formula
				// guard, so a title that decodes to a leading "=" is still neutralised.
				$title      = html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$source_url = $post ? get_permalink( $post ) : $record[1];

				$last_utc  = $by_page ? $record[4] : $record[6];
				$last_site = '';
				$bucket    = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $last_utc, new DateTimeZone( 'UTC' ) );
				if ( false !== $bucket ) {
					$last_site = $bucket->setTimezone( $site_tz )->format( 'Y-m-d H:i:s' );
				}

				if ( $by_page ) {
					octk_csv_row( $out, array( $source_id, $title, $source_url, (int) $record[2], (int) $record[3], $last_utc, $last_site ) );
					continue;
				}
				// $record: source_id, source_url, destination_host, destination_url, label, clicks, last_bucket.
				octk_csv_row( $out, array( $source_id, $title, $source_url, $record[2], $record[3], (int) $record[5], $last_utc, $last_site, $record[4] ) );
			}
		} while ( count( $records ) === OCTK_EXPORT_BATCH );

		if ( $selected['truncated'] ) {
			/* translators: %d: maximum number of exported rows. */
			octk_csv_row( $out, array( sprintf( __( '(export truncated at %d rows - narrow the filters)', 'hopcount-click-tracking' ), OCTK_EXPORT_BATCH * OCTK_EXPORT_MAX_BATCHES ) ) );
		}
	} finally {
		fclose( $spool ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- temp spool.
	}
}

/**
 * UTF-8 BOM plus the column header row. Machine-readable column
 * identifiers: deliberately NOT translated.
 */
function octk_export_csv_header( $out, bool $by_page ): void {
	// UTF-8 BOM so Excel detects the encoding.
	fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streaming to php://output, not the filesystem.

	if ( $by_page ) {
		octk_csv_row( $out, array(
			'source_post_id',
			'source_title',
			'source_url',
			'links',
			'clicks',
			'last_activity_utc',
			'last_activity_site_tz',
		) );
		return;
	}
	// 'label' is deliberately LAST: existing consumers' column positions must not shift.
	octk_csv_row( $out, array(
		'source_post_id',
		'source_title',
		'source_url',
		'destination_host',
		'destination_url',
		'clicks',
		'last_activity_utc',
		'last_activity_site_tz',
		'label',
	) );
}

/**
 * Write one CSV row with spreadsheet formula-injection guarding.
 */
function octk_csv_row( $handle, array $cells ): void {
	fputcsv( $handle, array_map( 'octk_csv_cell', $cells ), ',', '"', '' );
}

/**
 * Neutralize cells a spreadsheet would evaluate as a formula (=, +, -, @,
 * tab, CR) by prefixing a single quote.
 */
function octk_csv_cell( $value ): string {
	$value = (string) $value;
	if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
		$value = "'" . $value;
	}
	return $value;
}
