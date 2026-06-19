<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Elementor_CSS_Override — surgical CSS/JS overrides for Elementor targets.
 *
 * Provides two surfaces used by Novamira abilities when the normal Page
 * Custom-CSS or WPCode route is not enough:
 *
 *   - inject_page_custom_css()  Append-only Page Custom-CSS with the
 *     `html body` prefix the treets.local session-debug showed Elementor
 *     4.x containers need to beat `.e-con` rules (specificity 0,1,2 vs
 *     the .e-con 0,1,0). Also compatible with plain CSS the caller has
 *     already specificity-bumped.
 *
 *   - generate_click_guard_script()  Inline JavaScript for marquee /
 *     carousel / slider widgets that lets taps on inner anchors still link
 *     through, but blocks navigation when the pointer has moved more than
 *     `$threshold` pixels along the x axis (default 12px).
 *
 * Both methods return WP_Error on failure (inject_page_custom_css) or a
 * raw JS source string (generate_click_guard_script). The click-guard
 * script's selector comes through wp_json_encode so any quote /
 * backslash / newline / closing-script-tag is escaped context-safely;
 * addslashes() alone would leave a `</script>` XSS vector.
 *
 * @package Novamira_AdrianV2
 * @since   1.0.0
 */

namespace Novamira\AdrianV2\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Outputs CSS / JS overrides for Elementor rendering targets.
 *
 * @since 1.0.0
 */
final class Elementor_CSS_Override {

	/**
	 * Appends page Custom-CSS to an Elementor page.
	 *
	 * The CSS is rewritten so each top-level class selector gets a
	 * `html body` prefix if it lacks one already (specificity bump
	 * from 0,1,0 to 0,1,2 to beat `.e-con` rules in source-order
	 * tiebreak). Existing `_elementor_page_custom_css` is preserved;
	 * the new CSS is appended under a separator comment.
	 *
	 * Runtime warning: if the page already has `_elementor_page_settings`
	 * populated, that field may be where Elementor 4.x reads custom CSS
	 * from on this install. We log a warning so the agent can manually
	 * merge if the renderer ignores the new legacy field.
	 *
	 * @param int    $post_id Elementor page ID.
	 * @param string $css     Raw CSS (single rule or full stylesheet).
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public static function inject_page_custom_css( int $post_id, string $css ) {
		if ( $post_id <= 0 ) {
			return new \WP_Error(
				'css_override_invalid_id',
				__( 'Page ID must be a positive integer.', 'novamira-adrianv2' )
			);
		}
		if ( '' === trim( $css ) ) {
			return new \WP_Error(
				'css_override_empty_css',
				__( 'CSS body cannot be empty.', 'novamira-adrianv2' )
			);
		}
		if ( ! function_exists( 'get_post_meta' ) || ! function_exists( 'update_post_meta' ) ) {
			return new \WP_Error(
				'css_override_runtime_unavailable',
				__( 'WordPress meta helpers are unavailable.', 'novamira-adrianv2' )
			);
		}
		if ( ! function_exists( 'get_post' ) || null === get_post( $post_id ) ) {
			return new \WP_Error(
				'css_override_post_not_found',
				sprintf(
					/* translators: %d: post id */
					__( 'No post with ID %d.', 'novamira-adrianv2' ),
					$post_id
				)
			);
		}

		$existing = get_post_meta( $post_id, '_elementor_page_custom_css', true );
		$existing = is_string( $existing ) ? $existing : '';

		$prefixed = self::ensure_html_body_prefix( $css );
		if ( '' === $prefixed ) {
			return new \WP_Error(
				'css_override_invalid_css',
				__( 'CSS did not survive the specificity-bump rewrite (likely only comments or empty rules).', 'novamira-adrianv2' )
			);
		}

		$marker = '/* ---- novamira-adrianv2 ---- */';
		$merged = ( '' === $existing ) ? $prefixed : ( $existing . "\n\n" . $marker . "\n" . $prefixed );

		$saved = update_post_meta( $post_id, '_elementor_page_custom_css', $merged );
		// update_post_meta() returns false on no-change OR on real failure;
		// re-reading confirms the actual stored value.
		if ( false === $saved && get_post_meta( $post_id, '_elementor_page_custom_css', true ) !== $merged ) {
			return new \WP_Error(
				'css_override_save_failed',
				sprintf(
					/* translators: %d: post id */
					__( 'Could not persist CSS for post %d.', 'novamira-adrianv2' ),
					$post_id
				)
			);
		}

		// Elementor 4.x install-time heuristic: warn if page already has
		// the alternative _elementor_page_settings container so the agent
		// can decide whether the new CSS needs to merge into that path too.
		$page_settings_meta = get_post_meta( $post_id, '_elementor_page_settings', true );
		if ( ! empty( $page_settings_meta ) ) {
			error_log(
				sprintf(
					'[novamira-adrianv2] inject_page_custom_css: post %1$d already has `_elementor_page_settings` populated. ' .
					'New CSS was written to the legacy `_elementor_page_custom_css` field. Some Elementor 4.x installs ' .
					'read custom CSS from `_elementor_page_settings._custom_css` instead; if the new rules do not render, ' .
					'manually merge into the nested `custom_css` key.',
					$post_id
				)
			);
		}

		if (
			class_exists( '\Elementor\Plugin' )
			&& isset( \Elementor\Plugin::$instance->files_manager )
			&& method_exists( \Elementor\Plugin::$instance->files_manager, 'clear_cache' )
		) {
			try {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			} catch ( \Throwable $e ) {
				$msg = 'Elementor files_manager->clear_cache() failed for post ' . $post_id . ': ' . $e->getMessage();
				error_log( '[novamira-adrianv2] ' . $msg );
				// Soft: Elementor will rebake on the next request.
			}
		}

		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $post_id );
		}

		return true;
	}

	/**
	 * Returns a click-guard JavaScript snippet for carousel / marquee widgets.
	 *
	 * Behaviour:
	 *  - On pointerdown we start tracking pointer x via `lastX`.
	 *  - On pointermove while dragging we accumulate `moved += |dx|`.
	 *  - A `click` listener in capture phase calls preventDefault +
	 *    stopPropagation iff `moved > threshold`. So taps that drift <
	 *    threshold pixels still allow inner anchors to navigate.
	 *  - pointerCapture is requested so a drag that leaves the slider
	 *    still feeds pointermove events.
	 *
	 * @param int    $threshold Pixel threshold for "drag" classification. Default 12.
	 * @param string $selector  CSS selector for the draggable root. Default ".productslider".
	 * @return string JavaScript source, ready to be inlined inside <script>…</script>.
	 */
	public static function generate_click_guard_script( int $threshold = 12, string $selector = '.productslider' ): string {
		$threshold_js = max( 0, $threshold );

		// Use wp_json_encode for the selector so any quote, backslash, newline,
		// closing-script-tag and unicode char gets context-safe escaping into
		// a JS string literal. addslashes() does NOT escape `</script>` and
		// leaves an XSS vector when the snippet ends up inside <script>...</script>
		// in Elementor's HTML widget.
		$selector_literal = function_exists( 'wp_json_encode' )
			? wp_json_encode( (string) $selector )
			: json_encode( (string) $selector, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );

		return "(function () {\n" .
			'  var __selector = ' . $selector_literal . ";\n" .
			"  var root = document.querySelector(__selector);\n" .
			"  if (!root) { return; }\n" .
			'  var threshold = ' . $threshold_js . ";\n" .
			"  var moved = 0, drag = false, lastX = 0;\n" .
			"  root.addEventListener('pointerdown', function (e) {\n" .
			"    drag = true; moved = 0; lastX = e.clientX;\n" .
			"    if (e.pointerId !== undefined && root.setPointerCapture) {\n" .
			"      try { root.setPointerCapture(e.pointerId); } catch (_) {}\n" .
			"    }\n" .
			"  });\n" .
			"  root.addEventListener('pointermove', function (e) {\n" .
			"    if (!drag) { return; }\n" .
			"    moved += Math.abs(e.clientX - lastX);\n" .
			"    lastX = e.clientX;\n" .
			"  });\n" .
			"  function endDrag(e) {\n" .
			"    drag = false;\n" .
			"    if (e && e.pointerId !== undefined && root.releasePointerCapture) {\n" .
			"      try { root.releasePointerCapture(e.pointerId); } catch (_) {}\n" .
			"    }\n" .
			"  }\n" .
			"  root.addEventListener('pointerup',     endDrag);\n" .
			"  root.addEventListener('pointercancel', endDrag);\n" .
			"  root.addEventListener('click', function (e) {\n" .
			"    if (moved > threshold) {\n" .
			"      e.preventDefault();\n" .
			"      e.stopPropagation();\n" .
			"    }\n" .
			"  }, true);\n" .
			"})();\n";
	}

	/**
	 * Rewrites a CSS snippet so each top-level class selector is prefixed
	 * with `html body` (to bump specificity from 0,1,0 to 0,1,2) if it
	 * does not already start with that prefix or a higher-specificity
	 * combination (an id `#`, a pseudo-class function `:is/:where`, or
	 * an explicit `body`/`html` token).
	 *
	 * The rewrite is intentionally narrow so it cannot break existing
	 * rules:
	 *   - selector must appear before the first `{` on a line
	 *   - @-rule blocks (@media, @keyframes, @supports) are passed through
	 *     untouched and their bodies are recognised by a brace counter
	 *   - already-prefixed selectors, id-prefixed selectors, or anything
	 *     containing `html` or `body` as a class token are skipped
	 *
	 * @param string $css CSS source.
	 * @return string Rewritten CSS (empty string if nothing parseable).
	 */
	private static function ensure_html_body_prefix( string $css ): string {
		$lines  = preg_split( '/(\r\n|\n|\r)/', $css ) ?: array();
		$buffer = '';
		// Track braces inside @-rule bodies so we don't try to rewrite
		// selectors that appear inside @media blocks etc.
		$media_depth      = 0;
		$write_everything = true;

		foreach ( $lines as $line ) {
			$trimmed = ltrim( $line );

			// An @-rule starts a new block; selectors inside it are not
			// "element-tree-class" selectors anymore.
			if ( preg_match( '/^@\\w+/', $trimmed ) ) {
				$write_everything = false;
			}

			if ( ! $write_everything ) {
				// Count braces forward so we know when the @-rule closes.
				$opens        = substr_count( $line, '{' );
				$closes       = substr_count( $line, '}' );
				$buffer      .= $line . "\n";
				$media_depth += ( $opens - $closes );
				if ( $media_depth <= 0 ) {
					$media_depth      = 0;
					$write_everything = true;
				}
				continue;
			}

			// Top-level selectors: a `<selector>{` line.
			if ( preg_match( '/^([^{}@\/]+?)\\s*\{/', $line, $m ) ) {
				$selector = trim( $m[1] );
				if (
					'' !== $selector
					&& false === strpos( $selector, 'html body' )
					&& 0 !== strpos( $selector, '#' )
					&& 0 !== strpos( $selector, ':' )
					&& ! preg_match( '/(^|\s)(html|body)(\s|$|,)/', $selector )
				) {
					$buffer .= 'html body ' . $selector . " {\n";
					$body    = substr( $line, strlen( $m[0] ) );
					$buffer .= $body . "\n";
					// Do NOT append this line twice.
					continue;
				}
			}

			$buffer .= $line . "\n";
		}

		return trim( $buffer );
	}
}
