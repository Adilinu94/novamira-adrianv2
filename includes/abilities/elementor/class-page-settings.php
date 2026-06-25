<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers;
use Novamira\AdrianV2\Helpers\Guards;

if (!defined('ABSPATH')) {
    exit();
}

class Page_Settings {
    public static function register(): void {
        wp_register_ability('novamira-adrianv2/page-settings', [
            'label'               => 'Page Settings',
            'description'         => 'Reads or updates Elementor page-level settings: page template, title visibility, custom CSS, body classes, and arbitrary _elementor_page_settings keys.',
            'category'            => 'adrianv2-elementor',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'action' => [
                        'type'        => 'string',
                        'description' => 'Action: "get" to read settings, "set" to update.',
                        'enum'        => [ 'get', 'set' ],
                    ],
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'The post ID to read or update settings for.',
                    ],
                    'template' => [
                        'type'        => 'string',
                        'description' => 'Page template (set action only). Values: "default", "elementor_canvas", "elementor_header_footer", or a theme page-template filename.',
                    ],
                    'validate_template' => [
                        'type'        => 'boolean',
                        'description' => 'Validate template against Elementor and active-theme templates before saving. Defaults to true.',
                    ],
                    'hide_title' => [
                        'type'        => 'string',
                        'enum'        => [ 'yes', 'no' ],
                        'description' => 'Hide the page title on frontend (set action only).',
                    ],
                    'custom_css' => [
                        'type'        => 'string',
                        'description' => 'Page-level custom CSS (set action only, Pro feature).',
                    ],
                    'body_classes' => [
                        'type'        => 'string',
                        'description' => 'Space-separated body CSS classes to add to the page (set action only). Classes are sanitized with sanitize_html_class().',
                    ],
                    'page_settings' => [
                        'type'                 => 'object',
                        'description'          => 'Additional _elementor_page_settings keys to merge. Existing keys are preserved unless overwritten here.',
                        'additionalProperties' => true,
                    ],
                    'clear_keys' => [
                        'type'        => 'array',
                        'description' => 'Optional _elementor_page_settings keys to remove.',
                        'items'       => [ 'type' => 'string' ],
                    ],
                ],
                'required'   => [ 'action', 'post_id' ],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'data'    => [
                        'type'       => 'object',
                        'properties' => [
                            'post_id'             => [ 'type' => 'integer' ],
                            'post_title'          => [ 'type' => 'string' ],
                            'permalink'           => [ 'type' => 'string' ],
                            'template'            => [ 'type' => 'string' ],
                            'hide_title'          => [ 'type' => 'string' ],
                            'custom_css'          => [ 'type' => 'string' ],
                            'body_classes'        => [ 'type' => 'string' ],
                            'page_settings'       => [ 'type' => 'object' ],
                            'available_templates' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                            'edit_mode'           => [ 'type' => 'string' ],
                            'template_type'       => [ 'type' => 'string' ],
                            'version'             => [ 'type' => 'string' ],
                            'changed'             => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                            'cleared'             => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                        ],
                    ],
                    'error'   => [ 'type' => 'string' ],
                ],
            ],
            'execute_callback'    => [self::class, 'execute'],
            'permission_callback' => 'novamira_permission_callback',
            'meta'                => [
                'show_in_rest' => true,
                'mcp'          => [ 'public' => true ],
                'annotations'  => [
                    'readonly'    => false,
                    'destructive' => false,
                    'idempotent'  => true,
                ],
            ],
        ]);
    }

    public static function execute($input = null) {
        $post_id = (int) $input['post_id'];
        $action  = isset($input['action']) ? (string) $input['action'] : '';

        $post = get_post($post_id);
        if (!$post) {
            return [
                'success' => false,
                'error'   => sprintf('Post with ID %d not found.', $post_id),
            ];
        }

        if ('get' === $action) {
            return self::page_settings_response($post);
        }

        if ('set' !== $action) {
            return [
                'success' => false,
                'error'   => sprintf('Unknown action "%s". Use "get" or "set".', $action),
            ];
        }

        $changed       = [];
        $cleared       = [];
        $page_settings = self::get_elementor_page_settings($post_id);

        if (array_key_exists('template', $input)) {
            $template = self::normalize_page_template($input['template']);
            $validate = !array_key_exists('validate_template', $input) || (bool) $input['validate_template'];

            if ($validate && !in_array($template, self::get_allowed_page_templates($post), true)) {
                return [
                    'success' => false,
                    'error'   => sprintf('Invalid page template "%s" for post type "%s".', $template, $post->post_type),
                    'data'    => [
                        'post_id'             => $post_id,
                        'available_templates' => self::get_allowed_page_templates($post),
                    ],
                ];
            }

            update_post_meta($post_id, '_wp_page_template', $template);
            $changed[] = 'template';
        }

        if (array_key_exists('hide_title', $input)) {
            if (!in_array($input['hide_title'], ['yes', 'no'], true)) {
                return [
                    'success' => false,
                    'error'   => 'hide_title must be "yes" or "no".',
                ];
            }

            $page_settings['hide_title'] = $input['hide_title'];
            $changed[] = 'hide_title';
        }

        if (array_key_exists('custom_css', $input)) {
            $page_settings['custom_css'] = (string) $input['custom_css'];
            $changed[] = 'custom_css';
        }

        if (array_key_exists('body_classes', $input)) {
            $page_settings['body_classes'] = self::sanitize_body_classes((string) $input['body_classes']);
            $changed[] = 'body_classes';
        }

        if (array_key_exists('page_settings', $input)) {
            if (!is_array($input['page_settings'])) {
                return [
                    'success' => false,
                    'error'   => 'page_settings must be an object.',
                ];
            }

            foreach ($input['page_settings'] as $key => $value) {
                $key = (string) $key;
                if (!preg_match('/^[A-Za-z0-9_\-]+$/', $key)) {
                    return [
                        'success' => false,
                        'error'   => sprintf('Invalid page_settings key "%s".', $key),
                    ];
                }

                $page_settings[$key] = $value;
                $changed[] = 'page_settings.' . $key;
            }
        }

        if (array_key_exists('clear_keys', $input)) {
            if (!is_array($input['clear_keys'])) {
                return [
                    'success' => false,
                    'error'   => 'clear_keys must be an array of strings.',
                ];
            }

            foreach ($input['clear_keys'] as $key) {
                $key = (string) $key;
                if (array_key_exists($key, $page_settings)) {
                    unset($page_settings[$key]);
                    $cleared[] = $key;
                }
            }
        }

        if (!empty($changed) || !empty($cleared)) {
            update_post_meta($post_id, '_elementor_page_settings', $page_settings);
            Guards::invalidate_elementor_cache($post_id);
        }

        $response = self::page_settings_response($post);
        $response['data']['changed'] = array_values(array_unique($changed));
        $response['data']['cleared'] = array_values(array_unique($cleared));

        return $response;
    }

    private static function page_settings_response(\WP_Post $post): array {
        $post_id       = (int) $post->ID;
        $page_settings = self::get_elementor_page_settings($post_id);

        return [
            'success' => true,
            'data'    => [
                'post_id'             => $post_id,
                'post_title'          => get_the_title($post),
                'permalink'           => get_permalink($post),
                'template'            => self::normalize_page_template(get_post_meta($post_id, '_wp_page_template', true)),
                'hide_title'          => $page_settings['hide_title'] ?? 'no',
                'custom_css'          => $page_settings['custom_css'] ?? '',
                'body_classes'        => $page_settings['body_classes'] ?? '',
                'page_settings'       => $page_settings,
                'available_templates' => self::get_allowed_page_templates($post),
                'edit_mode'           => get_post_meta($post_id, '_elementor_edit_mode', true),
                'template_type'       => get_post_meta($post_id, '_elementor_template_type', true),
                'version'             => get_post_meta($post_id, '_elementor_version', true),
            ],
        ];
    }

    private static function get_elementor_page_settings(int $post_id): array {
        $page_settings = get_post_meta($post_id, '_elementor_page_settings', true);
        return is_array($page_settings) ? $page_settings : [];
    }

    private static function normalize_page_template(string $template): string {
        $template = trim($template);
        return '' === $template ? 'default' : $template;
    }

    private static function get_allowed_page_templates(\WP_Post $post): array {
        $templates = ['default', 'elementor_canvas', 'elementor_header_footer'];
        $theme     = wp_get_theme();

        foreach ($theme->get_page_templates($post, $post->post_type) as $file => $label) {
            $templates[] = (string) $file;
        }

        $current_template = self::normalize_page_template(get_post_meta($post->ID, '_wp_page_template', true));
        if ($current_template && !in_array($current_template, $templates, true)) {
            $templates[] = $current_template;
        }

        return array_values(array_unique($templates));
    }

    private static function sanitize_body_classes(string $classes): string {
        $classes = preg_split('/\s+/', trim($classes));
        $classes = array_filter(array_map('sanitize_html_class', $classes));
        return implode(' ', array_values(array_unique($classes)));
    }
}

add_action('wp_abilities_api_init', [Page_Settings::class, 'register']);
