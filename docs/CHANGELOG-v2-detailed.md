# CHANGELOG v2 — Detaillierte Migrations-History

> **Dokument:** Vollständige Entwicklungsgeschichte des `novamira-adrianv2` Plugins  
> **Stand:** 2026-06-21  
> **Referenz:** Ergänzung zu `CHANGELOG.md` — fokussiert auf Architektur-Entscheidungen und Breaking Changes

---

## Architektur-Übersicht

```
novamira-extra (alt, deprecated)
    └── Durch novamira-adrianv2 vollständig ersetzt
        └── Namespace: novamira-adrianv2/* (NICHT adrians-* oder novamira/adrians-*)
```

**Goldene Regel:** `novamira-extra` existiert NICHT mehr. Alle Abilities sind in `novamira-adrianv2`.

---

## Version 1.5.0 — 2026-06-21

### Kernänderungen

**`Conversion_AutoFixer`** massiv erweitert:
- `fix_kit_styles_for_page()` — Kit-Level Responsive Style Fix für Klassen, die auf Seitenebene referenziert aber im Kit definiert sind
- `process_style_variants()` und `clean_mobile_overrides()` als extrahierte Helper-Methoden
- Second-Pass-Cycle für Responsive-Fix (Step 2f/2g)
- Depth-Guard: e-flexbox → e-div-block-Konvertierung wenn MAX_NESTING_DEPTH überschritten

**PHP-Tests:** 29 neue PHPUnit-Tests in `ConversionAutoFixerTest.php` + 5 Integration-Tests

**Batch-Skript:** `tools/batch-convert.php` für CLI-Massenkonvertierung aller V3-Seiten

---

## Version 1.4.0 — 2026-06-20

### Hinzugefügt

- `V3_To_V4_Converter::wrap_direct_widget_children()` — automatisches e-div-block-Wrapping für direkte Widget-Kinder in e-flexbox (löst Layout-Constraint aus E2E-Test 3.3)
- `V3_To_V4_Converter::normalize_paragraph_content()` — `</p><p>` → `<br><br>` Konvertierung, saubere Content-Migration
- `V3_To_V4_Converter::build_color_index()` — einmaliger Aufbau des `[normalized_hex → e-gv-ID]` Index aus variable_map
- `V3_To_V4_Converter::resolve_color_var()` — O(1) Hex-zu-GV-Lookup
- `V3_To_V4_Converter::v4_color()` — gibt `{$$type: 'global-color-variable', value: 'e-gv-...'}` zurück wenn Treffer, sonst `{$$type: 'color', value: '#HEX'}`
- Korrekte `e-heading`-Migration: `header_size` → `tag`, default `h2`
- Korrekte `e-image`-Migration: `V4_Props::image($id)` gibt `{$$type: 'image-attachment-id', value: N}` zurück (Invariante IV korrekt)
- Container-Layout-Props: `flex_direction`, `align_items`, `gap`, `overflow` aus V3-Settings extrahiert
- Responsive Overrides: `_tablet`/`_mobile`-Suffix-Keys korrekt extrahiert und als separate Breakpoint-Variants gespeichert

### Fixes

- **PHP 8.2 Deprecation:** `${size}` → `{$size}` in `class-conversion-auditor.php:350`
- **Autoloader:** `class-v3-to-v4-converter.php`, `class-conversion-auditor.php`, `class-conversion-auto-fixer.php` explizit in `helpers/bootstrap.php` geladen (war zuvor Ursache für "Class not found" Fatal Errors)
- **`duplicate-page`:** Sicheres SQL-basiertes Kopieren via `$wpdb->insert()` statt `update_post_meta()` (verhindert JSON-Korruption durch WP-Slashing)

---

## Version 1.3.0 — 2026-06-15

### V4 Infrastruktur

- `Elementor_Version_Resolver` als Single-Source-of-Truth für V3/V4-Detection eingeführt
- `V4_Props` — vollständige Prop-Type-Abdeckung (`string`, `size`, `color`, `image-attachment-id`, `dimensions`, `global-color-variable`)
- `V4_Color_Contrast` — WCAG 2.2 Methoden merged (aus `V4_Color_Contrast_22`)
- `V4_Color_Contrast_22` als deprecated markiert (extends V4_Color_Contrast, fügt nichts hinzu)

### Neue Abilities

- `elementor-check-setup` — Vollständige Elementor-Setup-Prüfung (V3/V4, Kit, Global Classes, Permissions)
- `detect-elementor-version` — Site-level + page-level V4-Capability-Detection
- `convert-page-v3-to-v4` — V3→V4 Page-Konvertierung via `V3_To_V4_Converter`

### Tests

- 39 Tests in `V3ToV4ConverterTest.php`
- 28 Tests in `ConversionAuditorTest.php`

---

## Version 1.2.0 — 2026-06-10

### Kit-Konvertierung (Phase 1–4)

`kit-convert-v3-to-v4` als vollständige 4-Phasen-Orchestrierung:
- Phase 1: V3-Farben → V4 Color Variables (skip/overwrite/rename Strategien)
- Phase 2: V3-Schriften → V4 Font- und Size-Variables (dedupliziert, semantische Benennung)
- Phase 3: V3-Typography-Presets → V4 Global Classes (mit `$$type`-Wrapping)
- Phase 4: Responsive Varianten (tablet/mobile) auf Global Classes

### V3_To_V4_Converter (Basis)

Erster Entwurf des Converters mit:
- Container-Mapping: `section`/`container` → `e-flexbox`, `column` → `e-div-block`
- Widget-Mapping: `heading`, `text-editor`, `button`, `image`, `spacer`, `divider`, `icon`
- Style-Migration: V3 settings → V4 styles mit `$$type`-Wrapping

---

## Version 1.1.0 — 2026-05-20

### WPCode-Integration

8 vollständige Abilities für WPCode-Management:
`wpcode-check-setup`, `list-wpcode-snippets`, `get-wpcode-snippet`, `create-wpcode-snippet`, `update-wpcode-snippet`, `set-wpcode-snippet-status`, `duplicate-wpcode-snippet`, `delete-wpcode-snippet`

`bypass_kses` mit `try/finally` korrekt implementiert.

### Elementor-Inject-Calibrated-Page

`elementor-inject-calibrated-page` — routet über `Elementor_Document_Saver::save_data()`, unterstützt `merge_by_id` (DFS-Rekursion)

---

## Version 1.0.0 — 2026-04-01

### Initial Release

**Plugin-Architektur:**
- Namespace `novamira-adrianv2/*` (ersetzt `novamira-extra`)
- 18 Sub-Domains in `includes/abilities/`
- 24 geteilte Helper-Klassen in `includes/helpers/`
- 9 SKILL.md-Dateien in `includes/skills/`

**Erste Ability-Domains:**
- `audit/*` — 6 Audit-Abilities
- `a11y/*` — 3 Accessibility-Abilities
- `design-audit/*` — 3 Design-Bewertungs-Abilities
- `design-utilities/*` — 12 Reparatur-Abilities
- `atomic/*` — 10 Atomic Widget Convenience-Abilities
- `global-classes/*` — 7 Global Class Management-Abilities
- `media/*` — 7 Media-Abilities
- `seo/*` — 4 SEO-Abilities
- `php-sandbox/*` — 6 Sandbox-Abilities
- `variables/*` — 3 Variable-Abilities

---

## Migration von novamira-extra

Das alte `novamira-extra` Plugin wurde vollständig durch `novamira-adrianv2` ersetzt.

| Alt (novamira-extra) | Neu (novamira-adrianv2) | Status |
|----------------------|------------------------|--------|
| `adrians-batch-create-variables` | `novamira-adrianv2/batch-create-variables` | ✅ Migriert |
| `adrians-batch-build-page` | `novamira-adrianv2/batch-build-page` | ✅ Migriert |
| `adrians-patch-element-styles` | `novamira-adrianv2/patch-element-styles` | ✅ Migriert |
| `adrians-setup-v4-foundation` | `novamira-adrianv2/setup-v4-foundation` | ✅ Migriert |

**⚠️ `novamira-extra` darf NIEMALS wieder installiert werden** — alle Funktionalität ist in `novamira-adrianv2`.

---

## Offene Punkte (als of 2026-06-21)

| # | Thema | Priorität | Status |
|---|-------|-----------|--------|
| 1 | `convert-site-v3-to-v4` Bulk-Ability | 🟡 Mittel | ❌ Offen |
| 2 | `design-token-remap` | 🟡 Niedrig | ❌ Offen |
| 3 | Check-Setup für Yoast/Rank Math/AIOSEO/WooCommerce | 🟠 Mittel | ❌ Offen |
| 4 | SAST-Integration (psalm --taint-analysis) | 🟠 Mittel | ❌ Offen |
| 5 | YouTube/Video Widget-Mapping in Converter | 🟡 Niedrig | ❌ Offen |
| 6 | Elementor 4.1.3 Atomic CSS Pipeline (Frontend) | 🔴 Hoch | ⚠️ Workaround nötig |

---

*Dokument erstellt 2026-06-21 nach vollständigem Codebase-Review und E2E-Test-Analyse.*
