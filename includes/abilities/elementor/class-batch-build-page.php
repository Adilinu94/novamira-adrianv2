<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers\Helpers;
use Novamira\AdrianV2\Helpers\Guards;

if (!defined('ABSPATH')) {
    exit();
}

class Batch_Build_Page {
    /**
     * Atomics: all e-* widget types
     */
    private const ATOMIC_WIDGETS = [
        'e-heading', 'e-paragraph', 'e-button', 'e-image', 'e-svg',
        'e-divider', 'e-youtube', 'e-self-hosted-video', 'e-component',
    ];

    /**
     * Containers: layout wrappers
     */
    private const CONTAINERS = [
        'container', 'section', 'column', 'e-flexbox', 'e-div-block',
    ];

    public static function register(): void {
        wp_register_ability('novamira-adrianv2/batch-build-page', [
            'label'       => 'Batch Build Page',
            'description' => 'Builds a complete Elementor V4 page from a JSON element tree in one call. Fully supports V4 Atomic Widgets (e-heading, e-paragraph, e-button, e-image, e-svg, e-divider, e-youtube) with $$type settings + local styles, AND V3 containers/widgets. Creates the page if no post_id is given. Supports page-level CSS and JS injection. Returns post ID, permalink, edit URL, and element summary. Always prefer atomic widgets for V4 pages.',
            'category'    => 'novamira-adrianv2',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'post_id'  => [ 'type' => 'integer', 'description' => 'Existing post ID to overwrite. Omit to create a new page.' ],
                    'title'    => [ 'type' => 'string',  'description' => 'Page title.' ],
                    'slug'     => [ 'type' => 'string',  'description' => 'URL slug (only for new pages).' ],
                    'status'   => [ 'type' => 'string',  'description' => 'draft (default) | publish | private', 'enum' => [ 'draft', 'publish', 'private' ] ],
                    'template' => [ 'type' => 'string',  'description' => 'WP page template. Default: elementor_header_footer' ],
                    'page_css' => [ 'type' => 'string',  'description' => 'Page-level custom CSS (injected into _elementor_page_settings.custom_css).' ],
                    'page_js'  => [ 'type' => 'string',  'description' => 'JavaScript to inject (appended as HTML widget with <script> tag).' ],
                    'elements' => [
                        'type'  => 'array',
                        'description' => 'Complete element tree. Each node: {type, id?, settings?, styles?, children?, css_id?, css_class?, attributes?}. For atomic widgets use $$type format in settings and styles map. For V3 widgets use flat settings.',
                        'items' => [ 'type' => 'object' ],
                    ],
                ],
                'required' => [ 'elements' ],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'        => [ 'type' => 'boolean' ],
                    'post_id'        => [ 'type' => 'integer' ],
                    'permalink'      => [ 'type' => 'string' ],
                    'edit_url'       => [ 'type' => 'string' ],
                    'total_elements' => [ 'type' => 'integer' ],
                    'element_ids'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'created_page'   => [ 'type' => 'boolean' ],
                    'error'          => [ 'type' => 'string' ],
                ],
            ],
            'execute_callback'    => [self::class, 'execute'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => [
                'show_in_rest' => true,
                'mcp'          => [ 'public' => true ],
                'annotations'  => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
            ],
        ]);
    }

    public static function execute($input = null) {
        $elements_input = $input['elements'] ?? [];
        if (empty($elements_input) || !is_array($elements_input)) {
            return ['success' => false, 'error' => 'elements array is required and must not be empty.'];
        }

        // V3/V4 page version guard (1.1.0): prevent accidental cross-version writes.
        $post_id = (int) ($input['post_id'] ?? 0);
        $opt_in  = (bool) ($input['opt_in'] ?? false);
        if ($post_id > 0 && !$opt_in) {
            $page_v4  = \Novamira\AdrianV2\Helpers\Elementor_Version_Resolver::page_is_v4($post_id);
            $tree_v4  = self::tree_has_v4_atomic($elements_input);
            if ($page_v4 && !$tree_v4) {
                return new \WP_Error('page_version_mismatch', __('Target page is V4 but the provided tree contains no V4 atomic elements. Pass opt_in: true to override, or convert your tree to V4 atomic format.', 'novamira-adrianv2'));
            }
            if (!$page_v4 && $tree_v4) {
                return new \WP_Error('page_version_mismatch', __('Target page is V3 but the provided tree contains V4 atomic elements. Pass opt_in: true to override, or use V3 widget types.', 'novamira-adrianv2'));
            }
        }

        // 1. Get or create the post.
        $created_page = false;
        $post_id      = isset($input['post_id']) ? (int) $input['post_id'] : 0;

        if ($post_id > 0) {
            $post = get_post($post_id);
            if (!$post) {
                return ['success' => false, 'error' => "Post {$post_id} not found."];
            }
            if (!empty($input['title'])) {
                wp_update_post(['ID' => $post_id, 'post_title' => sanitize_text_field($input['title'])]);
            }
        } else {
            $title    = !empty($input['title']) ? sanitize_text_field($input['title']) : 'Untitled Page';
            $status   = in_array($input['status'] ?? '', ['draft', 'publish', 'private'], true) ? $input['status'] : 'draft';
            $postdata = ['post_title' => $title, 'post_status' => $status, 'post_type' => 'page'];
            if (!empty($input['slug'])) {
                $postdata['post_name'] = sanitize_title($input['slug']);
            }
            $post_id = wp_insert_post($postdata, true);
            if (is_wp_error($post_id)) {
                return ['success' => false, 'error' => 'Failed to create page: ' . $post_id->get_error_message()];
            }
            $created_page = true;
        }

        // 2. Page template + Elementor mode.
        $template = !empty($input['template']) ? $input['template'] : 'elementor_header_footer';
        update_post_meta($post_id, '_wp_page_template', $template);
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');
        if (defined('ELEMENTOR_VERSION')) {
            update_post_meta($post_id, '_elementor_version', ELEMENTOR_VERSION);
        }

        // 3. Build element tree.
        $used_ids       = [];
        $all_ids        = [];
        $total_elements = 0;

        $built = [];
        foreach ($elements_input as $node) {
            $built[] = self::build_node($node, $used_ids, $all_ids, $total_elements);
        }

        // 4. Append JS widget.
        if (!empty($input['page_js'])) {
            $js    = trim($input['page_js']);
            $html  = stripos($js, '<script') !== false ? $js : "<script>\n{$js}\n</script>";
            $jid   = self::generate_uid($used_ids);
            $built[] = ['id' => $jid, 'elType' => 'widget', 'widgetType' => 'html', 'settings' => ['html' => $html], 'elements' => []];
            $all_ids[] = $jid;
            $total_elements++;
        }

        // 4b. Sanitize all local style IDs (no hyphens allowed in V4 validator)
        self::sanitize_style_ids($built);

        // 4c. Normalize style variants so they survive Elementor editor save
        // roundtrips: ensure every variant meta has state + breakpoint keys,
        // custom_css is never a plain string, image-src has at most one
        // of id/url, and plain scalars in props are $$type-wrapped.
        self::normalize_style_variants($built);
        self::normalize_image_src_values($built);
        self::normalize_attributes_values($built);
        self::normalize_style_prop_scalars($built);

        // 5. Write Elementor data.
        $encoded = wp_json_encode($built, JSON_UNESCAPED_UNICODE);
        if (false === $encoded) {
            return ['success' => false, 'error' => 'JSON encoding failed.'];
        }
        update_post_meta($post_id, '_elementor_data', wp_slash($encoded));

        // 6. Page CSS.
        if (!empty($input['page_css'])) {
            $ps = get_post_meta($post_id, '_elementor_page_settings', true);
            if (!is_array($ps)) {
                $ps = [];
            }
            $ps['custom_css'] = $input['page_css'];
            update_post_meta($post_id, '_elementor_page_settings', $ps);
        }

        // 7. Clear caches.
        Guards::invalidate_elementor_cache($post_id);

        return [
            'success'        => true,
            'post_id'        => $post_id,
            'permalink'      => get_permalink($post_id),
            'edit_url'       => admin_url('post.php?post=' . $post_id . '&action=elementor'),
            'total_elements' => $total_elements,
            'element_ids'    => $all_ids,
            'created_page'   => $created_page,
        ];
    }

    private static function build_node(array $node, array &$used, array &$all, int &$total): array {
        $type = strtolower(trim($node['type'] ?? 'container'));

        $is_atomic    = in_array($type, self::ATOMIC_WIDGETS, true);
        $is_container = in_array($type, self::CONTAINERS, true);
        // Unknown e-* types are also treated as atomic
        if (!$is_atomic && !$is_container && str_starts_with($type, 'e-')) {
            $is_atomic = true;
        }

        // Resolve ID
        $el_id = '';
        if (!empty($node['id'])) {
            $sanitized = self::sanitize_id($node['id']);
            $el_id = in_array($sanitized, $used, true) ? self::generate_uid($used) : $sanitized;
        } else {
            $el_id = self::generate_uid($used);
        }
        $used[] = $el_id;
        $all[]  = $el_id;
        $total++;

        // Base settings from input
        $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];

        // Styles map (V4: keyed object; V3: empty or absent)
        $styles = is_array($node['styles'] ?? null) ? $node['styles'] : [];

        // Build children
        $children = [];
        foreach ((array) ($node['children'] ?? []) as $child) {
            $children[] = self::build_node($child, $used, $all, $total);
        }

        // ---- ATOMIC WIDGET ----
        if ($is_atomic) {
            // For atomic widgets, settings use $$type format.
            // css_id -> _cssid prop ($$type string)
            if (!empty($node['css_id']) && !isset($settings['_cssid'])) {
                $settings['_cssid'] = ['$$type' => 'string', 'value' => sanitize_html_class($node['css_id'])];
            }
            // classes shorthand: if caller passes simple array of class IDs, convert to $$type format
            if (isset($node['classes']) && is_array($node['classes']) && !isset($settings['classes'])) {
                $settings['classes'] = ['$$type' => 'classes', 'value' => $node['classes']];
            }
            // Ensure classes key exists
            if (!isset($settings['classes'])) {
                $settings['classes'] = ['$$type' => 'classes', 'value' => []];
            }

            return [
                'id'         => $el_id,
                'elType'     => 'widget',
                'widgetType' => $type,
                'settings'   => $settings,
                'styles'     => $styles,
                'elements'   => [],  // atomic widgets have no children
            ];
        }

        // ---- CONTAINER ----
        if ($is_container) {
            // V3 CSS ID + classes on containers (classic Advanced tab fields)
            if (!empty($node['css_id']) && !isset($settings['_element_id'])) {
                $settings['_element_id'] = sanitize_html_class($node['css_id']);
            }
            if (!empty($node['css_class']) && !isset($settings['_css_classes'])) {
                $settings['_css_classes'] = sanitize_text_field($node['css_class']);
            }
            // Custom HTML attributes
            if (!empty($node['attributes']) && is_array($node['attributes']) && !isset($settings['_attributes'])) {
                $attrs = [];
                foreach ($node['attributes'] as $k => $v) {
                    $attrs[] = ['_id' => substr(md5($k), 0, 7), 'key' => sanitize_text_field($k), 'value' => $v];
                }
                $settings['_attributes'] = $attrs;
            }

            $el_type = ($type === 'e-flexbox' || $type === 'e-div-block') ? $type : 'container';
            $element = [
                'id'       => $el_id,
                'elType'   => $el_type,
                'settings' => $settings,
                'elements' => $children,
            ];
            if (!empty($styles)) {
                $element['styles'] = $styles;
            }
            return $element;
        }

        // ---- V3 WIDGET (fallback) ----
        if (!empty($node['css_id']) && !isset($settings['_element_id'])) {
            $settings['_element_id'] = sanitize_html_class($node['css_id']);
        }
        if (!empty($node['css_class']) && !isset($settings['_css_classes'])) {
            $settings['_css_classes'] = sanitize_text_field($node['css_class']);
        }
        if (!empty($node['attributes']) && is_array($node['attributes']) && !isset($settings['_attributes'])) {
            $attrs = [];
            foreach ($node['attributes'] as $k => $v) {
                $attrs[] = ['_id' => substr(md5($k), 0, 7), 'key' => sanitize_text_field($k), 'value' => $v];
            }
            $settings['_attributes'] = $attrs;
        }

        $element = [
            'id'         => $el_id,
            'elType'     => 'widget',
            'widgetType' => $type,
            'settings'   => $settings,
            'elements'   => [],
        ];
        if (!empty($styles)) {
            $element['styles'] = $styles;
        }
        return $element;
    }

    private static function generate_uid(array &$used): string {
        do {
            $id = substr(md5(uniqid('', true)), 0, 7);
        } while (in_array($id, $used, true));
        $used[] = $id;
        return $id;
    }

    private static function sanitize_id(string $id): string {
        $id = strtolower(trim($id));
        $id = preg_replace('/[^a-z0-9\-]/', '-', $id);
        $id = trim($id, '-');
        return $id ?: substr(md5(uniqid()), 0, 7);
    }

    private static function sanitize_style_id(string $id): string {
        $id = preg_replace("/[^a-z0-9_]/", "", strtolower($id));
        if ($id && !ctype_alpha($id[0])) {
            $id = "s" . $id;
        }
        return $id ?: ("s" . substr(md5(uniqid()), 0, 7));
    }

    /**
     * Walk the entire element tree and sanitize all local style IDs.
     * Removes hyphens which Elementor V4 validator rejects.
     * Runs after tree is built, before writing to DB.
     */
    private static function sanitize_style_ids(array &$elements): void {
        foreach ($elements as &$el) {
            if (empty($el['styles'])) {
                if (!empty($el['elements'])) {
                    self::sanitize_style_ids($el['elements']);
                }
                continue;
            }
            $new_styles = [];
            $id_map     = [];
            foreach ($el['styles'] as $old_sid => $sdata) {
                $new_sid            = self::sanitize_style_id($old_sid);
                $id_map[$old_sid] = $new_sid;
                if (isset($sdata['id'])) {
                    $sdata['id'] = $new_sid;
                }
                $new_styles[$new_sid] = $sdata;
            }
            $el['styles'] = $new_styles;
            // Fix references in settings.classes
            if (!empty($el['settings']['classes']['value'])) {
                $el['settings']['classes']['value'] = array_map(
                    fn($c) => $id_map[$c] ?? $c,
                    $el['settings']['classes']['value']
                );
            }
            if (!empty($el['elements'])) {
                self::sanitize_style_ids($el['elements']);
            }
        }
    }

    /**
     * Walk the tree and ensure every style variant meta has state + breakpoint
     * keys and custom_css is never a plain string (which crashes the renderer).
     * Elementor's Style_Parser rejects variants without these, so this keeps
     * API-written data valid through editor save roundtrips.
     */
    private static function normalize_style_variants(array &$elements): void {
        foreach ($elements as &$el) {
            if (!empty($el['styles'])) {
                foreach ($el['styles'] as &$style) {
                    if (empty($style['variants']) || !is_array($style['variants'])) {
                        continue;
                    }
                    foreach ($style['variants'] as &$variant) {
                        if (!is_array($variant['meta'] ?? null)) {
                            $variant['meta'] = ['breakpoint' => 'desktop', 'state' => null];
                        } else {
                            if (($variant['meta']['breakpoint'] ?? null) === null) {
                                $variant['meta']['breakpoint'] = 'desktop';
                            }
                            if (!array_key_exists('state', $variant['meta'])) {
                                $variant['meta']['state'] = null;
                            }
                        }
                        if (array_key_exists('custom_css', $variant) && is_string($variant['custom_css'])) {
                            $variant['custom_css'] = ['raw' => base64_encode($variant['custom_css'])];
                        } elseif (!array_key_exists('custom_css', $variant)) {
                            $variant['custom_css'] = null;
                        }
                    }
                }
            }
            if (!empty($el['elements'])) {
                self::normalize_style_variants($el['elements']);
            }
        }
    }

    /**
     * Walk the tree and deduplicate image-src values that carry both id AND
     * url. Image_Src_Prop_Type::validate_value() requires exactly one of them
     * (count(array_filter($value)) === 1). Prefer id over url.
     */
    private static function normalize_image_src_values(array &$elements): void {
        foreach ($elements as &$el) {
            if (!empty($el['settings']['image']['value']['src']['value'])) {
                $src =& $el['settings']['image']['value']['src']['value'];
                if (is_array($src) && array_key_exists('id', $src) && array_key_exists('url', $src)) {
                    unset($src['url']);
                }
            }
            if (!empty($el['elements'])) {
                self::normalize_image_src_values($el['elements']);
            }
        }
    }

    /**
     * Walk the tree and convert any "attributes" setting that is a plain
     * key-value map (not $$type-wrapped) into the _attributes array format
     * that Elementor's validator expects. Prevents "attributes: invalid_value"
     * errors when saving in the editor.
     */
    /**
     * Check if an element tree contains V4 atomic containers/widgets.
     *
     * @since 1.1.0
     * @param array $elements Element tree.
     * @return bool
     */
    private static function tree_has_v4_atomic(array $elements): bool {
        foreach ($elements as $el) {
            if (!is_array($el)) { continue; }
            $et = (string) ($el['elType'] ?? '');
            if (in_array($et, ['e-flexbox', 'e-div-block'], true) || str_starts_with($et, 'e-')) {
                return true;
            }
            $wt = (string) ($el['widgetType'] ?? '');
            if (str_starts_with($wt, 'e-')) { return true; }
            if (!empty($el['elements']) && is_array($el['elements'])) {
                if (self::tree_has_v4_atomic($el['elements'])) { return true; }
            }
        }
        return false;
    }

    private static function normalize_attributes_values(array &$elements): void {
        foreach ($elements as &$el) {
            if (!empty($el['settings']['attributes']) && is_array($el['settings']['attributes']) && !isset($el['settings']['attributes']['$$type'])) {
                $attrs = [];
                foreach ($el['settings']['attributes'] as $k => $v) {
                    $attrs[] = ['_id' => substr(md5($k), 0, 7), 'key' => sanitize_text_field($k), 'value' => $v];
                }
                $el['settings']['_attributes'] = $attrs;
                unset($el['settings']['attributes']);
            }
            if (!empty($el['elements'])) {
                self::normalize_attributes_values($el['elements']);
            }
        }
    }

    /**
     * Walk the tree and wrap any plain scalar values (int, float, bool) in
     * style variant props with their $$type envelope. Elementor's
     * Style_Parser rejects unwrapped scalars in variant props.
     */
    private static function normalize_style_prop_scalars(array &$elements): void {
        foreach ($elements as &$el) {
            if (!empty($el['styles'])) {
                foreach ($el['styles'] as &$style) {
                    if (empty($style['variants'])) {
                        continue;
                    }
                    foreach ($style['variants'] as &$variant) {
                        if (empty($variant['props'])) {
                            continue;
                        }
                        foreach ($variant['props'] as $pk => &$pv) {
                            if (is_int($pv) || is_float($pv)) {
                                $pv = ['$$type' => 'number', 'value' => $pv];
                            } elseif (is_bool($pv)) {
                                $pv = ['$$type' => 'boolean', 'value' => $pv];
                            } elseif (is_string($pv) && $pv !== '') {
                                $pv = ['$$type' => 'string', 'value' => $pv];
                            }
                        }
                    }
                }
            }
            if (!empty($el['elements'])) {
                self::normalize_style_prop_scalars($el['elements']);
            }
        }
    }
}

add_action('wp_abilities_api_init', [Batch_Build_Page::class, 'register']);
