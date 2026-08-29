<?php
/**
 * Built-in rate limiter (scope §12): per-IP fixed-window counter on the
 * persistent object cache, attached behind the octk_click_allowed hook.
 *
 * Portable default stays allow-all: the limiter only acts when explicitly
 * enabled AND a persistent object cache is present (atomic incr). It FAILS
 * OPEN (decision 29): any cache unavailability records the click rather than
 * zeroing analytics; Cloudflare/WAF remains the outer abuse layer.
 *
 * Effective IP is REMOTE_ADDR only - never a raw CF-Connecting-IP header
 * (nginx/trusted-proxy normalization is the deployment's job, scope §12).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const OCTK_RL_GROUP = 'octk_rl';

function octk_rate_limit_enabled(): bool {
	return '1' === get_option( 'octk_rate_limit', '0' );
}

function octk_rate_limit_per_minute(): int {
	// Generous by design: a 429 only drops the beacon (navigation is never
	// affected), and the threat is counter inflation, not load. Long
	// posts can carry ~30 outbound links, so an open-every-link reader plus
	// same-IP coincidences (CGNAT, offices) must fit comfortably.
	return max( 1, min( 1000, (int) get_option( 'octk_rate_limit_per_minute', 60 ) ) );
}

/**
 * Count this hit in the current one-minute window; true while within limit.
 * Atomicity: wp_cache_add is NX, wp_cache_incr maps to Redis INCRBY.
 */
function octk_limiter_check( string $ip, int $limit, int $now ): bool {
	$key = 'ip:' . $ip . ':' . (int) floor( $now / MINUTE_IN_SECONDS );

	wp_cache_add( $key, 0, OCTK_RL_GROUP, 2 * MINUTE_IN_SECONDS );
	$count = wp_cache_incr( $key, 1, OCTK_RL_GROUP );

	if ( false === $count ) {
		return true; // Fail open.
	}
	return (int) $count <= $limit;
}

add_filter( 'octk_click_allowed', 'octk_builtin_limiter', 10, 2 );
function octk_builtin_limiter( $allowed, $token ) {
	if ( ! $allowed ) {
		return $allowed; // Another limiter already rejected.
	}
	if ( ! octk_rate_limit_enabled() || ! wp_using_ext_object_cache() ) {
		return $allowed;
	}
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	if ( '' === $ip ) {
		return $allowed; // Fail open.
	}
	return octk_limiter_check( $ip, octk_rate_limit_per_minute(), time() );
}
