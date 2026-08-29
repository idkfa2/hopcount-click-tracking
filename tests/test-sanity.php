<?php

class OCTK_Sanity_Test extends WP_UnitTestCase {

	public function test_plugin_loaded() {
		$this->assertTrue( defined( 'OCTK_VERSION' ) );
	}

	public function test_shortcoder_available_for_fixtures() {
		$this->assertTrue( shortcode_exists( 'sc' ) || class_exists( 'Shortcoder' ) );
	}
}
