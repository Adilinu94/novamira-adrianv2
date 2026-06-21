<?php
/**
 * Ability: Template Manager
 *
 * Full CRUD for Elementor templates (elementor_library post type).
 * Covers: get, create, update, delete, restore, empty-trash, duplicate, import, export.
 *
 * Built for Novamira AdrianV2.
 */
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\ElementorTemplates;

if (!defined('ABSPATH')) {
    exit();
}

class Template_Manager
{
    private const POST_TYPE = 'elementor_library';
    private const META_TEMPLATE_TYPE = '_elementor_template_type';
    private const META_CONDITIONS = '_elementor_conditions';
    private const META_DATA = '_elementor_data';
    private const META_CSS = '_elementor_css';

    private const VALID_TYPES = ['page', 'section', 'header', 'footer', 'single', 'archive', 'product', 'product-archive', 'loop', 'popup', 'kit'];

    /**
     * Register all template management abilities.
     */
    public static function register(): void
    {
        $common_meta = ['show_in_rest' => true, 'mcp' => ['public' => true]];
        $read  = ['readonly' => true,  'destructive' => false, 'idempotent' => true];
        $write = ['readonly' => false, 'destructive' => true,  'idempotent' => true];

        // 1. get-template
        wp_register_ability('novamira-adrianv2/get-template', [
            'label'       => 'Get Template',
            'description' => 'Retrieves a single Elementor template by ID with its metadata, conditions, and element data.',
            'category'    => 'adrianv2-templates',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'template_id' => ['type' => 'integer', 'description' => 'Template post ID.'],
                ],
                'required' => ['template_id'],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'    => ['type' => 'boolean'],
                    'template'   => ['type' => 'object'],
                    'summary'    => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute_get_template'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common_meta, ['annotations' => $read]),
        ]);

        // 2. create-template
        wp_register_ability('novamira-adrianv2/create-template', [
            'label'       => 'Create Template',
            'description' => 'Creates a new Elementor template. Supports all template types (page, section, header, footer, single, archive, popup, loop, kit).',
            'category'    => 'adrianv2-templates',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'title'        => ['type' => 'string', 'description' => 'Template title.'],
                    'type'         => ['type' => 'string', 'enum' => self::VALID_TYPES, 'description' => 'Template type.'],
                    'content'      => ['type' => 'array',  'description' => 'Elementor data (JSON array).'],
                    'conditions'   => ['type' => 'array',  'description' => 'Display conditions.'],
                    'status'       => ['type' => 'string', 'enum' => ['publish', 'draft'], 'description' => 'Post status. Default: publish.'],
                ],
                'required' => ['title', 'type'],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'     => ['type' => 'boolean'],
                    'template_id' => ['type' => 'integer'],
                    'edit_url'    => ['type' => 'string'],
                    'summary'     => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute_create_template'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common_meta, ['annotations' => $write]),
        ]);

        // 3. update-template
        wp_register_ability('novamira-adrianv2/update-template', [
            'label'       => 'Update Template',
            'description' => 'Updates an existing Elementor template\'s title, element data, conditions, or status.',
            'category'    => 'adrianv2-templates',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'template_id'  => ['type' => 'integer', 'description' => 'Template post ID.'],
                    'title'        => ['type' => 'string', 'description' => 'New title.'],
                    'content'      => ['type' => 'array',  'description' => 'New Elementor data.'],
                    'conditions'   => ['type' => 'array',  'description' => 'New display conditions.'],
                    'status'       => ['type' => 'string', 'enum' => ['publish', 'draft'], 'description' => 'New status.'],
                ],
                'required' => ['template_id'],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'     => ['type' => 'boolean'],
                    'updated'     => ['type' => 'array', 'items' => ['type' => 'string']],
                    'summary'     => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute_update_template'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common_meta, ['annotations' => $write]),
        ]);

        // 4. delete-template
        wp_register_ability('novamira-adrianv2/delete-template', [
            'label'       => 'Delete Template',
            'description' => 'Trashes or permanently deletes an Elementor template.',
            'category'    => 'adrianv2-templates',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'template_id'  => ['type' => 'integer', 'description' => 'Template post ID.'],
                    'force_delete' => ['type' => 'boolean', 'description' => 'Permanently delete (skip trash). Default: false.'],
                ],
                'required' => ['template_id'],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'   => ['type' => 'boolean'],
                    'trashed'   => ['type' => 'boolean'],
                    'summary'   => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute_delete_template'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common_meta, ['annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false]]),
        ]);

        // 5. restore-template
        wp_register_ability('novamira-adrianv2/restore-template', [
            'label'       => 'Restore Template',
            'description' => 'Restores a trashed Elementor template back to publish/draft status.',
            'category'    => 'adrianv2-templates',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'template_id' => ['type' => 'integer', 'description' => 'Template post ID.'],
                ],
                'required' => ['template_id'],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'   => ['type' => 'boolean'],
                    'status'    => ['type' => 'string'],
                    'summary'   => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute_restore_template'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common_meta, ['annotations' => $write]),
        ]);

        // 6. empty-trash
        wp_register_ability('novamira-adrianv2/empty-trash', [
            'label'       => 'Empty Template Trash',
            'description' => 'Permanently deletes all trashed Elementor templates.',
            'category'    => 'adrianv2-templates',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'type_filter' => ['type' => 'string', 'enum' => self::VALID_TYPES, 'description' => 'Only empty trash for this template type. Omit for all.'],
                    'dry_run'     => ['type' => 'boolean', 'description' => 'Preview only. Default: false.'],
                ],
                'required' => [],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'       => ['type' => 'boolean'],
                    'deleted_count' => ['type' => 'integer'],
                    'deleted_ids'   => ['type' => 'array', 'items' => ['type' => 'integer']],
                    'dry_run'       => ['type' => 'boolean'],
                    'summary'       => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute_empty_trash'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common_meta, ['annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false]]),
        ]);

        // 7. duplicate-template
        wp_register_ability('novamira-adrianv2/duplicate-template', [
            'label'       => 'Duplicate Template',
            'description' => 'Creates a copy of an Elementor template including all its element data and conditions.',
            'category'    => 'adrianv2-templates',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'template_id' => ['type' => 'integer', 'description' => 'Source template post ID.'],
                    'new_title'   => ['type' => 'string', 'description' => 'Title for the duplicate. Default: "Source Title (Copy)".'],
                ],
                'required' => ['template_id'],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'     => ['type' => 'boolean'],
                    'new_id'      => ['type' => 'integer'],
                    'edit_url'    => ['type' => 'string'],
                    'summary'     => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute_duplicate_template'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common_meta, ['annotations' => $write]),
        ]);

        // 8. import-template
        wp_register_ability('novamira-adrianv2/import-template', [
            'label'       => 'Import Template',
            'description' => 'Imports an Elementor template from a JSON export. Creates the template with its element data, type, and conditions.',
            'category'    => 'adrianv2-templates',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'json_data' => ['type' => 'string', 'description' => 'JSON export string (as produced by export-template).'],
                    'status'    => ['type' => 'string', 'enum' => ['publish', 'draft'], 'description' => 'Post status. Default: publish.'],
                ],
                'required' => ['json_data'],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'     => ['type' => 'boolean'],
                    'template_id' => ['type' => 'integer'],
                    'title'       => ['type' => 'string'],
                    'type'        => ['type' => 'string'],
                    'summary'     => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute_import_template'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common_meta, ['annotations' => $write]),
        ]);

        // 9. export-template
        wp_register_ability('novamira-adrianv2/export-template', [
            'label'       => 'Export Template',
            'description' => 'Exports an Elementor template as JSON including its element data, type, conditions, and metadata.',
            'category'    => 'adrianv2-templates',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'template_id' => ['type' => 'integer', 'description' => 'Template post ID.'],
                ],
                'required' => ['template_id'],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'  => ['type' => 'boolean'],
                    'json'     => ['type' => 'string'],
                    'template' => ['type' => 'object'],
                    'summary'  => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute_export_template'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common_meta, ['annotations' => $read]),
        ]);
    }

    // ============================================================
    // 1. GET TEMPLATE
    // ============================================================

    public static function execute_get_template($input = null)
    {
        $id = (int) ($input['template_id'] ?? 0);
        $post = get_post($id);
        if (!$post || $post->post_type !== self::POST_TYPE) {
            return ['success' => false, 'summary' => "Template {$id} not found."];
        }

        return [
            'success'  => true,
            'template' => self::format_template($post),
            'summary'  => "Template \"{$post->post_title}\" retrieved.",
        ];
    }

    // ============================================================
    // 2. CREATE TEMPLATE
    // ============================================================

    public static function execute_create_template($input = null)
    {
        $title   = trim((string) ($input['title'] ?? ''));
        $type    = (string) ($input['type'] ?? 'page');
        $status  = (string) ($input['status'] ?? 'publish');
        $content = $input['content'] ?? null;
        $conditions = $input['conditions'] ?? null;

        if ($title === '') {
            return ['success' => false, 'summary' => 'Title is required.'];
        }
        if (!in_array($type, self::VALID_TYPES, true)) {
            return ['success' => false, 'summary' => "Invalid template type: {$type}."];
        }

        $post_id = wp_insert_post([
            'post_title'  => $title,
            'post_type'   => self::POST_TYPE,
            'post_status' => $status,
            'meta_input'  => [
                self::META_TEMPLATE_TYPE => $type,
                '_wp_page_template'       => 'elementor_canvas',
            ],
        ], true);

        if (is_wp_error($post_id)) {
            return ['success' => false, 'summary' => $post_id->get_error_message()];
        }

        if (is_array($content) && !empty($content)) {
            update_post_meta($post_id, self::META_DATA, wp_slash(wp_json_encode($content)));
            update_post_meta($post_id, '_elementor_edit_mode', 'builder');
            update_post_meta($post_id, '_elementor_version', defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '3.0.0');
        }

        if (is_array($conditions) && !empty($conditions)) {
            update_post_meta($post_id, self::META_CONDITIONS, wp_slash(wp_json_encode($conditions)));
        }

        return [
            'success'     => true,
            'template_id' => $post_id,
            'edit_url'    => self::get_edit_url($post_id),
            'summary'     => "Template \"{$title}\" (#{$post_id}, type: {$type}) created.",
        ];
    }

    // ============================================================
    // 3. UPDATE TEMPLATE
    // ============================================================

    public static function execute_update_template($input = null)
    {
        $id = (int) ($input['template_id'] ?? 0);
        $post = get_post($id);
        if (!$post || $post->post_type !== self::POST_TYPE) {
            return ['success' => false, 'summary' => "Template {$id} not found."];
        }

        $updated = [];

        if (isset($input['title'])) {
            wp_update_post(['ID' => $id, 'post_title' => trim((string) $input['title'])]);
            $updated[] = 'title';
        }

        if (isset($input['status'])) {
            wp_update_post(['ID' => $id, 'post_status' => (string) $input['status']]);
            $updated[] = 'status';
        }

        if (isset($input['content'])) {
            update_post_meta($id, self::META_DATA, wp_slash(wp_json_encode($input['content'])));
            $updated[] = 'content';
            self::invalidate_cache($id);
        }

        if (isset($input['conditions'])) {
            update_post_meta($id, self::META_CONDITIONS, wp_slash(wp_json_encode($input['conditions'])));
            $updated[] = 'conditions';
        }

        if (empty($updated)) {
            return ['success' => false, 'summary' => 'No fields to update.'];
        }

        return [
            'success' => true,
            'updated' => $updated,
            'summary' => "Template #{$id} updated: " . implode(', ', $updated) . '.',
        ];
    }

    // ============================================================
    // 4. DELETE TEMPLATE
    // ============================================================

    public static function execute_delete_template($input = null)
    {
        $id = (int) ($input['template_id'] ?? 0);
        $force = (bool) ($input['force_delete'] ?? false);
        $post = get_post($id);
        if (!$post || $post->post_type !== self::POST_TYPE) {
            return ['success' => false, 'summary' => "Template {$id} not found."];
        }

        if ($force) {
            $result = wp_delete_post($id, true);
            if (!$result) {
                return ['success' => false, 'summary' => "Failed to delete template {$id}."];
            }
            return ['success' => true, 'trashed' => false, 'summary' => "Template #{$id} permanently deleted."];
        }

        $result = wp_trash_post($id);
        if (!$result) {
            return ['success' => false, 'summary' => "Failed to trash template {$id}."];
        }
        return ['success' => true, 'trashed' => true, 'summary' => "Template #{$id} moved to trash."];
    }

    // ============================================================
    // 5. RESTORE TEMPLATE
    // ============================================================

    public static function execute_restore_template($input = null)
    {
        $id = (int) ($input['template_id'] ?? 0);
        $post = get_post($id);
        if (!$post || $post->post_type !== self::POST_TYPE) {
            return ['success' => false, 'summary' => "Template {$id} not found."];
        }
        if ($post->post_status !== 'trash') {
            return ['success' => false, 'summary' => "Template {$id} is not in trash (status: {$post->post_status})."];
        }

        $result = wp_untrash_post($id);
        if (!$result) {
            return ['success' => false, 'summary' => "Failed to restore template {$id}."];
        }

        $post = get_post($id);
        return [
            'success' => true,
            'status'  => $post->post_status,
            'summary' => "Template #{$id} restored to {$post->post_status}.",
        ];
    }

    // ============================================================
    // 6. EMPTY TRASH
    // ============================================================

    public static function execute_empty_trash($input = null)
    {
        $type_filter = $input['type_filter'] ?? null;
        $dry_run = (bool) ($input['dry_run'] ?? false);

        $args = [
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'trash',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ];

        if ($type_filter) {
            $args['meta_key']   = self::META_TEMPLATE_TYPE;
            $args['meta_value'] = $type_filter;
        }

        $ids = get_posts($args);
        $count = count($ids);

        if ($dry_run) {
            return [
                'success'       => true,
                'deleted_count' => 0,
                'deleted_ids'   => $ids,
                'dry_run'       => true,
                'summary'       => "[DRY RUN] Would permanently delete {$count} trashed templates." . ($type_filter ? " (type: {$type_filter})" : ''),
            ];
        }

        $deleted = [];
        foreach ($ids as $id) {
            if (wp_delete_post($id, true)) {
                $deleted[] = $id;
            }
        }

        return [
            'success'       => true,
            'deleted_count' => count($deleted),
            'deleted_ids'   => $deleted,
            'dry_run'       => false,
            'summary'       => "Permanently deleted " . count($deleted) . " of {$count} trashed templates.",
        ];
    }

    // ============================================================
    // 7. DUPLICATE TEMPLATE
    // ============================================================

    public static function execute_duplicate_template($input = null)
    {
        $id = (int) ($input['template_id'] ?? 0);
        $src = get_post($id);
        if (!$src || $src->post_type !== self::POST_TYPE) {
            return ['success' => false, 'summary' => "Template {$id} not found."];
        }

        $new_title = !empty($input['new_title']) ? trim((string) $input['new_title']) : $src->post_title . ' (Copy)';
        $type = get_post_meta($id, self::META_TEMPLATE_TYPE, true);
        $data = get_post_meta($id, self::META_DATA, true);
        $conditions = get_post_meta($id, self::META_CONDITIONS, true);
        $css = get_post_meta($id, self::META_CSS, true);
        $version = get_post_meta($id, '_elementor_version', true);

        $new_id = wp_insert_post([
            'post_title'  => $new_title,
            'post_type'   => self::POST_TYPE,
            'post_status' => 'draft',
            'meta_input'  => [
                self::META_TEMPLATE_TYPE => $type,
                '_wp_page_template'       => 'elementor_canvas',
            ],
        ], true);

        if (is_wp_error($new_id)) {
            return ['success' => false, 'summary' => $new_id->get_error_message()];
        }

        if (!empty($data)) {
            update_post_meta($new_id, self::META_DATA, $data);
            update_post_meta($new_id, '_elementor_edit_mode', 'builder');
            if (!empty($version)) {
                update_post_meta($new_id, '_elementor_version', $version);
            }
        }
        if (!empty($conditions)) {
            update_post_meta($new_id, self::META_CONDITIONS, $conditions);
        }
        if (!empty($css)) {
            update_post_meta($new_id, self::META_CSS, $css);
        }

        return [
            'success'  => true,
            'new_id'   => $new_id,
            'edit_url' => self::get_edit_url($new_id),
            'summary'  => "Template \"{$src->post_title}\" duplicated as #{$new_id} \"{$new_title}\" (type: {$type}).",
        ];
    }

    // ============================================================
    // 8. IMPORT TEMPLATE
    // ============================================================

    public static function execute_import_template($input = null)
    {
        $json_str = (string) ($input['json_data'] ?? '');
        $status = (string) ($input['status'] ?? 'publish');

        $decoded = json_decode($json_str, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return ['success' => false, 'summary' => 'Invalid JSON: ' . json_last_error_msg()];
        }

        $title      = $decoded['title'] ?? 'Imported Template';
        $type       = $decoded['type']  ?? 'page';
        $content    = $decoded['content']    ?? [];
        $conditions = $decoded['conditions'] ?? [];
        $css        = $decoded['css'] ?? '';
        $version    = $decoded['elementor_version'] ?? (defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '');

        if (!in_array($type, self::VALID_TYPES, true)) {
            return ['success' => false, 'summary' => "Invalid template type in import: {$type}."];
        }

        $post_id = wp_insert_post([
            'post_title'  => $title,
            'post_type'   => self::POST_TYPE,
            'post_status' => $status,
            'meta_input'  => [
                self::META_TEMPLATE_TYPE => $type,
                '_wp_page_template'       => 'elementor_canvas',
            ],
        ], true);

        if (is_wp_error($post_id)) {
            return ['success' => false, 'summary' => $post_id->get_error_message()];
        }

        if (!empty($content)) {
            update_post_meta($post_id, self::META_DATA, wp_slash(wp_json_encode($content)));
            update_post_meta($post_id, '_elementor_edit_mode', 'builder');
            if ($version) {
                update_post_meta($post_id, '_elementor_version', $version);
            }
        }

        if (!empty($conditions)) {
            update_post_meta($post_id, self::META_CONDITIONS, wp_slash(wp_json_encode($conditions)));
        }

        if (!empty($css)) {
            update_post_meta($post_id, self::META_CSS, wp_slash($css));
        }

        return [
            'success'     => true,
            'template_id' => $post_id,
            'title'       => $title,
            'type'        => $type,
            'summary'     => "Template \"{$title}\" (#{$post_id}, type: {$type}) imported successfully.",
        ];
    }

    // ============================================================
    // 9. EXPORT TEMPLATE
    // ============================================================

    public static function execute_export_template($input = null)
    {
        $id = (int) ($input['template_id'] ?? 0);
        $post = get_post($id);
        if (!$post || $post->post_type !== self::POST_TYPE) {
            return ['success' => false, 'summary' => "Template {$id} not found."];
        }

        $data = get_post_meta($id, self::META_DATA, true);
        $conditions = get_post_meta($id, self::META_CONDITIONS, true);
        $css = get_post_meta($id, self::META_CSS, true);
        $type = get_post_meta($id, self::META_TEMPLATE_TYPE, true);
        $version = get_post_meta($id, '_elementor_version', true);

        $export = [
            'title'            => $post->post_title,
            'type'             => $type,
            'content'          => is_string($data) ? json_decode($data, true) : $data,
            'conditions'       => is_string($conditions) ? json_decode($conditions, true) : $conditions,
            'css'              => $css,
            'elementor_version' => $version ?: (defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : ''),
            'exported_at'      => gmdate('c'),
            'source_id'        => $id,
        ];

        $json = wp_json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            'success'  => true,
            'json'     => $json,
            'template' => $export,
            'summary'  => "Template \"{$post->post_title}\" (#{$id}) exported.",
        ];
    }

    // ============================================================
    // HELPERS
    // ============================================================

    private static function format_template(\WP_Post $post): array
    {
        $data = get_post_meta($post->ID, self::META_DATA, true);
        $conditions = get_post_meta($post->ID, self::META_CONDITIONS, true);
        $type = get_post_meta($post->ID, self::META_TEMPLATE_TYPE, true);

        $element_count = 0;
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            if (is_array($decoded)) {
                $element_count = self::count_elements($decoded);
            }
        }

        return [
            'id'             => $post->ID,
            'title'          => $post->post_title,
            'slug'           => $post->post_name,
            'status'         => $post->post_status,
            'type'           => $type,
            'element_count'  => $element_count,
            'has_data'       => !empty($data),
            'has_conditions' => !empty($conditions),
            'conditions'     => !empty($conditions) ? (is_string($conditions) ? json_decode($conditions, true) : $conditions) : [],
            'edit_url'       => self::get_edit_url($post->ID),
            'modified'       => $post->post_modified,
        ];
    }

    private static function count_elements(array $elements): int
    {
        $count = count($elements);
        foreach ($elements as $el) {
            if (is_array($el) && !empty($el['elements'])) {
                $count += self::count_elements($el['elements']);
            }
        }
        return $count;
    }

    private static function get_edit_url(int $id): string
    {
        return admin_url("post.php?post={$id}&action=elementor");
    }

    private static function invalidate_cache(int $post_id): void
    {
        if (class_exists('\\Novamira\\AdrianV2\\Helpers\\Guards')) {
            \Novamira\AdrianV2\Helpers\Guards::invalidate_elementor_cache($post_id);
        } else {
            delete_post_meta($post_id, self::META_CSS);
        }
    }
}

add_action('wp_abilities_api_init', [Template_Manager::class, 'register']);
