<?php

class OCTK_Reporting_Test extends WP_UnitTestCase {

	private $post_a;
	private $post_b;

	public function set_up() {
		parent::set_up();
		OCTK_Registry::reset_memo();
		delete_transient( 'octk_status_counts' );
		$this->post_a = self::factory()->post->create( array( 'post_title' => 'Widget deep dive' ) );
		$this->post_b = self::factory()->post->create( array( 'post_title' => 'Magnesium guide' ) );
	}

	private function seed( int $post_id, string $url, array $buckets ): string {
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
		return $token;
	}

	private function args( array $overrides = array() ): array {
		return array_merge(
			array(
				'start_utc' => '2026-08-01 00:00:00',
				'end_utc'   => '2026-08-08 00:00:00',
				'orderby'   => 'clicks',
				'order'     => 'desc',
				'page'      => 1,
				'per_page'  => 25,
			),
			$overrides
		);
	}

	public function test_rows_totals_and_range_bounds() {
		$this->seed( $this->post_a, 'https://m1.example/a', array(
			'2026-08-02 10:00:00' => 5,
			'2026-08-03 11:00:00' => 2,
			'2026-08-08 00:00:00' => 99, // outside half-open range
		) );
		$this->seed( $this->post_b, 'https://m2.example/b', array( '2026-08-04 09:00:00' => 3 ) );

		$result = OCTK_Reporting::query_rows( $this->args() );

		$this->assertSame( 2, $result->total );
		$this->assertSame( 7, (int) $result->rows[0]->clicks ); // desc: 7 first
		$this->assertSame( 'm1.example', $result->rows[0]->destination_host );
		$this->assertSame( '2026-08-03 11:00:00', $result->rows[0]->last_bucket );
		$this->assertSame( 3, (int) $result->rows[1]->clicks );
	}

	public function test_host_and_source_filters() {
		$this->seed( $this->post_a, 'https://m1.example/a', array( '2026-08-02 10:00:00' => 5 ) );
		$this->seed( $this->post_b, 'https://m2.example/b', array( '2026-08-02 10:00:00' => 3 ) );

		$by_host = OCTK_Reporting::query_rows( $this->args( array( 'host' => 'm2.example' ) ) );
		$this->assertSame( 1, $by_host->total );
		$this->assertSame( 'm2.example', $by_host->rows[0]->destination_host );

		$by_source = OCTK_Reporting::query_rows( $this->args( array( 'source_ids' => array( $this->post_a ) ) ) );
		$this->assertSame( 1, $by_source->total );
		$this->assertSame( $this->post_a, (int) $by_source->rows[0]->source_id );

		$no_match = OCTK_Reporting::query_rows( $this->args( array( 'source_ids' => array( 0 ) ) ) );
		$this->assertSame( 0, $no_match->total );
	}

	public function test_destination_search_matches_url_label_and_override() {
		global $wpdb;
		$this->seed( $this->post_a, 'https://m1.example/product-alpha', array( '2026-08-02 10:00:00' => 5 ) );
		$this->seed( $this->post_b, 'https://m2.example/other', array( '2026-08-02 10:00:00' => 3 ) );

		// URL substring (also covers the host, which the URL contains).
		$by_url = OCTK_Reporting::query_rows( $this->args( array( 'destination_search' => 'product-alpha' ) ) );
		$this->assertSame( 1, $by_url->total );
		$this->assertSame( 'm1.example', $by_url->rows[0]->destination_host );

		$by_host = OCTK_Reporting::query_rows( $this->args( array( 'destination_search' => 'm2.example' ) ) );
		$this->assertSame( 1, $by_host->total );

		// Manual override label, searched with '-' against a stored en dash.
		octk_override_upsert( 'https://m2.example/other', 'Brand – Widget' );
		try {
			$by_label = OCTK_Reporting::query_rows( $this->args( array( 'destination_search' => 'Brand - Widget' ) ) );
			$this->assertSame( 1, $by_label->total );
			$this->assertSame( 'm2.example', $by_label->rows[0]->destination_host );

			$summary = OCTK_Reporting::summary( $this->args( array( 'destination_search' => 'Brand - Widget' ) ) );
			$this->assertSame( 3, $summary->clicks );

			// Words match in any order, and each may hit the URL or the label.
			$mixed = OCTK_Reporting::query_rows( $this->args( array( 'destination_search' => 'widget m2.example' ) ) );
			$this->assertSame( 1, $mixed->total );

			$partial = OCTK_Reporting::query_rows( $this->args( array( 'destination_search' => 'widget nomatchword' ) ) );
			$this->assertSame( 0, $partial->total, 'every word must match' );
		} finally {
			$wpdb->query( 'DELETE FROM ' . octk_table_overrides() );
		}

		$none = OCTK_Reporting::query_rows( $this->args( array( 'destination_search' => 'no-such-destination' ) ) );
		$this->assertSame( 0, $none->total );
	}

	public function test_pagination_and_invalid_sort_falls_back() {
		$this->seed( $this->post_a, 'https://m1.example/a', array( '2026-08-02 10:00:00' => 5 ) );
		$this->seed( $this->post_b, 'https://m2.example/b', array( '2026-08-02 10:00:00' => 3 ) );

		$page2 = OCTK_Reporting::query_rows( $this->args( array( 'per_page' => 999, 'page' => 2 ) ) );
		// invalid per_page falls back to 25; page 2 of 2 rows is empty
		$this->assertSame( 2, $page2->total );
		$this->assertCount( 0, $page2->rows );

		$bad_sort = OCTK_Reporting::query_rows( $this->args( array( 'orderby' => 'clicks; DROP TABLE x', 'order' => 'evil' ) ) );
		$this->assertSame( 2, $bad_sort->total );
		$this->assertSame( 5, (int) $bad_sort->rows[0]->clicks ); // fell back to clicks DESC
	}

	public function test_summary_matches_rows() {
		$this->seed( $this->post_a, 'https://m1.example/a', array( '2026-08-02 10:00:00' => 5 ) );
		$this->seed( $this->post_a, 'https://m3.example/c', array( '2026-08-02 12:00:00' => 1 ) );

		$summary = OCTK_Reporting::summary( $this->args() );

		$this->assertSame( 6, $summary->clicks );
		$this->assertSame( 2, $summary->identities );
		$this->assertSame( 1, $summary->sources );
		$this->assertSame( '2026-08-02 12:00:00', $summary->last_bucket );
	}

	public function test_deleted_post_row_survives_with_stored_metadata() {
		$this->seed( $this->post_a, 'https://m1.example/a', array( '2026-08-02 10:00:00' => 5 ) );
		wp_delete_post( $this->post_a, true );

		$result = OCTK_Reporting::query_rows( $this->args() );

		$this->assertSame( 1, $result->total );
		$this->assertNotEmpty( $result->rows[0]->source_url );
	}

	public function test_no_sensitive_columns_in_rows() {
		$this->seed( $this->post_a, 'https://m1.example/a', array( '2026-08-02 10:00:00' => 5 ) );
		$row = OCTK_Reporting::query_rows( $this->args() )->rows[0];

		foreach ( array( 'id', 'link_id', 'public_token', 'identity_hash', 'destination_hash' ) as $forbidden ) {
			$this->assertObjectNotHasProperty( $forbidden, $row );
		}
	}

	public function test_retention_prunes_only_expired_buckets() {
		global $wpdb;
		update_option( 'octk_retention_months', 24 );

		$old = gmdate( 'Y-m-d 10:00:00', strtotime( '-30 months' ) );
		$new = gmdate( 'Y-m-d 10:00:00', strtotime( '-1 day' ) );
		$this->seed( $this->post_a, 'https://m1.example/a', array( $old => 5, $new => 2 ) );

		$deleted = octk_retention_run();

		$clicks    = octk_table_clicks();
		$remaining = $wpdb->get_col( "SELECT bucket_start_utc FROM {$clicks}" );
		$this->assertSame( 1, $deleted );
		$this->assertSame( array( $new ), $remaining );
	}

	public function test_status_counts_cached() {
		$this->seed( $this->post_a, 'https://m1.example/a', array( '2026-08-02 10:00:00' => 5 ) );

		$first = OCTK_Reporting::status_counts();
		$this->assertSame( 1, $first->links );

		// New data does not appear until the transient expires.
		$this->seed( $this->post_b, 'https://m2.example/b', array( '2026-08-02 11:00:00' => 1 ) );
		$this->assertSame( 1, OCTK_Reporting::status_counts()->links );

		delete_transient( 'octk_status_counts' );
		$this->assertSame( 2, OCTK_Reporting::status_counts()->links );
	}
}
