<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Conversion_Auditor — runs post-conversion audits on a V4 Atomic element tree.
 *
 * Three audit types:
 *  1. Layout Audit  — structural integrity (empty containers, missing content, nesting)
 *  2. Class Audit    — style class integrity (orphans, dangling refs, duplicates)
 *  3. Responsive Audit — breakpoint completeness (missing mobile variants, redundant overrides)
 *
 * Two-pass execution: one recursive tree walk collects layout issues and
 * flat style/class info, then class + responsive audits analyse the collections.
 *
 * Each issue: { type, severity, element_id, message }
 *
 * @package Novamira_AdrianV2
 * @since   1.3.0
 */
final class Conversion_Auditor {

	// ── Widget types that carry text content ──
	private const CONTENT_WIDGETS = array(
		'e-heading'   => 'title',
		'e-paragraph' => 'paragraph',
		'e-button'    => 'text',
	);

	// ── Container-like elTypes (can hold children) ──
	private const CONTAINER_TYPES = array( 'e-flexbox', 'e-div-block' );

	// ── CSS properties that commonly need responsive overrides ──
	private const RESPONSIVE_IMPORTANT_PROPS = array(
		'font-size', 'padding-block-start', 'padding-block-end',
		'padding-inline-start', 'padding-inline-end',
		'margin-block-start', 'margin-block-end',
		'margin-inline-start', 'margin-inline-end',
		'width', 'max-width', 'gap',
	);

	/**
	 * Run all audits on a converted V4 tree.
	 *
	 * @param array $tree Converted V4 element tree.
	 * @return array[] List of audit issues: {type, severity, element_id, message}.
	 */
	public static function audit( array $tree ): array {
		$issues = array();

		// ── Pass 1: recursive tree walk ──
		$ctx = array(
			'defined_styles'       => array(), // [class_id => element_id]
			'referenced_class_ids' => array(), // [class_id => count]
			'all_class_refs'       => array(), // [element_id => class_id[]]
			'max_depth'            => 0,
		);
		self::walk( $tree, 1, $issues, $ctx );

		// ── Collect full style definitions for cross-element responsive audit ──
		$all_styles = self::deep_collect_styles( $tree );

		// ── Pass 2: cross-element analysis ──
		self::class_audit_pass2( $ctx, $issues );
		self::responsive_audit_pass2( $ctx, $issues, $all_styles );

		return $issues;
	}

	// =====================================================================
	// Pass 1: Recursive Tree Walker
	// =====================================================================

	/**
	 * Recursively walk the V4 tree, collecting layout issues and style/class info.
	 *
	 * @param array  $elements List of V4 elements at current level.
	 * @param int    $depth    Current nesting depth (1-indexed).
	 * @param array  &$issues  Accumulated audit issues.
	 * @param array  &$ctx     Mutable context (defined_styles, referenced_class_ids, all_class_refs, max_depth).
	 */
	private static function walk(
		array $elements,
		int $depth,
		array &$issues,
		array &$ctx
	): void {
		if ( $depth > $ctx['max_depth'] ) {
			$ctx['max_depth'] = $depth;
		}

		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}

			$el_id   = $el['id'] ?? '?';
			$el_type = $el['elType'] ?? '?';
			$wt      = $el['widgetType'] ?? null;
			$settings = $el['settings'] ?? array();
			$children = $el['elements'] ?? array();
			$styles   = $el['styles'] ?? array();

			// ── Collect style definitions ──
			foreach ( $styles as $class_id => $style_def ) {
				// Track duplicates.
				if ( isset( $ctx['defined_styles'][ $class_id ] ) ) {
					$issues[] = array(
						'type'       => 'class',
						'severity'   => 'error',
						'element_id' => $el_id,
						'message'    => "Style class '$class_id' is defined multiple times (also on element '{$ctx['defined_styles'][$class_id]}').",
					);
				} else {
					$ctx['defined_styles'][ $class_id ] = $el_id;
				}
			}

			// ── Collect class references from settings ──
			$refs = self::extract_class_refs( $settings );
			$ctx['all_class_refs'][ $el_id ] = $refs;
			foreach ( $refs as $cid ) {
				$ctx['referenced_class_ids'][ $cid ] = ( $ctx['referenced_class_ids'][ $cid ] ?? 0 ) + 1;
			}

			// ── Layout: excessive nesting depth ──
			if ( $depth > 5 ) {
				$issues[] = array(
					'type'       => 'layout',
					'severity'   => 'info',
					'element_id' => $el_id,
					'message'    => "Element is nested at depth $depth. Consider flattening the structure.",
				);
			}

			// ── Layout: section with direct widget children (not in columns) ──
			if ( 'e-flexbox' === $el_type && ! empty( $children ) ) {
				$has_only_widgets = true;
				foreach ( $children as $child ) {
					if ( is_array( $child ) && ! in_array( $child['elType'] ?? '', array( 'widget' ), true ) ) {
						$has_only_widgets = false;
						break;
					}
				}
				if ( $has_only_widgets ) {
					$issues[] = array(
						'type'       => 'layout',
						'severity'   => 'error',
						'element_id' => $el_id,
						'message'    => 'e-flexbox contains direct widget children without intermediate container (e-div-block).',
					);
				}
			}

			// ── Layout: empty container (no children, no structural purpose) ──
			if ( in_array( $el_type, self::CONTAINER_TYPES, true ) && empty( $children ) ) {
				// Check if it has any visual styling that justifies its existence.
				$has_visual = self::has_visual_styling( $settings, $styles );
				if ( ! $has_visual ) {
					$issues[] = array(
						'type'       => 'layout',
						'severity'   => 'warning',
						'element_id' => $el_id,
						'message'    => "Empty '$el_type' container with no children and no visual styling — may be unnecessary.",
					);
				}
			}

			// ── Layout: widget missing required content ──
			if ( 'widget' === $el_type && $wt && isset( self::CONTENT_WIDGETS[ $wt ] ) ) {
				$content_key = self::CONTENT_WIDGETS[ $wt ];
				$content     = self::setting_to_string( $settings[ $content_key ] ?? '' );
				if ( '' === $content || null === $content ) {
					$issues[] = array(
						'type'       => 'layout',
						'severity'   => 'warning',
						'element_id' => $el_id,
						'message'    => "Widget '$wt' has no '$content_key' content — it will render empty.",
					);
				}
			}

			// ── Layout: image widget without image source ──
			if ( 'widget' === $el_type && 'e-image' === $wt ) {
				$img = $settings['image'] ?? array();
				if ( ! self::image_has_source( $img ) ) {
					$issues[] = array(
						'type'       => 'layout',
						'severity'   => 'error',
						'element_id' => $el_id,
						'message'    => "Widget 'e-image' has no image source (id or url) — will render broken.",
					);
				}
			}

			// ── Recurse ──
			if ( ! empty( $children ) ) {
				self::walk( $children, $depth + 1, $issues, $ctx );
			}
		}
	}

	// =====================================================================
	// Pass 2: Class Audit (cross-element analysis)
	// =====================================================================

	/**
	 * Analyse collected style/class info for integrity issues.
	 */
	private static function class_audit_pass2( array $ctx, array &$issues ): void {
		$defined    = $ctx['defined_styles'];
		$referenced = $ctx['referenced_class_ids'];
		$all_refs   = $ctx['all_class_refs'];

		// ── Dangling references: class used in settings but not defined in any styles map ──
		foreach ( $referenced as $class_id => $count ) {
			if ( self::is_external_class_ref( (string) $class_id ) ) {
				continue;
			}
			if ( ! isset( $defined[ $class_id ] ) ) {
				// Find which element(s) reference it.
				$referrers = array();
				foreach ( $all_refs as $el_id => $refs ) {
					if ( in_array( $class_id, $refs, true ) ) {
						$referrers[] = $el_id;
					}
				}
				$ref_str = implode( ', ', array_slice( $referrers, 0, 3 ) );
				if ( count( $referrers ) > 3 ) {
					$ref_str .= '…';
				}
				$issues[] = array(
					'type'       => 'class',
					'severity'   => 'error',
					'element_id' => $referrers[0] ?? '?',
					'message'    => "Class '$class_id' is referenced $count× (by $ref_str) but is not defined in any styles map.",
				);
			}
		}

		// ── Orphan styles: defined in styles map but never referenced ──
		foreach ( $defined as $class_id => $el_id ) {
			if ( ! isset( $referenced[ $class_id ] ) ) {
				$issues[] = array(
					'type'       => 'class',
					'severity'   => 'warning',
					'element_id' => $el_id,
					'message'    => "Style class '$class_id' is defined but never referenced by any element — orphan style.",
				);
			}
		}
	}

	// =====================================================================
	// Pass 2: Responsive Audit (breakpoint completeness)
	// =====================================================================

	/**
	 * Analyse collected styles for responsive completeness.
	 *
	 * Note: the ctx only has class_id → element_id mapping. The caller must
	 * provide the full styles map via $all_styles (from deep_collect_styles).
	 */
	private static function responsive_audit_pass2( array $ctx, array &$issues, array $all_styles = array() ): void {
		if ( empty( $all_styles ) ) {
			return;
		}

		foreach ( $all_styles as $class_id => $info ) {
			$variants = $info['variants'];
			$el_id    = $info['element_id'];

			if ( empty( $variants ) ) {
				continue;
			}

			// Find desktop variant.
			$desktop = null;
			$tablet  = null;
			$mobile  = null;
			foreach ( $variants as $v ) {
				$bp = $v['meta']['breakpoint'] ?? '';
				if ( 'desktop' === $bp ) {
					$desktop = $v;
				} elseif ( 'tablet' === $bp ) {
					$tablet = $v;
				} elseif ( 'mobile' === $bp ) {
					$mobile = $v;
				}
			}

			$desktop_props = $desktop['props'] ?? array();

			// ── Desktop-only (no mobile variant) with important props ──
			if ( ! empty( $desktop_props ) && null === $mobile ) {
				$important_missing = array();
				foreach ( self::RESPONSIVE_IMPORTANT_PROPS as $prop ) {
					if ( isset( $desktop_props[ $prop ] ) ) {
						$important_missing[] = $prop;
					}
				}
				if ( ! empty( $important_missing ) ) {
					$prop_list = implode( ', ', array_slice( $important_missing, 0, 4 ) );
					$issues[] = array(
						'type'       => 'responsive',
						'severity'   => 'warning',
						'element_id' => $el_id,
						'message'    => "Style class '$class_id' has desktop $prop_list but no mobile breakpoint variant — may not look right on small screens.",
					);
				}
			}

			// ── Identical mobile overrides (waste) ──
			if ( null !== $desktop && null !== $mobile ) {
				$identical_props = array();
				foreach ( $mobile['props'] as $prop => $value ) {
					if ( isset( $desktop_props[ $prop ] ) && $desktop_props[ $prop ] === $value ) {
						$identical_props[] = $prop;
					}
				}
				if ( ! empty( $identical_props ) ) {
					$prop_list = implode( ', ', array_slice( $identical_props, 0, 3 ) );
					$issues[] = array(
						'type'       => 'responsive',
						'severity'   => 'info',
						'element_id' => $el_id,
						'message'    => "Style class '$class_id' mobile variant has identical values to desktop for: $prop_list — override is redundant.",
					);
				}
			}

			// ── Fixed px width without responsive alternative ──
			if ( isset( $desktop_props['width'] ) ) {
				$w = $desktop_props['width'];
				$unit = $w['value']['unit'] ?? '';
				$size = $w['value']['size'] ?? 0;
				if ( 'px' === $unit && $size > 300 ) {
					$has_responsive_width = ( null !== $mobile && isset( $mobile['props']['width'] ) )
						|| ( null !== $tablet && isset( $tablet['props']['width'] ) );
					if ( ! $has_responsive_width ) {
						$issues[] = array(
							'type'       => 'responsive',
							'severity'   => 'warning',
							'element_id' => $el_id,
							'message'    => "Style class '$class_id' has fixed width ${size}px with no responsive alternative — may overflow on small screens.",
						);
					}
				}
			}
		}
	}

	// =====================================================================
	// Helpers
	// =====================================================================

	/**
	 * Extract class ID references from an element's settings.
	 *
	 * @param array $settings Element settings.
	 * @return string[] Class IDs.
	 */
	private static function extract_class_refs( array $settings ): array {
		$classes = $settings['classes'] ?? null;
		if ( ! is_array( $classes ) ) {
			return array();
		}
		$value = $classes['value'] ?? array();
		return is_array( $value ) ? $value : array();
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
	 * Global classes live outside the page tree and should not be audited as
	 * dangling local style classes.
	 */
	private static function is_external_class_ref( string $class_id ): bool {
		return str_starts_with( $class_id, 'gc-' );
	}

	/**
	 * Check if a container has visual styling that justifies its existence.
	 *
	 * Note: Only checks the element's own inline settings and local style classes.
	 * Cross-element class references (global classes defined elsewhere) are not
	 * resolved. This may produce false-positive "empty container" warnings for
	 * containers that reference global design-system classes.
	 */
	private static function has_visual_styling( array $settings, array $styles ): bool {
		// Check inline settings for visual properties.
		$visual_keys = array( 'padding', 'margin', 'background_color', 'border_border',
			'border_width', 'border_color', 'border_radius', 'box_shadow_box_shadow_type',
			'gap', 'content_width', 'width', 'height', 'min_height' );
		foreach ( $visual_keys as $key ) {
			if ( ! empty( $settings[ $key ] ) ) {
				return true;
			}
		}

		// Check if there are style classes that provide visual properties.
		$refs = self::extract_class_refs( $settings );
		foreach ( $refs as $cid ) {
			if ( isset( $styles[ $cid ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Deep-collect styles from the entire tree with full variant data.
	 *
	 * Must be called with the original tree, not the ctx.
	 *
	 * @param array $tree Converted V4 element tree.
	 * @return array [class_id => {element_id, variants[]}]
	 */
	public static function deep_collect_styles( array $tree ): array {
		$out = array();
		self::deep_collect_styles_walk( $tree, $out );
		return $out;
	}

	private static function deep_collect_styles_walk( array $elements, array &$out ): void {
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$el_id  = $el['id'] ?? '?';
			$styles = $el['styles'] ?? array();
			foreach ( $styles as $class_id => $style_def ) {
				// Skip duplicates — first occurrence wins (already flagged in Pass 1).
				if ( isset( $out[ $class_id ] ) ) {
					continue;
				}
				$out[ $class_id ] = array(
					'element_id' => $el_id,
					'variants'   => $style_def['variants'] ?? array(),
				);
			}
			$children = $el['elements'] ?? array();
			if ( ! empty( $children ) ) {
				self::deep_collect_styles_walk( $children, $out );
			}
		}
	}

	/**
	 * Filter audit issues by type and/or severity.
	 *
	 * @param array[]     $issues   Full audit issues array.
	 * @param string|null $type     Filter by type ('layout', 'class', 'responsive') or null.
	 * @param string|null $severity Filter by severity ('error', 'warning', 'info') or null.
	 * @return array[]
	 */
	public static function filter( array $issues, ?string $type = null, ?string $severity = null ): array {
		return array_values( array_filter( $issues, function ( $issue ) use ( $type, $severity ) {
			if ( null !== $type && ( $issue['type'] ?? '' ) !== $type ) {
				return false;
			}
			if ( null !== $severity && ( $issue['severity'] ?? '' ) !== $severity ) {
				return false;
			}
			return true;
		} ) );
	}

	/**
	 * Extract audit messages as a simple string array for merging into
	 * the existing warnings pipeline. Includes both error and warning severity.
	 *
	 * @param array[] $issues Full audit issues array.
	 * @return string[]
	 */
	public static function to_warnings( array $issues ): array {
		$warnings = array();
		foreach ( $issues as $issue ) {
			$severity = $issue['severity'] ?? '';
			if ( 'error' === $severity || 'warning' === $severity ) {
				$warnings[] = sprintf(
					'[audit:%s:%s] %s (element %s)',
					$issue['type'],
					$severity,
					$issue['message'],
					$issue['element_id']
				);
			}
		}
		return $warnings;
	}
}
