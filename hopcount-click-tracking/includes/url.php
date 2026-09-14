<?php
/**
 * Destination eligibility and normalization (scope §4, §6).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const OCTK_MAX_URL_BYTES  = 2048;
const OCTK_MAX_HOST_BYTES = 255;

/**
 * Hosts treated as internal: home/site hosts, their www/non-www aliases,
 * configured extras. Lowercase.
 *
 * @return string[]
 */
function octk_internal_hosts(): array {
	$hosts = octk_site_hosts();

	$extra = get_option( 'octk_internal_hosts', array() );
	if ( is_array( $extra ) ) {
		foreach ( $extra as $host ) {
			if ( is_string( $host ) && '' !== $host ) {
				$hosts[] = strtolower( trim( $host ) );
			}
		}
	}

	return array_values( array_unique( apply_filters( 'octk_internal_hosts', $hosts ) ) );
}

/**
 * Validate and normalize a raw href into a trackable outbound destination.
 *
 * Returns array{url: string, host: string} for an eligible outbound absolute
 * HTTP(S) URL, or null for anything else (internal, relative, non-HTTP scheme,
 * malformed, over-limit). Over-limit URLs are skipped entirely, never truncated.
 */
function octk_normalize_destination( string $raw ): ?array {
	$external = octk_normalize_external( $raw );
	if ( null !== $external ) {
		return $external;
	}
	// Same-site hrefs (relative, or on the home/site host) may be cloaked
	// redirects: consult the resolution table (read-only). See resolve.php.
	return function_exists( 'octk_resolve_lookup' ) ? octk_resolve_lookup( $raw ) : null;
}

/**
 * The pure external normaliser: same contract as octk_normalize_destination()
 * but never consults the same-site resolution table.
 */
function octk_normalize_external( string $raw ): ?array {
	$raw = trim( $raw );
	if ( '' === $raw || strlen( $raw ) > OCTK_MAX_URL_BYTES ) {
		return null;
	}

	$parts = wp_parse_url( $raw );
	if ( ! is_array( $parts ) ) {
		return null;
	}

	$scheme = strtolower( $parts['scheme'] ?? '' );
	if ( 'http' !== $scheme && 'https' !== $scheme ) {
		return null;
	}

	$host = strtolower( $parts['host'] ?? '' );
	if ( '' === $host || strlen( $host ) > OCTK_MAX_HOST_BYTES ) {
		return null;
	}

	// Embedded credentials are not legitimate affiliate URLs.
	if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
		return null;
	}

	if ( in_array( $host, octk_internal_hosts(), true ) ) {
		return null;
	}

	// Drop semantically-default ports; keep any other.
	$port = '';
	if ( isset( $parts['port'] ) ) {
		$default = ( 'http' === $scheme ) ? 80 : 443;
		if ( (int) $parts['port'] !== $default ) {
			$port = ':' . (int) $parts['port'];
		}
	}

	// Empty path with an authority is equivalent to '/' (RFC 3986 §6.2.3).
	$path = $parts['path'] ?? '';
	if ( '' === $path ) {
		$path = '/';
	}

	// Preserve query (affiliate/campaign parameters intact); drop fragment.
	$query = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';

	$normalized = $scheme . '://' . $host . $port . $path . $query;
	if ( strlen( $normalized ) > OCTK_MAX_URL_BYTES ) {
		return null;
	}

	return array(
		'url'  => $normalized,
		'host' => $host,
	);
}
