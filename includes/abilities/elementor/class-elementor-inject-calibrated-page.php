<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Ability: novamira-adrianv2/elementor-inject-calibrated-page
 *
 * WRITE — idempotent. Safe-mutation counterpart to the upstream
 * `.claude/skills/elementor/inject.php` (which uses raw `update_post_meta`
 * and therefore bypasses `wp_check_post_lock` AND Elementor's
 * `update_json_meta` cache hooks). This ability routes every mutation
 * through `Novamira\\AdrianV2\\Helpers\\Elementor_Document_Saver::save_data()`,
 * which uses Elementor's Document API: `documents->get($post_id)` →
 * `$doc->update_json_meta('_elementor_data', $elements)` → Post-CSS delete
 * → files_manager->clear_cache() → clean_post_cache($post_id).
 *
 * Chicken-and-egg: Elementor's `documents->get($post_id)` factory refuses
 * to return a `\\Elementor\\Core\\DocumentTypes\\Page` unless the post already
 * has `_elementor_edit_mode='builder'`. On first-ever write for a page
 * created outside the Elementor editor, the ability seeds the four boot
 * metas via raw `update_post_meta` BEFORE delegating to `save_data()`. On
 * subsequent writes the boot-meta writes are idempotent no-ops (same
 * values are reasserted, post-meta cache is dropped regardless).
 *
 * Permission: the global `novamira_permission_callback` is the broad gate
 * (matches every other Elementor ability in this plugin). A per-post check
 * (`edit_post` + (only when published) `edit_published_pages`) runs inside
 * `execute()` via the static `check_inject_permission()` method. The
 * `edit_theme_options` cap is intentionally NOT required — this ability
 * writes post-meta only, which `edit_post` already covers.
 *
 * @package Novamira_AdrianV2
 * @since   1.1.0
 */

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers\Elementor_Document_Saver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Elementor_Inject_Calibrated_Page {

	/**
	 * Register the single `novamira-adrianv2/elementor-inject-calibrated-page`
	 * ability against the WordPress Abilities API. Idempotent — safe to call
	 * multiple times in a request.
	 */
	public static function register(): void {
		wp_register_ability(
			'novamira-adrianv2/elementor-inject-calibrated-page',
			array(
				'label'               => __( 'Elementor — Inject Calibrated Page', 'novamira-adrianv2' ),
				'description'         => __(
					'Inject a calibrated `_elementor_data` JSON tree into a specific post or page. Routes through `Elementor_Document_Saver::save_data()` so the Document API + `wp_check_post_lock` + per-post CSS rebuild run the same path the Elementor editor uses on Save. Use this for whole-page rebuilds after a `_clone real atoms_` workflow; for targeted mutation use `assign-class-to-containers` instead.',
					'novamira-adrianv2'
				),
				'category'            => 'adrianv2-live-edit',
				'execute_callback'    => array( self::class, 'execute' ),
				'permission_callback' => 'novamira_permission_callback',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'           => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'_elementor_data'   => array(
							'type'                 => 'array',
							'description'          => __( 'A JSON-decodable tree of Elementor elements. Each top-level entry must have `id` and `elType` keys.', 'novamira-adrianv2' ),
						),
						'elementor_version' => array(
							'type'    => 'string',
							'default' => '3.0.0',
						),
						'wp_page_template'  => array(
							'type'    => 'string',
							'enum'    => array( 'elementor_header_footer', 'elementor_canvas', 'default' ),
							'default' => 'default',
						),
						'mode'              => array(
							'type'    => 'string',
							'enum'    => array( 'overwrite', 'merge_by_id' ),
							'default' => 'overwrite',
						),
					),
					'required' => array( 'post_id', '_elementor_data' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'            => array( 'type' => 'boolean' ),
						'post_id'            => array( 'type' => 'integer' ),
						'sections_count'     => array(
							'type'        => 'integer',
							'description' => __( 'Top-level element count in the post-mutation tree (or in the merge deltas, when mode=merge_by_id).', 'novamira-adrianv2' ),
						),
						'kit_id'             => array(
							'type'        => array( 'integer', 'null' ),
							'description' => __( 'Active Elementor kit post ID (null when kits_manager is unavailable).', 'novamira-adrianv2' ),
						),
						'warnings'           => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'blocks_invalidated' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Cached surfaces the ability tore down (`post_css`, `files_manager_global`, `post_meta_cache`).', 'novamira-adrianv2' ),
						),
						'saved_at'           => array(
							'type'        => 'string',
							'description' => __( 'ISO-8601 UTC timestamp of the successful write (or attempted write on failure).', 'novamira-adrianv2' ),
						),
					),
					'required' => array( 'success', 'post_id', 'sections_count', 'warnings', 'blocks_invalidated', 'saved_at' ),
				),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array(
						/* translators: keep as a single paragraph string so MCP clients surface it verbatim. */
						'instructions' => __(
							"Use this ability to inject or replace an entire Elementor page tree from a calibrated JSON. ROUTE through this ability (NOT raw update_post_meta) so Elementor's Document API + wp_check_post_lock + per-post CSS rebuild fire. ALWAYS seed _elementor_edit_mode='builder' first when the page was created outside the Elementor editor — the ability handles this internally. mode='overwrite' replaces the entire tree; mode='merge_by_id' matches by element id and replaces subtrees at the matched locations OR appends unmatched incoming elements at the bottom. elementor_version is informational — a payload older than the active plugin triggers a warning, not a hard fail (intentional for back-port testing). Pre-condition: Elementor plugin must be active.",
							'novamira-adrianv2'
						),
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	/**
	 * Execute the page-injection.
	 *
	 * Returns a structured array matching the registered output_schema. On
	 * any failure the `success` flag is `false`, `sections_count = 0`,
	 * `kit_id = null`, `blocks_invalidated = []`, and `warnings[]` carries
	 * the human-readable error description. On success `warnings[]` carries
	 * soft-fail notices only (e.g. stale `elementor_version`); the
	 * `blocks_invalidated[]` list confirms which cache surfaces were torn
	 * down by the underlying `Elementor_Document_Saver::save_data()` call.
	 *
	 * @param array<string, mixed>|null $input
	 * @return array<string, mixed>
	 */
	public static function execute( $input = null ): array {
		$input = is_array( $input ) ? $input : array();

		// ---- 1. Extract + normalize base inputs. ----
		$post_id  = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		$el_data  = ( isset( $input['_elementor_data'] ) && is_array( $input['_elementor_data'] ) )
			? $input['_elementor_data']
			: null;
		$version  = isset( $input['elementor_version'] ) && is_string( $input['elementor_version'] ) && '' !== $input['elementor_version']
			? (string) $input['elementor_version']
			: '3.0.0';
		$template = isset( $input['wp_page_template'] ) && in_array( $input['wp_page_template'], array( 'elementor_header_footer', 'elementor_canvas', 'default' ), true )
			? (string) $input['wp_page_template']
			: 'default';
		$mode     = isset( $input['mode'] ) && 'merge_by_id' === $input['mode']
			? 'merge_by_id'
			: 'overwrite';

		// ---- 2. Hard validation gates. ----
		// Ordered cheapest-first: a locked page or inactive Elementor
		// fails fast BEFORE we pay the cost of shape validation +
		// permission lookups.
		if ( $post_id <= 0 ) {
			return self::fail_response( $post_id, 'invalid_post_id: post_id must be a positive integer.' );
		}
		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			return self::fail_response( $post_id, 'elementor_inactive: Elementor plugin is not active on this site.' );
		}
		if ( function_exists( 'wp_check_post_lock' ) && wp_check_post_lock( $post_id ) ) {
			return self::fail_response( $post_id, 'post_locked: another user is currently editing this post in Elementor.' );
		}
		if ( null === $el_data ) {
			return self::fail_response( $post_id, 'invalid_elementor_data: `_elementor_data` is required and must be a JSON-decodable array.' );
		}
		if ( ! self::is_valid_elementor_tree( $el_data ) ) {
			return self::fail_response( $post_id, 'invalid_elementor_data: payload must be a non-empty list whose first entry has both `id` and `elType` keys.' );
		}
		if ( ! self::check_inject_permission( $post_id ) ) {
			return self::fail_response( $post_id, 'permission_denied: current user lacks edit_post on the post, or (for publish-status posts) edit_published_pages.' );
		}

		$warnings = array();

		// ---- 3. Soft-warn if payload version is older than the active plugin. ----
		if ( defined( 'ELEMENTOR_VERSION' ) && version_compare( $version, ELEMENTOR_VERSION, '<' ) ) {
			$warnings[] = sprintf(
				/* translators: 1: payload version, 2: active Elementor version */
				__( 'payload_version_old: payload elementor_version `%1$s` is older than active Elementor `%2$s` — backend render may differ from the source-of-truth.', 'novamira-adrianv2' ),
				$version,
				ELEMENTOR_VERSION
			);
		}

		// ---- 4. Merge strategy: replace-by-id-and-append if requested. ----
		if ( 'merge_by_id' === $mode ) {
			$existing_data = self::read_existing_tree( $post_id );
			if ( null === $existing_data ) {
				// Contract violation: caller asked for merge but there's
				// no existing tree to merge INTO. Fail loudly rather than
				// silent-overwrite, otherwise an agent's "merge" intent
				// cannot be distinguished from "overwrite" intent.
				return self::fail_response(
					$post_id,
					'merge_no_existing_tree: mode=merge_by_id but current _elementor_data is missing or unparseable; refusing to fall back to overwrite.'
				);
			}
			$el_data = self::merge_trees_by_id( $existing_data, $el_data );
		}

		// ---- 5. Seed the four boot metas via raw update_post_meta so ----
		//      Elementor's Document Loader can find the post on first-ever
		//      write. On subsequent writes the writes are idempotent
		//      no-ops (same values reasserted, post-meta cache is dropped
		//      regardless in step 6).
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );
		update_post_meta( $post_id, '_elementor_version', $version );
		update_post_meta( $post_id, '_wp_page_template', $template );

		// ---- 6. Drop the post-meta cache so Elementor's `documents->get()` ----
		//      factory reads the freshly-written `_elementor_edit_mode`
		//      instead of the stale memoised value. Without this,
		//      documents->get() may have cached the "page with no
		//      _elementor_edit_mode" path earlier in the request and
		//      return the wrong Document class for this post.
		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $post_id );
		}

		// ---- 7. Delegate to Elementor_Document_Saver — handles ----
		//      wp_check_post_lock (second time, defensively), update_json_meta,
		//      Post-CSS delete, files_manager->clear_cache, clean_post_cache.
		$save_result = Elementor_Document_Saver::save_data( $post_id, $el_data );

		if ( ! $save_result['success'] ) {
			return self::fail_response(
				$post_id,
				sprintf(
					/* translators: %s: warnings joined by space */
					__( 'elementor_save_failed: %s', 'novamira-adrianv2' ),
					implode( ' ', $save_result['warnings'] )
				)
			);
		}

		// Append any soft warnings returned by the saver (e.g. CSS delete failures).
		$warnings = array_merge( $warnings, $save_result['warnings'] );

		// ---- 8. Resolve the active kit id (null when kits_manager unavailable). ----
		$kit_id = null;
		if (
			isset( \Elementor\Plugin::$instance->kits_manager )
			&& method_exists( \Elementor\Plugin::$instance->kits_manager, 'get_active_id' )
		) {
			$active_kit = \Elementor\Plugin::$instance->kits_manager->get_active_id();
			if ( is_numeric( $active_kit ) ) {
				$kit_id = (int) $active_kit;
			}
		}

		return array(
			'success'            => true,
			'post_id'            => $post_id,
			'sections_count'     => is_array( $el_data ) ? count( $el_data ) : 0,
			'kit_id'             => $kit_id,
			'warnings'           => $warnings,
			'blocks_invalidated' => array( 'post_css', 'files_manager_global', 'post_meta_cache' ),
			'saved_at'           => gmdate( 'c' ),
		);
	}

	/**
	 * Per-post + global permission gate. Returns true only when the current
	 * user can edit this specific post AND (for already-published posts)
	 * holds the `edit_published_pages` cap. `edit_theme_options` is NOT
	 * required — this ability only writes post-meta, which `edit_post` covers.
	 *
	 * @param int $post_id
	 * @return bool
	 */
	private static function check_inject_permission( int $post_id ): bool {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}
		if ( 'publish' === get_post_status( $post_id ) && ! current_user_can( 'edit_published_pages' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Structural validation of the input tree — proves the payload round-trips
	 * Elementor's expected top-level shape (non-empty list; first element has
	 * both `id` and `elType`). Deeper shape validation is delegated to the
	 * Elementor Document Loader itself when it ingests the tree.
	 *
	 * @param array $data
	 * @return bool
	 */
	private static function is_valid_elementor_tree( array $data ): bool {
		if ( empty( $data ) || ! array_is_list( $data ) ) {
			return false;
		}
		$first = $data[0] ?? null;
		return is_array( $first ) && isset( $first['id'], $first['elType'] );
	}

	/**
	 * Read the existing `_elementor_data` JSON for a post. Returns null when
	 * the post is not Elementor-aware (no `_elementor_edit_mode`) OR the data
	 * is unreadable. Always stripslashes so callers receive a clean JSON string.
	 *
	 * @param int $post_id
	 * @return array<int|string, mixed>|null
	 */
	private static function read_existing_tree( int $post_id ): ?array {
		if ( 'builder' !== get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
			return null;
		}
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$decoded = json_decode( stripslashes_deep( $raw ), true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Recursive DFS merge: walk both trees, replace subtrees at any matching
	 * `id`. Top-level unmatched incoming elements are appended at the bottom
	 * of the result. Descendant recursions apply the same rule on the inner
	 * `elements` arrays.
	 *
	 * Idempotent: passing an existing tree + itself yields the input tree
	 * unchanged (every id matches itself exactly).
	 *
	 * @param array<int|string, mixed> $existing
	 * @param array<int|string, mixed> $incoming
	 * @return array<int|string, mixed>
	 */
	private static function merge_trees_by_id( array $existing, array $incoming ): array {
		$matched_ids  = array();
		$result       = array();

		foreach ( $existing as $el ) {
			$el_id        = is_array( $el ) && isset( $el['id'] ) ? (string) $el['id'] : null;
			$replacement  = null;

			foreach ( $incoming as $inc ) {
				if ( ! is_array( $inc ) || ! isset( $inc['id'] ) ) {
					continue;
				}
				if ( (string) $inc['id'] === $el_id ) {
					$replacement            = $inc;
					$matched_ids[ $el_id ]  = true;
					break;
				}
			}

			if ( null !== $replacement ) {
				if (
					isset( $el['elements'], $replacement['elements'] )
					&& is_array( $el['elements'] )
					&& is_array( $replacement['elements'] )
				) {
					$replacement['elements'] = self::merge_trees_by_id( $el['elements'], $replacement['elements'] );
				}
				$result[] = $replacement;
			} else {
				$result[] = $el;
			}
		}

		foreach ( $incoming as $inc ) {
			if ( ! is_array( $inc ) || ! isset( $inc['id'] ) ) {
				continue;
			}
			if ( ! isset( $matched_ids[ (string) $inc['id'] ] ) ) {
				$result[] = $inc;
			}
		}

		return $result;
	}

	/**
	 * Build the standard failure response. Always populated with the same
	 * schema shape as a success response so callers can treat the result
	 * uniformly. The `warnings[]` array carries the human-readable error
	 * description(s) — keep keys short, codes-as-prefix preferred.
	 *
	 * @param int    $post_id
	 * @param string $error
	 * @return array<string, mixed>
	 */
	private static function fail_response( int $post_id, string $error ): array {
		return array(
			'success'            => false,
			'post_id'            => $post_id,
			'sections_count'     => 0,
			'kit_id'             => null,
			'warnings'           => array( $error ),
			'blocks_invalidated' => array(),
			'saved_at'           => gmdate( 'c' ),
		);
	}
}
