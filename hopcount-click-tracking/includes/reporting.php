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

	/** Most host suggestions offered under the Report's host input. */
	const HOST_SUGGESTIONS = 500;

	/**
	 * Longest accepted search, in characters. Matches the destination_url
	 * column so any stored URL can be pasted whole; longer input is rejected
	 * upstream, never shortened (a shortened URL would match every URL
	 * sharing its prefix).
	 */
	const SEARCH_MAX = 2048;

	/**
	 * Characters and entities folded to their ASCII keyboard forms before
	 * substring matching, on both the needle and the haystack. Stored titles
	 * contain entities (`&amp;`, sometimes numeric ones) and texturized
	 * dashes/quotes; searchers type '&', '-' and "'".
	 *
	 * ORDERED: both sides apply the list sequentially, first entry first
	 * (fold_needle() loops, fold_sql() nests the first entry innermost), so
	 * they agree on every input. `&amp;` comes last so `&amp;#8217;` folds
	 * to `&#8217;`, not to "'". Entities not listed stay literal on both sides.
	 */
	private const FOLD_MAP = array(
		'–'       => '-',
		'—'       => '-',
		'’'       => "'",
		'‘'       => "'",
		'&#8211;' => '-',
		'&#8212;' => '-',
		'&#8217;' => "'",
		'&#8216;' => "'",
		'&#039;'  => "'",
		'&quot;'  => '"',
		'&#038;'  => '&',
		'&amp;'   => '&',
	);

	public static function fold_needle( string $needle ): string {
		foreach ( self::FOLD_MAP as $from => $to ) {
			$needle = str_replace( $from, $to, $needle );
		}
		return $needle;
	}

	/**
	 * Wrap a SQL expression in REPLACE() calls applying FOLD_MAP, in the
	 * same order as fold_needle().
	 */
	public static function fold_sql( string $expr ): string {
		foreach ( self::FOLD_MAP as $from => $to ) {
			$expr = "REPLACE({$expr}, '{$from}', '" . str_replace( "'", "''", $to ) . "')";
		}
		return $expr;
	}

	/**
	 * Post types whose titles are searchable: public, minus attachments.
	 *
	 * @return string[]
	 */
	public static function title_post_types(): array {
		return array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
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

	/** Page-grouped variants: the tiebreaker must be the group key. */
	private const ORDER_SQL_PAGE = array(
		'clicks_desc'        => 'ORDER BY clicks DESC, l.source_id ASC',
		'clicks_asc'         => 'ORDER BY clicks ASC, l.source_id ASC',
		'last_activity_desc' => 'ORDER BY last_bucket DESC, l.source_id ASC',
		'last_activity_asc'  => 'ORDER BY last_bucket ASC, l.source_id ASC',
	);

	/** Resolve request orderby/order to one of the ORDER_SQL literals. */
	private static function order_sql( array $args ): string {
		$orderby = ( 'last_activity' === ( $args['orderby'] ?? '' ) ) ? 'last_activity' : 'clicks';
		$order   = ( 'asc' === strtolower( $args['order'] ?? '' ) ) ? 'asc' : 'desc';
		$map     = self::group_by_page( $args ) ? self::ORDER_SQL_PAGE : self::ORDER_SQL;
		return $map[ "{$orderby}_{$order}" ];
	}

	/** Whether rows are rolled up to one per source post. */
	private static function group_by_page( array $args ): bool {
		return 'page' === ( $args['group'] ?? '' );
	}

	/**
	 * FROM/JOIN clause shared by every report query, plus its prepare()
	 * values: %i for each table, then one %s per post type when the posts
	 * join is present. The posts join is added only for an all-mode search
	 * (it needs titles); it is a primary-key join restricted to published,
	 * searchable posts, so it matches at most one row per link and never
	 * multiplies aggregates.
	 *
	 * @return array{0: string, 1: array}
	 */
	private static function from_sql( array $args ): array {
		global $wpdb;

		$sql    = 'FROM %i c JOIN %i l ON l.id = c.link_id ' . self::overrides_join_sql();
		$values = array( octk_table_clicks(), octk_table_links(), octk_table_overrides() );

		$types = self::all_search_types( $args );
		if ( ! empty( $types ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
			$sql         .= " LEFT JOIN %i p ON p.ID = l.source_id AND p.post_status = 'publish' AND p.post_type IN ({$placeholders})";
			$values       = array_merge( $values, array( $wpdb->posts ), $types );
		}
		return array( $sql, $values );
	}

	/**
	 * Post types for the all-mode title join; empty when there is no
	 * all-mode search (or, defensively, no searchable types), in which case
	 * all mode matches destinations only.
	 *
	 * @return string[]
	 */
	private static function all_search_types( array $args ): array {
		if ( empty( $args['all_search'] ) || empty( self::search_tokens( (string) $args['all_search'] ) ) ) {
			return array();
		}
		return self::title_post_types();
	}

	/**
	 * Paginated rows for a UTC range: one per link identity, or one per
	 * source post when $group is 'page'.
	 *
	 * @param array $args {
	 *     @type string   $start_utc          'Y-m-d H:i:s' inclusive.
	 *     @type string   $end_utc            'Y-m-d H:i:s' exclusive.
	 *     @type string   $host               Optional destination host filter.
	 *     @type int[]    $source_ids         Optional source post filter (capped upstream).
	 *     @type string   $destination_search Optional substring match on destination URL or label.
	 *     @type string   $all_search         Optional per-word match: every word in the
	 *                                        source title, destination URL or label.
	 *     @type string   $group              'link' (default) | 'page'.
	 *     @type string   $orderby            'clicks' | 'last_activity'.
	 *     @type string   $order              'asc' | 'desc'.
	 *     @type int      $page               1-based.
	 *     @type int      $per_page           One of PER_PAGE_CHOICES.
	 * }
	 * @return object|null {rows: object[], total: int} or null on query failure.
	 */
	public static function query_rows( array $args ) {
		global $wpdb;

		list( $from, $from_values ) = self::from_sql( $args );
		list( $where, $values )     = self::build_where( $args );

		$per_page = in_array( (int) ( $args['per_page'] ?? 0 ), self::PER_PAGE_CHOICES, true ) ? (int) $args['per_page'] : self::PER_PAGE_CHOICES[0];
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$count_expr = self::group_by_page( $args ) ? 'COUNT(DISTINCT l.source_id)' : 'COUNT(DISTINCT c.link_id)';
		$total      = $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $count_expr is a literal; $from / $where are placeholder-only fragments (from_sql, build_where); tables and values are prepared.
			$wpdb->prepare( "SELECT {$count_expr} {$from} WHERE {$where}", array_merge( $from_values, $values ) )
		);
		if ( null === $total && '' !== $wpdb->last_error ) {
			octk_log( 'report count failed: ' . $wpdb->last_error );
			return null;
		}

		$sql  = self::row_select_sql( $args, $from, $where );
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is placeholder-only SQL (row_select_sql); tables and values are prepared.
			$wpdb->prepare( $sql, array_merge( $from_values, $values, array( $per_page, $offset ) ) )
		);
		// get_results() answers a failed query with an empty array, not null:
		// last_error (reset per query) is what tells failure from no rows.
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
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
	 * Page grouping selects per-post aggregates instead: the number of
	 * distinct matching links, their summed clicks and latest bucket.
	 *
	 * Returns placeholder-only SQL: the from_sql() placeholders, the
	 * build_where() placeholders, then %d LIMIT / %d OFFSET. The ORDER BY is
	 * one of the ORDER_SQL literals.
	 */
	private static function row_select_sql( array $args, string $from, string $where ): string {
		$order_sql = self::order_sql( $args );

		if ( self::group_by_page( $args ) ) {
			return "SELECT MAX(l.source_type) AS source_type, l.source_id, MAX(l.source_url) AS source_url,
					COUNT(DISTINCT l.id) AS links,
					SUM(c.hits) AS clicks, MAX(c.bucket_start_utc) AS last_bucket
					{$from}
					WHERE {$where}
					GROUP BY l.source_id
					{$order_sql}
					LIMIT %d OFFSET %d";
		}

		return "SELECT l.source_type, l.source_id, l.source_url, l.destination_url, l.destination_host,
				COALESCE(o.label, l.label) AS label,
				CASE WHEN o.label IS NOT NULL THEN 'manual' WHEN l.label IS NOT NULL THEN 'auto' ELSE '' END AS label_origin,
				SUM(c.hits) AS clicks, MAX(c.bucket_start_utc) AS last_bucket
				{$from}
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
	 * report allow-list with the group key as the tiebreaker, so batches
	 * are stable.
	 *
	 * @return object[]|null Rows, or null on query failure.
	 */
	public static function export_rows( array $args, int $limit, int $offset ) {
		global $wpdb;

		list( $from, $from_values ) = self::from_sql( $args );
		list( $where, $values )     = self::build_where( $args );

		$limit  = max( 1, $limit );
		$offset = max( 0, $offset );

		$sql  = self::row_select_sql( $args, $from, $where );
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is placeholder-only SQL (row_select_sql); tables and values are prepared.
			$wpdb->prepare( $sql, array_merge( $from_values, $values, array( $limit, $offset ) ) )
		);
		// get_results() answers a failed query with an empty array, not null:
		// last_error (reset per query) is what tells failure from no rows.
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
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

		list( $from, $from_values ) = self::from_sql( $args );
		list( $where, $values )     = self::build_where( $args );

		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $from / $where are placeholder-only fragments (from_sql, build_where); tables and values are prepared.
			$wpdb->prepare(
				"SELECT COALESCE(SUM(c.hits), 0) AS clicks, COUNT(DISTINCT c.link_id) AS identities,
				COUNT(DISTINCT l.source_id) AS sources, MAX(c.bucket_start_utc) AS last_bucket
				{$from} WHERE {$where}",
				array_merge( $from_values, $values )
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
	 * Host suggestions for the filter input: hosts clicked in the UTC range,
	 * most-clicked first, bounded. Suggestions only; the input accepts any
	 * host, so the bound never hides one.
	 *
	 * @return string[]
	 */
	public static function hosts( string $start_utc, string $end_utc, int $limit = self::HOST_SUGGESTIONS ): array {
		global $wpdb;
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT l.destination_host FROM %i c JOIN %i l ON l.id = c.link_id
				WHERE c.bucket_start_utc >= %s AND c.bucket_start_utc < %s
				GROUP BY l.destination_host
				ORDER BY SUM(c.hits) DESC, l.destination_host ASC
				LIMIT %d',
				octk_table_clicks(),
				octk_table_links(),
				$start_utc,
				$end_utc,
				max( 1, $limit )
			)
		);
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

		if ( ! empty( $args['all_search'] ) ) {
			// All mode: every word must appear in the source title, the URL
			// or the resolved label, and different words may match in
			// different places ("joe rogan hone" = Hone links on Joe Rogan
			// posts). p is the published-only posts join from from_sql(); a
			// NULL title (draft, deleted) simply fails its LIKE. Without
			// searchable post types the title branch is left out.
			$label_expr = self::fold_sql( 'COALESCE(o.label, l.label)' );
			$has_titles = ! empty( self::all_search_types( $args ) );
			$title_expr = self::fold_sql( 'p.post_title' );
			foreach ( self::search_tokens( (string) $args['all_search'] ) as $token ) {
				$like = '%' . $wpdb->esc_like( $token ) . '%';
				if ( $has_titles ) {
					$where   .= " AND ({$title_expr} LIKE %s OR l.destination_url LIKE %s OR {$label_expr} LIKE %s)";
					$values[] = $like;
				} else {
					$where .= " AND (l.destination_url LIKE %s OR {$label_expr} LIKE %s)";
				}
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
