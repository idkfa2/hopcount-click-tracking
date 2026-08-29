<?php
/**
 * Public click endpoint (scope §5): POST /wp-json/octk/v1/click.
 * POST-only, intentionally public, token-only. No GET variant may ever be
 * added (query-string tokens would leak into access logs — decision 30).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function octk_register_rest_routes(): void {
	register_rest_route(
		'octk/v1',
		'/click',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			// Intentionally public: this is a click beacon endpoint hit by
			// anonymous visitors, so authentication would prove nothing.
			// Safety comes from the design instead: POST-only (no GET variant
			// ever); the only input is a 32-hex token validated by the args
			// schema and looked up against a unique index; the response
			// exposes no data (a bare {recorded:true} or a 4xx); the only
			// write is an aggregate hourly counter increment, never link
			// metadata; and abuse is bounded by the recommended CDN/WAF rate
			// limit plus the built-in per-IP limiter (scope §5, §11).
			'permission_callback' => '__return_true',
			'callback'            => 'octk_handle_click',
			'args'                => array(
				'token' => array(
					'required'          => true,
					'type'              => 'string',
					'validate_callback' => 'octk_validate_token_arg',
				),
			),
		)
	);
}

function octk_validate_token_arg( $value ): bool {
	return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{32}$/', $value );
}

/**
 * Hourly UTC bucket start for a Unix timestamp (scope §7).
 */
function octk_bucket_for( int $timestamp ): string {
	return gmdate( 'Y-m-d H:00:00', $timestamp );
}

/**
 * Endpoint sequence (scope §5): master switch → token syntax (arg schema) →
 * rate limiter → unique-index lookup → atomic increment. No metadata writes.
 */
function octk_handle_click( WP_REST_Request $request ) {
	global $wpdb;

	// 1. Master switch: cached pages keep beaconing after a disable.
	if ( ! octk_enabled() ) {
		return new WP_Error( 'octk_disabled', __( 'Tracking is disabled.', 'outbound-click-tracking' ), array( 'status' => 403 ) );
	}

	// 2. Token syntax was enforced by the args schema; defend anyway.
	$token = $request->get_param( 'token' );
	if ( ! octk_validate_token_arg( $token ) ) {
		return new WP_Error( 'octk_bad_token', __( 'Invalid token.', 'outbound-click-tracking' ), array( 'status' => 400 ) );
	}

	// 3. Rate limiter: allow-all portable default; the built-in per-IP
	// limiter or a site plugin hooks in here. Fails open by design (decision 29).
	if ( ! (bool) apply_filters( 'octk_click_allowed', true, $token, $request ) ) {
		return new WP_Error( 'octk_rate_limited', __( 'Too many requests.', 'outbound-click-tracking' ), array( 'status' => 429 ) );
	}

	// 4. Unique-index lookup; every known token is eligible in v1.
	$links   = octk_table_links();
	$link_id = $wpdb->get_var(
		$wpdb->prepare( "SELECT id FROM {$links} WHERE public_token = UNHEX(%s)", $token )
	);
	if ( null === $link_id ) {
		return new WP_Error( 'octk_unknown_token', __( 'Unknown token.', 'outbound-click-tracking' ), array( 'status' => 404 ) );
	}

	// 5. Atomic increment of the current hourly UTC bucket.
	$clicks = octk_table_clicks();
	$result = $wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$clicks} (link_id, bucket_start_utc, hits) VALUES (%d, %s, 1) ON DUPLICATE KEY UPDATE hits = hits + 1",
			(int) $link_id,
			octk_bucket_for( time() )
		)
	);
	if ( false === $result ) {
		octk_log( 'click increment failed: ' . $wpdb->last_error );
		return new WP_Error( 'octk_storage_error', __( 'Unable to record click.', 'outbound-click-tracking' ), array( 'status' => 500 ) );
	}

	return rest_ensure_response( array( 'recorded' => true ) );
}
