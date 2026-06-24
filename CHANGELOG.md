# Changelog — Novamira AdrianV2

## [1.7.0] — 2026-06-24

### Added

#### 5 neue Kit-Abilities — schließen das offene Versprechen von `import-template-kit`

| Ability | Klasse | Beschreibung |
|---|---|---|
| `novamira-adrianv2/rollback-kit-import` | `Kit_Rollback` | Rollback einer Kit-Import-Session via Snapshot-ID; löscht erstellte Posts, restauriert WP-Settings, deaktiviert Plugins |
| `novamira-adrianv2/list-kit-snapshots` | `Kit_Rollback` | Listet alle verfügbaren Rollback-Snapshots (neueste zuerst) |
| `novamira-adrianv2/check-editor-health` | `Kit_Editor_Health` | 4 read-only Readiness-Checks: REST API, admin-ajax, checklist.js Null-Deref-Bug, HFE CSS-Pfade |
| `novamira-adrianv2/import-kit-plugins` | `Kit_Plugin_Installer` | Prüft, installiert (wordpress.org) und aktiviert Plugins aus dem Kit-Manifest; dry-run default |
| `novamira-adrianv2/import-kit-media` | `Kit_Media_Handler` | Lädt Kit-Medien in die WP Media Library und schreibt URLs in `_elementor_data` um |
| `novamira-adrianv2/import-kit-fonts` | `Kit_Font_Localizer` | Google Fonts lokal hosten (DSGVO-konform), `@font-face` CSS als WP-Option gespeichert |

- `bootstrap.php` registriert jetzt alle 8 Kit-Ability-Klassen (vorher nur 2).
- `Kit_Manifest::from_json()` statische Factory-Methode hinzugefügt (Shorthand für `new Kit_Manifest($json)`).

#### `novamira-adrianv2/export-kit` — neues Feature, größter Nutzwert

- Neue Klasse `Kit_Exporter` → Ability `novamira-adrianv2/export-kit`.
- Exportiert die aktuelle WordPress-Site als wiederverwendbares **Novamira Enhanced Kit Manifest JSON** — direktes Gegenstück zu `import-template-kit`.
- **Was wird exportiert:**
  - Alle Elementor-editierten Posts/Pages (`_elementor_data` + Metadaten)
  - Elementor Kit Globals (Design-Tokens: Farben, Typografie aus `page_settings`)
  - WP-Site-Settings (`blogname`, `permalink_structure`, `page_on_front` als Template-Ref)
  - Aktive Nav-Menus mit Item-Targets (`page:ref`, `url:href`, `home`, `category:slug`)
  - Plugin-Anforderungen (aktive Plugins ohne Core-Elementor)
  - Media-File-Referenzen (aus `_elementor_data` extrahierte Upload-URLs)
  - Google-Fonts-Familiennamen (aus Typography-Settings)
  - Aktiver Theme-Slug
- Parameter: `kit_name`, `kit_version`, `post_ids` (Filter), `include_menus/plugins/media`, `save_as_option`
- Output: `{ success, manifest (JSON-String), summary, warnings }`

#### V3→V4 Converter: `icon-box` und `image-box`

- `icon-box` und `image-box` zu `WIDGET_MAP` hinzugefügt (Wert `null`).
- Beide Widgets werden mit **`kept_v3` + Warn-Message** behandelt (statt `unsupported_widgets`).
- Strategie `skip` korrekt unterstützt: beide werden dann aus dem Baum entfernt.
- Warn-Message enthält Migrationsempfehlung (`e-svg + e-heading + e-paragraph` bzw. `e-image + e-heading + e-paragraph`).

#### Tests: 165 → 203 (+38 neue Tests)

**Neue Testdatei `KitHelpersTest.php`** — 36 Unit-Tests für pure-PHP-Logik ohne WP-DB-Abhängigkeit:
- `Kit_Menu_Builder::resolve_target()` — 10 Tests (alle Präfix-Varianten: `url:`, `home`, `page:`, `category:`, unbekannt, leer)
- `Kit_Plugin_Installer::find_plugin_file()` — 8 Tests (exact match, directory fallback, not found, no partial match, hyphens)
- `Kit_Rollback` Ring-Buffer — 18 Tests (create/list/record/delete snapshots, MAX_SNAPSHOTS-Cap, Cleanup, Isolation)

**`V3ToV4ConverterTest.php`** — 6 neue Tests:
- `icon-box` → kept_v3 mit Warning (keep strategy)
- `icon-box` → skip (skip strategy)
- `image-box` → kept_v3 mit Warning (keep strategy)
- `image-box` → skip (skip strategy)
- `WIDGET_MAP` enthält jetzt `icon-box` und `image-box`

**Test-Bootstrap:**
- Lädt `Kit_Page_Creator`, `Kit_Menu_Builder`, `Kit_Rollback`, `Kit_Plugin_Installer`
- Neue WP-Stubs: `get_option`, `update_option`, `home_url`, `get_permalink`, `get_term_by`, `get_term_link`, `get_theme_mod`, `set_theme_mod`, `switch_theme`, `flush_rewrite_rules`, `wp_delete_post`, `wp_delete_nav_menu`, `deactivate_plugins`
- `$wpdb` In-Memory-Stub für `Kit_Page_Creator::resolve_template_ref()`

---

## [1.6.0] — 2026-06-23

### Added

#### `Local_Styles_Renderer` — Frontend-CSS-Workaround für Elementor 4.1.x
- **Problem:** `elementor/atomic-widgets/styles/register` wird im Elementor 4.1.x Frontend nie gefeuert. Alle Atomic Widget CSS-Klassen (`.e-{style_id}`) haben kein CSS-Backing → Schriften, Farben, Abstände sichtbar nicht gerendert.
- Neue Klasse `Novamira\AdrianV2\Helpers\Local_Styles_Renderer` in `includes/helpers/class-local-styles-renderer.php`.
- Hängt sich in `wp_head` (Priorität 100) ein.
- Liest `_elementor_data` direkt via `$wpdb->get_var()` (kein `wp_unslash`-Problem).
- Läuft rekursiv durch den Element-Tree, sammelt alle `element.styles`-Maps.
- Emittiert `<style id="novamira-atomic-styles">` mit einer CSS-Regel pro Stil-Variante.
- **`prop_to_css()` Mapping:**
  - `global-color-variable` → `var(--e-gv-{id})`
  - `color` → `#HEX` / `rgba()` / `hsl()` as stored
  - `size` → `{n}{unit}` z.B. `16px`, `1.5rem`
  - `dimensions` → expandiert zu `padding-block-start`, `padding-inline-end` etc.
  - `string`, `number`, `boolean` → Rohwert
- **Responsive:** `desktop` = kein Wrapper, `tablet` = `max-width: 1024px`, `mobile` = `max-width: 767px`.
- **Disable Gate:** Automatische Deaktivierung bei `ELEMENTOR_VERSION >= 4.2.0`. Außerdem per Filter override-bar: `add_filter('novamira_adrianv2/local_styles_renderer/enabled', '__return_false')`.
- **Autoloader:** Klasse in `vendor/composer/autoload_classmap.php` und `vendor/composer/autoload_static.php` eingetragen.
- Registrierung in `includes/helpers/bootstrap.php` (Schritt 19).

#### PHPUnit Tests — `LocalStylesRendererTest`
- 33 neue Assertions in `tests/LocalStylesRendererTest.php`.
- Deckt ab: alle `$$type`-Fälle in `prop_to_css()`, `dimensions_shorthand()`, `dimensions_to_declarations()`, `collect_styles()` (flach, tief, Deduplizierung, Fehlertoleranz), `render_style_def()` (Selektor-Prefix, `@media`, Pseudo-Klassen, leere Varianten, übersprungene Props).
- `tests/bootstrap.php` um WP-Stubs (`add_action`, `apply_filters`) und `ELEMENTOR_VERSION`-Konstante erweitert.

### Fixed

#### Version-Alignierung
- Plugin-Header `Version: 1.0.0` → `1.6.0`
- Konstante `NOVAMIRA_ADRIANV2_VERSION` `1.1.0` → `1.6.0`
- Beide lagen bisher hinter dem CHANGELOG zurück; jetzt aligned.

---

## [1.5.0] — 2026-06-21

### Added

#### Kit-Level Responsive Style Fix (`fix_kit_styles_for_page`)
- New public method `Conversion_AutoFixer::fix_kit_styles_for_page(array $page_tree, int &$fixes): ?array`
  - Finds style classes referenced by page elements but NOT defined in the page tree (Kit-defined classes)
  - Loads the active Elementor Kit's `_elementor_data` (with static caching, keyed by Kit ID)
  - Generates missing tablet/mobile responsive variants for those Kit-level classes
  - Returns the modified Kit tree for the caller to persist, or `null` if no changes
- Integrated into `Convert_Page_V3_To_V4::execute()` after auto-fix: persists modified Kit tree via `update_post_meta()` when `dry_run=false`

#### e-flexbox → e-div-block Conversion (Depth Guard)
- When `fix_flexbox_widget_children` depth guard blocks wrapping (children would exceed `MAX_NESTING_DEPTH`), the e-flexbox is converted to `e-div-block` if it has no flex layout settings
- Eliminates remaining e-flexbox-direct-widget audit errors without increasing nesting depth

#### Second-Pass Responsive Fix
- Steps 2f/2g in `Conversion_AutoFixer::run()`: second pass of `generate_responsive_variants` + `remove_identical_mobile_overrides`
- Fixes a cycle where `remove_identical_mobile_overrides` deletes mobile variants whose V3 values happened to be identical to desktop; the second pass regenerates them with properly scaled values

#### PHPUnit Tests
- **`ConversionAutoFixerTest.php`** — 29 unit tests covering:
  - `process_style_variants`: desktop-only→tablet+mobile, existing tablet→mobile-only, all variants→skip, no desktop→skip, empty variants, font-size clamping (14px), multi-prop, mixed responsive/non-responsive props
  - `clean_mobile_overrides`: identical props removed, different kept, all-identical→variant deleted, no mobile→skip, extra mobile-only props kept, tablet variant preserved
  - `fix_kit_styles_for_page`: no missing classes→null, no references→null, empty tree→null
  - `scale_props`: font-size scaling (0.9× tablet, 0.8× mobile), width→100%, max-width→100%, padding/gap scaling, string props (calc) skipped, rounding to 1 decimal
- **`tests/FixKitStylesForPageIntegrationTest.php`** — 5 WordPress integration tests (requires wp-load.php, run via `tests/run-integration.php`):
  - Kit class with only desktop → tablet + mobile generated
  - Nested Kit elements with styles fixed
  - Multiple Kit classes all get fixes
  - No matching classes → returns null
  - Already complete variants → returns null

#### Batch Conversion Script
- `batch-convert.php`: CLI script for bulk V3→V4 conversion (located at plugin root)
  - Discovers all V3 pages (legacy_widget_count > 0) via `List_Elementor_Pages`
  - Supports `--dry-run` (preview) and `--execute` (persist)
  - All 30 V3 pages successfully migrated in one batch

### Refactored

#### Extracted Style Helpers
- `process_style_variants(&$style_def, &$fixes): void` extracted from `generate_responsive_variants`
- `clean_mobile_overrides(&$style_def, &$fixes): void` extracted from `remove_identical_mobile_overrides`
- Both operate on a single style definition, reusable by `fix_kit_styles_for_page` and `fix_kit_styles_walk`

#### Foreach Iteration Robustness
- All three style-iterating methods (`generate_responsive_variants`, `remove_identical_mobile_overrides`, `fix_kit_styles_walk`) now use `array_keys($styles)` snapshot instead of `foreach ($styles as $class_id => &$style_def)`
- Prevents PHP Copy-On-Write / HashTable reallocation from causing iterator element skips

### Fixed

#### `scale_props()` — Width/Max-Width Special Case
- `0.0 === $scale` changed to `0.0 === (float) $scale` — the scale constants define `width => 0` and `max-width => 0` as integers, but strict comparison against `0.0` (float) failed
- Width/max-width → 100% conversion now works correctly for both mobile and tablet breakpoints

#### `fix_kit_styles_walk()` — Recursive Modification Bug
- Fixed: `$el['elements']` was not reassigned after recursive call, causing nested Kit modifications to be lost

### Statistics

| Metric | Value |
|---|---|
| Pages converted (batch) | 30 |
| Widgets converted | 628 |
| Widgets kept as V3 | 129 |
| Auto-fixes applied (per batch) | 4,849 |
| Audit errors after conversion | 0 |
| Audit warnings after conversion | 17 (all from 3rd-party widgets) |
| Unit tests | 29 |
| Integration tests | 5 |
| Total test assertions | 367 + 36 |
| Total test failures | 0 |

---

## [1.4.0] — 2026-06 (prior)

### Added
- `Conversion_AutoFixer` class with `run()`: two-pass architecture
  - Pass 1: structural fixes (empty containers, empty/broken widgets, e-flexbox wrapping, excessive nesting flattening)
  - Pass 2: style fixes (dangling refs, orphan styles, duplicate styles, responsive variants, identical overrides)
- `Conversion_Auditor` class: layout, class, and responsive audits
- `V3_To_V4_Converter`: widget/container/style conversion pipeline
- `Convert_Page_V3_To_V4`: single-page V3→V4 ability with `auto_fix`, `run_kit_convert`, `dry_run` options
- `Kit_Convert_V3_To_V4`: Kit-level design system conversion (colors→variables, typography→global classes)
- `Elementor_Document_Saver`: Elementor 4.0 data persistence
- `Elementor_Version_Resolver`: site version detection
