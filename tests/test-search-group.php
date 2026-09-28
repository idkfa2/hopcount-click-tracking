<?php

/**
 * 1.3.0: "All" search mode (default), pasted-URL and entity-safe search,
 * the empty-result hint, group by page, and readable destination URLs.
 * Search cases go through $_GET -> octk_report_request() -> reporting, the
 * path the Report screen and CSV export actually use.
 */
class OCTK_Search_Group_Test extends WP_UnitTestCase {

	private $previous_get;
	private $previous_request;

	public function set_up() {
		parent::set_up();
		OCTK_Registry::reset_memo();
		delete_transient( 'octk_status_counts' );
		$this->previous_get     = $_GET;
		$this->previous_request = $_REQUEST;
	}

	public function tear_down() {
		global $wpdb;
		$_GET     = $this->previous_get;
		$_REQUEST = $this->previous_request;
		$wpdb->query( 'DELETE FROM ' . octk_table_overrides() );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** Seed one link with clicks in the current hour (inside every preset). */
	private function seed( int $post_id, string $url, int $hits = 1, ?string $bucket = null ): string {
		global $wpdb;
		$dest   = octk_normalize_destination( $url );
		$tokens = OCTK_Registry::tokens_for( 'post', $post_id, array( $dest ) );
		$token  = array_values( $tokens )[0];

		$link_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE public_token = UNHEX(%s)', octk_table_links(), $token ) );
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (link_id, bucket_start_utc, hits) VALUES (%d, %s, %d)', octk_table_clicks(), $link_id, $bucket ?? gmdate( 'Y-m-d H:00:00' ), $hits ) );
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT destination_url FROM %i WHERE id = %d', octk_table_links(), $link_id ) );
	}

	/** A published post whose stored title is exactly $title (no kses/texturize). */
	private function post_titled( string $title, string $status = 'publish', string $type = 'post' ): int {
		global $wpdb;
		$id = self::factory()->post->create( array( 'post_title' => 'placeholder', 'post_status' => $status, 'post_type' => $type ) );
		$wpdb->update( $wpdb->posts, array( 'post_title' => $title ), array( 'ID' => $id ) );
		clean_post_cache( $id );
		return $id;
	}

	private function request( array $get ): array {
		$_GET = $get;
		return octk_report_request();
	}

	private function rows( array $get ) {
		return OCTK_Reporting::query_rows( $this->request( $get )['args'] );
	}

	private function render( array $get ): string {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET = array_merge( array( 'page' => 'octk-report' ), $get );
		ob_start();
		octk_render_report_page();
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------ */
	/* All mode                                                             */
	/* ------------------------------------------------------------------ */

	public function test_search_in_defaults_to_all_and_keeps_explicit_modes() {
		$this->assertSame( 'all', $this->request( array( 'search' => 'x' ) )['search_in'] );
		$this->assertSame( 'all', $this->request( array( 'search' => 'x', 'search_in' => 'bogus' ) )['search_in'] );
		$this->assertSame( 'source', $this->request( array( 'search' => 'x', 'search_in' => 'source' ) )['search_in'] );
		$this->assertSame( 'destination', $this->request( array( 'search' => 'x', 'search_in' => 'destination' ) )['search_in'] );

		$all = $this->request( array( 'search' => 'hone' ) );
		$this->assertSame( 'hone', $all['args']['all_search'] );
		$this->assertSame( '', $all['args']['destination_search'] );
		$this->assertNull( $all['args']['source_ids'] );
		$this->assertFalse( $all['capped'] );
	}

	public function test_all_matches_destination_title_and_words_across_both() {
		$joe  = $this->post_titled( 'OCTK Joe Rogan Peptides List' );
		$gary = $this->post_titled( 'OCTK Gary Brecka Peptides' );
		$this->seed( $joe, 'https://honehealth.pxf.io/c/1/2/3?u=trt', 5 );
		$this->seed( $joe, 'https://www.amazon.com/dp/B000', 2 );
		$this->seed( $gary, 'https://honehealth.pxf.io/c/1/2/3?u=nad', 3 );

		$this->assertSame( 2, $this->rows( array( 'search' => 'honehealth' ) )->total, 'destination only (the Hone case)' );
		$this->assertSame( 2, $this->rows( array( 'search' => 'octk joe rogan' ) )->total, 'title only: every link on the post' );
		$mixed = $this->rows( array( 'search' => 'octk joe rogan honehealth' ) );
		$this->assertSame( 1, $mixed->total, 'words may match title and destination separately' );
		$this->assertSame( 5, (int) $mixed->rows[0]->clicks );
		$this->assertSame( 0, $this->rows( array( 'search' => 'octk nomatchword' ) )->total, 'no match is empty, not unfiltered' );
	}

	public function test_all_matches_labels() {
		$post = $this->post_titled( 'OCTK Label Probe Post' );
		$url  = $this->seed( $post, 'https://m1.example/x', 4 );
		octk_override_upsert( $url, 'Hone Health' );

		$this->assertSame( 1, $this->rows( array( 'search' => 'hone health' ) )->total );
	}

	public function test_all_ignores_unpublished_titles_and_attachments() {
		$draft      = $this->post_titled( 'OCTK Draftonly Title', 'draft' );
		$private    = $this->post_titled( 'OCTK Draftonly Private', 'private' );
		$attachment = $this->post_titled( 'OCTK Draftonly Attachment', 'inherit', 'attachment' );
		foreach ( array( $draft, $private, $attachment ) as $id ) {
			$this->seed( $id, 'https://m' . $id . '.example/a' );
		}

		$this->assertSame( 0, $this->rows( array( 'search' => 'draftonly' ) )->total );
	}

	public function test_all_combines_with_pin_and_host() {
		$joe  = $this->post_titled( 'OCTK Pin Joe' );
		$gary = $this->post_titled( 'OCTK Pin Gary' );
		$this->seed( $joe, 'https://honehealth.pxf.io/a', 5 );
		$this->seed( $joe, 'https://www.amazon.com/hone-book', 1 );
		$this->seed( $gary, 'https://honehealth.pxf.io/b', 3 );

		$pinned = $this->rows( array( 'search' => 'hone', 'source_id' => (string) $joe ) );
		$this->assertSame( 2, $pinned->total );

		$hosted = $this->rows( array( 'search' => 'hone', 'host' => 'honehealth.pxf.io' ) );
		$this->assertSame( 2, $hosted->total );

		$both = $this->rows( array( 'search' => 'hone', 'host' => 'honehealth.pxf.io', 'source_id' => (string) $joe ) );
		$this->assertSame( 1, $both->total );
	}

	public function test_all_has_no_title_cap() {
		for ( $i = 0; $i < OCTK_Reporting::MAX_SOURCE_IDS + 5; $i++ ) {
			$this->seed( $this->post_titled( 'OCTK Bulkprobe ' . $i ), 'https://m.example/p' . $i );
		}

		$request = $this->request( array( 'search' => 'bulkprobe', 'per_page' => '100' ) );
		$result  = OCTK_Reporting::query_rows( $request['args'] );

		$this->assertFalse( $request['capped'] );
		$this->assertSame( OCTK_Reporting::MAX_SOURCE_IDS + 5, $result->total );
	}

	public function test_all_posts_join_does_not_multiply_clicks() {
		$post = $this->post_titled( 'OCTK Sum Probe' );
		$url  = $this->seed( $post, 'https://m1.example/a', 5 );
		$this->seed( $post, 'https://m2.example/b', 3 );
		octk_override_upsert( $url, 'Sum Probe Label' );

		$args    = $this->request( array( 'search' => 'octk sum probe' ) )['args'];
		$summary = OCTK_Reporting::summary( $args );
		$rows    = OCTK_Reporting::query_rows( $args );

		$this->assertSame( 8, $summary->clicks );
		$this->assertSame( 8, array_sum( array_map( static fn( $r ) => (int) $r->clicks, $rows->rows ) ) );
		$this->assertSame( 2, $summary->identities );
	}

	/* ------------------------------------------------------------------ */
	/* Pasted URLs and the sanitizer                                        */
	/* ------------------------------------------------------------------ */

	public function test_pasted_encoded_affiliate_url_matches_in_destination_and_all() {
		$post = $this->post_titled( 'OCTK Paste Probe' );
		$url  = $this->seed( $post, 'https://honehealth.pxf.io/c/1911326/3228231/40835?trafsrc=affiliate&u=https%3A%2F%2Fhonehealth.com%2Fmens%2Ftestosterone-replacement-therapy%2F', 11 );
		$this->seed( $post, 'https://honehealth.pxf.io/c/1911326/3228231/40835?trafsrc=affiliate&u=https%3A%2F%2Fhonehealth.com%2Fmens%2Fsermorelin%2F', 7 );

		$as_href = str_replace( '&', '&amp;', $url ); // copied from page source
		foreach ( array( 'destination', 'all' ) as $mode ) {
			foreach ( array( $url, $as_href ) as $needle ) {
				$result = $this->rows( array( 'search' => wp_slash( $needle ), 'search_in' => $mode ) );
				$this->assertSame( 1, $result->total, "{$mode}: {$needle}" );
				$this->assertSame( 11, (int) $result->rows[0]->clicks );
			}
		}
	}

	public function test_long_urls_differing_after_250_chars_match_exactly_one() {
		$post   = $this->post_titled( 'OCTK Long Probe' );
		$prefix = 'https://m.example/' . str_repeat( 'a', 240 );
		$url_a  = $this->seed( $post, $prefix . 'x-tail-one', 2 );
		$url_b  = $this->seed( $post, $prefix . 'x-tail-two', 3 );
		$this->assertGreaterThan( 250, strlen( $url_a ) );

		$a = $this->rows( array( 'search' => $url_a ) );
		$b = $this->rows( array( 'search' => $url_b ) );
		$this->assertSame( 1, $a->total );
		$this->assertSame( 2, (int) $a->rows[0]->clicks );
		$this->assertSame( 1, $b->total );
		$this->assertSame( 3, (int) $b->rows[0]->clicks );
	}

	public function test_sanitizer_keeps_octets_and_entities_strips_tags_and_bad_utf8() {
		$this->assertSame( 'u=https%3A%2F%2Fx.example%2F', octk_sanitize_search( 'u=https%3A%2F%2Fx.example%2F' ) );
		$this->assertSame( 'R&amp;D a&b', octk_sanitize_search( 'R&amp;D   a&b ' ) );
		$this->assertSame( "it's", octk_sanitize_search( "it's" ) );
		$this->assertSame( 'bold x', octk_sanitize_search( '<b>bold</b> x' ) );
		$this->assertSame( '', octk_sanitize_search( "a\xC3b" ) );
		$this->assertSame( '', octk_sanitize_search( array( 'x' ) ) );
	}

	public function test_search_length_limit_accepts_2048_and_rejects_2049() {
		$ok = $this->request( array( 'search' => str_repeat( 'a', OCTK_Reporting::SEARCH_MAX ) ) );
		$this->assertSame( '', $ok['search_error'] );
		$this->assertSame( OCTK_Reporting::SEARCH_MAX, strlen( $ok['search'] ) );

		$bad = $this->request( array( 'search' => str_repeat( 'a', OCTK_Reporting::SEARCH_MAX + 1 ) ) );
		$this->assertSame( 'too_long', $bad['search_error'] );
		$this->assertSame( '', $bad['search'] );
	}

	public function test_overlong_search_reports_error_and_empty_table_not_all_rows() {
		$this->seed( $this->post_titled( 'OCTK Overlong Probe' ), 'https://m1.example/a', 5 );

		$request = $this->request( array( 'search' => str_repeat( 'a', OCTK_Reporting::SEARCH_MAX + 1 ) ) );
		$this->assertSame( 0, OCTK_Reporting::query_rows( $request['args'] )->total, 'even a caller ignoring search_error gets nothing' );

		$html = $this->render( array( 'search' => str_repeat( 'a', OCTK_Reporting::SEARCH_MAX + 1 ) ) );
		$this->assertStringContainsString( 'Search is too long', $html );
		$this->assertStringNotContainsString( 'octk-table', $html );
		$this->assertStringNotContainsString( 'octk-cards', $html );
		$this->assertStringNotContainsString( str_repeat( 'a', 100 ), $html, 'rejected text is not echoed back' );
	}

	public function test_overlong_search_csv_export_dies_before_output() {
		$this->seed( $this->post_titled( 'OCTK Overlong CSV' ), 'https://m1.example/a', 5 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$nonce    = wp_create_nonce( 'octk_export_csv' );
		$_GET     = array( 'action' => 'octk_export_csv', 'search' => str_repeat( 'a', OCTK_Reporting::SEARCH_MAX + 1 ), '_wpnonce' => $nonce );
		$_REQUEST = $_GET;

		ob_start();
		try {
			octk_handle_export_csv();
			$this->fail( 'export must die' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( 'Search is too long', $e->getMessage() );
		} finally {
			$output = ob_get_clean();
		}
		$this->assertSame( '', $output, 'no CSV bytes before the error' );
	}

	/* ------------------------------------------------------------------ */
	/* Entity folding                                                       */
	/* ------------------------------------------------------------------ */

	public function fold_cases(): array {
		return array(
			array( 'R&amp;D' ),
			array( '&amp;#8217;' ),
			array( '&#038;amp;' ),
			array( '&apos;' ),
			array( 'Joe&#8217;s &#8216;list&#8217;' ),
			array( 'A &#8211; B &#8212; C' ),
			array( 'Dash – and — quote ’ ‘' ),
			array( '&quot;x&quot; &#039;y&#039;' ),
			array( 'mixed &amp;amp; &amp;quot; plain' ),
		);
	}

	/**
	 * @dataProvider fold_cases
	 */
	public function test_fold_parity_php_and_mysql( string $input ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fold_sql() wraps the %s placeholder in literal REPLACE() calls.
		$sql_folded = $wpdb->get_var( $wpdb->prepare( 'SELECT ' . OCTK_Reporting::fold_sql( '%s' ), $input ) );
		$this->assertSame( OCTK_Reporting::fold_needle( $input ), $sql_folded );
	}

	public function test_fold_order_single_pass() {
		$this->assertSame( '&#8217;', OCTK_Reporting::fold_needle( '&amp;#8217;' ) );
		$this->assertSame( '&', OCTK_Reporting::fold_needle( '&#038;amp;' ) );
		$this->assertSame( '&apos;', OCTK_Reporting::fold_needle( '&apos;' ) );
	}

	/** Title matches through both title-searching modes, end to end. */
	private function title_hits( string $needle, int $post ): array {
		$hits = array();
		$all  = $this->rows( array( 'search' => wp_slash( $needle ) ) );
		$hits['all'] = in_array( $post, array_map( static fn( $r ) => (int) $r->source_id, $all->rows ), true );
		$source      = $this->request( array( 'search' => wp_slash( $needle ), 'search_in' => 'source' ) );
		$hits['source'] = in_array( $post, (array) $source['args']['source_ids'], true );
		return $hits;
	}

	public function test_title_entities_match_consistently_in_all_and_source_modes() {
		$rd   = $this->post_titled( 'OCTK R&amp;D Lab' );
		$joe  = $this->post_titled( 'OCTK Joe&#8217;s List' );
		$ann  = $this->post_titled( 'OCTK Ann&#039;s Notes' );
		$odd  = $this->post_titled( 'OCTK Oddprobe &amp;#8217; Title' );
		$apos = $this->post_titled( 'OCTK Aposprobe &apos;x' );
		foreach ( array( $rd, $joe, $ann, $odd, $apos ) as $id ) {
			$this->seed( $id, 'https://m' . $id . '.example/a' );
		}

		$both = array( 'all' => true, 'source' => true );
		$none = array( 'all' => false, 'source' => false );

		$this->assertSame( $both, $this->title_hits( 'octk r&d lab', $rd ) );
		$this->assertSame( $both, $this->title_hits( 'octk r&amp;d lab', $rd ) );
		$this->assertSame( $both, $this->title_hits( "octk joe's", $joe ) );
		$this->assertSame( $both, $this->title_hits( 'octk joe’s', $joe ) );
		$this->assertSame( $both, $this->title_hits( "octk ann's", $ann ) );

		// Title folds to "&#8217;" (the &amp; is decoded once, last).
		$this->assertSame( $both, $this->title_hits( 'octk oddprobe &amp;#8217;', $odd ) );
		$this->assertSame( $none, $this->title_hits( 'octk oddprobe &#8217;', $odd ) );
		$this->assertSame( $none, $this->title_hits( "octk oddprobe '", $odd ) );

		// Unlisted entities stay literal on both sides.
		$this->assertSame( $both, $this->title_hits( 'octk aposprobe &apos;x', $apos ) );
		$this->assertSame( $none, $this->title_hits( "octk aposprobe 'x", $apos ) );
	}

	public function test_label_entities_match_in_destination_and_all() {
		$post = $this->post_titled( 'OCTK Label Entity Post' );
		$url  = $this->seed( $post, 'https://m1.example/energy', 2 );
		octk_override_upsert( $url, 'Energy & Focus' );

		foreach ( array( 'destination', 'all' ) as $mode ) {
			$this->assertSame( 1, $this->rows( array( 'search' => 'energy &amp; focus', 'search_in' => $mode ) )->total, $mode );
			$this->assertSame( 1, $this->rows( array( 'search' => 'energy & focus', 'search_in' => $mode ) )->total, $mode );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Empty-result hint                                                    */
	/* ------------------------------------------------------------------ */

	public function test_hint_args_drop_title_ids_and_keep_pin() {
		$request = $this->request( array( 'search' => 'hone', 'search_in' => 'source' ) );
		$this->assertSame( array( 0 ), $request['args']['source_ids'], 'no title matched: sentinel' );

		$args = octk_report_all_args( $request );
		$this->assertSame( 'hone', $args['all_search'] );
		$this->assertSame( '', $args['destination_search'] );
		$this->assertNull( $args['source_ids'], 'sentinel must not survive' );

		$pinned = octk_report_all_args( $this->request( array( 'search' => 'hone', 'search_in' => 'destination', 'source_id' => '42' ) ) );
		$this->assertSame( array( 42 ), $pinned['source_ids'] );
		$this->assertSame( '', $pinned['destination_search'] );
	}

	public function test_hint_shown_in_source_mode_when_all_finds_destinations() {
		$post = $this->post_titled( 'OCTK Hint Post' );
		$this->seed( $post, 'https://honehealth.pxf.io/a', 5 );

		$html = $this->render( array( 'search' => 'honehealth', 'search_in' => 'source', 'paged' => '2' ) );

		$this->assertStringContainsString( 'No matches in source content. 5 clicks match in All.', $html );
		$this->assertMatchesRegularExpression( '#<a href="[^"]*search_in=all[^"]*">Search All</a>#', $html );
		preg_match( '#<div class="notice notice-info octk-all-hint"><p>.*?<a href="([^"]*)"#', $html, $m );
		$this->assertStringNotContainsString( 'paged=', $m[1] );
	}

	public function test_hint_uses_destination_wording_in_destination_mode() {
		$post = $this->post_titled( 'OCTK Hintdest Title' );
		$this->seed( $post, 'https://m1.example/a', 1 );

		$html = $this->render( array( 'search' => 'octk hintdest', 'search_in' => 'destination' ) );

		$this->assertStringContainsString( 'No matches in destinations. 1 click matches in All.', $html );
	}

	public function test_hint_hidden_when_all_is_empty_too_and_in_all_mode() {
		$this->seed( $this->post_titled( 'OCTK Hint Empty' ), 'https://m1.example/a', 1 );

		$this->assertStringNotContainsString( 'octk-all-hint', $this->render( array( 'search' => 'zzznomatch', 'search_in' => 'source' ) ) );
		$this->assertStringNotContainsString( 'octk-all-hint', $this->render( array( 'search' => 'zzznomatch' ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Group by page                                                        */
	/* ------------------------------------------------------------------ */

	public function test_group_by_page_sums_clicks_and_counts_links() {
		$a = $this->post_titled( 'OCTK Group A' );
		$b = $this->post_titled( 'OCTK Group B' );
		$this->seed( $a, 'https://honehealth.pxf.io/1', 5 );
		$this->seed( $a, 'https://honehealth.pxf.io/2', 4 );
		$this->seed( $a, 'https://www.amazon.com/x', 50 );
		$this->seed( $b, 'https://honehealth.pxf.io/3', 2 );

		$result = $this->rows( array( 'search' => 'honehealth', 'group' => 'page' ) );

		$this->assertSame( 2, $result->total );
		$this->assertSame( $a, (int) $result->rows[0]->source_id );
		$this->assertSame( 9, (int) $result->rows[0]->clicks, 'Amazon link excluded by the search' );
		$this->assertSame( 2, (int) $result->rows[0]->links );
		$this->assertSame( 2, (int) $result->rows[1]->clicks );

		$ungrouped = $this->rows( array( 'group' => 'page' ) );
		$this->assertSame( 59, (int) $ungrouped->rows[0]->clicks );
		$this->assertSame( 3, (int) $ungrouped->rows[0]->links );
	}

	public function test_group_by_page_respects_host_modes_and_pin() {
		$a = $this->post_titled( 'OCTK Grouphost A' );
		$b = $this->post_titled( 'OCTK Grouphost B' );
		$this->seed( $a, 'https://honehealth.pxf.io/1', 5 );
		$this->seed( $a, 'https://www.amazon.com/x', 7 );
		$this->seed( $b, 'https://honehealth.pxf.io/2', 2 );

		$host = $this->rows( array( 'host' => 'www.amazon.com', 'group' => 'page' ) );
		$this->assertSame( 1, $host->total );
		$this->assertSame( 7, (int) $host->rows[0]->clicks );

		$dest = $this->rows( array( 'search' => 'honehealth', 'search_in' => 'destination', 'group' => 'page' ) );
		$this->assertSame( 2, $dest->total );

		$source = $this->rows( array( 'search' => 'octk grouphost a', 'search_in' => 'source', 'group' => 'page' ) );
		$this->assertSame( 1, $source->total );
		$this->assertSame( 12, (int) $source->rows[0]->clicks );

		$pin = $this->rows( array( 'source_id' => (string) $b, 'group' => 'page' ) );
		$this->assertSame( 1, $pin->total );
		$this->assertSame( $b, (int) $pin->rows[0]->source_id );
	}

	public function test_group_by_page_pagination_and_sorting() {
		$ids = array();
		for ( $i = 1; $i <= 26; $i++ ) {
			$ids[ $i ] = $this->post_titled( 'OCTK Pageprobe ' . $i );
			// Clicks = i; last bucket gets later as i falls, so the two sorts disagree.
			$this->seed( $ids[ $i ], 'https://m.example/p' . $i, $i, gmdate( 'Y-m-d H:00:00', time() - $i * HOUR_IN_SECONDS ) );
		}

		$page1 = $this->rows( array( 'group' => 'page', 'per_page' => '25' ) );
		$page2 = $this->rows( array( 'group' => 'page', 'per_page' => '25', 'paged' => '2' ) );
		$this->assertSame( 26, $page1->total );
		$this->assertCount( 25, $page1->rows );
		$this->assertCount( 1, $page2->rows );
		$this->assertSame( 26, (int) $page1->rows[0]->clicks );
		$this->assertSame( 1, (int) $page2->rows[0]->clicks );

		$asc = $this->rows( array( 'group' => 'page', 'order' => 'asc' ) );
		$this->assertSame( 1, (int) $asc->rows[0]->clicks );

		$recent = $this->rows( array( 'group' => 'page', 'orderby' => 'last_activity', 'order' => 'desc' ) );
		$this->assertSame( $ids[1], (int) $recent->rows[0]->source_id );
		$oldest = $this->rows( array( 'group' => 'page', 'orderby' => 'last_activity', 'order' => 'asc' ) );
		$this->assertSame( $ids[26], (int) $oldest->rows[0]->source_id );
	}

	public function test_unknown_group_falls_back_to_link() {
		$this->assertSame( 'link', $this->request( array( 'group' => 'bogus' ) )['group'] );
		$this->assertSame( 'link', $this->request( array() )['group'] );
		$this->assertSame( 'page', $this->request( array( 'group' => 'page' ) )['group'] );
	}

	public function test_page_mode_drilldown_keeps_search_and_switches_to_link() {
		$a = $this->post_titled( 'OCTK Drill A' );
		$this->seed( $a, 'https://honehealth.pxf.io/1', 5 );

		$html = $this->render( array( 'search' => 'honehealth', 'group' => 'page', 'paged' => '1' ) );

		$this->assertMatchesRegularExpression( '#<th scope="col" style="text-align:right;">Links</th>#', $html );
		preg_match( '#<a href="([^"]*source_id=' . $a . '[^"]*)">See all links</a>#', $html, $m );
		$this->assertNotEmpty( $m );
		$href = html_entity_decode( $m[1] );
		$this->assertStringContainsString( 'search=honehealth', $href );
		$this->assertStringContainsString( 'group=link', $href );
		$this->assertStringNotContainsString( 'paged=', $href );
	}

	public function test_link_mode_drilldown_still_drops_search() {
		$a = $this->post_titled( 'OCTK Drill Link' );
		$this->seed( $a, 'https://honehealth.pxf.io/1', 5 );

		$html = $this->render( array( 'search' => 'honehealth' ) );

		preg_match( '#<a href="([^"]*source_id=' . $a . '[^"]*)">See all links</a>#', $html, $m );
		$this->assertNotEmpty( $m );
		$this->assertStringNotContainsString( 'search=', html_entity_decode( $m[1] ) );
	}

	public function test_long_destination_breaks_only_after_the_ellipsis() {
		$post = $this->post_titled( 'OCTK Wbr Probe' );
		$this->seed( $post, 'https://honehealth.pxf.io/c/1911326/3228231/40835?trafsrc=affiliate&u=https%3A%2F%2Fhonehealth.com%2Fmens%2Ftestosterone-replacement-therapy%2F', 1 );

		$html = $this->render( array( 'search' => 'octk wbr probe' ) );

		$this->assertStringContainsString( '…<wbr><span class="octk-nowrap">s/testosterone-replacement-therapy/</span>', $html );
		$this->assertSame( 1, substr_count( $html, '<wbr>' ) );
	}

	public function test_page_mode_hostile_source_url_renders_inert() {
		$row    = (object) array(
			'source_type' => 'post',
			'source_id'   => 999999,
			'source_url'  => 'https://x.example/"><script>alert(1)</script>',
			'links'       => 2,
			'clicks'      => 3,
			'last_bucket' => '<script>alert(2)</script>',
		);
		$result = (object) array( 'rows' => array( $row ), 'total' => 1 );
		ob_start();
		octk_report_table( $result, array( 'group' => 'page', 'args' => array( 'orderby' => 'clicks', 'order' => 'desc', 'page' => 1, 'per_page' => 25 ) ) );
		$html = ob_get_clean();

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	/* ------------------------------------------------------------------ */
	/* Export                                                               */
	/* ------------------------------------------------------------------ */

	private function csv( array $get ): array {
		$args = $this->request( $get )['args'];
		ob_start();
		octk_export_csv_stream( $args );
		$lines = explode( "\n", trim( substr( ob_get_clean(), 3 ) ) );
		return array_map( static fn( $l ) => str_getcsv( $l, ',', '"', '' ), $lines );
	}

	public function test_csv_honours_all_mode() {
		$joe = $this->post_titled( 'OCTK Csvall Joe' );
		$this->seed( $joe, 'https://honehealth.pxf.io/1', 5 );
		$this->seed( $joe, 'https://www.amazon.com/x', 2 );
		$this->seed( $this->post_titled( 'OCTK Csvall Other' ), 'https://m.example/o', 9 );

		$rows = $this->csv( array( 'search' => 'octk csvall joe honehealth' ) );

		$this->assertCount( 2, $rows ); // header + 1
		$this->assertSame( 'https://honehealth.pxf.io/1', $rows[1][4] );
	}

	public function test_csv_page_mode_columns_totals_and_filename() {
		$a = $this->post_titled( 'OCTK Csvpage A' );
		$this->seed( $a, 'https://honehealth.pxf.io/1', 5 );
		$this->seed( $a, 'https://honehealth.pxf.io/2', 4 );

		$rows = $this->csv( array( 'search' => 'honehealth', 'group' => 'page' ) );

		$this->assertSame( array( 'source_post_id', 'source_title', 'source_url', 'links', 'clicks', 'last_activity_utc', 'last_activity_site_tz' ), $rows[0] );
		$this->assertCount( 2, $rows );
		$this->assertSame( (string) $a, $rows[1][0] );
		$this->assertSame( '2', $rows[1][3] );
		$this->assertSame( '9', $rows[1][4] );

		$link_rows = $this->csv( array( 'search' => 'honehealth' ) );
		$this->assertSame( 'label', end( $link_rows[0] ), 'link-mode columns unchanged' );
		$this->assertCount( 9, $link_rows[0] );

		$this->assertSame( 'clicks-by-page_2026-08-01_2026-08-07.csv', octk_export_filename( array( 'group' => 'page', 'from' => '2026-08-01', 'to' => '2026-08-07' ) ) );
		$this->assertSame( 'clicks_2026-08-01_2026-08-07.csv', octk_export_filename( array( 'group' => 'link', 'from' => '2026-08-01', 'to' => '2026-08-07' ) ) );
	}

	public function test_export_url_carries_group() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$page = octk_export_url( $this->request( array( 'group' => 'page', 'search' => 'x' ) ) );
		$link = octk_export_url( $this->request( array( 'search' => 'x' ) ) );

		$this->assertStringContainsString( 'group=page', $page );
		$this->assertStringContainsString( 'search_in=all', $page );
		$this->assertStringNotContainsString( 'group=', $link );
	}

	/* ------------------------------------------------------------------ */
	/* Display URL                                                          */
	/* ------------------------------------------------------------------ */

	public function test_display_url_decodes_and_truncates_in_the_middle() {
		$this->assertSame( 'https://m.example/a', octk_display_url( 'https://m.example/a' ) );

		$hone = 'https://honehealth.pxf.io/c/1911326/3228231/40835?trafsrc=affiliate&u=https%3A%2F%2Fhonehealth.com%2Fmens%2Fsermorelin%2F';
		$shown = octk_display_url( $hone );
		$this->assertStringEndsWith( 'honehealth.com/mens/sermorelin/', $shown );
		$this->assertStringStartsWith( 'https://honehealth.pxf.io/c/1911326', $shown );
		$this->assertStringContainsString( '…', $shown );
		$this->assertLessThanOrEqual( 88, mb_strlen( $shown ) );

		$multi = 'https://m.example/' . str_repeat( 'ü', 100 ) . '/ende';
		$shown = octk_display_url( $multi );
		$this->assertSame( 88, mb_strlen( $shown ) );
		$this->assertNotSame( '', wp_check_invalid_utf8( $shown ) );

		$bad = 'https://m.example/%FF%FE';
		$this->assertSame( $bad, octk_display_url( $bad ), 'invalid UTF-8 after decoding falls back to raw' );
	}
}
