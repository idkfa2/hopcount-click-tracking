<?php
/**
 * PHPUnit bootstrap for the wp-env tests environment.
 *
 * wp-env's tests containers set WP_TESTS_DIR to the core test library.
 * Run via: npm run test (see package.json).
 */

require_once __DIR__ . '/vendor/autoload.php';

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = '/wordpress-phpunit';
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter( 'muplugins_loaded', static function () {
	require WP_PLUGIN_DIR . '/hopcount-click-tracking/hopcount-click-tracking.php';

	// Shortcoder is an integration-fixture dependency (scope §14).
	$shortcoder = WP_PLUGIN_DIR . '/shortcoder/shortcoder.php';
	if ( file_exists( $shortcoder ) ) {
		require $shortcoder;
	}
} );

require $_tests_dir . '/includes/bootstrap.php';
