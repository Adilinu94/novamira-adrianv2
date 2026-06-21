# Test Spec: Path-Traversal + MIME-Spoofing Protection (Phase 0.5.6 + 0.5.2)

**File:** `includes/abilities/media/class-media-upload.php`
**Audit evidence:** `novamira-improvement-2026-06/report.md` — Items **D6** (path-traversal) + **B7** (MIME-spoofing).
**Implementation date:** 2026-06-10
**Status:** ✅ Implementation complete, manual smoke test pending on solar.local.

---

## 1. What was implemented

Three private static guards were added to `class-media-upload.php`:

### `guard_filename(string $filename): string|array` (D6)
Strips path-traversal sequences, rejects dot-prefixed / empty filenames, and enforces a strict extension whitelist.

| Layer | Mechanism | Failure mode |
|---|---|---|
| 1. WordPress sanitize | `sanitize_file_name($filename)` strips `../`, `..\`, slashes | empty string → reject |
| 2. Defense in depth | Explicit check for `/` and `\` after sanitize | reject with "invalid path components" |
| 3. Dot-prefix block | `str_starts_with($sanitized, '.')` blocks `.htaccess` | reject with "cannot start with a dot" |
| 4. Extension whitelist | `['jpg','jpeg','png','gif','webp','svg','pdf','ico']` | reject with "extension '.xyz' is not allowed" |

### `guard_file_content(string $content, string $ext): ?string` (B7 — Magic-Bytes)
Precise format-specific header check. Returns `null` on match, error string on mismatch.

| Extension | Required signature | Notes |
|---|---|---|
| `jpg` / `jpeg` | `FF D8 FF` | SOI marker |
| `png` | `89 50 4E 47 0D 0A 1A 0A` | PNG header |
| `gif` | `47 49 46 38 37 61` or `47 49 46 38 39 61` | GIF87a / GIF89a |
| `webp` | `52 49 46 46` | RIFF prefix (full RIFF/WEBP check would need 8 bytes) |
| `pdf` | `25 50 44 46` | `%PDF` |
| `ico` | `00 00 01 00` | ICO header |
| `svg` | regex `<svg[\s>]` or `<\?xml` | text-based, scans first 200 bytes |

### `guard_mime_buffer(string $content, string $claimed_ext): ?string` (B7 — finfo_buffer upgrade)
Generic libmagic cross-check. Defense-in-depth alongside `guard_file_content()`. Catches polyglot files and generic MIME/extension mismatches that the magic-bytes check would miss.

**Layered defense (both must pass):**

| Scenario | `guard_file_content` (precise) | `guard_mime_buffer` (generic) | Result |
|---|---|---|---|
| Real JPEG, claimed .jpg | ✅ pass (FF D8 FF) | ✅ pass (image/jpeg) | accepted |
| PNG bytes claimed as .jpg | ❌ reject (header mismatch) | ❌ reject (image/png vs image/jpeg) | **rejected by both** |
| JPEG header + appended PHP (polyglot) | ✅ pass (header is valid JPEG) | ⚠️ depends on libmagic — may detect `text/x-php` or `image/jpeg` | **rejected if finfo detects non-JPEG** |
| Random bytes claimed as .pdf | ❌ reject (no %PDF) | ❌ reject (application/octet-stream) | **rejected by both** |
| Real SVG claimed as .svg | ✅ pass (regex) | ✅ pass (image/svg+xml OR text/xml OR text/plain — tolerant mapping) | accepted |
| Plain text claimed as .svg | ❌ reject (no `<svg`) | ❌ reject (text/plain not in mapping) | **rejected by both** |
| ZIP archive renamed to .jpg | ❌ reject (no FF D8 FF) | ❌ reject (application/zip vs image/jpeg) | **rejected by both** |

**finfo fail-open behavior:** if `finfo_open()` or `finfo_buffer()` returns false (libmagic unavailable, e.g. minimal PHP build), `guard_mime_buffer()` returns `null` and the magic-bytes check still runs as the primary defense. This means finfo is an *additional* layer, not a single point of failure.

**Extension → acceptable MIMEs mapping:**

```php
$expected_mimes = [
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png'  => ['image/png'],
    'gif'  => ['image/gif'],
    'webp' => ['image/webp'],
    'svg'  => ['image/svg+xml', 'application/xml', 'text/xml', 'text/plain'], // tolerant
    'pdf'  => ['application/pdf'],
    'ico'  => ['image/x-icon', 'image/vnd.microsoft.icon', 'image/ico'],
];
```

---

## 2. Test Matrix (T1–T12)

### Block A — Path-Traversal (D6)

| # | Filename input | Expected outcome | Tests guard |
|---|---|---|---|
| T1 | `../../etc/passwd.jpg` | ✅ sanitized to `passwd.jpg`, magic-bytes check uses .jpg | `guard_filename` |
| T2 | `..\..\windows\system32\config.jpg` | ✅ sanitized to `config.jpg` (slashes removed) | `guard_filename` |
| T3 | `/etc/passwd.jpg` | ✅ sanitized to `passwd.jpg` (leading slash removed) | `guard_filename` |
| T4 | `.htaccess` | ❌ "Filename cannot start with a dot." | `guard_filename` |
| T5 | `../../../etc/shadow.png` | ✅ sanitized to `shadow.png` | `guard_filename` |
| T6 | `photo.exe` | ❌ "File extension '.exe' is not allowed." | `guard_filename` |
| T7 | `archive.tar.gz` | ❌ "File extension '.gz' is not allowed." | `guard_filename` |
| T8 | `malicious.php` | ❌ "File extension '.php' is not allowed." | `guard_filename` |
| T9 | `noextension` | ❌ "Filename must include a file extension." | `execute()` (post-guard) |
| T10 | `..` (only dots) | ❌ "Invalid filename after sanitization." | `guard_filename` |
| T11 | empty string | ❌ "Invalid filename after sanitization." | `guard_filename` |
| T12 | `..%2F..%2Fetc%2Fpasswd.jpg` | ✅ sanitized to `passwd.jpg` (URL-encoded dots removed, % stays) | `guard_filename` |

### Block B — MIME-Spoofing (B7)

| # | Claimed ext | Actual file header | Expected outcome | Tests guard |
|---|---|---|---|---|
| M1 | `photo.jpg` | PNG header `89 50 4E 47` | ❌ "File content does not match claimed extension '.jpg' (possible MIME-spoofing)." | `guard_file_content` |
| M2 | `photo.png` | JPEG header `FF D8 FF` | ❌ "...does not match claimed extension '.png'..." | `guard_file_content` |
| M3 | `image.gif` | plain text `"Hello World"` | ❌ "...does not match claimed extension '.gif'..." | `guard_file_content` |
| M4 | `doc.pdf` | random bytes | ❌ "...does not match claimed extension '.pdf'..." | `guard_file_content` |
| M5 | `logo.svg` | `<svg xmlns="http://www.w3.org/2000/svg">` | ✅ match (regex `<svg[\s>]`) | `guard_file_content` |
| M6 | `logo.svg` | `<script>alert(1)</script>` | ❌ "File content does not appear to be valid SVG." | `guard_file_content` |
| M7 | `photo.jpg` | actual JPEG `FF D8 FF E0 ...` | ✅ match | `guard_file_content` |
| M8 | `photo.png` | actual PNG `89 50 4E 47 0D 0A 1A 0A` | ✅ match | `guard_file_content` |
| M9 | `image.webp` | non-RIFF garbage | ❌ "...does not match claimed extension '.webp'..." | `guard_file_content` |
| M10 | `favicon.ico` | non-ICO garbage | ❌ "...does not match claimed extension '.ico'..." | `guard_file_content` |

### Block C — finfo_buffer Cross-Check (B7 upgrade)

| # | Filename | File header | finfo detected | Magic-bytes | finfo | Expected outcome |
|---|---|---|---|---|---|---|
| F1 | `photo.jpg` | real JPEG `FF D8 FF E0` | `image/jpeg` | ✅ pass | ✅ pass | ✅ accepted (both layers green) |
| F2 | `photo.png` | real PNG `89 50 4E 47` | `image/png` | ✅ pass | ✅ pass | ✅ accepted |
| F3 | `photo.jpg` | PNG bytes `89 50 4E 47` | `image/png` | ❌ reject (FF D8 FF missing) | ❌ reject (image/png ≠ image/jpeg) | ❌ rejected by **magic-bytes first** |
| F4 | `doc.pdf` | `%PDF-1.7 ...` | `application/pdf` | ✅ pass | ✅ pass | ✅ accepted |
| F5 | `doc.pdf` | `<?php echo 1;` | `text/x-php` | ❌ reject (no %PDF) | ❌ reject (text/x-php ≠ application/pdf) | ❌ rejected by **magic-bytes first** |
| F6 | `photo.jpg` | JPEG header + appended `<?php` | `image/jpeg` (most libmagics) | ✅ pass | ✅ pass (tolerated) | ✅ accepted — known limitation: polyglot not always caught by finfo |
| F7 | `photo.jpg` | JPEG header + appended `<?php` | `text/x-php` (some libmagics) | ✅ pass | ❌ reject (text/x-php ≠ image/jpeg) | ❌ rejected by **finfo** — defense-in-depth worked |
| F8 | `logo.svg` | `<svg xmlns=...>` | `image/svg+xml` | ✅ pass | ✅ pass | ✅ accepted |
| F9 | `logo.svg` | `<?php echo "x";` | `text/x-php` | ❌ reject (no `<svg`) | ❌ reject (text/x-php not in mapping) | ❌ rejected by **magic-bytes first** |
| F10 | `favicon.ico` | real ICO `00 00 01 00` | `image/x-icon` | ✅ pass | ✅ pass | ✅ accepted |
| F11 | `favicon.ico` | random bytes | `application/octet-stream` | ❌ reject (no ICO header) | ❌ reject (octet-stream not in mapping) | ❌ rejected by **magic-bytes first** |
| F12 | `archive.zip` (renamed to .jpg) | ZIP `50 4B 03 04` | `application/zip` | ❌ reject (no FF D8 FF) | ❌ reject (application/zip ≠ image/jpeg) | ❌ rejected by **magic-bytes first** |
| F13 | `image.webp` | real WebP `52 49 46 46 ... WEBP` | `image/webp` | ✅ pass (RIFF prefix) | ✅ pass | ✅ accepted |
| F14 | libmagic unavailable | any content | n/a (finfo_open returns false) | ✅/❌ per magic-bytes | skip (fail-open) | magic-bytes still runs; finfo silently skipped |

---

## 3. Manual Test Procedure (solar.local WP-CLI)

```bash
# 1. Verify guard is wired up — check the class file contains both methods
wp eval 'echo method_exists("Novamira\\AdrianV2\\Abilities\\Media\\Media_Upload", "guard_filename") ? "OK" : "MISSING";'
wp eval 'echo method_exists("Novamira\\AdrianV2\\Abilities\\Media\\Media_Upload", "guard_file_content") ? "OK" : "MISSING";'

# 2. Build a base64 payload from a real PNG (should pass)
PNG_B64=$(base64 -w0 test-assets/sample.png)
wp eval "
\$payload = json_encode(['base64_content' => '$PNG_B64', 'filename' => 'sample.png']);
do_action('wp_abilities_api_init');
\$ability = wp_get_ability('novamira/adrians-media-upload');
print_r(\$ability->execute(json_decode(\$payload, true)));
" 2>&1 | head -30
# Expect: success => true, attachment_id set

# 3. Test path-traversal rejection
wp eval "
do_action('wp_abilities_api_init');
\$ability = wp_get_ability('novamira/adrians-media-upload');
print_r(\$ability->execute(['base64_content' => 'aGVsbG8=', 'filename' => '../../etc/passwd.jpg']));
" 2>&1
# Expect: success => false, error contains "passwd.jpg" sanitized output

# 4. Test MIME-spoofing rejection
PNG_B64=$(base64 -w0 test-assets/sample.png)
wp eval "
do_action('wp_abilities_api_init');
\$ability = wp_get_ability('novamira/adrians-media-upload');
// Real PNG bytes but claim it's a JPG
print_r(\$ability->execute(['base64_content' => '$PNG_B64', 'filename' => 'photo.jpg']));
" 2>&1
# Expect: success => false, error "File content does not match claimed extension '.jpg' (possible MIME-spoofing)."

# 5. Test dot-prefix rejection
wp eval "
do_action('wp_abilities_api_init');
\$ability = wp_get_ability('novamira/adrians-media-upload');
print_r(\$ability->execute(['base64_content' => 'aGVsbG8=', 'filename' => '.htaccess']));
" 2>&1
# Expect: success => false, error "Filename cannot start with a dot."

# 6. Test extension rejection
wp eval "
do_action('wp_abilities_api_init');
\$ability = wp_get_ability('novamira/adrians-media-upload');
print_r(\$ability->execute(['base64_content' => 'aGVsbG8=', 'filename' => 'malicious.php']));
" 2>&1
# Expect: success => false, error "File extension '.php' is not allowed."
```

---

## 4. PHPUnit Class (Future Work)

```php
<?php
/**
 * @group security
 * @group media-upload
 */
class Test_Media_Upload_Security extends WP_UnitTestCase {

    public function test_sanitizes_path_traversal() {
        $ability = wp_get_ability('novamira/adrians-media-upload');
        $result = $ability->execute([
            'base64_content' => base64_encode('fake content'),
            'filename' => '../../etc/passwd.jpg',
        ]);
        $this->assertTrue($result['success']);
        $this->assertStringNotContainsString('..', $result['data']['filename']);
        $this->assertStringNotContainsString('/', $result['data']['filename']);
    }

    public function test_rejects_dot_prefix() {
        $ability = wp_get_ability('novamira/adrians-media-upload');
        $result = $ability->execute([
            'base64_content' => base64_encode('fake'),
            'filename' => '.htaccess',
        ]);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('dot', $result['error']);
    }

    public function test_rejects_disallowed_extension() {
        $ability = wp_get_ability('novamira/adrians-media-upload');
        $result = $ability->execute([
            'base64_content' => base64_encode('<?php echo 1;'),
            'filename' => 'shell.php',
        ]);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('.php', $result['error']);
    }

    public function test_rejects_mime_spoofing() {
        // Real PNG bytes (89 50 4E 47) but claim it's a JPG
        $png_bytes = "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 100);
        $ability = wp_get_ability('novamira/adrians-media-upload');
        $result = $ability->execute([
            'base64_content' => base64_encode($png_bytes),
            'filename' => 'photo.jpg',
        ]);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('MIME-spoofing', $result['error']);
    }

    public function test_accepts_valid_jpeg() {
        // Minimal valid JPEG: FF D8 FF E0 ...
        $jpg_bytes = "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 100);
        $ability = wp_get_ability('novamira/adrians-media-upload');
        $result = $ability->execute([
            'base64_content' => base64_encode($jpg_bytes),
            'filename' => 'test.jpg',
        ]);
        $this->assertTrue($result['success']);
    }
}
```

**Status:** PHPUnit class is Future Work — depends on Phase 1.4 (CI-Binding) test-infrastructure setup. Until then, manual WP-CLI smoke tests on solar.local are the validation method.

---

## 5. Acceptance Criteria

- [x] `guard_filename()` private method exists (D6, path-traversal layer)
- [x] `guard_file_content()` private method exists (B7, magic-bytes layer)
- [x] `guard_mime_buffer()` private method exists (B7, finfo_buffer upgrade layer)
- [x] `execute()` calls `guard_filename()` immediately after extracting `$input['filename']`
- [x] `execute()` calls `guard_file_content()` after base64 decode + before `wp_upload_bits()`
- [x] `execute()` calls `guard_mime_buffer()` immediately after `guard_file_content()` (defense-in-depth)
- [x] `sanitize_file_name()` applied as first sanitization step
- [x] Extension whitelist enforced (8 allowed types: jpg/jpeg/png/gif/webp/svg/pdf/ico)
- [x] Dot-prefix rejected (`.htaccess`-style blocks)
- [x] Magic-bytes validation for all binary formats
- [x] SVG content validated via regex (text-based, no magic bytes)
- [x] finfo_buffer cross-check uses tolerant SVG mapping (4 acceptable MIMEs to handle libmagic version variance)
- [x] finfo_buffer fails open (skip if libmagic unavailable) — magic-bytes still runs
- [ ] Manual WP-CLI smoke test on solar.local (T1-T12 + M1-M10 + F1-F14 all pass)
- [ ] PHPUnit test class integrated (Future Work, depends on Phase 1.4)

---

## 6. References

- Audit report: `novamira-improvement-2026-06/report.md`
- Related XSS test spec: `includes/abilities/elementor/test-xss-protection.md`
- WordPress sanitize_file_name: https://developer.wordpress.org/reference/functions/sanitize_file_name/
- OWASP File Upload Cheat Sheet: https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html
- PHP `finfo_buffer` (for future MIME detection upgrade): https://www.php.net/manual/en/function.finfo-buffer.php
