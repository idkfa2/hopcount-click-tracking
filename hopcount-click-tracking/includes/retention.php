<?php
/**
 * Aggregate-bucket retention (scope §9): scheduled pruning is the ONLY
 * deletion path (decision 36 - no immediate destructive action). Link rows
 * are never deleted (append-only, §11).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const OCTK_RETENTION_HOOK       = 'octk_retention_cleanup';
const OCTK_RETENTION_BATCH      = 5000;
const OCTK_RETENTION_MAX_BATCHES = 20;

function octk_retention_months(): int {
	$months = (int) get_option( 'octk_retention_months', 24 );
	return max( 1, min( 120, $months ) );
}

function octk_schedule_retention(): void {
	if ( ! wp_next_scheduled( OCTK_RETENTION_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', OCTK_RETENTION_HOOK );
	}
}

function octk_unschedule_retention(): void {
	wp_clear_scheduled_hook( OCTK_RETENTION_HOOK );
}

/**
 * Bounded batch deletion of expired buckets.
 *
 * @return int rows deleted.
 */
function octk_retention_run(): int {
	global $wpdb;

	$clicks = octk_table_clicks();
	$cutoff = gmdate( 'Y-m-d H:00:00', strtotime( '-' . octk_retention_months() . ' months' ) );

	$deleted = 0;
	for ( $i = 0; $i < OCTK_RETENTION_MAX_BATCHES; $i++ ) {
		$result = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE bucket_start_utc < %s LIMIT %d',
				$clicks,
				$cutoff,
				OCTK_RETENTION_BATCH
			)
		);
		if ( false === $result ) {
			octk_log( 'retention delete failed: ' . $wpdb->last_error );
			break;
		}
		$deleted += (int) $result;
		if ( $result < OCTK_RETENTION_BATCH ) {
			break;
		}
	}
	return $deleted;
}
add_action( OCTK_RETENTION_HOOK, 'octk_retention_run' );
