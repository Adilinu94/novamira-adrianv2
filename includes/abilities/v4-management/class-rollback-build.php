<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\V4Management;

use Novamira\AdrianV2\Helpers\Elementor_Version_Resolver;

if (!defined('ABSPATH')) { exit; }

/**
 * Rollback_Build — WP revision-based rollback for Elementor page builds.
 *
 * Snapshots _elementor_data as a WP revision before a destructive build,
 * then restores from the latest revision tagged _novamira_rollback_status=good.
 *
 * @package Novamira_AdrianV2
 * @since   1.1.0
 */
class Rollback_Build {

    /** WP revision meta key for rollback status tracking. */
    private const ROLLBACK_META = '_novamira_rollback_status';

    /**
     * Register the ability.
     */
    public static function register(): void {
        wp_register_ability('novamira-adrianv2/rollback-build', [
            'name'        => 'novamira-adrianv2/rollback-build',
            'label'       => __('Rollback Build', 'novamira-adrianv2'),
            'description' => __('Snapshot and rollback Elementor page data via WP revisions.', 'novamira-adrianv2'),
            'category'    => 'adrianv2-v4-management',
            'callback'    => [self::class, 'execute'],
            'schema'      => [
                'type'       => 'object',
                'properties' => [
                    'post_id'     => ['type' => 'integer', 'required' => true],
                    'revision_id' => ['type' => 'integer', 'default' => null],
                    'action'      => ['type' => 'string', 'enum' => ['snapshot', 'rollback'], 'default' => 'rollback'],
                ],
            ],
            'permission_callback' => fn() => current_user_can('manage_options'),
            'mcp' => ['public' => true, 'type' => 'tool'],
        ]);
    }

    /**
     * Execute: snapshot or rollback.
     *
     * @param array|null $input
     * @return array|\WP_Error
     */
    public static function execute($input = null) {
        $post_id     = (int) ($input['post_id'] ?? 0);
        $revision_id = $input['revision_id'] ?? null;
        $action      = $input['action'] ?? 'rollback';

        if ($post_id <= 0) {
            return new \WP_Error('invalid_post', __('Valid post_id required.', 'novamira-adrianv2'));
        }

        // V4 guard (1.1.0): WP revision snapshots make sense for atomic-tree edits.
        if (!Elementor_Version_Resolver::page_is_v4($post_id)) {
            return new \WP_Error('v4_required', __('Rollback build is designed for V4 atomic pages. Use WP revisions directly for V3 pages.', 'novamira-adrianv2'));
        }

        if ('snapshot' === $action) {
            return self::create_snapshot($post_id);
        }

        return self::restore_snapshot($post_id, $revision_id);
    }

    /**
     * Create a WP revision snapshot of the current _elementor_data.
     *
     * @param int $post_id
     * @return array
     */
    private static function create_snapshot(int $post_id): array|\WP_Error {
        $data = get_post_meta($post_id, '_elementor_data', true);
        if (empty($data)) {
            return new \WP_Error('no_data', __('No _elementor_data found for this post.', 'novamira-adrianv2'));
        }

        // Create a WP revision, then manually store _elementor_data as
        // custom meta on the revision (wp_save_post_revision only copies
        // post_content/title/excerpt, NOT post_meta).
        $revision_id = wp_save_post_revision($post_id);
        if (!$revision_id || is_wp_error($revision_id)) {
            return new \WP_Error('snapshot_failed', __('Failed to create WP revision snapshot.', 'novamira-adrianv2'));
        }

        // Store the Elementor tree as custom meta on the revision.
        update_metadata('post', $revision_id, '_novamira_elementor_snapshot', wp_slash($data));
        update_metadata('post', $revision_id, self::ROLLBACK_META, 'good');

        return [
            'success'      => true,
            'post_id'      => $post_id,
            'revision_id'  => $revision_id,
            'action'       => 'snapshot',
            'summary'      => sprintf('Revision snapshot %d created with _elementor_data stored.', $revision_id),
        ];
    }

    /**
     * Restore _elementor_data from a WP revision snapshot.
     *
     * @param int      $post_id
     * @param int|null $revision_id Specific revision, or null for latest "good" one.
     * @return array|\WP_Error
     */
    private static function restore_snapshot(int $post_id, $revision_id) {
        if (null === $revision_id) {
            // Find latest revision tagged as "good".
            $revisions = wp_get_post_revisions($post_id, [
                'posts_per_page' => 1,
                'meta_key'       => self::ROLLBACK_META,
                'meta_value'     => 'good',
                'orderby'        => 'ID',
                'order'          => 'DESC',
            ]);

            if (empty($revisions)) {
                return new \WP_Error('no_snapshot', __('No rollback snapshot found. Create one with action: snapshot first.', 'novamira-adrianv2'));
            }

            $revision    = reset($revisions);
            $revision_id = $revision->ID;
        }

        $revision = wp_get_post_revision($revision_id);
        if (!$revision) {
            return new \WP_Error('revision_not_found', sprintf(__('Revision %d not found.', 'novamira-adrianv2'), $revision_id));
        }

        // Restore _elementor_data from the custom meta stored on the revision.
        $restored_data = get_metadata('post', $revision_id, '_novamira_elementor_snapshot', true);
        if (empty($restored_data)) {
            return new \WP_Error('empty_revision', __('Revision has no Elementor snapshot data to restore.', 'novamira-adrianv2'));
        }

        update_post_meta($post_id, '_elementor_data', wp_slash($restored_data));
        \Novamira\AdrianV2\Helpers\Guards::invalidate_elementor_cache($post_id);

        return [
            'success'      => true,
            'post_id'      => $post_id,
            'rolled_back_to' => $revision_id,
            'action'       => 'rollback',
            'summary'      => sprintf('Rolled back to revision %d. Cache invalidated.', $revision_id),
        ];
    }
}
