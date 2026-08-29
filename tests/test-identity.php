<?php

class OCTK_Identity_Test extends WP_UnitTestCase {

	public function test_deterministic() {
		$a = octk_identity_hash( 'post', 42, 'https://merchant.example/x' );
		$b = octk_identity_hash( 'post', 42, 'https://merchant.example/x' );
		$this->assertSame( $a, $b );
		$this->assertSame( 32, strlen( $a ) );
	}

	public function test_component_boundary_shifts_produce_different_hashes() {
		// Naive concatenation would make these identical: '12' . '3x' == '123' . 'x'.
		$a = octk_identity_hash( 'post', 12, '3https://m.example/' );
		$b = octk_identity_hash( 'post', 123, 'https://m.example/' );
		$this->assertNotSame( $a, $b );

		// 'postX' + '1' vs 'post' + 'X1' style shift across type/id boundary.
		$c = octk_identity_hash( 'a', 11, 'x' );
		$d = octk_identity_hash( 'a1', 1, 'x' );
		$this->assertNotSame( $c, $d );
	}

	public function test_distinct_inputs_distinct_hashes() {
		$base = octk_identity_hash( 'post', 1, 'https://m.example/a' );
		$this->assertNotSame( $base, octk_identity_hash( 'post', 2, 'https://m.example/a' ) );
		$this->assertNotSame( $base, octk_identity_hash( 'post', 1, 'https://m.example/b' ) );
		$this->assertNotSame( $base, octk_identity_hash( 'page', 1, 'https://m.example/a' ) );
	}
}
