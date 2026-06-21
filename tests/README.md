# tests/

PHPUnit test suite for the Novamira AdrianV2 plugin. PHPUnit 10.5+.

## Quick Start

```bash
# Run all unit tests (no WordPress required)
cd wp-content/plugins/novamira-adrianv2
php vendor/phpunit/phpunit/phpunit --bootstrap tests/bootstrap.php --no-configuration tests/V3ToV4ConverterTest.php tests/ConversionAuditorTest.php tests/ConversionAutoFixerTest.php --testdox

# Run a single test file
php vendor/phpunit/phpunit/phpunit --bootstrap tests/bootstrap.php --no-configuration tests/ConversionAutoFixerTest.php --testdox

# Run the WordPress integration test (requires WordPress)
cd ../../../..  # back to WordPress root
php wp-content/plugins/novamira-adrianv2/tests/run-integration.php
```

## Test Files

| File | Tests | Requires WP | Description |
|---|---|---|---|
| `V3ToV4ConverterTest.php` | 33 | No | V3→V4 widget/container/style conversion |
| `ConversionAuditorTest.php` | 33 | No | Layout, class, and responsive audits |
| `ConversionAutoFixerTest.php` | 29 | No | Auto-fixer: responsive variants, identical overrides, Kit-level styles, scale_props |
| `FixKitStylesForPageIntegrationTest.php` | 5 | **Yes** | `fix_kit_styles_for_page()` positive path with real Kit data |

## Infrastructure

### `bootstrap.php`

Standard PHPUnit bootstrap for unit tests. Defines `ABSPATH` to prevent the plugin's exit guard from killing PHPUnit, loads the Composer autoloader, and requires the three helper classes:

- `class-v3-to-v4-converter.php`
- `class-conversion-auto-fixer.php`
- `class-conversion-auditor.php`

No WordPress functions are available — all unit tests operate on pure PHP arrays.

### `run-integration.php`

Custom runner for the WordPress integration test. PHPUnit 10's `TestSuite` constructor is private, so this script:

1. Loads WordPress via `wp-load.php`
2. Loads the plugin's helper classes
3. Writes a minimal no-op bootstrap file for PHPUnit
4. Invokes `PHPUnit\TextUI\Application` programmatically
5. Cleans up the temp bootstrap file

**Why not `phpunit --bootstrap`?** The standard CLI bootstrap loads _before_ the test file, but we need WordPress fully loaded before the test class is parsed (ReflectionProperty on `Conversion_AutoFixer` requires the class definition, which requires `ABSPATH` to be defined, etc.). The custom runner ensures WordPress loads first, then the autoloader, then the test class.

## Writing New Tests

### Unit Tests (no WP)

Extend `PHPUnit\Framework\TestCase`, use `ReflectionMethod` for private methods:

```php
use Novamira\AdrianV2\Helpers\Conversion_AutoFixer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class MyTest extends TestCase
{
    public function test_private_method(): void
    {
        $method = new ReflectionMethod(Conversion_AutoFixer::class, 'somePrivateMethod');
        $result = $method->invokeArgs(null, [$arg1, &$arg2]);
    }
}
```

### Integration Tests (with WP)

Extend `PHPUnit\Framework\TestCase`, load via `run-integration.php`:

- Use `setUp()` to back up database state
- Use `tearDown()` to restore it
- Reset static caches via `ReflectionProperty::setAccessible(true)` + `setValue(null, null)`
- Run via: `php wp-content/plugins/novamira-adrianv2/tests/run-integration.php`
