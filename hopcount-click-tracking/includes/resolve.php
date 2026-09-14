<?php
/**
 * Same-site redirect resolution (1.2.0).
 *
 * A link whose href points at this site (root-relative, or absolute on the
 * home/site host) may be a cloaked outbound redirect (Redirection, Pretty
 * Links, an nginx rule, ...). Instead of reading any plugin's rule table the
 * plugin asks the site itself: a signed HEAD request for that path, with
 * redirects disabled, sent to the server's own address. An off-site Location
 * answer makes the link outbound and it is tracked under that destination;
 * any other answer leaves it internal.
 *
 * Everything here is fail-soft and bounded: a small durable table holds one
 * row per distinct same-site URL, cron refreshes rows on a TTL, renders only
 * ever probe rows that were never confirmed and within a small budget, and a
 * breaker, a daily cap and a kill switch sit above all of it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const OCTK_RESOLVE_HOOK           = 'octk_resolve_run';
const OCTK_RESOLVE_UA             = 'HopcountClickTracking/' . OCTK_VERSION . ' (+resolve)';
const OCTK_RESOLVE_HEADER         = 'X-Octk-Resolve';
const OCTK_RESOLVE_MAX_HOPS       = 3;
const OCTK_RESOLVE_RENDER_PROBES  = 3;
const OCTK_RESOLVE_RENDER_SECONDS = 3.0;
const OCTK_RESOLVE_CRON_PROBES    = 20;
const OCTK_RESOLVE_CRON_SECONDS   = 20.0;
const OCTK_RESOLVE_PROBE_TIMEOUT  = 2.0;
const OCTK_RESOLVE_CLAIM_SECONDS  = 300;
const OCTK_RESOLVE_SIG_WINDOW     = 300;
const OCTK_RESOLVE_DAILY_CAP      = 10000;
const OCTK_RESOLVE_BACKFILL_BATCH = 50;
const OCTK_RESOLVE_DOWN_KEY       = 'octk_resolve_down';
const OCTK_RESOLVE_CACHE_GROUP    = 'octk';

/* ---------------------------------------------------------------------- */
/* Tables, options, switches                                                */
/* ---------------------------------------------------------------------- */

function octk_table_redirects(): string {
	global $wpdb;
	return $wpdb->prefix . 'octk_redirects';
}

function octk_table_redirect_sources(): string {
	global $wpdb;
	return $wpdb->prefix . 'octk_redirect_sources';
}

/**
 * Kill switch: constant, filter, schema and secret must all allow it.
 * Off means behaviour identical to 1.1.1 (same-site links ignored).
 */
function octk_resolve_enabled(): bool {
	if ( defined( 'OCTK_DISABLE_RESOLVE' ) && OCTK_DISABLE_RESOLVE ) {
		return false;
	}
	if ( ! (bool) apply_filters( 'octk_resolve_enabled', true ) ) {
		return false;
	}
	if ( ! function_exists( 'octk_schema_v3_ready' ) || ! octk_schema_v3_ready() ) {
		return false;
	}
	return '' !== octk_resolve_secret();
}

/**
 * Per-install signing secret, 32 random bytes stored as hex. Created
 * idempotently by migration 3 and at activation; rotated by Clear. Empty
 * means nothing is ever signed or verified.
 */
function octk_resolve_secret(): string {
	$hex = get_option( 'octk_resolve_secret', '' );
	return ( is_string( $hex ) && 1 === preg_match( '/^[0-9a-f]{64}$/', $hex ) ) ? $hex : '';
}

function octk_resolve_ensure_secret(): void {
	if ( '' === octk_resolve_secret() ) {
		update_option( 'octk_resolve_secret', bin2hex( random_bytes( 32 ) ), false );
	}
}

function octk_resolve_generation(): int {
	return max( 1, (int) get_option( 'octk_resolve_generation', 1 ) );
}

/**
 * Clear resolution cache: every row becomes due but keeps its last verdict
 * until re-confirmed (stale-while-revalidate). Also rotates the secret, drops
 * the breaker and forgets the origin route so it is re-tested.
 */
function octk_resolve_clear(): void {
	update_option( 'octk_resolve_generation', octk_resolve_generation() + 1, false );
	update_option( 'octk_resolve_secret', bin2hex( random_bytes( 32 ) ), false );
	delete_transient( OCTK_RESOLVE_DOWN_KEY );
	delete_option( 'octk_probe_origin' );
	delete_option( 'octk_probe_origin_next_try' );
	OCTK_Resolver::reset_memo();
	octk_schedule_resolve( true );
}

/* ---------------------------------------------------------------------- */
/* Schema                                                                  */
/* ---------------------------------------------------------------------- */

function octk_schema_v3_ready( bool $recheck = false ): bool {
	static $ready = null;
	if ( null !== $ready && ! $recheck ) {
		return $ready;
	}
	global $wpdb;
	$suppress = $wpdb->suppress_errors( true );
	$a        = $wpdb->query( $wpdb->prepare( 'SELECT url_hash, url, verdict, destination_url, destination_host, checked_at_utc, next_check_utc, attempts, last_error, claimed_until_utc, claim_token, claim_generation, generation FROM %i LIMIT 1', octk_table_redirects() ) );
	$b        = $wpdb->query( $wpdb->prepare( 'SELECT url_hash, source_type, source_id FROM %i LIMIT 1', octk_table_redirect_sources() ) );
	$wpdb->suppress_errors( $suppress );
	$ready = false !== $a && false !== $b;
	return $ready;
}

/**
 * v3: redirect table, sources table, secret and generation, cron, backfill.
 * Idempotent; every object is checked or created with IF NOT EXISTS.
 */
function octk_migration_3(): bool {
	global $wpdb;

	$charset_collate = $wpdb->get_charset_collate();

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $charset_collate is wpdb::get_charset_collate(); the table name is prepared.
	$ok = false !== $wpdb->query( $wpdb->prepare(
		"CREATE TABLE IF NOT EXISTS %i (
			url_hash BINARY(16) NOT NULL,
			url VARCHAR(2048) NOT NULL,
			verdict VARCHAR(10) NOT NULL DEFAULT 'pending',
			destination_url VARCHAR(2048) NULL DEFAULT NULL,
			destination_host VARCHAR(255) NULL DEFAULT NULL,
			checked_at_utc DATETIME NULL DEFAULT NULL,
			next_check_utc DATETIME NOT NULL,
			attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			last_error VARCHAR(255) NULL DEFAULT NULL,
			claimed_until_utc DATETIME NULL DEFAULT NULL,
			claim_token BINARY(16) NULL DEFAULT NULL,
			claim_generation INT UNSIGNED NULL DEFAULT NULL,
			generation INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY (url_hash),
			KEY next_check_utc (next_check_utc),
			KEY generation (generation)
		) ENGINE=InnoDB {$charset_collate}",
		octk_table_redirects()
	) );
	if ( ! $ok ) {
		octk_log( 'migration 3: creating redirects table failed: ' . $wpdb->last_error );
		return false;
	}

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $charset_collate is wpdb::get_charset_collate(); the table name is prepared.
	$ok = false !== $wpdb->query( $wpdb->prepare(
		"CREATE TABLE IF NOT EXISTS %i (
			url_hash BINARY(16) NOT NULL,
			source_type VARCHAR(20) NOT NULL,
			source_id BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY (url_hash, source_type, source_id),
			KEY source (source_type, source_id)
		) ENGINE=InnoDB {$charset_collate}",
		octk_table_redirect_sources()
	) );
	if ( ! $ok ) {
		octk_log( 'migration 3: creating sources table failed: ' . $wpdb->last_error );
		return false;
	}

	if ( ! octk_schema_v3_ready( true ) ) {
		return false;
	}

	// Secret and generation before any cron exists: an update without
	// reactivation must still be able to sign its first probe.
	octk_resolve_ensure_secret();
	add_option( 'octk_resolve_generation', 1, '', false );
	add_option( 'octk_resolve_backfill', 0, '', false );
	octk_schedule_resolve( true );

	return '' !== octk_resolve_secret();
}

/* ---------------------------------------------------------------------- */
/* Cron scheduling                                                         */
/* ---------------------------------------------------------------------- */

/**
 * The hourly sweep is always present; a one-minute successor is added by
 * each run that finds due rows (before it processes anything).
 */
function octk_schedule_resolve( bool $soon = false ): void {
	if ( ! wp_next_scheduled( OCTK_RESOLVE_HOOK ) ) {
		$result = wp_schedule_event( time() + ( $soon ? MINUTE_IN_SECONDS : HOUR_IN_SECONDS ), 'hourly', OCTK_RESOLVE_HOOK, array(), true );
		if ( true !== $result ) {
			octk_log( 'could not schedule the hourly resolve sweep: ' . ( is_wp_error( $result ) ? $result->get_error_message() : 'rejected' ) );
		}
	} elseif ( $soon ) {
		octk_schedule_resolve_successor();
	}
}

/**
 * The successor carries its own argument so WordPress treats it as a
 * distinct event: a single event with the same hook and arguments as the
 * hourly sweep is rejected whenever that sweep is due within ten minutes,
 * which would silently leave due rows waiting for the hour.
 */
const OCTK_RESOLVE_SUCCESSOR_ARGS = array( 'successor' );

function octk_schedule_resolve_successor(): bool {
	$soonest = time() + MINUTE_IN_SECONDS + 5;
	$hourly  = wp_next_scheduled( OCTK_RESOLVE_HOOK );
	if ( false !== $hourly && $hourly <= $soonest ) {
		return true; // the sweep itself is the successor.
	}
	$existing = wp_next_scheduled( OCTK_RESOLVE_HOOK, OCTK_RESOLVE_SUCCESSOR_ARGS );
	if ( false !== $existing && $existing <= $soonest ) {
		return true;
	}
	$result = wp_schedule_single_event( time() + MINUTE_IN_SECONDS, OCTK_RESOLVE_HOOK, OCTK_RESOLVE_SUCCESSOR_ARGS, true );
	if ( true !== $result ) {
		octk_log( 'could not schedule the resolve successor: ' . ( is_wp_error( $result ) ? $result->get_error_message() : 'rejected' ) );
		return false;
	}
	return true;
}

function octk_unschedule_resolve(): void {
	wp_unschedule_hook( OCTK_RESOLVE_HOOK ); // every event of the hook, with or without arguments.
}

/* ---------------------------------------------------------------------- */
/* URLs                                                                    */
/* ---------------------------------------------------------------------- */

/**
 * Hosts that count as "this site" for probing: home and site hosts plus
 * their www/non-www aliases. Extras from Settings are internal but never
 * probed.
 *
 * @return string[] lowercase.
 */
function octk_site_hosts(): array {
	$hosts = array();
	foreach ( array( home_url(), site_url() ) as $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			continue;
		}
		$host    = strtolower( $host );
		$hosts[] = $host;
		$hosts[] = str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : 'www.' . $host;
	}
	return array_values( array_unique( $hosts ) );
}

/**
 * Logical URL for a same-site href, or null when the href is not same-site.
 * Root-relative hrefs take scheme, host and port from home_url() and keep
 * path and query exactly as written (never home_url( $path ): a subdirectory
 * install would prepend the directory). Absolute hrefs are kept as written.
 * Fragment dropped. Hosts listed under Settings > Internal hosts are never
 * same-site for this purpose.
 */
function octk_same_site_logical_url( string $raw ): ?string {
	$raw = trim( $raw );
	if ( '' === $raw || strlen( $raw ) > OCTK_MAX_URL_BYTES ) {
		return null;
	}

	if ( '/' === $raw[0] ) {
		if ( isset( $raw[1] ) && '/' === $raw[1] ) {
			return null; // protocol-relative: has a host, handled below if absolute.
		}
		$home = wp_parse_url( home_url() );
		if ( ! is_array( $home ) || empty( $home['host'] ) ) {
			return null;
		}
		$scheme = strtolower( $home['scheme'] ?? 'http' );
		$port   = isset( $home['port'] ) ? ':' . (int) $home['port'] : '';
		$parts  = wp_parse_url( 'http://x' . $raw );
		if ( ! is_array( $parts ) ) {
			return null;
		}
		$path  = $parts['path'] ?? '/';
		$query = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';
		return $scheme . '://' . strtolower( $home['host'] ) . $port . $path . $query;
	}

	$parts = wp_parse_url( $raw );
	if ( ! is_array( $parts ) ) {
		return null;
	}
	$scheme = strtolower( $parts['scheme'] ?? '' );
	$host   = strtolower( $parts['host'] ?? '' );
	if ( ( 'http' !== $scheme && 'https' !== $scheme ) || '' === $host || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
		return null;
	}
	if ( ! in_array( $host, octk_site_hosts(), true ) ) {
		return null;
	}
	$extra = get_option( 'octk_internal_hosts', array() );
	if ( is_array( $extra ) && in_array( $host, array_map( 'strtolower', array_map( 'trim', array_filter( $extra, 'is_string' ) ) ), true ) ) {
		return null;
	}
	$port = '';
	if ( isset( $parts['port'] ) && (int) $parts['port'] !== ( 'http' === $scheme ? 80 : 443 ) ) {
		$port = ':' . (int) $parts['port'];
	}
	$path  = $parts['path'] ?? '';
	$path  = '' === $path ? '/' : $path;
	$query = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';
	$url   = $scheme . '://' . $host . $port . $path . $query;
	return strlen( $url ) > OCTK_MAX_URL_BYTES ? null : $url;
}

function octk_resolve_hash( string $logical_url ): string {
	return md5( $logical_url ); // hex; UNHEX() at the SQL boundary.
}

/**
 * Same-site logical URLs referenced by anchors in a piece of HTML, unique.
 *
 * @return string[]
 */
function octk_scan_same_site_urls( string $html ): array {
	$urls = array();
	if ( '' === $html || false === stripos( $html, '<a' ) ) {
		return $urls;
	}
	$processor = new WP_HTML_Tag_Processor( $html );
	while ( $processor->next_tag( 'a' ) ) {
		$href = $processor->get_attribute( 'href' );
		if ( ! is_string( $href ) ) {
			continue;
		}
		$logical = octk_same_site_logical_url( $href );
		if ( null !== $logical ) {
			$urls[ $logical ] = $logical;
		}
	}
	return array_values( $urls );
}

/* ---------------------------------------------------------------------- */
/* Signing                                                                 */
/* ---------------------------------------------------------------------- */

/**
 * Identity that is signed: host (no port) and path?query. The scheme is
 * deliberately excluded because the backend may receive an https URL over
 * plain http behind upstream TLS termination.
 */
function octk_probe_identity( string $logical_url ): ?array {
	$parts = wp_parse_url( $logical_url );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
		return null;
	}
	$path  = $parts['path'] ?? '/';
	$path  = '' === $path ? '/' : $path;
	$query = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';
	return array( strtolower( $parts['host'] ), $path . $query );
}

function octk_probe_sign( int $time, string $via, string $host, string $path_query ): string {
	$secret = octk_resolve_secret();
	if ( '' === $secret ) {
		return '';
	}
	return hash_hmac( 'sha256', $time . '|' . $via . '|' . $host . '|' . $path_query, hex2bin( $secret ) );
}

/** Header value for a probe of $logical_url, or '' when nothing can be signed. */
function octk_probe_header_value( string $logical_url, string $via ): string {
	$identity = octk_probe_identity( $logical_url );
	if ( null === $identity ) {
		return '';
	}
	$time = time();
	$sig  = octk_probe_sign( $time, $via, $identity[0], $identity[1] );
	return '' === $sig ? '' : $time . '.' . $via . '.' . $sig;
}

/**
 * Verify the incoming probe header against this request's own host and URI.
 * Returns the signed "via" (origin|public) or null for absent, malformed,
 * expired or mismatched headers, which are ignored entirely.
 */
function octk_verify_probe_header(): ?string {
	if ( ! octk_resolve_enabled() ) {
		return null;
	}
	$raw = isset( $_SERVER['HTTP_X_OCTK_RESOLVE'] ) ? (string) $_SERVER['HTTP_X_OCTK_RESOLVE'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- verified by HMAC below.
	if ( 1 !== preg_match( '/^(\d{1,12})\.(origin|public)\.([0-9a-f]{64})$/', $raw, $m ) ) {
		return null;
	}
	$time = (int) $m[1];
	if ( abs( time() - $time ) > OCTK_RESOLVE_SIG_WINDOW ) {
		return null;
	}
	$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- part of the signed identity.
	$host = preg_replace( '/:\d+$/', '', $host );
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- part of the signed identity.
	if ( '' === $host || '' === $uri ) {
		return null;
	}
	$expected = octk_probe_sign( $time, $m[2], $host, $uri );
	if ( '' === $expected || ! hash_equals( $expected, $m[3] ) ) {
		return null;
	}
	return $m[2];
}

function octk_request_is_from_self(): bool {
	$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared, not output.
	$server = isset( $_SERVER['SERVER_ADDR'] ) ? (string) $_SERVER['SERVER_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared, not output.
	if ( '' === $remote ) {
		return false;
	}
	return in_array( $remote, array_filter( array( $server, '127.0.0.1', '::1' ) ), true );
}

/**
 * template_redirect at PHP_INT_MAX: after every redirect plugin, canonical
 * and old-slug redirect has had its turn, so reaching here means the URL is
 * not a redirect. Signed via=origin from the server's own address: blank 204,
 * never cached, no render. Any other signed probe: full render with the
 * resolver off and the page cache told not to store it.
 */
function octk_probe_short_circuit(): void {
	$decision = octk_probe_short_circuit_decision();
	if ( null === $decision ) {
		return;
	}
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- page-cache convention (WP Rocket, W3TC, LiteSpeed).
	}
	nocache_headers();
	header( 'Cache-Control: no-store, private, max-age=0' );
	header( 'X-Octk-Probe: ' . $decision );

	if ( 'blank' === $decision ) {
		status_header( 204 );
		exit;
	}
}

/**
 * 'blank' (signed via=origin from the server itself), 'render' (any other
 * verified signature: full page, resolver off, never cached) or null (no
 * valid signature: an ordinary visit).
 */
function octk_probe_short_circuit_decision(): ?string {
	$via = octk_verify_probe_header();
	if ( null === $via ) {
		return null;
	}
	OCTK_Resolver::$request_disabled = true;
	return ( 'origin' === $via && octk_request_is_from_self() ) ? 'blank' : 'render';
}

/* ---------------------------------------------------------------------- */
/* Origin route                                                            */
/* ---------------------------------------------------------------------- */

/**
 * Register the self-test route: HEAD /wp-json/octk/v1/probe-selftest with a
 * valid signature answers 204 plus a response signature that only this
 * install can produce. Anything else is 404.
 */
function octk_register_selftest_route(): void {
	register_rest_route(
		'octk/v1',
		'/probe-selftest',
		array(
			'methods'             => WP_REST_Server::READABLE,
			// Intentionally public: the request must carry a valid HMAC to get
			// anything but a 404, and the only output is a signature header.
			'permission_callback' => '__return_true',
			'callback'            => static function () {
				$via = octk_verify_probe_header();
				if ( null === $via ) {
					return new WP_Error( 'octk_not_found', __( 'Not found.', 'hopcount-click-tracking' ), array( 'status' => 404 ) );
				}
				$raw      = (string) $_SERVER['HTTP_X_OCTK_RESOLVE']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- already verified above.
				$response = new WP_REST_Response( null, 204 );
				// The scheme WordPress saw is part of the answer: a backend that
				// does not map X-Forwarded-Proto would build http URLs and redirect
				// every page probe, so such a route must fail the self-test.
				$response->header( 'X-Octk-Selftest', octk_selftest_signature( $raw, is_ssl() ? 'https' : 'http' ) );
				$response->header( 'Cache-Control', 'no-store, private, max-age=0' );
				return $response;
			},
		)
	);
}

function octk_selftest_signature( string $request_header, string $scheme_seen ): string {
	$secret = octk_resolve_secret();
	return '' === $secret ? '' : hash_hmac( 'sha256', 'selftest|' . $request_header . '|' . $scheme_seen, hex2bin( $secret ) );
}

/**
 * Stored origin route {scheme, host, port, sslverify} or null.
 */
function octk_probe_origin(): ?array {
	$route = get_option( 'octk_probe_origin', null );
	if ( is_array( $route ) && isset( $route['scheme'], $route['host'], $route['port'] ) ) {
		return $route;
	}
	return null;
}

/**
 * Candidates in order: explicit filter, https to SERVER_ADDR:SERVER_PORT,
 * https 443, http 80, http 127.0.0.1:80, and finally https 127.0.0.1:443
 * without certificate verification (loopback only: origin certificates such
 * as Cloudflare's are not publicly trusted, and loopback traffic never
 * leaves the machine).
 *
 * @return array<int, array{scheme: string, host: string, port: int, sslverify: bool}>
 */
function octk_probe_origin_candidates(): array {
	$verify     = (bool) apply_filters( 'octk_resolve_probe_sslverify', true );
	$candidates = array();
	$explicit   = apply_filters( 'octk_resolve_probe_origin', null );
	if ( is_array( $explicit ) && isset( $explicit['scheme'], $explicit['host'] ) ) {
		$candidates[] = array(
			'scheme'    => 'http' === $explicit['scheme'] ? 'http' : 'https',
			'host'      => (string) $explicit['host'],
			'port'      => (int) ( $explicit['port'] ?? ( 'http' === $explicit['scheme'] ? 80 : 443 ) ),
			'sslverify' => isset( $explicit['sslverify'] ) ? (bool) $explicit['sslverify'] : $verify,
		);
	}
	$addr = isset( $_SERVER['SERVER_ADDR'] ) ? (string) $_SERVER['SERVER_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- hint only, verified by self-test.
	$port = isset( $_SERVER['SERVER_PORT'] ) ? (int) $_SERVER['SERVER_PORT'] : 0;      // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- hint only, verified by self-test.
	if ( '' !== $addr && filter_var( $addr, FILTER_VALIDATE_IP ) ) {
		if ( $port > 0 ) {
			$candidates[] = array( 'scheme' => 'https', 'host' => $addr, 'port' => $port, 'sslverify' => $verify );
		}
		$candidates[] = array( 'scheme' => 'https', 'host' => $addr, 'port' => 443, 'sslverify' => $verify );
		$candidates[] = array( 'scheme' => 'http', 'host' => $addr, 'port' => 80, 'sslverify' => $verify );
	}
	$candidates[] = array( 'scheme' => 'http', 'host' => '127.0.0.1', 'port' => 80, 'sslverify' => $verify );
	$candidates[] = array( 'scheme' => 'https', 'host' => '127.0.0.1', 'port' => 443, 'sslverify' => false );

	$unique = array();
	foreach ( $candidates as $c ) {
		$unique[ $c['scheme'] . '|' . $c['host'] . '|' . $c['port'] . '|' . (int) $c['sslverify'] ] = $c;
	}
	return array_values( $unique );
}

/**
 * Make sure an origin route is known, trying candidates at most once an
 * hour while none passes. Never called from render.
 */
function octk_probe_origin_ensure( bool $force = false ): ?array {
	$route = octk_probe_origin();
	if ( null !== $route && ! $force ) {
		return $route;
	}
	if ( ! $force && (int) get_option( 'octk_probe_origin_next_try', 0 ) > time() ) {
		return null;
	}
	update_option( 'octk_probe_origin_next_try', time() + HOUR_IN_SECONDS, false );

	foreach ( octk_probe_origin_candidates() as $candidate ) {
		if ( octk_probe_origin_selftest( $candidate ) ) {
			update_option( 'octk_probe_origin', $candidate, false );
			delete_option( 'octk_probe_origin_next_try' );
			return $candidate;
		}
	}
	delete_option( 'octk_probe_origin' );
	return null;
}

/**
 * A candidate passes only when a signed HEAD to this install's own selftest
 * endpoint, sent through that candidate, comes back 204 with the expected
 * response signature, which proves the connection reached this WordPress.
 */
function octk_probe_origin_selftest( array $candidate ): bool {
	$logical = rest_url( 'octk/v1/probe-selftest' );
	$header  = octk_probe_header_value( $logical, 'origin' );
	if ( '' === $header ) {
		return false;
	}
	$response = octk_probe_request( $logical, 'origin', $candidate, OCTK_RESOLVE_PROBE_TIMEOUT, $header );
	if ( is_wp_error( $response ) || 204 !== (int) wp_remote_retrieve_response_code( $response ) || ! octk_probe_request_mapped() ) {
		return false;
	}
	$sig    = (string) wp_remote_retrieve_header( $response, 'x-octk-selftest' );
	$scheme = strtolower( (string) wp_parse_url( $logical, PHP_URL_SCHEME ) );
	return '' !== $sig && hash_equals( octk_selftest_signature( $header, $scheme ), $sig );
}

/**
 * One HEAD request. Three URLs are kept apart: the logical URL (identity,
 * signature), the transport URL (the candidate's scheme with the logical
 * host, so curl speaks the backend's protocol) and the connection, which
 * CURLOPT_CONNECT_TO points at the origin address while Host and SNI stay
 * the logical host. Without a route the public URL is used as-is.
 *
 * @return array|WP_Error wp_remote_head() result.
 */
function octk_probe_request( string $logical_url, string $via, ?array $origin, float $timeout, string $header = '' ) {
	$identity = octk_probe_identity( $logical_url );
	if ( null === $identity ) {
		return new WP_Error( 'octk_bad_url', 'unparseable URL' );
	}
	if ( '' === $header ) {
		$header = octk_probe_header_value( $logical_url, $via );
	}
	if ( '' === $header ) {
		return new WP_Error( 'octk_unsigned', 'no secret' );
	}

	$parts         = wp_parse_url( $logical_url );
	$logical_host  = $identity[0];
	$logical_port  = isset( $parts['port'] ) ? (int) $parts['port'] : null;
	$logical_https = 'https' === strtolower( $parts['scheme'] ?? 'http' );

	$headers = array(
		OCTK_RESOLVE_HEADER => $header,
	);
	$args    = array(
		'timeout'     => max( 0.5, min( OCTK_RESOLVE_PROBE_TIMEOUT, $timeout ) ),
		'redirection' => 0,
		'user-agent'  => OCTK_RESOLVE_UA,
		'sslverify'   => true,
		'blocking'    => true,
	);

	$transport_url = $logical_url;
	$hook          = null;
	$hook_ran      = false;

	if ( null !== $origin ) {
		if ( ! defined( 'CURLOPT_CONNECT_TO' ) || 'WP_Http_Curl' !== _wp_http_get_object()->_get_first_available_transport( array(), $logical_url ) ) {
			return new WP_Error( 'octk_no_curl', 'origin route needs curl 7.49+' );
		}
		$scheme         = 'http' === $origin['scheme'] ? 'http' : 'https';
		$transport_port = $logical_port ?? ( 'http' === $scheme ? 80 : 443 );
		$transport_url  = $scheme . '://' . $logical_host . ( null !== $logical_port ? ':' . $logical_port : '' ) . $identity[1];
		if ( 'http' === $scheme && $logical_https ) {
			$headers['X-Forwarded-Proto'] = 'https';
		}
		$args['sslverify'] = (bool) $origin['sslverify'];
		$connect_to        = $logical_host . ':' . $transport_port . ':' . $origin['host'] . ':' . (int) $origin['port'];
		$hook              = static function ( $handle, $parsed_args, $url ) use ( $connect_to, $transport_url, &$hook_ran ): void {
			if ( $url !== $transport_url ) {
				return;
			}
			$hook_ran = true;
			curl_setopt( $handle, CURLOPT_CONNECT_TO, array( $connect_to ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- connection mapping has no WP_Http equivalent.
		};
		add_action( 'http_api_curl', $hook, 10, 3 );
	}

	$args['headers'] = $headers;
	$response        = wp_remote_head( $transport_url, $args );

	if ( null !== $hook ) {
		remove_action( 'http_api_curl', $hook, 10 );
		octk_probe_request_mapped( $hook_ran );
	}
	return $response;
}

/**
 * Whether the last origin-routed request really had its connection
 * remapped (the curl hook ran). A self-test answer that arrived without the
 * mapping came through the public URL and proves nothing. Filter
 * octk_resolve_selftest_trust is a test seam for stubbed transports.
 */
function octk_probe_request_mapped( ?bool $set = null ): bool {
	static $mapped = false;
	if ( null !== $set ) {
		$mapped = $set;
	}
	return $mapped || (bool) apply_filters( 'octk_resolve_selftest_trust', false );
}

/* ---------------------------------------------------------------------- */
/* Ceilings                                                                */
/* ---------------------------------------------------------------------- */

function octk_resolve_breaker_error(): string {
	$value = get_transient( OCTK_RESOLVE_DOWN_KEY );
	return is_string( $value ) ? $value : '';
}

function octk_resolve_breaker_tripped(): bool {
	return false !== get_transient( OCTK_RESOLVE_DOWN_KEY );
}

function octk_resolve_trip_breaker( string $reason ): void {
	set_transient( OCTK_RESOLVE_DOWN_KEY, gmdate( 'Y-m-d H:i:s' ) . ' UTC: ' . substr( $reason, 0, 200 ), HOUR_IN_SECONDS );
}

function octk_resolve_daily_cap(): int {
	return max( 0, (int) apply_filters( 'octk_resolve_daily_cap', OCTK_RESOLVE_DAILY_CAP ) );
}

function octk_resolve_daily_key(): string {
	return 'octk_resolve_daily_' . gmdate( 'Ymd' );
}

/**
 * Daily counter lives in an option (autoload off), not only in the object
 * cache: a cache flush must not reset the cap. Reads are served from the
 * object cache by get_option() anyway; the write cost is one UPDATE per
 * probe, which the cap itself bounds.
 */
function octk_resolve_probes_today(): int {
	return (int) get_option( octk_resolve_daily_key(), 0 );
}

function octk_resolve_cap_reached(): bool {
	return octk_resolve_probes_today() >= octk_resolve_daily_cap();
}

/**
 * Reserve one HTTP request against the daily cap (every hop counts).
 * One conditional UPDATE on the option row: the increment and the limit
 * check are a single statement, so overlapping workers cannot overwrite
 * each other's counts or slip past the cap. Returns false when the cap is
 * reached; nothing is counted then.
 */
function octk_resolve_reserve_request(): bool {
	global $wpdb;
	$key = octk_resolve_daily_key();
	$cap = octk_resolve_daily_cap();
	if ( $cap <= 0 ) {
		return false;
	}
	if ( false === get_option( $key, false ) ) {
		add_option( $key, 0, '', false ); // idempotent; a concurrent add is absorbed.
		delete_option( 'octk_resolve_daily_' . gmdate( 'Ymd', time() - DAY_IN_SECONDS ) );
	}
	$affected = $wpdb->query(
		$wpdb->prepare(
			'UPDATE %i SET option_value = CAST(option_value AS UNSIGNED) + 1 WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d',
			$wpdb->options,
			$key,
			$cap
		)
	);
	wp_cache_delete( $key, 'options' ); // get_option() must see the new value on this and other workers.
	return 1 === (int) $affected;
}

/* ---------------------------------------------------------------------- */
/* Rows, claims, verdicts                                                  */
/* ---------------------------------------------------------------------- */

/**
 * Per-request memo and object-cache front for redirect rows plus the
 * request-scoped resolver switches.
 */
final class OCTK_Resolver {

	/** @var array<string, array|null> hash_hex => row (null = not in table). */
	private static $rows = array();

	/** @var array<string, bool> hash_hex => skipped this request (budget). */
	public static $skipped = array();

	/** @var bool set by a verified probe header: no discovery, no probes. */
	public static $request_disabled = false;

	public static function reset_memo(): void {
		self::$rows             = array();
		self::$skipped          = array();
		self::$request_disabled = false;
	}

	/** @return array|null row as assoc array with hex url_hash, or null. */
	public static function row( string $hash_hex, bool $fresh = false ): ?array {
		if ( ! $fresh && array_key_exists( $hash_hex, self::$rows ) ) {
			return self::$rows[ $hash_hex ];
		}
		if ( ! $fresh ) {
			$cached = wp_cache_get( 'row:' . $hash_hex, OCTK_RESOLVE_CACHE_GROUP );
			if ( is_array( $cached ) ) {
				self::$rows[ $hash_hex ] = $cached;
				return $cached;
			}
		}
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT HEX(url_hash) AS url_hash, url, verdict, destination_url, destination_host, checked_at_utc, next_check_utc, attempts, last_error, claimed_until_utc, HEX(claim_token) AS claim_token, claim_generation, generation FROM %i WHERE url_hash = UNHEX(%s)',
				octk_table_redirects(),
				$hash_hex
			),
			ARRAY_A
		);
		$row = is_array( $row ) ? $row : null;
		if ( null !== $row ) {
			$row['url_hash'] = strtolower( $row['url_hash'] );
			wp_cache_set( 'row:' . $hash_hex, $row, OCTK_RESOLVE_CACHE_GROUP, DAY_IN_SECONDS );
		}
		self::$rows[ $hash_hex ] = $row;
		return $row;
	}

	public static function forget( string $hash_hex ): void {
		unset( self::$rows[ $hash_hex ] );
		wp_cache_delete( 'row:' . $hash_hex, OCTK_RESOLVE_CACHE_GROUP );
	}

	public static function remember( string $hash_hex, ?array $row ): void {
		self::$rows[ $hash_hex ] = $row;
	}
}

/** Insert a pending row if absent. Returns the row (existing or new). */
function octk_resolve_ensure_row( string $logical_url ): ?array {
	global $wpdb;
	$hash = octk_resolve_hash( $logical_url );
	$row  = OCTK_Resolver::row( $hash );
	if ( null !== $row ) {
		return $row;
	}
	$result = $wpdb->query(
		$wpdb->prepare(
			'INSERT IGNORE INTO %i (url_hash, url, verdict, next_check_utc, generation) VALUES (UNHEX(%s), %s, %s, %s, %d)',
			octk_table_redirects(),
			$hash,
			$logical_url,
			'pending',
			gmdate( 'Y-m-d H:i:s' ),
			0
		)
	);
	if ( false === $result ) {
		octk_log( 'redirect row insert failed: ' . $wpdb->last_error );
		return null;
	}
	return OCTK_Resolver::row( $hash, true );
}

/** Cache key of the per-source reconciliation fingerprint. */
function octk_resolve_fingerprint_key( string $source_type, int $source_id ): string {
	return 'srcset:' . $source_type . ':' . $source_id;
}

/**
 * Record that a source references a URL. Atomic and idempotent. Any write
 * that may have changed the source's stored pair set also drops that
 * source's reconciliation fingerprint, so the next render re-reads the
 * table instead of trusting a fingerprint taken before this write.
 */
function octk_resolve_add_source( string $hash_hex, string $source_type, int $source_id ): void {
	global $wpdb;
	$key = 'src:' . $hash_hex . ':' . $source_type . ':' . $source_id;
	if ( wp_cache_get( $key, OCTK_RESOLVE_CACHE_GROUP ) ) {
		return;
	}
	$wpdb->query(
		$wpdb->prepare(
			'INSERT IGNORE INTO %i (url_hash, source_type, source_id) VALUES (UNHEX(%s), %s, %d)',
			octk_table_redirect_sources(),
			$hash_hex,
			$source_type,
			$source_id
		)
	);
	wp_cache_set( $key, 1, OCTK_RESOLVE_CACHE_GROUP, DAY_IN_SECONDS );
	wp_cache_delete( octk_resolve_fingerprint_key( $source_type, $source_id ), OCTK_RESOLVE_CACHE_GROUP );
}

/**
 * Rendered content is authoritative for what a post references (it includes
 * shortcode output, which the raw scan on save cannot see). Drop every
 * (url, post) pair the rendered HTML no longer contains, so a removed link
 * stops being refreshed and purged on this post's account. The pairs to
 * remove are read from the table itself, never inferred from a cached
 * list. A per-post fingerprint in the object cache makes the steady state
 * query-free; every source insert drops it (see octk_resolve_add_source()),
 * so a save-time change can never hide behind a fingerprint of an earlier
 * render.
 *
 * @param string[] $urls logical URLs found in the rendered content.
 */
function octk_resolve_reconcile_sources( array $urls, int $post_id ): void {
	global $wpdb;
	$hashes = array_map( 'octk_resolve_hash', $urls );
	sort( $hashes );
	$fingerprint = md5( implode( ',', $hashes ) );
	$cache_key   = octk_resolve_fingerprint_key( 'post', $post_id );
	if ( wp_cache_get( $cache_key, OCTK_RESOLVE_CACHE_GROUP ) === $fingerprint ) {
		return;
	}

	// What the table really holds for this post decides what is removed
	// and which pair markers are invalidated: references added by the
	// save-time scan never went through a render, so no cached list of a
	// previous render can be trusted to know about them.
	$stored = $wpdb->get_col(
		$wpdb->prepare( 'SELECT HEX(url_hash) FROM %i WHERE source_type = %s AND source_id = %d', octk_table_redirect_sources(), 'post', $post_id )
	);
	$stored = is_array( $stored ) ? array_map( 'strtolower', $stored ) : array();
	$gone   = array_values( array_diff( $stored, $hashes ) );

	if ( ! empty( $gone ) ) {
		$in     = implode( ', ', array_fill( 0, count( $gone ), 'UNHEX(%s)' ) );
		$sql    = 'DELETE FROM %i WHERE source_type = %s AND source_id = %d AND url_hash IN (' . $in . ')';
		$values = array_merge( array( octk_table_redirect_sources(), 'post', $post_id ), $gone );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $in is a "UNHEX(%s), ..." list built above; every value, and the table name, goes through prepare().
		if ( false === $wpdb->query( $wpdb->prepare( $sql, $values ) ) ) {
			octk_log( 'source reconcile failed: ' . $wpdb->last_error );
			return;
		}
		foreach ( $gone as $hash ) {
			wp_cache_delete( 'src:' . $hash . ':post:' . $post_id, OCTK_RESOLVE_CACHE_GROUP );
		}
	}
	wp_cache_set( $cache_key, $fingerprint, OCTK_RESOLVE_CACHE_GROUP, DAY_IN_SECONDS );
}

/** @return int[] post ids referencing the URL. */
function octk_resolve_source_ids( string $hash_hex ): array {
	global $wpdb;
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			'SELECT source_id FROM %i WHERE url_hash = UNHEX(%s) AND source_type = %s',
			octk_table_redirect_sources(),
			$hash_hex,
			'post'
		)
	);
	return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
}

/**
 * Discovery: upsert rows and (url, post) pairs for every same-site URL in a
 * post's raw content or rendered output.
 *
 * @param string[] $urls logical URLs.
 */
function octk_resolve_discover( array $urls, int $post_id ): void {
	foreach ( $urls as $url ) {
		$row = octk_resolve_ensure_row( $url );
		if ( null === $row ) {
			continue;
		}
		octk_resolve_add_source( $row['url_hash'], 'post', $post_id );
	}
}

function octk_resolve_row_is_due( array $row, ?int $now = null ): bool {
	$now = $now ?? time();
	return strtotime( $row['next_check_utc'] . ' UTC' ) <= $now || (int) $row['generation'] < octk_resolve_generation();
}

function octk_resolve_row_confirmed( array $row ): bool {
	return in_array( $row['verdict'], array( 'outbound', 'internal' ), true );
}

/**
 * Atomic claim shared by render and cron. Exactly one caller wins a due,
 * unclaimed row; the token must be presented to octk_store_verdict().
 *
 * @return string|null claim token hex.
 */
function octk_resolve_claim( string $hash_hex ): ?string {
	global $wpdb;
	$now   = gmdate( 'Y-m-d H:i:s' );
	$until = gmdate( 'Y-m-d H:i:s', time() + OCTK_RESOLVE_CLAIM_SECONDS );
	$token = bin2hex( random_bytes( 16 ) );
	$gen   = octk_resolve_generation();

	$affected = $wpdb->query(
		$wpdb->prepare(
			'UPDATE %i SET claimed_until_utc = %s, claim_token = UNHEX(%s), claim_generation = %d WHERE url_hash = UNHEX(%s) AND (next_check_utc <= %s OR generation < %d) AND (claimed_until_utc IS NULL OR claimed_until_utc < %s)',
			octk_table_redirects(),
			$until,
			$token,
			$gen,
			$hash_hex,
			$now,
			$gen,
			$now
		)
	);
	if ( 1 !== (int) $affected ) {
		return null;
	}
	return $token;
}

/** TTL in seconds for a verdict, filterable. */
function octk_resolve_ttl( string $verdict, int $attempts = 0 ): int {
	switch ( $verdict ) {
		case 'outbound':
		case 'internal':
			$seconds = 7 * DAY_IN_SECONDS;
			break;
		case 'unknown':
			$seconds = min( DAY_IN_SECONDS, HOUR_IN_SECONDS * ( 2 ** max( 0, min( 5, $attempts - 1 ) ) ) );
			break;
		default:
			$seconds = 0;
	}
	return max( 0, (int) apply_filters( 'octk_resolve_ttl', $seconds, $verdict, $attempts ) );
}

/**
 * Single write path. Accepts only results carrying the row's current claim
 * token and the current global generation. Unknown never overwrites a
 * confirmed verdict. Change detection on (has token, destination) purges
 * every referencing post and fires octk_resolution_changed.
 *
 * @param array $result array{verdict: string, destination_url?: string, destination_host?: string, error?: string}
 */
function octk_store_verdict( string $logical_url, array $result, string $claim_token ): bool {
	global $wpdb;

	$hash = octk_resolve_hash( $logical_url );
	$old  = OCTK_Resolver::row( $hash, true );
	if ( null === $old ) {
		return false;
	}
	$gen = octk_resolve_generation();
	if ( strtolower( (string) $old['claim_token'] ) !== $claim_token || (int) $old['claim_generation'] !== $gen ) {
		return false; // stale claim or pre-Clear result: discarded, row stays as is.
	}

	$verdict = in_array( $result['verdict'] ?? '', array( 'outbound', 'internal', 'unknown' ), true ) ? $result['verdict'] : 'unknown';
	$now     = gmdate( 'Y-m-d H:i:s' );

	if ( 'unknown' === $verdict ) {
		$attempts = min( 255, (int) $old['attempts'] + 1 );
		$next     = gmdate( 'Y-m-d H:i:s', time() + octk_resolve_ttl( 'unknown', $attempts ) );
		$keep     = octk_resolve_row_confirmed( $old ) ? $old['verdict'] : 'unknown';
		$ok       = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET verdict = %s, attempts = %d, last_error = %s, next_check_utc = %s, generation = %d, claimed_until_utc = NULL, claim_token = NULL, claim_generation = NULL WHERE url_hash = UNHEX(%s) AND claim_token = UNHEX(%s)',
				octk_table_redirects(),
				$keep,
				$attempts,
				substr( (string) ( $result['error'] ?? '' ), 0, 255 ),
				$next,
				$gen,
				$hash,
				$claim_token
			)
		);
		OCTK_Resolver::forget( $hash );
		return 1 === (int) $ok;
	}

	$dest_url  = 'outbound' === $verdict ? (string) ( $result['destination_url'] ?? '' ) : null;
	$dest_host = 'outbound' === $verdict ? (string) ( $result['destination_host'] ?? '' ) : null;
	$next      = gmdate( 'Y-m-d H:i:s', time() + octk_resolve_ttl( $verdict ) );
	$ok        = $wpdb->query(
		$wpdb->prepare(
			'UPDATE %i SET verdict = %s, destination_url = %s, destination_host = %s, checked_at_utc = %s, next_check_utc = %s, attempts = 0, last_error = NULL, generation = %d, claimed_until_utc = NULL, claim_token = NULL, claim_generation = NULL WHERE url_hash = UNHEX(%s) AND claim_token = UNHEX(%s)',
			octk_table_redirects(),
			$verdict,
			$dest_url,
			$dest_host,
			$now,
			$next,
			$gen,
			$hash,
			$claim_token
		)
	);
	OCTK_Resolver::forget( $hash );
	if ( 1 !== (int) $ok ) {
		return false;
	}

	$post_ids = octk_resolve_source_ids( $hash );
	if ( empty( $post_ids ) ) {
		// Nothing references this URL any more: drop it after its check.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE url_hash = UNHEX(%s)', octk_table_redirects(), $hash ) );
		OCTK_Resolver::forget( $hash );
		return true;
	}

	$before = 'outbound' === $old['verdict'] ? (string) $old['destination_url'] : '';
	$after  = 'outbound' === $verdict ? (string) $dest_url : '';
	if ( $before !== $after ) {
		octk_resolve_purge( $post_ids );
		do_action(
			'octk_resolution_changed',
			$logical_url,
			array( 'verdict' => $old['verdict'], 'destination_url' => $old['destination_url'] ),
			array( 'verdict' => $verdict, 'destination_url' => $dest_url ),
			$post_ids
		);
	}
	return true;
}

/** @param int[] $post_ids */
function octk_resolve_purge( array $post_ids ): void {
	foreach ( $post_ids as $post_id ) {
		clean_post_cache( $post_id );
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $post_id );
		}
	}
}

/* ---------------------------------------------------------------------- */
/* Probing                                                                 */
/* ---------------------------------------------------------------------- */

/**
 * Resolve one logical URL: pre-resolution filter, then up to three signed
 * HEAD hops. Returns array{verdict, destination_url?, destination_host?,
 * error?, requests}. Never writes; callers pass the result to
 * octk_store_verdict().
 */
function octk_probe( string $logical_url, float $budget_seconds = OCTK_RESOLVE_PROBE_TIMEOUT * OCTK_RESOLVE_MAX_HOPS, ?int $max_requests = null ): array {
	$pre = apply_filters( 'octk_pre_resolve_same_site', null, $logical_url );
	if ( is_array( $pre ) && isset( $pre['verdict'] ) ) {
		if ( 'outbound' === $pre['verdict'] && isset( $pre['destination_url'] ) ) {
			$dest = octk_normalize_external( (string) $pre['destination_url'] );
			if ( null !== $dest ) {
				return array( 'verdict' => 'outbound', 'destination_url' => $dest['url'], 'destination_host' => $dest['host'], 'requests' => 0 );
			}
		}
		if ( 'internal' === $pre['verdict'] ) {
			return array( 'verdict' => 'internal', 'requests' => 0 );
		}
	}

	$origin   = octk_probe_origin();
	$via      = null === $origin ? 'public' : 'origin';
	$current  = $logical_url;
	$requests = 0;
	$started  = microtime( true );
	$hops     = min( OCTK_RESOLVE_MAX_HOPS, $max_requests ?? OCTK_RESOLVE_MAX_HOPS );

	for ( $hop = 0; $hop < $hops; $hop++ ) {
		$remaining = $budget_seconds - ( microtime( true ) - $started );
		if ( $remaining < 0.5 ) {
			return array( 'verdict' => 'unknown', 'error' => 'budget exhausted', 'requests' => $requests );
		}
		if ( ! octk_resolve_reserve_request() ) {
			return array( 'verdict' => 'unknown', 'error' => 'daily cap reached', 'requests' => $requests );
		}
		$requests++;
		$response = octk_probe_request( $current, $via, $origin, $remaining );

		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			octk_resolve_trip_breaker( $message );
			return array( 'verdict' => 'unknown', 'error' => 'transport: ' . $message, 'requests' => $requests );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
			$location = (string) wp_remote_retrieve_header( $response, 'location' );
			if ( is_array( $location ) ) {
				$location = (string) reset( $location );
			}
			if ( '' === $location ) {
				return array( 'verdict' => 'internal', 'requests' => $requests );
			}
			$location = WP_Http::make_absolute_url( $location, $current );
			$host     = strtolower( (string) wp_parse_url( $location, PHP_URL_HOST ) );
			if ( '' === $host ) {
				return array( 'verdict' => 'internal', 'requests' => $requests );
			}
			if ( in_array( $host, octk_site_hosts(), true ) ) {
				$next = octk_same_site_logical_url( $location );
				if ( null === $next ) {
					return array( 'verdict' => 'internal', 'requests' => $requests );
				}
				$current = $next;
				continue; // on-site hop
			}
			if ( in_array( $host, octk_internal_hosts(), true ) ) {
				return array( 'verdict' => 'internal', 'requests' => $requests );
			}
			$dest = octk_normalize_external( $location );
			if ( null === $dest ) {
				return array( 'verdict' => 'internal', 'requests' => $requests );
			}
			return array( 'verdict' => 'outbound', 'destination_url' => $dest['url'], 'destination_host' => $dest['host'], 'requests' => $requests );
		}

		if ( in_array( $code, array( 200, 204, 404, 410 ), true ) || ( $code >= 300 && $code < 400 ) ) {
			return array( 'verdict' => 'internal', 'requests' => $requests );
		}

		return array( 'verdict' => 'unknown', 'error' => 'HTTP ' . $code, 'requests' => $requests );
	}

	return array( 'verdict' => 'unknown', 'error' => 'hop limit reached', 'requests' => $requests );
}

/**
 * Claim, probe and store one row. Returns requests made (0 when the claim
 * was lost or nothing ran).
 */
function octk_resolve_one( array $row, float $budget_seconds, ?int $max_requests = null ): int {
	$token = octk_resolve_claim( $row['url_hash'] );
	if ( null === $token ) {
		return 0;
	}
	$result = octk_probe( $row['url'], $budget_seconds, $max_requests );
	octk_store_verdict( $row['url'], $result, $token );
	return (int) $result['requests'];
}

/* ---------------------------------------------------------------------- */
/* Lookup used by the normaliser                                           */
/* ---------------------------------------------------------------------- */

/**
 * Outbound destination for a same-site href when the table says so, else
 * null. Read-only: never probes, never inserts.
 *
 * @return array{url: string, host: string}|null
 */
function octk_resolve_lookup( string $raw ): ?array {
	if ( ! octk_resolve_enabled() ) {
		return null;
	}
	$logical = octk_same_site_logical_url( $raw );
	if ( null === $logical ) {
		return null;
	}
	$row = OCTK_Resolver::row( octk_resolve_hash( $logical ) );
	if ( null === $row || 'outbound' !== $row['verdict'] || '' === (string) $row['destination_url'] ) {
		return null;
	}
	return array( 'url' => (string) $row['destination_url'], 'host' => (string) $row['destination_host'] );
}

/* ---------------------------------------------------------------------- */
/* Render-time discovery and bounded fallback                              */
/* ---------------------------------------------------------------------- */

/**
 * Called from the render filter before pass 1. Discovers same-site URLs in
 * the rendered content, then probes never-confirmed due rows within a small
 * budget. Confirmed rows are used as-is even when due (refresh is cron's
 * job), so a token is never dropped because a render ran out of budget.
 */
function octk_resolve_prepare_render( string $content, int $post_id ): void {
	if ( ! octk_resolve_enabled() || OCTK_Resolver::$request_disabled ) {
		return;
	}
	$urls = octk_scan_same_site_urls( $content );
	octk_resolve_discover( $urls, $post_id );
	octk_resolve_reconcile_sources( $urls, $post_id );
	if ( empty( $urls ) ) {
		return;
	}

	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared only.
	if ( 'HEAD' === $method || octk_resolve_breaker_tripped() || octk_resolve_cap_reached() ) {
		return;
	}

	$started  = microtime( true );
	$requests = 0;
	$now      = time();
	foreach ( $urls as $url ) {
		$hash = octk_resolve_hash( $url );
		$row  = OCTK_Resolver::row( $hash );
		if ( null === $row || octk_resolve_row_confirmed( $row ) || ! octk_resolve_row_is_due( $row, $now ) ) {
			continue;
		}
		$remaining = OCTK_RESOLVE_RENDER_SECONDS - ( microtime( true ) - $started );
		if ( $requests >= OCTK_RESOLVE_RENDER_PROBES || $remaining < 0.5 ) {
			OCTK_Resolver::$skipped[ $hash ] = true;
			continue;
		}
		$requests += octk_resolve_one( $row, $remaining, OCTK_RESOLVE_RENDER_PROBES - $requests );
		if ( octk_resolve_breaker_tripped() ) {
			break;
		}
	}
}

/* ---------------------------------------------------------------------- */
/* save_post warm-up, delete_post cleanup, backfill                        */
/* ---------------------------------------------------------------------- */

function octk_resolve_post_types(): array {
	$types = get_post_types( array( 'public' => true ), 'names' );
	unset( $types['attachment'] );
	return array_values( $types );
}

function octk_resolve_on_save_post( int $post_id, $post ): void {
	if ( ! octk_resolve_enabled() || ! $post instanceof WP_Post ) {
		return;
	}
	if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || 'publish' !== $post->post_status ) {
		return;
	}
	if ( ! in_array( $post->post_type, octk_resolve_post_types(), true ) ) {
		return;
	}
	$urls = octk_scan_same_site_urls( (string) $post->post_content );
	if ( empty( $urls ) ) {
		return;
	}
	octk_resolve_discover( $urls, $post_id );
	octk_schedule_resolve( true );
}

function octk_resolve_on_delete_post( int $post_id ): void {
	global $wpdb;
	if ( ! octk_schema_v3_ready() ) {
		return;
	}
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE source_type = %s AND source_id = %d', octk_table_redirect_sources(), 'post', $post_id ) );
}

/**
 * One backfill batch: scan raw content of published posts in ID order.
 * Returns true when more remain.
 */
function octk_resolve_backfill_step(): bool {
	global $wpdb;
	$offset = get_option( 'octk_resolve_backfill', null );
	if ( null === $offset || false === $offset ) {
		return false;
	}
	$offset = (int) $offset;
	$types  = octk_resolve_post_types();
	if ( empty( $types ) ) {
		delete_option( 'octk_resolve_backfill' );
		return false;
	}
	$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
	$sql          = "SELECT ID, post_content FROM %i WHERE post_status = 'publish' AND post_type IN ({$placeholders}) AND ID > %d ORDER BY ID ASC LIMIT %d";
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $placeholders is a "%s, ..." list built above; every value, and the table name, goes through prepare().
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( $wpdb->posts ), $types, array( $offset, OCTK_RESOLVE_BACKFILL_BATCH ) ) ) );
	if ( ! is_array( $rows ) ) {
		return false;
	}
	$last = $offset;
	foreach ( $rows as $post ) {
		$last = (int) $post->ID;
		$urls = octk_scan_same_site_urls( (string) $post->post_content );
		if ( ! empty( $urls ) ) {
			octk_resolve_discover( $urls, (int) $post->ID );
		}
	}
	if ( count( $rows ) < OCTK_RESOLVE_BACKFILL_BATCH ) {
		delete_option( 'octk_resolve_backfill' );
		return false;
	}
	update_option( 'octk_resolve_backfill', $last, false );
	return true;
}

/* ---------------------------------------------------------------------- */
/* Cron run                                                                */
/* ---------------------------------------------------------------------- */

/**
 * @return int[] due rows as assoc arrays, oldest next_check first.
 */
function octk_resolve_due_rows( int $limit ): array {
	global $wpdb;
	$now  = gmdate( 'Y-m-d H:i:s' );
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT HEX(url_hash) AS url_hash, url, verdict, destination_url, destination_host, checked_at_utc, next_check_utc, attempts, last_error, claimed_until_utc, HEX(claim_token) AS claim_token, claim_generation, generation FROM %i WHERE (next_check_utc <= %s OR generation < %d) AND (claimed_until_utc IS NULL OR claimed_until_utc < %s) ORDER BY next_check_utc ASC LIMIT %d',
			octk_table_redirects(),
			$now,
			octk_resolve_generation(),
			$now,
			$limit
		),
		ARRAY_A
	);
	if ( ! is_array( $rows ) ) {
		return array();
	}
	foreach ( $rows as &$row ) {
		$row['url_hash'] = strtolower( $row['url_hash'] );
	}
	return $rows;
}

function octk_resolve_due_count(): int {
	global $wpdb;
	$now = gmdate( 'Y-m-d H:i:s' );
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE next_check_utc <= %s OR generation < %d', octk_table_redirects(), $now, octk_resolve_generation() ) );
}

/**
 * Cron worker: successor first, then backfill, then at most 20 probes or
 * 20 seconds, oldest due first. Breaker and cap exits keep the schedule.
 *
 * @return int requests made.
 */
function octk_resolve_run(): int {
	if ( ! octk_resolve_enabled() ) {
		return 0;
	}
	octk_schedule_resolve();
	update_option( 'octk_resolve_last_run', time(), false );

	$more = octk_resolve_backfill_step();
	$due  = octk_resolve_due_rows( OCTK_RESOLVE_CRON_PROBES + 1 );
	if ( $more || count( $due ) > 0 ) {
		octk_schedule_resolve_successor(); // before any work: a killed run leaves a worker.
	}
	if ( empty( $due ) ) {
		return 0;
	}

	if ( null === octk_probe_origin() ) {
		octk_probe_origin_ensure();
	}
	if ( octk_resolve_breaker_tripped() || octk_resolve_cap_reached() ) {
		return 0;
	}

	$started  = microtime( true );
	$requests = 0;
	foreach ( array_slice( $due, 0, OCTK_RESOLVE_CRON_PROBES ) as $row ) {
		$remaining = OCTK_RESOLVE_CRON_SECONDS - ( microtime( true ) - $started );
		if ( $requests >= OCTK_RESOLVE_CRON_PROBES || $remaining < 0.5 ) {
			break;
		}
		$requests += octk_resolve_one( $row, min( $remaining, OCTK_RESOLVE_PROBE_TIMEOUT * OCTK_RESOLVE_MAX_HOPS ), OCTK_RESOLVE_CRON_PROBES - $requests );
		if ( octk_resolve_breaker_tripped() || octk_resolve_cap_reached() ) {
			break;
		}
	}
	return $requests;
}

/* ---------------------------------------------------------------------- */
/* Status helper                                                           */
/* ---------------------------------------------------------------------- */

/**
 * @return array{state: string, detail: string, counts: array<string,int>, due: int, today: int, cap: int, last_run: int, origin: string}
 */
function octk_resolve_status(): array {
	global $wpdb;
	$counts = array( 'pending' => 0, 'outbound' => 0, 'internal' => 0, 'unknown' => 0 );
	$due    = 0;
	if ( octk_schema_v3_ready() ) {
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT verdict, COUNT(*) AS n FROM %i GROUP BY verdict', octk_table_redirects() ) );
		foreach ( (array) $rows as $row ) {
			if ( isset( $counts[ $row->verdict ] ) ) {
				$counts[ $row->verdict ] = (int) $row->n;
			}
		}
		$due = octk_resolve_due_count();
	}

	$origin = octk_probe_origin();
	if ( ! octk_resolve_enabled() ) {
		$state  = 'disabled';
		$detail = __( 'Disabled', 'hopcount-click-tracking' );
	} elseif ( octk_resolve_breaker_tripped() ) {
		$state  = 'breaker';
		/* translators: %s: last transport error with its time. */
		$detail = sprintf( __( 'Paused for up to an hour after a failed probe (%s)', 'hopcount-click-tracking' ), octk_resolve_breaker_error() );
	} elseif ( octk_resolve_cap_reached() ) {
		$state  = 'cap';
		$detail = __( 'Daily probe cap reached; resumes at the next UTC day', 'hopcount-click-tracking' );
	} else {
		$state  = 'working';
		$detail = __( 'Working', 'hopcount-click-tracking' );
	}

	return array(
		'state'    => $state,
		'detail'   => $detail,
		'counts'   => $counts,
		'due'      => $due,
		'today'    => octk_resolve_probes_today(),
		'cap'      => octk_resolve_daily_cap(),
		'last_run' => (int) get_option( 'octk_resolve_last_run', 0 ),
		'origin'   => null === $origin
			? __( 'not found, using public URL', 'hopcount-click-tracking' )
			: $origin['scheme'] . '://' . $origin['host'] . ':' . $origin['port'] . ( empty( $origin['sslverify'] ) && 'https' === $origin['scheme'] ? ' (' . __( 'certificate not verified', 'hopcount-click-tracking' ) . ')' : '' ),
	);
}

/* ---------------------------------------------------------------------- */
/* Hooks                                                                   */
/* ---------------------------------------------------------------------- */

function octk_register_resolve_hooks(): void {
	add_action( OCTK_RESOLVE_HOOK, 'octk_resolve_run' );
	add_action( 'template_redirect', 'octk_probe_short_circuit', PHP_INT_MAX );
	add_action( 'save_post', 'octk_resolve_on_save_post', 20, 2 );
	add_action( 'delete_post', 'octk_resolve_on_delete_post' );
	add_action( 'rest_api_init', 'octk_register_selftest_route' );
}
