<?php
/**
 * Test: Elementor Assign Class To Containers Ability.
 *
 * Locks down the new adrianv2-live-edit ability's registration shape
 * (input schema, output schema, category, idempotent annotations) plus
 * the algorithmic invariants of execute():
 *
 *   - Invalid inputs bail early with `success: false` + `error`.
 *   - DFS only mutates an element (and its descendants) when the
 *     `container_selector` token is present in either `settings.css_classes`
 *     or `settings._css_classes`.
 *   - `recursive=false` mutates the matched container ONLY; descendants
 *     are skipped.
 *   - `append_to_existing=true` keeps the existing class list and appends.
 *   - `append_to_existing=false` replaces the class list with just the
 *     new class on each touched element.
 *   - `custom_css` injection failures surface as `warnings[]` (NOT
 *     `success: false`).
 *
 * @package Novamira\AdrianV2\Tests
 * @since   1.1.0
 */

declare(strict_types=1);

// Helpers (Elementor_Document_Saver / Elementor_CSS_Override / Diagnostics /
// Elementor_Data_Helpers) + ability bootstrap (so ::register() runs).
require_once dirname(__DIR__) . '/includes/helpers/bootstrap.php';
require_once dirname(__DIR__) . '/includes/abilities/elementor/bootstrap.php';

use Novamira\AdrianV2\Abilities\Elementor\Elementor_Assign_Class_To_Containers;
use PHPUnit\Framework\TestCase;

/**
 * Elementor_Assign_Class_To_Containers ability tests.
 *
 * @since 1.1.0
 */
#[CoversClass(Elementor_Assign_Class_To_Containers::class)]
class ElementorAssignClassToContainersTest extends TestCase
{
    // ── Lifecycle ────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        $this->resetGlobals();
        // Default caps: an admin who passes novamira_permission_callback().
        $GLOBALS['_test_caps'] = array(
            'manage_options' => true,
        );
        // Empty registry so each test seeds exactly the tree it needs.
        $GLOBALS['_test_elementor_docs'] = array();
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
                '_test_html_class_map',
                '_test_caps',
                '_wpcode_storage',
                '_wpcode_terms',
                '_wpcode_meta',
                '_test_posts',
            ) as $key
        ) {
            unset( $GLOBALS[ $key ] );
        }
        $GLOBALS['_registered_abilities'] = array();
    }

    private function seedPage(int $id, array $tree): void
    {
        $GLOBALS['_test_elementor_docs'][ $id ] = $tree;
    }

    private function forceMetaUpdateFailure(int $id): void
    {
        $GLOBALS['_test_post_meta_force_fail']                       = $GLOBALS['_test_post_meta_force_fail'] ?? array();
        $GLOBALS['_test_post_meta_force_fail'][ (int) $id ]          = true;
    }

    private function lockPage(int $id): void
    {
        $GLOBALS['_test_post_lock_by_page']                          = $GLOBALS['_test_post_lock_by_page'] ?? array();
        $GLOBALS['_test_post_lock_by_page'][ (int) $id ]             = true;
    }

    private function assertErrorPayload(array $result, string $contains): void
    {
        $this->assertFalse(
            $result['success'],
            sprintf(
                'Expected success=false when payload contains "%s", got success=true with payload: %s',
                $contains,
                wp_json_encode($result)
            )
        );
        $hay = (string) ($result['error'] ?? '');
        $this->assertStringContainsString(
            $contains,
            $hay,
            sprintf('Expected error string to contain "%s", got: %s', $contains, $hay)
        );
    }

    // ── Registration shape ───────────────────────────────────────────────────

    public function test_ability_is_registered(): void
    {
        $this->assertArrayHasKey(
            'novamira-adrianv2/elementor-assign-class-to-containers',
            $GLOBALS['_registered_abilities']
        );
    }

    public function test_category_is_adrianv2_live_edit(): void
    {
        $a = $GLOBALS['_registered_abilities']['novamira-adrianv2/elementor-assign-class-to-containers'];
        $this->assertSame('adrianv2-live-edit', $a['args']['category']);
    }

    public function test_permission_callback_is_novamira_global(): void
    {
        $a = $GLOBALS['_registered_abilities']['novamira-adrianv2/elementor-assign-class-to-containers'];
        $this->assertSame('novamira_permission_callback', $a['args']['permission_callback']);
    }

    public function test_input_schema_requires_page_id_container_selector_and_class(): void
    {
        $a = $GLOBALS['_registered_abilities']['novamira-adrianv2/elementor-assign-class-to-containers'];
        $this->assertSame(
            array('page_id', 'container_selector', 'class'),
            $a['args']['input_schema']['required']
        );
    }

    public function test_input_schema_pattern_constraints_on_selector_and_class(): void
    {
        $a = $GLOBALS['_registered_abilities']['novamira-adrianv2/elementor-assign-class-to-containers'];
        $this->assertSame(
            '^[A-Za-z_\\-][A-Za-z0-9_\\-]{0,63}$',
            $a['args']['input_schema']['properties']['container_selector']['pattern']
        );
        $this->assertSame(
            '^[A-Za-z_\\-][A-Za-z0-9_\\-]{0,63}$',
            $a['args']['input_schema']['properties']['class']['pattern']
        );
    }

    public function test_input_schema_default_append_to_existing_is_true(): void
    {
        $a = $GLOBALS['_registered_abilities']['novamira-adrianv2/elementor-assign-class-to-containers'];
        $this->assertTrue($a['args']['input_schema']['properties']['append_to_existing']['default']);
    }

    public function test_input_schema_default_recursive_is_true(): void
    {
        $a = $GLOBALS['_registered_abilities']['novamira-adrianv2/elementor-assign-class-to-containers'];
        $this->assertTrue($a['args']['input_schema']['properties']['recursive']['default']);
    }

    public function test_input_schema_optional_fields_have_no_default_required(): void
    {
        // custom_css must be optional (no default required).
        $a = $GLOBALS['_registered_abilities']['novamira-adrianv2/elementor-assign-class-to-containers'];
        $this->assertNotContains('custom_css', $a['args']['input_schema']['required']);
    }

    public function test_output_schema_contains_data_modified_ids_and_warnings(): void
    {
        $a = $GLOBALS['_registered_abilities']['novamira-adrianv2/elementor-assign-class-to-containers'];
        $properties = $a['args']['output_schema']['properties'];
        $this->assertArrayHasKey('success', $properties);
        $this->assertArrayHasKey('data', $properties);
        $this->assertArrayHasKey('error', $properties);

        $data_props = $properties['data']['properties'];
        $this->assertArrayHasKey('page_id',                 $data_props);
        $this->assertArrayHasKey('container_selector',      $data_props);
        $this->assertArrayHasKey('class',                   $data_props);
        $this->assertArrayHasKey('matched_containers',      $data_props);
        $this->assertArrayHasKey('elements_modified_count', $data_props);
        $this->assertArrayHasKey('modified_ids',            $data_props);
        $this->assertArrayHasKey('warnings',                $data_props);
    }

    public function test_annotations_are_idempotent_not_destructive_not_readonly(): void
    {
        $annotations = $GLOBALS['_registered_abilities']['novamira-adrianv2/elementor-assign-class-to-containers']
            ['args']['meta']['annotations'];
        $this->assertFalse($annotations['readonly']);
        $this->assertFalse($annotations['destructive']);
        $this->assertTrue($annotations['idempotent']);
    }

    public function test_show_in_rest_and_mcp_public_flags_are_set(): void
    {
        $meta = $GLOBALS['_registered_abilities']['novamira-adrianv2/elementor-assign-class-to-containers']
            ['args']['meta'];
        $this->assertTrue($meta['show_in_rest']);
        $this->assertSame(array('public' => true), $meta['mcp']);
    }

    // ── Execute error paths ──────────────────────────────────────────────────

    public function test_execute_returns_error_when_page_id_invalid(): void
    {
        $result = Elementor_Assign_Class_To_Containers::execute(
            array(
                'page_id'            => 0,
                'container_selector' => 'productslider',
                'class'              => 'newclass',
            )
        );
        $this->assertErrorPayload($result, 'page_id');
    }

    public function test_execute_returns_error_when_input_array_is_null(): void
    {
        $result = Elementor_Assign_Class_To_Containers::execute(null);
        $this->assertFalse($result['success']);
    }

    public function test_execute_returns_error_when_container_selector_missing(): void
    {
        $result = Elementor_Assign_Class_To_Containers::execute(
            array(
                'page_id' => 100,
                'class'   => 'newclass',
            )
        );
        $this->assertErrorPayload($result, 'container_selector');
    }

    public function test_execute_returns_error_when_class_name_missing(): void
    {
        $result = Elementor_Assign_Class_To_Containers::execute(
            array(
                'page_id'            => 100,
                'container_selector' => 'productslider',
            )
        );
        $this->assertErrorPayload($result, 'class');
    }

    public function test_execute_returns_error_when_invalid_selector_after_sanitize(): void
    {
        // sanitize_html_class mock returns the fallback ("") for "*invalid!".
        $GLOBALS['_test_html_class_map']['%bad selector%'] = '';
        $this->seedPage(
            100,
            array(
                array(
                    'id'       => 'c1',
                    'elType'   => 'container',
                    'settings' => array('css_classes' => 'baseline'),
                ),
            )
        );

        $result = Elementor_Assign_Class_To_Containers::execute(
            array(
                'page_id'            => 100,
                'container_selector' => '%bad selector%',
                'class'              => 'newclass',
            )
        );
        $this->assertErrorPayload($result, 'Invalid selector');
    }

    public function test_execute_returns_error_when_no_matching_container(): void
    {
        // Tree has containers but none carry the sought-after class.
        $this->seedPage(
            100,
            array(
                array(
                    'id'       => 'c1',
                    'elType'   => 'container',
                    'settings' => array('css_classes' => 'unrelated'),
                ),
                array(
                    'id'       => 'c2',
                    'elType'   => 'container',
                    'settings' => array('_css_classes' => 'also-unrelated'),
                ),
            )
        );

        $result = Elementor_Assign_Class_To_Containers::execute(
            array(
                'page_id'            => 100,
                'container_selector' => 'productslider',
                'class'              => 'newclass',
            )
        );
        $this->assertErrorPayload($result, 'No element on page');
    }

    public function test_execute_returns_error_when_post_locked_by_another_user(): void
    {
        $this->seedPage(
            100,
            array(
                array(
                    'id'       => 'c1',
                    'elType'   => 'container',
                    'settings' => array('css_classes' => 'productslider'),
                ),
            )
        );
        $this->lockPage(100);

        $result = Elementor_Assign_Class_To_Containers::execute(
            array(
                'page_id'            => 100,
                'container_selector' => 'productslider',
                'class'              => 'newclass',
            )
        );
        $this->assertErrorPayload($result, 'Another user is currently editing');
    }

    // ── Execute happy paths ──────────────────────────────────────────────────

    public function test_execute_recursive_true_mutates_container_and_all_descendants(): void
    {
        $this->seedPage(
            200,
            array(
                array(
                    'id'       => 'container',
                    'elType'   => 'container',
                    'settings' => array(
                        'css_classes' => 'productslider baseline',
                    ),
                    'elements' => array(
                        array(
                            'id'       => 'leaf-a',
                            'elType'   => 'widget',
                            'settings' => array('css_classes' => 'original'),
                            'elements' => array(),
                        ),
                        array(
                            'id'       => 'leaf-b',
                            'elType'   => 'container',
                            'settings' => array('css_classes' => 'original-middle'),
                            'elements' => array(
                                array(
                                    'id'       => 'leaf-b1',
                                    'elType'   => 'widget',
                                    'settings' => array(),
                                    'elements' => array(),
                                ),
                            ),
                        ),
                    ),
                ),
            )
        );

        $result = Elementor_Assign_Class_To_Containers::execute(
            array(
                'page_id'            => 200,
                'container_selector' => 'productslider',
                'class'              => 'newclass',
                'recursive'          => true,
                'append_to_existing' => true,
            )
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['matched_containers']);

        // Container itself + every descendant = 4 elements touched.
        $this->assertSame(4, $result['data']['elements_modified_count']);

        // Re-fetch the tree via the registered docs handler.
        $tree = \Elementor\Plugin::$instance->documents->get(200)->get_elements_data();
        $container_settings = $tree[0]['settings'];
        $this->assertStringContainsString('productslider', $container_settings['css_classes']);
        $this->assertStringContainsString('newclass',       $container_settings['css_classes']);
        $this->assertStringContainsString('productslider', $container_settings['_css_classes']);
        $this->assertStringContainsString('newclass',       $container_settings['_css_classes']);

        // Leaves got the new class appended to their existing list.
        $leaf_a_settings = $tree[0]['elements'][0]['settings'];
        $this->assertSame('original newclass', $leaf_a_settings['css_classes']);
        $this->assertSame('original newclass', $leaf_a_settings['_css_classes']);

        $leaf_b_settings = $tree[0]['elements'][1]['settings'];
        $this->assertSame('original-middle newclass', $leaf_b_settings['css_classes']);
        $this->assertSame('original-middle newclass', $leaf_b_settings['_css_classes']);

        $leaf_b1_settings = $tree[0]['elements'][1]['elements'][0]['settings'];
        $this->assertSame('newclass', $leaf_b1_settings['css_classes']);
        $this->assertSame('newclass', $leaf_b1_settings['_css_classes']);
    }

    public function test_execute_recursive_false_mutates_only_container(): void
    {
        $this->seedPage(
            201,
            array(
                array(
                    'id'       => 'container',
                    'elType'   => 'container',
                    'settings' => array('css_classes' => 'productslider'),
                    'elements' => array(
                        array(
                            'id'       => 'leaf',
                            'elType'   => 'widget',
                            'settings' => array('css_classes' => 'unchanged'),
                            'elements' => array(),
                        ),
                    ),
                ),
            )
        );

        $result = Elementor_Assign_Class_To_Containers::execute(
            array(
                'page_id'            => 201,
                'container_selector' => 'productslider',
                'class'              => 'newclass',
                'recursive'          => false,
                'append_to_existing' => true,
            )
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['elements_modified_count']);

        $tree = \Elementor\Plugin::$instance->documents->get(201)->get_elements_data();
        $this->assertStringContainsString('newclass', $tree[0]['settings']['css_classes']);
        // Leaf is untouched.
        $this->assertSame('unchanged', $tree[0]['elements'][0]['settings']['css_classes']);
    }

    public function test_execute_append_false_replaces_class_list(): void
    {
        $this->seedPage(
            202,
            array(
                array(
                    'id'       => 'container',
                    'elType'   => 'container',
                    'settings' => array('css_classes' => 'productslider foo bar'),
                    'elements' => array(),
                ),
            )
        );

        $result = Elementor_Assign_Class_To_Containers::execute(
            array(
                'page_id'            => 202,
                'container_selector' => 'productslider',
                'class'              => 'replacement',
                'recursive'          => false,
                'append_to_existing' => false,
            )
        );

        $this->assertTrue($result['success']);
        $tree = \Elementor\Plugin::$instance->documents->get(202)->get_elements_data();
        // The class list is replaced (only "replacement" — not "foo bar" anymore
        // since we matched "productslider" and replaced with just "replacement").
        // Note: the helper still emits "productslider" too if recursively found,
        // but with recursive=false + replace=true on a single container we set
        // the list to just "replacement".
        $this->assertSame('replacement', trim($tree[0]['settings']['css_classes']));
    }

    public function test_execute_appends_to_existing_classes_by_default(): void
    {
        // No append_to_existing key passed → defaults to true.
        $this->seedPage(
            203,
            array(
                array(
                    'id'       => 'container',
                    'elType'   => 'container',
                    'settings' => array('css_classes' => 'productslider baseline'),
                    'elements' => array(),
                ),
            )
        );

        $result = Elementor_Assign_Class_To_Containers::execute(
            array(
                'page_id'            => 203,
                'container_selector' => 'productslider',
                'class'              => 'newclass',
                'recursive'          => false,
            )
        );

        $this->assertTrue($result['success']);
        $tree = \Elementor\Plugin::$instance->documents->get(203)->get_elements_data();
        $this->assertSame('productslider baseline newclass', $tree[0]['settings']['css_classes']);
    }

    public function test_execute_does_not_match_unrelated_class_tokens(): void
    {
        // Class token is "productslider"; the tree only carries "unrelated".
        $this->seedPage(
            204,
            array(
                array(
                    'id'       => 'container',
                    'elType'   => 'container',
                    'settings' => array('css_classes' => 'unrelated'),
                    'elements' => array(),
                ),
            )
        );

        $result = Elementor_Assign_Class_To_Containers::execute(
            array(
                'page_id'            => 204,
                'container_selector' => 'productslider',
                'class'              => 'newclass',
            )
        );

        $this->assertErrorPayload($result, 'No element on page');
    }

    public function test_execute_matches_class_token_in_underscore_prefixed_setting(): void
    {
        // Elementor 4.x sometimes stores classes in _css_classes without the
        // css_classes mirror. The helper must find containers either way.
        $this->seedPage(
            205,
            array(
                array(
                    'id'       => 'container',
                    'elType'   => 'container',
                    'settings' => array('_css_classes' => 'productslider'),
                    'elements' => array(),
                ),
            )
        );

        $result = Elementor_Assign_Class_To_Containers::execute(
            array(
                'page_id'            => 205,
                'container_selector' => 'productslider',
                'class'              => 'newclass',
                'recursive'          => false,
            )
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['matched_containers']);
    }

    public function test_execute_surfaces_warning_when_custom_css_inject_fails(): void
    {
        // Real happy-path tree + a custom_css payload. We force update_post_meta
        // to fail so inject_page_custom_css returns WP_Error → soft-fail warning.
        $this->seedPage(
            206,
            array(
                array(
                    'id'       => 'container',
                    'elType'   => 'container',
                    'settings' => array('css_classes' => 'productslider'),
                    'elements' => array(),
                ),
            )
        );
        $this->forceMetaUpdateFailure(206);

        $result = Elementor_Assign_Class_To_Containers::execute(
            array(
                'page_id'            => 206,
                'container_selector' => 'productslider',
                'class'              => 'newclass',
                'recursive'          => false,
                'custom_css'         => '.newclass { color: red; }',
            )
        );

        $this->assertTrue(
            $result['success'],
            'Class assignment succeeded; CSS injection failure must NOT flip success.'
        );
        $this->assertNotEmpty($result['data']['warnings']);
        $this->assertStringContainsString(
            'inject_page_custom_css failed',
            implode(' ', $result['data']['warnings'])
        );
    }

    public function test_execute_succeeds_silently_when_custom_css_inject_succeeds(): void
    {
        $this->seedPage(
            207,
            array(
                array(
                    'id'       => 'container',
                    'elType'   => 'container',
                    'settings' => array('css_classes' => 'productslider'),
                    'elements' => array(),
                ),
            )
        );

        $result = Elementor_Assign_Class_To_Containers::execute(
            array(
                'page_id'            => 207,
                'container_selector' => 'productslider',
                'class'              => 'newclass',
                'recursive'          => false,
                'custom_css'         => '.newclass { color: red; }',
            )
        );

        $this->assertTrue($result['success']);
        $this->assertSame(array(), $result['data']['warnings']);
        // update_post_meta was called for the page (the Bump CSS meta).
        $this->assertNotEmpty($GLOBALS['_test_post_meta_update_calls']);
        $page_keys = array_column($GLOBALS['_test_post_meta_update_calls'], 'post_id');
        $this->assertContains(207, $page_keys);
    }

    public function test_execute_persists_tree_via_elementor_document_saver(): void
    {
        $this->seedPage(
            208,
            array(
                array(
                    'id'       => 'container',
                    'elType'   => 'container',
                    'settings' => array('css_classes' => 'productslider'),
                    'elements' => array(),
                ),
            )
        );

        Elementor_Assign_Class_To_Containers::execute(
            array(
                'page_id'            => 208,
                'container_selector' => 'productslider',
                'class'              => 'newclass',
                'recursive'          => false,
            )
        );

        // The Document Saver should have:
        //   1. Asked Elementor plugin for the document and called update_json_meta.
        //   2. Called files_manager->clear_cache().
        //   3. Called clean_post_cache(208).
        $this->assertNotEmpty($GLOBALS['_test_elementor_update_json_meta']);
        $calls = $GLOBALS['_test_elementor_update_json_meta'];
        $this->assertSame('_elementor_data', end($calls)['k']);
        $this->assertNotEmpty(end($calls)['v']);

        $this->assertGreaterThanOrEqual(1, $GLOBALS['_test_files_manager_clear_calls']);
        $this->assertContains(208, $GLOBALS['_test_clean_post_cache']);
    }

    public function test_execute_dedupes_modified_ids_across_duplicate_hits(): void
    {
        // Two matched containers, each a parent of the same leaf-ish id (synthesised).
        // The ability flattens modified_ids via array_unique before counting.
        $this->seedPage(
            209,
            array(
                array(
                    'id'       => 'c1',
                    'elType'   => 'container',
                    'settings' => array('css_classes' => 'productslider'),
                    'elements' => array(
                        array(
                            'id'       => 'leaf',
                            'elType'   => 'widget',
                            'settings' => array(),
                            'elements' => array(),
                        ),
                    ),
                ),
                array(
                    'id'       => 'c2',
                    'elType'   => 'container',
                    'settings' => array('css_classes' => 'productslider'),
                    'elements' => array(
                        array(
                            'id'       => 'leaf',
                            'elType'   => 'widget',
                            'settings' => array(),
                            'elements' => array(),
                        ),
                    ),
                ),
            )
        );

        $result = Elementor_Assign_Class_To_Containers::execute(
            array(
                'page_id'            => 209,
                'container_selector' => 'productslider',
                'class'              => 'newclass',
            )
        );

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['matched_containers']);
        $this->assertCount(2, $result['data']['modified_ids']); // c1 + c2 unique, leaves duplicate.
    }
}
