# Changelog — Novamira AdrianV2

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
