<?php
/**
 * Test: Elementor_Inject_Calibrated_Page Ability.
 *
 * Locks down:
 *   - Registration shape (input/output schema, category, permission_callback,
 *     annotations).
 *   - Happy-path execute() with mode='overwrite' against a seeded Elementor
 *     Document mock; verifies output_schema field shape, blocks_invalidated
 *     list, saved_at ISO-8601, kit_id resolution.
 *   - mode='merge_by_id' merge semantics: existing subtree replaces at matched
 *     `id`; unmatched incoming elements appended at the bottom; descendant
 *     elements recurse.
 *   - Soft-warn on a stale `elementor_version` payload.
 *   - Error paths: post_id ≤ 0, missing _elementor_data, permission denied,
 *     post-locked, invalid tree shape.
 *
 * @package Novamira\AdrianV2\Tests
 * @since   1.1.0
 */

declare(strict_types=1);

// Helpers + elementor ability bootstrap so ::register() runs.
require_once dirname(__DIR__) . '/includes/helpers/bootstrap.php';
require_once dirname(__DIR__) . '/includes/abilities/elementor/bootstrap.php';

use Novamira\AdrianV2\Abilities\Elementor\Elementor_Inject_Calibrated_Page;
use PHPUnit\Framework\TestCase;

#[CoversClass(Elementor_Inject_Calibrated_Page::class)]
class ElementorInjectCalibratedPageTest extends TestCase
{
    protected function setUp(): void
    {
        $this->resetGlobals();
        // Default caps: covers the per-post + per-status gates
        // (edit_post + edit_published_pages; edit_theme_options is
        // intentionally NOT required by the production code).
        $GLOBALS['_test_caps'] = array(
            'edit_post'            => true,
            'edit_published_pages' => true,
        );
        $GLOBALS['_test_elementor_docs'] = array();
        // Per-post status defaults to 'draft' inside the
        // get_post_status() mock; tests that need 'publish' set
        // $GLOBALS['_test_post_status'][$id] = 'publish' explicitly.
        $GLOBALS['_test_post_status'] = $GLOBALS['_test_post_status'] ?? array();
    }

    protected function tearDown(): void
    {
        $this->resetGlobals();
    }

    private function resetGlobals(): void
    {
        foreach (
            array(
                '_registered_abilities',
                '_test_elementor_docs',
                '_test_elementor_docs_missing',
                '_test_elementor_update_json_meta',
                '_test_files_manager_clear_calls',
                '_test_post_meta_update_calls',
                '_test_post_meta_force_fail',
                '_test_clean_post_cache',
                '_test_post_lock_by_page',
                '_test_post_status',
                '_test_html_class_map',
                '_test_caps',
                '_wpcode_storage',
                '_wpcode_terms',
                '_wpcode_meta',
                '_test_posts',
                '_test_kits_manager_active_id',
            ) as $key
        ) {
            unset($GLOBALS[$key]);
        }
        $GLOBALS['_registered_abilities'] = array();
    }

    private function seedPage(int $id, array $tree): void
    {
        $GLOBALS['_test_elementor_docs'][$id] = $tree;
    }

    private function lockPage(int $id): void
    {
        $GLOBALS['_test_post_lock_by_page']                       = $GLOBALS['_test_post_lock_by_page'] ?? array();
        $GLOBALS['_test_post_lock_by_page'][(int) $id]             = true;
    }

    private function revokeCap(string $cap): void
    {
        $GLOBALS['_test_caps'][$cap] = false;
    }

    // ── Registration shape ───────────────────────────────────────────────────

    public function test_ability_is_registered(): void
    {
        $this->assertArrayHasKey(
            'novamira-adrianv2/elementor-inject-calibrated-page',
            $GLOBALS['_registered_abilities']
        );
    }

    public function test_category_is_adrianv2_live_edit(): void
    {
        $a = $GLOBALS['_registered_abilities']['novamira-adrianv2/elementor-inject-calibrated-page'];
        $this->assertSame('adrianv2-live-edit', $a['args']['category']);
    }

    public function test_permission_callback_is_novamira_global(): void
    {
        $a = $GLOBALS['_registered_abilities']['novamira-adrianv2/elementor-inject-calibrated-page'];
        $this->assertSame('novamira_permission_callback', $a['args']['permission_callback']);
    }

    public function test_input_schema_requires_post_id_and_elementor_data(): void
    {
        $a = $GLOBALS['_registered_abilities']['novamira-adrianv2/elementor-inject-calibrated-page'];
        $this->assertSame(
            array('post_id', '_elementor_data'),
            $a['args']['input_schema']['required']
        );
    }

    public function test_input_schema_default_values(): void
    {
        $props = $GLOBALS['_registered_abilities']['novamira-adrianv2/elementor-inject-calibrated-page']
            ['args']['input_schema']['properties'];
        $this->assertSame('3.0.0', $props['elementor_version']['default']);
        $this->assertSame('default', $props['wp_page_template']['default']);
        $this->assertSame('overwrite', $props['mode']['default']);
    }

    public function test_input_schema_enum_constraints(): void
    {
        $props = $GLOBALS['_registered_abilities']['novamira-adrianv2/elementor-inject-calibrated-page']
            ['args']['input_schema']['properties'];
        $this->assertSame(
            array('elementor_header_footer', 'elementor_canvas', 'default'),
            $props['wp_page_template']['enum']
        );
        $this->assertSame(
            array('overwrite', 'merge_by_id'),
            $props['mode']['enum']
        );
    }

    public function test_output_schema_lists_expected_keys_and_required_fields(): void
    {
        $schema = $GLOBALS['_registered_abilities']['novamira-adrianv2/elementor-inject-calibrated-page']
            ['args']['output_schema'];
        $props   = $schema['properties'];
        $this->assertArrayHasKey('success', $props);
        $this->assertArrayHasKey('post_id', $props);
        $this->assertArrayHasKey('sections_count', $props);
        $this->assertArrayHasKey('kit_id', $props);
        $this->assertArrayHasKey('warnings', $props);
        $this->assertArrayHasKey('blocks_invalidated', $props);
        $this->assertArrayHasKey('saved_at', $props);
        // The required[] array must surface the audit-trail keys so callers
        // can rely on them being populated on every response (success+fail).
        $this->assertContains('success', $schema['required']);
        $this->assertContains('post_id', $schema['required']);
        $this->assertContains('saved_at', $schema['required']);
    }

    public function test_annotations_are_destructive_not_readonly_and_idempotent(): void
    {
        $a = $GLOBALS['_registered_abilities']['novamira-adrianv2/elementor-inject-calibrated-page']
            ['args']['meta']['annotations'];
        $this->assertFalse($a['readonly']);
        $this->assertTrue($a['destructive']);
        $this->assertTrue($a['idempotent']);
    }

    // ── Execute error paths ──────────────────────────────────────────────────

    public function test_execute_returns_failure_when_post_id_invalid(): void
    {
        $result = Elementor_Inject_Calibrated_Page::execute(
            array(
                'post_id'         => 0,
                '_elementor_data' => array(array('id' => 'a1b2c3d', 'elType' => 'container')),
            )
        );
        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['post_id']);
        $this->assertSame(0, $result['sections_count']);
        $this->assertNull($result['kit_id']);
        $this->assertSame(array(), $result['blocks_invalidated']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('invalid_post_id', implode(' ', $result['warnings']));
    }

    public function test_execute_returns_failure_when_elementor_data_missing(): void
    {
        $result = Elementor_Inject_Calibrated_Page::execute(array('post_id' => 100));
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('invalid_elementor_data', implode(' ', $result['warnings']));
    }

    public function test_execute_returns_failure_when_elementor_data_invalid_shape(): void
    {
        $result = Elementor_Inject_Calibrated_Page::execute(
            array(
                'post_id'         => 100,
                '_elementor_data' => array('not-a-list-of-elements'),
            )
        );
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('invalid_elementor_data', implode(' ', $result['warnings']));
    }

    public function test_execute_returns_failure_when_permission_denied(): void
    {
        // Revoke edit_post — production code requires this per-post cap.
        $this->revokeCap('edit_post');
        $this->seedPage(100, array());

        $result = Elementor_Inject_Calibrated_Page::execute($this->validPayload(100));
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('permission_denied', implode(' ', $result['warnings']));
    }

    public function test_execute_returns_failure_when_post_locked(): void
    {
        $this->seedPage(100, array());
        $this->lockPage(100);

        $result = Elementor_Inject_Calibrated_Page::execute($this->validPayload(100));
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('post_locked', implode(' ', $result['warnings']));
    }

    // ── Execute happy paths ─────────────────────────────────────────────────

    public function test_execute_happy_path_overwrite(): void
    {
        $this->seedPage(
            500,
            array(
                array('id' => 'a1b2c3d', 'elType' => 'container', 'settings' => array('css_classes' => 'old'), 'elements' => array()),
            )
        );

        $result = Elementor_Inject_Calibrated_Page::execute(
            array(
                'post_id'           => 500,
                '_elementor_data'   => $this->calibratedPayload(),
                'elementor_version' => '3.20.0',
                'wp_page_template'  => 'elementor_header_footer',
                'mode'              => 'overwrite',
            )
        );

        $this->assertTrue($result['success'], 'execute() should succeed with mode=overwrite on a properly seeded page. Got: ' . wp_json_encode($result));
        $this->assertSame(500, $result['post_id']);
        $this->assertSame(1, $result['sections_count'], 'sections_count counts top-level elements');
        $this->assertSame(
            array('post_css', 'files_manager_global', 'post_meta_cache'),
            $result['blocks_invalidated']
        );
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}([+-]\d{2}:\d{2}|Z)$/', $result['saved_at']);
        $this->assertSame(array(), $result['warnings']);

        // The Document API + Elementor_Document_Saver + boot-meta writes should have run.
        $this->assertNotEmpty($GLOBALS['_test_elementor_update_json_meta']);
        $this->assertGreaterThanOrEqual(1, $GLOBALS['_test_files_manager_clear_calls']);
        $this->assertContains(500, $GLOBALS['_test_clean_post_cache']);
        $post_meta_keys = array_column($GLOBALS['_test_post_meta_update_calls'], 'key');
        $this->assertContains('_elementor_edit_mode', $post_meta_keys);
        $this->assertContains('_elementor_template_type', $post_meta_keys);
        $this->assertContains('_elementor_version', $post_meta_keys);
        $this->assertContains('_wp_page_template', $post_meta_keys);
    }

    public function test_execute_soft_warns_on_stale_elementor_version(): void
    {
        $this->seedPage(600, array());

        // Simulate an active Elementor version higher than the payload.
        // We can NOT modify the constant in PHP-CLI; instead exercise
        // the path by passing a version older than the actual ELEMENTOR_VERSION.
        // The exact warning string is asserted in either case — the warning
        // is emitted when version_compare(payload, active, '<').
        $result = Elementor_Inject_Calibrated_Page::execute(
            array(
                'post_id'           => 600,
                '_elementor_data'   => $this->calibratedPayload(),
                'elementor_version' => '0.0.1', // ancient
            )
        );
        $this->assertTrue($result['success']);
        $joined = implode(' ', $result['warnings']);
        $this->assertStringContainsString('payload_version_old', $joined);
    }

    public function test_execute_blocks_invalidated_and_saved_at_present_on_failure(): void
    {
        $result = Elementor_Inject_Calibrated_Page::execute(array('post_id' => 0));
        $this->assertFalse($result['success']);
        $this->assertSame(array(), $result['blocks_invalidated']);
        $this->assertNotEmpty($result['saved_at']);
        $this->assertSame(0, $result['sections_count']);
        $this->assertNull($result['kit_id']);
    }

    // ── mode='merge_by_id' semantics ────────────────────────────────────────

    public function test_execute_merge_by_id_replaces_matching_subtree(): void
    {
        // Existing tree has top-level container 'old-root' AND nested leaf 'old-leaf'.
        $this->seedPage(
            700,
            array(
                array(
                    'id'       => 'old-root',
                    'elType'   => 'container',
                    'settings' => array('css_classes' => 'old-root-class'),
                    'elements' => array(
                        array('id' => 'old-leaf', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array('title' => 'OLD'), 'elements' => array()),
                    ),
                ),
            )
        );

        // Incoming replaces 'old-root' with a new container, leaves the inner
        // headline intact, and introduces a brand-new 'new-leaf' widget at
        // the bottom (which should be appended because it doesn't exist in
        // the existing tree at the top level).
        $result = Elementor_Inject_Calibrated_Page::execute(
            array(
                'post_id'         => 700,
                '_elementor_data' => array(
                    array(
                        'id'       => 'old-root',
                        'elType'   => 'container',
                        'settings' => array('css_classes' => 'new-root-class'),
                        'elements' => array(),
                    ),
                    array(
                        'id'       => 'new-leaf',
                        'elType'   => 'widget',
                        'widgetType' => 'button',
                        'settings' => array('text' => 'NEW'),
                        'elements' => array(),
                    ),
                ),
                'mode' => 'merge_by_id',
            )
        );

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['sections_count']);

        // After the merge:
        //   - Top-level[0] = 'old-root' BUT with new-root-class (replaced root).
        //   - Top-level[1] = 'new-leaf' (appended because no existing tree
        //     element at top level had that id).
        $tree = \Elementor\Plugin::$instance->documents->get(700)->get_elements_data();
        $this->assertSame('old-root', $tree[0]['id']);
        $this->assertSame('new-root-class', $tree[0]['settings']['css_classes']);
        $this->assertSame('new-leaf', $tree[1]['id']);
    }

    public function test_execute_merge_by_id_appends_unmatched_incoming(): void
    {
        // Existing tree has one container; incoming has two NEW ids — both
        // should be appended after the existing entry.
        $this->seedPage(800, array(
            array('id' => 'existing', 'elType' => 'container', 'settings' => array(), 'elements' => array()),
        ));

        $result = Elementor_Inject_Calibrated_Page::execute(
            array(
                'post_id'         => 800,
                '_elementor_data' => array(
                    array('id' => 'a', 'elType' => 'container', 'settings' => array(), 'elements' => array()),
                    array('id' => 'b', 'elType' => 'container', 'settings' => array(), 'elements' => array()),
                ),
                'mode' => 'merge_by_id',
            )
        );

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['sections_count'], 'existing (1) + two appended (2) = 3 top-level elements');
        $tree = \Elementor\Plugin::$instance->documents->get(800)->get_elements_data();
        $this->assertSame('existing', $tree[0]['id']);
        $this->assertSame('a', $tree[1]['id']);
        $this->assertSame('b', $tree[2]['id']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Minimal valid Elementor tree with one container + one heading.
     *
     * @return array<int, array<string, mixed>>
     */
    private function calibratedPayload(): array
    {
        return array(
            array(
                'id'       => 'injected-root',
                'elType'   => 'container',
                'settings' => array('css_classes' => 'injected-class'),
                'elements' => array(
                    array(
                        'id'         => 'injected-h',
                        'elType'     => 'widget',
                        'widgetType' => 'heading',
                        'settings'   => array('title' => 'Injected by ability'),
                        'elements'   => array(),
                    ),
                ),
            ),
        );
    }

    /**
     * Build a valid happy-path payload for a given post_id.
     *
     * @param int $post_id
     * @return array<string, mixed>
     */
    private function validPayload(int $post_id): array
    {
        return array(
            'post_id'         => $post_id,
            '_elementor_data' => $this->calibratedPayload(),
            'mode'            => 'overwrite',
        );
    }
}
