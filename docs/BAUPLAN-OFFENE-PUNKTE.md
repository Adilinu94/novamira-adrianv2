# Bauplan: Offene Punkte

> **Stand:** 2026-06-21  
> **Scope:** Drei absichtlich nicht implementierte Punkte aus ADRIANV2-ANALYSE-UND-V3V4-PLAN.md
> und E2E-TEST-ANALYSE.md — mit je einem vollständigen Implementierungsplan.

---

## Punkt 1 — `design-token-remap` (XL-Aufwand, mittlerer Sofort-Wert)

### Problem

Nach einem Kit-Rebuild oder Brand-Update ändern sich die `e-gv-*` IDs der Global
Variables. Jede Seite enthält in `element.styles` Referenzen wie:

```json
{ "$$type": "global-color-variable", "value": "e-gv-bebd7fa" }
```

Wenn `e-gv-bebd7fa` nach dem Rebuild zu `e-gv-f43276f` wird, zeigt die Seite die falsche
Farbe (oder keine). Derzeit gibt es keine Ability, die das site-weit korrigiert.

### Was zu remappen ist

| Meta-Key | Inhalt | Benötigt Remap? |
|---|---|---|
| `_elementor_data` (pro Post) | V4 Styles mit `global-color-variable` Refs | ✅ |
| `_elementor_global_variables` (Kit) | Definiert die GVs — enthält selbst keine Refs | ❌ |
| `_elementor_global_classes` (Kit CPT / Kit Meta) | Variant-Props in Global Classes | ✅ |
| `_elementor_page_settings` (Kit) | V4-Typography-Presets, könnten GV-Refs enthalten | ⚠️ prüfen |

### Scope der Suche im Element Tree

Rekursive Suche in jedem Element nach dem Muster:

```json
{ "$$type": "global-color-variable", "value": "<OLD_ID>" }
```

Das `value`-Feld enthält entweder:
- `"e-gv-bebd7fa"` (reine ID) → replace mit `"e-gv-NEWID"`
- `"var(--e-gv-bebd7fa)"` (CSS-var-String) → replace mit `"var(--e-gv-NEWID)"`

Nicht anfassen:
- `{ "$$type": "global-class", "value": "gc-..." }` — das sind Class-IDs, anderer Namespace
- V3-Referenzen `{ "__globals__": { ... } }` — sollten nach convert-page-v3-to-v4 nicht mehr existieren

### Input-Schema

```json
{
  "remap_map": {
    "e-gv-bebd7fa": "e-gv-f43276f",
    "e-gv-154a25a": "e-gv-9901abc"
  },
  "dry_run": true,
  "scope": "both",
  "post_ids": null,
  "limit": 50,
  "offset": 0
}
```

| Parameter | Typ | Default | Beschreibung |
|---|---|---|---|
| `remap_map` | object | — | **Pflicht.** `{ old_id: new_id }` — ohne `var(--...)` Wrapper |
| `dry_run` | boolean | `true` | Nur zählen, nichts schreiben |
| `scope` | string | `"both"` | `"pages"` / `"kit"` / `"both"` |
| `post_ids` | int[] | `null` | Null = alle Elementor-Posts autodiscovery |
| `limit` | int | `50` | Max Posts pro Aufruf |
| `offset` | int | `0` | Für Paginierung |

### Output-Schema

```json
{
  "success": true,
  "dry_run": true,
  "scope": "both",
  "stats": {
    "posts_scanned": 42,
    "posts_modified": 8,
    "refs_replaced": 34,
    "kit_refs_replaced": 5
  },
  "per_page": [
    { "post_id": 123, "title": "Über uns", "refs_replaced": 7 }
  ],
  "kit": {
    "refs_replaced": 5,
    "classes_touched": ["gc-abc123"]
  }
}
```

### Klassenstruktur

**Neue Datei:** `includes/abilities/elementor/class-design-token-remap.php`

```
class Design_Token_Remap {

    public static function register(): void
        // wp_register_ability('novamira-adrianv2/design-token-remap', [...])

    public static function execute($input = null): array
        // 1. Input validieren (remap_map darf nicht leer sein)
        // 2. scope prüfen
        // 3. Posts autodiscovery oder post_ids nutzen
        // 4. Paginierung anwenden (limit/offset)
        // 5. Jeden Post durch remap_post() jagen
        // 6. Wenn scope kit: remap_kit() aufrufen
        // 7. Aggregierte Stats zurückgeben

    private static function discover_posts(): array
        // Wie discover_v3_pages() in convert-site-v3-to-v4,
        // aber OHNE V3-Filter:
        //   SELECT DISTINCT pm.post_id FROM wp_postmeta pm
        //   INNER JOIN wp_posts p ON p.ID = pm.post_id
        //   WHERE pm.meta_key = '_elementor_data'
        //   AND p.post_status NOT IN ('trash', 'auto-draft')

    private static function remap_post(int $post_id, array $remap_map, bool $dry_run): array
        // 1. Raw SQL lesen (wie in convert-site-v3-to-v4::read_raw_post_data)
        // 2. JSON-decode
        // 3. walk_tree($tree, $remap_map, $count) aufrufen
        // 4. Wenn $count > 0 && !dry_run: Elementor_Document_Saver::save_data()
        // 5. Rückgabe: ['post_id' => N, 'refs_replaced' => N]

    private static function remap_kit(int $kit_id, array $remap_map, bool $dry_run): array
        // 1. _elementor_global_classes_order lesen (Liste aller Class-IDs)
        // 2. Pro Class-ID: _elementor_global_class_{id} meta lesen (Variant-Props)
        // 3. remap_variants($data, $remap_map) aufrufen
        // 4. Wenn Änderungen && !dry_run: update_post_meta($kit_id, ...)
        // 5. Rückgabe: ['refs_replaced' => N, 'classes_touched' => [...]]

    private static function walk_tree(array &$tree, array $remap_map, int &$count): void
        // Rekursiver Descent durch $tree['elements']
        // Pro Element: remap_styles($element['styles'], ...)
        // Dann rekursiv in $element['elements']

    private static function remap_styles(array &$styles, array $remap_map, int &$count): void
        // styles = { style_id: { type, id, variants: [ {props: {...}, meta: {...}} ] } }
        // Pro Variant, pro Prop: remap_prop($prop, $remap_map, $count)

    private static function remap_prop(mixed &$value, array $remap_map, int &$count): void
        // Kernlogik:
        // if is_array($value) && ($value['$$type'] ?? '') === 'global-color-variable':
        //   $old = $value['value'] ?? ''
        //   Strip 'var(--' prefix und ')' suffix falls vorhanden
        //   lookup in remap_map
        //   Wenn gefunden: ersetzen (Format beibehalten: mit/ohne var(--...))
        //   $count++

    private static function normalize_id(string $value): string
        // 'var(--e-gv-abc)' → 'e-gv-abc'
        // 'e-gv-abc' → 'e-gv-abc'
}
```

### Bootstrap-Eintrag

In `includes/abilities/elementor/bootstrap.php`:

```php
require_once __DIR__ . '/class-design-token-remap.php';
Design_Token_Remap::register();
```

### Tests

Neue Datei `tests/DesignTokenRemapTest.php`:

| Test | Was geprüft wird |
|---|---|
| `test_remap_single_color_in_style_prop` | `global-color-variable` mit reiner ID wird ersetzt |
| `test_remap_css_var_format` | `var(--e-gv-old)` wird zu `var(--e-gv-new)` |
| `test_no_match_no_change` | ID nicht in remap_map → Tree unverändert |
| `test_nested_elements_remapped` | Verschachtelter Tree → alle Treffer |
| `test_dry_run_no_write` | dry_run=true: count korrekt, kein save_data() |
| `test_global_class_not_touched` | `{$$type:"global-class", value:"gc-..."}` bleibt unberührt |
| `test_kit_remap` | Kit-Meta: Variant-Props werden korrekt ersetzt |
| `test_multiple_ids_in_remap_map` | Zwei verschiedene Farbwerte in einem Schritt remappen |

### Aufwandsschätzung

| Schritt | Aufwand |
|---|---|
| `remap_prop()` + `normalize_id()` | 30 min |
| `remap_styles()` + `walk_tree()` | 1 h |
| `remap_post()` inkl. SQL-Read/Save-Integration | 1 h |
| `remap_kit()` (Kit-Meta-Struktur verstehen + schreiben) | 2 h |
| `discover_posts()` + execute() + register() + Schema | 1 h |
| Tests (8 Stück) | 2 h |
| **Gesamt** | **~7–8 h** |

### Bekannte Stolpersteine

**Stolperstein 1 — Kit-Meta-Format:**  
`_elementor_global_class_{class_id}` ist ein per-Class-Meta-Key auf dem Kit-Post
(nicht serialisiert, sondern JSON-String). Die Struktur:

```json
{
  "type": "class",
  "id": "gc-abc123",
  "variants": [
    {
      "meta": { "state": "default", "breakpoint": "desktop" },
      "props": {
        "background-color": { "$$type": "global-color-variable", "value": "e-gv-OLD" }
      }
    }
  ]
}
```

**Stolperstein 2 — Kit-Save ohne wp_slash-Korrumption:**  
`update_post_meta()` macht intern `wp_slash()`. JSON mit Hex-Farben wird dadurch nicht
korrumpiert (kein Backslash-Problem bei `#RRGGBB`), ABER JSON mit eingebetteten
Anführungszeichen (z.B. in CSS-Strings) kann korrupt werden. Lösung:
`update_post_meta($kit_id, $key, wp_slash(wp_json_encode($data)))`.

**Stolperstein 3 — E2E-Issue 3.5 (ID-Inkonsistenz):**  
Wenn `kit-convert-v3-to-v4` mehrfach aufgerufen wurde, gibt es möglicherweise mehrere
verschiedene IDs für dieselbe Farbe. Das `remap_map` muss dann ALLE alten IDs als Keys
enthalten. Der Caller ist dafür verantwortlich, das richtige `remap_map` zu übergeben.

---

## Punkt 2 — SAST: `psalm --taint-analysis`

### Aktueller Stand

`psalm.xml` existiert mit Basic-Config. Taint-Analyse ist NICHT aktiviert:
- Kein `runTaintAnalysis="true"` in `psalm.xml`
- `.github/workflows/psalm.yml` ruft Psalm ohne `--taint-analysis` auf
- Keine `@psalm-taint-*` Docblocks im Code

### Warum separater Schritt

Psalm Taint Analysis erzeugt bei WordPress-Plugins grundsätzlich viele False Positives:
- `get_post_meta()` ist für Psalm eine Taint-Quelle (Datenbankinhalt = potenziell User-Controlled)
- WP-Sanitization-Funktionen (`absint()`, `sanitize_text_field()`) müssen explizit als
  Taint-Escapes annotiert werden, sonst flaggt Psalm jeden sanitisierten Wert als tainted
- Intentionale Injection-Surfaces (PHP-Sandbox `eval()`, WPCode `bypass_kses`) müssen
  explizit als "this is by design" markiert werden

Deshalb: Eigener Workflow, eigene psalm-Konfiguration, getrennt vom normalen Psalm-Lauf.

### Schritt-für-Schritt

#### Schritt 1: `psalm-taint.xml` erstellen

Neue Datei `psalm-taint.xml` — erweitert `psalm.xml` um Taint-Mode:

```xml
<?xml version="1.0"?>
<psalm
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns="https://getpsalm.org/schema/config"
    xsi:schemaLocation="https://getpsalm.org/schema/config vendor/vimeo/psalm/config.xsd"

    errorLevel="4"
    runTaintAnalysis="true"

    resolveFromConfigFile="true"
    cacheDirectory=".psalm-cache-taint"
>
    <projectFiles>
        <directory name="includes/" />
        <file name="novamira-adrianv2.php" />
        <ignoreFiles allowMissingFiles="true">
            <directory name=".psalm-cache-taint/" />
            <directory name="vendor/" />
        </ignoreFiles>
    </projectFiles>

    <stubs>
        <file name="vendor/php-stubs/wordpress-stubs/wordpress-stubs.php" />
    </stubs>

    <!-- Taint: Suppresse bekannte False Positives in WordPress-Kontext -->
    <issueHandlers>
        <UndefinedGlobalVariable errorLevel="suppress">
            <errorLevel type="suppress">
                <referencedVariable name="$wpdb" />
            </errorLevel>
        </UndefinedGlobalVariable>
        <UndefinedFunction>
            <errorLevel type="suppress">
                <!-- alle WP-Funktionen wie in psalm.xml -->
                <referencedFunction name="wp_register_ability" />
                <referencedFunction name="add_action" />
                <!-- [...] -->
            </errorLevel>
        </UndefinedFunction>
        <UndefinedConstant errorLevel="suppress" />
    </issueHandlers>
</psalm>
```

**Warum nicht `runTaintAnalysis="true"` in psalm.xml?**  
Der normale Psalm-Lauf (CI-Check) läuft in <30 Sekunden. Taint-Analyse dauert 5–10×
länger. Separate Configs = normale CI bleibt schnell.

#### Schritt 2: GitHub Actions Workflow

Neue Datei `.github/workflows/psalm-taint.yml`:

```yaml
name: Psalm Taint Analysis (SAST)

on:
  push:
    branches: [ master, main ]
  schedule:
    # Wöchentlich Montags 06:00 UTC — nicht bei jedem Push
    - cron: '0 6 * * 1'
  workflow_dispatch:  # Manuell auslösbar

jobs:
  psalm-taint:
    runs-on: ubuntu-latest
    name: Psalm Taint Analysis
    # Darf fehlschlagen — als Info, nicht als Blocker
    continue-on-error: true

    steps:
      - uses: actions/checkout@v4

      - name: Set up PHP 8.2
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          extensions: mbstring, dom

      - name: Install dependencies
        run: composer install --prefer-dist --no-progress --no-interaction

      - name: Run Psalm Taint Analysis
        run: |
          ./vendor/bin/psalm \
            --config=psalm-taint.xml \
            --taint-analysis \
            --output-format=github \
            --no-progress \
            --report=psalm-taint-report.sarif \
            || true

      - name: Upload SARIF to GitHub Security
        uses: github/codeql-action/upload-sarif@v3
        if: always()
        continue-on-error: true
        with:
          sarif_file: psalm-taint-report.sarif
          category: psalm-taint
```

#### Schritt 3: Docblock-Annotierungen

##### 3a — PHP-Sandbox (intentionale `eval()`-Surface, B8)

Datei `includes/abilities/php-sandbox/class-php-snippets.php`:

Die `php_code`-Parameter-Verarbeitung muss als "taint intentionally escaped here"
annotiert werden, sonst flaggt Psalm jede Stelle, die `$input['php_code']` nutzt.

```php
/**
 * Execute PHP snippet from sandbox.
 *
 * The php_code content is an intentional arbitrary-code-execution surface.
 * Access is gated by: (a) current_user_can('manage_options') and
 * (b) snippet must exist in wp_options sandbox store before execution.
 * Taint is intentionally released here — this is the design.
 *
 * @psalm-taint-escape html $input
 * @psalm-taint-escape sql  $input
 */
private static function execute_snippet( array $input ): mixed {
    // ...
}
```

##### 3b — WPCode KSES-Bypass (B9, `add-custom-js`)

Datei `includes/helpers/class-wpcode-kses-bypass.php`:

```php
/**
 * Temporarily allow unfiltered HTML to write WPCode snippets.
 * This is intentional: WPCode manages its own sanitization pipeline.
 * The caller must validate the snippet content independently.
 *
 * @psalm-taint-escape html $callback
 */
public static function with_bypass( callable $callback ): mixed {
    // ...
}
```

##### 3c — `Elementor_Document_Saver` (Taint-Escape für sanitisierten Elementor-Daten-Save)

Datei `includes/helpers/class-elementor-document-saver.php`:

```php
/**
 * Save element data to post meta.
 * Input $data comes from the V3→V4 Converter output (pure PHP arrays,
 * never raw user strings). wp_slash() is applied before storage.
 *
 * @psalm-taint-escape html $data
 * @psalm-taint-escape sql  $data
 */
public static function save_data( int $post_id, array $data ): bool {
    // ...
}
```

#### Schritt 4: Erwartete Taint-Findings (und wie sie zu behandeln sind)

| Finding | Datei | Typ | Behandlung |
|---|---|---|---|
| `eval($code)` mit `$code` aus `$input[]` | `class-php-snippets.php` | intentional RCE | `@psalm-taint-escape all` + Kommentar |
| WPCode JS-Content aus `$input['code']` | `class-wpcode-snippets.php` | intentional XSS-Surface | `@psalm-taint-escape html` + WPCode-Auth-Gate dokumentieren |
| `$wpdb->insert()` mit `$data['post_id']` | `class-duplicate-page.php` | SQL | Sollte sauber sein (absint() wird genutzt) — Psalm benötigt Annotation |
| `echo wp_json_encode($output)` | diverse abilities | HTML Sink | `wp_json_encode` escapt JSON-Sonderzeichen, ist sicher — `@psalm-taint-escape html` auf Helper |
| `get_post_meta($post_id, '_elementor_data')` | diverse | DB Source | Wird nach `json_decode()` als Array verarbeitet, nie direkt als HTML ausgegeben — Pfad prüfen |

#### Schritt 5: Bekannte echte Vulnerabilities zu fixen

**B8 — PHP-Sandbox ohne Rate Limiting:**  
`execute-php` (Core) + PHP-Sandbox (AdrianV2) führen beliebigen PHP-Code aus.
Ist by-design (MCP-Surface), aber:
- Checken: Ist `current_user_can('manage_options')` überall gesetzt? → Ja, via `wp_register_ability` + capability gate
- Checken: Kann ein Nicht-Admin über MCP `php-sandbox/execute-snippet` aufrufen? → Abhängig von MCP-Adapter Auth

**B9 — XSS via `add-custom-js`:**  
JS-Content wird via `WPCode_Snippet::save()` gespeichert. WPCode hat eigene
Sanitization. Das Taint-Finding ist: User-String → WPCode-DB. Das ist by-design,
weil der Ability-Aufrufer ein Admin ist. Die Annotation macht das explizit.

### Aufwandsschätzung

| Schritt | Aufwand |
|---|---|
| `psalm-taint.xml` erstellen | 30 min |
| `.github/workflows/psalm-taint.yml` | 30 min |
| Docblock-Annotierungen (geschätzt 10–15 Stellen) | 2 h |
| Taint-Lauf lokal ausführen + Findings triagen | 1 h |
| Echte Findings fixen (optimistisch, da Code gut sanitisiert) | 1–3 h |
| **Gesamt** | **~5–7 h** |

### Hinweis zu Psalm 5.x Taint-API

Psalm 5.x hat die XML-basierten `<safeFunction>` und `<sinkFunction>` entfernt.
Alle Taint-Klassifizierungen müssen jetzt als Docblock-Annotierungen direkt
in den PHP-Dateien stehen (wie in Schritt 3 gezeigt). Das psalm.xml-Kommentar
unter `<!-- Taint analysis ... -->` dokumentiert das bereits.

---

## Punkt 3 — Elementor 4.1.3 Atomic CSS Pipeline

### Was nicht fixbar ist (Elementor-seitig)

Wie in `docs/atomic-css-pipeline.md` und `E2E-TEST-ANALYSE.md` (Sektion 8+10) detailliert
dokumentiert:

- **`elementor/atomic-widgets/styles/register`** wird im Frontend-Request nicht gefeuert
- **CSS-Layout auf `inline`** blockiert den CSS_Files_Manager
- **Atomare Elemente** (`e-flexbox`, `e-heading`, etc.) können in Elementor 4.1.3 als
  beta/nicht-vollständig-registriert behandelt werden → `get_element_instance()` → null

Diese Breakpoints liegen vollständig im Elementor-Core. Kein Plugin-Code kann
`do_action('elementor/atomic-widgets/styles/register', ...)` korrekt simulieren,
ohne in interne Elementor-APIs einzugreifen, die undokumentiert und zwischen
Releases instabil sind.

### Was der Plugin tun KANN

Workaround, der stabil in 4.1.3 funktioniert und bei Elementor 4.2+ (vollständige
Atomic Pipeline) deaktiviert werden kann:

**Klasse:** `Local_Styles_Renderer`  
**Datei:** `includes/helpers/class-local-styles-renderer.php`

```
class Local_Styles_Renderer {

    public static function init(): void
        // Hooks registrieren:
        // add_action('wp_head', [__CLASS__, 'render_for_current_post'], 100)
        // NUR wenn Elementor Frontend aktiv ist

    public static function render_for_current_post(): void
        // 1. get_the_ID() — nur auf Singular-Posts
        // 2. Prüfen: Ist Elementor auf dieser Seite aktiv? (get_post_meta($id, '_elementor_edit_mode'))
        // 3. Prüfen: Läuft bereits die native Atomic CSS Pipeline?
        //    → Wenn ja (Elementor 4.2+), eigene Ausgabe überspringen
        // 4. render_post($post_id) aufrufen

    public static function render_post(int $post_id): void
        // 1. Raw _elementor_data lesen (SQL, kein wp_unslash)
        // 2. JSON decode
        // 3. $styles_map = collect_styles($tree)
        // 4. $global_class_css = render_global_classes() (Optional, Sektion unten)
        // 5. $local_css = render_styles_map($styles_map)
        // 6. echo '<style id="novamira-atomic-styles">' . $local_css . '</style>'

    private static function collect_styles(array $tree): array
        // Rekursiver Descent
        // Pro Element: Wenn $element['styles'] vorhanden → in $map mergen
        // Wenn $element['elements'] vorhanden → rekursiv
        // Rückgabe: { style_id: { variants: [...] } }

    private static function render_styles_map(array $styles_map): string
        // Pro Style-ID: render_style_block($style_id, $style_data)
        // Konkateniert alle Blöcke

    private static function render_style_block(string $style_id, array $style_data): string
        // Pro Variant in $style_data['variants']:
        //   $meta = $variant['meta'] (breakpoint, state)
        //   $props = $variant['props']
        //   $css_props = render_props($props) → "background-color:#00EBAF;font-size:16px;"
        //   Selector: ".{$style_id}" (der Converter schreibt style_id als CSS-Klassen-Name)
        //   Breakpoint wrappen: desktop → keine media query; tablet → max-width:1024px; mobile → max-width:767px
        // Ergebnis: ".abc-def { background-color: #00EBAF; }\n@media (...) { ... }"

    private static function render_props(array $props): string
        // Pro CSS-Property-Name → Wert:
        //   prop_to_css($prop_name, $prop_value)

    private static function prop_to_css(string $name, mixed $value): string
        // { $$type: "color", value: "#HEX" } → "#HEX"
        // { $$type: "global-color-variable", value: "e-gv-abc" } → "var(--e-gv-abc)"
        // { $$type: "size", value: { size: 16, unit: "px" } } → "16px"
        // { $$type: "string", value: "..." } → "..."
        // { $$type: "dimensions", value: { block-start: "1rem", ... } } → "1rem 1rem 1rem 1rem"
        // Unbekannter $$type → leerer String (überspringen)

    // OPTIONAL: Global Classes aus Kit-Meta direkt rendern
    private static function render_global_classes(): string
        // 1. Kit-Post-ID via get_option('elementor_active_kit')
        // 2. _elementor_global_classes_order lesen (Liste der Class-IDs)
        // 3. Pro Class-ID:
        //    a. _elementor_global_class_{id} meta lesen
        //    b. Label aus _elementor_global_classes_labels[class_id]
        //    c. render_style_block($label, $class_data) → CSS
        // 4. Alle Blöcke konkatenieren
        // ACHTUNG: Diese Methode rendert ALLE globalen Klassen auf JEDER Seite.
        //          Besser: Nur die Klassen rendern, die auf dieser Seite via
        //          _elementor_used_global_class Meta referenziert werden.
}
```

### CSS-Ausgabeformat

Das Rendering basiert auf Element-Data-Attributen ODER Style-IDs als CSS-Klassen.
Der Converter schreibt `settings.classes.value = [{ id: "style-abc-def" }]` in die Elemente.
Elementor 4.x setzt `class="e-abc-def-123456 ..."` im Frontend-HTML.
Der CSS-Selektor muss zu diesem Klassen-Muster passen.

**Selektor-Strategie:**

```
Style-ID: "e7fb18b-4f43c3e" (aus element.styles)
→ CSS-Klasse im HTML: class="e-e7fb18b-4f43c3e ..."
→ Selektor: ".e-e7fb18b-4f43c3e"
```

Das `e-` Prefix kommt aus Elementors eigener Klassen-Generierung. Unser Renderer
muss dasselbe Prefix hinzufügen.

### Deaktivierungsgate für Elementor 4.2+

```php
public static function render_for_current_post(): void {
    // Wenn Elementors native Atomic CSS Pipeline aktiv ist, nicht eingreifen.
    // Signal: CSS-Dateien existieren in uploads/elementor/css/
    $css_dir = WP_CONTENT_DIR . '/uploads/elementor/css/';
    if ( is_dir( $css_dir ) && count( glob( $css_dir . 'post-*.css' ) ) > 0 ) {
        return; // native Pipeline läuft → kein Workaround nötig
    }
    // ... weiter
}
```

Oder eleganter: Auf das Event `elementor/atomic-widgets/styles/register` prüfen,
ob es Listener hat:

```php
if ( has_action( 'elementor/atomic-widgets/styles/register' ) ) {
    return; // Elementor hat eigene Handler → Workaround überspringen
}
```

### Integration

**Wo registrieren:** `includes/helpers/bootstrap.php`

```php
require_once __DIR__ . '/class-local-styles-renderer.php';
if ( class_exists( 'Elementor\Plugin' ) ) {
    Local_Styles_Renderer::init();
}
```

### Tests

Neue Datei `tests/LocalStylesRendererTest.php`:

| Test | Was geprüft wird |
|---|---|
| `test_prop_color` | `{$$type:color}` → `#HEX` |
| `test_prop_global_color_variable` | `{$$type:global-color-variable}` → `var(--e-gv-...)` |
| `test_prop_size` | `{$$type:size}` → `16px` |
| `test_prop_dimensions` | `{$$type:dimensions}` → `1rem 2rem 1rem 2rem` |
| `test_render_style_block_desktop` | Desktop-Variant → kein media query |
| `test_render_style_block_tablet` | Tablet-Variant → `@media (max-width: 1024px)` |
| `test_collect_styles_nested` | Styles aus verschachteltem Baum werden gesammelt |
| `test_empty_styles_no_output` | Kein `<style>` Block wenn keine Styles |

### Aufwandsschätzung

| Schritt | Aufwand |
|---|---|
| `prop_to_css()` + alle $$type-Cases | 1 h |
| `render_props()` + `render_style_block()` | 1 h |
| `collect_styles()` (rekursiver Descent) | 1 h |
| `render_post()` + `render_for_current_post()` inkl. Gates | 1 h |
| `render_global_classes()` (optional, Kit-seitig) | 2 h |
| Bootstrap-Integration + init() | 30 min |
| Tests (8 Stück) | 2 h |
| **Gesamt (ohne render_global_classes)** | **~6–7 h** |
| **Gesamt (mit render_global_classes)** | **~8–9 h** |

### Wichtiger Hinweis zum Selector-Muster

Das Selector-Muster (`e-{style_id}`) muss gegen eine aktive Elementor 4.1.3
Installation verifiziert werden, bevor die Klasse gebaut wird. Konkret:
In einer konvertierten Seite im Browser-Devtools prüfen, welche CSS-Klasse
auf einem `e-heading` Element landet (`class="..."`). Das ist der Ground-Truth-Selektor.

---

## Zusammenfassung: Reihenfolgeempfehlung

| Priorität | Item | Aufwand | Unblock |
|---|---|---|---|
| **1** | Atomic CSS Pipeline — `Local_Styles_Renderer` | 6–9 h | Konvertierte Seiten sind im Frontend sichtbar |
| **2** | `design-token-remap` | 7–8 h | Brand-Updates ohne manuelle Seiten-Edits |
| **3** | SAST `psalm --taint-analysis` | 5–7 h | Sicherheits-Compliance-Nachweis |

Die Atomic CSS Pipeline hat höchste Priorität, weil sie aktuell verhindert, dass
konvertierte V4-Seiten korrekt dargestellt werden. `design-token-remap` und SAST
sind wichtig, aber setzen einen funktionierenden Frontend-Render voraus.

---

*Dokument erstellt 2026-06-21. Alle Architekturentscheidungen basieren auf dem
tatsächlichen Codestand in `includes/` und der E2E-Test-Analyse.*
