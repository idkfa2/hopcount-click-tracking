<?php
/**
 * Uninstall handler (scope §9, decision 22): data is PRESERVED by default.
 * Deletion happens only when the explicit opt-in setting was enabled.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( '1' !== get_option( 'octk_uninstall_delete', '0' ) ) {
	return;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}octk_clicks_hourly" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}octk_links" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}octk_label_overrides" );

foreach ( array(
	'octk_schema_version',
	'octk_enabled',
	'octk_excluded_roles',
	'octk_internal_hosts',
	'octk_retention_months',
	'octk_rate_limit',
	'octk_rate_limit_per_minute',
	'octk_uninstall_delete',
) as $option ) {
	delete_option( $option );
}

delete_transient( 'octk_status_counts' );
delete_transient( 'octk_adopt_error' );
delete_metadata( 'user', 0, 'octk_dashboard_days', '', true );
foreach ( array( 'today', 'yesterday', '7', '30', '90' ) as $octk_period ) {
	delete_transient( 'octk_dashboard_widget_' . $octk_period );
}
wp_clear_scheduled_hook( 'octk_retention_cleanup' );
