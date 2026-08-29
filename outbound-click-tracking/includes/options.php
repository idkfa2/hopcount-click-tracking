<?php
/**
 * Options and logging helpers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Master switch: gates annotation, listener enqueue, and the endpoint.
 */
function octk_enabled(): bool {
	// Paused while legacy data is still waiting to be adopted: no annotation,
	// no listener, endpoint 403s, so nothing queries tables that may not exist.
	if ( function_exists( 'octk_adoption_pending' ) && octk_adoption_pending() ) {
		return false;
	}
	return '1' === get_option( 'octk_enabled', '1' );
}

/**
 * Roles that are never tracked and never receive the listener (default).
 */
function octk_default_excluded_roles(): array {
	return array( 'administrator', 'editor' );
}

/**
 * Debug-visible logging; never user-facing (scope §5 fail-soft doctrine).
 */
function octk_log( string $message ): void {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( 'octk: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug-only, gated on WP_DEBUG
	}
}
