<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\ClonerLabs;

use Novamira\AdrianV2\Helpers\Guards;
use Novamira\AdrianV2\Helpers\V3_To_V4_Converter;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Import_ClonerLabs_Page
 *
 * Ability: `novamira-adrianv2/import-clonerlabs-page`
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  ACCEPTED INPUT FORMATS for `cloner_data`:
 *
 *  A) Full page export (ClonerLabs "Export to Elementor JSON"):
 *     {
 *       "content":        [...],           // element tree
 *       "settings":       {               // BUG #2 FIX: NOT global_styles
 *         "system_colors": [...],
 *         "custom_colors": [...],
 *         "system_typography": [...],
 *         "custom_typography": [...]
 *       },
 *       "page_settings":  {},
 *       "media_library":  { "svgs": [] },
 *       "version":        "0.4",
 *       "type":           "page"           // BUG #9 FIX: "page" is normal
 *     }
 *
 *  B) Saved section (from ClonerLabs chrome.storage.local):
 *     {
 *       "id": "sec_xxx", "name": "Hero",
 *       "elementorData": { ...container... },  // FIX: not mappedElements
 *       "isGridMode": false,
 *       "widgetCount": 12
 *     }
 *     → Normalised to format A before processing.
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Bug fixes applied:
 *   #1  v4_strategy enum: keep_v3 / skip / error  (not keep / html)
 *   #2  Global styles from data['settings'], not data['global_styles']
 *   #4  accordion exported as nested-accordion (handled by converter)
 *   #6  _gsapCode stripped and injected into page_settings.custom_js
 *   #7  var(--e-global-color-*) + __globals__ protected in style minifier
 *   #8  site_settings removed from schema (doesn't exist in page exports)
 *   #9  type: "page" accepted as valid
 *   #11 icon placeholder count tracked in stats + warned
 *   #12 custom-widget normalised to html widgetType
 *   #13 Guards::save_elementor_data() used instead of manual update_post_meta
 *   #16 isLocked: true containers skipped in ID regeneration
 *   #18 data['settings'] used for validation (not global_styles)
 *
 * @package Novamira_AdrianV2
 * @since   1.3.0
 */
final class Import_ClonerLabs_Page {

    /** Maximum element tree depth allowed. */
    private const MAX_DEPTH = 15;

    public static function register(): void {
        wp_register_ability( 'novamira-adrianv2/import-clonerlabs-page', [
            'label'    => 'Import ClonerLabs Page',
            'description' =>
                'Imports a ClonerLabs JSON export into Elementor. Accepts both full-page exports '
                . '(content + settings + media_library) and saved-section format (elementorData). '
                . 'Handles SVG/external image sideloading, global style merging, V3→V4 conversion, '
                . 'style-noise cleanup, and GSAP script preservation.',
            'category' => 'adrianv2-clonerlabs',

            'input_schema' => [
                'type'       => 'object',
                'required'   => [ 'cloner_data' ],
                'properties' => [
                    'cloner_data'          => [ 'type' => 'object',  'description' => 'ClonerLabs full-page export or saved-section object.' ],
                    'target'               => [ 'type' => 'string',  'enum' => [ 'v3', 'v4' ], 'default' => 'v3' ],
                    'post_id'              => [ 'type' => 'integer', 'description' => 'Existing post ID to overwrite. Omit to create a new page.' ],
                    'title'                => [ 'type' => 'string' ],
                    'slug'                 => [ 'type' => 'string' ],
                    'status'               => [ 'type' => 'string',  'enum' => [ 'draft', 'publish', 'private' ], 'default' => 'draft' ],
                    'template'             => [ 'type' => 'string',  'default' => 'elementor_header_footer' ],
                    'upload_media'         => [ 'type' => 'boolean', 'default' => true ],
                    'apply_global_styles'  => [ 'type' => 'boolean', 'default' => true ],
                    // BUG #1: correct enum values matching V3_To_V4_Converter
                    'v4_strategy'          => [ 'type' => 'string',  'enum' => [ 'keep_v3', 'skip', 'error' ], 'default' => 'keep_v3' ],
                    'create_template'      => [ 'type' => 'boolean', 'default' => false ],
                    'cleanup_styles'       => [ 'type' => 'boolean', 'default' => true ],
                    'regenerate_ids'       => [ 'type' => 'boolean', 'default' => true ],
                    // BUG #8: site_settings removed — doesn't exist in page exports
                ],
            ],

            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'              => [ 'type' => 'boolean' ],
                    'target'               => [ 'type' => 'string' ],
                    'post_id'              => [ 'type' => 'integer' ],
                    'permalink'            => [ 'type' => 'string' ],
                    'edit_url'             => [ 'type' => 'string' ],
                    'created_page'         => [ 'type' => 'boolean' ],
                    'template_id'          => [ 'type' => [ 'integer', 'null' ] ],
                    'stats'                => [ 'type' => 'object' ],
                    'warnings'             => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'manual_adjustments'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'summary'              => [ 'type' => 'string' ],
                    'error'                => [ 'type' => 'string' ],
                ],
            ],

            'execute_callback'    => [ self::class, 'execute' ],
            'permission_callback' => 'novamira_permission_callback',
            'meta'                => [
                'show_in_rest' => true,
                'mcp'          => [ 'public' => true ],
                'annotations'  => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
            ],
        ] );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Entry Point
    // ══════════════════════════════════════════════════════════════════════════

    public static function execute( ?array $input = null ): array {
        $input = $input ?? [];

        $cloner_data         = $input['cloner_data']         ?? null;
        $target              = $input['target']              ?? 'v3';
        $post_id             = (int) ( $input['post_id']    ?? 0 );
        $title               = (string) ( $input['title']   ?? '' );
        $slug                = (string) ( $input['slug']    ?? '' );
        $status              = in_array( $input['status'] ?? 'draft', [ 'draft', 'publish', 'private' ], true ) ? ( $input['status'] ?? 'draft' ) : 'draft';
        $template            = (string) ( $input['template']          ?? 'elementor_header_footer' );
        $upload_media        = (bool)   ( $input['upload_media']      ?? true );
        $apply_global_styles = (bool)   ( $input['apply_global_styles'] ?? true );
        // BUG #1: default is keep_v3, not keep
        $v4_strategy         = in_array( $input['v4_strategy'] ?? 'keep_v3', [ 'keep_v3', 'skip', 'error' ], true ) ? ( $input['v4_strategy'] ?? 'keep_v3' ) : 'keep_v3';
        $create_template     = (bool)   ( $input['create_template']   ?? false );
        $cleanup_styles      = (bool)   ( $input['cleanup_styles']    ?? true );
        $regenerate_ids      = (bool)   ( $input['regenerate_ids']    ?? true );

        if ( ! is_array( $cloner_data ) ) {
            return [ 'success' => false, 'error' => 'cloner_data must be an object.' ];
        }

        $stats = [
            'total_elements'            => 0,
            'converted_to_v4'           => 0,
            'kept_v3'                   => 0,
            'skipped'                   => 0,
            'media_uploaded'            => 0,
            'media_replaced'            => 0,
            'global_colors_applied'     => 0,
            'global_typography_applied' => 0,
            'styles_cleaned'            => 0,
            'ids_regenerated'           => 0,
            'gsap_scripts_collected'    => 0,   // FIX #6
            'icon_placeholders'         => 0,   // FIX #11
        ];
        $warnings           = [];
        $manual_adjustments = [];

        try {
            // ── PHASE 1: VALIDATE & NORMALISE ─────────────────────────────────
            $v = self::phase1_validate( $cloner_data, $title );
            if ( isset( $v['error'] ) ) {
                return [ 'success' => false, 'error' => $v['error'] ];
            }

            $elements         = $v['elements'];
            $page_settings    = $v['page_settings'];
            $raw_settings     = $v['raw_settings'];   // BUG #2
            $media_library    = $v['media_library'];
            $page_title       = $title ?: $v['title'];
            $manual_adjustments = array_merge( $manual_adjustments, $v['manual_adjustments'] );

            // FIX #11: Count icon placeholder widgets.
            $stats['icon_placeholders'] = self::count_widget_type( $elements, 'icon' );
            if ( $stats['icon_placeholders'] > 0 ) {
                $warnings[] = "Icon widgets ({$stats['icon_placeholders']}) contain placeholder star icons — manual icon selection required in Elementor.";
            }

            $stats['total_elements'] = self::count_elements( $elements );

            // ── PHASE 2: MEDIA ─────────────────────────────────────────────────
            if ( $upload_media ) {
                $mr = ClonerLabs_Media_Handler::process( $media_library, $elements );
                $stats['media_uploaded'] = $mr['uploaded'];
                $stats['media_replaced'] = $mr['replaced'];
                foreach ( $mr['errors'] as $e ) $warnings[] = "Media: {$e}";
            }

            // ── PHASE 3: V4 CONVERSION ─────────────────────────────────────────
            if ( $target === 'v4' ) {
                $conv_stats    = [];
                $conv_warnings = [];
                $variable_map  = self::build_variable_map( $raw_settings );

                $elements = V3_To_V4_Converter::convert_elements(
                    $elements,
                    $v4_strategy,
                    $conv_stats,
                    $conv_warnings,
                    $variable_map,
                    []
                );

                $stats['converted_to_v4'] = $conv_stats['converted'] ?? 0;
                $stats['kept_v3']         = $conv_stats['kept_v3']   ?? 0;
                $stats['skipped']         = $conv_stats['skipped']   ?? 0;
                $warnings                 = array_merge( $warnings, $conv_warnings );
            } else {
                $stats['kept_v3'] = $stats['total_elements'];
            }

            // ── PHASE 4: GLOBAL STYLES ─────────────────────────────────────────
            if ( $apply_global_styles && ! empty( $raw_settings ) ) {
                $sr = ClonerLabs_Global_Styles::apply( $raw_settings );
                $stats['global_colors_applied']     = $sr['colors_applied'];
                $stats['global_typography_applied'] = $sr['typography_applied'];
                if ( ! empty( $sr['error'] ) ) $warnings[] = 'Global styles: ' . $sr['error'];

                // FIX #12 (Google Fonts): warn about fonts that need to be enqueued.
                foreach ( $sr['fonts_to_verify'] ?? [] as $font ) {
                    $warnings[] = "Font '{$font}' used in typography — ensure it is loaded via Elementor kit or Google Fonts URL.";
                }
            }

            // ── PHASE 5: PRE-SAVE PROCESSING ──────────────────────────────────

            // FIX #6: Collect and strip _gsapCode; inject into page_settings.custom_js.
            $gsap_scripts = [];
            $elements     = self::collect_and_strip_gsap( $elements, $gsap_scripts );
            $stats['gsap_scripts_collected'] = count( $gsap_scripts );
            if ( ! empty( $gsap_scripts ) ) {
                $combined_js = implode( "\n\n/* --- ClonerLabs GSAP --- */\n\n", $gsap_scripts );
                $page_settings['custom_js'] = ( $page_settings['custom_js'] ?? '' )
                    ? $page_settings['custom_js'] . "\n\n" . $combined_js
                    : $combined_js;
                $warnings[] = count( $gsap_scripts ) . " GSAP script(s) collected and injected into page custom JS. Review and test animations.";
            }

            // Style cleanup.
            if ( $cleanup_styles ) {
                $before = self::count_settings_keys( $elements );
                $elements = ClonerLabs_Style_Minifier::clean( $elements );
                $stats['styles_cleaned'] = $before - self::count_settings_keys( $elements );
            }

            // ID regeneration (FIX #16: skip isLocked).
            if ( $regenerate_ids ) {
                $id_count = 0;
                $elements = self::regenerate_element_ids( $elements, $id_count );
                $stats['ids_regenerated'] = $id_count;
            }

            // ── PHASE 5B: SAVE ─────────────────────────────────────────────────
            $page_result = self::create_or_update_page( [
                'post_id'       => $post_id,
                'title'         => $page_title ?: 'Cloned Page',
                'slug'          => $slug,
                'status'        => $status,
                'template'      => $template,
                'elements'      => $elements,
                'page_settings' => $page_settings,
            ] );

            if ( isset( $page_result['error'] ) ) {
                return [ 'success' => false, 'error' => $page_result['error'] ];
            }

            $post_id      = $page_result['post_id'];
            $created_page = $page_result['created_page'];

            // ── PHASE 6: TEMPLATE ──────────────────────────────────────────────
            $template_id = null;
            if ( $create_template ) {
                $template_id = self::save_as_template( $post_id, $page_title ?: 'Cloned Page' );
            }

            // ── PHASE 7: REPORT ────────────────────────────────────────────────
            $v_label = $target === 'v4' ? 'V4' : 'V3';
            $action  = $created_page ? 'created' : 'updated';
            $summary = sprintf(
                "Page '%s' (#%d) %s with %d elements (%s). %d media files uploaded, %d styles cleaned.",
                $page_title ?: 'Cloned Page',
                $post_id,
                $action,
                $stats['total_elements'],
                $v_label,
                $stats['media_uploaded'],
                $stats['styles_cleaned']
            );

            return [
                'success'            => true,
                'target'             => $target,
                'post_id'            => $post_id,
                'permalink'          => get_permalink( $post_id ) ?: '',
                'edit_url'           => get_edit_post_link( $post_id, 'raw' ) ?: '',
                'created_page'       => $created_page,
                'template_id'        => $template_id,
                'stats'              => $stats,
                'warnings'           => $warnings,
                'manual_adjustments' => $manual_adjustments,
                'summary'            => $summary,
            ];

        } catch ( \Throwable $e ) {
            return [ 'success' => false, 'error' => 'Unexpected error: ' . $e->getMessage() ];
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Phase 1 — Validate & Normalise
    // ══════════════════════════════════════════════════════════════════════════

    private static function phase1_validate( array $data, string $title_override ): array {
        $empty = [ 'elements' => [], 'page_settings' => [], 'raw_settings' => [], 'media_library' => [], 'manual_adjustments' => [], 'title' => '' ];

        // Detect saved-section format: has `elementorData` key (not `content`).
        if ( isset( $data['elementorData'] ) && is_array( $data['elementorData'] ) ) {
            $data = self::normalise_saved_section( $data );
        }

        $content = $data['content'] ?? null;
        if ( ! is_array( $content ) || empty( $content ) ) {
            return array_merge( $empty, [ 'error' => 'cloner_data.content must be a non-empty array.' ] );
        }

        // BUG #9: Accept type "page" (normal), "container", "section" — all valid.
        // No hard check on type field needed.

        if ( self::max_tree_depth( $content ) > self::MAX_DEPTH ) {
            return array_merge( $empty, [ 'error' => 'Element tree exceeds max depth of ' . self::MAX_DEPTH . '.' ] );
        }

        $dup_ids = self::find_duplicate_ids( $content );
        $notes   = [];
        if ( ! empty( $dup_ids ) ) {
            $notes[] = 'Duplicate element IDs found (will be regenerated): ' . implode( ', ', array_slice( $dup_ids, 0, 10 ) );
        }

        // FIX #12 (custom-widget → html): pre-process widget types before anything else.
        $content = self::normalise_widget_types( $content );

        // ClonerLabs manual_adjustments_needed.
        $items = $data['_manual_adjustments_needed']['items'] ?? [];
        if ( is_array( $items ) ) $notes = array_merge( $notes, $items );

        return [
            'elements'           => $content,
            'page_settings'      => is_array( $data['page_settings'] ?? null ) ? $data['page_settings'] : [],
            // BUG #2 + FIX #18: global styles are under 'settings', not 'global_styles'.
            'raw_settings'       => is_array( $data['settings'] ?? null ) ? $data['settings']
                : ( is_array( $data['global_styles'] ?? null ) ? $data['global_styles'] : [] ), // backward compat fallback
            'media_library'      => is_array( $data['media_library'] ?? null ) ? $data['media_library'] : [],
            'manual_adjustments' => $notes,
            'title'              => $title_override ?: (string) ( $data['title'] ?? '' ),
        ];
    }

    /**
     * Normalise saved-section format into full-export format.
     *
     * Saved sections from ClonerLabs use `elementorData` (a single container object),
     * NOT `mappedElements` as the original plan incorrectly documented.
     */
    private static function normalise_saved_section( array $section ): array {
        $el = $section['elementorData'];
        if ( ! isset( $el['elType'] ) ) $el['elType'] = 'container';
        if ( ! isset( $el['id'] ) )     $el['id']     = self::gen_id();

        // FIX: isGridMode → row flex direction for grid-mode sections.
        if ( ! empty( $section['isGridMode'] ) ) {
            $el['settings']['flex_direction'] = $el['settings']['flex_direction'] ?? 'row';
        }

        return [
            'content'       => [ $el ],
            'settings'      => [],
            'page_settings' => [],
            'media_library' => [],
            'version'       => '0.4',
            'type'          => 'page',
            'title'         => $section['name'] ?? 'Cloned Section',
        ];
    }

    /**
     * FIX #12: Normalise widget types that ClonerLabs maps non-standardly.
     *   - `custom-widget` → `html`  (unrecognised elements get HTML fallback)
     *   - (accordion → nested-accordion is handled directly by the converter)
     */
    private static function normalise_widget_types( array $elements ): array {
        return array_map( function ( array $el ): array {
            if ( ( $el['widgetType'] ?? '' ) === 'custom-widget' ) {
                $el['widgetType'] = 'html';
                // Preserve any html content if present.
                if ( empty( $el['settings']['html'] ) && ! empty( $el['settings']['content'] ) ) {
                    $el['settings']['html'] = (string) $el['settings']['content'];
                }
            }
            if ( ! empty( $el['elements'] ) ) {
                $el['elements'] = self::normalise_widget_types( $el['elements'] );
            }
            return $el;
        }, $elements );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Phase 5 — Save
    // ══════════════════════════════════════════════════════════════════════════

    private static function create_or_update_page( array $params ): array {
        $post_id       = (int) ( $params['post_id'] ?? 0 );
        $title         = (string) ( $params['title']         ?? 'Cloned Page' );
        $slug          = (string) ( $params['slug']          ?? '' );
        $status        = (string) ( $params['status']        ?? 'draft' );
        $wp_template   = (string) ( $params['template']      ?? 'elementor_header_footer' );
        $elements      = $params['elements']      ?? [];
        $page_settings = $params['page_settings'] ?? [];
        $created_page  = false;

        if ( $post_id > 0 && get_post( $post_id ) ) {
            wp_update_post( [ 'ID' => $post_id, 'post_title' => $title, 'post_status' => $status ] );
        } else {
            $new_id = wp_insert_post( [
                'post_type'   => 'page',
                'post_title'  => $title,
                'post_status' => $status,
                'post_name'   => $slug ?: sanitize_title( $title ),
            ], true );

            if ( is_wp_error( $new_id ) ) {
                return [ 'post_id' => 0, 'created_page' => false, 'error' => $new_id->get_error_message() ];
            }
            $post_id      = $new_id;
            $created_page = true;
        }

        update_post_meta( $post_id, '_wp_page_template',    $wp_template );
        update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
        update_post_meta( $post_id, '_elementor_version',   defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0' );

        // FIX #13: Guards::save_elementor_data() handles wp_slash + cache invalidation.
        Guards::save_elementor_data( $post_id, $elements );

        if ( ! empty( $page_settings ) ) {
            update_post_meta( $post_id, '_elementor_page_settings', $page_settings );
        }

        return [ 'post_id' => $post_id, 'created_page' => $created_page ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Phase 6 — Optional Template
    // ══════════════════════════════════════════════════════════════════════════

    private static function save_as_template( int $source_id, string $title ): ?int {
        $data          = Guards::get_elementor_data( $source_id );
        $page_settings = get_post_meta( $source_id, '_elementor_page_settings', true );

        $id = wp_insert_post( [
            'post_type'   => 'elementor_library',
            'post_title'  => $title,
            'post_status' => 'publish',
        ] );

        if ( ! $id || is_wp_error( $id ) ) return null;

        update_post_meta( $id, '_elementor_template_type', 'page' );
        update_post_meta( $id, '_elementor_edit_mode',     'builder' );
        if ( $data !== false ) Guards::save_elementor_data( $id, $data );
        if ( is_array( $page_settings ) ) update_post_meta( $id, '_elementor_page_settings', $page_settings );

        return $id;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Utilities
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Build variable_map for V3_To_V4_Converter from ClonerLabs `settings` object.
     * FIX #20: Uses correct key paths.
     */
    private static function build_variable_map( array $raw_settings ): array {
        $all_colors   = array_merge(
            $raw_settings['system_colors']  ?? [],
            $raw_settings['custom_colors']  ?? []
        );
        $variable_map = [];
        foreach ( $all_colors as $entry ) {
            $id    = $entry['_id']   ?? '';
            $color = $entry['color'] ?? '';
            if ( $id === '' || $color === '' ) continue;
            $variable_map[ $id ] = [
                'id'    => 'e-gv-' . $id,
                'label' => $entry['title'] ?? $id,
                'type'  => 'global-color-variable',
                'value' => $color,
            ];
        }
        return $variable_map;
    }

    /**
     * FIX #6: Recursively collect `_gsapCode` values and strip from elements.
     *
     * @param  array   $elements Element tree.
     * @param  string[] $scripts  Scripts accumulator (by reference).
     * @return array              Tree without _gsapCode fields.
     */
    private static function collect_and_strip_gsap( array $elements, array &$scripts ): array {
        return array_map( function ( array $el ) use ( &$scripts ): array {
            if ( ! empty( $el['_gsapCode'] ) && is_string( $el['_gsapCode'] ) ) {
                $scripts[] = $el['_gsapCode'];
                unset( $el['_gsapCode'] );
            }
            if ( ! empty( $el['elements'] ) ) {
                $el['elements'] = self::collect_and_strip_gsap( $el['elements'], $scripts );
            }
            return $el;
        }, $elements );
    }

    /**
     * Regenerate all element IDs to prevent collisions on repeat imports.
     * FIX #16: isLocked containers get new ID but their settings are untouched.
     */
    private static function regenerate_element_ids( array $elements, int &$count ): array {
        return array_map( function ( array $el ) use ( &$count ): array {
            $el['id'] = self::gen_id();
            $count++;
            if ( ! empty( $el['elements'] ) ) {
                $el['elements'] = self::regenerate_element_ids( $el['elements'], $count );
            }
            return $el;
        }, $elements );
    }

    public static function gen_id(): string {
        return substr( str_replace( [ '+', '/', '=' ], '', base64_encode( random_bytes( 8 ) ) ), 0, 8 );
    }

    private static function count_elements( array $elements ): int {
        $n = 0;
        foreach ( $elements as $el ) {
            $n++;
            if ( ! empty( $el['elements'] ) ) $n += self::count_elements( $el['elements'] );
        }
        return $n;
    }

    private static function count_settings_keys( array $elements ): int {
        $n = 0;
        foreach ( $elements as $el ) {
            $n += count( $el['settings'] ?? [] );
            if ( ! empty( $el['elements'] ) ) $n += self::count_settings_keys( $el['elements'] );
        }
        return $n;
    }

    private static function count_widget_type( array $elements, string $type ): int {
        $n = 0;
        foreach ( $elements as $el ) {
            if ( ( $el['widgetType'] ?? '' ) === $type ) $n++;
            if ( ! empty( $el['elements'] ) ) $n += self::count_widget_type( $el['elements'], $type );
        }
        return $n;
    }

    private static function max_tree_depth( array $elements, int $current = 0 ): int {
        $max = $current;
        foreach ( $elements as $el ) {
            if ( ! empty( $el['elements'] ) ) {
                $child = self::max_tree_depth( $el['elements'], $current + 1 );
                if ( $child > $max ) $max = $child;
            }
        }
        return $max;
    }

    private static function find_duplicate_ids( array $elements ): array {
        $seen = [];
        $dups = [];
        self::collect_ids( $elements, $seen, $dups );
        return array_keys( $dups );
    }

    private static function collect_ids( array $elements, array &$seen, array &$dups ): void {
        foreach ( $elements as $el ) {
            $id = $el['id'] ?? '';
            if ( $id !== '' ) {
                if ( isset( $seen[ $id ] ) ) $dups[ $id ] = true;
                $seen[ $id ] = true;
            }
            if ( ! empty( $el['elements'] ) ) {
                self::collect_ids( $el['elements'], $seen, $dups );
            }
        }
    }
}
