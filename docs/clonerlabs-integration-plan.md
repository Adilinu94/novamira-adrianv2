# ClonerLabs Integration — Plan: `novamira-adrianv2/import-clonerlabs-page`

**Datum:** 2026-06-23
**Zielgruppe:** KI Agenten und Entwickler. Jede Ability ist eigenstaendig mit Datei-Pfaden, Klassen, Methoden, Input/Output Schemas und Implementierungsdetails.

---

# Teil 1: Core Ability — `novamira-adrianv2/import-clonerlabs-page`

## Datei-Struktur

```
includes/abilities/clonerlabs/
├── bootstrap.php                                          # Group-Loader (NEU)
├── class-import-clonerlabs-page.php                       # Main Ability (600-800 Zeilen, NEU)
├── class-clonerlabs-media-handler.php                     # Media Upload + URL-Ersetzung (~200 Zeilen, NEU)
└── class-clonerlabs-global-styles.php                     # Global Styles Extractor + Applier (~200 Zeilen, NEU)
```

## Geaenderte Dateien

- `includes/bootstrap.php` — Neuen Eintrag fuer `abilities/clonerlabs/bootstrap.php` hinzufuegen
- `includes/categories.php` — Neue Category `adrianv2-clonerlabs` registrieren

## Was ClonerLabs liefert (V3 Elementor JSON)

```json
{
  "content": [{ "elType": "container", "elements": [...] }],
  "page_settings": { "background_background": "classic", "background_color": "#FFFFFF", ... },
  "site_settings": { "container_width": { "unit": "px", "size": 1140 }, ... },
  "version": "0.4",
  "title": "geklonte-seite",
  "type": "container",
  "_manual_adjustments_needed": { "items": ["SVG icons need manual selection..."] },
  "media_library": {
    "svgs": [{ "dataUri": "data:image/svg+xml;base64,...", "filename": "icon-1.svg" }],
    "total": 5
  },
  "global_styles": {
    "colors": {
      "system": [{ "_id": "primary", "title": "Primary", "color": "#6C63FF" }],
      "custom": [{ "_id": "custom1", "title": "Custom 1", "color": "#FF6B35" }]
    },
    "typography": {
      "system": [{ "_id": "headings", "title": "Headings", "font_family": "Inter", "font_weight": "700" }]
    }
  }
}
```

### Widget-Typen die ClonerLabs produziert

`container` (section/div), `heading` (h1-h6), `text-editor` (p/span), `image` (img), `button` (button/a.btn), `icon` (svg/i), `icon-list` (ul/ol), `divider` (hr), `image-carousel` (swiper), `accordion` (FAQ).

Alle Widgets nutzen V3 Format (KEIN $$type System).

## Input Schema

```json
{
  "type": "object",
  "required": ["cloner_data"],
  "properties": {
    "cloner_data":       { "type": "object" },
    "target":            { "type": "string", "enum": ["v3", "v4"], "default": "v3" },
    "post_id":           { "type": "integer" },
    "title":             { "type": "string" },
    "slug":              { "type": "string" },
    "status":            { "type": "string", "enum": ["draft","publish","private"], "default": "draft" },
    "template":          { "type": "string", "default": "elementor_header_footer" },
    "upload_media":      { "type": "boolean", "default": true },
    "apply_global_styles": { "type": "boolean", "default": true },
    "v4_strategy":       { "type": "string", "enum": ["keep","skip","html"], "default": "keep" },
    "create_template":   { "type": "boolean", "default": false },
    "cleanup_styles":    { "type": "boolean", "default": true }
  }
}
```

## Pipeline (7 Phasen)

### Phase 1: VALIDATE

- `$data['content']` existiert und ist nicht-leeres Array
- Maximale Tiefe ≤ 15 (rekursiver Check)
- Keine doppelten IDs im Element Tree
- Extrahiere: `page_settings`, `site_settings`, `media_library`, `global_styles`, `_manual_adjustments_needed`
- Bei Fehlern: `\InvalidArgumentException`

### Phase 2: MEDIA (class-clonerlabs-media-handler.php)

`ClonerLabs_Media_Handler::process(array $media_library, array &$elements): array`

- Iteriere ueber `$media_library['svgs']`
- Jeder Eintrag: `dataUri` (base64) + `filename`
- Dekodiere dataUri zu temporaerer Datei
- `media_handle_sideload()` (WordPress Core)
- Sammle Mapping: `{old_data_uri -> new_attachment_url}`
- Zweiter Durchlauf: Ersetze Data-URIs in:
  - `settings['image']['url']`
  - `settings['html']`
  - `settings['svg']['url']`
- Gib Report: `['uploaded' => [...], 'replaced' => int]`

### Phase 3: V4 CONVERSION (nur target="v4")

- Extrahiere Global Colors aus `$globals['colors']`
- Baue `$variable_map`:
  ```
  ['primary' => ['id' => 'e-gv-primary', 'label' => 'Primary', 'type' => 'global-color-variable', 'value' => '#6C63FF']]
  ```
- Baue `$semantic_classes` (optional, kann leer sein)
- Rufe `V3_To_V4_Converter::convert_elements($elements, $strategy, $stats, $warnings, $variable_map, $semantic_classes)` auf
- WICHTIG: ClonerLabs exportiert Container mit `elType: 'container'` (kein section/column). Der Converter nutzt `convert_container()` dafuer.
- Gib: `['elements' => [...], 'stats' => [...], 'warnings' => [...]]`

### Phase 4: GLOBAL STYLES (class-clonerlabs-global-styles.php)

`ClonerLabs_Global_Styles::apply(array $globals): array`

- Hole aktive Kit-ID: `get_option('elementor_active_kit')`
- Hole: `get_post_meta($kit_id, '_elementor_page_settings', true)`
- Merge Colors: Fuer jeden Eintrag in `$globals['colors']['system']` + `['custom']`:
  - Existiert `_id` bereits? → Ueberschreibe `color`
  - Neu? → Fuege hinzu
- Gleiches fuer `$globals['typography']['system']` → `system_typography`
- `update_post_meta($kit_id, '_elementor_page_settings', $merged)`
- `delete_post_meta($kit_id, '_elementor_css')`
- Gib: `['colors_applied' => int, 'typography_applied' => int]`

### Phase 5: PAGE CREATION

- `$params['post_id'] > 0` → `get_post()` existiert? → optional `wp_update_post()`
- Sonst: `wp_insert_post(['post_type' => 'page', ...])`
- `update_post_meta($post_id, '_wp_page_template', $template)`
- `update_post_meta($post_id, '_elementor_edit_mode', 'builder')`
- `update_post_meta($post_id, '_elementor_version', ELEMENTOR_VERSION)`
- Style Cleanup (wenn `cleanup_styles=true`):
  - Padding/Margin mit 0 → entfernen
  - border_border '' → entfernen
  - background_color '' oder '#FFFFFF' / 'rgba(0,0,0,0)' → entfernen
  - typography_font_weight '400' → entfernen
- `wp_json_encode($elements, JSON_UNESCAPED_UNICODE)`
- `update_post_meta($post_id, '_elementor_data', wp_slash($encoded))`
- Page Settings: `update_post_meta($post_id, '_elementor_page_settings', $validated_data['page_settings'])`
- `Guards::invalidate_elementor_cache($post_id)`
- Gib: `['post_id' => int, 'permalink' => string, 'edit_url' => string, 'created_page' => bool]`

### Phase 6: TEMPLATE (optional)

- Delegiert an `template-manager::execute_create_template()`
- Extrahiert `_elementor_data` und `_elementor_page_settings` vom Post
- Erstellt `elementor_library` Eintrag, type 'page', status 'publish'

### Phase 7: REPORT

```json
{
  "success": true, "target": "v3", "post_id": 123,
  "permalink": "https://...", "edit_url": "https://.../post.php?post=123&action=elementor",
  "created_page": true, "template_id": null,
  "stats": {
    "total_elements": 42, "converted_to_v4": 0, "kept_v3": 42, "skipped": 0,
    "media_uploaded": 5, "data_uris_replaced": 3, "global_colors_applied": 12,
    "global_typography_applied": 6, "styles_cleaned": 28
  },
  "warnings": ["Icon widget kept as V3 - no V4 atomic equivalent"],
  "manual_adjustments": ["SVG icons need manual icon selection"],
  "summary": "Page 'meine-seite' (#123) created with 42 elements (V3). 5 media files uploaded."
}
```

## bootstrap.php

```php
add_action('wp_abilities_api_init', static function () {
    require_once __DIR__ . '/class-import-clonerlabs-page.php';
    require_once __DIR__ . '/class-clonerlabs-media-handler.php';
    require_once __DIR__ . '/class-clonerlabs-global-styles.php';
    \Novamira\AdrianV2\Abilities\ClonerLabs\Import_ClonerLabs_Page::register();
}, 20);
```

## Category (in categories.php)

```php
'adrianv2-clonerlabs' => array(
    'label'       => __('AdrianV2 - ClonerLabs', 'novamira-adrianv2'),
    'description' => __('Import ClonerLabs exports as Elementor V3/V4 pages.', 'novamira-adrianv2'),
    'meta'        => array('elementor_version' => 'mixed'),
),
```

## Bootstrap Eintrag (in bootstrap.php, nach bestehendem Schema)

```php
add_action('wp_abilities_api_init', static function () {
    try { require_once __DIR__ . '/abilities/clonerlabs/bootstrap.php'; }
    catch (\Throwable $e) { \Novamira\AdrianV2\Helpers\Diagnostics::record('clonerlabs', '?', $e); }
}, 20);
```

---

# Teil 2: Batch Import — `novamira-adrianv2/import-clonerlabs-batch`

**Datei:** `includes/abilities/clonerlabs/class-import-clonerlabs-batch.php` (~400 Zeilen, NEU)

**Zweck:** Mehrere ClonerLabs Exports als komplette Website importieren.

## Input Schema

```json
{
  "type": "object",
  "required": ["exports"],
  "properties": {
    "exports": {
      "type": "array",
      "items": {
        "type": "object",
        "required": ["cloner_data"],
        "properties": {
          "cloner_data": { "type": "object" },
          "page_title":  { "type": "string" },
          "page_slug":   { "type": "string" },
          "target":      { "type": "string", "enum": ["v3","v4"], "default": "v3" }
        }
      }
    },
    "status":    { "type": "string", "default": "draft" },
    "dry_run":   { "type": "boolean", "default": false },
    "apply_global_styles": { "type": "boolean", "default": true },
    "upload_media": { "type": "boolean", "default": true }
  }
}
```

## Output

```json
{
  "success": true, "dry_run": false, "pages_created": 5,
  "pages": [{ "post_id": 101, "title": "Home", "edit_url": "..." }],
  "errors": [{ "index": 2, "title": "Contact", "error": "Invalid element tree" }],
  "summary": "5 pages created, 1 error. 23 media files uploaded."
}
```

## Implementierung

- Basiert auf `import-template-kit` Rollback-Architektur
- `Kit_Rollback::create_snapshot()` vor dem Import
- `Kit_Rollback::record_posts()` nach jedem Page-Creation
- Jeder Export durchlaeuft Phasen 1-5 aus Teil 1
- Bei `dry_run: true`: nur validieren, nicht schreiben
- Errors sammeln, nicht abbrechen (graceful degradation)

---

# Teil 3: RAW HTML Direktimport — `novamira-adrianv2/convert-html-to-elementor`

**Datei:** `includes/abilities/clonerlabs/class-convert-html-to-elementor.php` (~300 Zeilen, NEU)

**Zweck:** Rohes HTML annehmen, per `html-to-elementor-widget-plan` analysieren, V3/V4 Seite bauen.

## Input Schema

```json
{
  "type": "object",
  "required": ["html"],
  "properties": {
    "html":          { "type": "string" },
    "target":        { "type": "string", "enum": ["v3","v4"], "default": "v3" },
    "title":         { "type": "string" },
    "status":        { "type": "string", "default": "draft" },
    "page_css":      { "type": "string" },
    "page_js":       { "type": "string" },
    "upload_media":  { "type": "boolean", "default": true },
    "max_nodes":     { "type": "integer", "default": 250 }
  }
}
```

## Pipeline

1. **HTML parsen** — `DOMDocument::loadHTML()` mit `LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD`, Body extrahieren

2. **html-to-elementor-widget-plan Analyse** — Rufe `Html_To_Elementor_Widget_Plan::execute()` auf. Extrahiere:
   - `stats`: tag_counts, total_elements
   - `tree`: Conversion-Tree mit selector_hint, elementor_target, native, confidence
   - `css_inventory` / `js_inventory`
   - `recommendations`

3. **Widget Tree bauen** — Aus `tree` einen `batch-build-page`-kompatiblen Element Tree:
   - `container`-Nodes → `elType: 'container'` (V3) oder `e-flexbox` (V4)
   - `widget`-Nodes → `elType: 'widget', widgetType: target`
   - confidence < 0.7 → HTML Widget statt native
   - Container-Struktur automatisch generieren (flexbox nesting)

4. **Seite anlegen** — Gleiche Logik wie Phase 5 aus Teil 1. page_css + page_js aus CSS/JS-Inventar uebernehmen.

## Output

```json
{
  "success": true, "target": "v3", "post_id": 123,
  "stats": { "html_elements_parsed": 85, "native_widgets": 62, "html_widgets": 12, "css_blocks_moved": 3, "js_blocks_moved": 1, "media_uploaded": 8 },
  "native_widget_ratio": 0.84,
  "summary": "Page created from HTML with 62 native widgets (84% coverage)."
}
```

---

# Teil 4: Style Minifier (Helper)

**Datei:** `includes/abilities/clonerlabs/class-clonerlabs-style-minifier.php` (~200 Zeilen, NEU)

**Zweck:** ClonerLabs exportiert zu viele computed styles. Bereinigung.

**Keine eigene Ability** — wird automatisch in Phase 5 aufgerufen wenn `cleanup_styles: true`.

## Bereinigungsregeln

```php
ClonerLabs_Style_Minifier::clean(array $elements): array
```

Rekursiver Walk. Entfernt:

- `padding: { top:0, right:0, bottom:0, left:0 }`
- `margin: { top:0, right:0, bottom:0, left:0 }`
- `gap: { size: 0 }`
- `border_border: ''` + zugehoerige `border_width`
- `border_radius: { top:0, ... }` wenn alle 0
- `box_shadow_box_shadow_type: ''`
- `background_background: ''` oder `'classic'` ohne `background_color`
- `background_color: ''` oder `'#FFFFFF'` oder `'rgba(0,0,0,0)'`
- `typography_font_size: { size: '' }`
- `typography_font_weight: '400'` oder `''`
- `typography_font_family: ''`

**NICHT entfernen:** `text_color`, `color` (Farben sind oft beabsichtigt).

---

# Teil 5: Library Import — `novamira-adrianv2/import-clonerlabs-library`

**Datei:** `includes/abilities/clonerlabs/class-import-clonerlabs-library.php` (~300 Zeilen, NEU)

**Zweck:** Eine komplette ClonerLabs Saved-Sections-Library importieren.

## ClonerLabs Library Format

```json
{
  "version": "1.0", "source": "ClonerLabs", "sections": [
    {
      "id": "sec_a1b2c3", "name": "Hero Section",
      "mappedElements": [
        { "id": "ec_abc123", "widget": "container", "widgetName": "Section Container", "depth": 0, "styles": {...}, "children": [...] }
      ],
      "widgetCount": 12
    }
  ]
}
```

## Input Schema

```json
{
  "type": "object",
  "required": ["library"],
  "properties": {
    "library":       { "type": "object" },
    "target":        { "type": "string", "enum": ["v3","v4"], "default": "v3" },
    "status":        { "type": "string", "default": "draft" },
    "prefix":        { "type": "string", "default": "[Cloned] " },
    "create_pages":  { "type": "boolean", "default": false },
    "dry_run":       { "type": "boolean", "default": false }
  }
}
```

## Pipeline

1. Library validieren (version, sections)
2. Jede Section: `mappedElements` → V3/V4 Widget Tree
3. Wenn `create_pages: false`: `template-manager::execute_create_template()` mit type: 'section'
4. Wenn `create_pages: true`: Phase 5 aus Teil 1

---

# Teil 6: Auto-Fix — `novamira-adrianv2/repair-clonerlabs-page`

**Datei:** `includes/abilities/clonerlabs/class-repair-clonerlabs-page.php` (~200 Zeilen, NEU)

**Zweck:** Broken-Layout-Faelle nach Import reparieren.

## Input Schema

```json
{
  "type": "object",
  "required": ["page_id"],
  "properties": {
    "page_id": { "type": "integer" },
    "dry_run": { "type": "boolean", "default": false },
    "fixes": {
      "type": "array",
      "items": {
        "type": "string",
        "enum": ["empty_containers", "single_child_unwrap", "depth_normalize", "text_overlap", "class_dedup", "style_null_remove", "image_fix"]
      }
    }
  }
}
```

## Pipeline

1. Hole `_elementor_data` von `$page_id`
2. Kopie fuer Dry-Run
3. Fuehre jede Reparatur aus
4. Speichern (wenn nicht Dry-Run)
5. Cache invalidieren

---

# Implementierungsreihenfolge

1. **Teil 1 (Core)** — `novamira-adrianv2/import-clonerlabs-page` — zuerst V3 only (schnellster Weg zu funktionierendem Import), dann Media Handling + Global Styles, dann V4 Conversion
2. **Teil 4 (Style Minifier)** — Direkt in Phase 5 integrieren
3. **Teil 6 (Auto-Fix)** — Nach Import automatisch laufen lassen
4. **Teil 3 (RAW HTML)** — Unabhaengig von ClonerLabs, aber simpler zu implementieren als Batch/Library
5. **Teil 2 (Batch) + Teil 5 (Library)** — Power-User Features, bauen auf Teil 1 auf

---

# Bekannte Einschraenkungen

1. ClonerLabs exportiert V3 Format mit `elType: 'container'` (kein section/column). `V3_To_V4_Converter` akzeptiert das via `convert_container()`.
2. `WIDGET_MAP['icon'] => null` — Icon hat kein V4 Aequivalent. ClonerLabs exportiert oft SVG data-URIs → koennten manuell als e-svg gemapped werden.
3. `_manual_adjustments_needed` muss immer in den Output.
4. `cleanup_styles` kann beabsichtigte Default-Werte entfernen. Nur Computed-Style-Artefakte bereinigen, nicht User-Settings.
5. `Kit_Rollback` (CPT `novamira_rollback`) kann fuer Batch-Imports wiederverwendet werden.
