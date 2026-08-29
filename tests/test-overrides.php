<?php

class OCTK_Overrides_Test extends WP_UnitTestCase {

	private $post_a;
	private $post_b;

	public function set_up() {
		parent::set_up();
		OCTK_Registry::reset_memo();
		octk_schema_v2_ready( true );
		delete_transient( 'octk_status_counts' );
		$this->post_a = self::factory()->post->create( array( 'post_title' => 'Widget deep dive' ) );
		$this->post_b = self::factory()->post->create( array( 'post_title' => 'Magnesium guide' ) );
	}

	public function tear_down() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . octk_table_overrides() );
		parent::tear_down();
	}

	private function register( int $post_id, string $url, string $label = '' ): array {
		$dest          = octk_normalize_destination( $url );
		$dest['label'] = $label;
		OCTK_Registry::tokens_for( 'post', $post_id, array( $dest ) );
		OCTK_Registry::reset_memo();
		return $dest;
	}

	private function seed_click( int $post_id, string $normalized_url, string $bucket, int $hits = 1 ): void {
		global $wpdb;
		$links   = octk_table_links();
		$clicks  = octk_table_clicks();
		$hash    = bin2hex( octk_identity_hash( 'post', $post_id, $normalized_url ) );
		$link_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$links} WHERE identity_hash = UNHEX(%s)", $hash ) );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$clicks} (link_id, bucket_start_utc, hits) VALUES (%d, %s, %d)", $link_id, $bucket, $hits ) );
	}

	private function report_args(): array {
		return array(
			'start_utc' => '2026-08-01 00:00:00',
			'end_utc'   => '2026-08-08 00:00:00',
			'orderby'   => 'clicks',
			'order'     => 'desc',
			'page'      => 1,
			'per_page'  => 25,
		);
	}

	/* ------------------------- canonical resolution ------------------------ */

	public function test_canonical_url_requires_tracked_destination() {
		$this->register( $this->post_a, 'https://m1.example/a?aff=7' );

		$this->assertSame( 'https://m1.example/a?aff=7', octk_override_canonical_url( 'https://m1.example/a?aff=7' ) );
		$this->assertNull( octk_override_canonical_url( 'https://m1.example/other' ) );
		$this->assertNull( octk_override_canonical_url( 'not a url' ) );
		$this->assertNull( octk_override_canonical_url( 'ftp://m1.example/a' ) );
		$this->assertNull( octk_override_canonical_url( '' ) );
	}

	public function test_rename_survives_host_later_marked_internal() {
		$this->register( $this->post_a, 'https://later.example/x' );

		update_option( 'octk_internal_hosts', array( 'later.example' ) );
		try {
			// Normalization would now reject this host; the rename path must not.
			$this->assertNull( octk_normalize_destination( 'https://later.example/x' ) );
			$this->assertSame( 'https://later.example/x', octk_override_canonical_url( 'https://later.example/x' ) );
			$this->assertTrue( octk_override_upsert( 'https://later.example/x', 'Still renameable' ) );
		} finally {
			delete_option( 'octk_internal_hosts' );
		}
	}

	public function test_fragment_variants_share_one_destination() {
		// Normalization drops fragments, so #variants collapse to one stored URL.
		$dest = $this->register( $this->post_a, 'https://m1.example/a#section-2' );
		$this->assertSame( 'https://m1.example/a', $dest['url'] );
		$this->assertSame( 'https://m1.example/a', octk_override_canonical_url( 'https://m1.example/a' ) );
	}

	/* ------------------------------- storage ------------------------------- */

	public function test_upsert_get_list_delete_roundtrip() {
		$this->register( $this->post_a, 'https://m1.example/a' );

		$this->assertTrue( octk_override_upsert( 'https://m1.example/a', 'Acme Widget Pro' ) );
		$this->assertSame( 'Acme Widget Pro', octk_override_get( 'https://m1.example/a' ) );

		$this->assertTrue( octk_override_upsert( 'https://m1.example/a', 'Renamed' ) );
		$this->assertSame( 'Renamed', octk_override_get( 'https://m1.example/a' ) );

		$list = octk_override_list();
		$this->assertCount( 1, $list );
		$this->assertSame( 'Renamed', $list[0]->label );

		$this->assertTrue( octk_override_delete( 'https://m1.example/a' ) );
		$this->assertNull( octk_override_get( 'https://m1.example/a' ) );
	}

	public function test_upsert_refuses_hash_row_with_different_stored_url() {
		global $wpdb;
		$this->register( $this->post_a, 'https://m1.example/a' );

		// Corrupt row: the hash of URL A stored against a different URL.
		$wpdb->query( $wpdb->prepare(
			'INSERT INTO ' . octk_table_overrides() . ' (destination_hash, destination_url, label, updated_at_utc) VALUES (UNHEX(%s), %s, %s, %s)',
			hash( 'sha256', 'https://m1.example/a' ),
			'https://evil.example/other',
			'Corrupt',
			'2026-08-01 00:00:00'
		) );

		$this->assertFalse( octk_override_upsert( 'https://m1.example/a', 'New label' ) );
	}

	public function test_upsert_and_delete_purge_dashboard_transients() {
		$this->register( $this->post_a, 'https://m1.example/a' );

		set_transient( 'octk_dashboard_widget_7', (object) array( 'clicks' => 1 ), 300 );
		octk_override_upsert( 'https://m1.example/a', 'Label' );
		$this->assertFalse( get_transient( 'octk_dashboard_widget_7' ) );

		set_transient( 'octk_dashboard_widget_30', (object) array( 'clicks' => 1 ), 300 );
		octk_override_delete( 'https://m1.example/a' );
		$this->assertFalse( get_transient( 'octk_dashboard_widget_30' ) );
	}

	/* ----------------------------- precedence ------------------------------ */

	public function test_report_resolves_override_over_auto_label() {
		$dest = $this->register( $this->post_a, 'https://m1.example/a', 'Auto anchor text' );
		$this->seed_click( $this->post_a, $dest['url'], '2026-08-02 10:00:00', 5 );

		$row = OCTK_Reporting::query_rows( $this->report_args() )->rows[0];
		$this->assertSame( 'Auto anchor text', $row->label );
		$this->assertSame( 'auto', $row->label_origin );

		octk_override_upsert( 'https://m1.example/a', 'Manual name' );
		$row = OCTK_Reporting::query_rows( $this->report_args() )->rows[0];
		$this->assertSame( 'Manual name', $row->label );
		$this->assertSame( 'manual', $row->label_origin );

		octk_override_delete( 'https://m1.example/a' );
		$row = OCTK_Reporting::query_rows( $this->report_args() )->rows[0];
		$this->assertSame( 'Auto anchor text', $row->label );
		$this->assertSame( 'auto', $row->label_origin );
	}

	public function test_unlabeled_row_has_empty_origin() {
		$dest = $this->register( $this->post_a, 'https://m1.example/a' );
		$this->seed_click( $this->post_a, $dest['url'], '2026-08-02 10:00:00' );

		$row = OCTK_Reporting::query_rows( $this->report_args() )->rows[0];
		$this->assertNull( $row->label );
		$this->assertSame( '', $row->label_origin );
	}

	public function test_override_applies_across_posts_sharing_destination() {
		$dest_a = $this->register( $this->post_a, 'https://m1.example/a' );
		$dest_b = $this->register( $this->post_b, 'https://m1.example/a' );
		$this->seed_click( $this->post_a, $dest_a['url'], '2026-08-02 10:00:00' );
		$this->seed_click( $this->post_b, $dest_b['url'], '2026-08-02 11:00:00' );

		octk_override_upsert( 'https://m1.example/a', 'Shared name' );

		$rows = OCTK_Reporting::query_rows( $this->report_args() )->rows;
		$this->assertCount( 2, $rows );
		foreach ( $rows as $row ) {
			$this->assertSame( 'Shared name', $row->label );
			$this->assertSame( 'manual', $row->label_origin );
		}
	}

	public function test_exact_url_matching_is_case_sensitive() {
		$lower = $this->register( $this->post_a, 'https://m1.example/product' );
		$upper = $this->register( $this->post_b, 'https://m1.example/Product' );
		$this->seed_click( $this->post_a, $lower['url'], '2026-08-02 10:00:00' );
		$this->seed_click( $this->post_b, $upper['url'], '2026-08-02 11:00:00' );

		octk_override_upsert( 'https://m1.example/product', 'Lowercase only' );

		$rows = OCTK_Reporting::query_rows( $this->report_args() )->rows;
		$labels = array();
		foreach ( $rows as $row ) {
			$labels[ $row->destination_url ] = $row->label;
		}
		$this->assertSame( 'Lowercase only', $labels['https://m1.example/product'] );
		$this->assertNull( $labels['https://m1.example/Product'] );
	}

	/* ------------------------------ handlers ------------------------------- */

	public function test_save_handler_requires_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->expectException( 'WPDieException' );
		octk_handle_save_label();
	}

	public function test_delete_handler_requires_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->expectException( 'WPDieException' );
		octk_handle_delete_label();
	}

	public function test_save_handler_requires_nonce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST = array( 'dest' => 'https://m1.example/a', 'label' => 'X' );
		try {
			$this->expectException( 'WPDieException' );
			octk_handle_save_label();
		} finally {
			$_POST = array();
		}
	}

	public function test_label_capability_defaults_to_manage_options_and_is_filterable() {
		$this->assertSame( 'manage_options', octk_label_capability() );

		$filter = static fn (): string => 'edit_published_posts';
		add_filter( 'octk_label_capability', $filter );
		try {
			$this->assertSame( 'edit_published_posts', octk_label_capability() );
		} finally {
			remove_filter( 'octk_label_capability', $filter );
		}
	}

	/* ---------------------------- column policy ---------------------------- */

	public function test_report_rows_still_expose_no_sensitive_columns() {
		$dest = $this->register( $this->post_a, 'https://m1.example/a', 'Label' );
		$this->seed_click( $this->post_a, $dest['url'], '2026-08-02 10:00:00' );

		$row = OCTK_Reporting::query_rows( $this->report_args() )->rows[0];
		foreach ( array( 'id', 'link_id', 'public_token', 'identity_hash', 'destination_hash' ) as $forbidden ) {
			$this->assertObjectNotHasProperty( $forbidden, $row );
		}
	}
}
