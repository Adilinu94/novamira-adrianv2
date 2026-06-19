<?php
/**
 * Ability: Site Tools
 *
 * Elementor site-level management tools: cache, maintenance mode, experiments,
 * kit management, and URL replacement.
 *
 * Built for Novamira AdrianV2. Uses Elementor's native API where available.
 */
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\ElementorSiteTools;

if (!defined('ABSPATH')) {
    exit();
}

class Site_Tools
{
    /**
     * Register all site-tool abilities.
     */
    public static function register(): void
    {
        $common = ['show_in_rest' => true, 'mcp' => ['public' => true]];
        $read  = ['readonly' => true,  'destructive' => false, 'idempotent' => true];
        $write = ['readonly' => false, 'destructive' => true,  'idempotent' => true];
        $danger = ['readonly' => false, 'destructive' => true, 'idempotent' => false];

        // 1. clear-cache
        wp_register_ability('novamira-adrianv2/clear-cache', [
            'label'       => 'Clear Elementor Cache',
            'description' => 'Clears Elementor\'s CSS cache and regenerates files. Equivalent to Elementor > Tools > Regenerate CSS.',
            'category'    => 'adrianv2-site-tools',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'scope' => ['type' => 'string', 'enum' => ['css', 'all'], 'description' => 'Cache scope. "css" = regenerate CSS files only. "all" = full cache clear. Default: css.'],
                ],
                'required' => [],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'summary' => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute_clear_cache'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common, ['annotations' => $write]),
        ]);

        // 2. get-maintenance-mode
        wp_register_ability('novamira-adrianv2/get-maintenance-mode', [
            'label'       => 'Get Maintenance Mode',
            'description' => 'Retrieves the current Elementor maintenance mode status and settings.',
            'category'    => 'adrianv2-site-tools',
            'input_schema' => ['type' => 'object', 'properties' => [], 'required' => []],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'  => ['type' => 'boolean'],
                    'mode'     => ['type' => 'string'],
                    'settings' => ['type' => 'object'],
                    'summary'  => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute_get_maintenance_mode'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common, ['annotations' => $read]),
        ]);

        // 3. update-maintenance-mode
        wp_register_ability('novamira-adrianv2/update-maintenance-mode', [
            'label'       => 'Update Maintenance Mode',
            'description' => 'Enables or disables Elementor maintenance mode with optional template and user role settings.',
            'category'    => 'adrianv2-site-tools',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'mode'         => ['type' => 'string', 'enum' => ['coming_soon', 'maintenance', 'disabled'], 'description' => 'Maintenance mode.'],
                    'template_id'  => ['type' => 'integer', 'description' => 'Template ID for the maintenance/coming-soon page.'],
                    'who_can_access' => ['type' => 'string', 'enum' => ['logged_in', 'custom'], 'description' => 'Who can access the site. Default: logged_in.'],
                ],
                'required' => ['mode'],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'mode'    => ['type' => 'string'],
                    'summary' => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute_update_maintenance_mode'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common, ['annotations' => $write]),
        ]);

        // 4. list-experiments
        wp_register_ability('novamira-adrianv2/list-experiments', [
            'label'       => 'List Experiments',
            'description' => 'Lists all Elementor experiments (features) with their current status (active/inactive/default).',
            'category'    => 'adrianv2-site-tools',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'filter' => ['type' => 'string', 'enum' => ['all', 'active', 'inactive'], 'description' => 'Filter by status. Default: all.'],
                ],
                'required' => [],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'     => ['type' => 'boolean'],
                    'experiments' => ['type' => 'array', 'items' => ['type' => 'object']],
                    'total'       => ['type' => 'integer'],
                    'summary'     => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute_list_experiments'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common, ['annotations' => $read]),
        ]);

        // 5. update-experiment
        wp_register_ability('novamira-adrianv2/update-experiment', [
            'label'       => 'Update Experiment',
            'description' => 'Activates or deactivates an Elementor experiment (feature).',
            'category'    => 'adrianv2-site-tools',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'feature' => ['type' => 'string', 'description' => 'Experiment feature name (e.g., "container", "e_optimized_markup").'],
                    'status'  => ['type' => 'string', 'enum' => ['active', 'inactive', 'default'], 'description' => 'New status.'],
                ],
                'required' => ['feature', 'status'],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'summary' => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute_update_experiment'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common, ['annotations' => $write]),
        ]);

        // 6. list-kits
        wp_register_ability('novamira-adrianv2/list-kits', [
            'label'       => 'List Kits',
            'description' => 'Lists all Elementor kit templates with their IDs, titles, and active status.',
            'category'    => 'adrianv2-site-tools',
            'input_schema' => ['type' => 'object', 'properties' => [], 'required' => []],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'    => ['type' => 'boolean'],
                    'active_kit' => ['type' => 'integer'],
                    'kits'       => ['type' => 'array', 'items' => ['type' => 'object']],
                    'summary'    => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute_list_kits'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common, ['annotations' => $read]),
        ]);

        // 7. get-kit-settings
        wp_register_ability('novamira-adrianv2/get-kit-settings', [
            'label'       => 'Get Kit Settings',
            'description' => 'Retrieves the settings of an Elementor kit (globals: colors, typography, spacing, etc.). Defaults to the active kit.',
            'category'    => 'adrianv2-site-tools',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'kit_id' => ['type' => 'integer', 'description' => 'Kit template ID. Omit for active kit.'],
                ],
                'required' => [],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'   => ['type' => 'boolean'],
                    'kit_id'    => ['type' => 'integer'],
                    'title'     => ['type' => 'string'],
                    'settings'  => ['type' => 'object'],
                    'elements'  => ['type' => 'array'],
                    'summary'   => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute_get_kit_settings'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common, ['annotations' => $read]),
        ]);

        // 8. update-kit-settings
        wp_register_ability('novamira-adrianv2/update-kit-settings', [
            'label'       => 'Update Kit Settings',
            'description' => 'Updates Elementor kit settings (system colors, typography, spacing, etc.). Defaults to the active kit.',
            'category'    => 'adrianv2-site-tools',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'kit_id'   => ['type' => 'integer', 'description' => 'Kit template ID. Omit for active kit.'],
                    'settings' => ['type' => 'object', 'description' => 'Settings object to merge into the kit.'],
                ],
                'required' => ['settings'],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'   => ['type' => 'boolean'],
                    'kit_id'    => ['type' => 'integer'],
                    'updated'   => ['type' => 'array', 'items' => ['type' => 'string']],
                    'summary'   => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute_update_kit_settings'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common, ['annotations' => $write]),
        ]);

        // 9. set-active-kit
        wp_register_ability('novamira-adrianv2/set-active-kit', [
            'label'       => 'Set Active Kit',
            'description' => 'Sets the active Elementor kit. Regenerates CSS cache after switching.',
            'category'    => 'adrianv2-site-tools',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'kit_id' => ['type' => 'integer', 'description' => 'Kit template ID to activate.'],
                ],
                'required' => ['kit_id'],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'   => ['type' => 'boolean'],
                    'kit_id'    => ['type' => 'integer'],
                    'title'     => ['type' => 'string'],
                    'summary'   => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute_set_active_kit'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common, ['annotations' => $danger]),
        ]);

        // 10. replace-urls
        wp_register_ability('novamira-adrianv2/replace-urls', [
            'label'       => 'Replace URLs',
            'description' => 'Performs find-and-replace of URLs across all Elementor page/post/template data. Useful after site migrations. Note: uses string replacement on raw JSON — avoid URLs containing JSON-sensitive characters (backslash, double-quote).',
            'category'    => 'adrianv2-site-tools',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'from'    => ['type' => 'string', 'description' => 'Old URL or string to find.'],
                    'to'      => ['type' => 'string', 'description' => 'New URL or string to replace with.'],
                    'dry_run' => ['type' => 'boolean', 'description' => 'Preview changes only. Default: false.'],
                ],
                'required' => ['from', 'to'],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'       => ['type' => 'boolean'],
                    'affected_posts' => ['type' => 'integer'],
                    'replacements'  => ['type' => 'integer'],
                    'dry_run'       => ['type' => 'boolean'],
                    'details'       => ['type' => 'array', 'items' => ['type' => 'object']],
                    'summary'       => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute_replace_urls'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common, ['annotations' => $danger]),
        ]);
    }

    // ============================================================
    // 1. CLEAR CACHE
    // ============================================================

    public static function execute_clear_cache($input = null)
    {
        $scope = (string) ($input['scope'] ?? 'css');

        if (!self::is_elementor_loaded()) {
            return ['success' => false, 'summary' => 'Elementor is not active.'];
        }

        try {
            \Elementor\Plugin::$instance->files_manager->clear_cache();

            if ($scope === 'all') {
                // Also purge the _elementor_css meta cache across all posts
                delete_post_meta_by_key('_elementor_css');
            }

            return [
                'success' => true,
                'summary' => "Elementor {$scope} cache cleared and CSS regenerated.",
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'summary' => 'Cache clear failed: ' . $e->getMessage()];
        }
    }

    // ============================================================
    // 2. GET MAINTENANCE MODE
    // ============================================================

    public static function execute_get_maintenance_mode($input = null)
    {
        if (!self::is_elementor_loaded()) {
            return ['success' => false, 'summary' => 'Elementor is not active.'];
        }

        $mode = \Elementor\Plugin::$instance->maintenance_mode->get('mode') ?: 'disabled';

        $settings = [
            'mode'            => $mode,
            'template_id'     => \Elementor\Plugin::$instance->maintenance_mode->get('template_id'),
            'who_can_access'  => \Elementor\Plugin::$instance->maintenance_mode->get('who_can_access') ?: 'logged_in',
        ];

        $label = $mode === 'disabled' ? 'disabled' : ($mode === 'coming_soon' ? 'coming soon' : 'maintenance');

        return [
            'success'  => true,
            'mode'     => $mode,
            'settings' => $settings,
            'summary'  => "Maintenance mode is {$label}.",
        ];
    }

    // ============================================================
    // 3. UPDATE MAINTENANCE MODE
    // ============================================================

    public static function execute_update_maintenance_mode($input = null)
    {
        if (!self::is_elementor_loaded()) {
            return ['success' => false, 'summary' => 'Elementor is not active.'];
        }

        $mode = (string) ($input['mode'] ?? 'disabled');

        $settings = [];
        if ($mode !== 'disabled') {
            if (isset($input['template_id'])) {
                $settings['template_id'] = (int) $input['template_id'];
            }
            $settings['who_can_access'] = (string) ($input['who_can_access'] ?? 'logged_in');
        }

        \Elementor\Plugin::$instance->maintenance_mode->set_mode($mode);
        foreach ($settings as $key => $value) {
            \Elementor\Plugin::$instance->maintenance_mode->set($key, $value);
        }

        $label = $mode === 'disabled' ? 'disabled' : ($mode === 'coming_soon' ? 'coming soon' : 'maintenance');

        return [
            'success' => true,
            'mode'    => $mode,
            'summary' => "Maintenance mode set to {$label}.",
        ];
    }

    // ============================================================
    // 4. LIST EXPERIMENTS
    // ============================================================

    public static function execute_list_experiments($input = null)
    {
        if (!self::is_elementor_loaded()) {
            return ['success' => false, 'summary' => 'Elementor is not active.'];
        }

        $filter = (string) ($input['filter'] ?? 'all');
        $experiments = \Elementor\Plugin::$instance->experiments->get_features();

        $result = [];
        foreach ($experiments as $name => $experiment) {
            $status = \Elementor\Plugin::$instance->experiments->is_feature_active($name) ? 'active' : 'inactive';
            $default = $experiment['default'] ?? 'inactive';
            if ($status === 'active' && $default === 'active') {
                $status = 'default';
            }

            if ($filter === 'active' && $status === 'inactive') continue;
            if ($filter === 'inactive' && $status !== 'inactive') continue;

            $result[] = [
                'name'        => $name,
                'title'       => $experiment['title'] ?? $name,
                'description' => $experiment['description'] ?? '',
                'status'      => $status,
                'default'     => $default,
                'release_status' => $experiment['release_status'] ?? 'alpha',
            ];
        }

        return [
            'success'     => true,
            'experiments' => $result,
            'total'       => count($result),
            'summary'     => count($result) . ' experiments listed' . ($filter !== 'all' ? " (filtered: {$filter})" : '') . '.',
        ];
    }

    // ============================================================
    // 5. UPDATE EXPERIMENT
    // ============================================================

    public static function execute_update_experiment($input = null)
    {
        if (!self::is_elementor_loaded()) {
            return ['success' => false, 'summary' => 'Elementor is not active.'];
        }

        $feature = (string) ($input['feature'] ?? '');
        $status = (string) ($input['status'] ?? 'default');

        $features = \Elementor\Plugin::$instance->experiments->get_features();
        if (!isset($features[$feature])) {
            return ['success' => false, 'summary' => "Unknown experiment: {$feature}."];
        }

        try {
            if ($status === 'default') {
                $existing = get_option('elementor_experiment-' . $feature, null);
                if ($existing !== null) {
                    delete_option('elementor_experiment-' . $feature);
                }
            } elseif (method_exists(\Elementor\Plugin::$instance->experiments, 'set_feature_default_state')) {
                \Elementor\Plugin::$instance->experiments->set_feature_default_state($feature, $status);
            } else {
                // Fallback: direct option update for older Elementor versions
                update_option('elementor_experiment-' . $feature, $status);
            }

            return [
                'success' => true,
                'summary' => "Experiment \"{$feature}\" set to {$status}.",
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'summary' => 'Failed: ' . $e->getMessage()];
        }
    }

    // ============================================================
    // 6. LIST KITS
    // ============================================================

    public static function execute_list_kits($input = null)
    {
        $active_id = self::get_active_kit_id();
        $kits = get_posts([
            'post_type'      => 'elementor_library',
            'meta_key'       => '_elementor_template_type',
            'meta_value'     => 'kit',
            'posts_per_page' => -1,
            'post_status'    => 'any',
        ]);

        $result = [];
        foreach ($kits as $kit) {
            $result[] = [
                'id'     => $kit->ID,
                'title'  => $kit->post_title,
                'status' => $kit->post_status,
                'active' => $kit->ID === $active_id,
                'modified' => $kit->post_modified,
            ];
        }

        return [
            'success'    => true,
            'active_kit' => $active_id,
            'kits'       => $result,
            'summary'    => count($result) . ' kit(s) found. Active: #' . ($active_id ?: 'none'),
        ];
    }

    // ============================================================
    // 7. GET KIT SETTINGS
    // ============================================================

    public static function execute_get_kit_settings($input = null)
    {
        $kit_id = isset($input['kit_id']) ? (int) $input['kit_id'] : self::get_active_kit_id();
        if (!$kit_id) {
            return ['success' => false, 'summary' => 'No kit found.'];
        }

        $post = get_post($kit_id);
        if (!$post) {
            return ['success' => false, 'summary' => "Kit #{$kit_id} not found."];
        }

        // Try Elementor API first
        if (self::is_elementor_loaded()) {
            try {
                $kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
                if ($kit && $kit->get_id() === $kit_id) {
                    $settings = $kit->get_settings();
                }
            } catch (\Throwable $e) {
                // Fall through to direct meta read
            }
        }

        // Fallback: read directly
        if (!isset($settings)) {
            $raw = get_post_meta($kit_id, '_elementor_page_settings', true);
            $settings = is_string($raw) ? json_decode($raw, true) : ($raw ?: []);
            if (!is_array($settings)) $settings = [];
        }

        $data_raw = get_post_meta($kit_id, '_elementor_data', true);
        $elements = is_string($data_raw) ? json_decode($data_raw, true) : ($data_raw ?: []);
        if (!is_array($elements)) $elements = [];

        return [
            'success'  => true,
            'kit_id'   => $kit_id,
            'title'    => $post->post_title,
            'settings' => $settings,
            'elements' => $elements,
            'summary'  => "Kit \"{$post->post_title}\" (#{$kit_id}) settings retrieved.",
        ];
    }

    // ============================================================
    // 8. UPDATE KIT SETTINGS
    // ============================================================

    public static function execute_update_kit_settings($input = null)
    {
        $kit_id = isset($input['kit_id']) ? (int) $input['kit_id'] : self::get_active_kit_id();
        $new_settings = $input['settings'] ?? [];

        if (!$kit_id) {
            return ['success' => false, 'summary' => 'No kit found.'];
        }
        if (empty($new_settings) || !is_array($new_settings)) {
            return ['success' => false, 'summary' => 'Settings object is required.'];
        }

        $post = get_post($kit_id);
        if (!$post) {
            return ['success' => false, 'summary' => "Kit #{$kit_id} not found."];
        }

        // Try Elementor API first
        $updated = [];
        if (self::is_elementor_loaded()) {
            try {
                $kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
                if ($kit && $kit->get_id() === $kit_id) {
                    $current = $kit->get_settings();
                    $merged = array_merge($current, $new_settings);
                    $kit->save(['settings' => $merged]);
                    $updated = array_keys($new_settings);
                }
            } catch (\Throwable $e) {
                // Fall through to direct save
            }
        }

        // Fallback: direct meta update
        if (empty($updated)) {
            $raw = get_post_meta($kit_id, '_elementor_page_settings', true);
            $current = is_string($raw) ? json_decode($raw, true) : ($raw ?: []);
            if (!is_array($current)) $current = [];
            $merged = array_merge($current, $new_settings);
            update_post_meta($kit_id, '_elementor_page_settings', wp_slash(wp_json_encode($merged)));
            $updated = array_keys($new_settings);
        }

        // Invalidate cache
        self::invalidate_cache($kit_id);

        return [
            'success' => true,
            'kit_id'  => $kit_id,
            'updated' => $updated,
            'summary' => 'Kit settings updated: ' . implode(', ', $updated) . '.',
        ];
    }

    // ============================================================
    // 9. SET ACTIVE KIT
    // ============================================================

    public static function execute_set_active_kit($input = null)
    {
        $kit_id = (int) ($input['kit_id'] ?? 0);
        $post = get_post($kit_id);

        if (!$post) {
            return ['success' => false, 'summary' => "Kit #{$kit_id} not found."];
        }

        $type = get_post_meta($kit_id, '_elementor_template_type', true);
        if ($type !== 'kit') {
            return ['success' => false, 'summary' => "Post #{$kit_id} is not a kit (type: {$type})."];
        }

        // Update the active kit option
        update_option('elementor_active_kit', $kit_id);

        // Clear cache after kit switch
        if (self::is_elementor_loaded()) {
            try {
                \Elementor\Plugin::$instance->files_manager->clear_cache();
            } catch (\Throwable $e) {
                // Non-critical
            }
        }

        return [
            'success' => true,
            'kit_id'  => $kit_id,
            'title'   => $post->post_title,
            'summary' => "Active kit set to \"{$post->post_title}\" (#{$kit_id}). CSS cache regenerated.",
        ];
    }

    // ============================================================
    // 10. REPLACE URLS
    // ============================================================

    public static function execute_replace_urls($input = null)
    {
        $from = (string) ($input['from'] ?? '');
        $to = (string) ($input['to'] ?? '');
        $dry_run = (bool) ($input['dry_run'] ?? false);

        if ($from === '' || $to === '') {
            return ['success' => false, 'summary' => 'Both "from" and "to" are required.'];
        }

        // Find all posts with Elementor data
        $post_ids = get_posts([
            'post_type'      => ['page', 'post', 'elementor_library'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => '_elementor_data',
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        $affected = [];
        $total_replacements = 0;

        foreach ($post_ids as $pid) {
            $raw = get_post_meta($pid, '_elementor_data', true);
            if (empty($raw)) continue;

            $count = 0;
            $new_raw = str_replace($from, $to, (string) $raw, $count);

            if ($count > 0) {
                $affected[] = [
                    'post_id'      => $pid,
                    'post_title'   => get_the_title($pid),
                    'post_type'    => get_post_type($pid),
                    'replacements' => $count,
                ];
                $total_replacements += $count;

                if (!$dry_run) {
                    update_post_meta($pid, '_elementor_data', wp_slash($new_raw));
                    self::invalidate_cache($pid);
                }
            }
        }

        $n = count($affected);
        $label = $dry_run ? '[DRY RUN] Would replace' : 'Replaced';

        return [
            'success'         => true,
            'affected_posts'  => $n,
            'replacements'    => $total_replacements,
            'dry_run'         => $dry_run,
            'details'         => $affected,
            'summary'         => "{$label} \"{$from}\" → \"{$to}\" across {$n} posts ({$total_replacements} total replacements).",
        ];
    }

    // ============================================================
    // HELPERS
    // ============================================================

    private static function is_elementor_loaded(): bool
    {
        return class_exists('\\Elementor\\Plugin') && method_exists('\\Elementor\\Plugin', 'instance');
    }

    private static function get_active_kit_id(): int
    {
        // Try Elementor API
        if (self::is_elementor_loaded()) {
            try {
                return \Elementor\Plugin::$instance->kits_manager->get_active_id();
            } catch (\Throwable $e) {
                // Fall through
            }
        }
        // Fallback to option
        return (int) get_option('elementor_active_kit', 0);
    }

    private static function invalidate_cache(int $post_id): void
    {
        if (class_exists('\\Novamira\\AdrianV2\\Helpers\\Guards')) {
            \Novamira\AdrianV2\Helpers\Guards::invalidate_elementor_cache($post_id);
        } else {
            delete_post_meta($post_id, '_elementor_css');
        }
    }
}

add_action('wp_abilities_api_init', [Site_Tools::class, 'register']);
