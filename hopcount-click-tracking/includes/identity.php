<?php
/**
 * Link identity hashing (scope §6): SHA-256 over canonical length-prefixed
 * framing. Naive delimiter concatenation is prohibited — component boundaries
 * must be unambiguous.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @return string 32 raw bytes.
 */
function octk_identity_hash( string $source_type, int $source_id, string $normalized_destination ): string {
	$framed = '';
	foreach ( array( $source_type, (string) $source_id, $normalized_destination ) as $field ) {
		$framed .= pack( 'N', strlen( $field ) ) . $field;
	}
	return hash( 'sha256', $framed, true );
}
