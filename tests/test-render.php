<?php

class OCTK_Render_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		OCTK_Registry::reset_memo();
		update_option( 'octk_enabled', '1' );
	}

	private function render_post_content( int $post_id ): string {
		$this->go_to( get_permalink( $post_id ) );
		$this->assertTrue( is_singular() );

		$output = '';
		while ( have_posts() ) {
			the_post();
			$output = apply_filters( 'the_content', get_the_content() );
		}
		return $output;
	}

	public function test_outbound_links_annotated_internal_and_mailto_untouched() {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<p><a href="https://merchant.example/deal?aff=1">Buy</a>'
					. ' <a href="' . esc_url( home_url( '/about/' ) ) . '">About</a>'
					. ' <a href="mailto:hi@example.com">Mail</a></p>',
			)
		);

		$html = $this->render_post_content( $post_id );

		$this->assertMatchesRegularExpression(
			'/<a(?=[^>]*href="https:\/\/merchant\.example\/deal\?aff=1")(?=[^>]*data-octk-token="[0-9a-f]{32}")[^>]*>/',
			$html
		);
		$this->assertSame( 1, substr_count( $html, 'data-octk-token' ) );
	}

	public function test_same_destination_twice_shares_one_token() {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<p><a href="https://merchant.example/x">One</a>'
					. ' <a href="https://merchant.example/x#detail">Two</a></p>',
			)
		);

		$html = $this->render_post_content( $post_id );

		preg_match_all( '/data-octk-token="([0-9a-f]{32})"/', $html, $m );
		$this->assertCount( 2, $m[1] );
		$this->assertSame( $m[1][0], $m[1][1] );
	}

	public function test_shortcode_output_annotated() {
		add_shortcode(
			'octk_test_link',
			static function () {
				return '<a href="https://shop.example/deal?aff=9">Deal</a>';
			}
		);

		$post_id = self::factory()->post->create( array( 'post_content' => 'Intro [octk_test_link] outro' ) );
		$html    = $this->render_post_content( $post_id );

		remove_shortcode( 'octk_test_link' );

		$this->assertMatchesRegularExpression(
			'/<a(?=[^>]*href="https:\/\/shop\.example\/deal\?aff=9")(?=[^>]*data-octk-token="[0-9a-f]{32}")[^>]*>/',
			$html
		);
	}

	public function test_disabled_switch_leaves_content_untouched() {
		update_option( 'octk_enabled', '0' );
		$post_id = self::factory()->post->create(
			array( 'post_content' => '<a href="https://merchant.example/x">Buy</a>' )
		);

		$html = $this->render_post_content( $post_id );

		$this->assertStringNotContainsString( 'data-octk-token', $html );
	}

	public function test_feed_context_untouched() {
		$post_id = self::factory()->post->create(
			array( 'post_content' => '<a href="https://merchant.example/x">Buy</a>' )
		);
		$this->go_to( '/?feed=rss2' );
		$this->assertTrue( is_feed() );

		$html = apply_filters( 'the_content', get_post( $post_id )->post_content );

		$this->assertStringNotContainsString( 'data-octk-token', $html );
	}

	public function test_out_of_loop_render_untouched() {
		$post_id = self::factory()->post->create(
			array( 'post_content' => '<a href="https://merchant.example/x">Buy</a>' )
		);
		$this->go_to( get_permalink( $post_id ) );

		// the_content applied outside the loop (e.g. SEO analysis pass).
		$html = apply_filters( 'the_content', get_post( $post_id )->post_content );

		$this->assertStringNotContainsString( 'data-octk-token', $html );
	}
}
