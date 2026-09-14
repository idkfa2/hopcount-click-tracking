<?php
/**
 * Render path (scope §5): annotate eligible outbound links in the_content
 * with data-octk-token, and enqueue the click listener.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const OCTK_CONTENT_FILTER_PRIORITY = 50;

function octk_register_render_hooks(): void {
	// Priority 50: after blocks and do_shortcode (11), before typical theme
	// content injectors at 98/99/100 (decision 26). Filterable (decision 42).
	add_filter(
		'the_content',
		'octk_filter_content',
		(int) apply_filters( 'octk_content_filter_priority', OCTK_CONTENT_FILTER_PRIORITY )
	);
	add_action( 'wp_enqueue_scripts', 'octk_enqueue_listener' );
}

/**
 * Contexts where the filter must return HTML untouched (scope §3).
 */
function octk_render_context_allowed(): bool {
	if ( ! octk_enabled() ) {
		return false;
	}
	if ( is_admin() || is_feed() || is_embed() || is_preview() || is_customize_preview() ) {
		return false;
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return false;
	}
	if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
		return false;
	}
	return true;
}

function octk_filter_content( $content ) {
	if ( ! is_string( $content ) || '' === $content ) {
		return $content;
	}

	try {
		if ( ! octk_render_context_allowed() ) {
			return $content;
		}

		$source_id = get_the_ID();
		if ( ! is_int( $source_id ) || $source_id <= 0 ) {
			return $content;
		}

		// Same-site redirect resolution (resolve.php): discover cloak
		// candidates in the rendered HTML and probe never-confirmed ones
		// within a small budget, so pass 1 and pass 2 see the same answers.
		octk_resolve_prepare_render( $content, $source_id );

		// Pass 1: collect eligible destinations + their label candidates
		// (anchor text / aria-label / img alt) in one token walk.
		$destinations = octk_collect_destinations( $content );

		if ( empty( $destinations ) ) {
			return $content;
		}

		$tokens = OCTK_Registry::tokens_for( 'post', $source_id, array_values( $destinations ) );
		if ( empty( $tokens ) ) {
			return $content; // Fail-soft: registration unavailable, render unchanged.
		}

		// Pass 2: annotate.
		$processor = new WP_HTML_Tag_Processor( $content );
		while ( $processor->next_tag( 'a' ) ) {
			$href = $processor->get_attribute( 'href' );
			if ( ! is_string( $href ) ) {
				continue;
			}
			$normalized = octk_normalize_destination( $href );
			if ( null === $normalized || ! isset( $tokens[ $normalized['url'] ] ) ) {
				continue;
			}
			$processor->set_attribute( 'data-octk-token', $tokens[ $normalized['url'] ] );
		}

		return $processor->get_updated_html();
	} catch ( Throwable $e ) {
		// Never break rendering because tracking failed (success criterion 11).
		octk_log( 'render filter failure: ' . $e->getMessage() );
		return $content;
	}
}

/**
 * Enqueue-only role exclusion (scope §9, decision 9): excluded logged-in users
 * simply never receive the listener. There is no endpoint-side backstop.
 */
function octk_user_is_excluded(): bool {
	if ( ! is_user_logged_in() ) {
		return false;
	}
	$excluded = get_option( 'octk_excluded_roles', octk_default_excluded_roles() );
	if ( ! is_array( $excluded ) || empty( $excluded ) ) {
		return false;
	}
	$user = wp_get_current_user();
	return (bool) array_intersect( $excluded, (array) $user->roles );
}

function octk_enqueue_listener(): void {
	if ( ! octk_enabled() || ! is_singular() || octk_user_is_excluded() ) {
		return;
	}

	// defer is acceptable; delay-until-interaction is not (scope §10).
	wp_enqueue_script(
		'octk-listener',
		plugins_url( 'js/listener.js', OCTK_PLUGIN_FILE ),
		array(),
		OCTK_VERSION,
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
	wp_add_inline_script(
		'octk-listener',
		'window.octkConfig = ' . wp_json_encode( array( 'endpoint' => rest_url( 'octk/v1/click' ) ) ) . ';',
		'before'
	);
}
