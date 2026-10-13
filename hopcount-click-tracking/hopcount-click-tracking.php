<?php
/**
 * Plugin Name: Hopcount Click Tracking
 * Plugin URI: https://github.com/idkfa2/hopcount-click-tracking
 * Description: Privacy-friendly outbound and affiliate click tracking. Server-side link pre-registration, token-only click endpoint, hourly aggregate counts, no cookies or per-click rows.
 * Version: 1.3.1
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Author: aph5
 * Author URI: https://profiles.wordpress.org/aph5/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: hopcount-click-tracking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OCTK_VERSION', '1.3.1' );
define( 'OCTK_PLUGIN_FILE', __FILE__ );
define( 'OCTK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require OCTK_PLUGIN_DIR . 'includes/options.php';
require OCTK_PLUGIN_DIR . 'includes/schema.php';
require OCTK_PLUGIN_DIR . 'includes/url.php';
require OCTK_PLUGIN_DIR . 'includes/resolve.php';
require OCTK_PLUGIN_DIR . 'includes/identity.php';
require OCTK_PLUGIN_DIR . 'includes/registration.php';
require OCTK_PLUGIN_DIR . 'includes/labels.php';
require OCTK_PLUGIN_DIR . 'includes/render.php';
require OCTK_PLUGIN_DIR . 'includes/endpoint.php';
require OCTK_PLUGIN_DIR . 'includes/limiter.php';
require OCTK_PLUGIN_DIR . 'includes/reporting.php';
require OCTK_PLUGIN_DIR . 'includes/retention.php';
require OCTK_PLUGIN_DIR . 'includes/admin.php';
require OCTK_PLUGIN_DIR . 'includes/export.php';
require OCTK_PLUGIN_DIR . 'includes/dashboard.php';
require OCTK_PLUGIN_DIR . 'includes/overrides.php';

// Multisite is unsupported in v1 (scope §9, decision 22).
register_activation_hook( __FILE__, static function () {
	if ( is_multisite() ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			esc_html__( 'Click Tracking does not support multisite in this version.', 'hopcount-click-tracking' ),
			esc_html__( 'Click Tracking', 'hopcount-click-tracking' ),
			array( 'back_link' => true )
		);
	}
	octk_migrate();
	add_option( 'octk_enabled', '1' );
	octk_schedule_retention();
	octk_resolve_ensure_secret();
	octk_schedule_resolve();
} );

register_deactivation_hook( __FILE__, static function () {
	octk_unschedule_retention();
	octk_unschedule_resolve();
} );

add_action( 'plugins_loaded', static function () {
	octk_migrate();             // Idempotent; covers upgrades without reactivation.
	octk_schedule_retention();  // Same: activation alone misses upgrade installs.
	octk_schedule_resolve();
	octk_register_render_hooks();
	octk_register_resolve_hooks();
} );

add_action( 'rest_api_init', 'octk_register_rest_routes' );
