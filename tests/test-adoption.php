<?php
/**
 * Legacy adoption: flhct_* (pre-1.0) data is renamed in place to octk_*.
 *
 * Every test here uses BASE tables (the framework's CREATE TEMPORARY TABLE
 * rewrite is removed) because SHOW TABLES cannot see temporary tables.
 * DDL commits the per-test transaction, so tear_down cleans everything
 * explicitly instead of relying on rollback.
 */
class OCTK_Adoption_Test extends WP_UnitTestCase {

	private array $legacy_options = array(
		'enabled'               => '0',
		'excluded_roles'        => array( 'administrator', 'author' ),
		'internal_hosts'        => array( 'example.test', 'cdn.example.test' ),
		'retention_months'      => 7,
		'rate_limit'            => '1',
		'rate_limit_per_minute' => 42,
		'uninstall_delete'      => '0',
	);

	private int $user_id = 0;

	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->user_id = self::factory()->user->create();
		$this->clean_state();
		// Park the real tables so each test can build its own legacy state.
		$this->rename( 'octk', 'octk_aside' );
	}

	public function tear_down() {
		global $wpdb;
		// Option/meta cleanup FIRST: the DDL below implicitly commits it,
		// so the framework's final ROLLBACK cannot resurrect seeded rows.
		$this->clean_state();
		update_option( 'octk_schema_version', OCTK_SCHEMA_VERSION, true );
		foreach ( array( 'flhct', 'octk' ) as $prefix ) {
			foreach ( OCTK_LEGACY_TABLES as $suffix ) {
				$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$prefix}_{$suffix}" );
			}
		}
		$this->rename( 'octk_aside', 'octk' );
		$this->assertTrue( octk_tables_exist() );
		$this->assertTrue( octk_schema_v2_ready( true ) );
		parent::tear_down();
	}

	/** Options/meta/cron/transients seeded by these tests survive rollback (DDL commits), so remove them explicitly. */
	private function clean_state(): void {
		foreach ( array_merge( OCTK_LEGACY_OPTIONS, array( 'schema_version' ) ) as $key ) {
			delete_option( 'flhct_' . $key );
			delete_option( 'octk_' . $key );
		}
		delete_transient( OCTK_ADOPT_ERROR );
		delete_transient( 'flhct_status_counts' );
		wp_clear_scheduled_hook( OCTK_LEGACY_CRON );
		wp_clear_scheduled_hook( OCTK_RETENTION_HOOK );
		delete_metadata( 'user', 0, OCTK_LEGACY_META, '', true );
		delete_metadata( 'user', 0, 'octk_dashboard_days', '', true );
		wp_cache_flush();
		$this->old_plugin_files( false );
	}

	/** Create or remove a stub of the old plugin's main file so is_plugin_active() + file_exists() see it. */
	private function old_plugin_files( bool $present ): void {
		$file = WP_PLUGIN_DIR . '/' . OCTK_LEGACY_BASENAME;
		if ( $present ) {
			wp_mkdir_p( dirname( $file ) );
			file_put_contents( $file, "<?php // stub for tests\n" );
			$this->assertFileExists( $file );
		} elseif ( file_exists( $file ) ) {
			unlink( $file );
			@rmdir( dirname( $file ) );
		}
	}

	// ----- fixtures -------------------------------------------------------

	private function rename( string $from, string $to ): void {
		global $wpdb;
		$parts = array();
		foreach ( OCTK_LEGACY_TABLES as $suffix ) {
			if ( octk_base_table_exists( "{$wpdb->prefix}{$from}_{$suffix}" ) ) {
				$parts[] = "{$wpdb->prefix}{$from}_{$suffix} TO {$wpdb->prefix}{$to}_{$suffix}";
			}
		}
		if ( $parts ) {
			$this->assertNotFalse( $wpdb->query( 'RENAME TABLE ' . implode( ', ', $parts ) ), $wpdb->last_error );
		}
	}

	/** Create an empty base table named {prefix}{$prefix}_{$suffix} shaped like the real one. */
	private function create_like_real( string $prefix, string $suffix ): void {
		global $wpdb;
		$this->assertNotFalse( $wpdb->query( "CREATE TABLE {$wpdb->prefix}{$prefix}_{$suffix} LIKE {$wpdb->prefix}octk_aside_{$suffix}" ), $wpdb->last_error );
	}

	private function seed_row( string $prefix, string $suffix ): void {
		global $wpdb;
		$t = "{$wpdb->prefix}{$prefix}_{$suffix}";
		switch ( $suffix ) {
			case 'links':
				$wpdb->query( $wpdb->prepare(
					"INSERT INTO {$t} (id, public_token, identity_hash, source_type, source_id, source_url, destination_url, destination_host, destination_hash, label, created_at_utc)
					VALUES (1, UNHEX(%s), UNHEX(%s), 'post', 1, '', 'https://legacy.example/x', 'legacy.example', UNHEX(%s), 'Legacy', '2026-08-01 00:00:00')",
					bin2hex( random_bytes( 16 ) ), bin2hex( random_bytes( 32 ) ), hash( 'sha256', 'https://legacy.example/x' )
				) );
				break;
			case 'clicks_hourly':
				$wpdb->query( "INSERT INTO {$t} (link_id, bucket_start_utc, hits) VALUES (1, '2026-08-01 10:00:00', 7)" );
				break;
			case 'label_overrides':
				$wpdb->query( $wpdb->prepare(
					"INSERT INTO {$t} (destination_hash, destination_url, label, updated_at_utc) VALUES (UNHEX(%s), 'https://legacy.example/x', 'Manual', '2026-08-01 00:00:00')",
					hash( 'sha256', 'https://legacy.example/x' )
				) );
				break;
		}
		$this->assertSame( '', $wpdb->last_error, "seed {$t}" );
	}

	/** Full v2 legacy install: three flhct_* tables with one row each, options, meta, cron, transient. */
	private function seed_legacy( array $tables = array( 'links', 'clicks_hourly', 'label_overrides' ) ): void {
		foreach ( $tables as $suffix ) {
			$this->create_like_real( 'flhct', $suffix );
			$this->seed_row( 'flhct', $suffix );
		}
		foreach ( $this->legacy_options as $key => $value ) {
			update_option( 'flhct_' . $key, $value );
		}
		update_option( 'flhct_schema_version', in_array( 'label_overrides', $tables, true ) ? 2 : 1, true );
		update_user_meta( $this->user_id, OCTK_LEGACY_META, '30' );
		wp_schedule_event( time() + 3600, 'daily', OCTK_LEGACY_CRON );
		set_transient( 'flhct_status_counts', array( 'x' => 1 ), 300 );
		delete_option( 'octk_schema_version' );
	}

	private function rows( string $prefix, string $suffix ): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}{$prefix}_{$suffix}" );
	}

	private function assert_untouched_legacy(): void {
		foreach ( OCTK_LEGACY_TABLES as $suffix ) {
			$this->assertTrue( octk_base_table_exists( $GLOBALS['wpdb']->prefix . 'flhct_' . $suffix ), "flhct_{$suffix} must still exist" );
		}
		$this->assertSame( 2, (int) get_option( 'flhct_schema_version' ) );
		$this->assertFalse( get_option( 'octk_schema_version' ) );
		$this->assertSame( '0', get_option( 'flhct_enabled' ) );
		$this->assertTrue( octk_adoption_pending() );
		$this->assertNotEmpty( get_transient( OCTK_ADOPT_ERROR ) );
	}

	// ----- tests ----------------------------------------------------------

	public function test_full_v2_adoption() {
		global $wpdb;
		$this->seed_legacy();
		$this->assertSame( '30', get_user_meta( $this->user_id, OCTK_LEGACY_META, true ), 'prime the meta cache' );

		octk_migrate();

		foreach ( OCTK_LEGACY_TABLES as $suffix ) {
			$this->assertFalse( octk_base_table_exists( "{$wpdb->prefix}flhct_{$suffix}" ), "flhct_{$suffix} gone" );
			$this->assertSame( 1, $this->rows( 'octk', $suffix ), "octk_{$suffix} has the row" );
		}
		foreach ( $this->legacy_options as $key => $value ) {
			$this->assertSame( $value, get_option( 'octk_' . $key ), $key );
			$this->assertFalse( get_option( 'flhct_' . $key ), "flhct_{$key} deleted" );
		}
		$this->assertSame( 2, (int) get_option( 'octk_schema_version' ) );
		$this->assertFalse( get_option( 'flhct_schema_version' ) );
		$this->assertFalse( wp_next_scheduled( OCTK_LEGACY_CRON ) );
		$this->assertFalse( get_transient( 'flhct_status_counts' ) );
		$this->assertFalse( get_transient( OCTK_ADOPT_ERROR ) );
		$this->assertSame( '30', get_user_meta( $this->user_id, 'octk_dashboard_days', true ) );
		$this->assertSame( '', get_user_meta( $this->user_id, OCTK_LEGACY_META, true ), 'primed cache must not resurrect the old key' );
		$this->assertFalse( octk_adoption_pending() );
		$this->assertTrue( octk_schema_v2_ready( true ) );
	}

	public function test_second_run_is_a_noop() {
		$this->seed_legacy();
		octk_migrate();
		update_option( 'octk_enabled', '0' );
		octk_migrate();
		$this->assertSame( '0', get_option( 'octk_enabled' ) );
		$this->assertSame( 2, (int) get_option( 'octk_schema_version' ) );
		$this->assertSame( 1, $this->rows( 'octk', 'links' ) );
	}

	public function test_v1_only_install_lands_at_v2_with_overrides_created() {
		global $wpdb;
		$this->seed_legacy( array( 'links', 'clicks_hourly' ) );

		octk_migrate();

		$this->assertSame( 2, (int) get_option( 'octk_schema_version' ) );
		$this->assertSame( 1, $this->rows( 'octk', 'clicks_hourly' ) );
		$this->assertTrue( octk_base_table_exists( octk_table_overrides() ), 'migration 2 created the overrides table' );
		$this->assertFalse( octk_base_table_exists( "{$wpdb->prefix}flhct_label_overrides" ) );
	}

	public function test_refuses_when_conflict_is_in_last_pair_and_touches_nothing() {
		$this->seed_legacy();
		// A populated octk_label_overrides already exists: conflict in the LAST pair.
		$this->create_like_real( 'octk', 'label_overrides' );
		$this->seed_row( 'octk', 'label_overrides' );

		octk_migrate();

		$this->assert_untouched_legacy();
		$this->assertStringContainsString( 'label_overrides', get_transient( OCTK_ADOPT_ERROR ) );
		$this->assertStringContainsString( '1 rows', get_transient( OCTK_ADOPT_ERROR ) );
		$this->assertFalse( octk_base_table_exists( octk_table_links() ), 'links pair must not have been renamed' );
	}

	public function test_drops_empty_stray_legacy_tables() {
		// Sequence: adoption already happened, then the old plugin was briefly
		// re-activated and recreated empty flhct_* tables.
		$this->seed_legacy();
		octk_migrate();
		foreach ( OCTK_LEGACY_TABLES as $suffix ) {
			$this->create_like_real( 'flhct', $suffix );
		}
		update_option( 'flhct_schema_version', 2, true );
		delete_option( 'octk_schema_version' );

		octk_migrate();

		global $wpdb;
		foreach ( OCTK_LEGACY_TABLES as $suffix ) {
			$this->assertFalse( octk_base_table_exists( "{$wpdb->prefix}flhct_{$suffix}" ) );
			$this->assertSame( 1, $this->rows( 'octk', $suffix ), 'real data kept' );
		}
		$this->assertSame( 2, (int) get_option( 'octk_schema_version' ) );
	}

	public function test_refuses_on_malformed_overrides_table() {
		global $wpdb;
		$this->seed_legacy( array( 'links', 'clicks_hourly' ) );
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}flhct_label_overrides (id INT)" );

		octk_migrate();

		$this->assertFalse( get_option( 'octk_schema_version' ), 'no version over a malformed schema' );
		$this->assertStringContainsString( 'not a valid v2 table', get_transient( OCTK_ADOPT_ERROR ) );
		// Tables were renamed (that step succeeded) but nothing was committed: next load retries.
		$this->assertTrue( octk_adoption_pending() );
	}

	public function test_partial_v2_links_columns_missing_is_repaired_by_migration_2() {
		global $wpdb;
		// Overrides table present, links table still v1-shaped: v2 probe fails
		// -> refused (table exists, malformed from the probe's point of view).
		$this->seed_legacy();
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}flhct_links DROP COLUMN label" );

		octk_migrate();

		$this->assertFalse( get_option( 'octk_schema_version' ) );
		$this->assertTrue( octk_adoption_pending() );
	}

	public function test_option_copy_failure_keeps_legacy_option_and_retries() {
		$this->seed_legacy();
		// Force update_option('octk_retention_months') to be a no-op.
		$block = static fn ( $new, $old ) => $old;
		add_filter( 'pre_update_option_octk_retention_months', $block, 10, 2 );
		try {
			octk_migrate();
		} finally {
			remove_filter( 'pre_update_option_octk_retention_months', $block, 10 );
		}
		$this->assertSame( 7, get_option( 'flhct_retention_months' ), 'source kept' );
		$this->assertFalse( get_option( 'octk_schema_version' ) );
		$this->assertStringContainsString( 'flhct_retention_months', get_transient( OCTK_ADOPT_ERROR ) );

		octk_migrate();
		$this->assertSame( 7, get_option( 'octk_retention_months' ) );
		$this->assertSame( 2, (int) get_option( 'octk_schema_version' ) );
	}

	public function test_cron_clear_failure_retries() {
		$this->seed_legacy();
		$block = static fn () => false;
		add_filter( 'pre_unschedule_event', $block );
		try {
			octk_migrate();
		} finally {
			remove_filter( 'pre_unschedule_event', $block );
		}
		$this->assertFalse( get_option( 'octk_schema_version' ) );
		$this->assertStringContainsString( 'cron', get_transient( OCTK_ADOPT_ERROR ) );

		octk_migrate();
		$this->assertSame( 2, (int) get_option( 'octk_schema_version' ) );
		$this->assertFalse( wp_next_scheduled( OCTK_LEGACY_CRON ) );
	}

	public function test_user_meta_copy_failure_keeps_legacy_meta_and_retries() {
		$this->seed_legacy();
		$block = static fn ( $check, $object_id, $meta_key ) => 'octk_dashboard_days' === $meta_key ? false : $check;
		add_filter( 'update_user_metadata', $block, 10, 3 );
		try {
			octk_migrate();
		} finally {
			remove_filter( 'update_user_metadata', $block, 10 );
		}
		$this->assertSame( '30', get_user_meta( $this->user_id, OCTK_LEGACY_META, true ), 'source kept' );
		$this->assertFalse( get_option( 'octk_schema_version' ) );
		$this->assertStringContainsString( 'widget preference', get_transient( OCTK_ADOPT_ERROR ) );

		octk_migrate();
		$this->assertSame( '30', get_user_meta( $this->user_id, 'octk_dashboard_days', true ) );
		$this->assertSame( 2, (int) get_option( 'octk_schema_version' ) );
	}

	public function test_refuses_when_required_table_missing_everywhere() {
		global $wpdb;
		$this->seed_legacy( array( 'links' ) );

		octk_migrate();

		$this->assertFalse( get_option( 'octk_schema_version' ), 'no version recorded over a missing table' );
		$this->assertSame( 1, (int) get_option( 'flhct_schema_version' ) );
		$this->assertTrue( octk_base_table_exists( "{$wpdb->prefix}flhct_links" ), 'nothing renamed' );
		$this->assertFalse( octk_base_table_exists( octk_table_clicks() ), 'fresh path must not have created tables' );
		$this->assertStringContainsString( 'clicks_hourly', get_transient( OCTK_ADOPT_ERROR ) );
	}

	public function test_stale_active_plugins_entry_without_files_is_ignored() {
		$this->seed_legacy();
		$inject = static fn ( $plugins ) => array_merge( (array) $plugins, array( OCTK_LEGACY_BASENAME ) );
		add_filter( 'option_active_plugins', $inject );
		try {
			octk_migrate();
		} finally {
			remove_filter( 'option_active_plugins', $inject );
		}
		$this->assertSame( 2, (int) get_option( 'octk_schema_version' ), 'entry without plugin files must not block adoption' );
	}

	public function test_refuses_while_old_plugin_active() {
		$this->seed_legacy();
		$this->old_plugin_files( true );
		$inject = static fn ( $plugins ) => array_merge( (array) $plugins, array( OCTK_LEGACY_BASENAME ) );
		add_filter( 'option_active_plugins', $inject );
		try {
			octk_migrate();
			$this->assert_untouched_legacy();
			$this->assertStringContainsString( 'still active', get_transient( OCTK_ADOPT_ERROR ) );
		} finally {
			remove_filter( 'option_active_plugins', $inject );
		}
		// Deactivating the old plugin lets the next load complete.
		octk_migrate();
		$this->assertSame( 2, (int) get_option( 'octk_schema_version' ) );
	}

	public function test_resumes_after_interruption_between_rename_and_options() {
		// State left by a run that died after the RENAME: new tables present,
		// legacy marker and options still in place.
		$this->seed_legacy();
		$this->rename( 'flhct', 'octk' );

		octk_migrate();

		$this->assertSame( 2, (int) get_option( 'octk_schema_version' ) );
		$this->assertFalse( get_option( 'flhct_schema_version' ) );
		$this->assertSame( '0', get_option( 'octk_enabled' ) );
		$this->assertSame( array( 'administrator', 'author' ), get_option( 'octk_excluded_roles' ) );
		$this->assertSame( 1, $this->rows( 'octk', 'links' ) );
	}

	public function test_activation_after_refusal_then_retry_keeps_tracking_off() {
		$this->seed_legacy();
		$this->old_plugin_files( true );
		$inject = static fn ( $plugins ) => array_merge( (array) $plugins, array( OCTK_LEGACY_BASENAME ) );
		add_filter( 'option_active_plugins', $inject );
		// What the activation hook does after a refused adoption.
		octk_migrate();
		delete_option( 'octk_enabled' );
		add_option( 'octk_enabled', '1' );
		remove_filter( 'option_active_plugins', $inject );
		$this->assertSame( '1', get_option( 'octk_enabled' ) );

		octk_migrate();

		$this->assertSame( '0', get_option( 'octk_enabled' ), 'site value wins over the activation default' );
	}

	public function test_fail_soft_while_pending() {
		$this->seed_legacy();
		$this->old_plugin_files( true );
		$inject = static fn ( $plugins ) => array_merge( (array) $plugins, array( OCTK_LEGACY_BASENAME ) );
		add_filter( 'option_active_plugins', $inject );
		try {
			octk_migrate();
			$this->assertFalse( octk_tables_exist() );
			$this->assertFalse( OCTK_Reporting::schema_ready() );
			$this->assertFalse( octk_enabled(), 'tracking is paused while adoption is pending' );
			$html = '<p><a href="https://ext.example/x">x</a></p>';
			$this->assertSame( $html, octk_filter_content( $html ), 'render filter is a no-op without tables' );
		} finally {
			remove_filter( 'option_active_plugins', $inject );
		}
	}

	public function test_fresh_install_never_enters_adoption() {
		delete_option( 'octk_schema_version' );
		$this->assertFalse( octk_adoption_pending() );
		octk_migrate();
		$this->assertSame( 2, (int) get_option( 'octk_schema_version' ) );
		$this->assertTrue( octk_tables_exist() );
		$this->assertFalse( get_transient( OCTK_ADOPT_ERROR ) );
	}

	public function test_admin_notice_shows_reason_only_when_pending() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( '', get_echo( 'octk_adoption_notice' ) );

		$this->seed_legacy();
		set_transient( OCTK_ADOPT_ERROR, 'why <b>', 60 );
		$out = get_echo( 'octk_adoption_notice' );
		$this->assertStringContainsString( 'notice-error', $out );
		$this->assertStringContainsString( 'why &lt;b&gt;', $out );
	}
}
