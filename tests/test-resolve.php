<?php
/**
 * Same-site redirect resolution (resolve.php). Every HTTP request is stubbed
 * through pre_http_request; no probe ever leaves the process.
 */

class OCTK_Resolve_Test extends WP_UnitTestCase {

	/** @var array<int, array{url: string, args: array}> */
	private $requests = array();

	/** @var callable|null url => response array|WP_Error */
	private $responder = null;

	private $stub;

	/** @var string home_url() without trailing slash, e.g. http://localhost:8889 */
	private $home;

	private function u( string $path ): string {
		return $this->home . $path;
	}

	public function set_up() {
		parent::set_up();
		global $wpdb;
		$this->home = untrailingslashit( home_url() );
		update_option( 'octk_probe_origin_next_try', time() + HOUR_IN_SECONDS ); // no route discovery inside tests unless forced
		OCTK_Registry::reset_memo();
		OCTK_Resolver::reset_memo();
		wp_cache_flush();
		update_option( 'octk_enabled', '1' );
		delete_transient( 'octk_resolve_down' );
		delete_option( 'octk_probe_origin' );
		delete_option( 'octk_resolve_backfill' );
		delete_option( 'octk_resolve_daily_' . gmdate( 'Ymd' ) );
		update_option( 'octk_resolve_generation', 1 );
		octk_resolve_ensure_secret();
		$wpdb->query( 'DELETE FROM ' . octk_table_redirects() );
		$wpdb->query( 'DELETE FROM ' . octk_table_redirect_sources() );
		$this->requests  = array();
		$this->responder = null;
		$this->stub      = function ( $pre, $args, $url ) {
			$this->requests[] = array( 'url' => $url, 'args' => $args );
			$this->assertLessThanOrEqual( 2.0, (float) $args['timeout'], 'probe timeout must never exceed 2 s' );
			$responder = $this->responder;
			return $responder ? $responder( $url, $args ) : self::response( 200 );
		};
		add_filter( 'pre_http_request', $this->stub, 10, 3 );
		_unregister_post_type( 'octk_probe' );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', $this->stub, 10 );
		wp_unschedule_hook( OCTK_RESOLVE_HOOK );
		parent::tear_down();
	}

	/* ---- helpers ---- */

	public static function response( int $code, array $headers = array() ): array {
		return array(
			'response' => array( 'code' => $code, 'message' => '' ),
			'headers'  => $headers,
			'body'     => '',
			'cookies'  => array(),
		);
	}

	private function respond_with( array $map, $default = null ): void {
		$this->responder = static function ( string $url ) use ( $map, $default ) {
			$path = wp_parse_url( $url, PHP_URL_PATH ) . ( wp_parse_url( $url, PHP_URL_QUERY ) ? '?' . wp_parse_url( $url, PHP_URL_QUERY ) : '' );
			if ( isset( $map[ $url ] ) ) {
				return $map[ $url ];
			}
			if ( isset( $map[ $path ] ) ) {
				return $map[ $path ];
			}
			return $default ?? self::response( 200 );
		};
	}

	private function render_post_content( int $post_id ): string {
		OCTK_Registry::reset_memo();
		OCTK_Resolver::reset_memo();
		$this->go_to( get_permalink( $post_id ) );
		$this->assertTrue( is_singular() );
		$output = '';
		while ( have_posts() ) {
			the_post();
			$output = apply_filters( 'the_content', get_the_content() );
		}
		return $output;
	}

	private function row( string $logical ): ?array {
		return OCTK_Resolver::row( octk_resolve_hash( $logical ), true );
	}

	private function set_row( string $logical, array $fields ): void {
		global $wpdb;
		octk_resolve_ensure_row( $logical );
		$sets   = array();
		$values = array();
		foreach ( $fields as $k => $v ) {
			$sets[]   = "{$k} = " . ( null === $v ? 'NULL' : '%s' );
			if ( null !== $v ) {
				$values[] = $v;
			}
		}
		$values[] = octk_resolve_hash( $logical );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . octk_table_redirects() . ' SET ' . implode( ', ', $sets ) . ' WHERE url_hash = UNHEX(%s)', $values ) );
		OCTK_Resolver::forget( octk_resolve_hash( $logical ) );
	}

	private function links_row_for_destination( string $url ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT destination_url, destination_host FROM ' . octk_table_links() . ' WHERE destination_url = %s', $url ) );
	}

	private function past( int $seconds = 60 ): string {
		return gmdate( 'Y-m-d H:i:s', time() - $seconds );
	}

	private function future( int $seconds = 3600 ): string {
		return gmdate( 'Y-m-d H:i:s', time() + $seconds );
	}

	private function outbound( string $to ): array {
		return self::response( 301, array( 'location' => $to ) );
	}

	/* ---- logical URLs ---- */

	public function test_logical_url_shapes() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$this->assertSame( $this->u( '/go/x?a=1' ), octk_same_site_logical_url( '/go/x?a=1#frag' ) );
		$this->assertSame( $this->u( '/go/x' ), octk_same_site_logical_url( $this->u( '/go/x' ) ) );
		$this->assertSame( 'http://www.' . $host . '/go/x', octk_same_site_logical_url( 'http://www.' . $host . '/go/x' ) );
		$this->assertNull( octk_same_site_logical_url( '//' . $host . '/x' ) );
		$this->assertNull( octk_same_site_logical_url( 'https://merchant.example/x' ) );
		$this->assertNull( octk_same_site_logical_url( 'mailto:a@b.c' ) );
		$this->assertNull( octk_same_site_logical_url( '#top' ) );
	}

	public function test_subdirectory_install_keeps_path_as_written() {
		$home = $this->home;
		$sub  = static fn( $url ) => $home . '/blog';
		add_filter( 'home_url', $sub );
		try {
			$this->assertSame( $this->u( '/offer' ), octk_same_site_logical_url( '/offer' ) );
		} finally {
			remove_filter( 'home_url', $sub );
		}
	}

	public function test_configured_internal_host_never_probed() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		update_option( 'octk_internal_hosts', array( 'www.' . $host ) );
		try {
			$this->assertNull( octk_same_site_logical_url( 'http://www.' . $host . '/go/x' ) );
		} finally {
			delete_option( 'octk_internal_hosts' );
		}
	}

	/* ---- render path ---- */

	public function test_relative_href_with_offsite_301_is_tracked_under_destination() {
		$this->respond_with( array( '/cocoavia' => $this->outbound( 'https://www.amazon.com/dp/B09?tag=x-20' ) ) );
		$post_id = self::factory()->post->create( array( 'post_content' => '<a href="/cocoavia">Buy</a> <a href="/about/">About</a>' ) );

		$html = $this->render_post_content( $post_id );

		$this->assertSame( 1, substr_count( $html, 'data-octk-token' ) );
		$this->assertMatchesRegularExpression( '/<a(?=[^>]*href="\/cocoavia")(?=[^>]*data-octk-token="[0-9a-f]{32}")[^>]*>/', $html );
		$row = $this->links_row_for_destination( 'https://www.amazon.com/dp/B09?tag=x-20' );
		$this->assertNotNull( $row );
		$this->assertSame( 'www.amazon.com', $row->destination_host );
		$this->assertSame( 'outbound', $this->row( $this->u( '/cocoavia' ) )['verdict'] );
		$this->assertSame( 'internal', $this->row( $this->u( '/about/' ) )['verdict'] );
		$this->assertCount( 2, $this->requests );
		$this->assertSame( 'HEAD', $this->requests[0]['args']['method'] );
		$this->assertSame( 0, (int) $this->requests[0]['args']['redirection'] );
		$this->assertMatchesRegularExpression( '/^\d+\.public\.[0-9a-f]{64}$/', $this->requests[0]['args']['headers']['X-Octk-Resolve'] );
	}

	public function test_absolute_home_host_href_with_302_is_tracked() {
		$this->respond_with( array( '/deal' => self::response( 302, array( 'location' => 'https://shop.example/x' ) ) ) );
		$post_id = self::factory()->post->create( array( 'post_content' => '<a href=\"' . $this->u( '/deal' ) . '\">Deal</a>' ) );
		$html    = $this->render_post_content( $post_id );
		$this->assertStringContainsString( 'data-octk-token', $html );
		$this->assertNotNull( $this->links_row_for_destination( 'https://shop.example/x' ) );
	}

	public function test_external_chain_not_followed_first_external_is_destination() {
		$this->respond_with( array( '/x' => $this->outbound( 'https://a.example/1' ), 'https://a.example/1' => $this->outbound( 'https://b.example/2' ) ) );
		$post_id = self::factory()->post->create( array( 'post_content' => '<a href="/x">x</a>' ) );
		$this->render_post_content( $post_id );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'https://a.example/1', $this->row( $this->u( '/x' ) )['destination_url'] );
	}

	public function test_two_onsite_hops_then_offsite_is_outbound_four_is_unknown() {
		$this->respond_with( array(
			'/a' => $this->outbound( '/b' ),
			'/b' => $this->outbound( $this->u( '/c' ) ),
			'/c' => $this->outbound( 'https://m.example/z' ),
			'/p' => $this->outbound( '/q' ),
			'/q' => $this->outbound( '/r' ),
			'/r' => $this->outbound( '/s' ),
			'/s' => $this->outbound( '/t' ),
		) );
		$result = octk_probe( $this->u( '/a' ) );
		$this->assertSame( 'outbound', $result['verdict'] );
		$this->assertSame( 'https://m.example/z', $result['destination_url'] );
		$this->assertSame( 3, $result['requests'] );

		$this->requests = array();
		$result         = octk_probe( $this->u( '/p' ) );
		$this->assertSame( 'unknown', $result['verdict'] );
		$this->assertSame( 3, $result['requests'] );
		$this->assertSame( 'hop limit reached', $result['error'] );
	}

	public function test_relative_location_resolved_against_request_url() {
		$this->respond_with( array( '/go/x' => self::response( 301, array( 'location' => 'y' ) ), '/go/y' => $this->outbound( 'https://m.example/' ) ) );
		$result = octk_probe( $this->u( '/go/x' ) );
		$this->assertSame( 'outbound', $result['verdict'] );
		$this->assertSame( $this->u( '/go/y' ), $this->requests[1]['url'] );
	}

	public function test_verdict_classification_and_ttls() {
		$this->respond_with( array(
			'/ok'   => self::response( 200 ),
			'/gone' => self::response( 404 ),
			'/on'   => $this->outbound( '/ok' ),
			'/m'    => self::response( 405 ),
			'/down' => self::response( 503 ),
		) );
		foreach ( array( 'ok', 'gone', 'on' ) as $p ) {
			$this->assertSame( 'internal', octk_probe( $this->u( '/' ) . $p )['verdict'], $p );
		}
		foreach ( array( 'm', 'down' ) as $p ) {
			$this->assertSame( 'unknown', octk_probe( $this->u( '/' ) . $p )['verdict'], $p );
		}
		$this->assertFalse( octk_resolve_breaker_tripped(), 'HTTP errors do not trip the breaker' );
		$this->assertSame( 7 * DAY_IN_SECONDS, octk_resolve_ttl( 'internal' ) );
		$this->assertSame( HOUR_IN_SECONDS, octk_resolve_ttl( 'unknown', 1 ) );
		$this->assertSame( 2 * HOUR_IN_SECONDS, octk_resolve_ttl( 'unknown', 2 ) );
		$this->assertSame( DAY_IN_SECONDS, octk_resolve_ttl( 'unknown', 9 ) );
	}

	public function test_transport_error_trips_breaker_until_clear() {
		$this->responder = static fn() => new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
		$post_id         = self::factory()->post->create( array( 'post_content' => '<a href="/x">x</a>' ) );
		$this->render_post_content( $post_id );
		$this->assertCount( 1, $this->requests );
		$this->assertTrue( octk_resolve_breaker_tripped() );
		$this->assertSame( 'unknown', $this->row( $this->u( '/x' ) )['verdict'] );

		$this->requests = array();
		$this->render_post_content( $post_id );
		$this->set_row( $this->u( '/x' ), array( 'next_check_utc' => $this->past() ) );
		octk_resolve_run();
		$this->assertCount( 0, $this->requests, 'no requests while the breaker is tripped' );

		octk_resolve_clear();
		update_option( 'octk_probe_origin_next_try', time() + HOUR_IN_SECONDS ); // Clear re-arms route discovery; keep it out of the count.
		$this->assertFalse( octk_resolve_breaker_tripped() );
		$this->responder = null;
		octk_resolve_run();
		$this->assertCount( 1, $this->requests );
	}

	public function test_pre_resolution_filter_skips_http() {
		$filter = static function ( $pre, string $url ) {
			if ( str_ends_with( $url, '/site' ) ) {
				return array( 'verdict' => 'outbound', 'destination_url' => 'https://m.example/via-filter' );
			}
			if ( str_ends_with( $url, '/home' ) ) {
				return array( 'verdict' => 'internal' );
			}
			return $pre;
		};
		add_filter( 'octk_pre_resolve_same_site', $filter, 10, 2 );
		try {
			$post_id = self::factory()->post->create( array( 'post_content' => '<a href="/site">s</a> <a href="/home">h</a>' ) );
			$html    = $this->render_post_content( $post_id );
		} finally {
			remove_filter( 'octk_pre_resolve_same_site', $filter, 10 );
		}
		$this->assertCount( 0, $this->requests );
		$this->assertSame( 1, substr_count( $html, 'data-octk-token' ) );
		$this->assertNotNull( $this->links_row_for_destination( 'https://m.example/via-filter' ) );
	}

	public function test_confirmed_row_hit_makes_no_request_generation_bump_reprobes() {
		$this->respond_with( array( '/x' => $this->outbound( 'https://m.example/1' ) ) );
		$post_id = self::factory()->post->create( array( 'post_content' => '<a href="/x">x</a>' ) );
		$this->render_post_content( $post_id );
		$this->assertCount( 1, $this->requests );

		$this->requests = array();
		$this->render_post_content( $post_id );
		octk_resolve_run();
		$this->assertCount( 0, $this->requests );

		update_option( 'octk_resolve_generation', 2 );
		octk_resolve_run();
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 2, (int) $this->row( $this->u( '/x' ) )['generation'] );
	}

	public function test_render_budget_leaves_fourth_pending_for_cron() {
		$this->respond_with( array(), $this->outbound( 'https://m.example/' ) );
		$post_id = self::factory()->post->create( array( 'post_content' => '<a href="/1">1</a><a href="/2">2</a><a href="/3">3</a><a href="/4">4</a>' ) );
		$html    = $this->render_post_content( $post_id );
		$this->assertCount( 3, $this->requests );
		$this->assertSame( 3, substr_count( $html, 'data-octk-token' ) );
		$this->assertSame( 'pending', $this->row( $this->u( '/4' ) )['verdict'] );
		$this->assertStringNotContainsString( 'href="/4" data-octk-token', $html );

		$this->requests = array();
		octk_resolve_run();
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'outbound', $this->row( $this->u( '/4' ) )['verdict'] );

		$this->requests = array();
		$html           = $this->render_post_content( $post_id );
		$this->assertCount( 0, $this->requests );
		$this->assertSame( 4, substr_count( $html, 'data-octk-token' ) );
	}

	/* ---- signatures and the short-circuit ---- */

	private function with_server( array $vars, callable $fn ) {
		$saved = $_SERVER;
		foreach ( $vars as $k => $v ) {
			$_SERVER[ $k ] = $v;
		}
		try {
			return $fn();
		} finally {
			$_SERVER = $saved;
		}
	}

	public function test_signed_origin_probe_from_self_is_blank_otherwise_render() {
		$header = octk_probe_header_value( 'https://example.org/go/x?a=1', 'origin' );
		$base   = array( 'HTTP_HOST' => 'example.org', 'REQUEST_URI' => '/go/x?a=1', 'HTTP_X_OCTK_RESOLVE' => $header, 'SERVER_ADDR' => '10.0.0.5' );

		$this->assertSame( 'blank', $this->with_server( array_merge( $base, array( 'REMOTE_ADDR' => '10.0.0.5' ) ), 'octk_probe_short_circuit_decision' ) );
		$this->assertSame( 'blank', $this->with_server( array_merge( $base, array( 'REMOTE_ADDR' => '127.0.0.1' ) ), 'octk_probe_short_circuit_decision' ) );
		$this->assertSame( 'render', $this->with_server( array_merge( $base, array( 'REMOTE_ADDR' => '203.0.113.9' ) ), 'octk_probe_short_circuit_decision' ) );

		// http transport of an https logical URL, X-Forwarded-Proto set: scheme is not signed.
		$this->assertSame( 'blank', $this->with_server( array_merge( $base, array( 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTPS' => '' ) ), 'octk_probe_short_circuit_decision' ) );
		// Port in Host is ignored for the identity.
		$this->assertSame( 'blank', $this->with_server( array_merge( $base, array( 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'example.org:8443' ) ), 'octk_probe_short_circuit_decision' ) );

		$public = octk_probe_header_value( 'https://example.org/go/x?a=1', 'public' );
		// Restored client IP equal to the server: signed via=public still renders.
		$this->assertSame( 'render', $this->with_server( array_merge( $base, array( 'REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_OCTK_RESOLVE' => $public ) ), 'octk_probe_short_circuit_decision' ) );
		$this->assertTrue( OCTK_Resolver::$request_disabled );
	}

	public function test_invalid_headers_are_ignored_entirely() {
		$header = octk_probe_header_value( 'https://example.org/go/x', 'origin' );
		$base   = array( 'HTTP_HOST' => 'example.org', 'REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1' );

		$this->assertNull( $this->with_server( array_merge( $base, array( 'REQUEST_URI' => '/go/other', 'HTTP_X_OCTK_RESOLVE' => $header ) ), 'octk_probe_short_circuit_decision' ), 'different path' );
		$this->assertNull( $this->with_server( array_merge( $base, array( 'REQUEST_URI' => '/go/x', 'HTTP_X_OCTK_RESOLVE' => '1', 'HTTP_X_OCTK_PROBE_VIA' => 'origin' ) ), 'octk_probe_short_circuit_decision' ), 'unsigned' );
		$this->assertNull( $this->with_server( array_merge( $base, array( 'REQUEST_URI' => '/go/x' ) ), 'octk_probe_short_circuit_decision' ), 'absent' );

		$parts = explode( '.', $header );
		$old   = ( (int) $parts[0] - 600 );
		$stale = $old . '.origin.' . octk_probe_sign( $old, 'origin', 'example.org', '/go/x' );
		$this->assertNull( $this->with_server( array_merge( $base, array( 'REQUEST_URI' => '/go/x', 'HTTP_X_OCTK_RESOLVE' => $stale ) ), 'octk_probe_short_circuit_decision' ), 'expired' );

		$other = $parts[0] . '.public.' . $parts[2];
		$this->assertNull( $this->with_server( array_merge( $base, array( 'REQUEST_URI' => '/go/x', 'HTTP_X_OCTK_RESOLVE' => $other ) ), 'octk_probe_short_circuit_decision' ), 'via swapped' );
		$this->assertFalse( OCTK_Resolver::$request_disabled );
	}

	public function test_missing_secret_disables_everything() {
		delete_option( 'octk_resolve_secret' );
		$this->assertFalse( octk_resolve_enabled() );
		$this->assertSame( '', octk_probe_header_value( 'https://example.org/x', 'origin' ) );
		$this->assertNull( $this->with_server( array( 'HTTP_HOST' => 'example.org', 'REQUEST_URI' => '/x', 'HTTP_X_OCTK_RESOLVE' => time() . '.origin.' . str_repeat( 'a', 64 ) ), 'octk_verify_probe_header' ) );

		$post_id = self::factory()->post->create( array( 'post_content' => '<a href="/x">x</a>' ) );
		$html    = $this->render_post_content( $post_id );
		$this->assertCount( 0, $this->requests );
		$this->assertStringNotContainsString( 'data-octk-token', $html );

		// The migration recreates it without reactivation.
		$this->assertTrue( octk_migration_3() );
		$this->assertTrue( octk_resolve_enabled() );
	}

	public function test_kill_switch_filter_restores_111_behaviour() {
		add_filter( 'octk_resolve_enabled', '__return_false' );
		try {
			$this->assertNull( octk_normalize_destination( '/relative/path' ) );
			$post_id = self::factory()->post->create( array( 'post_content' => '<a href="/x">x</a> <a href="https://m.example/y">y</a>' ) );
			$html    = $this->render_post_content( $post_id );
		} finally {
			remove_filter( 'octk_resolve_enabled', '__return_false' );
		}
		$this->assertCount( 0, $this->requests );
		$this->assertSame( 1, substr_count( $html, 'data-octk-token' ) );
	}

	public function test_head_request_never_probes_from_render() {
		$post_id = self::factory()->post->create( array( 'post_content' => '<a href="/x">x</a>' ) );
		$this->with_server( array( 'REQUEST_METHOD' => 'HEAD' ), fn() => $this->render_post_content( $post_id ) );
		$this->assertCount( 0, $this->requests );
		$this->assertSame( 'pending', $this->row( $this->u( '/x' ) )['verdict'] );
	}

	/* ---- origin route ---- */

	public function test_origin_selftest_and_transport_url() {
		$explicit = static fn() => array( 'scheme' => 'http', 'host' => '10.0.0.5', 'port' => 80 );
		add_filter( 'octk_resolve_probe_origin', $explicit );
		add_filter( 'octk_resolve_selftest_trust', '__return_true' );
		$this->responder = static function ( string $url, array $args ) {
			if ( str_contains( $url, 'probe-selftest' ) ) {
				return self::response( 204, array( 'x-octk-selftest' => octk_selftest_signature( $args['headers']['X-Octk-Resolve'], 'http' ) ) );
			}
			return self::response( 200 );
		};
		try {
			$route = octk_probe_origin_ensure( true );
		} finally {
			remove_filter( 'octk_resolve_probe_origin', $explicit );
			remove_filter( 'octk_resolve_selftest_trust', '__return_true' );
		}
		$this->assertSame( array( 'scheme' => 'http', 'host' => '10.0.0.5', 'port' => 80, 'sslverify' => true ), $route );
		$this->assertSame( $route, get_option( 'octk_probe_origin' ) );
		$this->assertCount( 1, $this->requests, 'explicit candidate tried first and accepted' );
		$this->assertSame( set_url_scheme( rest_url( 'octk/v1/probe-selftest' ), 'http' ), $this->requests[0]['url'] );

		// Now probe an https logical URL through it: http transport, forwarded proto, signed.
		$this->requests = array();
		$this->responder = static fn() => self::response( 200 );
		$response = octk_probe_request( 'https://example.org/go/x', 'origin', $route, 2.0 );
		$this->assertSame( 200, wp_remote_retrieve_response_code( $response ) );
		$this->assertSame( 'http://example.org/go/x', $this->requests[0]['url'] );
		$this->assertSame( 'https', $this->requests[0]['args']['headers']['X-Forwarded-Proto'] );
		$this->assertMatchesRegularExpression( '/^\d+\.origin\./', $this->requests[0]['args']['headers']['X-Octk-Resolve'] );
	}

	public function test_origin_selftest_rejects_unsigned_answer_and_reports_fallback() {
		add_filter( 'octk_resolve_selftest_trust', '__return_true' );
		$this->responder = static fn() => self::response( 204 );
		try {
			$this->assertNull( octk_probe_origin_ensure( true ) );
		} finally {
			remove_filter( 'octk_resolve_selftest_trust', '__return_true' );
		}
		$this->assertFalse( get_option( 'octk_probe_origin' ) );
		$this->assertGreaterThan( time(), (int) get_option( 'octk_probe_origin_next_try' ) );
		$this->assertStringContainsString( 'public URL', octk_resolve_status()['origin'] );
	}

	public function test_selftest_fails_when_backend_saw_the_wrong_scheme() {
		// Site is https (behind TLS termination) but the backend did not map
		// X-Forwarded-Proto, so WordPress there built http URLs: every page
		// probe would be answered with a redirect to https. Such a route must
		// not pass, even though the endpoint itself answered.
		$https = static fn( string $url ) => set_url_scheme( $url, 'https' );
		add_filter( 'rest_url', $https );
		add_filter( 'octk_resolve_selftest_trust', '__return_true' );
		$this->responder = static function ( string $url, array $args ) {
			return self::response( 204, array( 'x-octk-selftest' => octk_selftest_signature( $args['headers']['X-Octk-Resolve'], 'http' ) ) );
		};
		try {
			$candidate = array( 'scheme' => 'http', 'host' => '10.0.0.5', 'port' => 80, 'sslverify' => true );
			$this->assertFalse( octk_probe_origin_selftest( $candidate ) );
			$this->assertSame( 'https', $this->requests[0]['args']['headers']['X-Forwarded-Proto'] );
			// The same backend with the mapping in place passes.
			$this->responder = static function ( string $url, array $args ) {
				return self::response( 204, array( 'x-octk-selftest' => octk_selftest_signature( $args['headers']['X-Octk-Resolve'], 'https' ) ) );
			};
			$this->assertTrue( octk_probe_origin_selftest( $candidate ) );
		} finally {
			remove_filter( 'rest_url', $https );
			remove_filter( 'octk_resolve_selftest_trust', '__return_true' );
		}
	}

	public function test_selftest_without_connection_mapping_is_not_trusted() {
		$this->responder = static function ( string $url, array $args ) {
			return self::response( 204, array( 'x-octk-selftest' => octk_selftest_signature( $args['headers']['X-Octk-Resolve'], 'http' ) ) );
		};
		// Stubbed transport: the curl hook never runs, so the answer is refused.
		$this->assertFalse( octk_probe_origin_selftest( array( 'scheme' => 'http', 'host' => '127.0.0.1', 'port' => 80, 'sslverify' => true ) ) );
	}

	/* ---- discovery, sources, purge ---- */

	public function test_save_post_warms_up_and_cron_resolves_before_render() {
		$this->respond_with( array( '/x' => $this->outbound( 'https://m.example/1' ) ) );
		$post_id = self::factory()->post->create( array( 'post_content' => '<a href="/x">x</a>' ) );
		$this->assertSame( 'pending', $this->row( $this->u( '/x' ) )['verdict'] );
		$this->assertSame( array( $post_id ), octk_resolve_source_ids( octk_resolve_hash( $this->u( '/x' ) ) ) );

		octk_resolve_run();
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'outbound', $this->row( $this->u( '/x' ) )['verdict'] );

		$this->requests = array();
		$html           = $this->render_post_content( $post_id );
		$this->assertCount( 0, $this->requests );
		$this->assertStringContainsString( 'data-octk-token', $html );
	}

	public function test_shortcode_only_reference_lands_in_sources_at_render() {
		add_shortcode( 'octk_cloak', static fn() => '<a href="/via-shortcode">Deal</a>' );
		try {
			$post_id = self::factory()->post->create( array( 'post_content' => 'Intro [octk_cloak] outro' ) );
			$this->assertNull( $this->row( $this->u( '/via-shortcode' ) ), 'save_post scans raw content only' );
			$this->render_post_content( $post_id );
		} finally {
			remove_shortcode( 'octk_cloak' );
		}
		$this->assertSame( array( $post_id ), octk_resolve_source_ids( octk_resolve_hash( $this->u( '/via-shortcode' ) ) ) );
	}

	public function test_concurrent_discovery_from_two_posts_keeps_both_pairs() {
		$a = self::factory()->post->create( array( 'post_content' => '<a href="/x">x</a>' ) );
		$b = self::factory()->post->create( array( 'post_content' => '<a href="/x">x</a>' ) );
		$ids = octk_resolve_source_ids( octk_resolve_hash( $this->u( '/x' ) ) );
		sort( $ids );
		$this->assertSame( array( min( $a, $b ), max( $a, $b ) ), $ids );
	}

	public function test_expiry_reprobe_changed_target_purges_every_source() {
		$fired = array();
		$listener = static function ( $url, $old, $new, $post_ids ) use ( &$fired ) {
			$fired[] = compact( 'url', 'old', 'new', 'post_ids' );
		};
		add_action( 'octk_resolution_changed', $listener, 10, 4 );

		$this->respond_with( array( '/x' => $this->outbound( 'https://m.example/old' ) ) );
		$rendered = self::factory()->post->create( array( 'post_content' => '<a href="/x">x</a>' ) );
		$never    = self::factory()->post->create( array( 'post_content' => '<a href="/x">x</a>' ) );
		$this->render_post_content( $rendered );
		$this->assertCount( 1, $fired, 'pending -> outbound is a change' );
		sort( $fired[0]['post_ids'] );
		$this->assertSame( array( min( $rendered, $never ), max( $rendered, $never ) ), $fired[0]['post_ids'] );

		$this->set_row( $this->u( '/x' ), array( 'next_check_utc' => $this->past() ) );
		$this->respond_with( array( '/x' => $this->outbound( 'https://m.example/new' ) ) );
		octk_resolve_run();
		remove_action( 'octk_resolution_changed', $listener, 10 );

		$this->assertCount( 2, $fired );
		$this->assertSame( 'https://m.example/old', $fired[1]['old']['destination_url'] );
		$this->assertSame( 'https://m.example/new', $fired[1]['new']['destination_url'] );
		$this->assertContains( $never, $fired[1]['post_ids'] );
	}

	public function test_same_destination_reconfirmed_is_not_a_change() {
		$fired = 0;
		$listener = static function () use ( &$fired ) {
			$fired++;
		};
		add_action( 'octk_resolution_changed', $listener );
		$this->respond_with( array(), $this->outbound( 'https://m.example/same' ) );
		$post_id = self::factory()->post->create( array( 'post_content' => '<a href="/1">1</a><a href="/2">2</a><a href="/3">3</a><a href="/4">4</a>' ) );
		octk_resolve_run();
		$this->assertSame( 4, $fired );

		// Skipped-refresh regression: all four confirmed and due; render uses
		// them as-is with no probes, cron re-confirms and purges nothing.
		foreach ( array( 1, 2, 3, 4 ) as $i ) {
			$this->set_row( $this->u( "/{$i}" ), array( 'next_check_utc' => $this->past() ) );
		}
		$this->requests = array();
		$html           = $this->render_post_content( $post_id );
		$this->assertCount( 0, $this->requests );
		$this->assertSame( 4, substr_count( $html, 'data-octk-token' ) );
		octk_resolve_run();
		remove_action( 'octk_resolution_changed', $listener );
		$this->assertCount( 4, $this->requests );
		$this->assertSame( 4, $fired );
	}

	public function test_unknown_never_overwrites_confirmed_verdict() {
		$this->respond_with( array( '/x' => $this->outbound( 'https://m.example/1' ) ) );
		$post_id = self::factory()->post->create( array( 'post_content' => '<a href="/x">x</a>' ) );
		$this->render_post_content( $post_id );

		$this->set_row( $this->u( '/x' ), array( 'next_check_utc' => $this->past() ) );
		$this->respond_with( array( '/x' => self::response( 503 ) ) );
		octk_resolve_run();

		$row = $this->row( $this->u( '/x' ) );
		$this->assertSame( 'outbound', $row['verdict'] );
		$this->assertSame( 'https://m.example/1', $row['destination_url'] );
		$this->assertSame( 1, (int) $row['attempts'] );
		$this->assertSame( 'HTTP 503', $row['last_error'] );
		$this->assertGreaterThan( time(), strtotime( $row['next_check_utc'] . ' UTC' ) );

		$this->requests = array();
		$html           = $this->render_post_content( $post_id );
		$this->assertStringContainsString( 'data-octk-token', $html );
		$this->assertCount( 0, $this->requests );
	}

	/* ---- claims and generations ---- */

	public function test_claim_is_exclusive_and_stale_results_are_discarded() {
		octk_resolve_ensure_row( $this->u( '/x' ) );
		$hash = octk_resolve_hash( $this->u( '/x' ) );
		octk_resolve_add_source( $hash, 'post', 4242 ); // a referenced row survives its check
		$first = octk_resolve_claim( $hash );
		$this->assertNotNull( $first );
		$this->assertNull( octk_resolve_claim( $hash ), 'second claimant loses' );

		$this->assertFalse( octk_store_verdict( $this->u( '/x' ), array( 'verdict' => 'internal' ), bin2hex( random_bytes( 16 ) ) ) );
		$this->assertSame( 'pending', $this->row( $this->u( '/x' ) )['verdict'] );
		$this->assertTrue( octk_store_verdict( $this->u( '/x' ), array( 'verdict' => 'internal' ), $first ) );
		$this->assertSame( 'internal', $this->row( $this->u( '/x' ) )['verdict'] );
	}

	public function test_clear_makes_recent_rows_claimable_and_rejects_preclear_results() {
		$this->respond_with( array( '/x' => $this->outbound( 'https://m.example/1' ) ) );
		self::factory()->post->create( array( 'post_content' => '<a href="/x">x</a>' ) );
		octk_resolve_run();
		$hash = octk_resolve_hash( $this->u( '/x' ) );
		$this->assertNull( octk_resolve_claim( $hash ), 'just checked: not due' );

		// A claim taken before Clear.
		$this->set_row( $this->u( '/x' ), array( 'next_check_utc' => $this->past() ) );
		$pre = octk_resolve_claim( $hash );
		$this->assertNotNull( $pre );
		$this->set_row( $this->u( '/x' ), array( 'next_check_utc' => $this->future(), 'claimed_until_utc' => null ) );

		octk_resolve_clear();
		$this->assertFalse( octk_store_verdict( $this->u( '/x' ), array( 'verdict' => 'internal' ), $pre ), 'pre-Clear result rejected' );
		$row = $this->row( $this->u( '/x' ) );
		$this->assertSame( 'outbound', $row['verdict'], 'verdict keeps serving' );
		$this->assertTrue( octk_resolve_row_is_due( $row ) );

		$post = octk_resolve_claim( $hash );
		$this->assertNotNull( $post, 'claimable by generation right after Clear' );
		$this->assertTrue( octk_store_verdict( $this->u( '/x' ), array( 'verdict' => 'unknown', 'error' => 'HTTP 503' ), $post ) );
		$row = $this->row( $this->u( '/x' ) );
		$this->assertSame( octk_resolve_generation(), (int) $row['generation'], 'unknown stamps the new generation' );
		$this->assertFalse( octk_resolve_row_is_due( $row ), 'backoff honoured after Clear' );
		$this->assertNull( octk_resolve_claim( $hash ) );
	}

	public function test_repeated_renders_during_backoff_probe_once() {
		$this->respond_with( array( '/x' => self::response( 503 ) ) );
		$post_id = self::factory()->post->create( array( 'post_content' => '<a href="/x">x</a>' ) );
		$this->render_post_content( $post_id );
		$this->render_post_content( $post_id );
		$this->render_post_content( $post_id );
		$this->assertCount( 1, $this->requests );

		$this->set_row( $this->u( '/x' ), array( 'next_check_utc' => $this->past() ) );
		$this->respond_with( array( '/x' => self::response( 429 ) ) );
		$this->render_post_content( $post_id );
		$this->render_post_content( $post_id );
		$this->assertCount( 2, $this->requests );
		$this->assertSame( 2, (int) $this->row( $this->u( '/x' ) )['attempts'] );
	}

	public function test_expired_claim_is_reissued() {
		octk_resolve_ensure_row( $this->u( '/x' ) );
		$hash = octk_resolve_hash( $this->u( '/x' ) );
		$this->assertNotNull( octk_resolve_claim( $hash ) );
		$this->set_row( $this->u( '/x' ), array( 'claimed_until_utc' => $this->past() ) );
		$this->assertNotNull( octk_resolve_claim( $hash ) );
	}

	/* ---- cron ceilings ---- */

	public function test_cron_processes_twenty_and_schedules_successor() {
		$this->respond_with( array(), $this->outbound( 'https://m.example/' ) );
		$content = '';
		for ( $i = 1; $i <= 21; $i++ ) {
			$content .= "<a href=\"/p{$i}\">{$i}</a>";
		}
		self::factory()->post->create( array( 'post_content' => $content ) );
		wp_unschedule_hook( OCTK_RESOLVE_HOOK );

		$this->assertSame( 20, octk_resolve_run() );
		$this->assertNotFalse( wp_next_scheduled( OCTK_RESOLVE_HOOK ), 'hourly sweep re-registered' );
		$next = wp_next_scheduled( OCTK_RESOLVE_HOOK, OCTK_RESOLVE_SUCCESSOR_ARGS );
		$this->assertNotFalse( $next );
		$this->assertLessThanOrEqual( time() + MINUTE_IN_SECONDS + 5, $next, 'successor one minute ahead' );
		$this->assertSame( 1, octk_resolve_due_count() );
		$this->assertSame( 20, octk_resolve_probes_today() );
	}

	public function test_hops_count_toward_daily_cap_and_cap_pauses_probing() {
		$this->respond_with( array( '/a' => $this->outbound( '/b' ), '/b' => $this->outbound( 'https://m.example/' ) ) );
		self::factory()->post->create( array( 'post_content' => '<a href="/a">a</a>' ) );
		octk_resolve_run();
		$this->assertSame( 2, octk_resolve_probes_today() );

		$cap = static fn() => 2;
		add_filter( 'octk_resolve_daily_cap', $cap );
		try {
			$this->assertTrue( octk_resolve_cap_reached() );
			$this->requests = array();
			$post_id        = self::factory()->post->create( array( 'post_content' => '<a href="/c">c</a>' ) );
			$this->render_post_content( $post_id );
			octk_resolve_run();
			$this->assertCount( 0, $this->requests );
			$this->assertSame( 'pending', $this->row( $this->u( '/c' ) )['verdict'] );
			$this->assertSame( 'cap', octk_resolve_status()['state'] );
			$this->assertNotFalse( wp_next_scheduled( OCTK_RESOLVE_HOOK ) );
		} finally {
			remove_filter( 'octk_resolve_daily_cap', $cap );
		}
	}

	public function test_daily_cap_reservation_is_atomic_and_exact() {
		global $wpdb;
		$cap = static fn() => 3;
		add_filter( 'octk_resolve_daily_cap', $cap );
		try {
			$this->assertTrue( octk_resolve_reserve_request() );
			$this->assertTrue( octk_resolve_reserve_request() );
			// A concurrent worker's increment landed in the database meanwhile;
			// a read-modify-write would overwrite it, the conditional UPDATE
			// builds on it and refuses the reservation that would exceed the cap.
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = option_value + 1 WHERE option_name = %s', $wpdb->options, octk_resolve_daily_key() ) );
			wp_cache_delete( octk_resolve_daily_key(), 'options' );
			$this->assertFalse( octk_resolve_reserve_request() );
			$this->assertSame( 3, octk_resolve_probes_today() );
			$this->assertTrue( octk_resolve_cap_reached() );

			// A chain that would cross the cap stops at the cap, counted exactly.
			update_option( octk_resolve_daily_key(), 2 );
			$this->respond_with( array( '/a' => $this->outbound( '/b' ), '/b' => $this->outbound( '/c' ), '/c' => $this->outbound( 'https://m.example/' ) ) );
			$result = octk_probe( $this->u( '/a' ) );
			$this->assertSame( 'unknown', $result['verdict'] );
			$this->assertSame( 'daily cap reached', $result['error'] );
			$this->assertSame( 1, $result['requests'] );
			$this->assertSame( 3, octk_resolve_probes_today() );
		} finally {
			remove_filter( 'octk_resolve_daily_cap', $cap );
		}
	}

	public function test_removed_link_leaves_the_sources_table_shortcode_links_survive() {
		add_shortcode( 'octk_cloak', static fn() => '<a href="/via-shortcode">Deal</a>' );
		try {
			$post_id = self::factory()->post->create( array( 'post_content' => '<a href="/keep">k</a> <a href="/gone">g</a> [octk_cloak]' ) );
			$this->render_post_content( $post_id );
			foreach ( array( '/keep', '/gone', '/via-shortcode' ) as $path ) {
				$this->assertSame( array( $post_id ), octk_resolve_source_ids( octk_resolve_hash( $this->u( $path ) ) ), $path );
			}

			// Editor removes /gone; save then render.
			wp_update_post( array( 'ID' => $post_id, 'post_content' => '<a href="/keep">k</a> [octk_cloak]' ) );
			$this->assertSame( array( $post_id ), octk_resolve_source_ids( octk_resolve_hash( $this->u( '/gone' ) ) ), 'save alone does not remove: shortcode output is unknown there' );
			$this->render_post_content( $post_id );
			$this->assertSame( array(), octk_resolve_source_ids( octk_resolve_hash( $this->u( '/gone' ) ) ) );
			$this->assertSame( array( $post_id ), octk_resolve_source_ids( octk_resolve_hash( $this->u( '/keep' ) ) ) );
			$this->assertSame( array( $post_id ), octk_resolve_source_ids( octk_resolve_hash( $this->u( '/via-shortcode' ) ) ), 'shortcode-emitted link kept' );

			// The orphaned row is deleted at its next check; re-adding the link re-registers the pair.
			$this->set_row( $this->u( '/gone' ), array( 'next_check_utc' => $this->past() ) );
			octk_resolve_run();
			$this->assertNull( $this->row( $this->u( '/gone' ) ) );
			wp_update_post( array( 'ID' => $post_id, 'post_content' => '<a href="/keep">k</a> <a href="/gone">g</a> [octk_cloak]' ) );
			$this->render_post_content( $post_id );
			$this->assertSame( array( $post_id ), octk_resolve_source_ids( octk_resolve_hash( $this->u( '/gone' ) ) ) );
		} finally {
			remove_shortcode( 'octk_cloak' );
		}
	}

	public function test_link_saved_then_removed_before_first_render_can_be_re_added() {
		// 1. save with the link (pair registered by the save-time scan, marker cached)
		$post_id = self::factory()->post->create( array( 'post_content' => '<a href="/late">l</a>' ) );
		$hash    = octk_resolve_hash( $this->u( '/late' ) );
		$this->assertSame( array( $post_id ), octk_resolve_source_ids( $hash ) );
		// 2. remove it before any render, then render
		wp_update_post( array( 'ID' => $post_id, 'post_content' => 'nothing here' ) );
		$this->render_post_content( $post_id );
		$this->assertSame( array(), octk_resolve_source_ids( $hash ) );
		// 3. re-add and render: the pair must come back despite the earlier marker
		wp_update_post( array( 'ID' => $post_id, 'post_content' => '<a href="/late">l</a>' ) );
		$this->assertSame( array( $post_id ), octk_resolve_source_ids( $hash ), 'save re-registers' );
		wp_update_post( array( 'ID' => $post_id, 'post_content' => 'nothing here' ) );
		$this->render_post_content( $post_id );
		$this->assertSame( array(), octk_resolve_source_ids( $hash ) );
		wp_update_post( array( 'ID' => $post_id, 'post_content' => '<a href="/late">l</a>' ) );
		$this->render_post_content( $post_id );
		$this->assertSame( array( $post_id ), octk_resolve_source_ids( $hash ), 'render re-registers after two removals' );
	}

	public function test_link_saved_and_removed_after_a_linkless_render_is_reconciled() {
		// go_to() flushes the object cache, so after the first real render
		// the later renders call the render-time entry point directly: that
		// keeps the cached fingerprint alive across the cycle, as it is on a
		// site with a persistent object cache.
		$render = static function ( int $post_id ): void {
			OCTK_Resolver::reset_memo();
			octk_resolve_prepare_render( (string) get_post( $post_id )->post_content, $post_id );
		};
		// 1. render a post without links: an empty fingerprint is cached
		$post_id = self::factory()->post->create( array( 'post_content' => 'nothing here' ) );
		$this->render_post_content( $post_id );
		$hash = octk_resolve_hash( $this->u( '/late' ) );
		$this->assertSame( array(), octk_resolve_source_ids( $hash ) );
		// 2. add and save the link (registered by the save-time scan only)
		wp_update_post( array( 'ID' => $post_id, 'post_content' => '<a href="/late">l</a>' ) );
		$this->assertSame( array( $post_id ), octk_resolve_source_ids( $hash ) );
		// 3. remove and save before any render
		wp_update_post( array( 'ID' => $post_id, 'post_content' => 'nothing here' ) );
		$this->assertSame( array( $post_id ), octk_resolve_source_ids( $hash ), 'save never removes pairs' );
		// 4. render: the rendered set matches the earlier empty fingerprint,
		//    but the stored pair must still be dropped
		$render( $post_id );
		$this->assertSame( array(), octk_resolve_source_ids( $hash ), 'stale pair removed despite matching fingerprint' );
		// the same cycle again, with the fingerprint now freshly cached
		wp_update_post( array( 'ID' => $post_id, 'post_content' => '<a href="/late">l</a>' ) );
		wp_update_post( array( 'ID' => $post_id, 'post_content' => 'nothing here' ) );
		$render( $post_id );
		$this->assertSame( array(), octk_resolve_source_ids( $hash ), 'second cycle' );
	}

	public function test_successor_is_scheduled_even_when_the_hourly_sweep_is_minutes_away() {
		$this->respond_with( array(), $this->outbound( 'https://m.example/' ) );
		self::factory()->post->create( array( 'post_content' => '<a href="/x">x</a>' ) );
		wp_unschedule_hook( OCTK_RESOLVE_HOOK );
		wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', OCTK_RESOLVE_HOOK );

		octk_resolve_run();

		$successor = wp_next_scheduled( OCTK_RESOLVE_HOOK, OCTK_RESOLVE_SUCCESSOR_ARGS );
		$this->assertNotFalse( $successor, 'one-minute successor exists despite the sweep five minutes away' );
		$this->assertLessThanOrEqual( time() + MINUTE_IN_SECONDS + 5, $successor );
		$this->assertSame( time() + 5 * MINUTE_IN_SECONDS, wp_next_scheduled( OCTK_RESOLVE_HOOK ), 'hourly sweep untouched' );

		// With the sweep due within a minute no successor is added; when the
		// successor exists it is not duplicated.
		wp_unschedule_hook( OCTK_RESOLVE_HOOK );
		wp_schedule_event( time() + 30, 'hourly', OCTK_RESOLVE_HOOK );
		$this->assertTrue( octk_schedule_resolve_successor() );
		$this->assertFalse( wp_next_scheduled( OCTK_RESOLVE_HOOK, OCTK_RESOLVE_SUCCESSOR_ARGS ) );
	}

	public function test_row_without_sources_is_deleted_at_next_check() {
		$this->respond_with( array( '/x' => self::response( 200 ) ) );
		$post_id = self::factory()->post->create( array( 'post_content' => '<a href="/x">x</a>' ) );
		octk_resolve_run();
		$this->assertNotNull( $this->row( $this->u( '/x' ) ) );

		wp_delete_post( $post_id, true );
		$this->assertSame( array(), octk_resolve_source_ids( octk_resolve_hash( $this->u( '/x' ) ) ) );
		$this->set_row( $this->u( '/x' ), array( 'next_check_utc' => $this->past() ) );
		octk_resolve_run();
		$this->assertNull( $this->row( $this->u( '/x' ) ) );
	}

	public function test_backfill_discovers_published_content_in_batches() {
		$ids = self::factory()->post->create_many( 3, array( 'post_content' => '<a href="/backfilled">b</a>' ) );
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . octk_table_redirects() );
		$wpdb->query( 'DELETE FROM ' . octk_table_redirect_sources() );
		wp_cache_flush();
		OCTK_Resolver::reset_memo();
		update_option( 'octk_resolve_backfill', 0 );

		$this->assertFalse( octk_resolve_backfill_step(), 'fewer than a batch: done in one step' );
		$this->assertFalse( get_option( 'octk_resolve_backfill' ) );
		$found = octk_resolve_source_ids( octk_resolve_hash( $this->u( '/backfilled' ) ) );
		sort( $ids );
		sort( $found );
		$this->assertSame( $ids, $found );
	}

	public function test_migration_3_is_idempotent_and_creates_secret_and_cron() {
		delete_option( 'octk_resolve_secret' );
		delete_option( 'octk_resolve_generation' );
		wp_clear_scheduled_hook( OCTK_RESOLVE_HOOK );
		$this->assertTrue( octk_migration_3() );
		$this->assertTrue( octk_schema_v3_ready( true ) );
		$this->assertSame( 64, strlen( octk_resolve_secret() ) );
		$this->assertSame( 1, octk_resolve_generation() );
		$this->assertNotFalse( wp_next_scheduled( OCTK_RESOLVE_HOOK ) );
		$secret = octk_resolve_secret();
		$this->assertTrue( octk_migration_3() );
		$this->assertSame( $secret, octk_resolve_secret(), 'existing secret untouched' );
	}

	public function test_external_urls_never_touch_the_resolver() {
		$post_id = self::factory()->post->create( array( 'post_content' => '<a href="https://merchant.example/x">x</a>' ) );
		$html    = $this->render_post_content( $post_id );
		$this->assertStringContainsString( 'data-octk-token', $html );
		$this->assertCount( 0, $this->requests );
		global $wpdb;
		$this->assertSame( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . octk_table_redirects() ) );
	}

	/* ---- 1.3.1 ---- */

	public function test_old_daily_counters_are_all_deleted_but_not_today_or_later() {
		global $wpdb;
		$today = octk_resolve_daily_key();
		$old   = array( 'octk_resolve_daily_20260914', 'octk_resolve_daily_' . gmdate( 'Ymd', time() - 3 * DAY_IN_SECONDS ) );
		$later = 'octk_resolve_daily_' . gmdate( 'Ymd', time() + DAY_IN_SECONDS );
		foreach ( array_merge( $old, array( $later ) ) as $name ) {
			add_option( $name, 5, '', false );
		}
		add_option( 'octk_resolve_dailyx', 1, '', false ); // "_" must not act as a LIKE wildcard
		try {
			$this->assertTrue( octk_resolve_reserve_request() );
			foreach ( $old as $name ) {
				$this->assertFalse( get_option( $name ), $name );
				$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $name ) ), $name );
			}
			$this->assertSame( 1, octk_resolve_probes_today(), $today );
			$this->assertSame( '5', (string) get_option( $later ), 'a key newer than today is never deleted' );
			$this->assertSame( '1', (string) get_option( 'octk_resolve_dailyx' ) );
		} finally {
			delete_option( $later );
			delete_option( 'octk_resolve_dailyx' );
		}
	}

	public function test_probe_header_absent_skips_readiness_checks() {
		// octk_resolve_enabled() runs the readiness checks; the filter inside it
		// must never be reached for a request without a well-formed header.
		$filter = static function () {
			throw new LogicException( 'octk_resolve_enabled must not be consulted without a header' );
		};
		add_filter( 'octk_resolve_enabled', $filter );
		try {
			$this->assertNull( $this->with_server( array( 'HTTP_HOST' => 'example.org', 'REQUEST_URI' => '/x' ), 'octk_verify_probe_header' ) );
			$this->assertNull( $this->with_server( array( 'HTTP_HOST' => 'example.org', 'REQUEST_URI' => '/x', 'HTTP_X_OCTK_RESOLVE' => 'garbage' ), 'octk_verify_probe_header' ) );
		} finally {
			remove_filter( 'octk_resolve_enabled', $filter );
		}
	}

	public function test_repeated_location_header_uses_the_first_value() {
		$this->respond_with( array(
			'/go/dup' => self::response( 302, array( 'location' => array( 'https://first.example/p', 'https://second.example/q' ) ) ),
		) );
		$result = octk_probe( $this->u( '/go/dup' ) );
		$this->assertSame( 'outbound', $result['verdict'] );
		$this->assertSame( 'https://first.example/p', $result['destination_url'] );
		$this->assertSame( 1, $result['requests'] );
	}
}
