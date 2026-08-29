<?php

class OCTK_Limiter_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_cache_flush();
	}

	public function test_allows_up_to_limit_then_denies_within_window() {
		$now = 1754820000; // fixed timestamp, single window
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->assertTrue( octk_limiter_check( '203.0.113.9', 5, $now ), "hit $i should pass" );
		}
		$this->assertFalse( octk_limiter_check( '203.0.113.9', 5, $now ) );
	}

	public function test_windows_reset_and_ips_are_independent() {
		$now = 1754820000;
		for ( $i = 0; $i < 5; $i++ ) {
			octk_limiter_check( '203.0.113.9', 5, $now );
		}
		$this->assertFalse( octk_limiter_check( '203.0.113.9', 5, $now ) );

		// Next minute window: counter resets.
		$this->assertTrue( octk_limiter_check( '203.0.113.9', 5, $now + MINUTE_IN_SECONDS ) );
		// Different IP in the exhausted window: unaffected.
		$this->assertTrue( octk_limiter_check( '198.51.100.1', 5, $now ) );
	}

	public function test_filter_inactive_without_persistent_cache() {
		update_option( 'octk_rate_limit', '1' );
		update_option( 'octk_rate_limit_per_minute', 1 );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

		// wp-env tests have no persistent object cache: limiter must stand aside.
		$this->assertFalse( (bool) wp_using_ext_object_cache() );
		$this->assertTrue( apply_filters( 'octk_click_allowed', true, str_repeat( 'a', 32 ) ) );
		$this->assertTrue( apply_filters( 'octk_click_allowed', true, str_repeat( 'a', 32 ) ) );

		delete_option( 'octk_rate_limit' );
	}

	public function test_respects_prior_rejection() {
		$this->assertFalse( octk_builtin_limiter( false, str_repeat( 'a', 32 ) ) );
	}

	public function test_per_minute_setting_clamped() {
		update_option( 'octk_rate_limit_per_minute', 99999 );
		$this->assertSame( 1000, octk_rate_limit_per_minute() );
		update_option( 'octk_rate_limit_per_minute', 0 );
		$this->assertSame( 1, octk_rate_limit_per_minute() );
		delete_option( 'octk_rate_limit_per_minute' );
	}
}
