<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * V4_Styles — local style class builder for atomic elements.
 *
 * Builds local style class structures for Elementor 4.0 atomic elements.
 * In v4, visual styling (flex layout, spacing, colors, typography) is stored
 * in a `styles` map on each element, referenced via class IDs in settings.
 *
 * Key behaviors:
 * - background-color / color: Detects e-gv-* Global Variable IDs and wraps
 *   them as global-color-variable $$type instead of plain string.
 * - All references to the legacy Atomic_Props helper are now V4_Props.
 *
 * @package Novamira_AdrianV2
 * @since   1.0.0
 */

namespace Novamira\AdrianV2\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds local style classes for atomic elements.
 *
 * @since 1.0.0
 */
final class V4_Styles {

    /**
     * Creates a local style class structure for an element.
     *
     * ID-Pattern: "e-" + element_id + "-" + 7 hex chars.
     * Invariant III: class_id DARF Hyphens haben (global, nicht lokal).
     *
     * @param string      $element_id The element's ID.
     * @param array       $props      CSS properties as $$type-wrapped values.
     * @param string      $breakpoint The responsive breakpoint (desktop, tablet, mobile).
     * @param string|null $state      The CSS state (null, hover, focus, active).
     * @return array{class_id: string, style_def: array} Ready to merge into element.
     */
    public static function create_local_class(
        string $element_id,
        array $props,
        string $breakpoint = 'desktop',
        ?string $state = null
    ): array {
        $class_id = 'e-' . $element_id . '-' . substr(bin2hex(random_bytes(4)), 0, 7);

        $style_def = [
            'id'    => $class_id,
            'label' => 'local',
            'type'  => 'class',
            'variants' => [
                [
                    'meta'       => [
                        'breakpoint' => $breakpoint,
                        'state'      => $state,
                    ],
                    'props'      => $props,
                    'custom_css' => null,
                ],
            ],
        ];

        return [
            'class_id'  => $class_id,
            'style_def' => $style_def,
        ];
    }

    /**
     * Builds flexbox layout style props from AI-friendly parameters.
     *
     * Accepts plain values and returns $$type-wrapped CSS properties
     * using CSS property names (kebab-case).
     *
     * @param array $params Flat layout parameters from AI agent input.
     * @return array CSS props in $$type format (e.g., flex-direction, justify-content).
     */
    public static function build_flex_props(array $params): array {
        $props = [];

        $string_mappings = [
            'direction'       => 'flex-direction',
            'flex_direction'  => 'flex-direction',
            'justify'         => 'justify-content',
            'justify_content' => 'justify-content',
            'align'           => 'align-items',
            'align_items'     => 'align-items',
            'wrap'            => 'flex-wrap',
            'flex_wrap'       => 'flex-wrap',
        ];

        foreach ($string_mappings as $input_key => $css_prop) {
            if (isset($params[$input_key]) && '' !== $params[$input_key]) {
                $props[$css_prop] = V4_Props::string((string) $params[$input_key]);
            }
        }

        if (isset($params['gap'])) {
            $unit = $params['gap_unit'] ?? 'px';
            $props['gap'] = V4_Props::size((float) $params['gap'], $unit);
        }

        if (isset($params['row_gap'])) {
            $unit = $params['row_gap_unit'] ?? 'px';
            $props['row-gap'] = V4_Props::size((float) $params['row_gap'], $unit);
        }

        if (isset($params['column_gap'])) {
            $unit = $params['column_gap_unit'] ?? 'px';
            $props['column-gap'] = V4_Props::size((float) $params['column_gap'], $unit);
        }

        return $props;
    }

    /**
     * Builds common style props (padding, margin, background, etc.) from AI input.
     *
     * Uses logical properties (padding-block-start, padding-inline-end, etc.)
     * for RTL-compatible spacing.
     *
     * GV-Fix: background-color and color values starting with 'e-gv-' are
     * wrapped as global-color-variable $$type, not plain string.
     *
     * @param array $params Flat style parameters.
     * @return array CSS props in $$type format.
     */
    public static function build_common_props(array $params): array {
        $props = [];

        $size_mappings = [
            'padding_top'    => 'padding-block-start',
            'padding_right'  => 'padding-inline-end',
            'padding_bottom' => 'padding-block-end',
            'padding_left'   => 'padding-inline-start',
            'margin_top'     => 'margin-block-start',
            'margin_bottom'  => 'margin-block-end',
            'width'          => 'width',
            'min_height'     => 'min-height',
            'border_radius'  => 'border-radius',
        ];

        foreach ($size_mappings as $input_key => $css_prop) {
            if (isset($params[$input_key])) {
                $unit = $params[$input_key . '_unit'] ?? 'px';
                $props[$css_prop] = V4_Props::size(
                    (float) $params[$input_key],
                    $unit
                );
            }
        }

        if (isset($params['padding'])) {
            $unit = $params['padding_unit'] ?? 'px';
            $size_val = V4_Props::size((float) $params['padding'], $unit);
            $props['padding-block-start']  = $size_val;
            $props['padding-block-end']    = $size_val;
            $props['padding-inline-start'] = $size_val;
            $props['padding-inline-end']   = $size_val;
        }

        if (isset($params['background_color'])) {
            $props['background-color'] = self::wrap_color_value($params['background_color']);
        }

        if (isset($params['color'])) {
            $props['color'] = self::wrap_color_value($params['color']);
        }

        return $props;
    }

    /**
     * Wraps a color value, detecting Global Variable (e-gv-*) references.
     *
     * V4-native GV references use $$type: "global-color-variable", not string.
     * Plain hex/rgba values are wrapped as $$type: "string" for the Style Schema.
     *
     * @param string $value The raw color value or e-gv-* GV ID.
     * @return array $$type-wrapped color prop.
     */
    public static function wrap_color_value(string $value): array {
        if (str_starts_with($value, 'e-gv-')) {
            return [
                '$$type' => 'global-color-variable',
                'value'  => $value,
            ];
        }
        return V4_Props::string($value);
    }

    /**
     * Applies a local style class to an element structure.
     *
     * Adds the class to settings.classes and the style definition to the styles map.
     *
     * @param array  $element   The element array (passed by reference).
     * @param string $class_id  The style class ID.
     * @param array  $style_def The style definition array.
     */
    public static function apply_to_element(array &$element, string $class_id, array $style_def): void {
        if (!isset($element['settings']['classes'])) {
            $element['settings']['classes'] = V4_Props::classes([]);
        }
        $element['settings']['classes']['value'][] = $class_id;

        if (!isset($element['styles'])) {
            $element['styles'] = [];
        }
        $element['styles'][$class_id] = $style_def;
    }
}
