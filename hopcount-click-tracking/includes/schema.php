<?php
/**
 * Versioned schema migrations. Explicit SQL, not dbDelta (scope §7, decision 38).
 * Every v1 column NOT NULL; InnoDB; no foreign keys (referential cleanup is
 * application-managed). v2 adds nullable label columns (NULL = not captured).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const OCTK_SCHEMA_VERSION = 3;

function octk_table_links(): string {
	global $wpdb;
	return $wpdb->prefix . 'octk_links';
}

function octk_table_clicks(): string {
	global $wpdb;
	return $wpdb->prefix . 'octk_clicks_hourly';
}

function octk_table_overrides(): string {
	global $wpdb;
	return $wpdb->prefix . 'octk_label_overrides';
}

/**
 * Whether both v1 tables exist. Deliberately UNCHANGED by v2: migration 1
 * uses this as its own success check, so requiring v2 objects here would
 * fail migration 1 on fresh installs before migration 2 ever ran.
 */
function octk_tables_exist(): bool {
	global $wpdb;
	foreach ( array( octk_table_links(), octk_table_clicks() ) as $table ) {
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return false;
		}
	}
	return true;
}

/**
 * Whether every v2 object exists: both new octk_links columns, the
 * destination_hash index, and the override table. Statically cached per
 * request (called from runtime paths, not just migrations).
 */
function octk_schema_v2_ready( bool $recheck = false ): bool {
	static $ready = null;
	if ( null !== $ready && ! $recheck ) {
		return $ready;
	}

	global $wpdb;
	$links     = octk_table_links();
	$overrides = octk_table_overrides();

	$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $links ), 0 );
	$indexes = $wpdb->get_col( $wpdb->prepare( 'SHOW INDEX FROM %i', $links ), 2 );

	// SELECT probe, not SHOW TABLES: also validates the columns, and remains
	// visible for TEMPORARY shadow tables (the WP test framework rewrites
	// CREATE TABLE to CREATE TEMPORARY TABLE).
	$suppress = $wpdb->suppress_errors( true );
	$probe    = $wpdb->query( $wpdb->prepare( 'SELECT destination_hash, destination_url, label, updated_at_utc FROM %i LIMIT 1', $overrides ) );
	$wpdb->suppress_errors( $suppress );

	$ready = is_array( $columns ) && in_array( 'label', $columns, true )
		&& in_array( 'destination_hash', $columns, true )
		&& is_array( $indexes ) && in_array( 'destination_hash', $indexes, true )
		&& false !== $probe;

	return $ready;
}

/**
 * Legacy names from the pre-1.0 plugin. The ONLY place
 * the old prefix appears in shipped code: octk_adopt_legacy() renames these
 * in place on the first load after the rename so existing data survives.
 */
const OCTK_LEGACY_BASENAME = 'flh-click-tracking/flh-click-tracking.php';
const OCTK_LEGACY_TABLES   = array( 'links', 'clicks_hourly', 'label_overrides' );
const OCTK_LEGACY_OPTIONS  = array( 'enabled', 'excluded_roles', 'internal_hosts', 'retention_months', 'rate_limit', 'rate_limit_per_minute', 'uninstall_delete' );
const OCTK_LEGACY_CRON     = 'flhct_retention_cleanup';
const OCTK_LEGACY_META     = 'flhct_dashboard_days';
const OCTK_ADOPT_ERROR     = 'octk_adopt_error';

/**
 * Whether a legacy install is waiting to be adopted (drives the admin notice).
 */
function octk_adoption_pending(): bool {
	return false === get_option( 'octk_schema_version', false )
		&& false !== get_option( 'flhct_schema_version', false );
}

function octk_adopt_fail( string $reason ): bool {
	octk_log( 'legacy adoption refused: ' . $reason );
	set_transient( OCTK_ADOPT_ERROR, $reason, DAY_IN_SECONDS );
	return false;
}

function octk_base_table_exists( string $table ): bool {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
}

/**
 * Adopt data from the legacy flhct_* names in place. Every step is guarded;
 * any failure returns false and is retried on the next load because the
 * legacy version marker is only removed at the very end. Order matters:
 *   1. refuse while the old plugin is still active (it would recreate its
 *      tables on every load);
 *   2. preflight all table pairs with no mutation, so a conflict in the last
 *      pair leaves the first pairs untouched;
 *   3. one multi-table RENAME (atomic);
 *   4. options, cron, transients, user meta;
 *   5. delete the legacy marker, then write octk_schema_version LAST.
 */
function octk_adopt_legacy(): bool {
	global $wpdb;

	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	// A stale active_plugins entry (folder deleted by hand) is not "active":
	// WordPress only prunes it when the Plugins screen is next visited.
	if ( is_plugin_active( OCTK_LEGACY_BASENAME ) && file_exists( WP_PLUGIN_DIR . '/' . OCTK_LEGACY_BASENAME ) ) {
		return octk_adopt_fail( 'the pre-1.0 plugin is still active; deactivate it first' );
	}

	// Preflight.
	$renames = array();
	$drops   = array();
	foreach ( OCTK_LEGACY_TABLES as $suffix ) {
		$old        = $wpdb->prefix . 'flhct_' . $suffix;
		$new        = $wpdb->prefix . 'octk_' . $suffix;
		$old_exists = octk_base_table_exists( $old );
		$new_exists = octk_base_table_exists( $new );

		if ( $old_exists && ! $new_exists ) {
			$renames[] = array( $old, $new );
		} elseif ( $old_exists && $new_exists ) {
			$old_rows = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $old ) );
			if ( $old_rows > 0 ) {
				$new_rows = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $new ) );
				return octk_adopt_fail( sprintf( 'both %s (%d rows) and %s (%d rows) exist; nothing was changed', $old, $old_rows, $new, $new_rows ) );
			}
			$drops[] = $old;
		} elseif ( ! $old_exists && ! $new_exists && 'label_overrides' !== $suffix ) {
			return octk_adopt_fail( sprintf( 'required table %s is missing', $old ) );
		}
	}

	// Mutate.
	foreach ( $drops as $table ) {
		if ( false === $wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $table ) ) ) {
			return octk_adopt_fail( "could not drop empty stray table {$table}: " . $wpdb->last_error );
		}
	}
	if ( $renames ) {
		// One "%i TO %i" pair per table; every identifier goes through prepare().
		$pairs  = implode( ', ', array_fill( 0, count( $renames ), '%i TO %i' ) );
		$idents = array_merge( ...$renames );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $pairs is a placeholder list built above; all identifiers are prepared.
		if ( false === $wpdb->query( $wpdb->prepare( "RENAME TABLE {$pairs}", $idents ) ) ) {
			return octk_adopt_fail( 'rename failed: ' . $wpdb->last_error );
		}
	}
	if ( ! octk_tables_exist() ) {
		return octk_adopt_fail( 'tables missing after rename' );
	}

	// Options. update_option, not add_option: the activation hook adds
	// octk_enabled=1 right after migrate, so a failed-then-retried adoption
	// must be able to overwrite it with the site's real value. update_option
	// returns false both on failure and on "unchanged", so verify by reading
	// back before the legacy key is deleted: a setting is never lost.
	foreach ( OCTK_LEGACY_OPTIONS as $key ) {
		$value = get_option( 'flhct_' . $key, false );
		if ( false === $value ) {
			continue;
		}
		update_option( 'octk_' . $key, $value );
		if ( get_option( 'octk_' . $key, false ) !== $value ) {
			return octk_adopt_fail( "could not copy option flhct_{$key}" );
		}
		delete_option( 'flhct_' . $key );
	}

	if ( false === wp_clear_scheduled_hook( OCTK_LEGACY_CRON ) ) {
		return octk_adopt_fail( 'could not clear the legacy retention cron' );
	}
	delete_transient( 'flhct_status_counts' );
	foreach ( array( 'today', 'yesterday', '7', '30', '90' ) as $period ) {
		delete_transient( 'flhct_dashboard_widget_' . $period );
	}

	// User meta via the API so persistent object caches are invalidated;
	// verified the same way before the legacy key goes.
	foreach ( get_users( array( 'meta_key' => OCTK_LEGACY_META, 'fields' => 'ID' ) ) as $user_id ) {
		$days = get_user_meta( $user_id, OCTK_LEGACY_META, true );
		update_user_meta( $user_id, 'octk_dashboard_days', $days );
		if ( get_user_meta( $user_id, 'octk_dashboard_days', true ) !== $days ) {
			return octk_adopt_fail( "could not copy the widget preference for user {$user_id}" );
		}
		delete_user_meta( $user_id, OCTK_LEGACY_META );
	}

	// Schema version: 2 only when every v2 object is verified present. With
	// no overrides table at all, record 1 and let migration 2 build v2. An
	// overrides table that exists but fails the probe is malformed: refuse,
	// because migration 2 would not repair it and could not be re-entered.
	if ( octk_schema_v2_ready( true ) ) {
		$version = 2;
	} elseif ( ! octk_base_table_exists( octk_table_overrides() ) ) {
		$version = 1;
	} else {
		return octk_adopt_fail( octk_table_overrides() . ' exists but is not a valid v2 table; repair or drop it' );
	}

	// Commit: nothing guarded happens after the version write. If we die
	// between the delete and the write, both markers are absent and the
	// fresh path (CREATE TABLE IF NOT EXISTS) rediscovers the renamed tables.
	delete_transient( OCTK_ADOPT_ERROR );
	delete_option( 'flhct_schema_version' );
	update_option( 'octk_schema_version', $version, true );
	return true;
}

/**
 * Run pending migrations. Idempotent; safe to call on activation and upgrade.
 * A version is recorded only after its migration reports success, so a failed
 * migration is retried on the next load instead of being skipped forever.
 */
function octk_migrate(): void {
	if ( octk_adoption_pending() && ! octk_adopt_legacy() ) {
		return; // Never let the fresh path create empty tables over legacy data.
	}

	$current = (int) get_option( 'octk_schema_version', 0 );
	if ( $current >= OCTK_SCHEMA_VERSION ) {
		return;
	}

	$migrations = array(
		1 => 'octk_migration_1',
		2 => 'octk_migration_2',
		3 => 'octk_migration_3',
	);
	// Test seam: lets fixtures exercise the failure path without mocking $wpdb.
	$migrations = apply_filters( 'octk_migrations', $migrations );

	foreach ( $migrations as $version => $callback ) {
		if ( $version <= $current ) {
			continue;
		}
		if ( true !== call_user_func( $callback ) ) {
			octk_log( sprintf( 'schema migration %d failed; version stays at %d and will retry', $version, $current ) );
			return;
		}
		update_option( 'octk_schema_version', $version, true );
		$current = $version;
	}
}

function octk_migration_1(): bool {
	global $wpdb;

	$charset_collate = $wpdb->get_charset_collate();
	$links           = octk_table_links();
	$clicks          = octk_table_clicks();

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $charset_collate is wpdb::get_charset_collate(); the table name is prepared.
	$links_ok = false !== $wpdb->query( $wpdb->prepare(
		"CREATE TABLE IF NOT EXISTS %i (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_token BINARY(16) NOT NULL,
			identity_hash BINARY(32) NOT NULL,
			source_type VARCHAR(20) NOT NULL,
			source_id BIGINT UNSIGNED NOT NULL,
			source_url VARCHAR(2048) NOT NULL,
			destination_url VARCHAR(2048) NOT NULL,
			destination_host VARCHAR(255) NOT NULL,
			created_at_utc DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY public_token (public_token),
			UNIQUE KEY identity_hash (identity_hash),
			KEY source (source_type, source_id),
			KEY destination_host (destination_host)
		) ENGINE=InnoDB {$charset_collate}",
		$links
	) );

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $charset_collate is wpdb::get_charset_collate(); the table name is prepared.
	$clicks_ok = false !== $wpdb->query( $wpdb->prepare(
		"CREATE TABLE IF NOT EXISTS %i (
			link_id BIGINT UNSIGNED NOT NULL,
			bucket_start_utc DATETIME NOT NULL,
			hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY (link_id, bucket_start_utc),
			KEY bucket_start_utc (bucket_start_utc)
		) ENGINE=InnoDB {$charset_collate}",
		$clicks
	) );

	return $links_ok && $clicks_ok && octk_tables_exist();
}

/**
 * v2: label capture + per-destination manual overrides. Tolerates partial
 * prior execution - every object is checked individually before being added.
 */
function octk_migration_2(): bool {
	global $wpdb;

	$charset_collate = $wpdb->get_charset_collate();
	$links           = octk_table_links();
	$overrides       = octk_table_overrides();

	$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $links ), 0 );
	if ( ! is_array( $columns ) ) {
		return false;
	}

	if ( ! in_array( 'label', $columns, true )
		&& false === $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN label VARCHAR(200) NULL DEFAULT NULL AFTER destination_host', $links ) ) ) {
		octk_log( 'migration 2: adding label column failed: ' . $wpdb->last_error );
		return false;
	}

	if ( ! in_array( 'destination_hash', $columns, true )
		&& false === $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN destination_hash BINARY(32) NULL DEFAULT NULL AFTER destination_host', $links ) ) ) {
		octk_log( 'migration 2: adding destination_hash column failed: ' . $wpdb->last_error );
		return false;
	}

	$indexes = $wpdb->get_col( $wpdb->prepare( 'SHOW INDEX FROM %i', $links ), 2 );
	if ( is_array( $indexes ) && ! in_array( 'destination_hash', $indexes, true )
		&& false === $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD KEY destination_hash (destination_hash)', $links ) ) ) {
		octk_log( 'migration 2: adding destination_hash index failed: ' . $wpdb->last_error );
		return false;
	}

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $charset_collate is wpdb::get_charset_collate(); the table name is prepared.
	if ( false === $wpdb->query( $wpdb->prepare(
		"CREATE TABLE IF NOT EXISTS %i (
			destination_hash BINARY(32) NOT NULL,
			destination_url VARCHAR(2048) NOT NULL,
			label VARCHAR(200) NOT NULL,
			updated_at_utc DATETIME NOT NULL,
			PRIMARY KEY (destination_hash)
		) ENGINE=InnoDB {$charset_collate}",
		$overrides
	) ) ) {
		octk_log( 'migration 2: creating override table failed: ' . $wpdb->last_error );
		return false;
	}

	// Backfill destination_hash in bounded batches. SHA2 over the stored
	// utf8mb4 bytes equals PHP hash('sha256', $url) over the same UTF-8 string.
	for ( $i = 0; $i < 100; $i++ ) {
		$affected = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET destination_hash = UNHEX(SHA2(destination_url, 256)) WHERE destination_hash IS NULL LIMIT 500', $links ) );
		if ( false === $affected ) {
			octk_log( 'migration 2: destination_hash backfill failed: ' . $wpdb->last_error );
			return false;
		}
		if ( 0 === $affected ) {
			break;
		}
	}

	return octk_schema_v2_ready( true );
}
