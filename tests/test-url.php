<?php

class OCTK_Url_Test extends WP_UnitTestCase {

	public function test_internal_hosts_rejected() {
		$home = home_url( '/some-post/' );
		$this->assertNull( octk_normalize_destination( $home ) );
	}

	public function test_www_alias_of_internal_host_rejected() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$this->assertNull( octk_normalize_destination( 'https://www.' . $host . '/page' ) );
	}

	public function test_non_http_schemes_rejected() {
		$this->assertNull( octk_normalize_destination( 'mailto:someone@example.com' ) );
		$this->assertNull( octk_normalize_destination( 'tel:+15551234567' ) );
		$this->assertNull( octk_normalize_destination( 'javascript:alert(1)' ) );
		$this->assertNull( octk_normalize_destination( 'data:text/html,hi' ) );
		$this->assertNull( octk_normalize_destination( 'ftp://files.example.com/x' ) );
	}

	public function test_relative_and_fragment_rejected() {
		$this->assertNull( octk_normalize_destination( '/relative/path' ) );
		$this->assertNull( octk_normalize_destination( '#section' ) );
		$this->assertNull( octk_normalize_destination( '//protocol-relative.example.com/x' ) );
		$this->assertNull( octk_normalize_destination( '' ) );
		$this->assertNull( octk_normalize_destination( 'http://' ) );
	}

	public function test_embedded_credentials_rejected() {
		$this->assertNull( octk_normalize_destination( 'https://user:pass@merchant.example/x' ) );
	}

	public function test_scheme_and_host_lowercased_path_preserved() {
		$n = octk_normalize_destination( 'HTTPS://Merchant.EXAMPLE/Path/To/Item' );
		$this->assertSame( 'https://merchant.example/Path/To/Item', $n['url'] );
		$this->assertSame( 'merchant.example', $n['host'] );
	}

	public function test_fragment_stripped_query_preserved() {
		$n = octk_normalize_destination( 'https://merchant.example/deal?aff=abc&sub=1#reviews' );
		$this->assertSame( 'https://merchant.example/deal?aff=abc&sub=1', $n['url'] );
	}

	public function test_default_port_stripped_custom_kept() {
		$this->assertSame(
			'https://merchant.example/x',
			octk_normalize_destination( 'https://merchant.example:443/x' )['url']
		);
		$this->assertSame(
			'http://merchant.example/x',
			octk_normalize_destination( 'http://merchant.example:80/x' )['url']
		);
		$this->assertSame(
			'https://merchant.example:8443/x',
			octk_normalize_destination( 'https://merchant.example:8443/x' )['url']
		);
	}

	public function test_empty_path_becomes_root() {
		$this->assertSame(
			'https://merchant.example/',
			octk_normalize_destination( 'https://merchant.example' )['url']
		);
	}

	public function test_over_limit_urls_skipped_not_truncated() {
		$long = 'https://merchant.example/' . str_repeat( 'a', 2100 );
		$this->assertNull( octk_normalize_destination( $long ) );
	}

	public function test_configured_internal_host_rejected() {
		update_option( 'octk_internal_hosts', array( 'links.example.com' ) );
		$this->assertNull( octk_normalize_destination( 'https://links.example.com/go/x' ) );
		delete_option( 'octk_internal_hosts' );
	}
}
