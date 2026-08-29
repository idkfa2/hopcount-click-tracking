<?php

class OCTK_Labels_Test extends WP_UnitTestCase {

	private $post_a;

	public function set_up() {
		parent::set_up();
		OCTK_Registry::reset_memo();
		octk_schema_v2_ready( true ); // warm the static cache before any query counting
		$this->post_a = self::factory()->post->create( array( 'post_title' => 'Widget deep dive', 'post_status' => 'publish' ) );
	}

	private function stored_label( string $url, int $post_id ): ?string {
		global $wpdb;
		$links = octk_table_links();
		$hash  = bin2hex( octk_identity_hash( 'post', $post_id, $url ) );
		return $wpdb->get_var( $wpdb->prepare( "SELECT label FROM {$links} WHERE identity_hash = UNHEX(%s)", $hash ) );
	}

	/* ------------------------------ sanitize ------------------------------ */

	public function test_sanitize_decodes_entities_before_stripping_tags() {
		// Encoded markup must not survive as literal tag text.
		$this->assertSame( 'Product', octk_sanitize_anchor_text( '&lt;script&gt;alert(1)&lt;/script&gt;Product' ) );
		$this->assertSame( 'x y', octk_sanitize_anchor_text( 'x &lt;b&gt;y&lt;/b&gt;' ) );
	}

	public function test_sanitize_collapses_whitespace_and_caps_length() {
		$this->assertSame( 'a b c', octk_sanitize_anchor_text( "  a\n\t b   c  " ) );
		$this->assertSame( 200, mb_strlen( octk_sanitize_anchor_text( str_repeat( 'x', 300 ) ) ) );
		$this->assertSame( '', octk_sanitize_anchor_text( '   ' ) );
	}

	/* ------------------------------ chooser ------------------------------- */

	public function test_chooser_prefers_longest_non_generic() {
		$this->assertSame( 'Acme Widget Pro', octk_choose_label( array( 'check price', 'Acme Widget Pro', 'buy now' ) ) );
	}

	public function test_chooser_equal_length_keeps_first_occurrence() {
		$this->assertSame( 'Abcdef', octk_choose_label( array( 'Abcdef', 'Ghijkl' ) ) );
	}

	public function test_chooser_all_generic_keeps_first_generic() {
		$this->assertSame( 'check price', octk_choose_label( array( 'check price', 'buy now' ) ) );
	}

	public function test_chooser_empty_candidates_gives_null() {
		$this->assertNull( octk_choose_label( array() ) );
		$this->assertNull( octk_choose_label( array( '' ) ) );
	}

	public function test_generic_list_is_filterable() {
		$filter = static fn (): array => array( 'acme widget pro' );
		add_filter( 'octk_generic_anchor_texts', $filter );
		try {
			$this->assertSame( 'check price', octk_choose_label( array( 'Acme Widget Pro', 'check price' ) ) );
		} finally {
			remove_filter( 'octk_generic_anchor_texts', $filter );
		}
	}

	/* ----------------------------- extraction ----------------------------- */

	public function test_collect_plain_and_nested_text() {
		$dests = octk_collect_destinations(
			'<p><a href="https://m1.example/a">Acme Widget Pro</a> and <a href="https://m2.example/b"><strong>Bold</strong> name</a></p>'
		);
		$this->assertSame( 'Acme Widget Pro', $dests['https://m1.example/a']['label'] );
		$this->assertSame( 'Bold name', $dests['https://m2.example/b']['label'] );
	}

	public function test_collect_aria_label_beats_img_alt_and_first_alt_wins() {
		$dests = octk_collect_destinations(
			'<a href="https://m1.example/a" aria-label="Aria name"><img src="x.jpg" alt="Alt name" /></a>'
			. '<a href="https://m2.example/b"><img src="x.jpg" alt="First" /><img src="y.jpg" alt="Second" /></a>'
			. '<a href="https://m3.example/c"><img src="x.jpg" /></a>'
		);
		$this->assertSame( 'Aria name', $dests['https://m1.example/a']['label'] );
		$this->assertSame( 'First', $dests['https://m2.example/b']['label'] );
		$this->assertSame( '', $dests['https://m3.example/c']['label'] );
	}

	public function test_collect_same_destination_twice_prefers_non_generic() {
		$dests = octk_collect_destinations(
			'<a href="https://m1.example/a">check price</a><p><a href="https://m1.example/a">Acme Widget Pro</a></p>'
		);
		$this->assertSame( 'Acme Widget Pro', $dests['https://m1.example/a']['label'] );
	}

	public function test_collect_malformed_unclosed_anchor_still_captures() {
		$dests = octk_collect_destinations( '<p><a href="https://m1.example/a">Trailing name' );
		$this->assertSame( 'Trailing name', $dests['https://m1.example/a']['label'] );
	}

	public function test_collect_internal_and_ineligible_links_skipped() {
		$dests = octk_collect_destinations(
			'<a href="/relative">rel</a><a href="' . home_url( '/x' ) . '">internal</a><a href="https://m1.example/a">Keep</a>'
		);
		$this->assertCount( 1, $dests );
		$this->assertSame( 'Keep', $dests['https://m1.example/a']['label'] );
	}

	/* --------------------------- capture + budget -------------------------- */

	public function test_new_rows_insert_label_and_hash_in_three_queries() {
		global $wpdb;
		$dest = octk_normalize_destination( 'https://m1.example/a' );
		$dest['label'] = 'Acme Widget Pro';

		$before = $wpdb->num_queries;
		$tokens = OCTK_Registry::tokens_for( 'post', $this->post_a, array( $dest ) );
		$this->assertSame( 3, $wpdb->num_queries - $before, 'all-new rows: preload + insert + reselect' );
		$this->assertCount( 1, $tokens );

		$this->assertSame( 'Acme Widget Pro', $this->stored_label( $dest['url'], $this->post_a ) );

		$links = octk_table_links();
		$hash  = $wpdb->get_var( $wpdb->prepare(
			"SELECT HEX(destination_hash) FROM {$links} WHERE BINARY destination_url = BINARY %s",
			$dest['url']
		) );
		$this->assertSame( hash( 'sha256', $dest['url'] ), strtolower( (string) $hash ) );
	}

	public function test_existing_rows_with_labels_cost_one_query() {
		global $wpdb;
		$dest          = octk_normalize_destination( 'https://m1.example/a' );
		$dest['label'] = 'Acme Widget Pro';
		OCTK_Registry::tokens_for( 'post', $this->post_a, array( $dest ) );
		OCTK_Registry::reset_memo();

		$before = $wpdb->num_queries;
		OCTK_Registry::tokens_for( 'post', $this->post_a, array( $dest ) );
		$this->assertSame( 1, $wpdb->num_queries - $before, 'steady state: preload only' );
	}

	public function test_null_label_rows_filled_first_wins_in_two_queries() {
		global $wpdb;
		$dest = octk_normalize_destination( 'https://m1.example/a' );
		OCTK_Registry::tokens_for( 'post', $this->post_a, array( $dest ) ); // no label captured
		$this->assertNull( $this->stored_label( $dest['url'], $this->post_a ) );
		OCTK_Registry::reset_memo();

		$dest['label'] = 'Acme Widget Pro';
		$before        = $wpdb->num_queries;
		OCTK_Registry::tokens_for( 'post', $this->post_a, array( $dest ) );
		$this->assertSame( 2, $wpdb->num_queries - $before, 'NULL labels: preload + batched fill' );
		$this->assertSame( 'Acme Widget Pro', $this->stored_label( $dest['url'], $this->post_a ) );

		// First-wins: a later render with different text does not overwrite.
		OCTK_Registry::reset_memo();
		$dest['label'] = 'Different text';
		OCTK_Registry::tokens_for( 'post', $this->post_a, array( $dest ) );
		$this->assertSame( 'Acme Widget Pro', $this->stored_label( $dest['url'], $this->post_a ) );
	}

	/* ---------------------------- invalidation ----------------------------- */

	public function test_save_post_clears_labels_and_next_render_recaptures() {
		$dest          = octk_normalize_destination( 'https://m1.example/a' );
		$dest['label'] = 'Original name';
		OCTK_Registry::tokens_for( 'post', $this->post_a, array( $dest ) );
		$this->assertSame( 'Original name', $this->stored_label( $dest['url'], $this->post_a ) );

		wp_update_post( array( 'ID' => $this->post_a, 'post_title' => 'Edited title' ) );
		$this->assertNull( $this->stored_label( $dest['url'], $this->post_a ), 'save clears auto labels' );

		OCTK_Registry::reset_memo();
		$dest['label'] = 'Renamed product';
		OCTK_Registry::tokens_for( 'post', $this->post_a, array( $dest ) );
		$this->assertSame( 'Renamed product', $this->stored_label( $dest['url'], $this->post_a ) );
	}

	public function test_revision_and_draft_saves_do_not_clear_labels() {
		$dest          = octk_normalize_destination( 'https://m1.example/a' );
		$dest['label'] = 'Original name';
		OCTK_Registry::tokens_for( 'post', $this->post_a, array( $dest ) );

		$revision_id = wp_save_post_revision( $this->post_a );
		if ( $revision_id ) {
			octk_invalidate_labels_on_save( $revision_id, get_post( $revision_id ) );
			$this->assertSame( 'Original name', $this->stored_label( $dest['url'], $this->post_a ) );
		}

		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		octk_invalidate_labels_on_save( $draft, get_post( $draft ) );
		$this->assertSame( 'Original name', $this->stored_label( $dest['url'], $this->post_a ) );
	}
}
