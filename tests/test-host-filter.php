<?php

/**
 * 1.3.1: the Report host filter is free text with suggestions. Every host is
 * reachable (the old select listed the first 200 alphabetically), and the
 * chosen host survives a form round-trip even when it is not suggested.
 */
class OCTK_Host_Filter_Test extends WP_UnitTestCase {

	private $post;

	public function set_up() {
		parent::set_up();
		OCTK_Registry::reset_memo();
		$this->post = self::factory()->post->create( array( 'post_title' => 'Hosts' ) );
	}

	public function tear_down() {
		$_GET = array();
		parent::tear_down();
	}

	private function seed( string $url, array $buckets ): void {
		global $wpdb;
		$tokens  = OCTK_Registry::tokens_for( 'post', $this->post, array( octk_normalize_destination( $url ) ) );
		$link_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE public_token = UNHEX(%s)', octk_table_links(), array_values( $tokens )[0] ) );
		foreach ( $buckets as $bucket => $hits ) {
			$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (link_id, bucket_start_utc, hits) VALUES (%d, %s, %d)', octk_table_clicks(), $link_id, $bucket, $hits ) );
		}
	}

	private function form_html( array $get ): string {
		$_GET = $get;
		ob_start();
		octk_report_filters_form( octk_report_request() );
		return ob_get_clean();
	}

	public function test_suggestions_rank_by_clicks_in_range_and_are_capped() {
		$this->seed( 'https://a.example/', array( '2026-08-02 10:00:00' => 1 ) );
		$this->seed( 'https://z.example/', array( '2026-08-02 10:00:00' => 9 ) );
		$this->seed( 'https://m.example/', array( '2026-08-03 10:00:00' => 4, '2026-09-01 00:00:00' => 50 ) );
		$this->seed( 'https://outside.example/', array( '2026-07-01 00:00:00' => 99 ) );

		$this->assertSame(
			array( 'z.example', 'm.example', 'a.example' ),
			OCTK_Reporting::hosts( '2026-08-01 00:00:00', '2026-08-08 00:00:00' ),
			'most clicks in the range first; hosts without clicks in the range are not suggested'
		);
		$this->assertSame( array( 'z.example' ), OCTK_Reporting::hosts( '2026-08-01 00:00:00', '2026-08-08 00:00:00', 1 ) );
	}

	public function test_more_than_200_hosts_are_all_reachable() {
		for ( $i = 0; $i < 205; $i++ ) {
			$this->seed( sprintf( 'https://h%03d.example/', $i ), array( '2026-08-02 10:00:00' => 1 ) );
		}
		// Alphabetically last: the old select (first 200 A to Z) never offered it.
		$this->seed( 'https://www.amazon.zz/', array( '2026-08-02 10:00:00' => 50 ) );

		$hosts = OCTK_Reporting::hosts( '2026-08-01 00:00:00', '2026-08-08 00:00:00' );
		$this->assertCount( 206, $hosts );
		$this->assertSame( 'www.amazon.zz', $hosts[0] );

		$rows = OCTK_Reporting::query_rows( array(
			'start_utc' => '2026-08-01 00:00:00',
			'end_utc'   => '2026-08-08 00:00:00',
			'host'      => 'h204.example',
			'orderby'   => 'clicks',
			'order'     => 'desc',
			'page'      => 1,
			'per_page'  => 25,
		) );
		$this->assertSame( 1, $rows->total );
	}

	public function test_selected_host_outside_suggestions_stays_in_the_input() {
		$this->seed( 'https://suggested.example/', array( '2026-08-02 10:00:00' => 3 ) );

		$html = $this->form_html( array(
			'range' => 'custom',
			'from'  => '2026-08-01',
			'to'    => '2026-08-07',
			'host'  => 'Not-Clicked.Example',
		) );

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<!DOCTYPE html><html><body>' . $html . '</body></html>' );
		libxml_clear_errors();
		$xpath = new DOMXPath( $dom );

		$input = $xpath->query( '//input[@name="host"]' );
		$this->assertSame( 1, $input->length );
		$this->assertSame( 'not-clicked.example', $input->item( 0 )->getAttribute( 'value' ), 'kept (lowercased) although no suggestion matches' );
		$this->assertSame( 'octk-host-list', $input->item( 0 )->getAttribute( 'list' ) );
		$this->assertSame( 0, $xpath->query( '//select[@name="host"]' )->length );

		$options = array();
		foreach ( $xpath->query( '//datalist[@id="octk-host-list"]/option' ) as $option ) {
			$options[] = $option->getAttribute( 'value' );
		}
		$this->assertSame( array( 'suggested.example' ), $options );
	}

	public function test_host_input_sanitizing() {
		$this->assertSame( '', octk_sanitize_report_host( '' ) );
		$this->assertSame( 'www.amazon.com', octk_sanitize_report_host( ' WWW.Amazon.com ' ) );
		$this->assertSame( 'www.amazon.com', octk_sanitize_report_host( 'https://www.amazon.com/dp/B000?tag=x' ) );
		$this->assertSame( 'amzn.to', octk_sanitize_report_host( 'amzn.to/3abc' ) );
		$this->assertSame( 'shop.example', octk_sanitize_report_host( '//shop.example/x' ) );
		$this->assertSame( '', octk_sanitize_report_host( "x.example' OR 1=1" ) );
		$this->assertSame( '', octk_sanitize_report_host( 'two words' ) );
	}
}
