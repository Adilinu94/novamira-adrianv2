---
name: adrianv2-live-edit
description: Activate when an AI agent needs to mutate WPCode snippet code, an Elementor page's element tree, or assign CSS classes to Elementor containers in production-safe ways — i.e. when the user asks the agent to edit live CSS/JS via WPCode (raw `wp_update_post` will not bust the compiled-asset cache), to attach class identifiers to `.e-con` containers, or to write per-page CSS that has to win against Elementor 4.x's `.e-con` defaults. Routes every snippet write through kses bypass + WPCode cache purge, every Elementor write through the Document API with editor-lock guarding, every class assignment through the dual `css_classes` + `_css_classes` sync, and every per-page CSS write through the `html body` specificity bump.
---

# Live-editing WPCode snippets and Elementor pages

This skill is for using the `novamira-adrianv2/wpcode-*` and
`novamira-adrianv2/elementor-*` abilities together for live editing of:

  - WPCode snippet code (CSS/JS/HTML/PHP embedded via the wpcode_snippet post type), and
  - Elementor page data (`_elementor_data`, `settings.css_classes`, `_elementor_page_custom_css`).

Read it once at the start of any WPCode-or-Elementor live-edit task and
refer back when in doubt.

## When to use

Activate when:

  - The user asks to add or modify CSS/JS that lives in a WPCode snippet on a page where WPCode's compiled-asset cache is active.
  - The user asks the agent to assign a class identifier (e.g. `productitem`) to an Elementor container (`.e-con`) so subsequent CSS can target it.
  - The user asks the agent to inject per-page CSS that must survive Elementor 4.x `.e-con` defaults (typical symptom: agent's rule shows in DevTools but the rule is "striked out" because `.e-con` overrode it).
  - The user asks the agent to add a small inline JS guard (e.g. click-vs-drag detection) inside an Elementor HTML widget.

Do **not** activate when:

  - The user wants structural Elementor layout changes handled by `novamira-adrianv2/elementor-build-page` (this skill is for surgical, production-safe patches, not greenfield construction).
  - The user wants WPCode snippets written by a human editor (use WPCode's own admin UI; the agent has nothing to add).
  - WPCode is not active on the site (use a `check-setup`-style ability first).
  - The user only wants to inspect what's in a WPCode snippet — `get-wpcode-snippet` is itself a read ability; this skill is for writes.

## Why these are abilities (and what to do in plain execute-php)

The four production hazards are:

  1. **kses stripping.** Post content for the `wpcode_snippet` post type is sanitised through `content_save_pre`; CSS/JS body comes out hollowed if you write through `wp_update_post` naively.
  2. **WPCode compiled-asset cache.** The snapshot WP saves to `wpcode_snippets` / `wpcode_compiled_assets` does not invalidate when only `post_modified` moves. A raw `wp_update_post` (or worse, a `$wpdb->update`) leaves the page serving the old compiled asset.
  3. **Elementor 4.x `.e-con` defaults.** `.e-con` has specificity 0,1,0 and wins via source-order tiebreak against any CSS the agent writes without a prefix.
  4. **Concurrent Elementor editor sessions.** `update_post_meta('_elementor_data', …)` clobbers what a human is currently editing.

The abilities hide all four. Plain `execute-php` does not — any direct write
to these surfaces without this skill risks silent rollback.

## The helpers at a glance

| Helper | Namespace | What it does |
|---|---|---|
| `WPCode_Kses_Bypass::edit_post()` | `Novamira\AdrianV2\Helpers` | kses-bypassed `wp_update_post` + per-snippet compiled-meta delete + WPCode cache purge (options + transients + on-disk `*.cache.php`). |
| `WPCode_Kses_Bypass::invalidate_compiled_cache()` | same | Force-purge every layer of WPCode's compiled-asset snapshot. Returns stats array or `WP_Error('wpcode_purge_noop')` if WPCode looks uninitialised. |
| `Elementor_Document_Saver::save_data()` | same | Write Elementor `_elementor_data` through the Document API. Returns `{success, warnings[]}` (NOT a WP_Error union). |
| `Elementor_Document_Saver::assign_class()` | same | Assign or append a CSS class on one element; syncs `css_classes` + `_css_classes` + v4-atomic `settings.classes.value`. |
| `Elementor_CSS_Override::inject_page_custom_css()` | same | Append page Custom-CSS with the `html body` specificity bump. Returns `true\|WP_Error`. |
| `Elementor_CSS_Override::generate_click_guard_script()` | same | Returns inline JS that lets taps on inner anchors still link through, but blocks navigation when the pointer moved >N px. |

## Domain model

### WPCode snippet writes

`WPCode_Kses_Bypass::edit_post(int $snippet_id, string $content, string $title = '', array $extra = []): int|WP_Error`. Pipeline (sequential, no silent skips):

  1. Validate id, content, runtime helpers (`kses_remove_filters`, `wp_update_post`).
  2. Confirm post type is `wpcode_snippet` (returns `WP_Error('wpcode_wrong_post_type')` otherwise).
  3. **Temporary kses bypass**: `kses_remove_filters(); try { wp_update_post($update, true) } finally { kses_init_filters() }`. The `try/finally` is mandatory — without it, a thrown hook leaves every later content-write in the request unsanitised.
  4. `clean_post_cache($snippet_id)`.
  5. `invalidate_compiled_cache($snippet_id)` (see below). If the cache purge comes back as `WP_Error('wpcode_purge_noop')`, the helper propagates the error so the agent can warn the user "WPCode may not be active; the bump may not be live".
  6. Return `(int) $post_id`.

`WPCode_Kses_Bypass::invalidate_compiled_cache(?int $snippet_id = null): array|WP_Error`. Allow-listed cache layers only (wildcard SQL is forbidden):

  - Options + matching transients to delete: `wpcode_snippets`, `wpcode_snippets_cache`, `wpcode_global_js_css`, `wpcode_assets`, `wpcode_compiled_assets`, `wpcode_compiled_snippets`, `wpcode_snippets_data`, `wpcode_lib`, `wpcode_header_scripts`, `wpcode_footer_scripts`, `wpcode_css_print_method`.
  - Per-snippet post-meta (only when `$snippet_id` given): `_wpcode_compiled_code`, `_wpcode_compiled_snippet`.
  - Files in `wp-content/uploads/wpcode/cache/`: every `*.cache.php` (per-file try/catch around `@unlink`, errors collected).
  - Returns `{options_deleted, options_missing, transients_deleted, transients_missing, files_removed, files_failed, meta_deleted, errors}`. If every counter is zero, returns `WP_Error('wpcode_purge_noop')`.

### Elementor page writes

`Elementor_Document_Saver::save_data(int $post_id, array $elements): array{success, warnings}`. **Structured array** (not a WP_Error union):

```php
[
  'success'  => bool,
  'warnings' => ['post-css delete failed for 3546: …', '…', …]
]
```

Inspection order — early bail on each:

  1. `$post_id` must be a positive int.
  2. `Elementor\Plugin` class must exist (else `success: false`, `elementor_inactive`).
  3. `get_post($post_id)` must yield a post (else `elementor_post_not_found`).
  4. `wp_check_post_lock($post_id)` MUST be false. If a user is currently in the Elementor editor for this post, the helper refuses with `success: false` (`elementor_post_locked`) rather than stomping the human's pending edits.
  5. `Elementor\Plugin::instance()->documents->get($post_id)` must yield a document; otherwise no save (`elementor_no_doc`).
  6. `update_json_meta('_elementor_data', $elements)` on the document. Same path the Elementor editor uses on its own "Save" button — fires the cache rebuild hooks that a raw `update_post_meta` does not.
  7. Best-effort follow-ups (every caught `\Throwable` is `error_log('[novamira-adrianv2] …')` AND appended to `warnings[]`):
     - `\Elementor\Core\Files\CSS\Post::create($post_id)->delete()` (per-post CSS file).
     - `\Elementor\Plugin::$instance->files_manager->clear_cache()` (kit + global CSS).
     - `clean_post_cache($post_id)`.

`Elementor_Document_Saver::assign_class(array &$element, string $new_class, bool $append_to_existing = true): true|WP_Error`. Writes to **three** places so Elementor 3.x, 4.x compat-mode, and v4-atomic all honor the change:

  - `settings.css_classes` — the v3.x + 4-compat live-render string (space-separated class names). Always written.
  - `settings._css_classes` — the 3.x legacy fallback. Always written, identical string.
  - `settings.classes.value` (list) — the v4-atomic wrapper of shape `{$$type:'classes', value:[…]}`. Sanitised class is appended (or set to only the new value when `$append_to_existing === false`) IF the wrapper is present. Logs a warning when this branch fires so the agent knows a plain CSS hook name like `productitem` only renders as a style reference once a matching entry exists in the page's `styles` map, or until the agent pairs this call with `Elementor_CSS_Override::inject_page_custom_css` targeting `.productitem` with the actual CSS.

### Elementor per-page CSS / JS

`Elementor_CSS_Override::inject_page_custom_css(int $post_id, string $css): true|WP_Error`. Appends `$css` to `_elementor_page_custom_css`. Selector-bump rules (private helper `ensure_html_body_prefix`):

  - Lines that look like `<selector> {` get `html body` prepended unless the selector already starts with `html|body|…`, an id `#`, or a pseudo-class function such as `:is()` / `:where()`.
  - Lines inside `@media` / `@supports` / `@keyframes` blocks pass through untouched (brace counter tracks block boundaries).
  - Result: `.e-flex { … }` becomes `html body .e-flex { … }` — specificity bumps 0,1,0 → 0,1,2; safe margin against Elementor 4.x source-order tiebreak.
  - Existing CSS is preserved; the new block is appended under a `/* ---- novamira-adrianv2 ---- */` separator so future agents can audit it.
  - Runtime warning: if `_elementor_page_settings` is already populated as a top-level post-meta, the helper logs a note that **some** Elementor 4.x builds read custom CSS from `_elementor_page_settings._custom_css` instead — if the new rules don't render you may need to merge into that nested key.

`Elementor_CSS_Override::generate_click_guard_script(int $threshold = 12, string $selector = '.productslider'): string`. Returns raw JS source. Internals:

  - On `pointerdown` start tracking pointer x via `lastX`; request `setPointerCapture` so a drag that leaves the slider still feeds `pointermove`.
  - On `pointermove` while dragging accumulate `moved += |dx|`.
  - A `click` listener in **capture** phase calls `e.preventDefault(); e.stopPropagation()` iff `moved > $threshold`. Taps that drift < threshold pixels still let inner anchors navigate.
  - The selector is passed through `wp_json_encode` and assigned to a `var __selector = <literal>;` before being passed to `document.querySelector(...)`. **Never** `addslashes()` a selector — `addslashes` does not escape `</script>`, leaving an XSS vector when the script ends up inside a `<script>` block in an Elementor HTML widget.

## Workflows

### Update a WPCode CSS snippet and confirm cache busted

```
1. Confirm WPCode is on:
   GET /wp-json/novamira/v1/status   (or any wpcode-check-setup ability)

2. Compose the new snippet body — read fresh from disk or from
   the previously audited file. NEVER read post_content via raw
   SQL; the helper's write path uses a different invalidation
   contract than a raw UPDATE.

3. Call update-wpcode-snippet { id: 5328, code: <fresh css> }.
   → response: { id: 5328, code_type: "wpcode_snippet_css",
                 status: "publish", code_length: 1400 }

4. If the response is WP_Error wpcode_purge_noop, or if the response
   carries warnings: tell the user the edit landed but WPCode may
   need a deactivate/reactivate cycle before the new CSS renders.

5. Curl the affected page (?purged=1 to bypass any front-end cache)
   and ask the browser to show the cascade.
```

### Update a WPCode JS snippet with new slider-track drag logic

Same as above but with `code_type: "wpcode_snippet_js"` and a complete
IIFE/closure that exports nothing. The helper returns `code_length` so
you can sanity-check the agent sent the right shape. The helper returns
WP_Error if the snippet body contains unbalanced braces (looks like a
syntax-broken JS) — fix the source and resubmit.

### Assign a class to every `.e-con` inside `.productslider`

```
1. Read the element tree for /produkte/ (one of):

   - Preferred MCP path: `novamira-adrianv2/elementor-assign-class-to-containers`
     { page_id: 3546, container_selector: ".productslider",
       class: "productitem" }. Handles read-by-selector + assign +
     save atomically; the `wp_check_post_lock` guard runs inside.

   - Finer control via execute-php: read with the existing
     `Elementor_Data_Helpers` trait (find_element / write_page)
     and mutate each matching element with
     `Elementor_Document_Saver::assign_class($element, 'productitem', true)`,
     then persist the whole mutated tree via
     `Elementor_Document_Saver::save_data(3546, $elements)`.

2. The mutation helper writes css_classes + _css_classes + v4 atomic
   classes.value in lock-step (one call). When the v4-atomic branch
   fires, an error_log line is emitted so the agent pairs the
   call with the matching CSS in step 3.

3. Pair with `Elementor_CSS_Override::inject_page_custom_css({
     post_id: 3546,
     css: ".productslider { ... }\n.productslider .productitem { ... }"
   })`.
   The CSS gets the `html body` prefix and beats `.e-con` rules in
   source-order tiebreak; existing CSS on the page is preserved.
```

### Add a click-vs-drag guard to an existing carousel

```
1. Call the helper from PHP-side (the agent stays in MCP / PHP,
   not in the human editor):
     $js = Elementor_CSS_Override::generate_click_guard_script(12,
                                                              '.productslider');

2. In the Elementor editor for the target page, open an HTML widget
   and paste:
   <script><?php echo $js; ?></script>
   (Inside a V4 atomic HTML widget, the inline JS runs once per
   page load — no enqueue needed.)

3. Sanity-check the helper output does NOT contain a literal
   </script> token:
     grep -c '</script>' <<< "$js"
   Should be 0. If > 0, file a bug.

4. From a browser, drag the carousel > 12 px horizontally and click
   an internal anchor — the navigation should NOT fire. Tap (no
   drag) on the same anchor — the navigation SHOULD fire (moved ≤ 12).
```

### Bulk re-style of all product containers

Same as the class-assignment workflow, but submit a single
`save_data` with the full updated tree rather than many small writes.
One Document-API write rebuilds CSS from `post_content` cleanly;
many small writes leave the per-post CSS file stale and trigger N
cache-purge fail-recover cycles (lots of `warnings[]` noise).

## Gotchas

These are the production hazards that the abilities exist to hide.
Memorise them — when you find yourself wanting to skip the helper
and write the SQL/option directly, this is the reminder.

- **WPCode compiled-asset cache invalidiert NICHT durch `wp_update_post`.** Keine `$wpdb->update` oder nacktes `wp_update_post` für Snippets — die Abilities regeln das.
- **`Elementor css_classes` ist das rendering-relevante Feld, `_css_classes` ist Legacy.** Beide Felder müssen gesetzt werden, sonst entstehen Cross-Version-Render-Inkonsistenzen.
- **Elementor 4.x CSS-Variablen `--flex-grow` / `--flex-shrink` können ein normaler `flex: 0 0 auto` überschreiben**, weil sie als Longhand-inline-style von Elementor gesetzt werden. Die CSS, die ihr hier reinschreibt, muss `!important` longhands erzwingen UND die Selektor-Specificität mit `html body` auf 0,1,2 bumpen — sonst gewinnt Elementors Default in Source-Order-Tiebreak.
- **`wp_update_post` strippt `post_content` via kses für `wpcode_snippet` Posts.** Lösung: `kses_remove_filters()` davor und `kses_init_filters()` danach aufrufen — so bleibt `save_post` aktiv (WPCode-Cache-Rebuild über `post_content`), aber der Inhalt wird nicht durch kses zerstört. **Immer** in `try/finally` wrappen — sonst kann ein thrown hook jeden späteren Content-Write der Request ungesichert lassen.
- **Concurrent Elementor editor sessions ohne Guard überschreiben das, was ein User grade im Editor editiert.** Vor `update_json_meta('_elementor_data', …)` immer `wp_check_post_lock($post_id)` prüfen. `save_data` macht das automatisch und gibt `success: false` zurück, wenn gelockt.
- **`addslashes()` reicht NICHT für JavaScript-Strings in `<script>`-Blöcken.** Es escapet keine `</script>`-Sequenz. Für inline JS in Elementor HTML-Widgets Selector immer durch `wp_json_encode()` schicken (`generate_click_guard_script` tut das).
- **`_elementor_page_custom_css` ist auf most Elementor 4.x installs immer noch gültig**, aber wenn die Seite bereits `_elementor_page_settings` populated hat, kann der Render in einigen Installs aus `_elementor_page_settings._custom_css` lesen statt. `inject_page_custom_css` loggt diese Warning; du musst entscheiden ob manual merge nötig ist.
- **WPCode-Snippet-Writes brauchen vier Sachen, nicht nur `wp_update_post`:** `kses_remove_filters` / `restaurate` (try/finally), expliziten `_wpcode_compiled_code` post-meta delete, die allow-listed Option-Liste-purge, on-disk `wp-content/uploads/wpcode/cache/*.cache.php` cleanup. Wenn du nur eines davon tust, serviert die Page weiter das alte compiled asset.
- **WPCode Cache-Keys sind allow-listed.** `invalidate_compiled_cache` löscht NICHT wildcard SQL auf `wpcode_options` — die Liste ist hartcodiert damit nicht eine User-edited WPCode-Setting-Konfiguration verloren geht.
- **Plain class name in v4-atomic `settings.classes.value` rendert erst, wenn ein matching entry im `styles`-Map existiert.** Der Helper loggt das beim Schreiben — pair mit `inject_page_custom_css`, das die eigentliche CSS-Regel auf `.classname` schreibt.

## Conventions

  - Slugs: `novamira-adrianv2/<domain>-<verb>-<object>`. Today there are two domains: `wpcode-*` and `elementor-*`. Verbs are `update`, `get`, `list`, plus `elementor-assign-class-to-containers` for the class-assignment composite.
  - **Read-paradigm first.** Every write should be preceded by a read that returns the current state. This lets the agent merge / compare / sanity-check rather than blindly overwrite.
  - `update` and `assign_class` abilities are partial / merge: caller sends only fields that change. The legacy string fields are recomputed downstream from all current classes (atomic + legacy both updated in lock-step).
  - `inject_page_custom_css` is the only append-only write — never clears existing CSS; always prepends with a `/* ---- novamira-adrianv2 ---- */` marker. To replace the novamira-bump block surgically, re-read the merged meta, splice out the marker-delimited range, and write back.
  - `save_data` returns a structured array, **NOT** a WP_Error union. Inspect `success` rather than treating a falsy value as an error. The `warnings[]` array carries every soft-fail (Post-CSS delete, files_manager clear_cache) that did not block the write but might still need attention.
  - Error codes are namespaced by surface: `wpcode_*` for snippet writes (`wpcode_invalid_id`, `wpcode_empty_content`, `wpcode_kses_missing`, `wpcode_snippet_not_found`, `wpcode_wrong_post_type`, `wpcode_update_failed`, `wpcode_purge_noop`, `wpcode_runtime_unavailable`), `elementor_*` for Document API + class-assignment (`elementor_invalid_id`, `elementor_inactive`, `elementor_post_not_found`, `elementor_post_locked`, `elementor_no_doc`, `elementor_update_threw`, `elementor_empty_class`, `elementor_invalid_class`), `css_override_*` for page CSS (`css_override_invalid_id`, `css_override_empty_css`, `css_override_runtime_unavailable`, `css_override_post_not_found`, `css_override_invalid_css`, `css_override_save_failed`).
  - The At-Least-Once Cache Purge contract: every `update-wpcode-snippet` MUST be followed by either a successful normal purge (the normal path) OR a `wpcode_purge_noop` WP_Error that escalates to "abort the workflow, recommend deactivate+reactivate WPCode via admin" — never silently proceed.
