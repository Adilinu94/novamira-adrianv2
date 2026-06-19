<?php
/**
 * Test: Guards — cache invalidation, data validation, post guards (8 cases).
 *
 * @package Novamira\AdrianV2\Tests
 * @since   1.1.0
 */

declare(strict_types=1);

use Novamira\AdrianV2\Helpers\Guards;
use PHPUnit\Framework\TestCase;

#[CoversClass(Guards::class)]
class V4GuardTest extends TestCase
{
    private const POST_ELEMENTOR = 42;
    private const POST_NOT_FOUND = 999;

    protected function setUp(): void
    {
        // Reset all global test state.
        $GLOBALS['_test_posts']                   = [];
        $GLOBALS['_wpcode_meta']                  = [];
        $GLOBALS['_test_elementor_docs']          = [];
        $GLOBALS['_test_elementor_docs_missing']  = [];
        $GLOBALS['_test_clean_post_cache']        = [];
        $GLOBALS['_test_files_manager_clear_calls'] = 0;
        $GLOBALS['_test_post_meta_update_calls']  = [];
        $GLOBALS['_test_experiments']             = [];
        $GLOBALS['_registered_abilities']         = [];

        // Seed a valid Elementor post.
        $GLOBALS['_test_posts'][self::POST_ELEMENTOR] = [
            'ID'          => self::POST_ELEMENTOR,
            'post_title'  => 'Elementor Page',
            'post_content' => '',
            'post_status' => 'publish',
            'post_type'   => 'page',
        ];

        // Seed the Elementor document for the post.
        $GLOBALS['_test_elementor_docs'][self::POST_ELEMENTOR] = [
            ['id' => 'root', 'elType' => 'e-flexbox', 'elements' => []],
        ];
    }

    // ── ensure_elementor_post() ──────────────────────────────────────────────

    public function test_ensure_elementor_post_returns_true_for_valid_post(): void
    {
        $result = Guards::ensure_elementor_post(self::POST_ELEMENTOR);
        $this->assertTrue($result,
            'ensure_elementor_post() must return true for a valid Elementor post');
    }

    public function test_ensure_elementor_post_returns_error_for_missing_post(): void
    {
        $result = Guards::ensure_elementor_post(self::POST_NOT_FOUND);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('post_not_found', array_key_first($result->errors));
    }

    // ── validate_json() ──────────────────────────────────────────────────────

    public function test_validate_json_returns_array_for_valid_json(): void
    {
        $json   = '{"a":1,"b":["x","y"]}';
        $result = Guards::validate_json($json);

        $this->assertIsArray($result);
        $this->assertSame(1, $result['a']);
        $this->assertSame(['x', 'y'], $result['b']);
    }

    public function test_validate_json_returns_false_for_invalid_json(): void
    {
        $result = Guards::validate_json('{invalid json{{{');
        $this->assertFalse($result,
            'validate_json() must return false for syntactically invalid JSON');
    }

    public function test_validate_json_returns_false_for_non_array_root(): void
    {
        $result = Guards::validate_json('"just a string"');
        $this->assertFalse($result,
            'validate_json() must return false when root is not an array');
    }

    // ── is_valid_elementor_data() ────────────────────────────────────────────

    public function test_is_valid_elementor_data_returns_true_for_well_formed_data(): void
    {
        $data = [
            ['id' => 'a', 'elType' => 'e-flexbox'],
            ['id' => 'b', 'elType' => 'e-heading', 'settings' => ['title' => 'Hi']],
        ];
        $this->assertTrue(Guards::is_valid_elementor_data($data));
    }

    public function test_is_valid_elementor_data_returns_false_when_id_missing(): void
    {
        $data = [
            ['elType' => 'e-flexbox'], // no 'id' key
        ];
        $this->assertFalse(Guards::is_valid_elementor_data($data));
    }

    // ── get_elementor_data() / save_elementor_data() ─────────────────────────

    public function test_get_elementor_data_decodes_valid_meta(): void
    {
        $tree = [['id' => 'root', 'elType' => 'e-flexbox']];
        $GLOBALS['_wpcode_meta'][self::POST_ELEMENTOR]['_elementor_data'] = json_encode($tree);

        $result = Guards::get_elementor_data(self::POST_ELEMENTOR);
        $this->assertIsArray($result);
        $this->assertSame('root', $result[0]['id']);
    }

    public function test_get_elementor_data_returns_false_for_missing_meta(): void
    {
        // No _elementor_data meta set.
        $result = Guards::get_elementor_data(self::POST_ELEMENTOR);
        $this->assertFalse($result);
    }
}
