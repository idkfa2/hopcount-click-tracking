<?php
/**
 * CSV export of the report (post-v1 feature, added 0.2.0).
 *
 * The export honors the same filters as the Report screen - date preset or
 * custom range, destination host, all/source/destination search, link or page
 * grouping - passed as GET params to
 * admin-post.php. Rows come from OCTK_Reporting::export_rows() in bounded
 * batches; output is streamed, never buffered whole. Same column policy as
 * the report: no public_token, identity_hash, or internal database ids.
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
	$filename = octk_export_filename( $request );

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	header( 'X-Content-Type-Options: nosniff' );

	octk_export_csv_stream( $request['args'] );
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
 * Stream the CSV body for a validated report request (headers not included,
 * so tests can capture output). Batched; a hard cap of
 * OCTK_EXPORT_BATCH * OCTK_EXPORT_MAX_BATCHES rows is announced in the
 * file rather than applied silently.
 */
function octk_export_csv_stream( array $args ): void {
	$out     = fopen( 'php://output', 'w' );
	$by_page = 'page' === ( $args['group'] ?? 'link' );

	// UTF-8 BOM so Excel detects the encoding.
	fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streaming to php://output, not the filesystem.

	// Machine-readable column identifiers: deliberately NOT translated.
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
	} else {
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

	$site_tz = wp_timezone();

	for ( $batch = 0; $batch < OCTK_EXPORT_MAX_BATCHES; $batch++ ) {
		$rows = OCTK_Reporting::export_rows( $args, OCTK_EXPORT_BATCH, $batch * OCTK_EXPORT_BATCH );
		if ( null === $rows ) {
			octk_csv_row( $out, array( __( '(export aborted: report query failed)', 'hopcount-click-tracking' ) ) );
			return;
		}
		if ( empty( $rows ) ) {
			return;
		}

		$ids = array_unique( array_map( static fn( $r ) => (int) $r->source_id, $rows ) );
		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $ids, false, false );
		}

		foreach ( $rows as $row ) {
			$post  = get_post( (int) $row->source_id );
			/* translators: %d: post ID. */
			$title = $post ? get_the_title( $post ) : sprintf( __( '(deleted post #%d)', 'hopcount-click-tracking' ), (int) $row->source_id );

			$last_utc  = (string) $row->last_bucket;
			$last_site = '';
			$bucket    = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $last_utc, new DateTimeZone( 'UTC' ) );
			if ( false !== $bucket ) {
				$last_site = $bucket->setTimezone( $site_tz )->format( 'Y-m-d H:i:s' );
			}

			if ( $by_page ) {
				octk_csv_row( $out, array(
					(int) $row->source_id,
					$title,
					$post ? get_permalink( $post ) : (string) $row->source_url,
					(int) $row->links,
					(int) $row->clicks,
					$last_utc,
					$last_site,
				) );
				continue;
			}

			octk_csv_row( $out, array(
				(int) $row->source_id,
				$title,
				$post ? get_permalink( $post ) : (string) $row->source_url,
				(string) $row->destination_host,
				(string) $row->destination_url,
				(int) $row->clicks,
				$last_utc,
				$last_site,
				(string) ( $row->label ?? '' ),
			) );
		}

		if ( count( $rows ) < OCTK_EXPORT_BATCH ) {
			return;
		}
	}

	octk_log( 'CSV export truncated at ' . ( OCTK_EXPORT_BATCH * OCTK_EXPORT_MAX_BATCHES ) . ' rows' );
	/* translators: %d: maximum number of exported rows. */
	octk_csv_row( $out, array( sprintf( __( '(export truncated at %d rows - narrow the filters)', 'hopcount-click-tracking' ), OCTK_EXPORT_BATCH * OCTK_EXPORT_MAX_BATCHES ) ) );
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
