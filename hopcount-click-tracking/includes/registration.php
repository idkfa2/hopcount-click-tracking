<?php
/**
 * Link registration (scope §5 step 7, §7 decision 37).
 *
 * Budget per uncached render: one preload SELECT, one multi-row
 * INSERT ... ON DUPLICATE KEY UPDATE id = id (no-op; absorbs only duplicate
 * keys), one reselect. Per-row token retries only for rows the reselect shows
 * absent. Every failure is fail-soft: the affected link is left unannotated.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const OCTK_TOKEN_RETRIES = 3;

class OCTK_Registry {

	/** @var array<string, array<string, array{token: string, url: string}>> memo[source_key][identity_hash_hex] */
	private static $memo = array();

	public static function reset_memo(): void {
		self::$memo = array();
	}

	/**
	 * Token generation. The filter is a test seam for the forced
	 * token-collision fixture (scope §14); invalid overrides are ignored.
	 */
	private static function new_token_hex(): string {
		$override = apply_filters( 'octk_public_token_hex', null );
		if ( is_string( $override ) && 1 === preg_match( '/^[0-9a-f]{32}$/', $override ) ) {
			return $override;
		}
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Resolve public tokens for a set of normalized destinations on one source.
	 *
	 * @param string $source_type  e.g. 'post'.
	 * @param int    $source_id    WordPress post/page ID.
	 * @param array  $destinations list of array{url: string, host: string, label?: string}, unique by url.
	 * @return array<string, string> map normalized_url => token_hex (missing entries = fail-soft skip).
	 */
	public static function tokens_for( string $source_type, int $source_id, array $destinations ): array {
		global $wpdb;

		$source_key = $source_type . ':' . $source_id;
		if ( ! isset( self::$memo[ $source_key ] ) ) {
			self::$memo[ $source_key ] = self::preload( $source_type, $source_id );
		}

		$by_hash = array(); // hash_hex => array{url, host, label}
		foreach ( $destinations as $dest ) {
			$dest['label']        = isset( $dest['label'] ) ? (string) $dest['label'] : '';
			$hash_hex             = bin2hex( octk_identity_hash( $source_type, $source_id, $dest['url'] ) );
			$by_hash[ $hash_hex ] = $dest;
		}

		$missing = array_diff_key( $by_hash, self::$memo[ $source_key ] );

		if ( ! empty( $missing ) ) {
			$source_url = self::source_url( $source_type, $source_id );
			$inserted   = self::register_missing( $source_type, $source_id, $source_url, $missing );
			self::$memo[ $source_key ] += $inserted;
		}

		$result   = array();
		$to_label = array(); // hash_hex => label, for pre-existing rows whose label is still NULL
		foreach ( $by_hash as $hash_hex => $dest ) {
			if ( ! isset( self::$memo[ $source_key ][ $hash_hex ] ) ) {
				continue;
			}
			$row = self::$memo[ $source_key ][ $hash_hex ];
			// Invariant check on every reuse path (decision 24): a hash match
			// with a different stored destination is never reused.
			if ( $row['url'] !== $dest['url'] ) {
				octk_log( sprintf( 'identity invariant failure for hash %s: stored destination differs from proposed', $hash_hex ) );
				continue;
			}
			if ( null === $row['label'] && '' !== $dest['label'] ) {
				$to_label[ $hash_hex ] = $dest['label'];
			}
			$result[ $dest['url'] ] = $row['token'];
		}

		if ( ! empty( $to_label ) ) {
			self::fill_missing_labels( $source_key, $to_label );
		}

		return $result;
	}

	/**
	 * First-wins label fill for existing rows (link-labels plan): one batched
	 * UPDATE per render, only rows still NULL, never overwriting. Steady state
	 * (all labels present) issues no query at all.
	 *
	 * @param array<string, string> $to_label hash_hex => label.
	 */
	private static function fill_missing_labels( string $source_key, array $to_label ): void {
		global $wpdb;

		if ( ! octk_schema_v2_ready() ) {
			return; // fail-soft: labels are an enhancement, never a blocker.
		}

		$table  = octk_table_links();
		$when   = array();
		$in     = array();
		$values = array();
		foreach ( $to_label as $hash_hex => $label ) {
			$when[]   = 'WHEN UNHEX(%s) THEN %s';
			$values[] = $hash_hex;
			$values[] = $label;
		}
		foreach ( array_keys( $to_label ) as $hash_hex ) {
			$in[]     = 'UNHEX(%s)';
			$values[] = $hash_hex;
		}

		$sql = "UPDATE {$table} SET label = CASE identity_hash " . implode( ' ', $when ) . ' END'
			. ' WHERE identity_hash IN (' . implode( ', ', $in ) . ') AND label IS NULL';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( false === $wpdb->query( $wpdb->prepare( $sql, $values ) ) ) {
			octk_log( 'label fill failed: ' . $wpdb->last_error );
			return;
		}

		foreach ( $to_label as $hash_hex => $label ) {
			if ( isset( self::$memo[ $source_key ][ $hash_hex ] ) ) {
				self::$memo[ $source_key ][ $hash_hex ]['label'] = $label;
			}
		}
	}

	/** @return array<string, array{token: string, url: string, label: ?string}> hash_hex => row */
	private static function preload( string $source_type, int $source_id ): array {
		global $wpdb;

		// Source invariant on this path is enforced by the WHERE clause: every
		// returned row already matches source_type + source_id exactly. The
		// destination half of the invariant is checked at reuse in tokens_for().
		$table = octk_table_links();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT HEX(identity_hash) AS h, HEX(public_token) AS t, destination_url, label FROM {$table} WHERE source_type = %s AND source_id = %d",
				$source_type,
				$source_id
			)
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$map = array();
		foreach ( $rows as $row ) {
			$map[ strtolower( $row->h ) ] = array(
				'token' => strtolower( $row->t ),
				'url'   => $row->destination_url,
				'label' => $row->label, // null = not yet captured
			);
		}
		return $map;
	}

	/**
	 * Insert missing identities per the decision-37 flow.
	 *
	 * @param array<string, array{url: string, host: string}> $missing hash_hex => dest.
	 * @return array<string, array{token: string, url: string}> rows now usable.
	 */
	private static function register_missing( string $source_type, int $source_id, string $source_url, array $missing ): array {
		global $wpdb;

		$table  = octk_table_links();
		$now    = gmdate( 'Y-m-d H:i:s' );
		$tokens = array(); // hash_hex => proposed token_hex

		$values       = array();
		$placeholders = array();
		foreach ( $missing as $hash_hex => $dest ) {
			$token_hex           = self::new_token_hex();
			$tokens[ $hash_hex ] = $token_hex;
			// NULLIF: an empty captured label is stored as NULL (= not captured).
			$placeholders[]      = '(UNHEX(%s), UNHEX(%s), %s, %d, %s, %s, %s, UNHEX(%s), NULLIF(%s, \'\'), %s)';
			array_push( $values, $token_hex, $hash_hex, $source_type, $source_id, $source_url, $dest['url'], $dest['host'], hash( 'sha256', $dest['url'] ), $dest['label'] ?? '', $now );
		}

		$sql = "INSERT INTO {$table} (public_token, identity_hash, source_type, source_id, source_url, destination_url, destination_host, destination_hash, label, created_at_utc) VALUES "
			. implode( ', ', $placeholders )
			. ' ON DUPLICATE KEY UPDATE id = id';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( false === $wpdb->query( $wpdb->prepare( $sql, $values ) ) ) {
			octk_log( 'registration insert failed: ' . $wpdb->last_error );
			return array();
		}

		$usable = self::reselect_and_verify( $source_type, $source_id, $missing );

		// Identities still absent: the duplicate was the token key. Retry individually.
		foreach ( array_diff_key( $missing, $usable ) as $hash_hex => $dest ) {
			$row = self::retry_with_fresh_tokens( $source_type, $source_id, $source_url, $hash_hex, $dest, $now );
			if ( null !== $row ) {
				$usable[ $hash_hex ] = $row;
			}
		}

		return $usable;
	}

	/**
	 * Reselect proposed identities; invariant-check each present row (§6).
	 *
	 * @param array<string, array{url: string, host: string}> $proposed hash_hex => dest.
	 * @return array<string, array{token: string, url: string}> verified rows.
	 */
	private static function reselect_and_verify( string $source_type, int $source_id, array $proposed ): array {
		global $wpdb;

		if ( empty( $proposed ) ) {
			return array();
		}

		$table        = octk_table_links();
		$hashes       = array_keys( $proposed );
		$placeholders = implode( ', ', array_fill( 0, count( $hashes ), 'UNHEX(%s)' ) );

		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT HEX(identity_hash) AS h, HEX(public_token) AS t, source_type, source_id, destination_url, label FROM {$table} WHERE identity_hash IN ({$placeholders})",
				$hashes
			)
		);
		if ( ! is_array( $rows ) ) {
			octk_log( 'registration reselect failed: ' . $wpdb->last_error );
			return array();
		}

		$usable = array();
		foreach ( $rows as $row ) {
			$hash_hex = strtolower( $row->h );
			if ( ! isset( $proposed[ $hash_hex ] ) ) {
				continue;
			}
			// Invariant check: a hash match whose stored source or destination
			// differs means a collision or a bad row. Never reuse it (decision 24).
			if ( $row->source_type !== $source_type || (int) $row->source_id !== $source_id || $row->destination_url !== $proposed[ $hash_hex ]['url'] ) {
				octk_log( sprintf( 'identity invariant failure for hash %s: stored source/destination differs from proposed', $hash_hex ) );
				continue;
			}
			$usable[ $hash_hex ] = array(
				'token' => strtolower( $row->t ),
				'url'   => $proposed[ $hash_hex ]['url'],
				'label' => $row->label,
			);
		}
		return $usable;
	}

	/** @return array{token: string, url: string}|null on success. */
	private static function retry_with_fresh_tokens( string $source_type, int $source_id, string $source_url, string $hash_hex, array $dest, string $now ): ?array {
		global $wpdb;

		$table = octk_table_links();

		for ( $i = 0; $i < OCTK_TOKEN_RETRIES; $i++ ) {
			$token_hex = self::new_token_hex();
			$result    = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table} (public_token, identity_hash, source_type, source_id, source_url, destination_url, destination_host, destination_hash, label, created_at_utc) VALUES (UNHEX(%s), UNHEX(%s), %s, %d, %s, %s, %s, UNHEX(%s), NULLIF(%s, ''), %s) ON DUPLICATE KEY UPDATE id = id",
					$token_hex,
					$hash_hex,
					$source_type,
					$source_id,
					$source_url,
					$dest['url'],
					$dest['host'],
					hash( 'sha256', $dest['url'] ),
					$dest['label'] ?? '',
					$now
				)
			);
			if ( false === $result ) {
				octk_log( 'registration retry failed: ' . $wpdb->last_error );
				return null;
			}
			$verified = self::reselect_and_verify( $source_type, $source_id, array( $hash_hex => $dest ) );
			if ( isset( $verified[ $hash_hex ] ) ) {
				return $verified[ $hash_hex ];
			}
		}

		octk_log( sprintf( 'registration gave up after %d token retries for hash %s', OCTK_TOKEN_RETRIES, $hash_hex ) );
		return null;
	}

	private static function source_url( string $source_type, int $source_id ): string {
		$permalink = get_permalink( $source_id );
		return is_string( $permalink ) ? $permalink : '';
	}
}
