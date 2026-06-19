<?php
/**
 * Ability: Elementor Pro Features
 *
 * Elementor Pro-specific abilities: custom code CRUD, form submissions management,
 * and theme builder display conditions.
 *
 * All abilities gracefully degrade when Elementor Pro is not active,
 * using direct post-meta/option access as fallback.
 *
 * Built for Novamira AdrianV2.
 */
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\ElementorPro;

if (!defined('ABSPATH')) {
    exit();
}

class Pro_Features
{
    private const CPT_CUSTOM_CODE = 'elementor_snippet';
    private const CPT_FORM_SUBMISSION = 'e_submission';
    private const META_CONDITIONS = '_elementor_conditions';

    public static function register(): void
    {
        $common = ['show_in_rest' => true, 'mcp' => ['public' => true]];
        $read  = ['readonly' => true,  'destructive' => false, 'idempotent' => true];
        $write = ['readonly' => false, 'destructive' => true,  'idempotent' => true];

        // -- Custom Code --
        wp_register_ability('novamira-adrianv2/list-custom-code', [
            'label' => 'List Custom Code', 'description' => 'Lists all Elementor Pro custom code snippets.',
            'category' => 'adrianv2-pro',
            'input_schema' => ['type' => 'object', 'properties' => [
                'status' => ['type' => 'string', 'enum' => ['publish', 'draft', 'any'], 'description' => 'Filter by status. Default: any.'],
            ], 'required' => []],
            'output_schema' => ['type' => 'object', 'properties' => [
                'success' => ['type' => 'boolean'], 'snippets' => ['type' => 'array', 'items' => ['type' => 'object']],
                'total' => ['type' => 'integer'], 'summary' => ['type' => 'string'],
            ]],
            'execute_callback' => [self::class, 'execute_list_custom_code'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common, ['annotations' => $read]),
        ]);

        wp_register_ability('novamira-adrianv2/get-custom-code', [
            'label' => 'Get Custom Code', 'description' => 'Retrieves a single Elementor Pro custom code snippet by ID.',
            'category' => 'adrianv2-pro',
            'input_schema' => ['type' => 'object', 'properties' => [
                'snippet_id' => ['type' => 'integer', 'description' => 'Snippet post ID.'],
            ], 'required' => ['snippet_id']],
            'output_schema' => ['type' => 'object', 'properties' => [
                'success' => ['type' => 'boolean'], 'snippet' => ['type' => 'object'], 'summary' => ['type' => 'string'],
            ]],
            'execute_callback' => [self::class, 'execute_get_custom_code'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common, ['annotations' => $read]),
        ]);

        wp_register_ability('novamira-adrianv2/create-custom-code', [
            'label' => 'Create Custom Code', 'description' => 'Creates a new Elementor Pro custom code snippet.',
            'category' => 'adrianv2-pro',
            'input_schema' => ['type' => 'object', 'properties' => [
                'title' => ['type' => 'string', 'description' => 'Snippet title.'],
                'code' => ['type' => 'string', 'description' => 'The code content (CSS/JS/HTML).'],
                'language' => ['type' => 'string', 'enum' => ['css', 'js', 'html'], 'description' => 'Code language.'],
                'location' => ['type' => 'string', 'enum' => ['head', 'body_start', 'body_end'], 'description' => 'Where to output. Default: head.'],
                'status' => ['type' => 'string', 'enum' => ['publish', 'draft'], 'description' => 'Default: publish.'],
            ], 'required' => ['title', 'code']],
            'output_schema' => ['type' => 'object', 'properties' => [
                'success' => ['type' => 'boolean'], 'snippet_id' => ['type' => 'integer'], 'summary' => ['type' => 'string'],
            ]],
            'execute_callback' => [self::class, 'execute_create_custom_code'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common, ['annotations' => $write]),
        ]);

        wp_register_ability('novamira-adrianv2/update-custom-code', [
            'label' => 'Update Custom Code', 'description' => 'Updates an existing Elementor Pro custom code snippet.',
            'category' => 'adrianv2-pro',
            'input_schema' => ['type' => 'object', 'properties' => [
                'snippet_id' => ['type' => 'integer', 'description' => 'Snippet post ID.'],
                'title' => ['type' => 'string'], 'code' => ['type' => 'string'],
                'language' => ['type' => 'string', 'enum' => ['css', 'js', 'html']],
                'location' => ['type' => 'string', 'enum' => ['head', 'body_start', 'body_end']],
                'status' => ['type' => 'string', 'enum' => ['publish', 'draft']],
            ], 'required' => ['snippet_id']],
            'output_schema' => ['type' => 'object', 'properties' => [
                'success' => ['type' => 'boolean'], 'updated' => ['type' => 'array', 'items' => ['type' => 'string']], 'summary' => ['type' => 'string'],
            ]],
            'execute_callback' => [self::class, 'execute_update_custom_code'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common, ['annotations' => $write]),
        ]);

        wp_register_ability('novamira-adrianv2/delete-custom-code', [
            'label' => 'Delete Custom Code', 'description' => 'Deletes an Elementor Pro custom code snippet.',
            'category' => 'adrianv2-pro',
            'input_schema' => ['type' => 'object', 'properties' => [
                'snippet_id' => ['type' => 'integer', 'description' => 'Snippet post ID.'],
                'force_delete' => ['type' => 'boolean', 'description' => 'Permanently delete. Default: false (trash).'],
            ], 'required' => ['snippet_id']],
            'output_schema' => ['type' => 'object', 'properties' => [
                'success' => ['type' => 'boolean'], 'trashed' => ['type' => 'boolean'], 'summary' => ['type' => 'string'],
            ]],
            'execute_callback' => [self::class, 'execute_delete_custom_code'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common, ['annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false]]),
        ]);

        // -- Form Submissions --
        wp_register_ability('novamira-adrianv2/list-form-submissions', [
            'label' => 'List Form Submissions', 'description' => 'Lists Elementor Pro form submissions with optional form filter and limit.',
            'category' => 'adrianv2-pro',
            'input_schema' => ['type' => 'object', 'properties' => [
                'form_id' => ['type' => 'string', 'description' => 'Filter by form ID. Omit for all.'],
                'limit' => ['type' => 'integer', 'description' => 'Max results. Default: 50.'],
                'offset' => ['type' => 'integer', 'description' => 'Pagination offset. Default: 0.'],
            ], 'required' => []],
            'output_schema' => ['type' => 'object', 'properties' => [
                'success' => ['type' => 'boolean'], 'submissions' => ['type' => 'array', 'items' => ['type' => 'object']],
                'total' => ['type' => 'integer'], 'summary' => ['type' => 'string'],
            ]],
            'execute_callback' => [self::class, 'execute_list_form_submissions'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common, ['annotations' => $read]),
        ]);

        wp_register_ability('novamira-adrianv2/get-form-submission', [
            'label' => 'Get Form Submission', 'description' => 'Retrieves a single Elementor Pro form submission with all field values.',
            'category' => 'adrianv2-pro',
            'input_schema' => ['type' => 'object', 'properties' => [
                'submission_id' => ['type' => 'integer', 'description' => 'Submission ID.'],
            ], 'required' => ['submission_id']],
            'output_schema' => ['type' => 'object', 'properties' => [
                'success' => ['type' => 'boolean'], 'submission' => ['type' => 'object'], 'summary' => ['type' => 'string'],
            ]],
            'execute_callback' => [self::class, 'execute_get_form_submission'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common, ['annotations' => $read]),
        ]);

        wp_register_ability('novamira-adrianv2/delete-form-submission', [
            'label' => 'Delete Form Submission', 'description' => 'Permanently deletes an Elementor Pro form submission.',
            'category' => 'adrianv2-pro',
            'input_schema' => ['type' => 'object', 'properties' => [
                'submission_id' => ['type' => 'integer', 'description' => 'Submission ID.'],
            ], 'required' => ['submission_id']],
            'output_schema' => ['type' => 'object', 'properties' => [
                'success' => ['type' => 'boolean'], 'summary' => ['type' => 'string'],
            ]],
            'execute_callback' => [self::class, 'execute_delete_form_submission'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common, ['annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false]]),
        ]);

        // -- Theme Builder Conditions --
        wp_register_ability('novamira-adrianv2/get-theme-builder-conditions', [
            'label' => 'Get Theme Builder Conditions', 'description' => 'Retrieves the display conditions for an Elementor Theme Builder template.',
            'category' => 'adrianv2-pro',
            'input_schema' => ['type' => 'object', 'properties' => [
                'template_id' => ['type' => 'integer', 'description' => 'Template post ID.'],
            ], 'required' => ['template_id']],
            'output_schema' => ['type' => 'object', 'properties' => [
                'success' => ['type' => 'boolean'], 'template_id' => ['type' => 'integer'],
                'template_type' => ['type' => 'string'], 'title' => ['type' => 'string'],
                'conditions' => ['type' => 'array'], 'summary' => ['type' => 'string'],
            ]],
            'execute_callback' => [self::class, 'execute_get_conditions'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common, ['annotations' => $read]),
        ]);

        wp_register_ability('novamira-adrianv2/update-theme-builder-conditions', [
            'label' => 'Update Theme Builder Conditions', 'description' => 'Updates the display conditions for an Elementor Theme Builder template.',
            'category' => 'adrianv2-pro',
            'input_schema' => ['type' => 'object', 'properties' => [
                'template_id' => ['type' => 'integer', 'description' => 'Template post ID.'],
                'conditions' => ['type' => 'array', 'description' => 'New conditions array.'],
            ], 'required' => ['template_id', 'conditions']],
            'output_schema' => ['type' => 'object', 'properties' => [
                'success' => ['type' => 'boolean'], 'summary' => ['type' => 'string'],
            ]],
            'execute_callback' => [self::class, 'execute_update_conditions'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common, ['annotations' => $write]),
        ]);
    }

    // ============================================================
    // CUSTOM CODE
    // ============================================================

    public static function execute_list_custom_code($input = null)
    {
        $status = (string) ($input['status'] ?? 'any');
        if (!post_type_exists(self::CPT_CUSTOM_CODE)) {
            return ['success' => true, 'snippets' => [], 'total' => 0, 'summary' => 'Elementor Pro custom code post type not registered. Is Elementor Pro active?'];
        }
        $posts = get_posts(['post_type' => self::CPT_CUSTOM_CODE, 'posts_per_page' => -1, 'post_status' => $status]);
        $snippets = array_map([self::class, 'format_custom_code'], $posts);
        return ['success' => true, 'snippets' => $snippets, 'total' => count($snippets), 'summary' => count($snippets) . ' custom code snippet(s) found.'];
    }

    public static function execute_get_custom_code($input = null)
    {
        $id = (int) ($input['snippet_id'] ?? 0);
        $post = get_post($id);
        if (!$post || $post->post_type !== self::CPT_CUSTOM_CODE) {
            return ['success' => false, 'summary' => "Custom code snippet {$id} not found."];
        }
        return ['success' => true, 'snippet' => self::format_custom_code($post), 'summary' => "Snippet \"{$post->post_title}\" retrieved."];
    }

    public static function execute_create_custom_code($input = null)
    {
        $title = trim((string) ($input['title'] ?? ''));
        $code = (string) ($input['code'] ?? '');
        if ($title === '' || $code === '') {
            return ['success' => false, 'summary' => 'Title and code are required.'];
        }
        if (!post_type_exists(self::CPT_CUSTOM_CODE)) {
            return ['success' => false, 'summary' => 'Elementor Pro custom code not available.'];
        }
        $post_id = wp_insert_post([
            'post_title' => $title, 'post_content' => $code,
            'post_type' => self::CPT_CUSTOM_CODE, 'post_status' => (string) ($input['status'] ?? 'publish'),
            'meta_input' => [
                '_elementor_custom_code_language' => (string) ($input['language'] ?? 'css'),
                '_elementor_custom_code_location' => (string) ($input['location'] ?? 'head'),
            ],
        ], true);
        if (is_wp_error($post_id)) {
            return ['success' => false, 'summary' => $post_id->get_error_message()];
        }
        return ['success' => true, 'snippet_id' => $post_id, 'summary' => "Custom code \"{$title}\" created (#{$post_id})."];
    }

    public static function execute_update_custom_code($input = null)
    {
        $id = (int) ($input['snippet_id'] ?? 0);
        $post = get_post($id);
        if (!$post || $post->post_type !== self::CPT_CUSTOM_CODE) {
            return ['success' => false, 'summary' => "Snippet {$id} not found."];
        }
        $updated = [];
        $post_data = ['ID' => $id];
        if (isset($input['title']))  { $post_data['post_title'] = trim((string) $input['title']); $updated[] = 'title'; }
        if (isset($input['code']))   { $post_data['post_content'] = (string) $input['code']; $updated[] = 'code'; }
        if (isset($input['status'])) { $post_data['post_status'] = (string) $input['status']; $updated[] = 'status'; }
        if (count($post_data) > 1) { wp_update_post($post_data); }
        if (isset($input['language'])) { update_post_meta($id, '_elementor_custom_code_language', (string) $input['language']); $updated[] = 'language'; }
        if (isset($input['location'])) { update_post_meta($id, '_elementor_custom_code_location', (string) $input['location']); $updated[] = 'location'; }
        if (empty($updated)) {
            return ['success' => false, 'summary' => 'No fields to update.'];
        }
        return ['success' => true, 'updated' => $updated, 'summary' => 'Updated: ' . implode(', ', $updated) . '.'];
    }

    public static function execute_delete_custom_code($input = null)
    {
        $id = (int) ($input['snippet_id'] ?? 0);
        $force = (bool) ($input['force_delete'] ?? false);
        $post = get_post($id);
        if (!$post || $post->post_type !== self::CPT_CUSTOM_CODE) {
            return ['success' => false, 'summary' => "Snippet {$id} not found."];
        }
        if ($force) { wp_delete_post($id, true); return ['success' => true, 'trashed' => false, 'summary' => "Snippet #{$id} permanently deleted."]; }
        wp_trash_post($id);
        return ['success' => true, 'trashed' => true, 'summary' => "Snippet #{$id} moved to trash."];
    }

    // ============================================================
    // FORM SUBMISSIONS
    // ============================================================

    public static function execute_list_form_submissions($input = null)
    {
        $form_id = $input['form_id'] ?? null;
        $limit = min(100, (int) ($input['limit'] ?? 50));
        $offset = (int) ($input['offset'] ?? 0);

        $args = ['post_type' => self::CPT_FORM_SUBMISSION, 'posts_per_page' => $limit, 'offset' => $offset, 'post_status' => 'any', 'orderby' => 'date', 'order' => 'DESC'];
        if ($form_id) { $args['meta_key'] = '_elementor_form_id'; $args['meta_value'] = $form_id; }

        if (!post_type_exists(self::CPT_FORM_SUBMISSION)) {
            return self::list_form_submissions_from_table($form_id, $limit, $offset);
        }

        $posts = get_posts($args);
        $submissions = array_map([self::class, 'format_form_submission'], $posts);

        // If CPT has results, return them
        if (!empty($submissions)) {
            return ['success' => true, 'submissions' => $submissions, 'total' => count($submissions), 'summary' => count($submissions) . ' form submission(s) found.'];
        }

        // CPT exists but is empty — try table fallback
        return self::list_form_submissions_from_table($form_id, $limit, $offset);
    }

    private static function list_form_submissions_from_table(?string $form_id, int $limit, int $offset): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'e_submissions';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return ['success' => true, 'submissions' => [], 'total' => 0, 'summary' => 'No form submissions found (no CPT or table).'];
        }
        $where = '';
        if ($form_id) { $where = $wpdb->prepare(' WHERE form_id = %s', $form_id); }
        $rows = $wpdb->get_results("SELECT * FROM {$table}{$where} ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}");
        $submissions = array_map(function ($r) {
            return [
                'id' => (int) $r->id, 'form_id' => $r->form_id ?? '', 'form_name' => $r->form_name ?? '',
                'fields' => json_decode($r->fields ?? '{}', true) ?: [],
                'user_ip' => $r->user_ip ?? '', 'referer' => $r->referer ?? '', 'created_at' => $r->created_at ?? '',
            ];
        }, $rows ?: []);
        return ['success' => true, 'submissions' => $submissions, 'total' => count($submissions), 'summary' => count($submissions) . ' form submissions found (table).'];
    }

    public static function execute_get_form_submission($input = null)
    {
        $id = (int) ($input['submission_id'] ?? 0);
        if (post_type_exists(self::CPT_FORM_SUBMISSION)) {
            $post = get_post($id);
            if ($post && $post->post_type === self::CPT_FORM_SUBMISSION) {
                return ['success' => true, 'submission' => self::format_form_submission($post), 'summary' => "Submission #{$id} retrieved."];
            }
        }
        global $wpdb;
        $table = $wpdb->prefix . 'e_submissions';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
            if ($row) {
                return ['success' => true, 'submission' => [
                    'id' => (int) $row->id, 'form_id' => $row->form_id ?? '', 'form_name' => $row->form_name ?? '',
                    'fields' => json_decode($row->fields ?? '{}', true) ?: [],
                    'user_ip' => $row->user_ip ?? '', 'referer' => $row->referer ?? '', 'created_at' => $row->created_at ?? '',
                ], 'summary' => "Submission #{$id} retrieved."];
            }
        }
        return ['success' => false, 'summary' => "Submission {$id} not found."];
    }

    public static function execute_delete_form_submission($input = null)
    {
        $id = (int) ($input['submission_id'] ?? 0);
        if (post_type_exists(self::CPT_FORM_SUBMISSION)) {
            $post = get_post($id);
            if ($post && $post->post_type === self::CPT_FORM_SUBMISSION) {
                wp_delete_post($id, true);
                return ['success' => true, 'summary' => "Submission #{$id} deleted."];
            }
        }
        global $wpdb;
        $table = $wpdb->prefix . 'e_submissions';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            $deleted = $wpdb->delete($table, ['id' => $id]);
            if ($deleted) {
                return ['success' => true, 'summary' => "Submission #{$id} deleted."];
            }
        }
        return ['success' => false, 'summary' => "Submission {$id} not found."];
    }

    // ============================================================
    // THEME BUILDER CONDITIONS
    // ============================================================

    public static function execute_get_conditions($input = null)
    {
        $id = (int) ($input['template_id'] ?? 0);
        $post = get_post($id);
        if (!$post || $post->post_type !== 'elementor_library') {
            return ['success' => false, 'summary' => "Template {$id} not found."];
        }
        $raw = get_post_meta($id, self::META_CONDITIONS, true);
        $conditions = is_string($raw) ? json_decode($raw, true) : ($raw ?: []);
        return [
            'success' => true, 'template_id' => $id,
            'template_type' => get_post_meta($id, '_elementor_template_type', true),
            'title' => $post->post_title, 'conditions' => $conditions,
            'summary' => empty($conditions) ? 'No conditions set.' : count($conditions) . ' condition group(s) found.',
        ];
    }

    public static function execute_update_conditions($input = null)
    {
        $id = (int) ($input['template_id'] ?? 0);
        $conditions = $input['conditions'] ?? [];
        $post = get_post($id);
        if (!$post || $post->post_type !== 'elementor_library') {
            return ['success' => false, 'summary' => "Template {$id} not found."];
        }
        if (!is_array($conditions)) {
            return ['success' => false, 'summary' => 'Conditions must be an array of condition groups.'];
        }
        // Normalize conditions: cast sub_id to int for strict matching in Elementor
        $conditions = self::normalize_conditions($conditions);
        update_post_meta($id, self::META_CONDITIONS, wp_slash(wp_json_encode($conditions)));
        return ['success' => true, 'summary' => "Conditions updated for template \"{$post->post_title}\" (#{$id}). " . count($conditions) . ' group(s) saved.'];
    }

    // ============================================================
    // HELPERS
    // ============================================================

    private static function format_custom_code(\WP_Post $post): array
    {
        return [
            'id' => $post->ID, 'title' => $post->post_title, 'code' => $post->post_content,
            'language' => get_post_meta($post->ID, '_elementor_custom_code_language', true) ?: 'css',
            'location' => get_post_meta($post->ID, '_elementor_custom_code_location', true) ?: 'head',
            'status' => $post->post_status, 'modified' => $post->post_modified,
        ];
    }

    private static function normalize_conditions(array $conditions): array
    {
        foreach ($conditions as &$group) {
            if (!is_array($group)) { continue; }
            foreach ($group as &$condition) {
                if (is_array($condition) && array_key_exists('sub_id', $condition) && $condition['sub_id'] !== null) {
                    $condition['sub_id'] = (int) $condition['sub_id'];
                }
            }
            unset($condition);
        }
        unset($group);
        return $conditions;
    }

    private static function format_form_submission(\WP_Post $post): array
    {
        $fields_raw = get_post_meta($post->ID, '_elementor_form_fields', true);
        $fields = is_string($fields_raw) ? json_decode($fields_raw, true) : ($fields_raw ?: []);
        return [
            'id' => $post->ID,
            'form_id' => get_post_meta($post->ID, '_elementor_form_id', true) ?: '',
            'form_name' => get_post_meta($post->ID, '_elementor_form_name', true) ?: $post->post_title,
            'fields' => $fields,
            'user_ip' => get_post_meta($post->ID, '_elementor_form_user_ip', true) ?: '',
            'referer' => get_post_meta($post->ID, '_elementor_form_referer', true) ?: '',
            'created_at' => $post->post_date,
        ];
    }
}

add_action('wp_abilities_api_init', [Pro_Features::class, 'register']);
