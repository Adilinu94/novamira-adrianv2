<?php
/**
 * Test: WPCode Snippet Ability
 *
 * Covers the 4 core CRUD abilities (list/get/create/delete) plus a handful of
 * targeted registration-shape assertions on the new
 * Novamira\AdrianV2\Abilities\WpCode\WpCode_Snippets class — written so the
 * next CI run catches regressions if any of the abilities' input/output
 * schemas, permission gates, or WP_Error codes change.
 *
 * @package Novamira\AdrianV2\Tests
 * @since   1.1.0
 */

declare(strict_types=1);

// Boot the new ability class so PHPUnit's autoloader does not need to know about it.
require_once dirname(__DIR__) . '/includes/abilities/wpcode/bootstrap.php';

use Novamira\AdrianV2\Abilities\WpCode\WpCode_Snippets;
use PHPUnit\Framework\TestCase;

/**
 * WPCode Snippets Ability Tests.
 *
 * @since 1.1.0
 */
#[CoversClass(WpCode_Snippets::class)]
class WpCodeSnippetsAbilityTest extends TestCase
{
    // ── Lifecycle ────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        $this->resetGlobals();

        // Default caps: an admin with everything required to call any property
        // on every write-style ability.
        $GLOBALS['_test_caps'] = array(
            'manage_options'             => true,
            'unfiltered_html'            => true,
            'wpcode_activate_snippets'   => true,
        );

        // Register the abilities once. wp_register_ability() mock captures
        // every (name, callable, args) into $GLOBALS['_registered_abilities'].
        WpCode_Snippets::register();

        // Two seeded snippets: one active JS, one inactive PHP. Save them
        // through the Fake WPCode_Snippet so taxonomies + meta + posts map
        // are populated the same way the real WordPress flow would.
        $this->seedSnippet(
            101,
            array(
                'title'     => 'JS Hello',
                'code'      => 'console.log("hello");',
                'code_type' => 'js',
                'location'  => 'site_wide_header',
                'active'    => true,
            )
        );
        $this->seedSnippet(
            102,
            array(
                'title'     => 'Draft PHP',
                'code'      => 'echo "draft";',
                'code_type' => 'php',
                'location'  => '',
                'active'    => false,
            )
        );
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
                '_wpcode_storage',
                '_wpcode_terms',
                '_wpcode_meta',
                '_test_posts',
                '_test_caps',
                '_wpcode_missing',
            ) as $key
        ) {
            unset($GLOBALS[ $key ]);
        }
        $GLOBALS['_registered_abilities'] = array();
    }

    /**
     * Insert a snippet through the Fake WPCode_Snippet so all global stores
     * are wired the same way the abilities expect at runtime.
     */
    private function seedSnippet(int $id, array $data): void
    {
        $post_args = array(
            'ID'           => $id,
            'post_title'   => $data['title'],
            'post_content' => $data['code'],
            'post_type'    => 'wpcode',
            'post_status'  => empty($data['active']) ? 'draft' : 'publish',
        );
        $snippet = new \WPCode_Snippet(array());
        if (! empty($data['code_type'])) {
            $snippet->code_type = (string) $data['code_type'];
        }
        if (! empty($data['location'])) {
            $snippet->location = (string) $data['location'];
        }
        if (! empty($data['active'])) {
            $snippet->auto_insert = 1;
        }
        // Force the snippet id (avoids the fake's id-count offset logic).
        $snippet->id   = $id;
        $snippet->code = (string) $data['code'];
        $snippet->title = (string) $data['title'];
        $snippet->active = (bool) $data['active'];
        $snippet->save();
        // Re-key under the explicit id so $GLOBALS['_wpcode_storage'][$id] is
        // exactly what get/snippet lookups need.
        $GLOBALS['_wpcode_storage'][ $id ] = (array) $snippet->post_data;
        $GLOBALS['_test_posts'][ $id ]      = (array) $snippet->post_data;
    }

    /** Custom WP_Error assert: the real WP_Error stub stores errors as $errors[code] = [message]. */
    private function assertWPError(mixed $result, string $expectedCode): void
    {
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertArrayHasKey(
            $expectedCode,
            $result->errors,
            sprintf('Expected WP_Error with code %s.', $expectedCode)
        );
    }

    // ── Registration shape ───────────────────────────────────────────────────

    public function test_all_seven_abilities_are_registered(): void
    {
        $names = array_keys($GLOBALS['_registered_abilities']);
        sort($names);
        $expected = array(
            'novamira-adrianv2/create-wpcode-snippet',
            'novamira-adrianv2/delete-wpcode-snippet',
            'novamira-adrianv2/duplicate-wpcode-snippet',
            'novamira-adrianv2/get-wpcode-snippet',
            'novamira-adrianv2/list-wpcode-snippets',
            'novamira-adrianv2/set-wpcode-snippet-status',
            'novamira-adrianv2/update-wpcode-snippet',
        );
        $this->assertSame($expected, $names);
    }

    public function test_is_available_returns_true_when_wpcode_class_is_loaded(): void
    {
        // The mock-functions.php fixture declares a Fake WPCode_Snippet, so
        // class_exists('WPCode_Snippet') is true and is_available() must
        // return true to register the abilities.
        $this->assertTrue(WpCode_Snippets::is_available());
    }

    public function test_create_ability_requires_title_code_and_code_type(): void
    {
        $a = $GLOBALS['_registered_abilities']['novamira-adrianv2/create-wpcode-snippet'];
        $this->assertSame(array('title', 'code', 'code_type'), $a['args']['input_schema']['required']);
    }

    public function test_code_type_enum_matches_canonical_wpcode_set(): void
    {
        $a = $GLOBALS['_registered_abilities']['novamira-adrianv2/create-wpcode-snippet'];
        $this->assertSame(
            array('php', 'universal', 'css', 'html', 'js', 'text', 'blocks', 'scss'),
            $a['args']['input_schema']['properties']['code_type']['enum']
        );
    }

    public function test_write_abilities_gate_through_strict_write_callback(): void
    {
        // create / update / duplicate / delete must all flow through the
        // check_write_permission callable so the manage_options + unfiltered_html
        // gate is self-evident in this file.
        $expected = array(WpCode_Snippets::class, 'check_write_permission');
        foreach (array('create', 'update', 'duplicate', 'delete') as $verb) {
            $this->assertSame(
                $expected,
                $GLOBALS['_registered_abilities']["novamira-adrianv2/{$verb}-wpcode-snippet"]['args']['permission_callback'],
                "{$verb}-wpcode-snippet must gate through check_write_permission"
            );
        }
    }

    public function test_set_status_uses_status_permission_and_is_idempotent(): void
    {
        $a = $GLOBALS['_registered_abilities']['novamira-adrianv2/set-wpcode-snippet-status'];
        $this->assertSame(array(WpCode_Snippets::class, 'check_status_permission'), $a['args']['permission_callback']);
        $this->assertTrue($a['args']['meta']['annotations']['idempotent']);
    }

    public function test_update_ability_is_not_marked_idempotent(): void
    {
        // An update can flip active, demote a bad PHP snippet to draft, mutate
        // post_modified and last_error, so calling it twice is NOT idempotent.
        $a = $GLOBALS['_registered_abilities']['novamira-adrianv2/update-wpcode-snippet'];
        $this->assertFalse($a['args']['meta']['annotations']['idempotent']);
    }

    public function test_delete_ability_is_marked_destructive(): void
    {
        $a = $GLOBALS['_registered_abilities']['novamira-adrianv2/delete-wpcode-snippet'];
        $this->assertTrue($a['args']['meta']['annotations']['destructive']);
    }

    // ── Capability callbacks ────────────────────────────────────────────────

    public function test_check_read_permission_requires_manage_options(): void
    {
        $GLOBALS['_test_caps']['manage_options'] = false;
        $this->assertFalse(WpCode_Snippets::check_read_permission());
        $GLOBALS['_test_caps']['manage_options'] = true;
        $this->assertTrue(WpCode_Snippets::check_read_permission());
    }

    public function test_check_write_permission_requires_unfiltered_html(): void
    {
        $GLOBALS['_test_caps']['unfiltered_html'] = false;
        $this->assertFalse(WpCode_Snippets::check_write_permission());
        $GLOBALS['_test_caps']['unfiltered_html'] = true;
        $this->assertTrue(WpCode_Snippets::check_write_permission());
    }

    public function test_check_status_permission_requires_wpcode_activate_snippets(): void
    {
        $GLOBALS['_test_caps']['wpcode_activate_snippets'] = false;
        $this->assertFalse(WpCode_Snippets::check_status_permission());
        $GLOBALS['_test_caps']['wpcode_activate_snippets'] = true;
        $this->assertTrue(WpCode_Snippets::check_status_permission());
    }

    // ── list-wpcode-snippets ────────────────────────────────────────────────

    public function test_list_returns_count_and_summaries(): void
    {
        $result = WpCode_Snippets::execute_list(array());
        $this->assertSame(2, $result['count']);
        $this->assertCount(2, $result['snippets']);
        // The list view should not leak raw code.
        foreach ($result['snippets'] as $row) {
            $this->assertArrayHasKey('snippet_id', $row);
            $this->assertArrayHasKey('title', $row);
            $this->assertArrayHasKey('code_type', $row);
            $this->assertArrayHasKey('location', $row);
            $this->assertArrayHasKey('active', $row);
            $this->assertArrayNotHasKey('code', $row);
        }
    }

    /** @dataProvider provideListFilters */
    public function test_list_filters_correctly(array $input, string $expectedTitle): void
    {
        $result = WpCode_Snippets::execute_list($input);
        $this->assertSame(1, $result['count']);
        $this->assertSame($expectedTitle, $result['snippets'][0]['title']);
    }

    public static function provideListFilters(): array
    {
        return array(
            'filter by status publish'    => array(array('status' => 'publish'),    'JS Hello'),
            'filter by status draft'      => array(array('status' => 'draft'),      'Draft PHP'),
            'filter by code_type js'      => array(array('code_type' => 'js'),      'JS Hello'),
            'filter by code_type php'     => array(array('code_type' => 'php'),     'Draft PHP'),
            'filter by exact location'    => array(array('location' => 'site_wide_header'), 'JS Hello'),
        );
    }

    public function test_list_clamps_per_page_within_one_to_two_hundred(): void
    {
        $huge = WpCode_Snippets::execute_list(array('per_page' => 99_999));
        $this->assertGreaterThanOrEqual(1, $huge['count']);
        $tiny = WpCode_Snippets::execute_list(array('per_page' => 0));
        $this->assertGreaterThanOrEqual(1, $tiny['count']);
    }

    // ── get-wpcode-snippet ───────────────────────────────────────────────────

    public function test_get_returns_full_record_for_existing_snippet(): void
    {
        $result = WpCode_Snippets::execute_get(array('snippet_id' => 101));
        $this->assertIsArray($result);
        $this->assertSame(101, $result['snippet_id']);
        $this->assertSame('JS Hello', $result['title']);
        $this->assertTrue($result['active']);
        $this->assertSame('publish', $result['status']);
        $this->assertSame('js',       $result['code_type']);
        $this->assertSame('site_wide_header', $result['location']);
    }

    public function test_get_returns_full_code_body(): void
    {
        // The get record includes code by design (a get tool should be
        // complete). List deliberately does not. This locks the contract.
        $result = WpCode_Snippets::execute_get(array('snippet_id' => 101));
        $this->assertArrayHasKey('code', $result);
        $this->assertSame('console.log("hello");', $result['code']);
    }

    public function test_does_not_leak_code_through_list(): void
    {
        // The list view returns summaries only — full code is only available
        // via get. Locking this prevents accidental code leaks in summary
        // responses.
        $list  = WpCode_Snippets::execute_list(array());
        foreach ($list['snippets'] as $row) {
            $this->assertArrayNotHasKey('code', $row, 'list row must not carry `code`');
        }
    }

    public function test_get_returns_wp_error_when_snippet_id_missing(): void
    {
        $this->assertWPError(WpCode_Snippets::execute_get(array()), 'wpcode_missing_id');
    }

    public function test_get_returns_wp_error_when_snippet_not_found(): void
    {
        $this->assertWPError(WpCode_Snippets::execute_get(array('snippet_id' => 999_999)), 'wpcode_snippet_not_found');
    }

    // ── create-wpcode-snippet ────────────────────────────────────────────────

    public function test_create_returns_wp_error_when_required_fields_missing(): void
    {
        $this->assertWPError(WpCode_Snippets::execute_create(array()), 'wpcode_missing_required');
        $this->assertWPError(WpCode_Snippets::execute_create(array('title' => 'x')), 'wpcode_missing_required');
        $this->assertWPError(
            WpCode_Snippets::execute_create(array('title' => 'x', 'code' => 'y')),
            'wpcode_missing_required'
        );
    }

    public function test_create_returns_wp_error_for_invalid_code_type(): void
    {
        $this->assertWPError(
            WpCode_Snippets::execute_create(
                array('title' => 'Bad', 'code' => 'x', 'code_type' => 'python')
            ),
            'wpcode_invalid_code_type'
        );
    }

    public function test_create_happy_path_defaults_to_draft(): void
    {
        $result = WpCode_Snippets::execute_create(
            array(
                'title'     => 'Created HTML',
                'code'      => '<p>Hi</p>',
                'code_type' => 'html',
                'active'    => false,
            )
        );
        $this->assertIsArray($result);
        $this->assertSame('Created HTML',  $result['title']);
        $this->assertSame('<p>Hi</p>',     $result['code']);
        $this->assertSame('html',          $result['code_type']);
        $this->assertFalse($result['active'], 'create must default to inactive draft');
        $this->assertSame('draft', $result['status']);
        $this->assertGreaterThan(0, $result['snippet_id']);
    }

    public function test_created_snippet_is_retrievable_via_get(): void
    {
        $created = WpCode_Snippets::execute_create(
            array(
                'title'     => 'Roundtrip',
                'code'      => 'a',
                'code_type' => 'css',
            )
        );
        $this->assertIsArray($created);

        $fetched = WpCode_Snippets::execute_get(array('snippet_id' => $created['snippet_id']));
        $this->assertSame('Roundtrip', $fetched['title']);
        $this->assertSame('css',        $fetched['code_type']);
    }

    public function test_create_with_active_true_returns_publish(): void
    {
        // active=true must surface as status=publish so the agent sees what
        // WPCode actually stored (not what was requested before any
        // run_activation_checks). HTML is safe — no activation check runs.
        $result = WpCode_Snippets::execute_create(
            array(
                'title'     => 'Live HTML',
                'code'      => '<p>Hi</p>',
                'code_type' => 'html',
                'active'    => true,
            )
        );
        $this->assertTrue($result['active'], 'active=true must surface as active=true when type does not run activation checks');
        $this->assertSame('publish', $result['status']);
    }

    // ── delete-wpcode-snippet ────────────────────────────────────────────────

    public function test_delete_returns_wp_error_when_snippet_id_missing(): void
    {
        $this->assertWPError(WpCode_Snippets::execute_delete(array()), 'wpcode_missing_id');
    }

    public function test_delete_returns_wp_error_when_snippet_not_found(): void
    {
        $this->assertWPError(
            WpCode_Snippets::execute_delete(array('snippet_id' => 999_999)),
            'wpcode_snippet_not_found'
        );
    }

    public function test_delete_returns_wp_error_when_post_type_is_not_wpcode(): void
    {
        // Seed a non-wpcode post and try to delete it via the WPCode ability.
        $GLOBALS['_test_posts'][70000] = array(
            'ID'          => 70000,
            'post_title'  => 'Not a wpcode',
            'post_type'   => 'post',
            'post_status' => 'publish',
        );
        $this->assertWPError(
            WpCode_Snippets::execute_delete(array('snippet_id' => 70000)),
            'wpcode_snippet_not_found'
        );
    }

    public function test_delete_happy_path_returns_success_and_removes_record(): void
    {
        $result = WpCode_Snippets::execute_delete(array('snippet_id' => 101));
        $this->assertTrue($result['success']);
        $this->assertSame(101, $result['snippet_id']);

        $this->assertWPError(
            WpCode_Snippets::execute_get(array('snippet_id' => 101)),
            'wpcode_snippet_not_found'
        );
    }
}
