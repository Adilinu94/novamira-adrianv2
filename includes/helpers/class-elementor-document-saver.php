<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Elementor_Document_Saver — single-entry Elementor document writes.
 *
 * Tightly-controlled surface for Elementor page mutations invoked from MCP
 * abilities. Every write goes through `Elementor\Plugin::instance()->documents`
 * and Elementor's own `update_json_meta` path (NOT raw `update_post_meta`),
 * so the front-end render cache, the editor preview cache, and the per-post
 * `_elementor_css` file are all invalidated by Elementor itself.
 *
 * Two public methods:
 *   - save_data()        Replace the entire element tree of a page.
 *   - assign_class()     Assign/append a CSS class on a single element.
 *
 * assign_class() syncs BOTH `settings.css_classes` (the v3.x + v4-compat
 * legacy string) AND `settings._css_classes` (the 3.x fallback). If the
 * element also carries the v4 atomic `settings.classes` wrapper (shape
 * `{$$type:'classes', value:[list]}`), the helper appends to that list
 * too, with a runtime warning so the agent knows a plain class name
 * inserted into v4 atomic needs a matching styles map entry to render.
 *
 * Return shapes (intentionally NOT a WP_Error union):
 *   - save_data()    → array{success: bool, warnings: string[]}
 *   - assign_class() → true|\WP_Error
 * Soft-fail paths (Post-CSS delete, files_manager clear_cache) are appended
 * to `warnings` and to error_log so they travel in the MCP response
 * payload instead of being silently swallowed.
 *
 * @package Novamira_AdrianV2
 * @since   1.0.0
 */

namespace Novamira\AdrianV2\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes to Elementor pages via the Document API.
 *
 * @since 1.0.0
 */
final class Elementor_Document_Saver {

	/**
	 * Replaces an Elementor page's element tree.
	 *
	 * Returns a structured array (NOT a WP_Error union): callers must
	 * inspect `success` rather than treating a falsy value as an error.
	 *
	 * Performs, in order:
	 *   1. Resolve `Elementor\Plugin::instance()->documents->get($post_id)`.
	 *   2. `update_json_meta('_elementor_data', $elements)` on the document.
	 *      Same path as the Elementor editor save; fires
	 *      `elementor/document/save` and triggers the per-post CSS rebuild.
	 *   3. Delete the per-post Elementor CSS file (best-effort).
	 *   4. files_manager->clear_cache() so the global cache also drops
	 *      anything that referenced the page (best-effort).
	 *   5. clean_post_cache() so 3rd-party object caches drop the old payload.
	 *
	 * @param int   $post_id  Elementor page ID.
	 * @param array $elements The complete element tree (top-level array of elements).
	 * @return array{success:bool,warnings:string[]} Structured result; soft-fail errors live in `warnings`.
	 */
	public static function save_data( int $post_id, array $elements ): array {
		$warnings = array();

		if ( $post_id <= 0 ) {
			return array(
				'success'  => false,
				'warnings' => array( 'elementor_invalid_id: Page ID must be a positive integer.' ),
			);
		}
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return array(
				'success'  => false,
				'warnings' => array( 'elementor_inactive: Elementor is not active on this site.' ),
			);
		}
		if ( ! function_exists( 'get_post' ) || null === get_post( $post_id ) ) {
			return array(
				'success'  => false,
				'warnings' => array( sprintf( 'elementor_post_not_found: No post with ID %d.', $post_id ) ),
			);
		}

		// Concurrent edit guard: if a user is currently in the Elementor
		// editor, our backend write would clobber their pending state.
		if ( function_exists( 'wp_check_post_lock' ) && wp_check_post_lock( $post_id ) ) {
			return array(
				'success'  => false,
				'warnings' => array( 'elementor_post_locked: Another user is currently editing this post in Elementor.' ),
			);
		}

		$doc = \Elementor\Plugin::$instance->documents->get( $post_id );
		if ( ! $doc ) {
			return array(
				'success'  => false,
				'warnings' => array( sprintf( 'elementor_no_doc: No Elementor document for post %d (page may not have been saved as Elementor yet).', $post_id ) ),
			);
		}

		try {
			// Mirror the exact write path the Elementor editor uses on Save.
			$doc->update_json_meta( '_elementor_data', $elements );
		} catch ( \Throwable $e ) {
			return array(
				'success'  => false,
				'warnings' => array( sprintf( 'elementor_update_threw: %s', $e->getMessage() ) ),
			);
		}

		// Drop the per-post Elementor CSS file so the next render rebuilds it.
		try {
			if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
				\Elementor\Core\Files\CSS\Post::create( $post_id )->delete();
			}
		} catch ( \Throwable $e ) {
			$msg = 'Post-CSS delete failed for ' . $post_id . ': ' . $e->getMessage();
			error_log( '[novamira-adrianv2] ' . $msg );
			$warnings[] = $msg;
		}

		// Also punch the global files_manager cache (covers kits + global CSS).
		try {
			if (
				class_exists( '\Elementor\Plugin' )
				&& isset( \Elementor\Plugin::$instance->files_manager )
			) {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			}
		} catch ( \Throwable $e ) {
			$msg = 'Elementor files_manager->clear_cache() failed for post ' . $post_id . ': ' . $e->getMessage();
			error_log( '[novamira-adrianv2] ' . $msg );
			$warnings[] = $msg;
		}

		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $post_id );
		}

		return array(
			'success'  => true,
			'warnings' => $warnings,
		);
	}

	/**
	 * Assigns or appends a CSS class on a single Elementor element.
	 *
	 * Sanitizes the new class name, then:
	 *   - Always writes the merged string to BOTH `settings.css_classes`
	 *     (live-render field) and `settings._css_classes` (3.x fallback),
	 *     so v3.x and v4 compatibility-mode render paths both honor it.
	 *   - When the element carries a v4-atomic `settings.classes` wrapper
	 *     (shape `{$$type:'classes', value:[list]}`), also appends the
	 *     sanitized class into that list. Logs a warning so caller knows
	 *     a plain CSS hook name like "productitem" only renders as a
	 *     style reference once a matching entry in the page's styles
	 *     map exists (or the agent pairs this call with
	 *     Elementor_CSS_Override::inject_page_custom_css targeting the
	 *     class via a real CSS rule).
	 *
	 * @param array  $element            Element tree element (passed by reference; mutated).
	 * @param string $new_class          Single CSS class name (will be sanitized).
	 * @param bool   $append_to_existing If true, keep existing classes and append.
	 * @return true|\WP_Error             True on success, WP_Error on validation failure.
	 */
	public static function assign_class( array &$element, string $new_class, bool $append_to_existing = true ) {
		if ( '' === trim( $new_class ) ) {
			return new \WP_Error(
				'elementor_empty_class',
				__( 'Class name cannot be empty.', 'novamira-adrianv2' )
			);
		}
		$clean = function_exists( 'sanitize_html_class' )
			? sanitize_html_class( $new_class )
			: preg_replace( '/[^a-zA-Z0-9_\-]/', '', $new_class );
		if ( ! is_string( $clean ) || '' === $clean ) {
			return new \WP_Error(
				'elementor_invalid_class',
				sprintf(
					/* translators: %s: original (unsanitized) class name */
					__( 'Class name %1$s is invalid after sanitization.', 'novamira-adrianv2' ),
					$new_class
				)
			);
		}

		if ( ! isset( $element['settings'] ) || ! is_array( $element['settings'] ) ) {
			$element['settings'] = array();
		}

		// ---- v4-atomic classes.value branch (best-effort) ----------------
		$has_v4_atomic_classes = (
			isset( $element['settings']['classes'] )
			&& is_array( $element['settings']['classes'] )
			&& isset( $element['settings']['classes']['$$type'] )
			&& 'classes' === $element['settings']['classes']['$$type']
			&& isset( $element['settings']['classes']['value'] )
			&& is_array( $element['settings']['classes']['value'] )
		);
		if ( $has_v4_atomic_classes ) {
			$v4_list = $element['settings']['classes']['value'];
			if ( $append_to_existing ) {
				if ( ! in_array( $clean, $v4_list, true ) ) {
					$v4_list[] = $clean;
				}
			} else {
				$v4_list = array( $clean );
			}
			$element['settings']['classes']['value'] = $v4_list;

			error_log(
				sprintf(
					'[novamira-adrianv2] assign_class wrote "%1$s" into v4-atomic classes.value list. ' .
					'Elementor 4.x resolves list entries as style reference IDs — ' .
					'create a matching entry in the page styles map, or pair this call with ' .
					'Elementor_CSS_Override::inject_page_custom_css targeting ".%1$s" with the actual CSS.',
					$clean
				)
			);
		}

		// ---- legacy string field branch (always, for v3 + v4 compat-mode)
		$existing = '';
		if ( isset( $element['settings']['css_classes'] ) && is_string( $element['settings']['css_classes'] ) ) {
			$existing = $element['settings']['css_classes'];
		}

		if ( $append_to_existing ) {
			$tokens  = preg_split( '/\s+/', $existing, -1, PREG_SPLIT_NO_EMPTY ) ?: array();
			$present = in_array( $clean, $tokens, true );
			$merged  = $present ? $existing : trim( $existing . ' ' . $clean );
		} else {
			$merged = $clean;
		}

		// Always sync both legacy fields - see class-level docblock.
		$element['settings']['css_classes']  = $merged;
		$element['settings']['_css_classes'] = $merged;

		return true;
	}
}
