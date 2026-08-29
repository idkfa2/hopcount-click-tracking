<?php

class OCTK_Export_Test extends WP_UnitTestCase {

	private $post_a;
	private $post_b;

	public function set_up() {
		parent::set_up();
		OCTK_Registry::reset_memo();
		delete_transient( 'octk_status_counts' );
		$this->post_a = self::factory()->post->create( array( 'post_title' => 'Widget deep dive' ) );
		$this->post_b = self::factory()->post->create( array( 'post_title' => 'Magnesium guide' ) );
	}

	private function seed( int $post_id, string $url, array $buckets ): void {
		global $wpdb;
		$dest   = octk_normalize_destination( $url );
		$tokens = OCTK_Registry::tokens_for( 'post', $post_id, array( $dest ) );
		$token  = array_values( $tokens )[0];

		$links   = octk_table_links();
		$clicks  = octk_table_clicks();
		$link_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$links} WHERE public_token = UNHEX(%s)", $token ) );
		foreach ( $buckets as $bucket => $hits ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$clicks} (link_id, bucket_start_utc, hits) VALUES (%d, %s, %d)", $link_id, $bucket, $hits ) );
		}
	}

	private function args( array $overrides = array() ): array {
		return array_merge(
			array(
				'start_utc' => '2026-08-01 00:00:00',
				'end_utc'   => '2026-08-08 00:00:00',
				'orderby'   => 'clicks',
				'order'     => 'desc',
			),
			$overrides
		);
	}

	private function stream( array $args ): string {
		ob_start();
		octk_export_csv_stream( $args );
		return ob_get_clean();
	}

	public function test_export_rows_ignores_pagination_and_respects_range() {
		$this->seed( $this->post_a, 'https://m1.example/a', array( '2026-08-02 10:00:00' => 5 ) );
		$this->seed( $this->post_a, 'https://m2.example/b', array( '2026-08-03 10:00:00' => 2 ) );
		$this->seed( $this->post_b, 'https://m3.example/c', array( '2026-08-08 00:00:00' => 9 ) ); // outside half-open range

		$rows = OCTK_Reporting::export_rows( $this->args(), 1000, 0 );

		$this->assertCount( 2, $rows );
		$this->assertSame( 5, (int) $rows[0]->clicks ); // clicks DESC preserved
	}

	public function test_export_rows_batches_are_stable_and_disjoint() {
		$this->seed( $this->post_a, 'https://m1.example/a', array( '2026-08-02 10:00:00' => 3 ) );
		$this->seed( $this->post_a, 'https://m2.example/b', array( '2026-08-02 10:00:00' => 3 ) );
		$this->seed( $this->post_b, 'https://m3.example/c', array( '2026-08-02 10:00:00' => 3 ) );

		$first  = OCTK_Reporting::export_rows( $this->args(), 2, 0 );
		$second = OCTK_Reporting::export_rows( $this->args(), 2, 2 );

		$this->assertCount( 2, $first );
		$this->assertCount( 1, $second );
		$hosts = array_map( static fn( $r ) => $r->destination_host, array_merge( $first, $second ) );
		$this->assertSame( array( 'm1.example', 'm2.example', 'm3.example' ), $hosts ); // equal clicks: l.id ASC tiebreak
	}

	public function test_csv_stream_header_rows_and_timezone_column() {
		update_option( 'timezone_string', 'Europe/Berlin' ); // UTC+2 in August
		$this->seed( $this->post_a, 'https://m1.example/a?ref=x', array( '2026-08-02 10:00:00' => 5 ) );

		$csv = $this->stream( $this->args() );

		$this->assertStringStartsWith( "\xEF\xBB\xBF", $csv );
		$lines = explode( "\n", trim( substr( $csv, 3 ) ) );
		$this->assertCount( 2, $lines );
		// label is appended LAST so existing consumers' column positions never shift.
		$this->assertSame(
			array( 'source_post_id', 'source_title', 'source_url', 'destination_host', 'destination_url', 'clicks', 'last_activity_utc', 'last_activity_site_tz', 'label' ),
			str_getcsv( $lines[0], ',', '"', '' )
		);

		$row = str_getcsv( $lines[1], ',', '"', '' );
		$this->assertSame( (string) $this->post_a, $row[0] );
		$this->assertSame( 'Widget deep dive', $row[1] );
		$this->assertSame( 'm1.example', $row[3] );
		$this->assertSame( 'https://m1.example/a?ref=x', $row[4] );
		$this->assertSame( '5', $row[5] );
		$this->assertSame( '2026-08-02 10:00:00', $row[6] );
		$this->assertSame( '2026-08-02 12:00:00', $row[7] );
	}

	public function test_csv_formula_injection_guard() {
		$evil = self::factory()->post->create( array( 'post_title' => '=HYPERLINK("https://evil.example","x")' ) );
		$this->seed( $evil, 'https://m1.example/a', array( '2026-08-02 10:00:00' => 1 ) );

		$csv  = $this->stream( $this->args() );
		$rows = explode( "\n", trim( substr( $csv, 3 ) ) );
		$cell = str_getcsv( $rows[1], ',', '"', '' )[1];

		$this->assertStringStartsWith( "'=", $cell );

		$this->assertSame( "'+1", octk_csv_cell( '+1' ) );
		$this->assertSame( "'-2", octk_csv_cell( '-2' ) );
		$this->assertSame( "'@x", octk_csv_cell( '@x' ) );
		$this->assertSame( 'https://ok.example', octk_csv_cell( 'https://ok.example' ) );
		$this->assertSame( '', octk_csv_cell( '' ) );
	}

	public function test_csv_deleted_post_falls_back_to_stored_metadata() {
		$this->seed( $this->post_a, 'https://m1.example/a', array( '2026-08-02 10:00:00' => 5 ) );
		wp_delete_post( $this->post_a, true );

		$csv = $this->stream( $this->args() );
		$row = str_getcsv( explode( "\n", trim( substr( $csv, 3 ) ) )[1], ',', '"', '' );

		$this->assertSame( '(deleted post #' . $this->post_a . ')', $row[1] );
		$this->assertNotSame( '', $row[2] ); // stored source_url survives deletion
	}

	public function test_csv_contains_no_tokens() {
		global $wpdb;
		$this->seed( $this->post_a, 'https://m1.example/a', array( '2026-08-02 10:00:00' => 5 ) );

		$links  = octk_table_links();
		$tokens = $wpdb->get_col( "SELECT LOWER(HEX(public_token)) FROM {$links}" );
		$csv    = strtolower( $this->stream( $this->args() ) );

		$this->assertNotEmpty( $tokens );
		foreach ( $tokens as $token ) {
			$this->assertStringNotContainsString( $token, $csv );
		}
	}

	public function test_csv_label_column_resolves_override_over_auto() {
		global $wpdb;
		$dest          = octk_normalize_destination( 'https://m1.example/a' );
		$dest['label'] = 'Auto anchor';
		OCTK_Registry::tokens_for( 'post', $this->post_a, array( $dest ) );
		OCTK_Registry::reset_memo();
		$this->seed( $this->post_a, 'https://m1.example/a', array( '2026-08-02 10:00:00' => 5 ) );

		$row = str_getcsv( explode( "\n", trim( substr( $this->stream( $this->args() ), 3 ) ) )[1], ',', '"', '' );
		$this->assertSame( 'Auto anchor', end( $row ) );

		octk_override_upsert( 'https://m1.example/a', '=SUM(A1) Manual' );
		try {
			$row = str_getcsv( explode( "\n", trim( substr( $this->stream( $this->args() ), 3 ) ) )[1], ',', '"', '' );
			$this->assertSame( "'=SUM(A1) Manual", end( $row ), 'override wins and the formula guard applies' );
		} finally {
			$wpdb->query( 'DELETE FROM ' . octk_table_overrides() );
		}
	}

	public function test_export_url_carries_filters_and_nonce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$request = array(
			'preset'        => 'custom',
			'from'          => '2026-08-01',
			'to'            => '2026-08-07',
			'search'    => 'widget',
			'search_in' => 'destination',
			'source_id' => 123,
			'args'      => array( 'host' => 'm1.example', 'orderby' => 'clicks', 'order' => 'desc' ),
		);

		$url = octk_export_url( $request );

		$this->assertStringContainsString( 'admin-post.php', $url );
		$this->assertStringContainsString( 'action=octk_export_csv', $url );
		$this->assertStringContainsString( 'range=custom', $url );
		$this->assertStringContainsString( 'from=2026-08-01', $url );
		$this->assertStringContainsString( 'to=2026-08-07', $url );
		$this->assertStringContainsString( 'host=m1.example', $url );
		$this->assertStringContainsString( 'search=widget', $url );
		$this->assertStringContainsString( 'search_in=destination', $url );
		$this->assertStringContainsString( 'source_id=123', $url );
		$this->assertStringContainsString( '_wpnonce=', $url );
		$this->assertStringNotContainsString( 'per_page', $url );
	}
}
