<?php

class OCTK_Settings_Test extends WP_UnitTestCase {

	public function test_internal_hosts_sanitizer_accepts_hostnames_only() {
		$input = "Amazon.com\nlinks.example.com\nhttps://evil.com/path\nbad host!\n' OR 1=1 --\nshop.example\n\nSHOP.EXAMPLE";
		$this->assertSame(
			array( 'amazon.com', 'links.example.com', 'shop.example' ),
			octk_sanitize_internal_hosts( $input )
		);
	}

	public function test_internal_hosts_sanitizer_is_idempotent_on_array_input() {
		// The Settings API sanitizes twice when an option is first created;
		// the second pass receives the first pass's array. It must survive.
		$first  = octk_sanitize_internal_hosts( "example.com\nshop.example.org" );
		$second = octk_sanitize_internal_hosts( $first );

		$this->assertSame( array( 'example.com', 'shop.example.org' ), $first );
		$this->assertSame( $first, $second );
		$this->assertNotContains( 'array', $second );
	}

	public function test_internal_hosts_survive_first_time_settings_api_save() {
		// End-to-end reproduction of the double-sanitize-on-create path.
		octk_register_settings();
		delete_option( 'octk_internal_hosts' );

		update_option( 'octk_internal_hosts', "example.com\nshop.example.org" );

		$this->assertSame( array( 'example.com', 'shop.example.org' ), get_option( 'octk_internal_hosts' ) );
	}

	public function test_status_page_denies_report_cap_users() {
		// Status must stay admin-only even when the report cap is remapped
		// down (as a site plugin may do) - it prints server versions.
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$filter = static fn () => 'edit_published_posts';
		add_filter( 'octk_report_capability', $filter );
		wp_set_current_user( $author );
		try {
			$this->expectException( 'WPDieException' );
			octk_render_status_page();
		} finally {
			remove_filter( 'octk_report_capability', $filter );
			wp_set_current_user( 0 );
		}
	}

	public function test_source_search_matches_published_posts_only() {
		$published = self::factory()->post->create( array(
			'post_title'  => 'OCTK Search Probe Published',
			'post_status' => 'publish',
		) );
		$draft     = self::factory()->post->create( array(
			'post_title'  => 'OCTK Search Probe Draft',
			'post_status' => 'draft',
		) );

		$previous_get = $_GET;
		$_GET         = array( 'search' => 'OCTK Search Probe', 'search_in' => 'source' );
		try {
			$request = octk_report_request();
		} finally {
			$_GET = $previous_get;
		}

		$this->assertContains( $published, $request['args']['source_ids'] );
		$this->assertNotContains( $draft, $request['args']['source_ids'] );
	}

	public function test_source_search_matches_titles_not_content_and_folds_dashes() {
		// The 0.3.x filter used WP_Query 's', which also matched post CONTENT;
		// on a real site nearly every post mentions common topical terms, so the
		// filter appeared to do nothing. Titles only now, with en/em dashes
		// folded so a pasted texturized title still matches.
		$title_hit   = self::factory()->post->create( array(
			'post_title'   => 'OCTK Dash Probe – Alpha',
			'post_content' => 'nothing relevant here',
		) );
		$content_hit = self::factory()->post->create( array(
			'post_title'   => 'Unrelated title',
			'post_content' => 'body mentions OCTK Dash Probe - Alpha in passing',
		) );

		$previous_get = $_GET;
		$_GET         = array( 'search' => 'OCTK Dash Probe - Alpha', 'search_in' => 'source' );
		try {
			$request = octk_report_request();
		} finally {
			$_GET = $previous_get;
		}

		$this->assertContains( $title_hit, $request['args']['source_ids'] );
		$this->assertNotContains( $content_hit, $request['args']['source_ids'] );
	}

	public function test_source_search_words_match_in_any_order() {
		$post = self::factory()->post->create( array(
			'post_title' => 'OCTK Gary Brecka Diet (2026) His Personal Routine',
		) );

		$previous_get = $_GET;
		try {
			$_GET    = array( 'search' => 'octk gary diet routine', 'search_in' => 'source' );
			$request = octk_report_request();
			$this->assertContains( $post, $request['args']['source_ids'], 'all words present, any order' );

			$_GET    = array( 'search' => 'octk gary diet nomatchword', 'search_in' => 'source' );
			$request = octk_report_request();
			$this->assertNotContains( $post, $request['args']['source_ids'], 'every word must match' );
		} finally {
			$_GET = $previous_get;
		}
	}

	public function test_source_id_pin_wins_over_source_search_but_combines_with_destination() {
		$post = self::factory()->post->create( array( 'post_title' => 'OCTK Pin Target' ) );

		$previous_get = $_GET;
		try {
			// Pin alone.
			$_GET    = array( 'source_id' => (string) $post );
			$request = octk_report_request();
			$this->assertSame( array( $post ), $request['args']['source_ids'] );
			$this->assertSame( $post, $request['source_id'] );

			// Pin beats a source-mode text search.
			$_GET    = array( 'source_id' => (string) $post, 'search' => 'something unrelated', 'search_in' => 'source' );
			$request = octk_report_request();
			$this->assertSame( array( $post ), $request['args']['source_ids'] );
			$this->assertFalse( $request['capped'] );

			// Pin combines with a destination-mode search.
			$_GET    = array( 'source_id' => (string) $post, 'search' => 'amazon', 'search_in' => 'destination' );
			$request = octk_report_request();
			$this->assertSame( array( $post ), $request['args']['source_ids'] );
			$this->assertSame( 'amazon', $request['args']['destination_search'] );

			// Garbage pin values fall back to no pin.
			$_GET    = array( 'source_id' => '-5' );
			$request = octk_report_request();
			$this->assertSame( 0, $request['source_id'] );
			$this->assertNull( $request['args']['source_ids'] );
		} finally {
			$_GET = $previous_get;
		}
	}

	public function test_destination_search_mode_sets_destination_arg_only() {
		$previous_get = $_GET;
		$_GET         = array( 'search' => 'Brand – Widget', 'search_in' => 'destination' );
		try {
			$request = octk_report_request();
		} finally {
			$_GET = $previous_get;
		}

		$this->assertSame( 'Brand – Widget', $request['args']['destination_search'], 'raw needle; reporting folds both sides' );
		$this->assertNull( $request['args']['source_ids'] );
		$this->assertSame( 'destination', $request['search_in'] );
	}

	public function test_roles_sanitizer_drops_unknown_roles() {
		$this->assertSame(
			array( 'administrator', 'editor' ),
			octk_sanitize_roles( array( 'administrator', 'editor', 'superhacker', array( 'x' ) ) )
		);
		$this->assertSame( array(), octk_sanitize_roles( 'not-an-array' ) );
	}

	public function test_report_capability_default_and_filterable() {
		$this->assertSame( 'manage_options', octk_report_capability() );

		$filter = static fn() => 'edit_others_posts';
		add_filter( 'octk_report_capability', $filter );
		$this->assertSame( 'edit_others_posts', octk_report_capability() );
		remove_filter( 'octk_report_capability', $filter );
	}

	public function test_excluded_role_user_gets_no_listener() {
		update_option( 'octk_enabled', '1' );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->assertTrue( octk_user_is_excluded() );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$this->assertFalse( octk_user_is_excluded() );

		wp_set_current_user( 0 );
		$this->assertFalse( octk_user_is_excluded() );
	}

	public function test_retention_months_clamped() {
		update_option( 'octk_retention_months', 999 );
		$this->assertSame( 120, octk_retention_months() );
		update_option( 'octk_retention_months', -3 );
		$this->assertSame( 1, octk_retention_months() );
		update_option( 'octk_retention_months', 24 );
	}
}
