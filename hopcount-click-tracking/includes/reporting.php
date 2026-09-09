<?php
/**
 * Reporting service (scope §8, decision 32): the only component that
 * constructs report SQL. Typed results, never HTML. Sort columns and
 * directions come from allow-lists; pagination happens in SQL; date
 * predicates are sargable UTC ranges. Report output never contains
 * public_token, identity_hash, or internal database ids.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCTK_Reporting {

	const PER_PAGE_CHOICES = array( 25, 50, 100 );
	const MAX_SOURCE_IDS   = 100;

	/**
	 * Typographic characters folded to their ASCII keyboard forms before
	 * substring matching, on both the needle and the haystack. Stored titles
	 * and captured labels contain texturized en/em dashes and curly quotes;
	 * searchers type '-' and "'".
	 */
	private const FOLD_MAP = array(
		'–' => '-',
		'—' => '-',
		'’' => "'",
		'‘' => "'",
	);

	public static function fold_needle( string $needle ): string {
		return strtr( $needle, self::FOLD_MAP );
	}

	/**
	 * Wrap a SQL expression in REPLACE() calls applying FOLD_MAP.
	 */
	public static function fold_sql( string $expr ): string {
		foreach ( self::FOLD_MAP as $from => $to ) {
			$expr = "REPLACE({$expr}, '{$from}', '" . str_replace( "'", "''", $to ) . "')";
		}
		return $expr;
	}

	/**
	 * Split a search string into folded whitespace-separated tokens. Every
	 * token must match somewhere in the haystack, in any order, so
	 * "gary diet routine" finds "Gary Brecka's Diet ... Routine ...".
	 * Bounded so a pathological input cannot balloon the SQL.
	 *
	 * @return string[] At most 10 tokens; empty for a blank needle.
	 */
	public static function search_tokens( string $needle ): array {
		$tokens = preg_split( '/\s+/', self::fold_needle( trim( $needle ) ), -1, PREG_SPLIT_NO_EMPTY );
		return array_slice( is_array( $tokens ) ? $tokens : array(), 0, 10 );
	}

	/**
	 * Complete ORDER BY clauses, keyed by "<orderby>_<order>". Chosen by
	 * array lookup only, so no request value is ever concatenated into SQL.
	 */
	private const ORDER_SQL = array(
		'clicks_desc'        => 'ORDER BY clicks DESC, l.id ASC',
		'clicks_asc'         => 'ORDER BY clicks ASC, l.id ASC',
		'last_activity_desc' => 'ORDER BY last_bucket DESC, l.id ASC',
		'last_activity_asc'  => 'ORDER BY last_bucket ASC, l.id ASC',
	);

	/** Resolve request orderby/order to one of the ORDER_SQL literals. */
	private static function order_sql( array $args ): string {
		$orderby = ( 'last_activity' === ( $args['orderby'] ?? '' ) ) ? 'last_activity' : 'clicks';
		$order   = ( 'asc' === strtolower( $args['order'] ?? '' ) ) ? 'asc' : 'desc';
		return self::ORDER_SQL[ "{$orderby}_{$order}" ];
	}

	/** Table identifiers for the %i placeholders, in the order the queries use them. */
	private static function join_tables(): array {
		return array( octk_table_clicks(), octk_table_links(), octk_table_overrides() );
	}

	/**
	 * Paginated per-identity rows for a UTC range.
	 *
	 * @param array $args {
	 *     @type string   $start_utc          'Y-m-d H:i:s' inclusive.
	 *     @type string   $end_utc            'Y-m-d H:i:s' exclusive.
	 *     @type string   $host               Optional destination host filter.
	 *     @type int[]    $source_ids         Optional source post filter (capped upstream).
	 *     @type string   $destination_search Optional substring match on destination URL or label.
	 *     @type string   $orderby            'clicks' | 'last_activity'.
	 *     @type string   $order              'asc' | 'desc'.
	 *     @type int      $page               1-based.
	 *     @type int      $per_page           One of PER_PAGE_CHOICES.
	 * }
	 * @return object|null {rows: object[], total: int} or null on query failure.
	 */
	public static function query_rows( array $args ) {
		global $wpdb;

		$join = self::overrides_join_sql();

		list( $where, $values ) = self::build_where( $args );

		$per_page = in_array( (int) ( $args['per_page'] ?? 0 ), self::PER_PAGE_CHOICES, true ) ? (int) $args['per_page'] : self::PER_PAGE_CHOICES[0];
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$total = $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $join / $where are placeholder-only fragments (overrides_join_sql, build_where); tables and values are prepared.
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT c.link_id) FROM %i c JOIN %i l ON l.id = c.link_id {$join} WHERE {$where}",
				array_merge( self::join_tables(), $values )
			)
		);
		if ( null === $total && '' !== $wpdb->last_error ) {
			octk_log( 'report count failed: ' . $wpdb->last_error );
			return null;
		}

		$sql  = self::row_select_sql( $where, self::order_sql( $args ) );
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is placeholder-only SQL (row_select_sql); tables and values are prepared.
			$wpdb->prepare( $sql, array_merge( self::join_tables(), $values, array( $per_page, $offset ) ) )
		);
		if ( ! is_array( $rows ) ) {
			octk_log( 'report query failed: ' . $wpdb->last_error );
			return null;
		}

		return (object) array(
			'rows'  => $rows,
			'total' => (int) $total,
		);
	}

	/**
	 * Shared row SELECT for report + export. Labels resolve as manual
	 * override first, then auto-captured anchor text (link-labels plan).
	 * Override JOIN matches on hash AND exact url; BINARY because the
	 * default utf8mb4 collation is case-insensitive and destination
	 * equality is a byte-level promise. Never selects tokens, identity
	 * hashes, or internal ids.
	 *
	 * Returns placeholder-only SQL: %i for the three tables (join_tables()
	 * order), the build_where() placeholders, then %d LIMIT / %d OFFSET.
	 * $order_sql is one of the ORDER_SQL literals.
	 */
	private static function row_select_sql( string $where, string $order_sql ): string {
		$join = self::overrides_join_sql();

		return "SELECT l.source_type, l.source_id, l.source_url, l.destination_url, l.destination_host,
				COALESCE(o.label, l.label) AS label,
				CASE WHEN o.label IS NOT NULL THEN 'manual' WHEN l.label IS NOT NULL THEN 'auto' ELSE '' END AS label_origin,
				SUM(c.hits) AS clicks, MAX(c.bucket_start_utc) AS last_bucket
				FROM %i c
				JOIN %i l ON l.id = c.link_id
				{$join}
				WHERE {$where}
				GROUP BY l.id
				{$order_sql}
				LIMIT %d OFFSET %d";
	}

	/**
	 * The overrides LEFT JOIN, shared by every report query so build_where()
	 * may reference o.label. Matches at most one row per link (one override
	 * per exact destination URL), so it never multiplies aggregate rows.
	 */
	private static function overrides_join_sql(): string {
		return 'LEFT JOIN %i o ON o.destination_hash = l.destination_hash AND BINARY o.destination_url = BINARY l.destination_url';
	}

	/**
	 * Unpaginated slice of the same aggregation for CSV export. Callers
	 * (includes/export.php) iterate in bounded batches; sorting reuses the
	 * report allow-list with l.id as the tiebreaker, so batches are stable.
	 *
	 * @return object[]|null Rows, or null on query failure.
	 */
	public static function export_rows( array $args, int $limit, int $offset ) {
		global $wpdb;

		list( $where, $values ) = self::build_where( $args );

		$limit  = max( 1, $limit );
		$offset = max( 0, $offset );

		$sql  = self::row_select_sql( $where, self::order_sql( $args ) );
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is placeholder-only SQL (row_select_sql); tables and values are prepared.
			$wpdb->prepare( $sql, array_merge( self::join_tables(), $values, array( $limit, $offset ) ) )
		);
		if ( ! is_array( $rows ) ) {
			octk_log( 'export query failed: ' . $wpdb->last_error );
			return null;
		}
		return $rows;
	}

	/**
	 * Summary figures for the same filters (scope §8 summary row).
	 *
	 * @return object|null {clicks, identities, sources, last_bucket}
	 */
	public static function summary( array $args ) {
		global $wpdb;

		$join = self::overrides_join_sql();

		list( $where, $values ) = self::build_where( $args );

		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $join / $where are placeholder-only fragments (overrides_join_sql, build_where); tables and values are prepared.
			$wpdb->prepare(
				"SELECT COALESCE(SUM(c.hits), 0) AS clicks, COUNT(DISTINCT c.link_id) AS identities,
				COUNT(DISTINCT l.source_id) AS sources, MAX(c.bucket_start_utc) AS last_bucket
				FROM %i c JOIN %i l ON l.id = c.link_id {$join} WHERE {$where}",
				array_merge( self::join_tables(), $values )
			)
		);
		if ( ! is_object( $row ) ) {
			octk_log( 'report summary failed: ' . $wpdb->last_error );
			return null;
		}
		$row->clicks     = (int) $row->clicks;
		$row->identities = (int) $row->identities;
		$row->sources    = (int) $row->sources;
		return $row;
	}

	/**
	 * Distinct destination hosts for the filter control (bounded).
	 *
	 * @return string[]
	 */
	public static function hosts(): array {
		global $wpdb;
		$links = octk_table_links();
		$rows  = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT destination_host FROM %i ORDER BY destination_host ASC LIMIT 200', $links ) );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Status-page counts, short-TTL cached and approximate (decision 41).
	 *
	 * @return object {links, buckets, last_bucket}
	 */
	public static function status_counts() {
		$cached = get_transient( 'octk_status_counts' );
		if ( is_object( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$links  = octk_table_links();
		$clicks = octk_table_clicks();

		$counts = (object) array(
			'links'       => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $links ) ),
			'buckets'     => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $clicks ) ),
			'last_bucket' => $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(bucket_start_utc) FROM %i', $clicks ) ),
		);
		set_transient( 'octk_status_counts', $counts, 5 * MINUTE_IN_SECONDS );
		return $counts;
	}

	/**
	 * Whether the schema the report queries need is fully present: both v1
	 * tables AND every v2 object (label columns, hash index, override table).
	 * A partial migration must surface the error state here rather than
	 * letting the JOIN/SELECT fail mid-report.
	 */
	public static function schema_ready(): bool {
		return octk_tables_exist() && octk_schema_v2_ready();
	}

	/**
	 * @return array{0: string, 1: array} WHERE fragment (placeholders) and values.
	 */
	private static function build_where( array $args ): array {
		global $wpdb;

		$where  = 'c.bucket_start_utc >= %s AND c.bucket_start_utc < %s';
		$values = array( (string) $args['start_utc'], (string) $args['end_utc'] );

		if ( ! empty( $args['host'] ) ) {
			$where   .= ' AND l.destination_host = %s';
			$values[] = (string) $args['host'];
		}

		if ( ! empty( $args['destination_search'] ) ) {
			// Every search word must appear in the URL (which contains the
			// host) or the resolved label, in any order. Labels come from
			// texturized render output, so both sides fold via FOLD_MAP
			// (search_tokens folds the needle, so direct callers like tests
			// need not pre-fold).
			$label_expr = self::fold_sql( 'COALESCE(o.label, l.label)' );
			foreach ( self::search_tokens( (string) $args['destination_search'] ) as $token ) {
				$like     = '%' . $wpdb->esc_like( $token ) . '%';
				$where   .= " AND (l.destination_url LIKE %s OR {$label_expr} LIKE %s)";
				$values[] = $like;
				$values[] = $like;
			}
		}

		if ( ! empty( $args['source_ids'] ) && is_array( $args['source_ids'] ) ) {
			$ids          = array_slice( array_map( 'intval', $args['source_ids'] ), 0, self::MAX_SOURCE_IDS );
			$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
			$where       .= " AND l.source_id IN ({$placeholders})";
			$values       = array_merge( $values, $ids );
		}

		return array( $where, $values );
	}
}
