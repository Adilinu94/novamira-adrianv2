<?php
/**
 * Test: Sync_Schema — V4 prop-type schema export (4 cases).
 *
 * @package Novamira\AdrianV2\Tests
 * @since   1.1.0
 */

declare(strict_types=1);

// In mock mode, wp_abilities_api_init never fires, so we must
// require the class file directly.
require_once __DIR__ . '/../includes/abilities/v4-management/class-sync-schema.php';

use Novamira\AdrianV2\Abilities\V4Management\Sync_Schema;
use PHPUnit\Framework\TestCase;

#[CoversClass(Sync_Schema::class)]
class SyncSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['_registered_abilities'] = [];
        Sync_Schema::register();
    }

    // ── execute() – compact format ───────────────────────────────────────────

    public function test_execute_compact_format_returns_minimal_property_keys(): void
    {
        $result = Sync_Schema::execute(['format' => 'compact']);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame('compact', $result['format']);
        $this->assertArrayHasKey('version', $result);
        $this->assertArrayHasKey('elementor_version', $result);
        $this->assertArrayHasKey('schema', $result);
        $this->assertArrayHasKey('summary', $result);

        $schema = $result['schema'];
        $this->assertArrayHasKey('version', $schema);
        $this->assertArrayHasKey('types', $schema);
        $this->assertArrayHasKey('properties', $schema);

        // Compact mode: each property must only have 'type' and 'widgets' keys.
        foreach ($schema['properties'] as $prop => $def) {
            $this->assertIsArray($def);
            $keys = array_keys($def);
            sort($keys);
            $this->assertSame(['type', 'widgets'], $keys,
                "Compact format: property '{$prop}' must only contain 'type' and 'widgets' keys");
        }
    }

    // ── execute() – full format ──────────────────────────────────────────────

    public function test_execute_full_format_preserves_all_property_keys(): void
    {
        $result = Sync_Schema::execute(['format' => 'full']);

        $this->assertSame('full', $result['format']);
        $schema = $result['schema'];

        // Full format: properties may have additional keys like 'description'.
        // At minimum, 'type' and 'widgets' must still be present.
        foreach ($schema['properties'] as $prop => $def) {
            $this->assertArrayHasKey('type', $def);
            $this->assertArrayHasKey('widgets', $def);
        }
    }

    // ── execute() – section filtering ────────────────────────────────────────

    public function test_execute_section_filtering_returns_only_requested_sections(): void
    {
        $result = Sync_Schema::execute([
            'format'   => 'full',
            'sections' => ['types'],
        ]);

        $schema = $result['schema'];
        $this->assertArrayHasKey('version', $schema, 'Version key must always be present');
        $this->assertArrayHasKey('types', $schema, 'types section must be present when requested');
        $this->assertArrayNotHasKey('properties', $schema,
            'properties section must be absent when not requested');
    }

    // ── register() schema ────────────────────────────────────────────────────

    public function test_register_defines_correct_input_schema(): void
    {
        $registered = $GLOBALS['_registered_abilities']['novamira-adrianv2/sync-schema'] ?? null;

        $this->assertNotNull($registered, 'sync-schema must be registered');

        // wp_register_ability mock stores the definition array in 'callable' key.
        $def    = $registered['callable'] ?? [];
        $schema = $def['schema'] ?? [];
        $this->assertSame('object', $schema['type'] ?? '');
        $this->assertArrayHasKey('format', $schema['properties'] ?? []);
        $this->assertArrayHasKey('sections', $schema['properties'] ?? []);

        // format must accept 'compact' or 'full'.
        $formatEnum = $schema['properties']['format']['enum'] ?? [];
        $this->assertContains('compact', $formatEnum);
        $this->assertContains('full', $formatEnum);
        $this->assertSame('compact', $schema['properties']['format']['default'] ?? '');
    }
}
