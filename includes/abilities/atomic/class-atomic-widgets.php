<?php
declare(strict_types=1);

/**
 * V4 Atomic Widget Abilities — Port from EMCP class-atomic-widget-abilities.php
 *
 * Registers universal add/update tools plus convenience shortcut tools
 * for atomic widgets (e-heading, e-paragraph, e-button, e-image, etc.).
 * Only registers when Elementor V4 atomic elements are available.
 *
 * Architecture: Fully static (no constructor dependencies). Uses the
 * shared Elementor_Data_Helpers trait for page read/write/insert.
 * Zero hard dependency on Novamira Pro — all it needs is the V4 Props
 * and V4 Styles helpers from Phases 1–2.
 *
 * Key fixes from EMCP source:
 * - e-paragraph: `paragraph` prop (not `editor`) — already correct in EMCP.
 * - e-youtube / e-self-hosted-video: `source` prop (not `url`) — already correct.
 * - image(): uses image-attachment-id $$type (not number) via V4_Props.
 * - image(): Invariant IV — omits url when id is set.
 * - link(): uses destination + tag format via V4_Props.
 * - background-color / color: GV detection via V4_Styles.
 *
 * @package Extra
 * @since   1.1.0
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

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Static ability registrar for atomic widget operations.
 *
 * @since 1.1.0
 */
class Atomic_Widgets {
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
     * Register all atomic widget abilities.
     *
     * Call once from wp_abilities_api_init. Skips registration entirely
     * when Elementor V4 atomic elements are not available.
     */
    public static function register(): void {
        if (!V4_Props::is_atomic_supported()) {
            return;
        }

        self::register_add_atomic_widget();
        self::register_update_atomic_widget();
        self::register_add_atomic_heading();
        self::register_add_atomic_paragraph();
        self::register_add_atomic_button();
        self::register_add_atomic_image();
        self::register_add_atomic_svg();
        self::register_add_atomic_youtube();
        self::register_add_atomic_video();
        self::register_add_atomic_divider();
    }

    // =========================================================================
    // Element creation
    // =========================================================================

    /**
     * Creates an atomic widget element structure.
     *
     * Atomic widgets use elType='widget' + widgetType='<atomic_type>' with
     * the V4 extras (styles, interactions, editor_settings, version).
     *
     * @param string $widget_type The atomic widget type (e.g. 'e-heading').
     * @param array  $settings    The widget settings (already $$type-wrapped).
     * @return array The atomic widget element structure.
     */
    private static function create_atomic_widget(string $widget_type, array $settings = []): array {
        if (!isset($settings['classes'])) {
            $settings['classes'] = V4_Props::classes();
        }

        return [
            'id'              => self::generate_id(),
            'elType'          => 'widget',
            'widgetType'      => $widget_type,
            'isInner'         => false,
            'settings'        => $settings,
            'elements'        => [],
            'styles'          => [],
            'interactions'    => [],
            'editor_settings' => [],
            'version'         => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '',
        ];
    }

    // =========================================================================
    // Universal tools
    // =========================================================================

    private static function register_add_atomic_widget(): void {
        $name = 'novamira-adrianv2/add-atomic-widget';
        self::$ability_names[] = $name;

        wp_register_ability($name, [
            'label'               => __('Add Atomic Widget', 'novamira-adrianv2'),
            'description'         => __('Adds any Elementor 4.0+ atomic widget to a container. Settings must use the $$type prop format. For simpler usage, prefer the convenience tools (add-atomic-heading, etc.).', 'novamira-adrianv2'),
            'category'            => 'elementor',
            'execute_callback'    => [self::class, 'execute_add_atomic_widget'],
            'permission_callback' => 'novamira_permission_callback',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'post_id'     => ['type' => 'integer', 'description' => __('The post/page ID.', 'novamira-adrianv2')],
                    'parent_id'   => ['type' => 'string', 'description' => __('Parent container element ID.', 'novamira-adrianv2')],
                    'position'    => ['type' => 'integer', 'description' => __('Insert position. -1 = append.', 'novamira-adrianv2')],
                    'widget_type' => ['type' => 'string', 'description' => __('Atomic widget type name (e.g. e-heading, e-button).', 'novamira-adrianv2')],
                    'settings'    => ['type' => 'object', 'description' => __('Widget settings with $$type-wrapped values.', 'novamira-adrianv2')],
                ],
                'required'   => ['post_id', 'parent_id', 'widget_type'],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => ['element_id' => ['type' => 'string']],
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
    public static function execute_add_atomic_widget($input) {
        // V4 guard (1.1.0): atomic widgets require Elementor 4.0+.
        if (!\Novamira\AdrianV2\Helpers\Elementor_Version_Resolver::site_is_v4()) {
            return new \WP_Error(
                'v4_required',
                sprintf(
                    __('%s requires Elementor 4.0+. Detected version: %s. Use legacy widget abilities for V3 sites.', 'novamira-adrianv2'),
                    'add-atomic-widget',
                    \Novamira\AdrianV2\Helpers\Elementor_Version_Resolver::site_version_string()
                )
            );
        }

        $post_id     = absint($input['post_id'] ?? 0);
        $parent_id   = sanitize_text_field($input['parent_id'] ?? '');
        $position    = (int) ($input['position'] ?? -1);
        $widget_type = sanitize_text_field($input['widget_type'] ?? '');
        $settings    = $input['settings'] ?? [];

        if ($widget_type === '') {
            return new \WP_Error('missing_widget_type', __('widget_type is required.', 'novamira-adrianv2'));
        }

        $element = self::create_atomic_widget($widget_type, $settings);

        $page = self::read_page($post_id);
        if ($page['error'] !== null) {
            return new \WP_Error('read_failed', $page['error']);
        }

        $ok = self::insert_element($page['elements'], $parent_id, $element, $position);
        if (!$ok) {
            return new \WP_Error('not_found', "Parent element '{$parent_id}' not found in page {$post_id}.");
        }

        $save = self::write_page($post_id, $page['elements']);
        if (is_wp_error($save)) {
            return $save;
        }

        return ['element_id' => $element['id']];
    }

    private static function register_update_atomic_widget(): void {
        $name = 'novamira-adrianv2/update-atomic-widget';
        self::$ability_names[] = $name;

        wp_register_ability($name, [
            'label'               => __('Update Atomic Widget', 'novamira-adrianv2'),
            'description'         => __('Updates settings on an existing Elementor 4.0+ atomic widget. Performs a partial merge — only provided keys are changed.', 'novamira-adrianv2'),
            'category'            => 'elementor',
            'execute_callback'    => [self::class, 'execute_update_atomic_widget'],
            'permission_callback' => 'novamira_permission_callback',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'post_id'    => ['type' => 'integer', 'description' => __('The post/page ID.', 'novamira-adrianv2')],
                    'element_id' => ['type' => 'string', 'description' => __('The element ID to update.', 'novamira-adrianv2')],
                    'settings'   => ['type' => 'object', 'description' => __('Partial settings to merge ($$type-wrapped values).', 'novamira-adrianv2')],
                ],
                'required'   => ['post_id', 'element_id', 'settings'],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => ['success' => ['type' => 'boolean']],
            ],
            'meta'                => [
                'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);
    }

    /**
     * @param array $input Input parameters.
     * @return array|\WP_Error
     */
    public static function execute_update_atomic_widget($input) {
        // V4 guard (1.1.0): atomic widgets require Elementor 4.0+.
        if (!\Novamira\AdrianV2\Helpers\Elementor_Version_Resolver::site_is_v4()) {
            return new \WP_Error(
                'v4_required',
                sprintf(
                    __('%s requires Elementor 4.0+. Detected version: %s.', 'novamira-adrianv2'),
                    'update-atomic-widget',
                    \Novamira\AdrianV2\Helpers\Elementor_Version_Resolver::site_version_string()
                )
            );
        }

        $post_id    = absint($input['post_id'] ?? 0);
        $element_id = sanitize_text_field($input['element_id'] ?? '');
        $settings   = $input['settings'] ?? [];

        $page = self::read_page($post_id);
        if ($page['error'] !== null) {
            return new \WP_Error('read_failed', $page['error']);
        }

        $updated = self::update_element_settings($page['elements'], $element_id, $settings);
        if (!$updated) {
            return new \WP_Error('not_found', "Element '{$element_id}' not found in page {$post_id}.");
        }

        $save = self::write_page($post_id, $page['elements']);
        if (is_wp_error($save)) {
            return $save;
        }

        return ['success' => true];
    }

    // =========================================================================
    // Convenience tools — shared registration helper
    // =========================================================================

    /**
     * Shared registration for atomic convenience tools.
     *
     * @param string   $name         Tool name without prefix.
     * @param string   $label        Human-readable label.
     * @param string   $description  Tool description.
     * @param array    $extra_props  Additional JSON Schema properties.
     * @param array    $required     Additional required fields.
     * @param string   $widget_type  The atomic widget type (e.g. 'e-heading').
     * @param callable $settings_fn  Builds $$type settings from flat input.
     */
    private static function register_atomic_convenience(
        string $name,
        string $label,
        string $description,
        array $extra_props,
        array $required,
        string $widget_type,
        callable $settings_fn
    ): void {
        $full_name = 'novamira-adrianv2/' . $name;
        self::$ability_names[] = $full_name;

        $base_props = [
            'post_id'   => ['type' => 'integer', 'description' => __('The post/page ID.', 'novamira-adrianv2')],
            'parent_id' => ['type' => 'string', 'description' => __('Parent container element ID (e-flexbox or e-div-block).', 'novamira-adrianv2')],
            'position'  => ['type' => 'integer', 'description' => __('Insert position. -1 = append.', 'novamira-adrianv2')],
        ];

        $all_required = array_unique(array_merge(['post_id', 'parent_id'], $required));

        wp_register_ability($full_name, [
            'label'               => $label,
            'description'         => $description,
            'category'            => 'elementor',
            'execute_callback'    => function ($input) use ($widget_type, $settings_fn) {
                $settings = $settings_fn($input);
                $element  = self::create_atomic_widget($widget_type, $settings);

                // Apply styles if style params are present.
                $common_css = V4_Styles::build_common_props($input);
                if (!empty($common_css)) {
                    $style = V4_Styles::create_local_class($element['id'], $common_css);
                    V4_Styles::apply_to_element($element, $style['class_id'], $style['style_def']);
                }

                $post_id   = absint($input['post_id'] ?? 0);
                $parent_id = sanitize_text_field($input['parent_id'] ?? '');
                $position  = (int) ($input['position'] ?? -1);

                $page = self::read_page($post_id);
                if ($page['error'] !== null) {
                    return new \WP_Error('read_failed', $page['error']);
                }

                $ok = self::insert_element($page['elements'], $parent_id, $element, $position);
                if (!$ok) {
                    return new \WP_Error('not_found', "Parent element '{$parent_id}' not found in page {$post_id}.");
                }

                $save = self::write_page($post_id, $page['elements']);
                if (is_wp_error($save)) {
                    return $save;
                }

                return ['element_id' => $element['id']];
            },
            'permission_callback' => 'novamira_permission_callback',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => array_merge($base_props, $extra_props),
                'required'   => $all_required,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => ['element_id' => ['type' => 'string']],
            ],
            'meta'                => [
                'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);
    }

    // =========================================================================
    // Convenience tools — individual registrations
    // =========================================================================

    private static function register_add_atomic_heading(): void {
        self::register_atomic_convenience(
            'add-atomic-heading',
            __('Add Atomic Heading', 'novamira-adrianv2'),
            __('Adds an Elementor 4.0 atomic heading element. Accepts plain text and tag; $$type wrapping is handled automatically.', 'novamira-adrianv2'),
            [
                'title'  => ['type' => 'string', 'description' => __('Heading text content.', 'novamira-adrianv2')],
                'tag'    => ['type' => 'string', 'enum' => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], 'description' => __('HTML tag. Default: h2.', 'novamira-adrianv2')],
                'link'   => ['type' => 'string', 'description' => __('Optional URL to link the heading.', 'novamira-adrianv2')],
                'css_id' => ['type' => 'string', 'description' => __('Optional CSS ID for the element.', 'novamira-adrianv2')],
            ],
            [],
            'e-heading',
            function ($input) {
                $settings = [];
                $settings['title'] = V4_Props::html(sanitize_text_field($input['title'] ?? 'Heading'));
                $settings['tag']   = V4_Props::string(sanitize_text_field($input['tag'] ?? 'h2'));

                if (!empty($input['link'])) {
                    $settings['link'] = V4_Props::link(esc_url_raw($input['link']));
                }
                if (!empty($input['css_id'])) {
                    $settings['_cssid'] = V4_Props::string(sanitize_text_field($input['css_id']));
                }

                $settings['classes'] = V4_Props::classes();
                return $settings;
            }
        );
    }

    private static function register_add_atomic_paragraph(): void {
        self::register_atomic_convenience(
            'add-atomic-paragraph',
            __('Add Atomic Paragraph', 'novamira-adrianv2'),
            __('Adds an Elementor 4.0 atomic paragraph element. The content prop is named `paragraph` (not `text` or `editor`) — the correct V4 prop name.', 'novamira-adrianv2'),
            [
                'content' => ['type' => 'string', 'description' => __('Paragraph text content.', 'novamira-adrianv2')],
                'link'    => ['type' => 'string', 'description' => __('Optional URL to link the paragraph.', 'novamira-adrianv2')],
                'css_id'  => ['type' => 'string', 'description' => __('Optional CSS ID.', 'novamira-adrianv2')],
            ],
            [],
            'e-paragraph',
            function ($input) {
                $settings = [];
                // The e-paragraph widget's content prop is `paragraph` (Html_V3),
                // not `text` or `editor`. Writing `text` or `editor` silently
                // drops the content — confirmed by Elementor atomic-paragraph.php.
                $settings['paragraph'] = V4_Props::html(sanitize_text_field($input['content'] ?? 'Paragraph text'));

                if (!empty($input['link'])) {
                    $settings['link'] = V4_Props::link(esc_url_raw($input['link']));
                }
                if (!empty($input['css_id'])) {
                    $settings['_cssid'] = V4_Props::string(sanitize_text_field($input['css_id']));
                }

                $settings['classes'] = V4_Props::classes();
                return $settings;
            }
        );
    }

    private static function register_add_atomic_button(): void {
        self::register_atomic_convenience(
            'add-atomic-button',
            __('Add Atomic Button', 'novamira-adrianv2'),
            __('Adds an Elementor 4.0 atomic button element.', 'novamira-adrianv2'),
            [
                'text'         => ['type' => 'string', 'description' => __('Button label text.', 'novamira-adrianv2')],
                'link'         => ['type' => 'string', 'description' => __('Button URL.', 'novamira-adrianv2')],
                'target_blank' => ['type' => 'boolean', 'description' => __('Open in new tab.', 'novamira-adrianv2')],
                'css_id'       => ['type' => 'string', 'description' => __('Optional CSS ID.', 'novamira-adrianv2')],
            ],
            [],
            'e-button',
            function ($input) {
                $settings = [];
                $settings['text'] = V4_Props::html(sanitize_text_field($input['text'] ?? 'Click Here'));

                if (!empty($input['link'])) {
                    $target_blank = !empty($input['target_blank']);
                    $settings['link'] = V4_Props::link(esc_url_raw($input['link']), $target_blank);
                }
                if (!empty($input['css_id'])) {
                    $settings['_cssid'] = V4_Props::string(sanitize_text_field($input['css_id']));
                }

                $settings['classes'] = V4_Props::classes();
                return $settings;
            }
        );
    }

    private static function register_add_atomic_image(): void {
        self::register_atomic_convenience(
            'add-atomic-image',
            __('Add Atomic Image', 'novamira-adrianv2'),
            __('Adds an Elementor 4.0 atomic image element. Provide either image_id (from media library) or image_url. Uses image-attachment-id $$type for V4 compatibility (Invariant IV: url omitted when id is set).', 'novamira-adrianv2'),
            [
                'image_id'  => ['type' => 'integer', 'description' => __('WordPress media library attachment ID.', 'novamira-adrianv2')],
                'image_url' => ['type' => 'string', 'description' => __('Image URL (if not using media library).', 'novamira-adrianv2')],
                'alt'       => ['type' => 'string', 'description' => __('Alt text for the image.', 'novamira-adrianv2')],
                'link'      => ['type' => 'string', 'description' => __('Optional link URL.', 'novamira-adrianv2')],
                'css_id'    => ['type' => 'string', 'description' => __('Optional CSS ID.', 'novamira-adrianv2')],
            ],
            [],
            'e-image',
            function ($input) {
                $settings = [];

                $image_id  = absint($input['image_id'] ?? 0);
                $image_url = esc_url_raw($input['image_url'] ?? '');

                if ($image_id) {
                    $url = wp_get_attachment_url($image_id);
                    // Invariant IV: image() omits url when id > 0.
                    $settings['image'] = V4_Props::image($image_id, $url ?: '');
                } elseif ($image_url) {
                    $settings['image'] = V4_Props::image(0, $image_url);
                }

                if (!empty($input['alt'])) {
                    $settings['alt'] = V4_Props::string(sanitize_text_field($input['alt']));
                }
                if (!empty($input['link'])) {
                    $settings['link'] = V4_Props::link(esc_url_raw($input['link']));
                }
                if (!empty($input['css_id'])) {
                    $settings['_cssid'] = V4_Props::string(sanitize_text_field($input['css_id']));
                }

                $settings['classes'] = V4_Props::classes();
                return $settings;
            }
        );
    }

    private static function register_add_atomic_svg(): void {
        self::register_atomic_convenience(
            'add-atomic-svg',
            __('Add Atomic SVG', 'novamira-adrianv2'),
            __('Adds an Elementor 4.0 atomic SVG element.', 'novamira-adrianv2'),
            [
                'svg_id'  => ['type' => 'integer', 'description' => __('WordPress media library SVG attachment ID.', 'novamira-adrianv2')],
                'svg_url' => ['type' => 'string', 'description' => __('SVG URL (if not using media library).', 'novamira-adrianv2')],
                'css_id'  => ['type' => 'string', 'description' => __('Optional CSS ID.', 'novamira-adrianv2')],
            ],
            [],
            'e-svg',
            function ($input) {
                $settings = [];

                $svg_id  = absint($input['svg_id'] ?? 0);
                $svg_url = esc_url_raw($input['svg_url'] ?? '');

                if ($svg_id) {
                    $url = wp_get_attachment_url($svg_id);
                    $settings['svg'] = V4_Props::image($svg_id, $url ?: '');
                } elseif ($svg_url) {
                    $settings['svg'] = V4_Props::image(0, $svg_url);
                }

                if (!empty($input['css_id'])) {
                    $settings['_cssid'] = V4_Props::string(sanitize_text_field($input['css_id']));
                }

                $settings['classes'] = V4_Props::classes();
                return $settings;
            }
        );
    }

    private static function register_add_atomic_youtube(): void {
        self::register_atomic_convenience(
            'add-atomic-youtube',
            __('Add Atomic YouTube', 'novamira-adrianv2'),
            __('Adds an Elementor 4.0 atomic YouTube video element. The video URL is stored under the `source` prop (not `url`).', 'novamira-adrianv2'),
            [
                'video_url' => ['type' => 'string', 'description' => __('YouTube video URL.', 'novamira-adrianv2')],
                'css_id'    => ['type' => 'string', 'description' => __('Optional CSS ID.', 'novamira-adrianv2')],
            ],
            ['video_url'],
            'e-youtube',
            function ($input) {
                $settings = [];
                // e-youtube's video prop is `source` (a String prop), not `url`
                // — confirmed by Elementor's atomic widget schema.
                $settings['source'] = V4_Props::string(esc_url_raw($input['video_url'] ?? ''));

                if (!empty($input['css_id'])) {
                    $settings['_cssid'] = V4_Props::string(sanitize_text_field($input['css_id']));
                }

                $settings['classes'] = V4_Props::classes();
                return $settings;
            }
        );
    }

    private static function register_add_atomic_video(): void {
        self::register_atomic_convenience(
            'add-atomic-video',
            __('Add Atomic Video', 'novamira-adrianv2'),
            __('Adds an Elementor 4.0 atomic self-hosted video element. The video URL/video ID is stored under the `source` prop (not `url`).', 'novamira-adrianv2'),
            [
                'video_url' => ['type' => 'string', 'description' => __('Self-hosted video URL.', 'novamira-adrianv2')],
                'video_id'  => ['type' => 'integer', 'description' => __('Media library video attachment ID.', 'novamira-adrianv2')],
                'css_id'    => ['type' => 'string', 'description' => __('Optional CSS ID.', 'novamira-adrianv2')],
            ],
            [],
            'e-self-hosted-video',
            function ($input) {
                $settings = [];

                $video_id  = absint($input['video_id'] ?? 0);
                $video_url = esc_url_raw($input['video_url'] ?? '');

                if ($video_id) {
                    $url = wp_get_attachment_url($video_id);
                    $settings['source'] = V4_Props::url($url ?: '');
                } elseif ($video_url) {
                    $settings['source'] = V4_Props::url($video_url);
                }

                if (!empty($input['css_id'])) {
                    $settings['_cssid'] = V4_Props::string(sanitize_text_field($input['css_id']));
                }

                $settings['classes'] = V4_Props::classes();
                return $settings;
            }
        );
    }

    private static function register_add_atomic_divider(): void {
        self::register_atomic_convenience(
            'add-atomic-divider',
            __('Add Atomic Divider', 'novamira-adrianv2'),
            __('Adds an Elementor 4.0 atomic divider element.', 'novamira-adrianv2'),
            [
                'css_id' => ['type' => 'string', 'description' => __('Optional CSS ID.', 'novamira-adrianv2')],
            ],
            [],
            'e-divider',
            function ($input) {
                $settings = [];

                if (!empty($input['css_id'])) {
                    $settings['_cssid'] = V4_Props::string(sanitize_text_field($input['css_id']));
                }

                $settings['classes'] = V4_Props::classes();
                return $settings;
            }
        );
    }
}
