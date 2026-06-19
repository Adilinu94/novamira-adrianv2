<?php
/**
 * Test: el_check_setup_experiments_block() — Experiment-Status (8 cases).
 *
 * Tests the 4 Elementor experiments reported in check-setup's atomic block.
 * Mocks \Elementor\Plugin::$instance->experiments->is_feature_active()
 * per experiment to verify active/inactive/default/unknown states.
 *
 * @package Novamira\AdrianV2\Tests
 * @since   1.1.0
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// ── Bootstrap: define constants needed by novamira-pro/runtime.php ──
if (!defined('NOVAMIRA_PRO_ELEMENTOR_MIN_VERSION')) {
    define('NOVAMIRA_PRO_ELEMENTOR_MIN_VERSION', '3.6.0');
}
if (!defined('NOVAMIRA_PRO_ELEMENTOR_ATOMIC_BASE_CLASS')) {
    define('NOVAMIRA_PRO_ELEMENTOR_ATOMIC_BASE_CLASS', 'Elementor\Modules\AtomicWidgets\Elements\Atomic_Element_Base');
}
if (!defined('ELEMENTOR_VERSION')) {
    define('ELEMENTOR_VERSION', '4.0.0');
}

// Load the novamira-pro source files (these register functions in the
// Novamira\Pro\Abilities\Elementor namespace).
require_once __DIR__ . '/../../novamira-pro/includes/abilities/elementor/runtime.php';
require_once __DIR__ . '/../../novamira-pro/includes/abilities/elementor/check-setup.php';

/**
 * Configurable experiments mock — returns per-feature values read from
 * $GLOBALS['_test_experiments_block'] keyed by feature name.
 *
 *   true          → 'active'
 *   false         → 'inactive'
 *   non-boolean   → 'default'
 *   key not set   → 'unknown' (mock throws an exception caught by the null-safety)
 */
function _test_seed_experiments(array $states): void
{
    $GLOBALS['_test_experiments_block'] = $states;
}

/**
 * Inject a custom experiments mock into Elementor\Plugin::$instance.
 */
function _test_inject_experiments_mock(?object $mock): void
{
    if (!isset(\Elementor\Plugin::$instance)) {
        \Elementor\Plugin::$instance = new \Elementor\Plugin();
    }
    \Elementor\Plugin::$instance->experiments = $mock;
}

#[CoversNothing]
class CheckSetupExperimentsTest extends TestCase
{
    private object $activeMock;
    private object $inactiveMock;
    private object $defaultMock;
    private object $mixedMock;

    protected function setUp(): void
    {
        // Reset global state.
        $GLOBALS['_test_experiments_block'] = [];

        // ── Active mock: all 4 experiments return true ──
        $this->activeMock = new class {
            public function is_feature_active(string $name) {
                return true;
            }
        };

        // ── Inactive mock: all 4 experiments return false ──
        $this->inactiveMock = new class {
            public function is_feature_active(string $name) {
                return false;
            }
        };

        // ── Default mock: returns a non-boolean (simulates Elementor's
        //    "experiment is in default state, not explicitly toggled") ──
        $this->defaultMock = new class {
            public function is_feature_active(string $name) {
                return ['state' => 'default'];
            }
        };

        // ── Mixed mock: per-experiment return values ──
        $this->mixedMock = new class {
            public function is_feature_active(string $name) {
                $states = $GLOBALS['_test_experiments_block'] ?? [];
                return $states[$name] ?? 'unknown-token';
            }
        };
    }

    // ── Active state ─────────────────────────────────────────────────────────

    public function test_all_experiments_active(): void
    {
        _test_inject_experiments_mock($this->activeMock);
        $result = \Novamira\Pro\Abilities\Elementor\el_check_setup_experiments_block();

        $this->assertSame('active', $result['e_atomic_elements']);
        $this->assertSame('active', $result['e_opt_in_v4']);
        $this->assertSame('active', $result['e_variables']);
        $this->assertSame('active', $result['e_classes']);
    }

    // ── Inactive state ───────────────────────────────────────────────────────

    public function test_all_experiments_inactive(): void
    {
        _test_inject_experiments_mock($this->inactiveMock);
        $result = \Novamira\Pro\Abilities\Elementor\el_check_setup_experiments_block();

        $this->assertSame('inactive', $result['e_atomic_elements']);
        $this->assertSame('inactive', $result['e_opt_in_v4']);
        $this->assertSame('inactive', $result['e_variables']);
        $this->assertSame('inactive', $result['e_classes']);
    }

    // ── Default state (non-boolean return) ───────────────────────────────────

    public function test_all_experiments_default_when_non_boolean_return(): void
    {
        _test_inject_experiments_mock($this->defaultMock);
        $result = \Novamira\Pro\Abilities\Elementor\el_check_setup_experiments_block();

        $this->assertSame('default', $result['e_atomic_elements'],
            'Non-boolean return from is_feature_active() must map to "default"');
        $this->assertSame('default', $result['e_opt_in_v4']);
        $this->assertSame('default', $result['e_variables']);
        $this->assertSame('default', $result['e_classes']);
    }

    // ── Unknown state (no experiments manager) ───────────────────────────────

    public function test_all_unknown_when_experiments_manager_null(): void
    {
        _test_inject_experiments_mock(null);
        $result = \Novamira\Pro\Abilities\Elementor\el_check_setup_experiments_block();

        $this->assertSame('unknown', $result['e_atomic_elements'],
            'Null experiments manager must return "unknown" for all');
        $this->assertSame('unknown', $result['e_opt_in_v4']);
        $this->assertSame('unknown', $result['e_variables']);
        $this->assertSame('unknown', $result['e_classes']);
    }

    public function test_all_unknown_when_experiments_manager_has_no_is_feature_active(): void
    {
        // Mock without is_feature_active() method.
        _test_inject_experiments_mock(new class {});
        $result = \Novamira\Pro\Abilities\Elementor\el_check_setup_experiments_block();

        $this->assertSame('unknown', $result['e_atomic_elements']);
        $this->assertSame('unknown', $result['e_classes']);
    }

    // ── Mixed states ─────────────────────────────────────────────────────────

    public function test_mixed_experiment_states(): void
    {
        _test_seed_experiments([
            'e_atomic_elements' => true,
            'e_opt_in_v4'       => false,
            'e_variables'       => ['state' => 'default'],
            'e_classes'         => 'unknown-token',
        ]);
        _test_inject_experiments_mock($this->mixedMock);
        $result = \Novamira\Pro\Abilities\Elementor\el_check_setup_experiments_block();

        $this->assertSame('active', $result['e_atomic_elements'],
            'true → active');
        $this->assertSame('inactive', $result['e_opt_in_v4'],
            'false → inactive');
        $this->assertSame('default', $result['e_variables'],
            'non-boolean → default');
        $this->assertSame('default', $result['e_classes'],
            'unrecognised non-boolean → default');
    }

    // ── Structure ────────────────────────────────────────────────────────────

    public function test_return_array_has_exact_four_keys(): void
    {
        _test_inject_experiments_mock($this->activeMock);
        $result = \Novamira\Pro\Abilities\Elementor\el_check_setup_experiments_block();

        $keys = array_keys($result);
        sort($keys);
        $expected = ['e_atomic_elements', 'e_classes', 'e_opt_in_v4', 'e_variables'];
        sort($expected);
        $this->assertSame($expected, $keys,
            'Must return exactly the 4 expected experiment keys');
    }

    // ── Integration with atomic block ────────────────────────────────────────

    public function test_atomic_block_includes_experiments_key(): void
    {
        _test_inject_experiments_mock($this->activeMock);
        $atomic = \Novamira\Pro\Abilities\Elementor\el_check_setup_atomic_block();

        $this->assertArrayHasKey('experiments', $atomic,
            'el_check_setup_atomic_block() must include experiments');
        $this->assertIsArray($atomic['experiments']);
        $this->assertCount(4, $atomic['experiments']);
    }
}
