<?php
/**
 * Test: REST Endpoints — /health, /status, /version (Sprint 13)
 *
 * Verifies the additional REST endpoints registered in
 * includes/helpers/bootstrap.php.
 *
 * @package Novamira\AdrianV2\Tests
 * @since 1.4.0
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * REST Endpoints Tests (Sprint 13).
 */
class RestEndpointsTest extends TestCase
{
    // ── GET /health ────────────────────────────────────────────────────────

    public function test_health_returns_ok(): void
    {
        $response = novamira_adrianv2_rest_health();
        $this->assertIsArray($response);
        $this->assertArrayHasKey('status', $response);
        $this->assertSame('ok', $response['status']);
    }

    public function test_health_has_required_keys(): void
    {
        $response = novamira_adrianv2_rest_health();
        $this->assertArrayHasKey('status', $response);
        $this->assertArrayHasKey('timestamp', $response);
        $this->assertArrayHasKey('php', $response);
        $this->assertArrayHasKey('wp', $response);
    }

    public function test_health_php_is_version_string(): void
    {
        $response = novamira_adrianv2_rest_health();
        $this->assertIsString($response['php']);
        $this->assertNotEmpty($response['php']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+/', $response['php']);
    }

    public function test_health_wp_is_string(): void
    {
        $response = novamira_adrianv2_rest_health();
        $this->assertIsString($response['wp']);
    }

    public function test_health_timestamp_is_string(): void
    {
        $response = novamira_adrianv2_rest_health();
        $this->assertIsString($response['timestamp']);
    }

    // ── GET /status ────────────────────────────────────────────────────────

    public function test_status_returns_array(): void
    {
        $response = novamira_adrianv2_rest_status();
        $this->assertIsArray($response);
    }

    public function test_status_has_plugin_info(): void
    {
        $response = novamira_adrianv2_rest_status();
        $this->assertArrayHasKey('plugin', $response);
        $this->assertArrayHasKey('name', $response['plugin']);
        $this->assertArrayHasKey('version', $response['plugin']);
        $this->assertSame('novamira-adrianv2', $response['plugin']['name']);
        $this->assertSame('1.0.0', $response['plugin']['version']);
    }

    public function test_status_has_schema_info(): void
    {
        $response = novamira_adrianv2_rest_status();
        $this->assertArrayHasKey('schema', $response);
        $this->assertArrayHasKey('version', $response['schema']);
        $this->assertArrayHasKey('types', $response['schema']);
        $this->assertArrayHasKey('props', $response['schema']);
        $this->assertSame(12, $response['schema']['types']);
        $this->assertSame(13, $response['schema']['props']);
    }

    public function test_status_has_test_counts(): void
    {
        $response = novamira_adrianv2_rest_status();
        $this->assertArrayHasKey('tests', $response);
        $this->assertSame(52, $response['tests']['phpunit']);
        $this->assertSame(114, $response['tests']['pipeline']);
        $this->assertSame(18, $response['tests']['e2e']);
        $this->assertSame(184, $response['tests']['total']);
    }

    public function test_status_has_php_and_time(): void
    {
        $response = novamira_adrianv2_rest_status();
        $this->assertArrayHasKey('php', $response);
        $this->assertArrayHasKey('time', $response);
        $this->assertIsString($response['php']);
        $this->assertNotEmpty($response['php']);
    }

    // ── GET /version ───────────────────────────────────────────────────────

    public function test_version_returns_array(): void
    {
        $response = novamira_adrianv2_rest_version();
        $this->assertIsArray($response);
    }

    public function test_version_has_required_keys(): void
    {
        $response = novamira_adrianv2_rest_version();
        $this->assertArrayHasKey('plugin', $response);
        $this->assertArrayHasKey('php', $response);
        $this->assertArrayHasKey('wp', $response);
    }

    public function test_version_plugin_is_100(): void
    {
        $response = novamira_adrianv2_rest_version();
        $this->assertSame('1.0.0', $response['plugin']);
    }

    public function test_version_php_is_string(): void
    {
        $response = novamira_adrianv2_rest_version();
        $this->assertIsString($response['php']);
        $this->assertNotEmpty($response['php']);
    }

    // ── Cross-endpoint consistency ─────────────────────────────────────────

    public function test_all_endpoints_return_same_php_version(): void
    {
        $health  = novamira_adrianv2_rest_health();
        $status  = novamira_adrianv2_rest_status();
        $version = novamira_adrianv2_rest_version();

        $this->assertSame($health['php'], $status['php']);
        $this->assertSame($health['php'], $version['php']);
    }

    public function test_health_and_version_wp_match(): void
    {
        $health  = novamira_adrianv2_rest_health();
        $version = novamira_adrianv2_rest_version();

        $this->assertSame($health['wp'], $version['wp']);
    }
}
