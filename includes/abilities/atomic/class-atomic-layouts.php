<?php
declare(strict_types=1);

/**
 * V4 Atomic Layout Abilities — Port from EMCP class-atomic-layout-abilities.php
 *
 * Registers tools for creating flexbox and div-block containers plus a
 * read-only Elementor version diagnostic. Only the container abilities
 * gate on atomic support; detect-elementor-version always registers.
 *
 * Architecture: Fully static. Carries lightweight read/write/insert
 * helpers so it works without Novamira Pro. Depends on V4 Props + Styles
 * from Phases 1–2.
 *
 * Key fixes from EMCP source:
 * - Flexbox & div-block: layout/spacing/background go into the `styles`
 *   map (not `settings`) — matching Novamira Pro's atomic container pattern.
 * - background-color / color: GV detection via V4_Styles::wrap_color_value().
 * - `tag` default: 'div' for both container types.
 *
 * @package Extra
 * @since   1.3.0
 */

namespace Novamira\AdrianV2\Abilities\Atomic;

use Novamira\AdrianV2\Helpers\V4_Props;
use Novamira\AdrianV2\Helpers\V4_Styles;
use Novamira\AdrianV2\Helpers\V4_Color_Contrast;
use Novamira\AdrianV2\Helpers\V4_Content_Extractor;
use Novamira\AdrianV2\Helpers\V4_Seo_Meta;
use Novamira\AdrianV2\Helpers\PHP_Sandbox_Store;
use Novamira\AdrianV2\Helpers\PHP_Sandbox_Validator;
use Novamira\AdrianV2\Helpers\Ability_Registry;
use Novamira\AdrianV2\Helpers\Elementor_Data_Helpers;
use Novamira\AdrianV2\Helpers\Elementor_Version_Resolver;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Static ability registrar for atomic container operations.
 *
 * @since 1.3.0
 */
class Atomic_Layouts {
    use Elementor_Data_Helpers;
    use Ability_Registry;

    /** @var string[] */
    private static array $ability_names = [];

    /**
     * Read permission: edit_posts.
     */
    public static function check_read_permission(): bool {
        return current_user_can('edit_posts');
    }

    /**
     * Register all atomic layout abilities.
     *
     * Call once from wp_abilities_api_init. Only the container tools
     * skip when atomic is unavailable; detect-elementor-version always
     * registers.
     */
    public static function register(): void {
        // detect-elementor-version always registers (read-only diagnostic).
        self::register_detect_elementor_version();

        if (!V4_Props::is_atomic_supported()) {
            return;
        }

        self::register_add_flexbox();
        self::register_add_div_block();
    }

    // =========================================================================
    // Container element creation
    // =========================================================================

    /**
     * Creates an atomic flexbox container element.
     *
     * In V4, layout/spacing/background go into the `styles` map, not
     * `settings`. Settings only carry structural fields (tag, _cssid,
     * classes). This matches Novamira Pro's `el_add_atomic_container_element`.
     *
     * @param array $settings     Container settings ($$type-wrapped).
     * @param array $children     Child elements.
     * @param array $style_params Flat layout + common style params.
     * @return array The flexbox element structure.
     */
    private static function create_flexbox(
        array $settings = [],
        array $children = [],
        array $style_params = []
    ): array {
        $id = self::generate_id();

        if (!isset($settings['tag'])) {
            $settings['tag'] = V4_Props::string('div');
        }
        if (!isset($settings['classes'])) {
            $settings['classes'] = V4_Props::classes();
        }

        $element = [
            'id'              => $id,
            'elType'          => 'e-flexbox',
            'settings'        => $settings,
            'elements'        => $children,
            'isInner'         => false,
            'styles'          => [],
            'interactions'    => [],
            'editor_settings' => [],
            'version'         => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '',
        ];

        // Build flex layout + common styles from flat params.
        $flex_css   = V4_Styles::build_flex_props($style_params);
        $common_css = V4_Styles::build_common_props($style_params);
        $all_css    = array_merge($flex_css, $common_css);

        if (!empty($all_css)) {
            $style = V4_Styles::create_local_class($id, $all_css);
            V4_Styles::apply_to_element($element, $style['class_id'], $style['style_def']);
        }

        return $element;
    }

    /**
     * Creates an atomic div-block container element (block flow layout).
     *
     * Same V4 pattern as flexbox — styles go in the `styles` map.
     *
     * @param array $settings     Container settings ($$type-wrapped).
     * @param array $children     Child elements.
     * @param array $style_params Flat common style params (no flex props).
     * @return array The div-block element structure.
     */
    private static function create_div_block(
        array $settings = [],
        array $children = [],
        array $style_params = []
    ): array {
        $id = self::generate_id();

        if (!isset($settings['tag'])) {
            $settings['tag'] = V4_Props::string('div');
        }
        if (!isset($settings['classes'])) {
            $settings['classes'] = V4_Props::classes();
        }

        $element = [
            'id'              => $id,
            'elType'          => 'e-div-block',
            'settings'        => $settings,
            'elements'        => $children,
            'isInner'         => false,
            'styles'          => [],
            'interactions'    => [],
            'editor_settings' => [],
            'version'         => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '',
        ];

        // Build common styles only (no flex layout for div-block).
        $common_css = V4_Styles::build_common_props($style_params);

        if (!empty($common_css)) {
            $style = V4_Styles::create_local_class($id, $common_css);
            V4_Styles::apply_to_element($element, $style['class_id'], $style['style_def']);
        }

        return $element;
    }

    // =========================================================================
    // Shared execute helper
    // =========================================================================

    /**
     * Read page, insert container (or append at root), write back.
     *
     * @param array  $input     The raw ability input.
     * @param string $el_type   The container elType ('e-flexbox' or 'e-div-block').
     * @param array  $style_keys Which input keys to extract as style params.
     * @return array|\WP_Error
     */
    private static function execute_add_container($input, string $el_type, array $style_keys) {
        // V4 guard (1.1.0): atomic containers require Elementor 4.0+.
        if (!\Novamira\AdrianV2\Helpers\Elementor_Version_Resolver::site_is_v4()) {
            return new \WP_Error(
                'v4_required',
                sprintf(
                    __('%s requires Elementor 4.0+. Detected version: %s. Use legacy container/widget abilities for V3 sites.', 'novamira-adrianv2'),
                    'add-' . $el_type,
                    \Novamira\AdrianV2\Helpers\Elementor_Version_Resolver::site_version_string()
                )
            );
        }

        $post_id   = absint($input['post_id'] ?? 0);
        $parent_id = sanitize_text_field($input['parent_id'] ?? '');
        $position  = (int) ($input['position'] ?? -1);

        // Build settings (structural fields only).
        $settings = [];

        if (!empty($input['tag'])) {
            $settings['tag'] = V4_Props::string(
                sanitize_text_field($input['tag'])
            );
        }
        if (!empty($input['css_id'])) {
            $settings['_cssid'] = V4_Props::string(
                sanitize_text_field($input['css_id'])
            );
        }

        // Extract style params from input.
        $style_params = [];
        foreach ($style_keys as $key) {
            if (isset($input[$key])) {
                $style_params[$key] = $input[$key];
            }
        }

        // Create the container.
        $element = $el_type === 'e-flexbox'
            ? self::create_flexbox($settings, [], $style_params)
            : self::create_div_block($settings, [], $style_params);

        // Read, insert, write.
        $page = self::read_page($post_id);
        if ($page['error'] !== null) {
            return new \WP_Error('read_failed', $page['error']);
        }

        if ($parent_id !== '') {
            $ok = self::insert_element($page['elements'], $parent_id, $element, $position);
            if (!$ok) {
                return new \WP_Error(
                    'not_found',
                    "Parent element '{$parent_id}' not found in page {$post_id}."
                );
            }
        } else {
            // Top-level element.
            if ($position < 0 || $position >= count($page['elements'])) {
                $page['elements'][] = $element;
            } else {
                array_splice($page['elements'], max(0, $position), 0, [$element]);
            }
        }

        $save = self::write_page($post_id, $page['elements']);
        if (is_wp_error($save)) {
            return $save;
        }

        return [
            'element_id' => $element['id'],
            'post_id'    => $post_id,
        ];
    }

    // =========================================================================
    // Flexbox
    // =========================================================================

    private static function register_add_flexbox(): void {
        $name = 'novamira-adrianv2/add-flexbox';
        self::$ability_names[] = $name;

        wp_register_ability($name, [
            'label'               => __('Add Flexbox', 'novamira-adrianv2'),
            'description'         => __(
                'Adds an Elementor 4.0 flexbox container. Layout (direction, justify, align, gap, wrap) and visual styles (padding, background-color, color, min-height, width, border-radius) are applied as local V4 styles automatically — not as v3 container settings. Use this instead of legacy containers for Elementor 4.0+ sites.',
                'novamira-adrianv2'
            ),
            'category'            => 'elementor',
            'execute_callback'    => [self::class, 'execute_add_flexbox'],
            'permission_callback' => 'novamira_permission_callback',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'post_id'         => ['type' => 'integer', 'description' => __('The post/page ID.', 'novamira-adrianv2')],
                    'parent_id'       => ['type' => 'string', 'description' => __('Parent element ID. Empty for top-level.', 'novamira-adrianv2')],
                    'position'        => ['type' => 'integer', 'description' => __('Insert position. -1 = append.', 'novamira-adrianv2')],
                    'tag'             => ['type' => 'string', 'enum' => ['div', 'header', 'section', 'article', 'aside', 'footer'], 'description' => __('HTML tag. Default: div.', 'novamira-adrianv2')],
                    'direction'       => ['type' => 'string', 'enum' => ['row', 'column', 'row-reverse', 'column-reverse'], 'description' => __('Flex direction. Default: column.', 'novamira-adrianv2')],
                    'justify'         => ['type' => 'string', 'enum' => ['flex-start', 'center', 'flex-end', 'space-between', 'space-around', 'space-evenly'], 'description' => __('Justify content.', 'novamira-adrianv2')],
                    'align'           => ['type' => 'string', 'enum' => ['flex-start', 'center', 'flex-end', 'stretch', 'baseline'], 'description' => __('Align items.', 'novamira-adrianv2')],
                    'gap'             => ['type' => 'number', 'description' => __('Gap between children (px by default).', 'novamira-adrianv2')],
                    'gap_unit'        => ['type' => 'string', 'enum' => ['px', 'em', 'rem', '%', 'vw'], 'description' => __('Gap unit. Default: px.', 'novamira-adrianv2')],
                    'wrap'            => ['type' => 'string', 'enum' => ['nowrap', 'wrap', 'wrap-reverse'], 'description' => __('Flex wrap.', 'novamira-adrianv2')],
                    'css_id'          => ['type' => 'string', 'description' => __('Optional CSS ID.', 'novamira-adrianv2')],
                    'padding'         => ['type' => 'number', 'description' => __('Padding on all sides (px by default).', 'novamira-adrianv2')],
                    'padding_top'     => ['type' => 'number', 'description' => __('Padding top (px).', 'novamira-adrianv2')],
                    'padding_right'   => ['type' => 'number', 'description' => __('Padding right (px).', 'novamira-adrianv2')],
                    'padding_bottom'  => ['type' => 'number', 'description' => __('Padding bottom (px).', 'novamira-adrianv2')],
                    'padding_left'    => ['type' => 'number', 'description' => __('Padding left (px).', 'novamira-adrianv2')],
                    'margin_top'      => ['type' => 'number', 'description' => __('Margin top (px).', 'novamira-adrianv2')],
                    'margin_bottom'   => ['type' => 'number', 'description' => __('Margin bottom (px).', 'novamira-adrianv2')],
                    'background_color' => ['type' => 'string', 'description' => __('Background color (hex/rgba or e-gv-* Global Variable ID).', 'novamira-adrianv2')],
                    'color'           => ['type' => 'string', 'description' => __('Text color (hex/rgba or e-gv-* Global Variable ID).', 'novamira-adrianv2')],
                    'min_height'      => ['type' => 'number', 'description' => __('Minimum height (px by default).', 'novamira-adrianv2')],
                    'width'           => ['type' => 'number', 'description' => __('Element width (px by default).', 'novamira-adrianv2')],
                    'border_radius'   => ['type' => 'number', 'description' => __('Border radius (px).', 'novamira-adrianv2')],
                    'row_gap'         => ['type' => 'number', 'description' => __('Row gap (px).', 'novamira-adrianv2')],
                    'column_gap'      => ['type' => 'number', 'description' => __('Column gap (px).', 'novamira-adrianv2')],
                ],
                'required'   => ['post_id'],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'element_id' => ['type' => 'string'],
                    'post_id'    => ['type' => 'integer'],
                ],
            ],
            'meta'                => [
                'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);
    }

    /**
     * @param array $input Input parameters.
     * @return array|\WP_Error
     */
    public static function execute_add_flexbox($input) {
        $style_keys = [
            'direction', 'flex_direction', 'justify', 'justify_content',
            'align', 'align_items', 'wrap', 'flex_wrap',
            'gap', 'gap_unit', 'row_gap', 'column_gap',
            'padding', 'padding_unit',
            'padding_top', 'padding_right', 'padding_bottom', 'padding_left',
            'margin_top', 'margin_bottom',
            'background_color', 'color',
            'min_height', 'width', 'border_radius',
        ];

        return self::execute_add_container($input, 'e-flexbox', $style_keys);
    }

    // =========================================================================
    // Div Block
    // =========================================================================

    private static function register_add_div_block(): void {
        $name = 'novamira-adrianv2/add-div-block';
        self::$ability_names[] = $name;

        wp_register_ability($name, [
            'label'               => __('Add Div Block', 'novamira-adrianv2'),
            'description'         => __('Adds an Elementor 4.0 div-block container (block flow layout). Visual styles (padding, background-color, color, min-height, width, border-radius) are applied as local V4 styles automatically. Use for non-flex containers.', 'novamira-adrianv2'),
            'category'            => 'elementor',
            'execute_callback'    => [self::class, 'execute_add_div_block'],
            'permission_callback' => 'novamira_permission_callback',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'post_id'          => ['type' => 'integer', 'description' => __('The post/page ID.', 'novamira-adrianv2')],
                    'parent_id'        => ['type' => 'string', 'description' => __('Parent element ID. Empty for top-level.', 'novamira-adrianv2')],
                    'position'         => ['type' => 'integer', 'description' => __('Insert position. -1 = append.', 'novamira-adrianv2')],
                    'tag'              => ['type' => 'string', 'enum' => ['div', 'header', 'section', 'article', 'aside', 'footer'], 'description' => __('HTML tag. Default: div.', 'novamira-adrianv2')],
                    'css_id'           => ['type' => 'string', 'description' => __('Optional CSS ID.', 'novamira-adrianv2')],
                    'padding'          => ['type' => 'number', 'description' => __('Padding on all sides (px by default).', 'novamira-adrianv2')],
                    'padding_top'      => ['type' => 'number', 'description' => __('Padding top (px).', 'novamira-adrianv2')],
                    'padding_right'    => ['type' => 'number', 'description' => __('Padding right (px).', 'novamira-adrianv2')],
                    'padding_bottom'   => ['type' => 'number', 'description' => __('Padding bottom (px).', 'novamira-adrianv2')],
                    'padding_left'     => ['type' => 'number', 'description' => __('Padding left (px).', 'novamira-adrianv2')],
                    'margin_top'       => ['type' => 'number', 'description' => __('Margin top (px).', 'novamira-adrianv2')],
                    'margin_bottom'    => ['type' => 'number', 'description' => __('Margin bottom (px).', 'novamira-adrianv2')],
                    'background_color' => ['type' => 'string', 'description' => __('Background color (hex/rgba or e-gv-* Global Variable ID).', 'novamira-adrianv2')],
                    'color'            => ['type' => 'string', 'description' => __('Text color (hex/rgba or e-gv-* Global Variable ID).', 'novamira-adrianv2')],
                    'min_height'       => ['type' => 'number', 'description' => __('Minimum height (px by default).', 'novamira-adrianv2')],
                    'width'            => ['type' => 'number', 'description' => __('Element width (px by default).', 'novamira-adrianv2')],
                    'border_radius'    => ['type' => 'number', 'description' => __('Border radius (px).', 'novamira-adrianv2')],
                ],
                'required'   => ['post_id'],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'element_id' => ['type' => 'string'],
                    'post_id'    => ['type' => 'integer'],
                ],
            ],
            'meta'                => [
                'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);
    }

    /**
     * @param array $input Input parameters.
     * @return array|\WP_Error
     */
    public static function execute_add_div_block($input) {
        $style_keys = [
            'padding', 'padding_unit',
            'padding_top', 'padding_right', 'padding_bottom', 'padding_left',
            'margin_top', 'margin_bottom',
            'background_color', 'color',
            'min_height', 'width', 'border_radius',
        ];

        return self::execute_add_container($input, 'e-div-block', $style_keys);
    }

    // =========================================================================
    // Detect Elementor Version (always registers — read-only diagnostic)
    // =========================================================================

    private static function register_detect_elementor_version(): void {
        $name = 'novamira-adrianv2/detect-elementor-version';
        self::$ability_names[] = $name;

        wp_register_ability($name, [
            'label'               => __('Detect Elementor Version', 'novamira-adrianv2'),
            'description'         => __('Returns site-level Elementor V4/atomic capabilities and, when post_id is provided, the detected page version. Call this first to decide whether to use legacy tools, atomic tools, or V3-to-V4 conversion.', 'novamira-adrianv2'),
            'category'            => 'elementor',
            'execute_callback'    => static function ($input = null) {
                $input        = is_array($input) ? $input : [];
                $post_id      = absint($input['post_id'] ?? 0);
                $core_version = defined('ELEMENTOR_VERSION') ? (string) ELEMENTOR_VERSION : 'unknown';
                $pro_version  = defined('ELEMENTOR_PRO_VERSION') ? (string) ELEMENTOR_PRO_VERSION : 'not-installed';
                $caps         = Elementor_Version_Resolver::atomic_capabilities();
                $site_is_v4   = Elementor_Version_Resolver::site_is_v4();
                $supports     = V4_Props::is_atomic_supported();
                $kit_id       = function_exists('get_option') ? absint(get_option('elementor_active_kit')) : 0;

                $result = [
                    'elementor_version'     => $core_version,
                    'elementor_pro_version' => $pro_version,
                    'site_is_v4'            => $site_is_v4,
                    'atomic_supported'      => $supports,
                    'supports_atomic'       => $supports,
                    'global_classes_available' => (bool) ($caps['global_classes_available'] ?? false),
                    'global_variables_available' => $site_is_v4 && $kit_id > 0,
                    'elementor_active'      => (bool) ($caps['elementor_active'] ?? false),
                    'recommended_mode'      => $supports ? 'atomic' : 'legacy',
                ];

                if ($post_id > 0) {
                    $page_version = Elementor_Version_Resolver::detect_page_version($post_id);
                    $page_is_v4   = Elementor_Version_Resolver::VERSION_V4 === $page_version;

                    if ('unknown' === $page_version) {
                        $page_action = 'inspect_or_enable_elementor';
                    } elseif ($page_is_v4) {
                        $page_action = 'use_atomic';
                    } elseif ($supports) {
                        $page_action = 'convert_to_atomic';
                    } else {
                        $page_action = 'use_legacy';
                    }

                    $result['post_id'] = $post_id;
                    $result['page_version'] = $page_version;
                    $result['page_is_v4'] = $page_is_v4;
                    $result['detected'] = $page_version;
                    $result['recommended_page_action'] = $page_action;
                }

                return $result;
            },
            'permission_callback' => 'novamira_permission_callback',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'post_id' => ['type' => 'integer', 'description' => __('Optional post/page ID for page-level V3/V4 detection.', 'novamira-adrianv2')],
                ],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'elementor_version'              => ['type' => 'string'],
                    'elementor_pro_version'          => ['type' => 'string'],
                    'site_is_v4'                     => ['type' => 'boolean'],
                    'atomic_supported'               => ['type' => 'boolean'],
                    'supports_atomic'                => ['type' => 'boolean'],
                    'global_classes_available'       => ['type' => 'boolean'],
                    'global_variables_available'     => ['type' => 'boolean'],
                    'elementor_active'               => ['type' => 'boolean'],
                    'recommended_mode'               => ['type' => 'string'],
                    'post_id'                        => ['type' => 'integer'],
                    'page_version'                   => ['type' => 'string'],
                    'page_is_v4'                     => ['type' => 'boolean'],
                    'detected'                       => ['type' => 'string'],
                    'recommended_page_action'        => ['type' => 'string'],
                ],
            ],
            'meta'                => [
                'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);
    }
}
