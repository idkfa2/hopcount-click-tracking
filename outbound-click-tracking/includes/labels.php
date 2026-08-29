<?php
/**
 * Automatic link labels (link-labels plan): capture the anchor text a
 * visitor actually sees at render time, per link identity. First-wins:
 * a stored label is never overwritten by rendering; the post's auto labels
 * are cleared on save so the next uncached render re-captures. Manual
 * per-destination overrides (overrides.php) always win at display time.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const OCTK_LABEL_MAX_CHARS = 200;

/**
 * Sanitize raw anchor/aria/alt text into a storable label. Order matters:
 * entity-decode BEFORE strip-tags, otherwise encoded markup like &lt;b&gt;
 * would decode into literal tag-looking text after stripping already ran.
 * Returns '' when nothing usable remains.
 */
function octk_sanitize_anchor_text( string $raw ): string {
	$text = html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = wp_strip_all_tags( $text );
	$text = (string) preg_replace( '/\s+/u', ' ', $text );
	$text = trim( $text );
	return mb_substr( $text, 0, OCTK_LABEL_MAX_CHARS );
}

/**
 * Anchor texts too generic to identify a product. Compared lowercase,
 * entity-decoded, whitespace-collapsed, trimmed, exact match.
 *
 * @return string[]
 */
function octk_generic_anchor_texts(): array {
	$defaults = array(
		'click here',
		'here',
		'check price',
		'check current price',
		'buy now',
		'buy',
		'learn more',
		'read more',
		'this link',
		'link',
		'source',
		'website',
	);
	$list = apply_filters( 'octk_generic_anchor_texts', $defaults );
	return is_array( $list ) ? array_map( 'strval', $list ) : $defaults;
}

/**
 * Pick the best label from one render's candidates for one destination
 * (already sanitized, DOM order): longest non-generic candidate, ties broken
 * by first occurrence. All-generic: keep the first generic one (still beats
 * a bare URL). Nothing usable: null.
 *
 * @param string[] $candidates
 */
function octk_choose_label( array $candidates ): ?string {
	$generics = octk_generic_anchor_texts();

	$best         = null;
	$best_len     = -1;
	$first_seen   = null;
	foreach ( $candidates as $candidate ) {
		if ( '' === $candidate ) {
			continue;
		}
		if ( null === $first_seen ) {
			$first_seen = $candidate;
		}
		if ( in_array( mb_strtolower( $candidate ), $generics, true ) ) {
			continue;
		}
		$len = mb_strlen( $candidate );
		if ( $len > $best_len ) { // strict: equal length keeps the earlier one
			$best     = $candidate;
			$best_len = $len;
		}
	}

	return $best ?? $first_seen;
}

/**
 * Walk post HTML once, collecting eligible destinations AND their label
 * candidates in DOM order. Fallback per anchor when it has no text:
 * aria-label, then the first inner img alt.
 *
 * Requires WP_HTML_Tag_Processor::next_token() (WP 6.5+; plugin header
 * enforces the minimum). script/style contents never appear as #text
 * tokens, so they cannot leak into labels.
 *
 * @return array<string, array{url: string, host: string, label: string}> keyed by normalized url.
 */
function octk_collect_destinations( string $content ): array {
	$destinations = array(); // url => {url, host, label-candidates[]}
	$candidates   = array(); // url => string[]

	$processor = new WP_HTML_Tag_Processor( $content );
	$current   = null; // array{url, text, aria, img_alt} while inside an eligible <a>

	$finalize = static function () use ( &$current, &$candidates ): void {
		if ( null === $current ) {
			return;
		}
		$label = octk_sanitize_anchor_text( $current['text'] );
		if ( '' === $label && '' !== $current['aria'] ) {
			$label = octk_sanitize_anchor_text( $current['aria'] );
		}
		if ( '' === $label && '' !== $current['img_alt'] ) {
			$label = octk_sanitize_anchor_text( $current['img_alt'] );
		}
		if ( '' !== $label ) {
			$candidates[ $current['url'] ][] = $label;
		}
		$current = null;
	};

	while ( $processor->next_token() ) {
		$type = $processor->get_token_type();

		if ( '#text' === $type ) {
			if ( null !== $current ) {
				$current['text'] .= $processor->get_modifiable_text();
			}
			continue;
		}

		if ( '#tag' !== $type ) {
			continue;
		}

		$tag = $processor->get_tag();

		if ( 'A' === $tag ) {
			if ( $processor->is_tag_closer() ) {
				$finalize();
				continue;
			}
			$finalize(); // stray nested/unclosed anchor: close out the previous one

			$href = $processor->get_attribute( 'href' );
			if ( ! is_string( $href ) ) {
				continue;
			}
			$normalized = octk_normalize_destination( $href );
			if ( null === $normalized ) {
				continue;
			}
			$destinations[ $normalized['url'] ] = $normalized;

			$aria    = $processor->get_attribute( 'aria-label' );
			$current = array(
				'url'     => $normalized['url'],
				'text'    => '',
				'aria'    => is_string( $aria ) ? $aria : '',
				'img_alt' => '',
			);
			continue;
		}

		if ( 'IMG' === $tag && null !== $current && '' === $current['img_alt'] && ! $processor->is_tag_closer() ) {
			$alt = $processor->get_attribute( 'alt' );
			if ( is_string( $alt ) ) {
				$current['img_alt'] = $alt;
			}
		}
	}
	$finalize(); // unclosed trailing anchor

	foreach ( $destinations as $url => $dest ) {
		$label                          = octk_choose_label( $candidates[ $url ] ?? array() );
		$destinations[ $url ]['label']  = $label ?? '';
	}

	return $destinations;
}

/**
 * Clear a published post's auto labels on save so the next uncached render
 * re-captures from current content. Known simplification (plan): every real
 * save clears them, even edits that touch no links - labels disappear from
 * reports until that post next renders uncached. Overrides are untouched.
 */
add_action( 'save_post', 'octk_invalidate_labels_on_save', 10, 2 );
function octk_invalidate_labels_on_save( $post_id, $post ): void {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! is_post_type_viewable( $post->post_type ) ) {
		return;
	}
	if ( ! octk_schema_v2_ready() ) {
		return;
	}

	global $wpdb;
	$links = octk_table_links();
	// Pages register as source_type 'post' too (render path uses get_the_ID()).
	$wpdb->query( $wpdb->prepare( "UPDATE {$links} SET label = NULL WHERE source_type = %s AND source_id = %d", 'post', (int) $post_id ) );
}
