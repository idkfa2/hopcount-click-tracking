<?php

class OCTK_Schema_Test extends WP_UnitTestCase {

	public function tear_down() {
		global $wpdb;
		// Tests that run migrations get their CREATE TABLE rewritten to
		// CREATE TEMPORARY TABLE by the WP test framework, leaving temp
		// shadow tables on the connection. Drop them (base tables untouched).
		$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . octk_table_links() . ', ' . octk_table_clicks() . ', ' . octk_table_overrides() );
		// Every test must leave the real schema installed for the rest of the suite.
		update_option( 'octk_schema_version', OCTK_SCHEMA_VERSION, true );
		$this->assertTrue( octk_tables_exist() );
		$this->assertTrue( octk_schema_v2_ready( true ) );
		parent::tear_down();
	}

	public function test_migration_1_reports_success_when_tables_exist() {
		$this->assertTrue( octk_migration_1() );
	}

	public function test_tables_exist_requires_both_tables() {
		global $wpdb;
		$clicks = octk_table_clicks();

		$this->assertTrue( octk_tables_exist() );
		$this->assertTrue( OCTK_Reporting::schema_ready() );

		$renamed = $wpdb->query( "RENAME TABLE {$clicks} TO {$clicks}_hidden" );
		$this->assertNotFalse( $renamed, 'RENAME failed: ' . $wpdb->last_error );
		try {
			$this->assertFalse( octk_tables_exist() );
			$this->assertFalse( OCTK_Reporting::schema_ready() );
		} finally {
			$wpdb->query( "RENAME TABLE {$clicks}_hidden TO {$clicks}" );
		}

		$this->assertTrue( octk_tables_exist() );
	}

	public function test_failed_migration_does_not_record_schema_version() {
		update_option( 'octk_schema_version', 0, true );

		$failing = static function (): array {
			return array( 1 => static fn (): bool => false );
		};
		add_filter( 'octk_migrations', $failing );
		try {
			octk_migrate();
		} finally {
			remove_filter( 'octk_migrations', $failing );
		}

		$this->assertSame( 0, (int) get_option( 'octk_schema_version', 0 ) );

		// Without the failure injected, the same pending migration completes.
		octk_migrate();
		$this->assertSame( OCTK_SCHEMA_VERSION, (int) get_option( 'octk_schema_version', 0 ) );
	}

	public function test_schema_v2_ready_detects_all_objects() {
		$this->assertTrue( octk_schema_v2_ready( true ) );
		$this->assertTrue( OCTK_Reporting::schema_ready() );
	}

	public function test_partial_migration_2_surfaces_not_ready_then_resumes() {
		global $wpdb;
		$overrides = octk_table_overrides();

		// Simulate a partial migration 2: columns exist, override table gone.
		$renamed = $wpdb->query( "RENAME TABLE {$overrides} TO {$overrides}_hidden" );
		$this->assertNotFalse( $renamed, 'RENAME failed: ' . $wpdb->last_error );
		try {
			$this->assertFalse( octk_schema_v2_ready( true ) );
			$this->assertFalse( OCTK_Reporting::schema_ready(), 'runtime must surface the error state' );

			// Resuming the migration recreates only the missing object
			// (a TEMPORARY shadow here, courtesy of the test framework).
			$this->assertTrue( octk_migration_2() );
			$this->assertTrue( octk_schema_v2_ready( true ) );
		} finally {
			$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$overrides}" );
			$wpdb->query( "RENAME TABLE {$overrides}_hidden TO {$overrides}" );
		}

		$this->assertTrue( octk_schema_v2_ready( true ) );
	}

	public function test_migration_2_backfills_destination_hash() {
		global $wpdb;
		$links = octk_table_links();

		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$links} (public_token, identity_hash, source_type, source_id, source_url, destination_url, destination_host, destination_hash, label, created_at_utc)
			VALUES (UNHEX(%s), UNHEX(%s), 'post', 999999, '', %s, 'pre-v2.example', NULL, NULL, %s)",
			bin2hex( random_bytes( 16 ) ),
			bin2hex( random_bytes( 32 ) ),
			'https://pre-v2.example/legacy',
			'2026-08-01 00:00:00'
		) );

		try {
			$this->assertTrue( octk_migration_2() );
			$hash = $wpdb->get_var( $wpdb->prepare(
				"SELECT HEX(destination_hash) FROM {$links} WHERE source_id = 999999 AND BINARY destination_url = BINARY %s",
				'https://pre-v2.example/legacy'
			) );
			$this->assertSame( hash( 'sha256', 'https://pre-v2.example/legacy' ), strtolower( (string) $hash ) );
		} finally {
			$wpdb->query( "DELETE FROM {$links} WHERE source_id = 999999" );
		}
	}

	public function test_report_request_defaults_emit_no_warnings() {
		$previous_get = $_GET;
		$_GET         = array();
		set_error_handler(
			static function ( int $errno, string $errstr ): bool {
				throw new ErrorException( $errstr, $errno );
			},
			E_WARNING | E_NOTICE | E_DEPRECATED
		);
		try {
			$request = octk_report_request();
		} finally {
			restore_error_handler();
			$_GET = $previous_get;
		}

		$this->assertSame( 25, $request['args']['per_page'] );
		$this->assertSame( 1, $request['args']['page'] );
	}

	public function test_report_request_accepts_valid_per_page() {
		$previous_get     = $_GET;
		$_GET             = array( 'per_page' => '50' );
		try {
			$request = octk_report_request();
		} finally {
			$_GET = $previous_get;
		}

		$this->assertSame( 50, $request['args']['per_page'] );
	}
}
