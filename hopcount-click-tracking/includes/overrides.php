<?php
/**
 * Manual label overrides (link-labels plan): one label per exact normalized
 * destination URL, winning over auto-captured anchor text everywhere.
 *
 * Security model: no hashes or internal ids ever appear in markup. Forms
 * round-trip the destination URL itself; the handler validates syntax and
 * length only (NOT current eligibility - a host later marked internal must
 * stay renameable), looks it up exactly (BINARY) among tracked links, and
 * treats the STORED url as canonical from there.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Who may rename/delete labels. Portable default stays locked down;
 * a site plugin can map this to edit_published_posts via the
 * octk_label_capability filter so editors and authors can curate labels.
 */
function octk_label_capability(): string {
	return (string) apply_filters( 'octk_label_capability', 'manage_options' );
}

/**
 * Syntax/length-only check for a submitted rename target. Deliberately NOT
 * octk_normalize_destination(): that also applies the CURRENT
 * internal-hosts eligibility config and would strand previously tracked
 * destinations.
 */
function octk_label_target_syntax_ok( string $url ): bool {
	if ( '' === $url || strlen( $url ) > OCTK_MAX_URL_BYTES ) {
		return false;
	}
	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) ) {
		return false;
	}
	$scheme = strtolower( $parts['scheme'] ?? '' );
	$host   = $parts['host'] ?? '';
	return ( 'http' === $scheme || 'https' === $scheme ) && is_string( $host ) && '' !== $host;
}

/**
 * Resolve a submitted destination to its canonical STORED url, or null when
 * it is not a tracked destination. Exact byte comparison (BINARY beats the
 * case-insensitive default collation); the hash narrows via its index.
 */
function octk_override_canonical_url( string $submitted ): ?string {
	global $wpdb;

	if ( ! octk_label_target_syntax_ok( $submitted ) ) {
		return null;
	}

	$links  = octk_table_links();
	$stored = $wpdb->get_var( $wpdb->prepare(
		"SELECT destination_url FROM {$links} WHERE destination_hash = UNHEX(%s) AND BINARY destination_url = BINARY %s LIMIT 1",
		hash( 'sha256', $submitted ),
		$submitted
	) );

	return is_string( $stored ) ? $stored : null;
}

/**
 * All overrides for the Labels screen (bounded; newest first).
 *
 * @return object[]
 */
function octk_override_list( int $limit = 200 ): array {
	global $wpdb;
	$table = octk_table_overrides();
	$rows  = $wpdb->get_results( $wpdb->prepare(
		"SELECT destination_url, label, updated_at_utc FROM {$table} ORDER BY updated_at_utc DESC, destination_url ASC LIMIT %d",
		$limit
	) );
	return is_array( $rows ) ? $rows : array();
}

/**
 * Current override label for a canonical url, or null.
 */
function octk_override_get( string $canonical_url ): ?string {
	global $wpdb;
	$table = octk_table_overrides();
	$label = $wpdb->get_var( $wpdb->prepare(
		"SELECT label FROM {$table} WHERE destination_hash = UNHEX(%s) AND BINARY destination_url = BINARY %s",
		hash( 'sha256', $canonical_url ),
		$canonical_url
	) );
	return is_string( $label ) ? $label : null;
}

/**
 * Atomic upsert. Refuses (and logs) a hash row whose stored url differs -
 * same invariant policy as identity reuse. Returns true on success.
 */
function octk_override_upsert( string $canonical_url, string $label ): bool {
	global $wpdb;
	$table    = octk_table_overrides();
	$hash_hex = hash( 'sha256', $canonical_url );

	$existing_url = $wpdb->get_var( $wpdb->prepare(
		"SELECT destination_url FROM {$table} WHERE destination_hash = UNHEX(%s)",
		$hash_hex
	) );
	if ( is_string( $existing_url ) && $existing_url !== $canonical_url ) {
		octk_log( sprintf( 'override invariant failure for hash %s: stored destination differs from proposed', $hash_hex ) );
		return false;
	}

	$ok = $wpdb->query( $wpdb->prepare(
		"INSERT INTO {$table} (destination_hash, destination_url, label, updated_at_utc) VALUES (UNHEX(%s), %s, %s, %s)
		ON DUPLICATE KEY UPDATE label = VALUES(label), updated_at_utc = VALUES(updated_at_utc)",
		$hash_hex,
		$canonical_url,
		$label,
		gmdate( 'Y-m-d H:i:s' )
	) );
	if ( false === $ok ) {
		octk_log( 'override upsert failed: ' . $wpdb->last_error );
		return false;
	}

	octk_purge_label_caches();
	return true;
}

function octk_override_delete( string $canonical_url ): bool {
	global $wpdb;
	$table = octk_table_overrides();
	$ok    = $wpdb->query( $wpdb->prepare(
		"DELETE FROM {$table} WHERE destination_hash = UNHEX(%s) AND BINARY destination_url = BINARY %s",
		hash( 'sha256', $canonical_url ),
		$canonical_url
	) );
	if ( false === $ok ) {
		return false;
	}
	octk_purge_label_caches();
	return true;
}

/**
 * Renamed labels must not sit stale in the dashboard widget's 5-minute
 * caches.
 */
function octk_purge_label_caches(): void {
	foreach ( array_keys( OCTK_DASHBOARD_PERIODS ) as $period ) {
		delete_transient( 'octk_dashboard_widget_' . $period );
	}
}

/* ---------------------------------------------------------------------- */
/* Admin: Labels screen + handlers                                         */
/* ---------------------------------------------------------------------- */

function octk_labels_page_url( array $params = array() ): string {
	return add_query_arg( $params, admin_url( 'admin.php?page=octk-labels' ) );
}

add_action( 'admin_post_octk_save_label', 'octk_handle_save_label' );
function octk_handle_save_label(): void {
	if ( ! current_user_can( octk_label_capability() ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'hopcount-click-tracking' ), '', 403 );
	}
	check_admin_referer( 'octk_save_label' );

	$submitted = trim( (string) wp_unslash( $_POST['dest'] ?? '' ) );
	$canonical = octk_override_canonical_url( $submitted );
	if ( null === $canonical ) {
		wp_safe_redirect( octk_labels_page_url( array( 'octk_msg' => 'notfound' ) ) );
		exit;
	}

	// Rejecting empty is deliberate: deletion is only the explicit Delete action.
	$label = mb_substr( trim( sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) ) ), 0, OCTK_LABEL_MAX_CHARS );
	if ( '' === $label ) {
		wp_safe_redirect( octk_labels_page_url( array( 'octk_msg' => 'empty', 'dest' => rawurlencode( $canonical ) ) ) );
		exit;
	}

	$msg = octk_override_upsert( $canonical, $label ) ? 'saved' : 'failed';
	wp_safe_redirect( octk_labels_page_url( array( 'octk_msg' => $msg ) ) );
	exit;
}

add_action( 'admin_post_octk_delete_label', 'octk_handle_delete_label' );
function octk_handle_delete_label(): void {
	if ( ! current_user_can( octk_label_capability() ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'hopcount-click-tracking' ), '', 403 );
	}
	check_admin_referer( 'octk_delete_label' );

	$submitted = trim( (string) wp_unslash( $_POST['dest'] ?? '' ) );
	$canonical = octk_override_canonical_url( $submitted );

	$msg = ( null !== $canonical && octk_override_delete( $canonical ) ) ? 'deleted' : 'failed';
	wp_safe_redirect( octk_labels_page_url( array( 'octk_msg' => $msg ) ) );
	exit;
}

function octk_render_labels_page(): void {
	if ( ! current_user_can( octk_label_capability() ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'hopcount-click-tracking' ) );
	}

	octk_admin_styles();
	echo '<div class="wrap"><h1>' . esc_html__( 'Link Labels', 'hopcount-click-tracking' ) . '</h1>';
	echo '<p>' . esc_html__( 'Labels name the affiliate product behind a destination URL. Automatic labels come from the link text in your articles; a manual label set here wins over the automatic one and applies to this exact URL on every page it appears.', 'hopcount-click-tracking' ) . '</p>';

	if ( ! octk_schema_v2_ready() ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'The label tables are unavailable.', 'hopcount-click-tracking' ) . ' ' . sprintf(
			/* translators: %s: link to the Status page (anchor text "Status page"). */
			esc_html__( 'Visit the %s.', 'hopcount-click-tracking' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=octk-status' ) ) . '">' . esc_html__( 'Status page', 'hopcount-click-tracking' ) . '</a>'
		) . '</p></div></div>';
		return;
	}

	$messages = array(
		'saved'    => array( 'success', __( 'Label saved.', 'hopcount-click-tracking' ) ),
		'deleted'  => array( 'success', __( 'Label removed. The automatic label (if any) applies again.', 'hopcount-click-tracking' ) ),
		'empty'    => array( 'error', __( 'The label cannot be empty. To remove a manual label, use its Remove button below.', 'hopcount-click-tracking' ) ),
		'notfound' => array( 'error', __( 'That URL is not a tracked destination.', 'hopcount-click-tracking' ) ),
		'failed'   => array( 'error', __( 'Saving the label failed. Check the Status page.', 'hopcount-click-tracking' ) ),
	);
	$msg = sanitize_key( $_GET['octk_msg'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice selector.
	if ( isset( $messages[ $msg ] ) ) {
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $messages[ $msg ][0] ), esc_html( $messages[ $msg ][1] ) );
	}

	// Rename form when a destination is being edited.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only prefill; the save handler checks its own nonce.
	$dest = trim( (string) wp_unslash( $_GET['dest'] ?? '' ) );
	if ( '' !== $dest ) {
		$canonical = octk_override_canonical_url( $dest );
		if ( null === $canonical ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'That URL is not a tracked destination.', 'hopcount-click-tracking' ) . '</p></div>';
		} else {
			octk_render_label_form( $canonical );
		}
	}

	$rows = octk_override_list();
	echo '<h2>' . esc_html__( 'Manual labels', 'hopcount-click-tracking' ) . '</h2>';
	if ( empty( $rows ) ) {
		echo '<p>' . esc_html__( 'No manual labels yet.', 'hopcount-click-tracking' ) . ' ' . sprintf(
			/* translators: %s: link to the report page (anchor text "report"). */
			esc_html__( 'Use the Rename action on a %s row to add one.', 'hopcount-click-tracking' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=octk-report' ) ) . '">' . esc_html__( 'report', 'hopcount-click-tracking' ) . '</a>'
		) . '</p></div>';
		return;
	}

	echo '<table class="widefat striped octk-table" style="max-width:1000px;">';
	echo '<thead><tr><th scope="col">' . esc_html__( 'Label', 'hopcount-click-tracking' ) . '</th><th scope="col">' . esc_html__( 'Destination', 'hopcount-click-tracking' ) . '</th><th scope="col" style="width:220px;">' . esc_html__( 'Updated', 'hopcount-click-tracking' ) . '</th><th scope="col" style="width:160px;">' . esc_html__( 'Actions', 'hopcount-click-tracking' ) . '</th></tr></thead><tbody>';
	foreach ( $rows as $row ) {
		$edit_url = octk_labels_page_url( array( 'dest' => rawurlencode( $row->destination_url ) ) );
		echo '<tr>';
		echo '<td><strong>' . esc_html( $row->label ) . '</strong></td>';
		echo '<td><span class="octk-url">' . esc_html( $row->destination_url ) . '</span></td>';
		echo '<td>' . esc_html( octk_format_bucket( $row->updated_at_utc ) ) . '</td>';
		echo '<td><a class="button button-small" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'hopcount-click-tracking' ) . '</a> ';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
		wp_nonce_field( 'octk_delete_label' );
		echo '<input type="hidden" name="action" value="octk_delete_label" />';
		echo '<input type="hidden" name="dest" value="' . esc_attr( $row->destination_url ) . '" />';
		submit_button( __( 'Remove', 'hopcount-click-tracking' ), 'small delete', '', false );
		echo '</form></td></tr>';
	}
	echo '</tbody></table></div>';
}

function octk_render_label_form( string $canonical ): void {
	global $wpdb;

	$current = octk_override_get( $canonical );
	if ( null === $current ) {
		// Prefill with an auto label from any page carrying this destination.
		$links   = octk_table_links();
		$auto    = $wpdb->get_var( $wpdb->prepare(
			"SELECT label FROM {$links} WHERE destination_hash = UNHEX(%s) AND BINARY destination_url = BINARY %s AND label IS NOT NULL LIMIT 1",
			hash( 'sha256', $canonical ),
			$canonical
		) );
		$current = is_string( $auto ) ? $auto : '';
	}

	echo '<div class="octk-card" style="max-width:760px;margin:16px 0;">';
	echo '<h2 style="margin-top:0;">' . esc_html__( 'Rename destination', 'hopcount-click-tracking' ) . '</h2>';
	echo '<p class="octk-url">' . esc_html( $canonical ) . '</p>';
	echo '<p class="description">' . esc_html__( 'Applies to this exact URL wherever it appears. URLs differing only by #fragment are treated as the same destination; different query parameters are separate destinations.', 'hopcount-click-tracking' ) . '</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'octk_save_label' );
	echo '<input type="hidden" name="action" value="octk_save_label" />';
	echo '<input type="hidden" name="dest" value="' . esc_attr( $canonical ) . '" />';
	printf( '<input type="text" name="label" value="%s" maxlength="%d" style="width:100%%;max-width:480px;" placeholder="%s" /> ', esc_attr( $current ), (int) OCTK_LABEL_MAX_CHARS, esc_attr__( 'e.g. NOW Foods Creatine (iHerb)', 'hopcount-click-tracking' ) );
	submit_button( __( 'Save label', 'hopcount-click-tracking' ), 'primary', '', false );
	echo ' <a class="button" href="' . esc_url( octk_labels_page_url() ) . '">' . esc_html__( 'Cancel', 'hopcount-click-tracking' ) . '</a>';
	echo '</form></div>';
}
