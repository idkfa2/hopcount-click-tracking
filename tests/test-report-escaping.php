<?php

/**
 * Scope §14: all report fields remain safe even when database values are
 * hostile (the 0.4.0 stored-XSS lesson). Feeds crafted rows straight into the
 * table renderer and asserts nothing executable survives.
 */
class OCTK_Report_Escaping_Test extends WP_UnitTestCase {

	public function test_hostile_stored_values_render_inert() {
		$hostile = (object) array(
			'source_type'      => 'post',
			'source_id'        => 999999, // deleted/nonexistent: forces source_url fallback path
			'source_url'       => 'https://x.example/"><script>alert(1)</script>',
			'destination_url'  => 'https://evil.example/"><img src=x onerror=alert(2)>?q=<script>',
			'destination_host' => 'evil.example"><script>alert(3)</script>',
			'clicks'           => 7,
			'last_bucket'      => '2026-08-02 10:00:00',
		);
		$result  = (object) array(
			'rows'  => array( $hostile ),
			'total' => 1,
		);
		$request = array(
			'args' => array(
				'orderby'  => 'clicks',
				'order'    => 'desc',
				'page'     => 1,
				'per_page' => 25,
			),
		);

		ob_start();
		octk_report_table( $result, $request );
		$html = ob_get_clean();

		// Parse-level property: no executable elements or handlers survive,
		// regardless of how the inert text is encoded.
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<!DOCTYPE html><html><body>' . $html . '</body></html>' );
		libxml_clear_errors();
		$xpath = new DOMXPath( $dom );

		$this->assertSame( 0, $xpath->query( '//script' )->length );
		$this->assertSame( 0, $xpath->query( '//img' )->length );
		$this->assertSame( 0, $xpath->query( '//*[@onerror or @onload or @onclick]' )->length );
		// The one link must point at the (sanitized) destination, nowhere else.
		foreach ( $xpath->query( '//a[@href]' ) as $a ) {
			$this->assertMatchesRegularExpression( '#^(https://evil\.example/|/\?)#', $a->getAttribute( 'href' ) );
		}
		// The escaped text content is still shown.
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_hostile_bucket_value_escaped_by_table_renderer() {
		// octk_format_bucket returns unparseable input as-is; the renderer's
		// esc_html() must be what keeps it inert.
		$row     = (object) array(
			'source_type'      => 'post',
			'source_id'        => self::factory()->post->create(),
			'source_url'       => 'https://x.example/a',
			'destination_url'  => 'https://m.example/a',
			'destination_host' => 'm.example',
			'clicks'           => 1,
			'last_bucket'      => '<script>alert(1)</script>',
		);
		$result  = (object) array( 'rows' => array( $row ), 'total' => 1 );
		$request = array( 'args' => array( 'orderby' => 'clicks', 'order' => 'desc', 'page' => 1, 'per_page' => 25 ) );

		ob_start();
		octk_report_table( $result, $request );
		$html = ob_get_clean();

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}
}
