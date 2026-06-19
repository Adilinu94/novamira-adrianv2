<?php
/**
 * Test: Elementor_Data_Helpers — page read/write/find/insert primitives.
 *
 * Verifies that the trait added in Phase 2 (UMBAUPLAN §2.2/2.3) exposes
 * read_page / write_page / find_element / update_element_settings /
 * insert_element / generate_id, and that the previously broken
 * audit-page-a11y + audit-page-seo + atomic-container abilities now have
 * a backing implementation.
 *
 * Tests run in Mock-Modus (no WordPress, no Elementor). We polyfill the
 * minimum surface area: get_post, get_post_meta, clean_post_cache,
 * and a fake Elementor\Plugin that returns a fake document.
 *
 * @package Novamira\AdrianV2\Tests
 * @since   1.1.0
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Concrete consumer that uses the trait so we can test protected methods.
 */
class _ElementorDataHelpersConsumer
{
    use Novamira\AdrianV2\Helpers\Elementor_Data_Helpers;

    public function call(string $name, ...$args)
    {
        $ref = new ReflectionMethod(self::class, $name);
        $ref->setAccessible(true);
        // Static invocation — pass null as receiver and forward args.
        return $ref->invokeArgs(null, $args);
    }

    public function callByRef(string $name, array &$elements, ...$args)
    {
        $ref = new ReflectionMethod(self::class, $name);
        $ref->setAccessible(true);
        $combined = [&$elements, ...$args];
        return $ref->invokeArgs(null, $combined);
    }
}

/**
 * Elementor_Data_Helpers Tests.
 */
#[CoversClass(Novamira\AdrianV2\Helpers\Elementor_Data_Helpers::class)]
class ElementorDataHelpersTest extends TestCase
{
    private _ElementorDataHelpersConsumer $c;

    protected function setUp(): void
    {
        $this->c = new _ElementorDataHelpersConsumer();

        // Reset all in-memory test state used by the polyfilled
        // mocks in mock-functions.php.
        $GLOBALS['_test_posts']            = [];
        $GLOBALS['_test_post_meta']        = [];
        $GLOBALS['_test_elementor_docs']   = [];
        $GLOBALS['_test_clean_post_cache'] = [];
    }

    // ── generate_id ──────────────────────────────────────────────────────────

    public function test_generate_id_returns_seven_char_hex(): void
    {
        $id = $this->c->call('generate_id');
        $this->assertIsString($id);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{7}$/', $id,
            'generate_id() must return 7 lowercase hex chars');
    }

    public function test_generate_id_produces_unique_values(): void
    {
        $ids = [];
        for ($i = 0; $i < 50; $i++) {
            $ids[] = $this->c->call('generate_id');
        }
        $this->assertCount(50, array_unique($ids),
            'generate_id() must produce unique ids over 50 calls');
    }

    // ── read_page ───────────────────────────────────────────────────────────

    public function test_read_page_returns_invalid_post_id_for_zero(): void
    {
        $r = $this->c->call('read_page', 0);
        $this->assertArrayHasKey('elements', $r);
        $this->assertSame([], $r['elements']);
        $this->assertSame('invalid_post_id', $r['error']);
    }

    public function test_read_page_returns_post_not_found_for_unknown_id(): void
    {
        $r = $this->c->call('read_page', 12345);
        $this->assertSame('post_not_found', $r['error']);
    }

    public function test_read_page_returns_no_elementor_doc_for_post_without_elementor(): void
    {
        $GLOBALS['_test_posts'][7] = ['ID' => 7, 'post_title' => 'No Elementor'];
        $r = $this->c->call('read_page', 7);
        $this->assertSame('no_elementor_doc', $r['error']);
    }

    public function test_read_page_returns_elements_when_document_present(): void
    {
        $GLOBALS['_test_posts'][42] = ['ID' => 42, 'post_title' => 'Atomic page'];
        $GLOBALS['_test_elementor_docs'][42] = [
            ['id' => 'root', 'elType' => 'e-flexbox', 'elements' => [
                ['id' => 'child1', 'elType' => 'e-heading'],
            ]],
        ];
        $r = $this->c->call('read_page', 42);
        $this->assertNull($r['error']);
        $this->assertCount(1, $r['elements']);
        $this->assertSame('root', $r['elements'][0]['id']);
        $this->assertSame('child1', $r['elements'][0]['elements'][0]['id']);
    }

    public function test_read_page_normalizes_non_array_data_to_empty(): void
    {
        $GLOBALS['_test_posts'][8] = ['ID' => 8, 'post_title' => 'Edge case'];
        $GLOBALS['_test_elementor_docs'][8] = null;
        $r = $this->c->call('read_page', 8);
        $this->assertNull($r['error']);
        $this->assertSame([], $r['elements']);
    }

    // ── find_element ────────────────────────────────────────────────────────

    public function test_find_element_returns_root_match(): void
    {
        $els = [
            ['id' => 'root', 'settings' => ['x' => 1], 'elements' => []],
        ];
        $found = $this->c->call('find_element', $els, 'root');
        $this->assertNotNull($found);
        $this->assertSame(1, $found['settings']['x']);
    }

    public function test_find_element_recurses_into_children(): void
    {
        $els = [
            ['id' => 'root', 'elements' => [
                ['id' => 'a', 'elements' => []],
                ['id' => 'b', 'elements' => [
                    ['id' => 'deep-target', 'elements' => []],
                ]],
            ]],
        ];
        $found = $this->c->call('find_element', $els, 'deep-target');
        $this->assertNotNull($found);
        $this->assertSame('deep-target', $found['id']);
    }

    public function test_find_element_returns_null_when_not_found(): void
    {
        $els = [['id' => 'a', 'elements' => []]];
        $this->assertNull($this->c->call('find_element', $els, 'zzz'));
    }

    public function test_find_element_handles_string_ids_via_coercion(): void
    {
        $els = [['id' => 12345, 'elements' => []]];
        $this->assertNotNull($this->c->call('find_element', $els, '12345'));
    }

    // ── update_element_settings ─────────────────────────────────────────────

    public function test_update_element_settings_merges_into_existing_settings(): void
    {
        $els = [['id' => 'root', 'settings' => ['a' => 1, 'b' => 2]]];
        $ok = $this->c->callByRef('update_element_settings', $els, 'root', ['b' => 20, 'c' => 3]);
        $this->assertTrue($ok);
        $this->assertSame(1, $els[0]['settings']['a']);
        $this->assertSame(20, $els[0]['settings']['b']);
        $this->assertSame(3, $els[0]['settings']['c']);
    }

    public function test_update_element_settings_creates_settings_array_if_missing(): void
    {
        $els = [['id' => 'root']];
        $ok = $this->c->callByRef('update_element_settings', $els, 'root', ['a' => 1]);
        $this->assertTrue($ok);
        $this->assertSame(1, $els[0]['settings']['a']);
    }

    public function test_update_element_settings_recurses_into_children(): void
    {
        $els = [['id' => 'root', 'elements' => [['id' => 'child', 'settings' => []]]]];
        $ok = $this->c->callByRef('update_element_settings', $els, 'child', ['x' => 'y']);
        $this->assertTrue($ok);
        $this->assertSame('y', $els[0]['elements'][0]['settings']['x']);
    }

    public function test_update_element_settings_returns_false_when_id_not_found(): void
    {
        $els = [['id' => 'a']];
        $this->assertFalse($this->c->callByRef('update_element_settings', $els, 'missing', ['x' => 1]));
    }

    // ── insert_element ──────────────────────────────────────────────────────

    public function test_insert_element_appends_at_root_when_parent_id_empty(): void
    {
        $els = [['id' => 'a']];
        $ok = $this->c->callByRef('insert_element', $els, '', ['id' => 'b'], -1);
        $this->assertTrue($ok);
        $this->assertCount(2, $els);
        $this->assertSame('b', $els[1]['id']);
    }

    public function test_insert_element_inserts_at_specific_position(): void
    {
        $els = [['id' => 'a'], ['id' => 'b']];
        $ok = $this->c->callByRef('insert_element', $els, '', ['id' => 'inserted'], 1);
        $this->assertTrue($ok);
        $this->assertSame(['a', 'inserted', 'b'], array_column($els, 'id'));
    }

    public function test_insert_element_inserts_under_parent(): void
    {
        $els = [['id' => 'parent', 'elements' => [['id' => 'c1']]]];
        $ok = $this->c->callByRef('insert_element', $els, 'parent', ['id' => 'c2'], -1);
        $this->assertTrue($ok);
        $this->assertCount(2, $els[0]['elements']);
        $this->assertSame('c2', $els[0]['elements'][1]['id']);
    }

    public function test_insert_element_creates_elements_array_on_parent_if_missing(): void
    {
        $els = [['id' => 'parent']];
        $ok = $this->c->callByRef('insert_element', $els, 'parent', ['id' => 'c1'], -1);
        $this->assertTrue($ok);
        $this->assertSame(['c1'], array_column($els[0]['elements'], 'id'));
    }

    public function test_insert_element_returns_false_when_parent_not_found(): void
    {
        $els = [['id' => 'a']];
        $this->assertFalse($this->c->callByRef('insert_element', $els, 'missing', ['id' => 'b'], -1));
    }

    public function test_insert_element_recurses_to_find_nested_parent(): void
    {
        $els = [
            ['id' => 'root', 'elements' => [
                ['id' => 'mid', 'elements' => []],
            ]],
        ];
        $ok = $this->c->callByRef('insert_element', $els, 'mid', ['id' => 'leaf'], -1);
        $this->assertTrue($ok);
        $this->assertSame('leaf', $els[0]['elements'][0]['elements'][0]['id']);
    }

    // ── Trait integration with A11y / Seo / Atomic ──────────────────────────

    public function test_trait_is_used_by_a11y_class(): void
    {
        $traits = class_uses_recursive(\Novamira\AdrianV2\Abilities\A11y\A11y::class);
        $this->assertContains(Novamira\AdrianV2\Helpers\Elementor_Data_Helpers::class, $traits,
            'A11y class must use Elementor_Data_Helpers trait');
    }

    public function test_trait_is_used_by_seo_class(): void
    {
        $traits = class_uses_recursive(\Novamira\AdrianV2\Abilities\Seo\Seo::class);
        $this->assertContains(Novamira\AdrianV2\Helpers\Elementor_Data_Helpers::class, $traits,
            'Seo class must use Elementor_Data_Helpers trait');
    }

    public function test_trait_is_used_by_atomic_layouts_class(): void
    {
        $traits = class_uses_recursive(\Novamira\AdrianV2\Abilities\Atomic\Atomic_Layouts::class);
        $this->assertContains(Novamira\AdrianV2\Helpers\Elementor_Data_Helpers::class, $traits,
            'Atomic_Layouts class must use Elementor_Data_Helpers trait');
    }

    public function test_trait_is_used_by_atomic_widgets_class(): void
    {
        $traits = class_uses_recursive(\Novamira\AdrianV2\Abilities\Atomic\Atomic_Widgets::class);
        $this->assertContains(Novamira\AdrianV2\Helpers\Elementor_Data_Helpers::class, $traits,
            'Atomic_Widgets class must use Elementor_Data_Helpers trait');
    }

    public function test_trait_is_used_by_custom_code_class(): void
    {
        $traits = class_uses_recursive(\Novamira\AdrianV2\Abilities\CustomCode\Custom_Code::class);
        $this->assertContains(Novamira\AdrianV2\Helpers\Elementor_Data_Helpers::class, $traits,
            'Custom_Code class must use Elementor_Data_Helpers trait');
    }

    public function test_trait_exposes_all_six_helper_methods(): void
    {
        $ref  = new ReflectionClass(Novamira\AdrianV2\Helpers\Elementor_Data_Helpers::class);
        $m    = array_map(fn($r) => $r->getName(), $ref->getMethods());
        foreach (['read_page', 'write_page', 'find_element', 'update_element_settings',
                  'insert_element', 'generate_id'] as $name) {
            $this->assertContains($name, $m,
                "Elementor_Data_Helpers must expose {$name}()");
        }
    }
}

if (!function_exists('class_uses_recursive')) {
    function class_uses_recursive(string $class): array
    {
        $traits = [];
        do {
            $traits = array_merge(class_uses($class) ?: [], $traits);
        } while ($class = get_parent_class($class));
        foreach ($traits as $trait) {
            $sub = class_uses($trait) ?: [];
            $traits = array_merge($traits, $sub);
        }
        return array_values(array_unique($traits));
    }
}
