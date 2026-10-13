<?php

/**
 * 1.3.2: the CSV export selects every batch inside one consistent snapshot,
 * spools raw rows to a bounded temp stream, and formats after COMMIT.
 *
 * Visibility across connections can only be tested with committed data:
 * the per-test transaction of the WP harness is ended in set_up (autocommit
 * on), fixtures are real rows, and tear_down removes them explicitly, as
 * test-adoption.php does. Mutations "from another request" go through a
 * second wpdb connection between batches.
 */
class OCTK_Export_Snapshot_Test extends WP_UnitTestCase {

	/** @var int[] */
	private $posts = array();

	/** @var string[] destination URLs, most-clicked first */
	private $urls = array();

	/** @var wpdb|null */
	private $other;

	public function set_up() {
		parent::set_up();
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		$wpdb->query( 'SET autocommit = 1' );
		OCTK_Registry::reset_memo();

		// Six links, one per post, 60/50/40/30/20/10 clicks: distinct totals,
		// so clicks-desc order is fully determined.
		for ( $i = 1; $i <= 6; $i++ ) {
			$post_id       = wp_insert_post( array(
				'post_title'  => "Zebra post {$i}",
				'post_status' => 'publish',
				'post_type'   => 'post',
			) );
			$this->posts[] = $post_id;
			$url           = "https://snap{$i}.example/item";
			$this->urls[]  = $url;
			$this->seed( $post_id, $url, "Gadget {$i}", ( 7 - $i ) * 10 );
		}
	}

	public function tear_down() {
		global $wpdb;
		if ( $this->other instanceof wpdb ) {
			$this->other->close();
		}
		$links = octk_table_links();
		$ids   = $wpdb->get_col( 'SELECT id FROM ' . $links . ' WHERE destination_url LIKE \'https://snap%\' OR destination_url LIKE \'https://big.example/%\'' );
		if ( $ids ) {
			$in = implode( ',', array_map( 'intval', $ids ) );
			$wpdb->query( 'DELETE FROM ' . octk_table_clicks() . " WHERE link_id IN ({$in})" );
			$wpdb->query( "DELETE FROM {$links} WHERE id IN ({$in})" );
		}
		foreach ( $this->posts as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		parent::tear_down();
	}

	private function seed( int $post_id, string $url, string $label, int $hits ): void {
		global $wpdb;
		$dest          = octk_normalize_destination( $url );
		$dest['label'] = $label;
		$tokens        = OCTK_Registry::tokens_for( 'post', $post_id, array( $dest ) );
		$link_id       = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE public_token = UNHEX(%s)', octk_table_links(), array_values( $tokens )[0] ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET label = %s WHERE id = %d', octk_table_links(), $label, $link_id ) );
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (link_id, bucket_start_utc, hits) VALUES (%d, %s, %d)', octk_table_clicks(), $link_id, '2026-08-02 10:00:00', $hits ) );
	}

	private function other(): wpdb {
		if ( ! $this->other instanceof wpdb ) {
			global $wpdb;
			$this->other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
			$this->other->set_prefix( $wpdb->prefix );
		}
		return $this->other;
	}

	private function link_id( string $url ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE destination_url = %s', octk_table_links(), $url ) );
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

	/**
	 * Spool contents as "url=clicks" strings, in order.
	 */
	private function spooled( array $selected ): array {
		$spool = $selected['spool'];
		rewind( $spool );
		$rows = array();
		while ( is_array( $record = fgetcsv( $spool, null, ',', '"', '' ) ) && array( null ) !== $record ) {
			$rows[] = $record[3] . '=' . $record[5];
		}
		fclose( $spool );
		return $rows;
	}

	/**
	 * Select in batches of 2, running $mutation (on the second connection)
	 * after the first batch. Returns the spooled rows.
	 */
	private function select_with_mutation( array $args, callable $mutation, bool $snapshot = true ): array {
		$off = static fn() => false;
		if ( ! $snapshot ) {
			add_filter( 'octk_export_snapshot', $off );
		}
		try {
			$done     = false;
			$selected = octk_export_select( $args, array(
				'batch'       => 2,
				'after_batch' => function () use ( $mutation, &$done ) {
					if ( ! $done ) {
						$done = true;
						$mutation( $this->other() );
					}
				},
			) );
		} finally {
			remove_filter( 'octk_export_snapshot', $off );
		}
		$this->assertIsArray( $selected );
		$this->assertSame( $snapshot, $selected['snapshot'] );
		return $this->spooled( $selected );
	}

	private function baseline( array $args ): array {
		$selected = octk_export_select( $args );
		$this->assertTrue( $selected['snapshot'] );
		return $this->spooled( $selected );
	}

	private function in_transaction(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT @@in_transaction' );
	}

	/* ---- frozen row set across connections ---- */

	public function test_new_click_mid_export_neither_repeats_nor_skips() {
		$args     = $this->args();
		$expected = $this->baseline( $args );
		$this->assertCount( 6, $expected );

		$last = $this->link_id( $this->urls[5] );
		$bump = static function ( wpdb $db ) use ( $last ) {
			$db->query( $db->prepare( 'UPDATE %i SET hits = hits + 100 WHERE link_id = %d', octk_table_clicks(), $last ) );
		};

		$this->assertSame( $expected, $this->select_with_mutation( $args, $bump ) );
		$this->assertSame( 0, $this->in_transaction() );

		// Control: without a snapshot the same kind of mutation does move a
		// row across the batch edge, so the assertion above is meaningful.
		$expected = $this->baseline( $args );
		$fifth    = $this->link_id( $this->urls[4] );
		$jump     = static function ( wpdb $db ) use ( $fifth ) {
			$db->query( $db->prepare( 'UPDATE %i SET hits = hits + 500 WHERE link_id = %d', octk_table_clicks(), $fifth ) );
		};
		$unsnapshotted = $this->select_with_mutation( $args, $jump, false );
		$this->assertNotSame( $expected, $unsnapshotted );
		$this->assertLessThan( 6, count( array_unique( array_map( static fn( $r ) => strtok( $r, '=' ), $unsnapshotted ) ) ), 'a row was repeated and another skipped' );
	}

	public function test_title_edit_that_changes_matches_mid_export() {
		$args     = $this->args( array( 'all_search' => 'zebra' ) );
		$expected = $this->baseline( $args );
		$this->assertCount( 6, $expected );

		// Post 1 (top row, already selected) stops matching: without a
		// snapshot every later row moves up one and one is skipped.
		$first  = $this->posts[0];
		$rename = static function ( wpdb $db ) use ( $first ) {
			$db->query( $db->prepare( 'UPDATE %i SET post_title = %s WHERE ID = %d', $db->posts, 'Renamed', $first ) );
		};

		$this->assertSame( $expected, $this->select_with_mutation( $args, $rename ) );
	}

	public function test_label_edit_that_changes_matches_mid_export() {
		$args     = $this->args( array( 'destination_search' => 'gadget' ) );
		$expected = $this->baseline( $args );
		$this->assertCount( 6, $expected );

		$first   = $this->link_id( $this->urls[0] );
		$relabel = static function ( wpdb $db ) use ( $first ) {
			$db->query( $db->prepare( 'UPDATE %i SET label = %s WHERE id = %d', octk_table_links(), 'Other', $first ) );
		};

		$this->assertSame( $expected, $this->select_with_mutation( $args, $relabel ) );
	}

	public function test_retention_delete_mid_export() {
		$args     = $this->args();
		$expected = $this->baseline( $args );

		$first = $this->link_id( $this->urls[0] );
		$prune = static function ( wpdb $db ) use ( $first ) {
			$db->query( $db->prepare( 'DELETE FROM %i WHERE link_id = %d', octk_table_clicks(), $first ) );
		};

		$this->assertSame( $expected, $this->select_with_mutation( $args, $prune ) );
	}

	/* ---- no snapshot-era data in the object cache ---- */

	public function test_selection_loads_no_posts_formatting_does() {
		foreach ( $this->posts as $post_id ) {
			clean_post_cache( $post_id );
		}
		$selected = octk_export_select( $this->args(), array( 'batch' => 2 ) );
		$this->assertTrue( $selected['snapshot'] );
		foreach ( $this->posts as $post_id ) {
			$this->assertFalse( wp_cache_get( $post_id, 'posts' ), 'no post cached during selection' );
		}

		ob_start();
		octk_export_csv_write( $this->args(), $selected );
		$csv = ob_get_clean();

		$this->assertStringContainsString( 'Zebra post 1', $csv );
		$this->assertIsObject( wp_cache_get( $this->posts[0], 'posts' ), 'formatting uses the normal post APIs' );
	}

	/* ---- failure paths ---- */

	public function test_query_failure_mid_selection_commits_and_closes() {
		$break  = static fn( $query ) => false !== strpos( $query, 'LIMIT' ) ? 'SELECT broken FROM' : $query;
		$result = octk_export_select( $this->args(), array(
			'batch'       => 2,
			'after_batch' => static function () use ( $break ) {
				add_filter( 'query', $break );
			},
		) );
		remove_filter( 'query', $break );

		$this->assertWPError( $result );
		$this->assertSame( 'octk_export_query', $result->get_error_code() );
		$this->assertSame( 0, $this->in_transaction(), 'COMMIT ran on the failure path' );
	}

	public function test_spool_open_failure_aborts_before_any_transaction() {
		$result = octk_export_select( $this->args(), array( 'open_spool' => static fn() => false ) );
		$this->assertWPError( $result );
		$this->assertSame( 'octk_export_spool', $result->get_error_code() );
		$this->assertSame( 0, $this->in_transaction() );
	}

	public function test_spool_write_failure_aborts_commits_and_closes() {
		if ( ! in_array( 'octkfull', stream_get_wrappers(), true ) ) {
			stream_wrapper_register( 'octkfull', OCTK_Full_Stream::class );
		}
		$handle = null;
		$result = octk_export_select( $this->args(), array(
			'open_spool' => static function () use ( &$handle ) {
				$handle = fopen( 'octkfull://spool', 'w+' );
				return $handle;
			},
		) );
		$this->assertWPError( $result );
		$this->assertSame( 'octk_export_spool', $result->get_error_code() );
		$this->assertSame( 0, $this->in_transaction() );
		$this->assertFalse( is_resource( $handle ), 'spool closed' );
	}

	public function test_failed_selection_streams_header_and_abort_row_only() {
		$break = static fn( $query ) => false !== strpos( $query, 'LIMIT' ) ? 'SELECT broken FROM' : $query;
		add_filter( 'query', $break );
		try {
			ob_start();
			octk_export_csv_stream( $this->args() );
			$csv = ob_get_clean();
		} finally {
			remove_filter( 'query', $break );
		}
		$lines = explode( "\n", trim( substr( $csv, 3 ) ) );
		$this->assertCount( 2, $lines );
		$this->assertStringContainsString( 'export aborted', $lines[1] );
	}

	/* ---- bounded memory ---- */

	public function test_large_long_url_export_keeps_memory_bounded_and_round_trips() {
		global $wpdb;
		$rows  = 3000;
		$pad   = str_repeat( 'x', 2000 );
		$links = octk_table_links();
		$now   = gmdate( 'Y-m-d H:i:s' );
		for ( $start = 0; $start < $rows; $start += 250 ) {
			$values = array();
			for ( $i = $start; $i < min( $rows, $start + 250 ); $i++ ) {
				$url      = sprintf( 'https://big.example/%05d/%s', $i, $pad );
				$values[] = $wpdb->prepare(
					'(UNHEX(%s), UNHEX(%s), %s, %d, %s, %s, %s, %s, %s)',
					bin2hex( random_bytes( 16 ) ),
					hash( 'sha256', $url . '|' . $i ),
					'post',
					$this->posts[0],
					'https://source.example/' . $pad,
					$url,
					'big.example',
					'Big ' . $i,
					$now
				);
			}
			$wpdb->query( "INSERT INTO {$links} (public_token, identity_hash, source_type, source_id, source_url, destination_url, destination_host, label, created_at_utc) VALUES " . implode( ',', $values ) );
		}
		$wpdb->query( $wpdb->prepare( "INSERT INTO %i (link_id, bucket_start_utc, hits) SELECT id, %s, 1 FROM {$links} WHERE destination_host = 'big.example'", octk_table_clicks(), '2026-08-03 10:00:00' ) );

		$args = $this->args( array( 'host' => 'big.example' ) );
		memory_reset_peak_usage();
		$before   = memory_get_usage();
		$selected = octk_export_select( $args, array( 'batch' => 100 ) );
		$growth   = memory_get_peak_usage() - $before;

		$this->assertTrue( $selected['snapshot'] );
		$this->assertFalse( $selected['truncated'] );
		// ~12 MB of URL text went through (3,000 rows x ~4 KB); a buffered selection would hold
		// all of it. Batches of 100 plus the 2 MB spool buffer stay far below.
		$this->assertLessThan( 6 * MB_IN_BYTES, $growth, 'selection memory must not grow with the row count' );

		$spool = $selected['spool'];
		$this->assertGreaterThan( 10 * MB_IN_BYTES, fstat( $spool )['size'] );

		// Format through a chunked output buffer that keeps nothing.
		$lines = 0;
		$tail  = '';
		ob_start( static function ( $chunk ) use ( &$lines, &$tail ) {
			$lines += substr_count( $chunk, "\n" );
			$tail   = substr( $tail . $chunk, -5000 );
			return '';
		}, 8192 );
		memory_reset_peak_usage();
		$before = memory_get_usage();
		octk_export_csv_write( $args, $selected );
		$growth = memory_get_peak_usage() - $before;
		ob_end_flush();

		$this->assertSame( $rows + 1, $lines, 'header plus every spooled row' );
		$this->assertLessThan( 16 * MB_IN_BYTES, $growth, 'formatting reads the spool back in batches' );
		$this->assertStringContainsString( 'https://big.example/', $tail );
		$this->assertStringContainsString( $pad, $tail, 'long URLs survive the spool intact' );
	}
}

/**
 * Stream wrapper that accepts the open and refuses every write, like a full
 * temp directory.
 */
class OCTK_Full_Stream {
	public $context;

	public function stream_open( $path, $mode, $options, &$opened_path ) {
		return true;
	}

	public function stream_write( $data ) {
		return 0;
	}

	public function stream_read( $count ) {
		return '';
	}

	public function stream_eof() {
		return true;
	}

	public function stream_close() {
	}

	public function stream_stat() {
		return array();
	}
}
