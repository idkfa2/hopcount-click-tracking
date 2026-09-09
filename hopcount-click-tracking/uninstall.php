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

foreach ( array( 'octk_clicks_hourly', 'octk_links', 'octk_label_overrides' ) as $octk_table ) {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $octk_table ) );
}

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
