<?php
/**
 * Test: Rollback_Build — WP revision snapshot & rollback (6 cases).
 *
 * @package Novamira\AdrianV2\Tests
 * @since   1.1.0
 */

declare(strict_types=1);

// In mock mode, wp_abilities_api_init never fires, so we must
// require the class file directly.
require_once __DIR__ . '/../includes/abilities/v4-management/class-rollback-build.php';

use Novamira\AdrianV2\Abilities\V4Management\Rollback_Build;
use PHPUnit\Framework\TestCase;

#[CoversClass(Rollback_Build::class)]
class RollbackBuildTest extends TestCase
{
    private const POST_V4 = 42;
    private const POST_V3 = 43;

    protected function setUp(): void
    {
        // Reset all global test state.
        $GLOBALS['_wpcode_meta']              = [];
        $GLOBALS['_test_wp_cache']            = [];
        $GLOBALS['_test_posts']               = [];
        $GLOBALS['_test_revisions']           = [];
        $GLOBALS['_test_revision_metadata']   = [];
        $GLOBALS['_test_revision_counter']    = 0;
        $GLOBALS['_test_post_meta_update_calls'] = [];
        $GLOBALS['_registered_abilities']     = [];

        // Seed posts so get_post() returns them.
        $GLOBALS['_test_posts'][self::POST_V4] = [
            'ID'          => self::POST_V4,
            'post_title'  => 'V4 Atomic Page',
            'post_content' => '',
            'post_status' => 'publish',
            'post_type'   => 'page',
        ];
        $GLOBALS['_test_posts'][self::POST_V3] = [
            'ID'          => self::POST_V3,
            'post_title'  => 'V3 Page',
            'post_content' => '',
            'post_status' => 'publish',
            'post_type'   => 'page',
        ];

        // Seed V4 page meta.
        $v4_data = json_encode([
            ['id' => 'root', 'elType' => 'e-flexbox', 'elements' => [
                ['id' => 'heading', 'elType' => 'e-heading', 'settings' => ['title' => 'Hello']],
            ]],
        ]);
        $GLOBALS['_wpcode_meta'][self::POST_V4]['_elementor_edit_mode'] = 'builder';
        $GLOBALS['_wpcode_meta'][self::POST_V4]['_elementor_data']      = $v4_data;

        // Seed V3 page meta (no V4 atomic containers).
        $GLOBALS['_wpcode_meta'][self::POST_V3]['_elementor_edit_mode'] = 'builder';
        $GLOBALS['_wpcode_meta'][self::POST_V3]['_elementor_data'] = json_encode([
            ['id' => 'section', 'elType' => 'section', 'elements' => []],
        ]);

        Rollback_Build::register();
    }

    // ── Input validation ─────────────────────────────────────────────────────

    public function test_execute_rejects_invalid_post_id(): void
    {
        $result = Rollback_Build::execute(['post_id' => 0]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_post', array_key_first($result->errors));
    }

    public function test_execute_guards_v3_page(): void
    {
        $result = Rollback_Build::execute([
            'post_id' => self::POST_V3,
            'action'  => 'snapshot',
        ]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('v4_required', array_key_first($result->errors),
            'Rollback must refuse V3 pages with v4_required error');
    }

    // ── Snapshot ─────────────────────────────────────────────────────────────

    public function test_snapshot_creates_revision_with_custom_meta(): void
    {
        $result = Rollback_Build::execute([
            'post_id' => self::POST_V4,
            'action'  => 'snapshot',
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame('snapshot', $result['action']);
        $this->assertArrayHasKey('revision_id', $result);
        $revisionId = $result['revision_id'];

        // Verify the revision was stored with correct meta.
        $this->assertArrayHasKey($revisionId, $GLOBALS['_test_revisions'],
            'wp_save_post_revision must have been called');
        $this->assertSame(
            'good',
            $GLOBALS['_test_revision_metadata'][$revisionId]['_novamira_rollback_status'] ?? '',
            'Revision must be tagged with _novamira_rollback_status=good'
        );
        $this->assertNotEmpty(
            $GLOBALS['_test_revision_metadata'][$revisionId]['_novamira_elementor_snapshot'] ?? '',
            'Revision must store _elementor_data as _novamira_elementor_snapshot meta'
        );
    }

    public function test_snapshot_fails_when_no_elementor_data(): void
    {
        // Remove _elementor_data but keep _elementor_version=4.0.0 so
        // the page is still detected as V4 (V4 guard passes, but
        // create_snapshot rejects the empty data).
        unset($GLOBALS['_wpcode_meta'][self::POST_V4]['_elementor_data']);
        $GLOBALS['_wpcode_meta'][self::POST_V4]['_elementor_version'] = '4.0.0';

        $result = Rollback_Build::execute([
            'post_id' => self::POST_V4,
            'action'  => 'snapshot',
        ]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('no_data', array_key_first($result->errors));
    }

    // ── Rollback (restore) ───────────────────────────────────────────────────

    public function test_rollback_restores_elementor_data_from_snapshot(): void
    {
        // 1. Create a snapshot.
        $snap = Rollback_Build::execute([
            'post_id' => self::POST_V4,
            'action'  => 'snapshot',
        ]);
        $this->assertTrue($snap['success']);
        $revisionId = $snap['revision_id'];

        // 2. Mutate the live _elementor_data (simulating a destructive build).
        $mutated = json_encode([
            ['id' => 'broken', 'elType' => 'e-flexbox', 'elements' => []],
        ]);
        $GLOBALS['_wpcode_meta'][self::POST_V4]['_elementor_data'] = $mutated;

        // 3. Rollback to the snapshot.
        $rollback = Rollback_Build::execute([
            'post_id'     => self::POST_V4,
            'action'      => 'rollback',
            'revision_id' => $revisionId,
        ]);

        $this->assertIsArray($rollback);
        $this->assertTrue($rollback['success']);
        $this->assertSame('rollback', $rollback['action']);
        $this->assertSame($revisionId, $rollback['rolled_back_to']);

        // 4. Verify _elementor_data was restored.
        $updateCalls = $GLOBALS['_test_post_meta_update_calls'] ?? [];
        $dataCalls = array_filter($updateCalls, fn($c) => '_elementor_data' === $c['key']);
        $this->assertNotEmpty($dataCalls,
            'Rollback must call update_post_meta for _elementor_data');

        $lastUpdate = end($dataCalls);
        $restored = is_string($lastUpdate['value']) ? stripslashes($lastUpdate['value']) : '';
        $this->assertStringContainsString('e-heading', $restored,
            'Restored data must contain the original e-heading element');
    }

    public function test_rollback_fails_without_any_snapshot(): void
    {
        // No snapshot created → rollback must fail.
        $result = Rollback_Build::execute([
            'post_id' => self::POST_V4,
            'action'  => 'rollback',
        ]);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('no_snapshot', array_key_first($result->errors));
    }
}
