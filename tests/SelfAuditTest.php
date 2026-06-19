<?php
/**
 * Test: Self_Audit — Plugin health checks (6 cases).
 *
 * @package Novamira\AdrianV2\Tests
 * @since   1.1.0
 */

declare(strict_types=1);

// In mock mode, wp_abilities_api_init never fires, so we must
// require the class file directly.
require_once __DIR__ . '/../includes/abilities/utilities/class-self-audit.php';

use Novamira\AdrianV2\Abilities\Utilities\Self_Audit;
use PHPUnit\Framework\TestCase;

#[CoversClass(Self_Audit::class)]
class SelfAuditTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset global test state.
        $GLOBALS['_registered_abilities']       = [];
        $GLOBALS['_test_registered_abilities']  = [];
        $GLOBALS['_test_wp_cache']              = [];

        // Register the ability so its schema is available.
        Self_Audit::register();
    }

    // ── execute() basic structure ────────────────────────────────────────────

    public function test_execute_runs_all_three_checks_by_default(): void
    {
        $result = Self_Audit::execute([]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success'], 'execute() must return success=true');
        $this->assertArrayHasKey('overall_status', $result);
        $this->assertArrayHasKey('checks', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertCount(3, $result['checks'],
            'execute() must run all 3 checks (bom, strict_types, ability_count) by default');
        $this->assertIsString($result['summary']);
    }

    public function test_execute_respects_input_filters(): void
    {
        $result = Self_Audit::execute([
            'include_bom_check'     => false,
            'include_strict_probe'  => true,
            'include_ability_count' => false,
        ]);

        $this->assertCount(1, $result['checks'],
            'execute() must only run 1 check when bom + ability_count are disabled');
        $this->assertSame('php_strict_probe', $result['checks'][0]['name']);
    }

    public function test_execute_returns_success_true(): void
    {
        // Ability count: seed 60+ novamira-adrianv2 abilities so the
        // ability_count check passes (status=ok).
        $abilities = [];
        for ($i = 0; $i < 60; $i++) {
            $abilities["novamira-adrianv2/ability-{$i}"] = ['label' => "Ability {$i}"];
        }
        $GLOBALS['_test_registered_abilities'] = $abilities;

        $result = Self_Audit::execute([
            'include_bom_check'     => false,
            'include_strict_probe'  => false,
            'include_ability_count' => true,
        ]);

        $this->assertTrue($result['success'],
            'execute() must always return success=true (audit is non-fatal)');
    }

    // ── ability count check ──────────────────────────────────────────────────

    public function test_check_ability_count_warns_when_below_expected(): void
    {
        // Seed only 10 abilities — well below the expected 60.
        $abilities = [];
        for ($i = 0; $i < 10; $i++) {
            $abilities["novamira-adrianv2/ability-{$i}"] = ['label' => "Ability {$i}"];
        }
        $GLOBALS['_test_registered_abilities'] = $abilities;

        $result = Self_Audit::execute([
            'include_bom_check'     => false,
            'include_strict_probe'  => false,
            'include_ability_count' => true,
        ]);

        $check = $result['checks'][0];
        $this->assertSame('ability_count', $check['name']);
        $this->assertSame('warning', $check['status'],
            'ability_count must warn when v2_registered < expected');
        $this->assertSame(60, $check['expected']);
        $this->assertSame(10, $check['v2_registered']);
    }

    public function test_overall_status_is_warning_when_ability_count_warns(): void
    {
        // Seed only 10 abilities to trigger warning.
        $abilities = [];
        for ($i = 0; $i < 10; $i++) {
            $abilities["novamira-adrianv2/ability-{$i}"] = ['label' => "Ability {$i}"];
        }
        $GLOBALS['_test_registered_abilities'] = $abilities;

        $result = Self_Audit::execute([
            'include_bom_check'     => false,
            'include_strict_probe'  => false,
            'include_ability_count' => true,
        ]);

        $this->assertSame('warning', $result['overall_status'],
            'overall_status must be "warning" when any check warns');
    }

    // ── register() ───────────────────────────────────────────────────────────

    public function test_register_registers_with_correct_category(): void
    {
        $registered = $GLOBALS['_registered_abilities']['novamira-adrianv2/self-audit'] ?? null;

        $this->assertNotNull($registered,
            'register() must store self-audit in _registered_abilities');
        // wp_register_ability mock stores the definition array in 'callable' key.
        $def = $registered['callable'] ?? [];
        $this->assertSame('adrianv2-utilities', $def['category'] ?? null,
            'self-audit must be registered under category "adrianv2-utilities"');
        $this->assertSame([Self_Audit::class, 'execute'], $def['callback'] ?? null,
            'callback must be [Self_Audit::class, "execute"]');
    }
}
