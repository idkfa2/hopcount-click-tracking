<?php

class OCTK_Registration_Test extends WP_UnitTestCase {

	private $post_id;

	public function set_up() {
		parent::set_up();
		OCTK_Registry::reset_memo();
		$this->post_id = self::factory()->post->create();
	}

	private function dest( string $url ): array {
		$n = octk_normalize_destination( $url );
		$this->assertNotNull( $n );
		return $n;
	}

	public function test_registers_and_returns_tokens() {
		$dests  = array( $this->dest( 'https://m1.example/a?aff=1' ), $this->dest( 'https://m2.example/b' ) );
		$tokens = OCTK_Registry::tokens_for( 'post', $this->post_id, $dests );

		$this->assertCount( 2, $tokens );
		foreach ( $tokens as $token ) {
			$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $token );
		}

		global $wpdb;
		$table = octk_table_links();
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE source_type = 'post' AND source_id = %d", $this->post_id )
		);
		$this->assertSame( 2, $count );
	}

	public function test_reregistration_reuses_stable_token() {
		$dest  = array( $this->dest( 'https://m1.example/a' ) );
		$first = OCTK_Registry::tokens_for( 'post', $this->post_id, $dest );

		OCTK_Registry::reset_memo(); // Simulate a fresh request.
		$second = OCTK_Registry::tokens_for( 'post', $this->post_id, $dest );

		$this->assertSame( $first, $second );
	}

	public function test_same_destination_on_two_posts_gets_distinct_identities() {
		$other = self::factory()->post->create();
		$dest  = array( $this->dest( 'https://m1.example/a' ) );

		$a = OCTK_Registry::tokens_for( 'post', $this->post_id, $dest );
		$b = OCTK_Registry::tokens_for( 'post', $other, $dest );

		$this->assertNotSame( array_values( $a ), array_values( $b ) );
	}

	public function test_invariant_failure_is_fail_soft_never_wrong_token() {
		global $wpdb;

		$dest   = $this->dest( 'https://m1.example/a' );
		$tokens = OCTK_Registry::tokens_for( 'post', $this->post_id, array( $dest ) );
		$this->assertCount( 1, $tokens );

		// Corrupt the stored destination behind the same identity hash.
		$table = octk_table_links();
		$hash  = bin2hex( octk_identity_hash( 'post', $this->post_id, $dest['url'] ) );
		$wpdb->query(
			$wpdb->prepare( "UPDATE {$table} SET destination_url = 'https://evil.example/other' WHERE identity_hash = UNHEX(%s)", $hash )
		);

		OCTK_Registry::reset_memo();
		$tokens = OCTK_Registry::tokens_for( 'post', $this->post_id, array( $dest ) );
		$this->assertSame( array(), $tokens );
	}

	public function test_invariant_failure_on_corrupted_source_type_is_fail_soft() {
		global $wpdb;

		$dest   = $this->dest( 'https://m1.example/a' );
		$tokens = OCTK_Registry::tokens_for( 'post', $this->post_id, array( $dest ) );
		$this->assertCount( 1, $tokens );

		// Corrupt the stored source_type behind the same identity hash.
		$table = octk_table_links();
		$hash  = bin2hex( octk_identity_hash( 'post', $this->post_id, $dest['url'] ) );
		$wpdb->query(
			$wpdb->prepare( "UPDATE {$table} SET source_type = 'corrupt' WHERE identity_hash = UNHEX(%s)", $hash )
		);

		OCTK_Registry::reset_memo();
		$tokens = OCTK_Registry::tokens_for( 'post', $this->post_id, array( $dest ) );
		$this->assertSame( array(), $tokens );
	}

	public function test_invariant_failure_on_corrupted_source_id_is_fail_soft() {
		global $wpdb;

		$dest   = $this->dest( 'https://m1.example/a' );
		$tokens = OCTK_Registry::tokens_for( 'post', $this->post_id, array( $dest ) );
		$this->assertCount( 1, $tokens );

		$table = octk_table_links();
		$hash  = bin2hex( octk_identity_hash( 'post', $this->post_id, $dest['url'] ) );
		$wpdb->query(
			$wpdb->prepare( "UPDATE {$table} SET source_id = %d WHERE identity_hash = UNHEX(%s)", $this->post_id + 999, $hash )
		);

		OCTK_Registry::reset_memo();
		$tokens = OCTK_Registry::tokens_for( 'post', $this->post_id, array( $dest ) );
		$this->assertSame( array(), $tokens );
	}

	public function test_race_insert_between_preload_and_insert_reuses_existing_row() {
		global $wpdb;

		// Warm the memo for this source with destination X.
		OCTK_Registry::tokens_for( 'post', $this->post_id, array( $this->dest( 'https://m1.example/x' ) ) );

		// Another request registers destination Y first (after our preload).
		$dest_y    = $this->dest( 'https://m1.example/y' );
		$token_y   = bin2hex( random_bytes( 16 ) );
		$hash_y    = bin2hex( octk_identity_hash( 'post', $this->post_id, $dest_y['url'] ) );
		$table     = octk_table_links();
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (public_token, identity_hash, source_type, source_id, source_url, destination_url, destination_host, created_at_utc) VALUES (UNHEX(%s), UNHEX(%s), 'post', %d, '', %s, %s, %s)",
				$token_y,
				$hash_y,
				$this->post_id,
				$dest_y['url'],
				$dest_y['host'],
				gmdate( 'Y-m-d H:i:s' )
			)
		);

		// Our insert hits the duplicate identity key; the reselect path must
		// reuse the winner's row, not fail and not duplicate.
		$tokens = OCTK_Registry::tokens_for( 'post', $this->post_id, array( $dest_y ) );

		$this->assertSame( $token_y, $tokens[ $dest_y['url'] ] );
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE identity_hash = UNHEX(%s)", $hash_y )
		);
		$this->assertSame( 1, $count );
	}

	public function test_forced_token_collision_retries_with_fresh_token() {
		global $wpdb;

		// Existing identity on another post holds token T.
		$other    = self::factory()->post->create();
		$existing = OCTK_Registry::tokens_for( 'post', $other, array( $this->dest( 'https://m9.example/z' ) ) );
		$taken    = array_values( $existing )[0];

		// Force the first generated token to collide with T, then go random.
		$queue  = array( $taken );
		$filter = static function ( $value ) use ( &$queue ) {
			return array_shift( $queue );
		};
		add_filter( 'octk_public_token_hex', $filter );

		$dest   = $this->dest( 'https://m1.example/new' );
		$tokens = OCTK_Registry::tokens_for( 'post', $this->post_id, array( $dest ) );

		remove_filter( 'octk_public_token_hex', $filter );

		$this->assertCount( 1, $tokens );
		$this->assertNotSame( $taken, $tokens[ $dest['url'] ] );

		$table = octk_table_links();
		$hash  = bin2hex( octk_identity_hash( 'post', $this->post_id, $dest['url'] ) );
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE identity_hash = UNHEX(%s)", $hash )
		);
		$this->assertSame( 1, $count );
	}

	public function test_duplicate_concurrent_style_insert_converges_to_one_row() {
		global $wpdb;

		$dest = array( $this->dest( 'https://m1.example/a' ) );

		// Two "requests" with cold memos register the same identity.
		OCTK_Registry::tokens_for( 'post', $this->post_id, $dest );
		OCTK_Registry::reset_memo();
		OCTK_Registry::tokens_for( 'post', $this->post_id, $dest );

		$table = octk_table_links();
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE source_id = %d", $this->post_id )
		);
		$this->assertSame( 1, $count );
	}
}
