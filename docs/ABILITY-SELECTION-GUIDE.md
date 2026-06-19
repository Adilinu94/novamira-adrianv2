# Ability Selection Guide — Elementor Page Building

> **Audience:** This document is written for AI agents and human operators that
> build Elementor pages through the available MCP abilities on a WordPress site
> with the Novamira plugin stack. It is the single source of truth for choosing
> between the three competing write paths:
>
> 1. `novamira/elementor-set-content` (Novamira Pro, namespace `novamira/*`)
> 2. `novamira-adrianv2/batch-build-page` (AdrianV2 plugin, namespace `novamira-adrianv2/*`)
> 3. `novamira/elementor-add-element` + `edit-element` (granular, Novamira Pro)
>
> Read this **before** the first page-write call. Picking the wrong path costs
> a turn to debug, sometimes loses the page, and confuses the user.

## TL;DR — Decision Matrix

| Scenario | Use | Why |
|---|---|---|
| You have a complete V4 atomic tree (or close to one) and want to write it in one call | **`novamira/elementor-set-content`** | Server-side validation, inline schema on errors, official path, no plugin-bug risk |
| You have a complete V4 + V3 mix tree and are on the AdrianV2 plugin | `novamira-adrianv2/batch-build-page` (after bugfix) **or** `novamira/elementor-set-content` with the same tree | Either works once Guards is wired; `set-content` is safer |
| You are adding/editing ONE element on an existing page | `novamira/elementor-add-element` / `edit-element` | Cheaper than full-tree rewrite, validation is local to the affected widget |
| You are iteratively building page-by-page (each call adds one section) | `novamira/elementor-add-element` in a loop | Avoids shipping a half-built tree that fails whole-page validation |
| The user says "build me a page from this Framer XML" | **`novamira/elementor-set-content`** with the V4 tree | Atomic tree → atomic write path; one roundtrip |
| The user wants to migrate an existing V3 Elementor page to V4 Atomic | Run `detect-elementor-version` with `post_id`, then `kit-convert-v3-to-v4`, then rebuild/convert the page tree with Atomic widgets | Kit conversion creates Variables/Classes only; page structure conversion is a separate step |

**Default rule:** if in doubt, use `novamira/elementor-set-content`. It is
official, validated, and lives in the `novamira/*` namespace which is not
affected by the AdrianV2 namespace bug.

---

## The Three Write Paths In Detail

### 1. `novamira/elementor-set-content` (Novamira Pro) — **RECOMMENDED**

**Namespace prefix:** `novamira/*`
**Where it lives:** Novamira Pro core, registered by the Novamira plugin
itself (not the AdrianV2 add-on).
**Schema:**
- `post_id` (required, int): the post to overwrite.
- `content` (required, array): the complete Elementor document tree.
- Returns: success status + validation summary.

**Strengths:**
- **Server-side validation.** Every call checks every control against the
  widget's live schema. Unknown control IDs and bad enum values abort the
  write — nothing is persisted.
- **Inline schema in errors.** When validation fails, the affected widget's
  compact content-only schema is returned INLINE in the error, so you can
  correct the value and retry in a single roundtrip without an extra
  `get-schema` call.
- **Atomic auto-wrap.** V4 atomic widgets accept ergonomic scalars
  (`color: "#FFFFFF"`, `font-size: 72`, `padding: {block-start: 16, ...}`)
  and the server wraps them into the `{"$$type": ..., "value": ...}` shape.
  You can also pass the long-form shape directly.
- **Cache invalidation.** Calls `clean_post_cache` and fires Elementor's CSS
  cache invalidation so the frontend reflects the change immediately and
  third-party optimization plugins (Perfmatters, WP Rocket, LiteSpeed) can
  purge their per-post caches.
- **Content-only schema output.** When you ask for the schema of a widget
  you already have, the response is the minimal `{t, opts?, def?, ...}` shape
  with no labels — so it can be fed straight back into `set-content` without
  translation.
- **Works on both v3 and v4 atomic pages.** The same call writes to legacy
  Elementor widgets (`heading`, `text-editor`, `button`) and to atomic
  widgets (`e-heading`, `e-paragraph`, `e-button`, `e-flexbox`, `e-div-block`).

**Weaknesses:**
- For very large trees (hundreds of elements) the response can be heavy.
  For batch operations on many pages, prefer the granular add/edit path.
- Requires `post_id` to exist (no auto-create — use `novamira/create-post`
  first if building from scratch).

**When to choose it:**
- ANY complete-tree write where you already know the shape.
- Any build that comes out of an upstream tool (Framer XML, HTML conversion,
  screenshot reconstruction, AI design agent output).
- Any build on a page where validation feedback matters more than speed.

### 2. `novamira-adrianv2/batch-build-page` (AdrianV2 plugin) — **CONDITIONAL**

**Namespace prefix:** `novamira-adrianv2/*`
**Where it lives:** the AdrianV2 add-on plugin (`adilinu94/novamira-adrianv2`),
file `includes/abilities/elementor/class-batch-build-page.php`.
**Schema:**
- `post_id` (optional, int): existing post to overwrite.
- `title`, `slug`, `status`, `template` (optional): for creating a new page
  when `post_id` is omitted — this is the only ability in this list that
  **creates** a page in a single call.
- `elements` (required, array): complete element tree.
- `page_css`, `page_js` (optional, strings): page-level custom CSS / JS.
- Returns: post_id, permalink, edit_url, total_elements, element_ids,
  created_page flag.

**Strengths:**
- **Auto-creates the page.** If `post_id` is omitted, it inserts a new WP
  page (draft by default), sets `_elementor_edit_mode=builder`, writes
  `_elementor_data` and `_wp_page_template=elementor_header_footer`. This is
  a true one-call "build a new page" API.
- **More forgiving v3/v4 mixing.** Has a wider list of known widget types
  in `ATOMIC_WIDGETS` and `CONTAINERS` constants, plus a fallback branch
  for unknown `e-*` types. Useful when you don't know whether the target
  page is v3 or v4 and the caller is in a hurry.
- **Built-in normalization pass.** After building the tree, it runs four
  post-processors: `sanitize_style_ids`, `normalize_style_variants`,
  `normalize_image_src_values`, `normalize_attributes_values`,
  `normalize_style_prop_scalars`. These exist because the original Framer
  pipeline output did not always match Elementor's editor-roundtrip
  validator and the AdrianV2 plugin authors saw the failures firsthand.

**Weaknesses:**
- **No server-side validation.** Unlike `novamira/elementor-set-content`,
  this ability writes the data immediately and runs four normalization
  passes afterwards. A malformed tree gets persisted; bad values only
  surface when the user opens the page in the Elementor editor and the
  Style_Parser rejects them.
- **Returns no inline schema on error.** If a write fails, you get an
  `'error'` field with a free-text string. You then have to make a second
  call to `novamira/elementor-get-schema` to figure out what went wrong.

**When to choose it (when not broken):**
- When the caller explicitly wants page-creation in one call AND the page is
  small (< 50 elements). For larger pages, prefer `novamira/create-post`
  followed by `novamira/elementor-set-content`.
- When the caller has a Framer-pipeline-style tree that needs the
  normalization passes (rare in 2026 — most modern output is already
  Elementor-validator-clean).

### 3. `novamira/elementor-add-element` + `edit-element` (Novamira Pro) — **GRANULAR**

**Namespace prefix:** `novamira/*`
**Where it lives:** Novamira Pro core.
**Schema (`add-element`):**
- `post_id` (required, int): target post.
- `parent_id` (optional, string|null): where to insert. `null` = root.
- `element_type` (required, string): `"widget"` + `widget_type`, OR
  `"e-flexbox"` / `"e-div-block"` / `"container"` as raw elTypes.
- `element_id` (optional, string, kebab-case): human-readable ID for
  `data-id` and `s-<element_id>` class — **use this** for named sections,
  omit for anonymous loop items.
- `settings` (optional, object): element settings, validated against the
  schema of the chosen widget type.
- `styles` (optional, object): V4 atomic local styles.
- `position` (optional, int|string): `"start"`, `"end"`, or index in siblings.

**Strengths:**
- **Local validation, no whole-tree risk.** Validation runs against the
  single element being added — the rest of the page is untouched.
- **Cheap to retry.** A bad call returns the schema of the affected widget
  inline in the error.
- **Atomic + legacy in one call.** Auto-detects v3 vs v4 atomic format.
- **Defaulting.** The server fills schema defaults for any atomic control
  you don't supply (link, tag, attributes, classes, ...) — passing minimal
  `settings` is safe.

**Weaknesses:**
- N roundtrips for N elements. For a 30-element hero, that's 30 calls.
- No single transaction — if call 15 fails, calls 1-14 already wrote.

**When to choose it:**
- Iterative construction (each call = one user-visible step).
- Adding ONE element to an existing page.
- Edits where you only want to touch one element's settings.

---

## Historical Note: The AdrianV2 Guards Namespace Bug

Older AdrianV2 builds had a broken `use` statement for `Guards`, so PHP
resolved cache invalidation to `Novamira\AdrianV2\Guards` instead of
`Novamira\AdrianV2\Helpers\Guards`. The symptom was:

```
PHP Fatal error:  Class "Novamira\AdrianV2\Guards" not found in
.../includes/abilities/elementor/class-batch-build-page.php on line 138
```

The page write itself usually succeeded — `_elementor_data` was on disk —
but the response was a fatal error.

**If you still see this error on a host:**

1. Verify the write actually landed: `novamira/elementor-get-content
   post_id=<id>` should return the tree.
2. Update AdrianV2 to a build where `use Novamira\AdrianV2\Helpers\Guards;`
   is used.
3. Use `novamira/elementor-set-content` as a temporary workaround.

**Current master status:** `batch-build-page` imports
`Novamira\AdrianV2\Helpers\Guards` correctly.

---

## Concrete Worked Example

### Scenario A — Single-call V4 atomic page from a Framer export

Caller has a V4 atomic tree from the framer-v4-pipeline (155 nodes,
`elements.json` in `tools/framer-export/.../v4-output/`).

**Correct call:**

```json
{
  "ability": "novamira/elementor-set-content",
  "parameters": {
    "post_id": 1234,
    "content": [<full V4 tree from elements.json>]
  }
}
```

**Why this and not batch-build-page:** the tree is already validator-clean
(no need for the normalization passes), validation feedback matters if any
node has a bad value, and `set-content` is unaffected by the Guards bug.

### Scenario B — Build a brand-new page from a small HTML snippet

Caller has 8 elements (hero + 3 cards + CTA) and no existing post.

**Correct call sequence:**

1. `novamira/create-post` with `post_type: "page"`, `status: "draft"`,
   `title: "Landing Page"` → returns `post_id`.
2. `novamira/elementor-set-content` with the 8-element tree → returns success.

**Why not `batch-build-page`:** it would do steps 1 and 2 in one call, but
the page is currently broken (Guards bug), and the auto-create path runs
before the failure point, so a failed call leaves an empty page in the DB
that the user has to clean up.

### Scenario C — Add one section to an existing page

**Correct call:**

```json
{
  "ability": "novamira/elementor-add-element",
  "parameters": {
    "post_id": 1234,
    "parent_id": null,
    "element_type": "e-flexbox",
    "element_id": "testimonials-section",
    "settings": {},
    "styles": { "padding": {"block-start": 64, "block-end": 64, "inline-start": 24, "inline-end": 24} }
  }
}
```

Then repeat `add-element` with `parent_id: "testimonials-section"` for each
child.

**Why not `set-content`:** would rewrite the entire page just to add one
section — wasteful and risky.

---

## Anti-Patterns To Avoid

1. **Calling `batch-build-page` then `set-content` on the same post in one
   turn.** Even when batch-build-page is fixed, the two write the same
   `_elementor_data` meta key — the second call silently overwrites the
   first. Decide one path per page-build.

2. **Falling back to `set-content` after a `batch-build-page` error
   without verifying the post didn't get created.** The auto-create path in
   `batch-build-page` runs before the failure point, so a retry without a
   `post_id` will create a SECOND empty page.

3. **Putting `padding`, `flex-direction`, `gap`, etc. into the `settings`
   of an atomic container.** For v4 atomic elements these go into the
   `styles` map, not `settings`. The `add-element` ability returns a
   targeted hint in the error if you make this mistake.

4. **Wrapping content in `<p style="...">...</p>` for alignment.** Use
   `align` for alignment, `typography_*` for font sizing/weight/family,
   `_padding`/`_margin` for spacing, `_element_custom_width` for
   max-width. Layout markup in content fields gets rejected by the
   Elementor validator.

5. **Trusting v3 widget control IDs as valid for v4 atomic widgets.** v3
   `heading` has `title`, `typography_*`, `_margin` etc. v4 `e-heading`
   has `title`, `tag`, plus its styling in a `styles` map. Mixing the two
   produces silent visual breakage.

---

## V3 vs V4 Detection and Split (AdrianV2 v1.1.0+)

Starting with AdrianV2 v1.1.0 the plugin enforces a clean split between
Elementor V3 and V4 (atomic) capabilities. Abilities are no longer "try
and see" — they actively refuse the wrong path.

### Detection — Always Run First

Before touching a page, ask the plugin what it sees:

```
novamira-adrianv2/detect-elementor-version
```

This returns `{ elementor_version, atomic_supported, supports_atomic, ... }`.
Pass `post_id` to also get `{ page_version, page_is_v4, detected,
recommended_page_action }`. If `atomic_supported` is `false` the site is not
ready for V4-only abilities.

### V4-Only Abilities (refuse on V3 site unless `opt_in: true`)

| Ability | Why V4-only |
|---|---|
| `novamira-adrianv2/setup-v4-foundation` | Creates `e-flexbox-base` / `e-div-block-base` global classes — meaningless on V3. |
| `novamira-adrianv2/add-global-class-variant` | Global Classes are a v4 concept. |
| `novamira-adrianv2/edit-global-class-variant` | Same. |
| `novamira-adrianv2/remove-global-class` | Same. |
| `novamira-adrianv2/apply-variable-to-class` | V4 design tokens only. |
| `novamira-adrianv2/kit-convert-v3-to-v4` | Migrates legacy kit colors/typography into V4 Variables and Global Classes. It does not convert page structure. |
| `novamira-adrianv2/rollback-build` | Snapshots elementor_data via WP revisions; the rollback only makes sense for atomic-tree edits. |

These abilities refuse when the site is not V4-capable. Check each ability
schema before assuming an `opt_in` override exists.

### Mixed Abilities (V4-aware per page)

| Ability | Behavior |
|---|---|
| `novamira-adrianv2/batch-build-page` | Reads `_elementor_version` and the elType shape of the existing page. Returns `WP_Error(novamira_adrianv2_page_version_mismatch)` if you build a V4 tree on a V3 page (or vice versa). |
| `novamira-adrianv2/batch-class` | Same per-page check. |
| `novamira-adrianv2/patch-element-styles` | Same per-page check. |

The detection uses `Elementor_Version_Resolver::detect_page_version()` —
check that on a page with version metadata cleared to confirm the heuristic
before relying on it in your workflow.

### New v1.1.0 Abilities

| Ability | What it does | When to use |
|---|---|---|
| `novamira-adrianv2/sync-schema` | Exports the live V4 prop-type schema (`version`, `types`, `properties`) from `V4_Props::get_schema()`. `format=compact` returns ~5 KB, `format=full` returns ~50 KB. Supports `sections=['types'|'properties'|'all']`. | Use as the authoritative schema source when validating a generated V4 tree before write. Replaces the framer-v4-pipeline-v2-main `schemas/v4-atomic-schema.json` cache. |
| `novamira-adrianv2/self-audit` | Runs three health checks on the plugin: BOM check across all PHP files, `declare(strict_types=1)` probe via `tempnam` + php cli, V2-ability count vs. expected. Returns `{overall_status, checks: [{name, status, ...}]}`. Use `include_*_check` flags to select which checks run. | Call before a build on a freshly updated plugin to detect BOM-polluted files or missing strict-types declarations. |
| `novamira-adrianv2/rollback-build` | Snapshots `_elementor_data` to a WP revision before a destructive build, then restores from the latest revision tagged `_novamira_rollback_status=good` when rolled back. Uses `wp_save_post_revision()` + `wp_get_post_revisions()` natively. | Use before any irreversible batch-build-page / patch-element-styles call when the build might regress. |

### Canonical Detection Helper

If you need to detect V3/V4 from outside the abilities (e.g. inside
custom PHP via `execute-php`), use the singleton:

```php
\Novamira\AdrianV2\Helpers\Elementor_Version_Resolver::site_is_v4();
\Novamira\AdrianV2\Helpers\Elementor_Version_Resolver::detect_page_version($post_id);
```

Both are cached for 5 minutes per post-id via `wp_cache_*`. Call
`wp_cache_delete('novamira_resolver_v4_' . $post_id, 'novamira')` to
bust the cache after a manual meta write.

---

## Versioning Note

This document is current as of the Novamira stack version on the test4
host: WordPress 6.9.4, Elementor 4.1.0-beta1 (atomic runtime available),
Novamira 1.7.0, **Novamira AdrianV2 1.1.0** (V3/V4 split + 3 new
abilities), Novamira Pro 1.3.0. If any of those numbers change,
re-verify by calling `novamira/elementor-check-setup` before relying on
this guide for a new build.
