<?php

class OCTK_Endpoint_Test extends WP_UnitTestCase {

	private $server;

	public function set_up() {
		parent::set_up();
		OCTK_Registry::reset_memo();
		update_option( 'octk_enabled', '1' );

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init', $wp_rest_server );
	}

	private function register_token(): string {
		$post_id = self::factory()->post->create();
		$dest    = octk_normalize_destination( 'https://merchant.example/deal?aff=1' );
		$tokens  = OCTK_Registry::tokens_for( 'post', $post_id, array( $dest ) );
		$this->assertCount( 1, $tokens );
		return array_values( $tokens )[0];
	}

	private function post_click( $token ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/octk/v1/click' );
		if ( null !== $token ) {
			$request->set_body_params( array( 'token' => $token ) );
		}
		return $this->server->dispatch( $request );
	}

	private function bucket_hits( string $token ): ?int {
		global $wpdb;
		$links  = octk_table_links();
		$clicks = octk_table_clicks();
		$hits   = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT c.hits FROM {$clicks} c JOIN {$links} l ON l.id = c.link_id WHERE l.public_token = UNHEX(%s)",
				$token
			)
		);
		return null === $hits ? null : (int) $hits;
	}

	public function test_valid_token_increments_hourly_bucket() {
		$token = $this->register_token();

		$response = $this->post_click( $token );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $this->bucket_hits( $token ) );

		$this->post_click( $token );
		$this->assertSame( 2, $this->bucket_hits( $token ) );
	}

	public function test_malformed_tokens_rejected_by_schema() {
		foreach ( array( 'short', str_repeat( 'g', 32 ), str_repeat( 'A', 32 ), '', array( 'x' ) ) as $bad ) {
			$response = $this->post_click( $bad );
			$this->assertSame( 400, $response->get_status() );
		}
		$response = $this->post_click( null );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_unknown_token_404_and_writes_nothing() {
		global $wpdb;

		$response = $this->post_click( str_repeat( 'ab', 16 ) );
		$this->assertSame( 404, $response->get_status() );

		$clicks = octk_table_clicks();
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$clicks}" ) );
		$links = octk_table_links();
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$links}" ) );
	}

	public function test_disabled_switch_rejects_known_token() {
		$token = $this->register_token();
		update_option( 'octk_enabled', '0' );

		$response = $this->post_click( $token );

		$this->assertSame( 403, $response->get_status() );
		$this->assertNull( $this->bucket_hits( $token ) );
	}

	public function test_rate_limiter_hook_can_reject() {
		$token  = $this->register_token();
		$filter = static function () {
			return false;
		};
		add_filter( 'octk_click_allowed', $filter );

		$response = $this->post_click( $token );

		remove_filter( 'octk_click_allowed', $filter );
		$this->assertSame( 429, $response->get_status() );
		$this->assertNull( $this->bucket_hits( $token ) );
	}

	public function test_hourly_bucket_boundaries_utc() {
		// 13:59:59Z and 14:00:00Z land in different buckets; DST never applies to UTC.
		$before = gmmktime( 13, 59, 59, 8, 10, 2026 );
		$at     = gmmktime( 14, 0, 0, 8, 10, 2026 );
		$late   = gmmktime( 14, 59, 59, 8, 10, 2026 );

		$this->assertSame( '2026-08-10 13:00:00', octk_bucket_for( $before ) );
		$this->assertSame( '2026-08-10 14:00:00', octk_bucket_for( $at ) );
		$this->assertSame( '2026-08-10 14:00:00', octk_bucket_for( $late ) );
	}

	public function test_rapid_repeat_increments_lose_nothing() {
		$token = $this->register_token();
		for ( $i = 0; $i < 25; $i++ ) {
			$this->post_click( $token );
		}
		$this->assertSame( 25, $this->bucket_hits( $token ) );
	}

	public function test_get_method_not_routable() {
		$request  = new WP_REST_Request( 'GET', '/octk/v1/click' );
		$response = $this->server->dispatch( $request );
		$this->assertContains( $response->get_status(), array( 404, 405 ) );
	}
}
