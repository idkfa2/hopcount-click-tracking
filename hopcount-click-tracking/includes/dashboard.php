<?php
/**
 * Dashboard widget (UI plan §4): most-clicked links over a selectable period
 * (7/30/90 days, per-user preference), reusing the reporting service,
 * ~5-minute TTL cache per period, capability-gated, fail-soft. No charts,
 * animation, live refresh, or external requests.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Number of top links shown in the dashboard widget. */
const OCTK_DASHBOARD_TOP = 10;

// Period keys. Keys are also the transient suffix and the stored user
// preference; 'today'/'yesterday' are single site-tz days, the numeric keys
// are trailing windows including today. Labels: octk_dashboard_period_labels().
const OCTK_DASHBOARD_PERIODS = array(
	'today'     => true,
	'yesterday' => true,
	'7'         => true,
	'30'        => true,
	'90'        => true,
);

/**
 * Period key => translated dropdown label (a const cannot call __()).
 *
 * @return array<string, string>
 */
function octk_dashboard_period_labels(): array {
	return array(
		'today'     => __( 'Today', 'hopcount-click-tracking' ),
		'yesterday' => __( 'Yesterday', 'hopcount-click-tracking' ),
		'7'         => __( 'Last 7 days', 'hopcount-click-tracking' ),
		'30'        => __( 'Last 30 days', 'hopcount-click-tracking' ),
		'90'        => __( 'Last 90 days', 'hopcount-click-tracking' ),
	);
}

function octk_dashboard_period(): string {
	// Meta key predates the string periods; legacy stored ints ('7' etc.) are valid keys.
	$period = (string) get_user_meta( get_current_user_id(), 'octk_dashboard_days', true );
	return isset( OCTK_DASHBOARD_PERIODS[ $period ] ) ? $period : '7';
}

/**
 * Half-open site-timezone range for a period key, in UTC bucket format.
 *
 * @return array{0: string, 1: string} start_utc, end_utc.
 */
function octk_dashboard_range( string $period ): array {
	$today = new DateTimeImmutable( 'today', wp_timezone() );
	$utc   = new DateTimeZone( 'UTC' );

	if ( 'today' === $period ) {
		$start = $today;
		$end   = $today->modify( '+1 day' );
	} elseif ( 'yesterday' === $period ) {
		$start = $today->modify( '-1 day' );
		$end   = $today;
	} else {
		$start = $today->modify( '-' . ( (int) $period - 1 ) . ' days' );
		$end   = $today->modify( '+1 day' );
	}

	return array(
		$start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
		$end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
	);
}

add_action( 'wp_dashboard_setup', static function () {
	if ( ! current_user_can( octk_report_capability() ) ) {
		return;
	}

	// Persist a period change (own display preference, strict allow-list).
	if ( isset( $_GET['octk_widget_days'], $_GET['octk_widget_nonce'] )
		&& wp_verify_nonce( sanitize_key( $_GET['octk_widget_nonce'] ), 'octk_widget_days' )
		&& isset( OCTK_DASHBOARD_PERIODS[ sanitize_key( $_GET['octk_widget_days'] ) ] )
	) {
		update_user_meta( get_current_user_id(), 'octk_dashboard_days', sanitize_key( $_GET['octk_widget_days'] ) );
	}

	wp_add_dashboard_widget( 'octk_dashboard', __( 'Outbound clicks', 'hopcount-click-tracking' ), 'octk_render_dashboard_widget' );
} );

/**
 * Totals + top five links for the period, cached per period. Null on failure.
 *
 * @return object|null {clicks: int, top: object[]}
 */
function octk_dashboard_data( string $period ) {
	if ( ! isset( OCTK_DASHBOARD_PERIODS[ $period ] ) ) {
		$period = '7';
	}

	$transient = 'octk_dashboard_widget_' . $period;
	$cached    = get_transient( $transient );
	if ( is_object( $cached ) ) {
		return $cached;
	}

	list( $start_utc, $end_utc ) = octk_dashboard_range( $period );

	$args = array(
		'start_utc' => $start_utc,
		'end_utc'   => $end_utc,
		'orderby'   => 'clicks',
		'order'     => 'desc',
		'page'      => 1,
		'per_page'  => 25,
	);

	$summary = OCTK_Reporting::summary( $args );
	$result  = OCTK_Reporting::query_rows( $args );
	if ( null === $summary || null === $result ) {
		return null;
	}

	$data = (object) array(
		'clicks' => $summary->clicks,
		'top'    => array_slice( $result->rows, 0, OCTK_DASHBOARD_TOP ),
	);
	set_transient( $transient, $data, 5 * MINUTE_IN_SECONDS );
	return $data;
}

function octk_dashboard_period_form( string $period ): void {
	echo '<form method="get" action="' . esc_url( admin_url( 'index.php' ) ) . '" style="margin:0 0 8px;">';
	wp_nonce_field( 'octk_widget_days', 'octk_widget_nonce', false );
	echo '<label for="octk-widget-days" class="screen-reader-text">' . esc_html__( 'Period', 'hopcount-click-tracking' ) . '</label>';
	echo '<select name="octk_widget_days" id="octk-widget-days" onchange="this.form.submit()">';
	foreach ( octk_dashboard_period_labels() as $key => $label ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $period, $key, false ), esc_html( $label ) );
	}
	echo '</select><noscript> <button type="submit" class="button button-small">' . esc_html__( 'Apply', 'hopcount-click-tracking' ) . '</button></noscript>';
	echo '</form>';
}

/**
 * Report link matching the widget period. The report has no single-day
 * presets, so today/yesterday map to its custom range.
 */
function octk_dashboard_report_url( string $period ): string {
	if ( 'today' === $period || 'yesterday' === $period ) {
		$day = new DateTimeImmutable( 'today' === $period ? 'today' : 'yesterday', wp_timezone() );
		return admin_url( 'admin.php?page=octk-report&range=custom&from=' . $day->format( 'Y-m-d' ) . '&to=' . $day->format( 'Y-m-d' ) );
	}
	return admin_url( 'admin.php?page=octk-report&range=' . $period );
}

function octk_dashboard_zero_text( string $period ): string {
	if ( 'today' === $period ) {
		return __( 'No outbound clicks recorded today yet.', 'hopcount-click-tracking' );
	}
	if ( 'yesterday' === $period ) {
		return __( 'No outbound clicks recorded yesterday.', 'hopcount-click-tracking' );
	}
	/* translators: %d: number of days in the selected period. */
	return sprintf( __( 'No outbound clicks recorded in the last %d days yet.', 'hopcount-click-tracking' ), (int) $period );
}

function octk_render_dashboard_widget(): void {
	$period     = octk_dashboard_period();
	$report_url = octk_dashboard_report_url( $period );

	if ( ! OCTK_Reporting::schema_ready() ) {
		echo '<p>' . esc_html__( 'Click tracking is unavailable.', 'hopcount-click-tracking' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=octk-status' ) ) . '">' . esc_html__( 'Check status', 'hopcount-click-tracking' ) . '</a>.</p>';
		return;
	}
	if ( ! octk_enabled() ) {
		echo '<p>' . esc_html__( 'Tracking is currently disabled.', 'hopcount-click-tracking' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=octk-settings' ) ) . '">' . esc_html__( 'Settings', 'hopcount-click-tracking' ) . '</a></p>';
		return;
	}

	octk_dashboard_period_form( $period );

	$data = octk_dashboard_data( $period );
	if ( null === $data ) {
		echo '<p>' . esc_html__( 'The click summary is unavailable right now.', 'hopcount-click-tracking' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=octk-status' ) ) . '">' . esc_html__( 'Check status', 'hopcount-click-tracking' ) . '</a>.</p>';
		return;
	}

	if ( 0 === $data->clicks ) {
		echo '<p>' . esc_html( octk_dashboard_zero_text( $period ) ) . '</p>';
		echo '<p><a href="' . esc_url( $report_url ) . '">' . esc_html__( 'View full click report &rarr;', 'hopcount-click-tracking' ) . '</a></p>';
		return;
	}

	printf(
		'<p style="font-size:20px;font-weight:600;margin:4px 0 12px;">%s <span style="font-size:13px;font-weight:400;color:#646970;">%s</span></p>',
		esc_html( number_format_i18n( $data->clicks ) ),
		esc_html__( 'total clicks', 'hopcount-click-tracking' )
	);

	echo '<table style="width:100%;border-collapse:collapse;">';
	foreach ( $data->top as $row ) {
		// Primary: the resolved label when one exists (link-labels plan),
		// otherwise host bold plus a taste of the path.
		$label = isset( $row->label ) && is_string( $row->label ) && '' !== $row->label ? $row->label : null;
		if ( null !== $label ) {
			if ( mb_strlen( $label ) > 40 ) {
				$label = mb_substr( $label, 0, 37 ) . '…';
			}
			$destination = '<a href="' . esc_url( $row->destination_url ) . '" rel="noopener noreferrer nofollow" target="_blank" title="' . esc_attr( $row->destination_url ) . '">'
				. '<strong>' . esc_html( $label ) . '</strong></a>';
		} else {
			$path = preg_replace( '#^https?://[^/]*#', '', $row->destination_url );
			if ( '' === $path ) {
				$path = '/';
			}
			if ( mb_strlen( $path ) > 34 ) {
				$path = mb_substr( $path, 0, 31 ) . '…';
			}
			$destination = '<a href="' . esc_url( $row->destination_url ) . '" rel="noopener noreferrer nofollow" target="_blank" title="' . esc_attr( $row->destination_url ) . '">'
				. '<strong>' . esc_html( $row->destination_host ) . '</strong><span style="color:#787c82;">' . esc_html( $path ) . '</span></a>';
		}

		// Secondary: the page it was clicked from.
		$post  = get_post( (int) $row->source_id );
		$title = $post ? get_the_title( $post ) : ( $row->source_url ?: __( 'Deleted post', 'hopcount-click-tracking' ) );
		if ( mb_strlen( $title ) > 55 ) {
			$title = mb_substr( $title, 0, 52 ) . '…';
		}
		$source = $post
			? '<a href="' . esc_url( get_permalink( $post ) ) . '" style="color:inherit;">' . esc_html( $title ) . '</a>'
			: esc_html( $title );

		printf(
			'<tr><td style="padding:4px 0;">%s<br /><span style="color:#787c82;font-size:12px;">%s</span></td><td style="padding:4px 0;text-align:right;white-space:nowrap;vertical-align:top;">%s</td></tr>',
			$destination, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			/* translators: %s: title of the post the link was clicked on (already escaped HTML). */
			sprintf( esc_html__( 'from %s', 'hopcount-click-tracking' ), $source ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			esc_html( number_format_i18n( (int) $row->clicks ) )
		);
	}
	echo '</table>';

	echo '<p style="margin-top:10px;"><a href="' . esc_url( $report_url ) . '">' . esc_html__( 'View full click report &rarr;', 'hopcount-click-tracking' ) . '</a></p>';
}
