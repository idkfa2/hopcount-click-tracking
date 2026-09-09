<?php
/**
 * Admin UI (scope §8): Click Tracking menu with Report / Settings / Status.
 * Server-rendered, filter state in the URL, single shared capability filter,
 * assets none (no JS/CSS needed for v1 screens).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function octk_report_capability(): string {
	return (string) apply_filters( 'octk_report_capability', 'manage_options' );
}

/**
 * Small shared styles and the report-page range toggle, registered as
 * empty-src handles with inline content and enqueued on plugin screens only
 * (no assets load outside them - scope §8).
 */
add_action( 'admin_enqueue_scripts', 'octk_admin_enqueue_assets' );
function octk_admin_enqueue_assets( string $hook_suffix ): void {
	$screens = array( 'octk-report', 'octk-labels', 'octk-settings', 'octk-status' );
	$page    = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- screen detection only.
	if ( ! in_array( $page, $screens, true ) ) {
		return;
	}

	wp_register_style( 'octk-admin', false, array(), OCTK_VERSION );
	wp_add_inline_style( 'octk-admin', octk_admin_inline_css() );
	wp_enqueue_style( 'octk-admin' );

	if ( 'octk-report' === $page ) {
		wp_register_script( 'octk-admin', false, array(), OCTK_VERSION, true );
		wp_add_inline_script( 'octk-admin', '(function(){var r=document.getElementById("octk-range"),s=document.getElementById("octk-custom-dates");if(!r||!s){return;}function t(){s.style.display="custom"===r.value?"":"none";}r.addEventListener("change",t);t();})();' );
		wp_enqueue_script( 'octk-admin' );
	}
}

function octk_admin_inline_css(): string {
	return '.octk-cards{display:flex;gap:12px;flex-wrap:wrap;margin:16px 0}
	.octk-card{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:12px 20px;min-width:150px;box-shadow:0 1px 1px rgba(0,0,0,.04)}
	.octk-card-value{font-size:20px;font-weight:600;line-height:1.4}
	.octk-card-label{color:#646970;margin-top:2px}
	.octk-filters{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:12px 0}
	.octk-url{color:#787c82;font-size:12px;word-break:break-all}
	.octk-table td{vertical-align:top}
	.octk-dot{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:8px;vertical-align:baseline}
	.octk-dot-ok{background:#00a32a}
	.octk-dot-warn{background:#dba617}
	.octk-dot-info{background:#c3c4c7}';
}

/**
 * Kept as a no-op hook point for callers that printed styles inline before
 * 1.0.0; styles now enqueue via octk_admin_enqueue_assets().
 */
function octk_admin_styles(): void {}

add_action( 'admin_menu', 'octk_register_admin_menu' );
function octk_register_admin_menu(): void {
	$cap = octk_report_capability();
	add_menu_page( __( 'Hopcount Click Tracking', 'hopcount-click-tracking' ), __( 'Click Tracking', 'hopcount-click-tracking' ), $cap, 'octk-report', 'octk_render_report_page', 'dashicons-chart-bar', 58 );
	add_submenu_page( 'octk-report', __( 'Hopcount Click Tracking', 'hopcount-click-tracking' ), __( 'Report', 'hopcount-click-tracking' ), $cap, 'octk-report', 'octk_render_report_page' );
	add_submenu_page( 'octk-report', __( 'Link Labels', 'hopcount-click-tracking' ), __( 'Labels', 'hopcount-click-tracking' ), octk_label_capability(), 'octk-labels', 'octk_render_labels_page' );
	add_submenu_page( 'octk-report', __( 'Click Tracking Settings', 'hopcount-click-tracking' ), __( 'Settings', 'hopcount-click-tracking' ), 'manage_options', 'octk-settings', 'octk_render_settings_page' );
	// Status stays admin-only regardless of the report-cap filter: it prints
	// WP/PHP/DB versions, which non-admin roles have no need to see.
	add_submenu_page( 'octk-report', __( 'Click Tracking Status', 'hopcount-click-tracking' ), __( 'Status', 'hopcount-click-tracking' ), 'manage_options', 'octk-status', 'octk_render_status_page' );
}

add_filter( 'plugin_action_links_' . plugin_basename( OCTK_PLUGIN_FILE ), static function ( array $links ): array {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=octk-report' ) ) . '">' . esc_html__( 'View report', 'hopcount-click-tracking' ) . '</a>' );
	return $links;
} );

/* ---------------------------------------------------------------------- */
/* Report                                                                  */
/* ---------------------------------------------------------------------- */

/**
 * Post IDs whose TITLE contains the needle. Deliberately not WP_Query 's':
 * content search matches nearly every post for topical terms, which made
 * the old Source filter appear to do nothing. Published only: the report cap
 * can be remapped below manage_options via the octk_report_capability
 * filter, so draft/private titles must not be discoverable. Every search word must appear in the title, in any order
 * (OCTK_Reporting::search_tokens), with typographic dashes and quotes
 * folded to keyboard forms on both sides so a pasted texturized title
 * still matches.
 *
 * @return int[] Up to MAX_SOURCE_IDS + 1 ids, newest first (the extra row
 *               signals capping to the caller).
 */
function octk_search_source_titles( string $needle ): array {
	global $wpdb;

	$types  = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
	$tokens = OCTK_Reporting::search_tokens( $needle );
	if ( empty( $types ) || empty( $tokens ) ) {
		return array();
	}
	// Placeholder lists only: one %s per post type, one "<constant REPLACE()
	// expression> LIKE %s" per search token. Every value is passed to prepare().
	$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
	$title_expr   = OCTK_Reporting::fold_sql( 'post_title' );
	$token_where  = implode( ' AND ', array_fill( 0, count( $tokens ), "{$title_expr} LIKE %s" ) );
	$likes        = array_map( static fn( $t ) => '%' . $wpdb->esc_like( $t ) . '%', $tokens );

	$ids = $wpdb->get_col(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders / $token_where are placeholder lists built above; all values are prepared.
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			WHERE post_status = 'publish' AND post_type IN ({$placeholders})
			AND {$token_where}
			ORDER BY post_date DESC LIMIT %d",
			array_merge( $types, $likes, array( OCTK_Reporting::MAX_SOURCE_IDS + 1 ) )
		)
	);
	return array_map( 'intval', is_array( $ids ) ? $ids : array() );
}

/**
 * Validate GET params into a report request. Invalid values fall back safely.
 *
 * source_id is the exact-post pin set by the "See all links" row action; it
 * wins over a source-mode text search (the pin is more specific), while a
 * destination-mode search still combines with it.
 *
 * @return array{args: array, preset: string, from: string, to: string, search: string, search_in: string, source_id: int, capped: bool}
 */
function octk_report_request(): array {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only report filters in GET.
	$preset  = in_array( $_GET['range'] ?? '', array( '7', '30', '90', 'custom' ), true ) ? $_GET['range'] : '30';
	$tz      = wp_timezone();
	$today   = new DateTimeImmutable( 'today', $tz );

	if ( 'custom' === $preset ) {
		$from_raw = sanitize_text_field( wp_unslash( $_GET['from'] ?? '' ) );
		$to_raw   = sanitize_text_field( wp_unslash( $_GET['to'] ?? '' ) );
		$from     = DateTimeImmutable::createFromFormat( 'Y-m-d', $from_raw, $tz ) ?: $today->modify( '-29 days' );
		$to       = DateTimeImmutable::createFromFormat( 'Y-m-d', $to_raw, $tz ) ?: $today;
		if ( $from > $to ) {
			list( $from, $to ) = array( $to, $from );
		}
		// Bound the custom range to retained data.
		$min = $today->modify( '-' . octk_retention_months() . ' months' );
		if ( $from < $min ) {
			$from = $min;
		}
	} else {
		$from = $today->modify( '-' . ( (int) $preset - 1 ) . ' days' );
		$to   = $today;
	}

	$start_utc = $from->setTime( 0, 0 )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	$end_utc   = $to->setTime( 0, 0 )->modify( '+1 day' )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );

	$host = sanitize_text_field( wp_unslash( $_GET['host'] ?? '' ) );
	if ( '' !== $host && 1 !== preg_match( '/^[a-z0-9.-]+$/i', $host ) ) {
		$host = '';
	}

	$search    = sanitize_text_field( wp_unslash( $_GET['search'] ?? '' ) );
	$search_in = ( 'destination' === ( $_GET['search_in'] ?? '' ) ) ? 'destination' : 'source';
	$source_id = max( 0, (int) ( $_GET['source_id'] ?? 0 ) );

	$source_ids         = ( $source_id > 0 ) ? array( $source_id ) : null;
	$destination_search = '';
	$capped             = false;
	if ( '' !== $search ) {
		if ( 'destination' === $search_in ) {
			// Reporting matches this against destination URL and label,
			// folding typographic characters on both sides itself.
			$destination_search = $search;
		} elseif ( null === $source_ids ) {
			$source_ids = octk_search_source_titles( $search );
			$capped     = count( $source_ids ) > OCTK_Reporting::MAX_SOURCE_IDS;
			if ( $capped ) {
				$source_ids = array_slice( $source_ids, 0, OCTK_Reporting::MAX_SOURCE_IDS );
			}
			if ( empty( $source_ids ) ) {
				$source_ids = array( 0 ); // No match: force an empty result, not an unfiltered one.
			}
		}
	}

	$per_page = (int) ( $_GET['per_page'] ?? 25 );
	if ( ! in_array( $per_page, OCTK_Reporting::PER_PAGE_CHOICES, true ) ) {
		$per_page = 25;
	}

	$args = array(
		'start_utc'          => $start_utc,
		'end_utc'            => $end_utc,
		'host'               => strtolower( $host ),
		'source_ids'         => $source_ids,
		'destination_search' => $destination_search,
		'orderby'            => sanitize_key( $_GET['orderby'] ?? 'clicks' ),
		'order'              => sanitize_key( $_GET['order'] ?? 'desc' ),
		'page'               => max( 1, (int) ( $_GET['paged'] ?? 1 ) ),
		'per_page'           => $per_page,
	);
	// phpcs:enable

	return array(
		'args'      => $args,
		'preset'    => $preset,
		'from'      => $from->format( 'Y-m-d' ),
		'to'        => $to->format( 'Y-m-d' ),
		'search'    => $search,
		'search_in' => $search_in,
		'source_id' => $source_id,
		'capped'    => $capped,
	);
}

function octk_render_report_page(): void {
	if ( ! current_user_can( octk_report_capability() ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'hopcount-click-tracking' ) );
	}

	octk_admin_styles();
	echo '<div class="wrap"><h1>' . esc_html__( 'Hopcount Click Tracking', 'hopcount-click-tracking' ) . '</h1>';
	echo '<p>' . esc_html__( 'Clicks on outbound HTTP(S) links rendered inside post and page content. Menus, widgets, headers, and footers are not covered.', 'hopcount-click-tracking' ) . '</p>';

	if ( ! OCTK_Reporting::schema_ready() ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'The tracking tables are unavailable.', 'hopcount-click-tracking' ) . ' ' . sprintf(
			/* translators: %s: link to the Status page (anchor text "Status page"). */
			esc_html__( 'Visit the %s.', 'hopcount-click-tracking' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=octk-status' ) ) . '">' . esc_html__( 'Status page', 'hopcount-click-tracking' ) . '</a>'
		) . '</p></div></div>';
		return;
	}
	if ( ! octk_enabled() ) {
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Tracking is currently disabled. Existing data is shown below; no new clicks are being recorded.', 'hopcount-click-tracking' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=octk-settings' ) ) . '">' . esc_html__( 'Settings', 'hopcount-click-tracking' ) . '</a></p></div>';
	}

	$request = octk_report_request();
	$summary = OCTK_Reporting::summary( $request['args'] );
	$result  = OCTK_Reporting::query_rows( $request['args'] );

	octk_report_filters_form( $request );

	if ( $request['source_id'] > 0 ) {
		// "See all links" pin: not visible in the form controls, so announce
		// it and offer a one-click way out (Reset also clears it).
		$pin_post  = get_post( $request['source_id'] );
		/* translators: %d: post ID. */
		$pin_title = $pin_post ? get_the_title( $pin_post ) : sprintf( __( '(deleted post #%d)', 'hopcount-click-tracking' ), (int) $request['source_id'] );
		$clear_url = add_query_arg( array_merge( $_GET, array( 'source_id' => false, 'paged' => false ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="notice notice-info"><p>' . sprintf(
			/* translators: %s: post title. */
			esc_html__( 'Showing all links for: %s', 'hopcount-click-tracking' ),
			'<strong>' . esc_html( $pin_title ) . '</strong>'
		) . ' &nbsp;<a href="' . esc_url( $clear_url ) . '">' . esc_html__( 'Clear filter', 'hopcount-click-tracking' ) . '</a></p></div>';
	}

	if ( $request['capped'] ) {
		echo '<div class="notice notice-info"><p>' . sprintf(
			/* translators: %d: maximum number of source posts included. */
			esc_html__( 'Source search matched too many posts; only the newest %d are included. Narrow your search.', 'hopcount-click-tracking' ),
			(int) OCTK_Reporting::MAX_SOURCE_IDS
		) . '</p></div>';
	}

	if ( null === $result || null === $summary ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'The report query failed.', 'hopcount-click-tracking' ) . ' ' . sprintf(
			/* translators: %s: link to the Status page (anchor text "Status page"). */
			esc_html__( 'Check the %s.', 'hopcount-click-tracking' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=octk-status' ) ) . '">' . esc_html__( 'Status page', 'hopcount-click-tracking' ) . '</a>'
		) . '</p></div></div>';
		return;
	}

	octk_report_summary_row( $summary );

	if ( $result->total > 0 ) {
		echo '<p><a class="button" href="' . esc_url( octk_export_url( $request ) ) . '">' . esc_html__( 'Export CSV', 'hopcount-click-tracking' ) . '</a> ';
		echo '<span class="description">' . esc_html__( 'Exports every row matching the current filters and date range (not just this page).', 'hopcount-click-tracking' ) . '</span></p>';
	}

	if ( 0 === $result->total ) {
		$counts = OCTK_Reporting::status_counts();
		if ( 0 === $counts->links ) {
			echo '<p>' . esc_html__( 'No outbound links have been registered yet. Links register the first time a post renders uncached with tracking enabled.', 'hopcount-click-tracking' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'No clicks match the selected filters. Try a wider date range or clear the filters.', 'hopcount-click-tracking' ) . '</p>';
		}
		echo '</div>';
		return;
	}

	octk_report_table( $result, $request );
	echo '</div>';
}

/**
 * admin-post.php URL carrying the current report filters, nonce-signed.
 * per_page/paged are omitted deliberately - the export is unpaginated.
 */
function octk_export_url( array $request ): string {
	$params = array(
		'action' => 'octk_export_csv',
		'range'  => $request['preset'],
	);
	if ( 'custom' === $request['preset'] ) {
		$params['from'] = $request['from'];
		$params['to']   = $request['to'];
	}
	if ( '' !== $request['args']['host'] ) {
		$params['host'] = $request['args']['host'];
	}
	if ( '' !== $request['search'] ) {
		$params['search']    = $request['search'];
		$params['search_in'] = $request['search_in'];
	}
	if ( ! empty( $request['source_id'] ) ) {
		$params['source_id'] = (int) $request['source_id'];
	}
	$params['orderby'] = $request['args']['orderby'];
	$params['order']   = $request['args']['order'];

	return wp_nonce_url( add_query_arg( $params, admin_url( 'admin-post.php' ) ), 'octk_export_csv' );
}

function octk_report_filters_form( array $request ): void {
	$hosts = OCTK_Reporting::hosts();
	echo '<form method="get" class="octk-filters">';
	echo '<input type="hidden" name="page" value="octk-report" />';
	if ( $request['source_id'] > 0 ) {
		// Keep the "See all links" pin across range/host/per-page changes.
		printf( '<input type="hidden" name="source_id" value="%d" />', (int) $request['source_id'] );
	}

	echo '<label for="octk-range">' . esc_html__( 'Range', 'hopcount-click-tracking' ) . '</label> <select name="range" id="octk-range">';
	$ranges = array(
		'7'      => __( 'Last 7 days', 'hopcount-click-tracking' ),
		'30'     => __( 'Last 30 days', 'hopcount-click-tracking' ),
		'90'     => __( 'Last 90 days', 'hopcount-click-tracking' ),
		'custom' => __( 'Custom', 'hopcount-click-tracking' ),
	);
	foreach ( $ranges as $value => $label ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $request['preset'], $value, false ), esc_html( $label ) );
	}
	echo '</select>';

	echo '<span id="octk-custom-dates">';
	printf( '<input type="date" name="from" value="%s" aria-label="%s" /> &ndash; ', esc_attr( 'custom' === $request['preset'] ? $request['from'] : '' ), esc_attr__( 'From date', 'hopcount-click-tracking' ) );
	printf( '<input type="date" name="to" value="%s" aria-label="%s" />', esc_attr( 'custom' === $request['preset'] ? $request['to'] : '' ), esc_attr__( 'To date', 'hopcount-click-tracking' ) );
	echo '</span>';
	// The range toggle script is enqueued via octk_admin_enqueue_assets().

	echo '<label for="octk-host">' . esc_html__( 'Host', 'hopcount-click-tracking' ) . '</label> <select name="host" id="octk-host"><option value="">' . esc_html__( 'All hosts', 'hopcount-click-tracking' ) . '</option>';
	foreach ( $hosts as $host ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $host ), selected( $request['args']['host'], $host, false ), esc_html( $host ) );
	}
	echo '</select> ';

	echo '<label for="octk-search-in">' . esc_html__( 'Search', 'hopcount-click-tracking' ) . '</label> <select name="search_in" id="octk-search-in" aria-label="' . esc_attr__( 'Search in', 'hopcount-click-tracking' ) . '">';
	$search_ins = array(
		'source'      => __( 'Source content', 'hopcount-click-tracking' ),
		'destination' => __( 'Destination', 'hopcount-click-tracking' ),
	);
	foreach ( $search_ins as $value => $label ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $request['search_in'], $value, false ), esc_html( $label ) );
	}
	echo '</select> ';
	printf(
		'<input type="search" name="search" id="octk-search" value="%s" placeholder="%s" aria-label="%s" /> ',
		esc_attr( $request['search'] ),
		esc_attr__( 'Contains&hellip;', 'hopcount-click-tracking' ),
		esc_attr__( 'Search text', 'hopcount-click-tracking' )
	);

	echo '<label for="octk-per-page">' . esc_html__( 'Show', 'hopcount-click-tracking' ) . '</label> <select name="per_page" id="octk-per-page">';
	foreach ( OCTK_Reporting::PER_PAGE_CHOICES as $choice ) {
		printf( '<option value="%d"%s>%d</option>', (int) $choice, selected( (int) $request['args']['per_page'], $choice, false ), (int) $choice ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- integer constants.
	}
	echo '</select>';

	submit_button( __( 'Filter', 'hopcount-click-tracking' ), 'secondary', '', false );
	echo ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=octk-report' ) ) . '">' . esc_html__( 'Reset', 'hopcount-click-tracking' ) . '</a>';
	echo '</form>';
}

function octk_report_summary_row( $summary ): void {
	// No "last activity" card: the site gets clicks around the clock, so it
	// only ever restated the current hour (removed 0.4.0 on user request).
	$cards = array(
		array( number_format_i18n( $summary->clicks ), __( 'Total clicks', 'hopcount-click-tracking' ) ),
		array( number_format_i18n( $summary->identities ), __( 'Clicked links', 'hopcount-click-tracking' ) ),
		array( number_format_i18n( $summary->sources ), __( 'Source posts', 'hopcount-click-tracking' ) ),
	);
	echo '<div class="octk-cards">';
	foreach ( $cards as $card ) {
		printf(
			'<div class="octk-card"><div class="octk-card-value">%s</div><div class="octk-card-label">%s</div></div>',
			esc_html( $card[0] ),
			esc_html( $card[1] )
		);
	}
	echo '</div>';
}

/**
 * Present an hourly UTC bucket in the site timezone, labelled as a bucket.
 */
function octk_format_bucket( string $bucket_start_utc ): string {
	$start = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $bucket_start_utc, new DateTimeZone( 'UTC' ) );
	if ( false === $start ) {
		return $bucket_start_utc;
	}
	$local = $start->setTimezone( wp_timezone() );
	return $local->format( 'j M Y, H:00' ) . '–' . $local->modify( '+1 hour' )->format( 'H:00' );
}

function octk_report_table( $result, array $request ): void {
	$args = $request['args'];

	// Batch post lookup: no per-row get_post() N+1 (scope §8).
	$ids = array_unique( array_map( static fn( $r ) => (int) $r->source_id, $result->rows ) );
	if ( function_exists( '_prime_post_caches' ) && ! empty( $ids ) ) {
		_prime_post_caches( $ids, false, false );
	}

	$sort_link = static function ( string $key, string $label ) use ( $args ): string {
		$order = ( $args['orderby'] === $key && 'desc' === $args['order'] ) ? 'asc' : 'desc';
		$url   = add_query_arg( array_merge( $_GET, array( 'orderby' => $key, 'order' => $order, 'paged' => 1 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$mark  = $args['orderby'] === $key ? ( 'desc' === $args['order'] ? ' &#9660;' : ' &#9650;' ) : '';
		return '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . $mark . '</a>';
	};

	echo '<table class="widefat striped octk-table">';
	echo '<colgroup><col style="width:30%" /><col /><col style="width:80px" /><col style="width:190px" /></colgroup>';
	echo '<thead><tr>';
	echo '<th scope="col">' . esc_html__( 'Source content', 'hopcount-click-tracking' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Destination', 'hopcount-click-tracking' ) . '</th>';
	echo '<th scope="col" style="text-align:right;">' . $sort_link( 'clicks', __( 'Clicks', 'hopcount-click-tracking' ) ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<th scope="col">' . $sort_link( 'last_activity', __( 'Last activity', 'hopcount-click-tracking' ) ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '</tr></thead><tbody>';

	foreach ( $result->rows as $row ) {
		$post = get_post( (int) $row->source_id );
		if ( $post ) {
			$source = '<a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a>';
		} else {
			// Deleted source: fall back to stored metadata (scope §8).
			/* translators: %d: post ID. */
			$source = esc_html( $row->source_url ?: sprintf( __( '(deleted post #%d)', 'hopcount-click-tracking' ), (int) $row->source_id ) ) . ' <em>' . esc_html__( '(deleted)', 'hopcount-click-tracking' ) . '</em>';
		}

		// Pin the report to this exact post; drops any text search (the pin
		// replaces it) but keeps range/host/sort.
		$pin_url = add_query_arg( array_merge( $_GET, array( // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'source_id' => (int) $row->source_id,
			'paged'     => false,
			'search'    => false,
			'search_in' => false,
		) ) );
		$source .= '<div class="row-actions"><a href="' . esc_url( $pin_url ) . '">' . esc_html__( 'See all links', 'hopcount-click-tracking' ) . '</a></div>';

		$dest_display = strlen( $row->destination_url ) > 90 ? substr( $row->destination_url, 0, 87 ) . '…' : $row->destination_url;
		$label        = isset( $row->label ) && is_string( $row->label ) && '' !== $row->label ? $row->label : null;

		if ( null !== $label ) {
			// Stacked (link-labels plan): label bold, host + URL always visible beneath.
			$origin_mark = ( 'manual' === ( $row->label_origin ?? '' ) ) ? ' <em class="octk-url">' . esc_html__( '(edited)', 'hopcount-click-tracking' ) . '</em>' : '';
			$destination = '<a href="' . esc_url( $row->destination_url ) . '" rel="noopener noreferrer nofollow" target="_blank" title="' . esc_attr( $row->destination_url ) . '"><strong>' . esc_html( $label ) . '</strong></a>' . $origin_mark
				. '<br /><span class="octk-url">' . esc_html( $row->destination_host ) . ' · ' . esc_html( $dest_display ) . '</span>';
		} else {
			$destination = '<a href="' . esc_url( $row->destination_url ) . '" rel="noopener noreferrer nofollow" target="_blank" title="' . esc_attr( $row->destination_url ) . '"><strong>' . esc_html( $row->destination_host ) . '</strong></a>'
				. '<br /><span class="octk-url">' . esc_html( $dest_display ) . '</span>';
		}

		if ( current_user_can( octk_label_capability() ) ) {
			$rename_url   = octk_labels_page_url( array( 'dest' => rawurlencode( $row->destination_url ) ) );
			$destination .= '<div class="row-actions"><a href="' . esc_url( $rename_url ) . '">' . esc_html__( 'Rename', 'hopcount-click-tracking' ) . '</a></div>';
		}

		printf(
			'<tr><td>%s</td><td>%s</td><td style="text-align:right;">%s</td><td>%s</td></tr>',
			$source,       // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			$destination,  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			esc_html( number_format_i18n( (int) $row->clicks ) ),
			esc_html( octk_format_bucket( $row->last_bucket ) )
		);
	}
	echo '</tbody></table>';

	$total_pages = (int) ceil( $result->total / max( 1, (int) $args['per_page'] ) );
	if ( $total_pages > 1 ) {
		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo paginate_links( array( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'base'    => add_query_arg( 'paged', '%#%' ),
			'format'  => '',
			'current' => $args['page'],
			'total'   => $total_pages,
		) );
		echo '</div></div>';
	}
}

/* ---------------------------------------------------------------------- */
/* Settings                                                                */
/* ---------------------------------------------------------------------- */

add_action( 'admin_init', 'octk_register_settings' );
function octk_register_settings(): void {
	register_setting( 'octk_settings', 'octk_enabled', array( 'sanitize_callback' => static fn( $v ) => '1' === $v ? '1' : '0' ) );
	register_setting( 'octk_settings', 'octk_excluded_roles', array( 'sanitize_callback' => 'octk_sanitize_roles' ) );
	register_setting( 'octk_settings', 'octk_internal_hosts', array( 'sanitize_callback' => 'octk_sanitize_internal_hosts' ) );
	register_setting( 'octk_settings', 'octk_retention_months', array( 'sanitize_callback' => static fn( $v ) => max( 1, min( 120, absint( $v ) ) ) ) );
	register_setting( 'octk_settings', 'octk_rate_limit', array( 'sanitize_callback' => static fn( $v ) => '1' === $v ? '1' : '0' ) );
	register_setting( 'octk_settings', 'octk_rate_limit_per_minute', array( 'sanitize_callback' => static fn( $v ) => max( 1, min( 1000, absint( $v ) ) ) ) );
	register_setting( 'octk_settings', 'octk_uninstall_delete', array( 'sanitize_callback' => static fn( $v ) => '1' === $v ? '1' : '0' ) );
}

function octk_sanitize_roles( $value ): array {
	if ( ! is_array( $value ) ) {
		return array();
	}
	$valid = array_keys( wp_roles()->roles );
	return array_values( array_intersect( array_map( 'sanitize_key', $value ), $valid ) );
}

/**
 * Textarea, one host per line. Hostnames only - never URL/SQL fragments (§9).
 */
function octk_sanitize_internal_hosts( $value ): array {
	// The Settings API sanitizes twice when an option is first created
	// (update_option, then add_option); the second pass receives the array
	// returned by the first. Re-join so both passes agree.
	if ( is_array( $value ) ) {
		$value = implode( "\n", array_map( 'strval', $value ) );
	}
	$hosts = array();
	foreach ( preg_split( '/[\r\n]+/', (string) $value ) as $line ) {
		$host = strtolower( trim( $line ) );
		if ( '' === $host ) {
			continue;
		}
		if ( 1 === preg_match( '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host ) && strlen( $host ) <= 255 ) {
			$hosts[] = $host;
		} else {
			/* translators: %s: the rejected hostname as entered. */
			add_settings_error( 'octk_internal_hosts', 'octk_bad_host', sprintf( __( 'Ignored invalid hostname: %s', 'hopcount-click-tracking' ), esc_html( $line ) ) );
		}
	}
	return array_values( array_unique( $hosts ) );
}

function octk_render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'hopcount-click-tracking' ) );
	}

	$enabled   = octk_enabled();
	$excluded  = get_option( 'octk_excluded_roles', octk_default_excluded_roles() );
	$hosts     = get_option( 'octk_internal_hosts', array() );
	$retention = octk_retention_months();
	$uninstall = '1' === get_option( 'octk_uninstall_delete', '0' );

	settings_errors();
	echo '<div class="wrap"><h1>' . esc_html__( 'Click Tracking Settings', 'hopcount-click-tracking' ) . '</h1><form method="post" action="options.php">';
	settings_fields( 'octk_settings' );

	echo '<h2>' . esc_html__( 'Tracking', 'hopcount-click-tracking' ) . '</h2><table class="form-table" role="presentation">';
	echo '<tr><th scope="row">' . esc_html__( 'Outbound click tracking', 'hopcount-click-tracking' ) . '</th><td><label><input type="checkbox" name="octk_enabled" value="1"' . checked( $enabled, true, false ) . ' /> ' . esc_html__( 'Enabled', 'hopcount-click-tracking' ) . '</label>';
	echo '<p class="description">' . esc_html__( 'When disabled, the endpoint stops recording immediately, even from still-cached pages.', 'hopcount-click-tracking' ) . '</p></td></tr>';

	echo '<tr><th scope="row">' . esc_html__( 'Exclude clicks from roles', 'hopcount-click-tracking' ) . '</th><td>';
	foreach ( wp_roles()->roles as $slug => $role ) {
		printf(
			'<label style="margin-right:1em;"><input type="checkbox" name="octk_excluded_roles[]" value="%s"%s /> %s</label>',
			esc_attr( $slug ),
			checked( in_array( $slug, (array) $excluded, true ), true, false ),
			esc_html( translate_user_role( $role['name'] ) )
		);
	}
	echo '<p class="description">' . esc_html__( 'Implemented by not loading the tracking listener for these logged-in users. Requires the full-page cache to bypass logged-in visitors.', 'hopcount-click-tracking' ) . '</p></td></tr>';

	$rl         = octk_rate_limit_enabled();
	$rl_per_min = octk_rate_limit_per_minute();
	echo '<tr><th scope="row">' . esc_html__( 'Rate limiting', 'hopcount-click-tracking' ) . '</th><td><label><input type="checkbox" name="octk_rate_limit" value="1"' . checked( $rl, true, false ) . ' /> ' . esc_html__( 'Limit clicks per visitor IP', 'hopcount-click-tracking' ) . '</label> ';
	printf(
		'<input type="number" name="octk_rate_limit_per_minute" value="%d" min="1" max="1000" style="width:5em;" aria-label="%s" /> %s',
		(int) $rl_per_min,
		esc_attr__( 'Clicks per minute', 'hopcount-click-tracking' ),
		esc_html__( 'clicks/minute', 'hopcount-click-tracking' )
	);
	echo '<p class="description">' . esc_html__( 'Requires a persistent object cache (e.g. Redis). Fails open: if the cache is unavailable, clicks are recorded without limiting.', 'hopcount-click-tracking' ) . '</p></td></tr>';
	echo '</table>';

	echo '<h2>' . esc_html__( 'Internal destinations', 'hopcount-click-tracking' ) . '</h2><table class="form-table" role="presentation">';
	echo '<tr><th scope="row">' . esc_html__( 'Additional internal hosts', 'hopcount-click-tracking' ) . '</th><td>';
	printf( '<textarea name="octk_internal_hosts" rows="4" cols="45" placeholder="links.example.com">%s</textarea>', esc_textarea( implode( "\n", (array) $hosts ) ) );
	echo '<p class="description">' . esc_html__( 'One hostname per line. The site host and its www alias are always internal. Matching links are never annotated or recorded. Purge page caches after changing.', 'hopcount-click-tracking' ) . '</p></td></tr>';
	echo '</table>';

	echo '<h2>' . esc_html__( 'Data retention', 'hopcount-click-tracking' ) . '</h2><table class="form-table" role="presentation">';
	printf(
		'<tr><th scope="row"><label for="octk_retention_months">%s</label></th><td><input type="number" id="octk_retention_months" name="octk_retention_months" value="%d" min="1" max="120" /> %s',
		esc_html__( 'Keep hourly counts for', 'hopcount-click-tracking' ),
		(int) $retention,
		esc_html__( 'months', 'hopcount-click-tracking' )
	);
	echo '<p class="description">' . esc_html__( 'Counts are stored as hourly aggregate buckets, not individual events. Lowering retention removes older buckets at the next scheduled cleanup.', 'hopcount-click-tracking' ) . '</p></td></tr>';
	echo '</table>';

	echo '<h2>' . esc_html__( 'Uninstall', 'hopcount-click-tracking' ) . '</h2><table class="form-table" role="presentation">';
	echo '<tr><th scope="row">' . esc_html__( 'On uninstall', 'hopcount-click-tracking' ) . '</th><td><label><input type="checkbox" name="octk_uninstall_delete" value="1"' . checked( $uninstall, true, false ) . ' /> ' . esc_html__( 'Delete all tracking data when the plugin is uninstalled', 'hopcount-click-tracking' ) . '</label>';
	echo '<p class="description">' . esc_html__( 'Off by default: uninstalling preserves data. Deactivating never deletes data.', 'hopcount-click-tracking' ) . '</p></td></tr>';
	echo '</table>';

	submit_button();
	echo '</form></div>';
}

/* ---------------------------------------------------------------------- */
/* Status                                                                  */
/* ---------------------------------------------------------------------- */

/**
 * Legacy adoption failed or is still pending: say so loudly, because the
 * debug log is invisible on production and the Report would otherwise be
 * silently empty.
 */
add_action( 'admin_notices', 'octk_adoption_notice' );
function octk_adoption_notice(): void {
	if ( ! current_user_can( 'manage_options' ) || ! octk_adoption_pending() ) {
		return;
	}
	$reason = (string) get_transient( OCTK_ADOPT_ERROR );
	echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Hopcount Click Tracking:', 'hopcount-click-tracking' ) . '</strong> '
		. esc_html__( 'existing click data has not been migrated yet', 'hopcount-click-tracking' )
		. ( '' !== $reason ? ' (' . esc_html( $reason ) . ')' : '' )
		. '. ' . esc_html__( 'Tracking and reports are paused until this is resolved; the migration retries on every page load.', 'hopcount-click-tracking' )
		. '</p></div>';
}

function octk_render_status_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'hopcount-click-tracking' ) );
	}

	global $wpdb;

	// v1 tables drive the counts; the v2 row below reports label objects separately.
	$schema_ready = octk_tables_exist();
	$counts       = $schema_ready ? OCTK_Reporting::status_counts() : null;
	$next_run     = wp_next_scheduled( OCTK_RETENTION_HOOK );
	$routes       = rest_get_server()->get_routes( 'octk/v1' );

	$schema_ok = (int) get_option( 'octk_schema_version', 0 ) === OCTK_SCHEMA_VERSION;
	$route_ok  = isset( $routes['/octk/v1/click'] );
	$rl_active = octk_rate_limit_enabled() && wp_using_ext_object_cache();
	$v2_ok     = octk_schema_v2_ready();

	// state: ok (green) / warn (amber) / info (neutral fact).
	$rows = array(
		array( 'info', __( 'Plugin version', 'hopcount-click-tracking' ), OCTK_VERSION ),
		array(
			$schema_ok ? 'ok' : 'warn',
			__( 'Database schema version', 'hopcount-click-tracking' ),
			sprintf(
				/* translators: 1: installed schema version, 2: expected schema version. */
				__( '%1$s (expected %2$s)', 'hopcount-click-tracking' ),
				(string) get_option( 'octk_schema_version', '0' ),
				(string) OCTK_SCHEMA_VERSION
			),
		),
		array(
			octk_adoption_pending() ? 'warn' : 'ok',
			__( 'Legacy data migration', 'hopcount-click-tracking' ),
			octk_adoption_pending()
				/* translators: %s: failure reason recorded by the migration. */
				? sprintf( __( 'pending: %s', 'hopcount-click-tracking' ), (string) get_transient( OCTK_ADOPT_ERROR ) ?: __( 'not yet attempted', 'hopcount-click-tracking' ) )
				: __( 'not needed or complete', 'hopcount-click-tracking' ),
		),
		array( $schema_ready ? 'ok' : 'warn', __( 'Tracking tables', 'hopcount-click-tracking' ), $schema_ready ? __( 'Present', 'hopcount-click-tracking' ) : __( 'Missing - needs attention', 'hopcount-click-tracking' ) ),
		array( $v2_ok ? 'ok' : 'warn', __( 'Label schema (v2 objects)', 'hopcount-click-tracking' ), $v2_ok ? __( 'Present', 'hopcount-click-tracking' ) : __( 'Missing - needs attention', 'hopcount-click-tracking' ) ),
		array( octk_enabled() ? 'ok' : 'warn', __( 'Tracking', 'hopcount-click-tracking' ), octk_enabled() ? __( 'Enabled', 'hopcount-click-tracking' ) : __( 'Disabled', 'hopcount-click-tracking' ) ),
		array( $route_ok ? 'ok' : 'warn', __( 'REST click route', 'hopcount-click-tracking' ), $route_ok ? __( 'Registered', 'hopcount-click-tracking' ) : __( 'Not registered - needs attention', 'hopcount-click-tracking' ) ),
		array(
			$next_run ? 'ok' : 'warn',
			__( 'Retention cleanup', 'hopcount-click-tracking' ),
			$next_run
				/* translators: %s: formatted date/time of the next scheduled run. */
				? sprintf( __( 'Scheduled; next run %s', 'hopcount-click-tracking' ), octk_format_bucket( gmdate( 'Y-m-d H:i:s', $next_run ) ) )
				: __( 'Not scheduled - needs attention', 'hopcount-click-tracking' ),
		),
		array( 'info', __( 'Registered link identities (approx.)', 'hopcount-click-tracking' ), $counts ? number_format_i18n( $counts->links ) : __( 'n/a', 'hopcount-click-tracking' ) ),
		array( 'info', __( 'Aggregate buckets (approx.)', 'hopcount-click-tracking' ), $counts ? number_format_i18n( $counts->buckets ) : __( 'n/a', 'hopcount-click-tracking' ) ),
		array( 'info', __( 'Latest activity bucket', 'hopcount-click-tracking' ), $counts && $counts->last_bucket ? octk_format_bucket( $counts->last_bucket ) : __( 'None', 'hopcount-click-tracking' ) ),
		array( 'info', __( 'Persistent object cache', 'hopcount-click-tracking' ), wp_using_ext_object_cache() ? __( 'Detected', 'hopcount-click-tracking' ) : __( 'Not detected', 'hopcount-click-tracking' ) ),
		array(
			octk_rate_limit_enabled() && ! $rl_active ? 'warn' : 'info',
			__( 'Rate limiter', 'hopcount-click-tracking' ),
			$rl_active
				/* translators: %d: allowed clicks per minute per IP address. */
				? sprintf( __( 'Built-in limiter active (%d/min per IP, fail-open)', 'hopcount-click-tracking' ), (int) octk_rate_limit_per_minute() )
				: ( octk_rate_limit_enabled() ? __( 'Enabled but inactive - no persistent object cache (fail-open)', 'hopcount-click-tracking' ) : __( 'Allow-all default (put a CDN/WAF rate limit in front)', 'hopcount-click-tracking' ) ),
		),
		array( 'info', __( 'WordPress', 'hopcount-click-tracking' ), get_bloginfo( 'version' ) ),
		array( 'info', __( 'PHP', 'hopcount-click-tracking' ), PHP_VERSION ),
		array( 'info', __( 'Database', 'hopcount-click-tracking' ), $wpdb->db_version() ),
	);

	octk_admin_styles();
	echo '<div class="wrap"><h1>' . esc_html__( 'Click Tracking Status', 'hopcount-click-tracking' ) . '</h1>';
	echo '<p>' . esc_html__( 'Read-only diagnosis. Counts are cached for five minutes and approximate.', 'hopcount-click-tracking' ) . '</p>';
	echo '<table class="widefat striped" style="max-width:760px;"><tbody>';
	foreach ( $rows as $row ) {
		printf(
			'<tr><td style="width:45%%;">%s</td><td><span class="octk-dot octk-dot-%s" aria-hidden="true"></span>%s</td></tr>',
			esc_html( $row[1] ),
			esc_attr( $row[0] ),
			esc_html( $row[2] )
		);
	}
	echo '</tbody></table></div>';
}
