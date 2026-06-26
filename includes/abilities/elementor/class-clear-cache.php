<?php

declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Clear_Cache — clears Elementor's CSS file cache and optionally per-post CSS.
 *
 * Handles three scenarios:
 *   1. Global cache clear  → scope: css|all (same as Elementor > Tools > Regenerate CSS)
 *   2. Single-post clear   → post_id + include_nested
 *   3. Multi-post clear    → post_ids[] batch
 *
 * Registered as: novamira-adrianv2/clear-cache
 *
 * @since 1.1.0
 */
class Clear_Cache
{
    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/clear-cache', [
            'label'       => 'Clear Elementor Cache',
            'description' => 'Clears Elementor\'s CSS cache. Supports global clear (scope: css|all), per-post clear (post_id), and batch clear (post_ids). Use include_nested: true to also clear nested referenced posts.',
            'category'    => 'adrianv2-elementor',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'scope' => [
                        'type'        => 'string',
                        'enum'        => ['css', 'all'],
                        'description' => '"css" regenerates CSS files (default). "all" also deletes _elementor_css post meta across all posts.',
                    ],
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'Optional. Clear CSS cache for a specific post/page ID only.',
                    ],
                    'post_ids' => [
                        'type'        => 'array',
                        'description' => 'Optional. Clear CSS cache for multiple post IDs in one call.',
                        'items'       => ['type' => 'integer'],
                    ],
                    'include_nested' => [
                        'type'        => 'boolean',
                        'description' => 'When post_id or post_ids provided: also clears global CSS cache so nested template CSS regenerates. Default: true.',
                    ],
                ],
                'required' => [],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'       => ['type' => 'boolean'],
                    'summary'       => ['type' => 'string'],
                    'cleared_posts' => ['type' => 'array', 'items' => ['type' => 'integer']],
                    'error'         => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => [
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
                'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
            ],
        ]);
    }

    public static function execute($input = null): array
    {
        if (!self::is_elementor_loaded()) {
            return ['success' => false, 'summary' => 'Elementor is not active.'];
        }

        $scope          = (string)  ($input['scope']          ?? 'css');
        $post_id        = (int)     ($input['post_id']         ?? 0);
        $post_ids       = (array)   ($input['post_ids']        ?? []);
        $include_nested = (bool)    ($input['include_nested']  ?? true);

        // Normalise: merge post_id into post_ids list
        if ($post_id > 0 && !in_array($post_id, $post_ids, true)) {
            $post_ids[] = $post_id;
        }

        try {
            $cleared = [];

            if (!empty($post_ids)) {
                // Per-post cache invalidation
                foreach ($post_ids as $pid) {
                    $pid = (int) $pid;
                    if ($pid <= 0 || !get_post($pid)) {
                        continue;
                    }
                    // Delete the generated CSS file for this post
                    self::delete_post_css($pid);
                    $cleared[] = $pid;
                }

                if ($include_nested) {
                    // Global CSS regeneration so nested templates update too
                    \Elementor\Plugin::$instance->files_manager->clear_cache();
                }

                $summary = sprintf(
                    'Post CSS cleared for %d post(s)%s.',
                    count($cleared),
                    $include_nested ? ' + global CSS regenerated' : ''
                );
            } else {
                // Global cache clear
                \Elementor\Plugin::$instance->files_manager->clear_cache();

                if ($scope === 'all') {
                    delete_post_meta_by_key('_elementor_css');
                }

                $summary = "Elementor {$scope} cache cleared and CSS regenerated.";
            }

            return [
                'success'       => true,
                'summary'       => $summary,
                'cleared_posts' => $cleared,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'summary' => 'Cache clear failed: ' . $e->getMessage(),
                'error'   => $e->getMessage(),
            ];
        }
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Delete the generated CSS file for a specific post so Elementor
     * regenerates it on next page load.
     */
    private static function delete_post_css(int $post_id): void
    {
        // Delete _elementor_css meta (forces CSS regeneration)
        delete_post_meta($post_id, '_elementor_css');

        // Also invalidate the WP object cache for this post
        clean_post_cache($post_id);

        // Elementor Document CSS file (if Elementor Pro / files API)
        if (
            isset(\Elementor\Plugin::$instance->files_manager) &&
            method_exists(\Elementor\Plugin::$instance->files_manager, 'get_by_post_id')
        ) {
            $css_file = \Elementor\Plugin::$instance->files_manager->get_by_post_id($post_id);
            if ($css_file && method_exists($css_file, 'delete')) {
                $css_file->delete();
            }
        }
    }

    private static function is_elementor_loaded(): bool
    {
        return class_exists('\Elementor\Plugin') &&
               isset(\Elementor\Plugin::$instance) &&
               isset(\Elementor\Plugin::$instance->files_manager);
    }
}
