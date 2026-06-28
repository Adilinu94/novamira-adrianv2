<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Conversion_AutoFixer — applies automatic fixes to a converted V4 Atomic tree.
 *
 * Re-scans the tree independently (does not parse audit messages) for robustness.
 * Two-pass architecture:
 *   1. Bottom-up structural fix (empty containers, empty/broken widgets, e-div-block wrapping)
 *   2. Global style fix (orphan/duplicate styles, dangling refs, responsive variants)
 *
 * @package Novamira_AdrianV2
 * @since   1.4.0
 */
final class Conversion_AutoFixer {

	// ── Container-like elTypes ──
	private const CONTAINER_TYPES = array( 'e-flexbox', 'e-div-block' );

	// ── Props that need responsive variants ──
	private const RESPONSIVE_PROPS = array(
		'font-size', 'padding-block-start', 'padding-block-end',
		'padding-inline-start', 'padding-inline-end',
		'margin-block-start', 'margin-block-end',
		'margin-inline-start', 'margin-inline-end',
		'width', 'max-width', 'gap',
	);

	// ── Scaling factors: desktop → mobile / tablet ──
	private const MOBILE_SCALE  = array(
		'font-size' => 0.8, 'padding-block-start' => 0.5, 'padding-block-end' => 0.5,
		'padding-inline-start' => 0.5, 'padding-inline-end' => 0.5,
		'margin-block-start' => 0.5, 'margin-block-end' => 0.5,
		'margin-inline-start' => 0.5, 'margin-inline-end' => 0.5,
		'width' => 0, 'max-width' => 0, 'gap' => 0.5, // 0 = special: width→100%
	);
	private const TABLET_SCALE  = array(
		'font-size' => 0.9, 'padding-block-start' => 0.75, 'padding-block-end' => 0.75,
		'padding-inline-start' => 0.75, 'padding-inline-end' => 0.75,
		'margin-block-start' => 0.75, 'margin-block-end' => 0.75,
		'margin-inline-start' => 0.75, 'margin-inline-end' => 0.75,
		'width' => 0, 'max-width' => 0, 'gap' => 0.75,
	);

	// ── Widget types whose content is checked/required ──
	private const CONTENT_WIDGETS = array(
		'e-heading'   => 'title',
		'e-paragraph' => 'paragraph',
		'e-button'    => 'text',
	);

	// ── Minimum clamped values (px) ──
	private const FONT_SIZE_FLOOR_PX = 14;

	/** @var array|null Cached Kit element tree. */
	private static $kit_tree_cache = null;
	/** @var int|null Kit post ID for cache validation. */
	private static $kit_tree_cache_id = null;

	// ── Maximum allowed nesting depth before auto-flattening ──
	private const MAX_NESTING_DEPTH = 4; // Lowered from 5: align with layout-audit depth-3 threshold (field-tested 2026-06)

	// ── Layout-critical settings that prevent container flattening ──
	private const LAYOUT_CRITICAL_KEYS = array(
		'flex_direction', 'justify_content', 'align_items', 'align_content',
		'flex_wrap', 'gap', 'row_gap', 'column_gap',
		'position', 'z_index', 'overflow', 'display',
		'width', 'max_width', 'height', 'min_height',
		'margin',
	);

	/**
	 * Run all auto-fixes on a V4 tree.
	 *
	 * @param array $tree          Converted V4 element tree.
	 * @param int   &$total_fixes  Output: total number of fixes applied.
	 * @return array Fixed V4 tree.
	 */
	public static function run( array $tree, int &$total_fixes = 0 ): array {
		$total_fixes = 0;
		$fixes       = 0;

		// ── Pass 1: Bottom-up structural fixes ──
		// Order matters: remove broken widgets first, then wrap survivors,
		// then remove containers that became empty after clean-up.

		// 1a. Remove empty content widgets (e-heading without title, etc.)
		$tree    = self::fix_empty_content_widgets( $tree, $fixes );
		$total_fixes += $fixes;

		// 1b. Remove broken image widgets (no source).
		$tree    = self::fix_broken_image_widgets( $tree, $fixes );
		$total_fixes += $fixes;

		// 1c. Wrap direct widget children of e-flexbox in e-div-block.
		$tree    = self::fix_flexbox_widget_children( $tree, $fixes );
		$total_fixes += $fixes;

		// 1d. Remove empty containers (may have become empty after 1a/1b).
		$tree    = self::fix_empty_containers( $tree, $fixes );
		$total_fixes += $fixes;

		// 1e. Flatten excessive nesting depth (>5). Runs after empty-container
		//     removal so pass-through wrappers at depth can be safely bypassed.
		//     Must run AFTER fix_flexbox_widget_children so aw-* wrappers
		//     at excessive depth get flattened too. Fix 5 is depth-aware,
		//     so re-wrapping at depth ≥ 5 is suppressed and no cycle occurs.
		$tree    = self::fix_excessive_nesting( $tree, $fixes );
		$total_fixes += $fixes;

		// ── Pass 2: Global style fixes ──

		// 2a. Remove dangling class references (referenced but never defined).
		//     Runs before orphan-styles so styles that lose their last ref get cleaned.
		$tree    = self::fix_dangling_class_refs( $tree, $fixes );
		$total_fixes += $fixes;

		// 2b. Remove orphan styles (defined but never referenced).
		$tree    = self::fix_orphan_styles( $tree, $fixes );
		$total_fixes += $fixes;

		// 2c. Deduplicate style definitions (same class_id on multiple elements).
		$tree    = self::fix_duplicate_styles( $tree, $fixes );
		$total_fixes += $fixes;

		// 2d. Generate missing responsive variants (tablet/mobile).
		$tree    = self::generate_responsive_variants( $tree, $fixes );
		$total_fixes += $fixes;

		// 2e. Remove identical mobile overrides. This can delete entire
		//     mobile variants whose props were all identical to desktop
		//     (preserved as-is from V3). Steps 2f/2g regenerate those
		//     variants with properly scaled values.
		$tree    = self::remove_identical_mobile_overrides( $tree, $fixes );
		$total_fixes += $fixes;

		// 2f. Second pass: regenerate mobile variants that were deleted
		//     by step 2e because their V3 values happened to match desktop.
		//     The regenerated variants use scaled values (e.g. font-size × 0.8)
		//     that differ from desktop, so step 2g keeps them.
		$tree    = self::generate_responsive_variants( $tree, $fixes );
		$total_fixes += $fixes;

		// 2g. Clean up any newly identical overrides from 2f.
		$tree    = self::remove_identical_mobile_overrides( $tree, $fixes );
		$total_fixes += $fixes;

		// 2h. Convert equal-width N-column flex rows to CSS Grid.
		// Reduces nesting depth: children no longer need explicit width.
		// Only applied to pass-through column containers (no styling/layout).
		$tree    = self::fix_grid_candidates( $tree, $fixes );
		$total_fixes += $fixes;

		return $tree;
	}

	// =====================================================================
	// Fix 1: Remove empty containers (bottom-up)
	// =====================================================================

	/**
	 * Bottom-up removal of empty containers without visual styling.
	 */
	private static function fix_empty_containers( array $elements, int &$fixes ): array {
		$out = array();

		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				$out[] = $el;
				continue;
			}

			$el_type = $el['elType'] ?? '';

			// Recurse first (bottom-up).
			if ( ! empty( $el['elements'] ?? array() ) ) {
				$el['elements'] = self::fix_empty_containers( $el['elements'], $fixes );
			}

			// Check if this is an empty container.
			$children = $el['elements'] ?? array();
			if ( in_array( $el_type, self::CONTAINER_TYPES, true ) && empty( $children ) ) {
				if ( ! self::has_visual_styling( $el['settings'] ?? array(), $el['styles'] ?? array() ) ) {
					$fixes++;
					continue; // Remove this element.
				}
			}

			$out[] = $el;
		}

		return array_values( $out );
	}

	// =====================================================================
	// Fix 2: Remove orphan styles
	// =====================================================================

	/**
	 * Remove style class definitions that are never referenced by any element.
	 */
	private static function fix_orphan_styles( array $tree, int &$fixes ): array {
		// Step 1: Collect all referenced class IDs across the tree.
		$referenced = array();
		self::collect_referenced_class_ids( $tree, $referenced );

		if ( empty( $referenced ) ) {
			return $tree;
		}

		// Step 2: Walk tree, remove unreferenced styles from each element.
		return self::remove_orphan_styles_walk( $tree, $referenced, $fixes );
	}

	private static function collect_referenced_class_ids( array $elements, array &$referenced ): void {
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}

			$classes = $el['settings']['classes']['value'] ?? array();
			foreach ( $classes as $cid ) {
				$referenced[ $cid ] = true;
			}

			$children = $el['elements'] ?? array();
			if ( ! empty( $children ) ) {
				self::collect_referenced_class_ids( $children, $referenced );
			}
		}
	}

	private static function remove_orphan_styles_walk( array $elements, array $referenced, int &$fixes ): array {
		foreach ( $elements as &$el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}

			$styles = $el['styles'] ?? array();
			foreach ( $styles as $class_id => $style_def ) {
				if ( ! isset( $referenced[ $class_id ] ) ) {
					unset( $el['styles'][ $class_id ] );
					$fixes++;
				}
			}

			$children = $el['elements'] ?? array();
			if ( ! empty( $children ) ) {
				$el['elements'] = self::remove_orphan_styles_walk( $children, $referenced, $fixes );
			}
		}
		unset( $el );

		return $elements;
	}

	// =====================================================================
	// Fix 3: Generate missing responsive variants
	// =====================================================================

	/**
	 * Generate tablet/mobile variants for style classes that only have desktop.
	 */
	private static function generate_responsive_variants( array $tree, int &$fixes ): array {
		foreach ( $tree as &$el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}

			if ( ! isset( $el['styles'] ) || ! is_array( $el['styles'] ) ) {
				continue;
			}
			$styles = &$el['styles'];
			// Snapshot keys before iterating: PHP's by-reference foreach
			// can skip elements when nested array modifications trigger
			// Copy-On-Write or HashTable reallocation.
			$class_ids = array_keys( $styles );
			foreach ( $class_ids as $class_id ) {
				$style_def = &$styles[ $class_id ];
				self::process_style_variants( $style_def, $fixes );
			}
			unset( $style_def );

			$children = $el['elements'] ?? array();
			if ( ! empty( $children ) ) {
				$el['elements'] = self::generate_responsive_variants( $children, $fixes );
			}
		}
		unset( $el );

		return $tree;
	}

	/**
	 * Process a single style definition: generate missing tablet/mobile
	 * variants from desktop props.
	 *
	 * @param array &$style_def Style definition with 'variants' key.
	 * @param int   &$fixes     Fix counter (incremented per generated variant).
	 */
	private static function process_style_variants( array &$style_def, int &$fixes ): void {
		$variants = $style_def['variants'] ?? array();
		if ( ! is_array( $variants ) || empty( $variants ) ) {
			return;
		}

		// Find existing breakpoints.
		$has_desktop   = false;
		$has_tablet    = false;
		$has_mobile    = false;
		$desktop_idx   = null;
		$desktop_props = array();

		foreach ( $variants as $idx => $v ) {
			$bp = $v['meta']['breakpoint'] ?? '';
			if ( 'desktop' === $bp ) {
				$has_desktop   = true;
				$desktop_idx   = $idx;
				$desktop_props = $v['props'] ?? array();
			} elseif ( 'tablet' === $bp ) {
				$has_tablet = true;
			} elseif ( 'mobile' === $bp ) {
				$has_mobile = true;
			}
		}

		if ( ! $has_desktop || empty( $desktop_props ) ) {
			return;
		}

		// Check if there are important props that need responsive variants.
		$needs_responsive = array_intersect_key( $desktop_props, array_flip( self::RESPONSIVE_PROPS ) );

		if ( empty( $needs_responsive ) ) {
			return;
		}

		$local_fixes = 0;

		// Generate tablet variant if missing.
		if ( ! $has_tablet ) {
			$tablet_props = self::scale_props( $desktop_props, self::TABLET_SCALE );
			if ( ! empty( $tablet_props ) ) {
				$insert_at = (int) $desktop_idx + 1;
				$tablet_variant = array(
					'meta'       => array( 'breakpoint' => 'tablet', 'state' => null ),
					'props'      => $tablet_props,
					'custom_css' => null,
				);
				array_splice( $style_def['variants'], $insert_at, 0, array( $tablet_variant ) );
				$local_fixes++;
			}
		}

		// Generate mobile variant if missing.
		if ( ! $has_mobile ) {
			$mobile_props = self::scale_props( $desktop_props, self::MOBILE_SCALE );
			if ( ! empty( $mobile_props ) ) {
				$style_def['variants'][] = array(
					'meta'       => array( 'breakpoint' => 'mobile', 'state' => null ),
					'props'      => $mobile_props,
					'custom_css' => null,
				);
				$local_fixes++;
			}
		}

		$fixes += $local_fixes;
	}

	/**
	 * Scale a set of $$type-wrapped props by the given multipliers.
	 *
	 * @param array $props  V4 props: [css_prop => {$$type, value}].
	 * @param array $scales [css_prop => multiplier].
	 * @return array Scaled props.
	 */
	private static function scale_props( array $props, array $scales ): array {
		$out = array();

		foreach ( $props as $prop => $def ) {
			$scale = $scales[ $prop ] ?? null;
			if ( null === $scale ) {
				continue; // Not a responsive-relevant prop.
			}

			$type  = $def['$$type'] ?? '';
			$value = $def['value'] ?? null;

			if ( 'size' === $type && is_array( $value ) && isset( $value['size'] ) ) {
				$unit = $value['unit'] ?? 'px';
				$new_size = (float) $value['size'];

			if ( 0.0 === (float) $scale ) {
				// Special: width/max-width → 100% for mobile/tablet.
				// Other props with scale=0 are intentionally skipped —
				// add explicit handling if new 0-scale props are added.
					if ( in_array( $prop, array( 'width', 'max-width' ), true ) ) {
						$out[ $prop ] = array(
							'$$type' => 'size',
							'value'  => array( 'size' => 100, 'unit' => '%' ),
						);
					}
					continue;
				}

				$new_size = round( $new_size * $scale, 1 );

				// Clamp font-size.
				if ( 'font-size' === $prop && 'px' === $unit ) {
					$new_size = max( $new_size, self::FONT_SIZE_FLOOR_PX );
				}

				$out[ $prop ] = array(
					'$$type' => 'size',
					'value'  => array( 'size' => $new_size, 'unit' => $unit ),
				);
			} elseif ( 'string' === $type ) {
				// String props (like gap with calc) — skip scaling.
				continue;
			}
		}

		return $out;
	}

	// =====================================================================
	// Fix 4: Remove identical mobile overrides
	// =====================================================================

	/**
	 * Remove mobile variant props that are identical to desktop values.
	 * If the mobile variant becomes empty after removal, delete it entirely.
	 */
	private static function remove_identical_mobile_overrides( array $tree, int &$fixes ): array {
		foreach ( $tree as &$el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}

			if ( ! isset( $el['styles'] ) || ! is_array( $el['styles'] ) ) {
				continue;
			}
			$styles = &$el['styles'];
			// Snapshot keys before iterating: prevents PHP iterator skip
			// caused by Copy-On-Write after nested array modifications.
			$class_ids = array_keys( $styles );
			foreach ( $class_ids as $class_id ) {
				$style_def = &$styles[ $class_id ];
				self::clean_mobile_overrides( $style_def, $fixes );
			}
			unset( $style_def );

			$children = $el['elements'] ?? array();
			if ( ! empty( $children ) ) {
				$el['elements'] = self::remove_identical_mobile_overrides( $children, $fixes );
			}
		}
		unset( $el );

		return $tree;
	}

	/**
	 * Remove mobile variant props identical to desktop for a single style definition.
	 *
	 * @param array &$style_def Style definition with 'variants' key.
	 * @param int   &$fixes     Fix counter.
	 */
	private static function clean_mobile_overrides( array &$style_def, int &$fixes ): void {
		$variants = $style_def['variants'] ?? array();
		if ( ! is_array( $variants ) || count( $variants ) < 2 ) {
			return;
		}

		// Find desktop and mobile variants.
		$desktop_idx   = null;
		$desktop_props = array();
		$mobile_idx    = null;

		foreach ( $variants as $idx => $v ) {
			$bp = $v['meta']['breakpoint'] ?? '';
			if ( 'desktop' === $bp ) {
				$desktop_idx   = $idx;
				$desktop_props = $v['props'] ?? array();
			} elseif ( 'mobile' === $bp ) {
				$mobile_idx = $idx;
			}
		}

		if ( null === $desktop_idx || null === $mobile_idx ) {
			return;
		}

		$mobile_props = &$style_def['variants'][ $mobile_idx ]['props'];
		$removed      = 0;

		foreach ( $mobile_props as $prop => $value ) {
			if ( isset( $desktop_props[ $prop ] ) && $desktop_props[ $prop ] === $value ) {
				unset( $mobile_props[ $prop ] );
				$removed++;
			}
		}

		if ( $removed > 0 ) {
			$fixes += $removed;

			// Remove empty mobile variant entirely.
			if ( empty( $mobile_props ) ) {
				unset( $style_def['variants'][ $mobile_idx ] );
				$style_def['variants'] = array_values( $style_def['variants'] );
			}
		}
	}

	// =====================================================================
	// Fix 10: Flatten excessive nesting depth
	// =====================================================================

	/**
	 * Bypass pass-through containers at excessive depth (depth >= MAX_NESTING_DEPTH).
	 *
	 * A container is "pass-through" if it has exactly one child and no visual
	 * or layout-critical styling — removing it won't change the rendered output.
	 *
	 * Bottom-up: children are flattened first, then the parent is checked.
	 * This means deeply nested wrappers collapse upward in a single pass.
	 *
	 * @param array $elements Current level of elements.
	 * @param int   &$fixes   Fix counter.
	 * @param int   $depth    Current nesting depth (1-indexed).
	 * @return array Flattened elements.
	 */
	private static function fix_excessive_nesting( array $elements, int &$fixes, int $depth = 1 ): array {
		$out = array();

		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				$out[] = $el;
				continue;
			}

			$el_type  = $el['elType'] ?? '';
			$children = $el['elements'] ?? array();

			// Recurse first (bottom-up).
			if ( ! empty( $children ) ) {
				$el['elements'] = self::fix_excessive_nesting( $children, $fixes, $depth + 1 );
			}

			// Only flatten container types (not widgets).
			if ( ! in_array( $el_type, self::CONTAINER_TYPES, true ) ) {
				$out[] = $el;
				continue;
			}

			// Only flatten if at or beyond max depth.
			if ( $depth < self::MAX_NESTING_DEPTH ) {
				$out[] = $el;
				continue;
			}

			$updated_children = $el['elements'] ?? array();

			// Must have at least one child to bypass.
			if ( empty( $updated_children ) ) {
				$out[] = $el;
				continue;
			}

			// Must have no visual styling.
			if ( self::has_visual_styling( $el['settings'] ?? array(), $el['styles'] ?? array() ) ) {
				$out[] = $el;
				continue;
			}

			// Must have no layout-critical settings.
			if ( self::has_layout_settings( $el['settings'] ?? array() ) ) {
				$out[] = $el;
				continue;
			}

			// This container is pass-through — bypass it by splatting
			// all children into the parent. Fix 5 converts any resulting
			// e-flexbox-with-direct-widgets to e-div-block at depth ≥ 4.
			$fixes++;
			foreach ( $updated_children as $child ) {
				$out[] = $child;
			}
		}

		return $out;
	}

	/**
	 * Check if settings contain layout-critical keys that prevent flattening.
	 *
	 * @param array $settings Element settings.
	 * @return bool
	 */
	private static function has_layout_settings( array $settings ): bool {
		foreach ( self::LAYOUT_CRITICAL_KEYS as $key ) {
			if ( ! empty( $settings[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	// =====================================================================
	// Fix 5: Wrap direct widget children of e-flexbox in an e-div-block
	// =====================================================================

	/**
	 * Wrap all direct widget children of e-flexbox elements into a single
	 * e-div-block container. This fixes the structural layout issue where
	 * e-flexbox should not contain raw widgets as direct children.
	 *
	 * Depth-aware: if wrapping would push children beyond MAX_NESTING_DEPTH,
	 * the wrap is skipped to avoid creating excessive nesting. The e-flexbox
	 * direct-widget audit error is less harmful than cascading depth violations.
	 *
	 * Bottom-up: processes nested children before wrapping the parent.
	 */
	private static function fix_flexbox_widget_children( array $elements, int &$fixes, int $depth = 1 ): array {
		$out = array();

		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				$out[] = $el;
				continue;
			}

			$el_type  = $el['elType'] ?? '';
			$children = $el['elements'] ?? array();

			// Recurse first (bottom-up).
			if ( ! empty( $children ) ) {
				$el['elements'] = self::fix_flexbox_widget_children( $children, $fixes, $depth + 1 );
			}

			// After recursion, check if this e-flexbox now has only widget children.
			if ( 'e-flexbox' !== $el_type ) {
				$out[] = $el;
				continue;
			}

			$updated_children = $el['elements'] ?? array();
			if ( empty( $updated_children ) ) {
				$out[] = $el;
				continue;
			}

			// Check if all children are widgets.
			$all_widgets = true;
			foreach ( $updated_children as $child ) {
				if ( ! is_array( $child ) || 'widget' !== ( $child['elType'] ?? '' ) ) {
					$all_widgets = false;
					break;
				}
			}

			if ( ! $all_widgets ) {
				$out[] = $el;
				continue;
			}

			// Depth guard: if wrapping would push children beyond MAX_NESTING_DEPTH,
			// the wrap is skipped. Instead, convert the e-flexbox to e-div-block
			// if it has no flex layout settings, since e-div-block tolerates direct
			// widget children and the conversion adds no nesting depth.
			if ( ( $depth + 2 ) > self::MAX_NESTING_DEPTH ) {
				if ( self::has_layout_settings( $el['settings'] ?? array() ) ) {
					// Has flex layout settings — can't safely convert. Leave as-is.
					$out[] = $el;
				} else {
					// Safe: no flex settings. Convert elType to e-div-block.
					$el['elType'] = 'e-div-block';
					$fixes++;
					$out[] = $el;
				}
				continue;
			}

			// Wrap all widget children in a single e-div-block.
			$wrapper = array(
				'id'              => 'aw-' . $el['id'],
				'elType'          => 'e-div-block',
				'settings'        => array(
					'classes' => array(
						'$$type' => 'classes',
						'value'  => array(),
					),
				),
				'elements'        => array_values( $updated_children ),
				'styles'          => array(),
				'interactions'    => array(),
				'editor_settings' => array(),
				'version'         => '0.0',
			);

			$el['elements'] = array( $wrapper );
			$fixes++;
			$out[] = $el;
		}

		return $out;
	}

	// =====================================================================
	// Fix 6: Remove empty content widgets (bottom-up)
	// =====================================================================

	/**
	 * Remove widgets that have no content: e-heading without title,
	 * e-paragraph/e-button without text.
	 *
	 * Bottom-up: children are processed first so a container whose only
	 * child was an empty widget can be cleaned up by fix_empty_containers.
	 */
	private static function fix_empty_content_widgets( array $elements, int &$fixes ): array {
		$out = array();

		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				$out[] = $el;
				continue;
			}

			$el_type  = $el['elType'] ?? '';
			$children = $el['elements'] ?? array();

			// Recurse first (bottom-up).
			if ( ! empty( $children ) ) {
				$el['elements'] = self::fix_empty_content_widgets( $children, $fixes );
			}

			// Check if this is an empty content widget.
			if ( 'widget' === $el_type && self::is_empty_content_widget( $el ) ) {
				$fixes++;
				continue; // Remove this element.
			}

			$out[] = $el;
		}

		return array_values( $out );
	}

	/**
	 * Check if a widget element has no content.
	 */
	private static function is_empty_content_widget( array $el ): bool {
		$wt = $el['widgetType'] ?? '';
		if ( ! isset( self::CONTENT_WIDGETS[ $wt ] ) ) {
			return false;
		}

		$content_key = self::CONTENT_WIDGETS[ $wt ];
		$settings    = $el['settings'] ?? array();
		$content     = self::setting_to_string( $settings[ $content_key ] ?? '' );

		return '' === $content || null === $content;
	}

	// =====================================================================
	// Fix 7: Remove broken image widgets (bottom-up)
	// =====================================================================

	/**
	 * Remove e-image widgets that have no image source (id or url).
	 *
	 * Bottom-up: children processed first.
	 */
	private static function fix_broken_image_widgets( array $elements, int &$fixes ): array {
		$out = array();

		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				$out[] = $el;
				continue;
			}

			$el_type  = $el['elType'] ?? '';
			$children = $el['elements'] ?? array();

			// Recurse first (bottom-up).
			if ( ! empty( $children ) ) {
				$el['elements'] = self::fix_broken_image_widgets( $children, $fixes );
			}

			// Check if this is a broken image widget.
			if ( 'widget' === $el_type && 'e-image' === ( $el['widgetType'] ?? '' ) ) {
				$img = $el['settings']['image'] ?? array();
				if ( ! self::image_has_source( $img ) ) {
					$fixes++;
					continue; // Remove this element.
				}
			}

			$out[] = $el;
		}

		return array_values( $out );
	}

	// =====================================================================
	// Fix 8: Remove dangling class references
	// =====================================================================

	/**
	 * Remove class IDs from settings.classes.value that reference a style
	 * class never defined in any element's styles map.
	 *
	 * Global two-pass: collect all defined class IDs, then filter refs.
	 */
	private static function fix_dangling_class_refs( array $tree, int &$fixes ): array {
		// Step 1: Collect all defined class IDs across the tree.
		$defined = array();
		self::collect_defined_class_ids( $tree, $defined );

		if ( empty( $defined ) ) {
			return $tree;
		}

		// Step 2: Walk tree, remove dangling refs from settings.classes.value.
		return self::remove_dangling_refs_walk( $tree, $defined, $fixes );
	}

	private static function collect_defined_class_ids( array $elements, array &$defined ): void {
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}

			$styles = $el['styles'] ?? array();
			foreach ( $styles as $class_id => $style_def ) {
				$defined[ $class_id ] = true;
			}

			$children = $el['elements'] ?? array();
			if ( ! empty( $children ) ) {
				self::collect_defined_class_ids( $children, $defined );
			}
		}
	}

	private static function remove_dangling_refs_walk( array $elements, array $defined, int &$fixes ): array {
		foreach ( $elements as &$el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}

			$classes = $el['settings']['classes']['value'] ?? array();
			if ( is_array( $classes ) && ! empty( $classes ) ) {
				$filtered = array();
				foreach ( $classes as $cid ) {
					if ( self::is_external_class_ref( (string) $cid ) ) {
						$filtered[] = $cid;
						continue;
					}
					if ( isset( $defined[ $cid ] ) ) {
						$filtered[] = $cid;
					} else {
						$fixes++;
					}
				}
				if ( count( $filtered ) !== count( $classes ) ) {
					$el['settings']['classes']['value'] = array_values( $filtered );
					// If $$type is missing, ensure it.
					if ( ! isset( $el['settings']['classes']['$$type'] ) ) {
						$el['settings']['classes']['$$type'] = 'classes';
					}
				}
			}

			$children = $el['elements'] ?? array();
			if ( ! empty( $children ) ) {
				$el['elements'] = self::remove_dangling_refs_walk( $children, $defined, $fixes );
			}
		}
		unset( $el );

		return $elements;
	}

	// =====================================================================
	// Fix 9: Deduplicate style definitions
	// =====================================================================

	/**
	 * Remove duplicate style class definitions (same class_id on multiple
	 * elements). Keeps the first occurrence found during tree walk.
	 */
	private static function fix_duplicate_styles( array $tree, int &$fixes ): array {
		$seen = array();
		return self::deduplicate_styles_walk( $tree, $seen, $fixes );
	}

	private static function deduplicate_styles_walk( array $elements, array &$seen, int &$fixes ): array {
		foreach ( $elements as &$el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}

			$styles = $el['styles'] ?? array();
			if ( is_array( $styles ) && ! empty( $styles ) ) {
				foreach ( $styles as $class_id => $style_def ) {
					if ( isset( $seen[ $class_id ] ) ) {
						// Duplicate — remove from this element, keep first occurrence.
						unset( $el['styles'][ $class_id ] );
						$fixes++;
					} else {
						$seen[ $class_id ] = true;
					}
				}
			}

			$children = $el['elements'] ?? array();
			if ( ! empty( $children ) ) {
				$el['elements'] = self::deduplicate_styles_walk( $children, $seen, $fixes );
			}
		}
		unset( $el );

		return $elements;
	}

	// =====================================================================
	// Public: Kit-level style fix
	// =====================================================================

	/**
	 * Fix responsive variants for style classes defined in the Elementor Kit
	 * that are referenced by the page but not defined in the page tree.
	 *
	 * Call this AFTER run() — it does not modify the page tree. Returns
	 * the modified Kit element tree for the caller to persist, or null if
	 * no changes were made.
	 *
	 * @param array $page_tree Converted V4 page tree.
	 * @param int   &$fixes    Output: number of fixes applied to Kit styles.
	 * @return array|null Modified Kit element tree, or null if no changes.
	 */
	public static function fix_kit_styles_for_page( array $page_tree, int &$fixes = 0 ): ?array {
		$fixes = 0;

		// Step 1: Collect all class IDs referenced by page elements.
		$referenced = array();
		self::collect_referenced_class_ids( $page_tree, $referenced );
		if ( empty( $referenced ) ) {
			return null;
		}

		// Step 2: Collect all class IDs defined in page tree styles maps.
		$defined_in_page = array();
		self::collect_defined_class_ids( $page_tree, $defined_in_page );

		// Step 3: Classes referenced but not defined in page → may be Kit-defined.
		$missing = array_diff_key( $referenced, $defined_in_page );
		if ( empty( $missing ) ) {
			return null;
		}

		// Step 4: Load Kit element tree.
		$kit_tree = self::load_kit_tree();
		if ( null === $kit_tree ) {
			return null;
		}

		// Step 5: Walk Kit tree, find missing class definitions, fix them.
		$changed = false;
		self::fix_kit_styles_walk( $kit_tree, $missing, $fixes, $changed );

		if ( ! $changed ) {
			return null;
		}

		return $kit_tree;
	}

	/**
	 * Load the active Kit's element tree, with static caching.
	 *
	 * @return array|null Kit elements array, or null if unavailable.
	 */
	private static function load_kit_tree(): ?array {
		$kit_id = (int) get_option( 'elementor_active_kit' );
		if ( $kit_id <= 0 ) {
			return null;
		}

		// Return cached tree if same kit.
		if ( self::$kit_tree_cache_id === $kit_id && null !== self::$kit_tree_cache ) {
			return self::$kit_tree_cache;
		}

		$raw = get_post_meta( $kit_id, '_elementor_data', true );
		if ( empty( $raw ) ) {
			self::$kit_tree_cache_id = $kit_id;
			self::$kit_tree_cache    = null;
			return null;
		}

		$tree = is_array( $raw ) ? $raw : json_decode( $raw, true );
		if ( ! is_array( $tree ) ) {
			self::$kit_tree_cache_id = $kit_id;
			self::$kit_tree_cache    = null;
			return null;
		}

		self::$kit_tree_cache_id = $kit_id;
		self::$kit_tree_cache    = $tree;
		return $tree;
	}

	/**
	 * Recursively walk the Kit tree, finding style definitions for missing
	 * class IDs and applying responsive fixes.
	 *
	 * @param array    &$elements Kit element array (modified in place).
	 * @param string[]  $missing  Class IDs to find and fix.
	 * @param int      &$fixes    Fix counter.
	 * @param bool     &$changed  Set to true if any modification was made.
	 */
	private static function fix_kit_styles_walk( array &$elements, array $missing, int &$fixes, bool &$changed ): void {
		foreach ( $elements as &$el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}

			if ( isset( $el['styles'] ) && is_array( $el['styles'] ) ) {
				$styles = &$el['styles'];
				// Snapshot keys to avoid PHP iterator skip from COW.
				$class_ids = array_keys( $styles );
				foreach ( $class_ids as $class_id ) {
					if ( ! isset( $missing[ $class_id ] ) ) {
						continue;
					}

					$style_def = &$styles[ $class_id ];

					$before = $fixes;
					self::process_style_variants( $style_def, $fixes );
					if ( $fixes > $before ) {
						$changed = true;
					}

					$before = $fixes;
					self::clean_mobile_overrides( $style_def, $fixes );
					if ( $fixes > $before ) {
						$changed = true;
					}
				}
				unset( $style_def );
			}

			$children = $el['elements'] ?? array();
			if ( ! empty( $children ) ) {
				self::fix_kit_styles_walk( $children, $missing, $fixes, $changed );
				$el['elements'] = $children;
			}
		}
		unset( $el );
	}

	// =====================================================================
	// Helpers
	// =====================================================================

	/**
	 * Check if a container has visual styling that justifies its existence.
	 */
	private static function has_visual_styling( array $settings, array $styles ): bool {
		$visual_keys = array( 'padding', 'margin', 'background_color', 'border_border',
			'border_width', 'border_color', 'border_radius', 'box_shadow_box_shadow_type',
			'gap', 'content_width', 'width', 'height', 'min_height' );
		foreach ( $visual_keys as $key ) {
			if ( ! empty( $settings[ $key ] ) ) {
				return true;
			}
		}

		$classes = $settings['classes']['value'] ?? array();
		foreach ( $classes as $cid ) {
			if ( isset( $styles[ $cid ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Convert scalar and typed V4 content settings to text.
	 *
	 * @param mixed $setting Raw Elementor setting.
	 * @return string
	 */
	private static function setting_to_string( $setting ): string {
		if ( is_array( $setting ) && isset( $setting['$$type'] ) ) {
			$setting = V4_Props::unwrap( $setting );
		}
		return is_scalar( $setting ) ? trim( (string) $setting ) : '';
	}

	/**
	 * Detect image sources in both raw and typed V4 image props.
	 *
	 * @param mixed $image Raw Elementor image setting.
	 * @return bool
	 */
	private static function image_has_source( $image ): bool {
		if ( is_array( $image ) && isset( $image['$$type'] ) ) {
			$image = V4_Props::unwrap( $image );
		}
		if ( ! is_array( $image ) ) {
			return false;
		}
		if ( ! empty( $image['id'] ) || ! empty( $image['url'] ) ) {
			return true;
		}
		$src = $image['src'] ?? array();
		if ( is_array( $src ) && isset( $src['$$type'] ) ) {
			$src = V4_Props::unwrap( $src );
		}
		return is_array( $src ) && ( ! empty( $src['id'] ) || ! empty( $src['url'] ) );
	}

	/**
	 * Global classes live in the Kit, not in the page-local styles map.
	 */
	private static function is_external_class_ref( string $class_id ): bool {
		return str_starts_with( $class_id, 'gc-' );
	}
	// =====================================================================
	// Fix 8: Convert equal-width N-column flex rows to CSS Grid
	// =====================================================================

	/**
	 * Walk the V4 tree and replace equal-width flex-row containers
	 * with CSS Grid when all N children (N=2,3,4) are unstyled column
	 * wrappers. Reduces nesting depth: children no longer need width props.
	 *
	 * Heuristic: "equal-width" means all children either
	 *   (a) have no explicit width style prop, or
	 *   (b) all share the same explicit width (e.g. all 50%, all 33.33%).
	 *
	 * Adds custom_css: display:grid; grid-template-columns: repeat(N, 1fr)
	 * to the parent, and removes width from children's style variants.
	 *
	 * @param array $tree   V4 element tree.
	 * @param int   &$fixes Fix counter.
	 * @return array
	 */
	private static function fix_grid_candidates( array $tree, int &$fixes ): array {
		foreach ( $tree as &$el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}

			$children = $el['elements'] ?? array();
			// Recurse first (bottom-up).
			if ( ! empty( $children ) ) {
				$el['elements'] = self::fix_grid_candidates( $children, $fixes );
			}

			// Only transform e-flexbox containers (not e-div-block or widgets).
			if ( 'e-flexbox' !== ( $el['elType'] ?? '' ) ) {
				continue;
			}

			$el_children = $el['elements'] ?? array();
			$n           = count( $el_children );

			// Only 2-, 3-, or 4-column layouts.
			if ( $n < 2 || $n > 4 ) {
				continue;
			}

			// All children must be containers (not widgets).
			foreach ( $el_children as $child ) {
				if ( ! in_array( $child['elType'] ?? '', array( 'e-flexbox', 'e-div-block' ), true ) ) {
					continue 2;
				}
			}

			// Parent must have row direction (or default, which is row).
			$parent_flex_dir = self::get_style_prop( $el, 'flex-direction' );
			if ( null !== $parent_flex_dir && 'row' !== $parent_flex_dir ) {
				continue;
			}

			// Check if children are equal-width (all same width or all no width).
			$widths = array();
			foreach ( $el_children as $child ) {
				$w = self::get_style_prop( $child, 'width' );
				$widths[] = $w ?? '__none__';
			}
			$unique_widths = array_unique( $widths );

			// All equal OR all have no width → grid candidate.
			if ( count( $unique_widths ) !== 1 ) {
				continue;
			}

			// Apply: add custom_css to parent, remove width from children.
			$grid_css = sprintf( 'display:grid;grid-template-columns:repeat(%d,1fr);', $n );
			$el       = self::apply_custom_css_to_element( $el, $grid_css );

			// Remove explicit width from children (grid tracks control it).
			if ( '__none__' !== $widths[0] ) {
				foreach ( $el['elements'] as &$child ) {
					$child = self::remove_style_prop( $child, 'width' );
				}
				unset( $child );
			}

			$fixes++;
		}
		unset( $el );

		return $tree;
	}

	/**
	 * Get the first desktop-variant value for a given CSS prop from an element's styles.
	 *
	 * @param array  $el   V4 element.
	 * @param string $prop CSS property name (e.g. 'width', 'flex-direction').
	 * @return string|null Raw value string, or null if not set.
	 */
	private static function get_style_prop( array $el, string $prop ): ?string {
		foreach ( $el['styles'] ?? array() as $style_def ) {
			foreach ( $style_def['variants'] ?? array() as $variant ) {
				if ( 'desktop' !== ( $variant['meta']['breakpoint'] ?? '' ) ) {
					continue;
				}
				$props = $variant['props'] ?? array();
				if ( ! isset( $props[ $prop ] ) ) {
					continue;
				}
				$def = $props[ $prop ];
				// Handle common $$type shapes.
				$type  = $def['$$type'] ?? '';
				$value = $def['value'] ?? null;
				if ( 'string' === $type ) {
					return (string) $value;
				}
				if ( 'size' === $type && is_array( $value ) ) {
					return (string) ( $value['size'] ?? '' ) . ( $value['unit'] ?? '' );
				}
				return (string) json_encode( $value );
			}
		}
		return null;
	}

	/**
	 * Apply a raw CSS string to the first desktop variant of the first style.
	 *
	 * If the element has no styles, creates a minimal style entry.
	 *
	 * @param array  $el  V4 element.
	 * @param string $css Raw CSS string (e.g. 'display:grid;grid-template-columns:repeat(3,1fr);').
	 * @return array Modified element.
	 */
	private static function apply_custom_css_to_element( array $el, string $css ): array {
		// If the element already has a style, attach to the first one's desktop variant.
		if ( ! empty( $el['styles'] ) ) {
			$first_key = array_key_first( $el['styles'] );
			foreach ( $el['styles'][ $first_key ]['variants'] as &$variant ) {
				if ( 'desktop' === ( $variant['meta']['breakpoint'] ?? '' ) ) {
					$existing = $variant['custom_css']['raw'] ?? '';
					$variant['custom_css'] = array(
						'raw' => trim( $existing . ' ' . 'selector{' . $css . '}' ),
					);
					return $el;
				}
			}
			unset( $variant );
			return $el;
		}

		// No styles: create a minimal one.
		$style_id             = $el['id'] . '-grid';
		$el['styles'][ $style_id ] = array(
			'id'       => $style_id,
			'type'     => 'class',
			'label'    => $style_id,
			'variants' => array(
				array(
					'meta'       => array( 'breakpoint' => 'desktop', 'state' => null ),
					'props'      => array(),
					'custom_css' => array( 'raw' => 'selector{' . $css . '}' ),
				),
			),
		);
		// Register in settings.classes (server usually does this, but be safe).
		$existing_classes = $el['settings']['classes']['value'] ?? array();
		if ( ! in_array( $style_id, $existing_classes, true ) ) {
			$el['settings']['classes'] = array( '$$type' => 'classes', 'value' => array_merge( $existing_classes, array( $style_id ) ) );
		}
		return $el;
	}

	/**
	 * Remove a CSS prop from all variants in all styles of an element.
	 *
	 * @param array  $el   V4 element.
	 * @param string $prop CSS prop to remove (e.g. 'width').
	 * @return array Modified element.
	 */
	private static function remove_style_prop( array $el, string $prop ): array {
		foreach ( $el['styles'] as &$style_def ) {
			foreach ( $style_def['variants'] as &$variant ) {
				unset( $variant['props'][ $prop ] );
			}
			unset( $variant );
		}
		unset( $style_def );
		return $el;
	}


}
