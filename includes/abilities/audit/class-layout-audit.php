<?php
/**
 * Ability 37: Layout Audit
 *
 * Analysiert eine Elementor-Seite auf unnoetige Container-Verschachtelungen
 * und schlaegt konkrete Layout-Verbesserungen vor.
 *
 * Checks:
 * - deep_nesting:       Alle Container (e-flexbox, e-div-block, container) tiefer als max_depth
 * - single_child:       Container mit genau 1 Kind-Container und keinen eigenen Styles
 * - background_wrapper: Container der nur als Hintergrund-Layer existiert (hat bg-Style, sonst nichts) oder 0 Kinder hat
 * - grid_candidate:     Flexbox-Container mit >=2 direkten Container-Kindern in row-Richtung (2D-Layout -> Grid besser)
 * - passthrough:        Container ohne eigene Styles, Settings und mehr als 0 Kindern
 * - kicker_wrapper:     row-Flexbox mit SVG+Heading als separate Ebene (koennte auf Elternelement)
 */
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Audit;

if (!defined('ABSPATH')) {
    exit();
}

class Layout_Audit
{
    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/layout-audit', [
            'label'       => 'Layout Audit',
            'description' => 'Audits an Elementor V4 page for unnecessary container nesting and layout anti-patterns. Detects: containers nested deeper than max_depth (default 3), single-child wrapper containers without own styles, background-only wrapper elements (bg-layer pattern), flexbox containers suitable for CSS grid conversion (2D layout with multiple column children), pass-through containers without any styles or settings, and redundant kicker-row wrappers (SVG+Heading in a separate row-flex that could live on the parent). Returns element IDs, depth, and actionable fix suggestions for each issue. Run before and after V3->V4 conversion.',
            'category'    => 'novamira-adrianv2',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'ID of the Elementor page to audit.',
                    ],
                    'checks' => [
                        'type'        => 'array',
                        'description' => 'Which checks to run. Omit to run all.',
                        'items'       => [
                            'type' => 'string',
                            'enum' => [ 'deep_nesting', 'single_child', 'background_wrapper', 'grid_candidate', 'passthrough', 'kicker_wrapper' ],
                        ],
                    ],
                    'max_depth' => [
                        'type'        => 'integer',
                        'description' => 'Maximum allowed container nesting depth before flagging. Default: 3.',
                    ],
                ],
                'required' => [ 'post_id' ],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'          => [ 'type' => 'boolean' ],
                    'post_id'          => [ 'type' => 'integer' ],
                    'post_title'       => [ 'type' => 'string' ],
                    'total_issues'     => [ 'type' => 'integer' ],
                    'max_depth_found'  => [ 'type' => 'integer' ],
                    'container_count'  => [ 'type' => 'integer' ],
                    'by_check'         => [ 'type' => 'object' ],
                    'issues'           => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
                    'summary'          => [ 'type' => 'string' ],
                ],
            ],
            'execute_callback'    => [self::class, 'execute'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => [
                'show_in_rest' => true,
                'mcp'          => [ 'public' => true ],
                'annotations'  => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ]);
    }

    public static function execute($input = null)
    {
        $post_id   = (int) $input['post_id'];
        $checks    = $input['checks'] ?? [ 'deep_nesting', 'single_child', 'background_wrapper', 'grid_candidate', 'passthrough', 'kicker_wrapper' ];
        $max_depth = isset( $input['max_depth'] ) ? (int) $input['max_depth'] : 3;

        $post = get_post( $post_id );
        if ( ! $post ) {
            return [ 'success' => false, 'summary' => "Post {$post_id} not found." ];
        }

        $raw  = get_post_meta( $post_id, '_elementor_data', true );
        $data = json_decode( is_string( $raw ) ? $raw : '[]', true );
        if ( ! is_array( $data ) || empty( $data ) ) {
            return [ 'success' => false, 'summary' => 'No valid Elementor data found.' ];
        }

        $issues          = [];
        $container_count = 0;
        $max_depth_found = 0;

        // --- Helper functions ---

        // Every container type (V3 + V4)
        $CONTAINER_TYPES = [ 'e-flexbox', 'e-div-block', 'container' ];
        // Only real flexbox types (have flex-direction)
        $FLEX_TYPES = [ 'e-flexbox', 'container' ];

        $is_container = fn( $el ) => in_array( $el['elType'] ?? '', $CONTAINER_TYPES, true );
        $is_flex      = fn( $el ) => in_array( $el['elType'] ?? '', $FLEX_TYPES, true );
        $is_widget    = fn( $el ) => ( $el['elType'] ?? '' ) === 'widget';

        // Unwrap $$type or flat value
        $unwrap = function ( $val ) {
            if ( is_array( $val ) ) {
                if ( isset( $val['$$type'] ) ) return $val['value'] ?? null;
                if ( isset( $val['type'] ) )   return $val['value'] ?? null;
            }
            return $val;
        };

        // Read display prop from styles (desktop)
        $get_style_prop = function ( $el, string $prop ) use ( $unwrap ) {
            foreach ( $el['styles'] ?? [] as $style ) {
                foreach ( $style['variants'] ?? [] as $variant ) {
                    if ( ( $variant['meta']['breakpoint'] ?? 'desktop' ) !== 'desktop' ) continue;
                    $v = $variant['props'][ $prop ] ?? null;
                    if ( $v !== null ) return $unwrap( $v );
                }
            }
            return null;
        };

        // flex-direction: styles + V3-settings fallback
        $get_flex_dir = function ( $el ) use ( $get_style_prop, $unwrap ) {
            $from_style = $get_style_prop( $el, 'flex-direction' );
            if ( $from_style !== null ) return $from_style;
            // V3 setting
            $v3 = $el['settings']['flex_direction'] ?? null;
            return $v3 ? $unwrap( $v3 ) : null;
        };

        // Does the element have a background (color or image)?
        $has_bg = function ( $el ) use ( $get_style_prop ) {
            if ( $get_style_prop( $el, 'background' ) !== null ) return true;
            if ( $get_style_prop( $el, 'background-color' ) !== null ) return true;
            if ( ! empty( $el['settings']['background_color'] ) ) return true;
            if ( ! empty( $el['settings']['background_image']['url'] ) ) return true;
            return false;
        };

        // All style props of the element (all variants, desktop+rest)
        $get_all_style_props = function ( $el ) {
            $props = [];
            foreach ( $el['styles'] ?? [] as $style ) {
                foreach ( $style['variants'] ?? [] as $variant ) {
                    foreach ( array_keys( $variant['props'] ?? [] ) as $p ) {
                        $props[] = $p;
                    }
                    if ( ! empty( $variant['custom_css'] ) ) $props[] = '__custom_css';
                }
            }
            return array_unique( $props );
        };

        // Props that count as "trivial" (no semantic value on their own)
        $TRIVIAL_PROPS = [ 'display', 'flex-direction', 'flex-wrap' ];

        // Does the element have own, non-trivial styles?
        $has_meaningful_styles = function ( $el ) use ( $get_all_style_props, $TRIVIAL_PROPS ) {
            $props = array_diff( $get_all_style_props( $el ), $TRIVIAL_PROPS );
            if ( ! empty( $props ) ) return true;
            // V3 settings
            $meaningful_v3 = [
                'background_color', 'background_image', 'padding', 'margin',
                'border_border', 'box_shadow_box_shadow', 'min_height', 'height',
                'custom_css', '_element_width',
            ];
            foreach ( $meaningful_v3 as $key ) {
                if ( ! empty( $el['settings'][ $key ] ) ) return true;
            }
            return false;
        };

        // Does the element have ANY settings (not empty/null)?
        $has_any_settings = function ( $el ) {
            foreach ( $el['settings'] ?? [] as $v ) {
                if ( $v !== null && $v !== '' && $v !== [] ) return true;
            }
            return false;
        };

        // Short label for an element
        $el_label = function ( $el ) {
            $type = $el['widgetType'] ?? $el['elType'] ?? 'el';
            $id   = $el['id'] ?? '?';
            return "{$type}#{$id}";
        };

        // --- Recursive analysis pass ---

        $analyse = function (
            array $elements,
            int   $depth,
            array $ancestor_ids,
            ?array $parent_el
        ) use (
            &$analyse, &$issues, &$container_count, &$max_depth_found,
            $checks, $max_depth,
            $is_container, $is_flex, $is_widget,
            $get_style_prop, $get_flex_dir,
            $has_bg, $get_all_style_props,
            $has_meaningful_styles, $has_any_settings,
            $el_label, $unwrap, $TRIVIAL_PROPS
        ) {
            foreach ( $elements as $el ) {
                $el_id    = $el['id'] ?? '';
                $children = $el['elements'] ?? [];
                $n_kids   = count( $children );
                $is_cont  = $is_container( $el );
                $is_fl    = $is_flex( $el );

                if ( $is_cont ) {
                    $container_count++;
                    if ( $depth > $max_depth_found ) $max_depth_found = $depth;
                }

                // 1. DEEP NESTING: all container types
                if ( in_array( 'deep_nesting', $checks, true ) && $is_cont && $depth > $max_depth ) {
                    $issues[] = [
                        'severity'    => 'error',
                        'check'       => 'deep_nesting',
                        'element_id'  => $el_id,
                        'element'     => $el_label( $el ),
                        'depth'       => $depth,
                        'max_allowed' => $max_depth,
                        'ancestors'   => array_slice( $ancestor_ids, -4 ), // last 4 ancestors
                        'message'     => "Container on depth {$depth}, limit is {$max_depth}. Ancestor chain: " . implode( ' > ', array_slice( $ancestor_ids, -4 ) ) . " > {$el_id}",
                        'fix'        => 'Use CSS Grid (display:grid + grid-template-columns) on a higher container to eliminate intermediate levels.',
                    ];
                }

                // 2. SINGLE-CHILD WRAPPER: container with exactly 1 child-container, no own styles
                if ( in_array( 'single_child', $checks, true ) && $is_cont && $n_kids === 1 ) {
                    $only = $children[0];
                    if ( $is_container( $only ) && ! $has_meaningful_styles( $el ) && ! $has_any_settings( $el ) ) {
                        $issues[] = [
                            'severity'   => 'warning',
                            'check'      => 'single_child',
                            'element_id' => $el_id,
                            'element'    => $el_label( $el ),
                            'child_id'   => $only['id'],
                            'child_type' => $only['elType'] ?? '?',
                            'message'    => 'Container with exactly 1 child-container and no own styles -- superfluous wrapper level.',
                            'fix'        => "Remove element #{$el_id}, move child #{$only['id']} directly into the parent element.",
                        ];
                    }
                }

                // 3. BACKGROUND WRAPPER: container with 0 children OR only bg-props
                if ( in_array( 'background_wrapper', $checks, true ) && $is_cont ) {
                    $all_props  = $get_all_style_props( $el );
                    $non_bg     = array_diff( $all_props, [ 'background', 'background-color', 'display', 'flex-direction', 'position', 'z-index', 'inset-block-start', 'inset-block-end', 'inset-inline-start', 'inset-inline-end', 'width', 'height' ] );
                    $has_bg_val = $has_bg( $el );

                    // Empty container (no widget, no meaningful content) with background
                    if ( $n_kids === 0 && $has_bg_val && $parent_el !== null ) {
                        $issues[] = [
                            'severity'   => 'warning',
                            'check'      => 'background_wrapper',
                            'element_id' => $el_id,
                            'element'    => $el_label( $el ),
                            'message'    => 'Empty container with background and no children -- classic bg-layer anti-pattern.',
                            'fix'        => 'Move background style directly onto the parent element (' . $el_label( $parent_el ) . '). Eliminate this container.',
                        ];
                    // Container that only has background + 1 additional content container (typical hero-bg wrapper)
                    } elseif ( $n_kids === 1 && $has_bg_val && empty( $non_bg ) && $parent_el !== null ) {
                        $issues[] = [
                            'severity'   => 'warning',
                            'check'      => 'background_wrapper',
                            'element_id' => $el_id,
                            'element'    => $el_label( $el ),
                            'message'    => 'Container has only a background and exactly 1 child -- likely a "hero-bg" wrapper pattern.',
                            'fix'        => 'Move background to the child or parent element, remove this wrapper.',
                        ];
                    }
                }

                // 4. GRID CANDIDATE: flexbox in row direction with >=2 direct container children,
                //    and each child itself has children (= 2D structure)
                if ( in_array( 'grid_candidate', $checks, true ) && $is_fl ) {
                    $flex_dir        = $get_flex_dir( $el );
                    $is_row          = ( $flex_dir === 'row' || $flex_dir === null ); // default is row
                    $cont_kids       = array_filter( $children, $is_container );
                    $n_cont_kids     = count( $cont_kids );

                    if ( $is_row && $n_cont_kids >= 2 ) {
                        // How many of these columns themselves have children? (= real 2D content)
                        $cols_with_kids = 0;
                        foreach ( $cont_kids as $col ) {
                            if ( count( $col['elements'] ?? [] ) > 0 ) $cols_with_kids++;
                        }
                        if ( $cols_with_kids >= 2 ) {
                            // Collect column widths if available
                            $widths = [];
                            foreach ( $cont_kids as $col ) {
                                foreach ( $col['styles'] ?? [] as $sty ) {
                                    foreach ( $sty['variants'] ?? [] as $v ) {
                                        if ( ( $v['meta']['breakpoint'] ?? 'desktop' ) !== 'desktop' ) continue;
                                        $w = $v['props']['width'] ?? null;
                                        if ( $w ) $widths[] = $unwrap( $w );
                                    }
                                }
                                // V3 width fallback
                                $v3w = $col['settings']['width'] ?? null;
                                if ( $v3w && empty( $widths ) ) $widths[] = $unwrap( $v3w );
                            }
                            $suggested_cols = implode( ' ', array_fill( 0, $n_cont_kids, '1fr' ) );
                            if ( ! empty( $widths ) ) {
                                $suggested_cols = implode( ' ', array_map( fn($w) => ( is_numeric( $w ) ? $w . 'px' : $w ), $widths ) );
                            }
                            $issues[] = [
                                'severity'         => 'info',
                                'check'            => 'grid_candidate',
                                'element_id'       => $el_id,
                                'element'          => $el_label( $el ),
                                'column_count'     => $n_cont_kids,
                                'columns_with_ids' => array_column( array_values( $cont_kids ), 'id' ),
                                'suggested_grid'   => "display:grid; grid-template-columns: {$suggested_cols}",
                                'message'          => "{$n_cont_kids}-column flexbox row with populated columns -- 2D layout, CSS Grid would be shorter and more robust.",
                                'fix'              => "display:grid + grid-template-columns: {$suggested_cols} on this container. Column containers can then be e-div-block without a width prop.",
                            ];
                        }
                    }
                }

                // 5. PASS-THROUGH: container without own styles AND without settings (pure pass-through wrapper)
                if ( in_array( 'passthrough', $checks, true ) && $is_cont && $n_kids > 0 && $parent_el !== null ) {
                    $all_props = $get_all_style_props( $el );
                    $non_trivial = array_diff( $all_props, $TRIVIAL_PROPS );
                    if ( empty( $non_trivial ) && ! $has_any_settings( $el ) ) {
                        $issues[] = [
                            'severity'   => 'info',
                            'check'      => 'passthrough',
                            'element_id' => $el_id,
                            'element'    => $el_label( $el ),
                            'n_kids'     => $n_kids,
                            'message'    => 'Container without own styles and without settings -- empty pass-through wrapper.',
                            'fix'        => "Move children directly into {$el_label($parent_el)} and remove #{$el_id}.",
                        ];
                    }
                }

                // 6. KICKER WRAPPER: row-flexbox with SVG + Heading without own styles,
                //    inside a column-flex parent element
                if ( in_array( 'kicker_wrapper', $checks, true ) && $is_fl && $n_kids >= 2 && $n_kids <= 3 ) {
                    $flex_dir = $get_flex_dir( $el );
                    if ( $flex_dir === 'row' ) {
                        $has_svg     = false;
                        $has_heading = false;
                        foreach ( $children as $kid ) {
                            $wt = $kid['widgetType'] ?? '';
                            if ( $wt === 'e-svg' )     $has_svg     = true;
                            if ( $wt === 'e-heading' ) $has_heading = true;
                        }
                        // Parent check: is it column-flex?
                        $parent_is_col = $parent_el &&
                            $is_flex( $parent_el ) &&
                            ( $get_flex_dir( $parent_el ) === 'column' );

                        if ( $has_svg && $has_heading && $parent_is_col && ! $has_meaningful_styles( $el ) ) {
                            $issues[] = [
                                'severity'   => 'info',
                                'check'      => 'kicker_wrapper',
                                'element_id' => $el_id,
                                'element'    => $el_label( $el ),
                                'parent_id'  => $parent_el['id'] ?? '?',
                                'message'    => 'Kicker row (SVG + Heading in flex-direction:row) as a separate container level under a column-flex parent.',
                                'fix'        => 'Parent element (' . $el_label( $parent_el ) . ') already uses flex-direction:column. Remove kicker-row, place SVG and Heading directly as first children -- OR -- change parent itself to flex-direction:row if the kicker should be inline with further items.',
                            ];
                        }
                    }
                }

                // Recursion
                if ( ! empty( $children ) ) {
                    $analyse( $children, $depth + 1, array_merge( $ancestor_ids, [ $el_id ] ), $el );
                }
            }
        };

        $analyse( $data, 1, [], null );

        // Summary
        $by_check = [];
        foreach ( $issues as $issue ) {
            $by_check[ $issue['check'] ] = ( $by_check[ $issue['check'] ] ?? 0 ) + 1;
        }

        $err_count  = count( array_filter( $issues, fn($i) => $i['severity'] === 'error' ) );
        $warn_count = count( array_filter( $issues, fn($i) => $i['severity'] === 'warning' ) );

        $summary = count( $issues ) === 0
            ? "Layout clean -- no issues in \"{$post->post_title}\" ({$container_count} containers, max depth {$max_depth_found})."
            : sprintf(
                '%d layout issues in "%s" (%d errors, %d warnings) | %s | %d total containers, max depth %d.',
                count( $issues ), $post->post_title,
                $err_count, $warn_count,
                implode( ', ', array_map(
                    fn( $k, $v ) => "{$v}x {$k}",
                    array_keys( $by_check ), array_values( $by_check )
                ) ),
                $container_count, $max_depth_found
            );

        return [
            'success'         => true,
            'post_id'         => $post_id,
            'post_title'      => $post->post_title,
            'total_issues'    => count( $issues ),
            'max_depth_found' => $max_depth_found,
            'container_count' => $container_count,
            'by_check'        => $by_check,
            'issues'          => $issues,
            'summary'         => $summary,
        ];
    }
}

add_action('wp_abilities_api_init', [Layout_Audit::class, 'register']);
