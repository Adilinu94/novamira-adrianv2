# novamira-adrianv2 — Gründliche Plugin-Analyse & V3→V4-Konvertierungs-Konzept

> **Repo analysiert:** `github.com/Adilinu94/novamira-adrianv2` + `github.com/use-novamira/novamira`  
> **Stand der Analyse:** 2026-06-19  
> **Plugin-Version:** 1.0.0 (public) → 1.1.0 (in Entwicklung, laut ROADMAP/CHANGELOG)  
> **Ziel:** Vollständige Code-Review + Spezifikation der neuen V3→V4-Seiten-Konvertierung

---

## 1. Was Novamira macht — Architekturüberblick

Das **Novamira Core Plugin** (`use-novamira/novamira`) ist der MCP-Server-Kern: er gibt KI-Agenten über die WordPress Abilities API + MCP Adapter vollständigen Zugriff auf eine WP-Installation (PHP-Ausführung, Filesystem, WP-CLI-Brücke). Er registriert sich als `novamira/*`-Namespace.

Das **AdrianV2-Plugin** ist das eigentliche Arbeitstier darüber: es liefert 40+ spezialisierte Abilities im Namespace `novamira-adrianv2/*`, primär für Elementor V4 Atomic, Media, Audit, SEO, WPCode und mehr.

```
Claude/KI-Agent
    │
    ▼
MCP Adapter (Novamira Core) ──── wp_abilities_api
    │
    ├── novamira/*          (Core: execute-php, run-wp-cli, filesystem …)
    │
    └── novamira-adrianv2/* (AdrianV2: Elementor, Media, Audit, Variables …)
                │
                ├── includes/helpers/    ← geteilte Helpers (V4_Props, V4_Styles, Guards …)
                ├── includes/abilities/  ← 11 Kategorien, 40+ Abilities
                └── includes/skills/    ← 9 SKILL.md-Dateien (Wissen für den Agenten)
```

---

## 2. Stärken — Was bereits hervorragend funktioniert

### 2.1 Solide V4-Infrastruktur

Der `Elementor_Version_Resolver` ist eine gut durchdachte Single-Source-of-Truth für die V3/V4-Erkennung. Die Klasse erkennt per-Page (über den `_elementor_data`-Tree) und site-wide (über `ELEMENTOR_VERSION`) ob Atomic aktiv ist. Das ist besser als die frühere Triple-Redundanz (`Elementor_WC_Bridge::resolve_version`, `V4_Props::is_atomic_supported`, `Elementor_WC_Bridge::detect_page_version`).

Der `V4_Props`-Helper deckt alle Prop-Typen ab (`string`, `size`, `color`, `image-attachment-id` etc.) und behandelt die kritische **Invariante IV** (kein `url`-Key wenn `id` gesetzt ist) korrekt.

### 2.2 Kit-Konvertierung (Phase 1–4)

`kit-convert-v3-to-v4` ist die bisher stärkste Ability des Plugins. Sie konvertiert das Elementor Global Kit vollständig:

- **Phase 1:** V3-Farben → V4 Color Variables (mit Strategy `skip`/`overwrite`/`rename`)
- **Phase 2:** V3-Schriften → V4 Font- und Size-Variables (dedupliziert, mit semantischer Benennung z.B. `font-heading`, `size-xl`)
- **Phase 3:** V3-Typography-Presets → V4 Global Classes (mit `$$type`-Wrapping)
- **Phase 4:** Responsive Varianten (tablet/mobile) auf die neuen Global Classes

Das ist echter Mehrwert: Die Klasse schreibt über den richtigen Meta-Key (`_elementor_global_classes_data`, `_elementor_global_classes_order`, etc.) und invalidiiert den Cache korrekt über `Guards::invalidate_all_elementor_caches()`.

### 2.3 Test-Infrastruktur

52 PHPUnit-Tests mit 145 Assertions, 0 WordPress-Abhängigkeiten im Test (20+ Mock-Funktionen). Das ist für ein WordPress-Plugin überdurchschnittlich gut. Die Fixture-Files (`tests/fixtures/elementor/`) decken V3-Container, verschachtelte Strukturen und V4-Atomic-Shapes ab.

### 2.4 WPCode-Integration

7 neue Abilities (CRUD + Status + Duplicate + Delete) für WPCode-Snippets. Schreibt korrekt über `WPCode_Snippet::save()` statt direkt über `wp_update_post`. Der `bypass_kses`-Pfad mit `try/finally` ist sicherheitsrelevant richtig implementiert.

### 2.5 Rollback + Inject-Calibrated-Page

`elementor-inject-calibrated-page` ist das richtige Gegenstück zum rohen `update_post_meta`-Ansatz: routet alles über `Elementor_Document_Saver::save_data()`, prüft `wp_check_post_lock`, seed boot-Meta bei first-write, und unterstützt `merge_by_id` (DFS-Rekursion). Das ist der einzig korrekte Weg für Page-Writes.

---

## 3. Lücken & Verbesserungspotenzial

### 3.1 🔴 KRITISCH: Page-Level V3→V4-Konvertierung fehlt komplett

Dies ist die größte Lücke im Plugin. Der Skill `adrianv2-v3-to-v4-convert` beschreibt im Schritt 2 `section → e-flexbox`, `column → e-div-block`, `widget/heading → widget/e-heading` usw. — aber **diese Ability existiert nicht**. Die einzige vorhandene Konvertierung (`kit-convert-v3-to-v4`) arbeitet ausschließlich auf dem Global Kit, nicht auf `_elementor_data` von Seiten.

Das bedeutet: Ein Agent der "konvertier meine V3-Seite zu V4" ausführt, kann den Kit migrieren (Design-Tokens) aber die Seiteninhalte bleiben als V3-Tree mit `section`/`column`/`heading`-Widgets stehen. **Der Hauptanwendungsfall fehlt.**

→ Lösung: Neue Ability `novamira-adrianv2/convert-page-v3-to-v4` (vollständige Spezifikation in Abschnitt 5)

### 3.2 🔴 `detect-elementor-version` Ability fehlt

Der Skill `adrianv2-v3-page-edit` und `adrianv2-v4-atomic-build` verweisen beide auf `novamira-adrianv2/detect-elementor-version` als erste Ability — diese ist aber nicht im Plugin registriert. Der `Elementor_Version_Resolver::detect_page_version()` Helper existiert, aber keine MCP-Ability drumherum.

→ Lösung: Einfache Wrapper-Ability registrieren (5-10 Zeilen PHP)

### 3.3 🟠 MITTEL: Phase 4 Dokumentation offen

Laut BAUPLAN-V3-V4-TRENNUNG.md fehlen noch:
- `docs/SKILLS-INVENTORY.md`
- `docs/V3-V4-DECISION-TREE.md`
- `docs/CHANGELOG-v2-detailed.md`

Diese sind für die Nutzung durch Agenten wichtig, weil sie das Wissen über Ability-Auswahl strukturieren.

### 3.4 🟠 MITTEL: Offene Security-Findings

Aus `docs/GOTCHAS.md` § 3:

| Finding | Status |
|---|---|
| PHP-Sandbox Audit (B8) | ⚠️ offen |
| XSS via `add-custom-js` (B9) | ⚠️ offen |
| SAST-Integration (`psalm --taint-analysis`) | ⚠️ offen |
| axe-core in Visual-QA | ⚠️ offen |

### 3.5 🟠 MITTEL: `check-setup`-Pattern nicht ausgerollt

`wpcode-check-setup` ist ein gutes Pattern (gibt Health-Report über Plugin-Status, Helper-Reachability, Cache-Layers, Permissions). Aber es existiert nur für WPCode. Das ROADMAP-Ziel E1–E5 (Elementor, AIOSEO, Yoast, Rank Math, WooCommerce) ist offen.

### 3.6 🟡 NIEDRIG: SEO-Mutations-Abilities fehlen

`class-seo.php` liest SEO-Meta, aber schreibt nicht. `set-rank-math-meta` und `set-aioseo-meta` sind im ROADMAP (G1–G2) geplant aber nicht implementiert.

### 3.7 🟡 NIEDRIG: Keine CI/CD für das Plugin selbst

Das `framer-v4-pipeline-v2`-Repo hat einen vollständigen CI-Workflow, aber das `novamira-adrianv2`-Plugin hat keinen `.github/workflows/`. Bei jedem Push auf `master` wird PHPUnit, phpcs und Psalm nicht automatisch ausgeführt.

### 3.8 🟡 NIEDRIG: `class-v4-color-contrast-22.php` deprecated but present

Die Datei ist als `@deprecated BC-Extension` markiert, liefert aber trotzdem Werte. Das erzeugt Unklarheit wann welche Version verwendet wird. Klare Deprecation-Notice oder Entfernen.

---

## 4. Was im Novamira Core steckt (Relevanz für AdrianV2)

Das Core-Plugin (`use-novamira/novamira`) ist der MCP-Server mit diesen Haupt-Abilities:

```
execute-php         → beliebiges PHP ausführen
run-wp-cli          → WP-CLI-Befehle ausführen  
read-file           → Dateien lesen
write-file          → Dateien schreiben
edit-file           → Dateien patchen
list-directory      → Verzeichnisse auflisten
create-upload-link  → Media-Upload-Links erzeugen
```

Das **CLAUDE.md** des Core-Repos ist wichtig: es gibt `mago-format`, `mago-lint`, `mago-analyze` als Pflicht-Gates vor jedem Commit an — AdrianV2 verwendet stattdessen phpcs+psalm, was äquivalent ist.

Der Core bietet außerdem Gutenberg-Batch-Editing (`add-pending-change`, `create-pending-batch`, `enable-batch-finalization`). Das wurde bewusst in AdrianV2 ausgeschlossen ("Gutenberg brauche ich nicht").

**Relevant für die Konvertierung:** `execute-php` könnte als Escape-Hatch für Operationen dienen, die keine eigene Ability haben. Aber für die V3→V4-Konvertierung sollten wir eine echte Ability bauen — nicht auf `execute-php` angewiesen sein.

---

## 5. Neue Feature-Spezifikation: `novamira-adrianv2/convert-page-v3-to-v4`

Dies ist die wichtigste neue Ability. Sie konvertiert `_elementor_data` einer V3-Seite vollständig in einen V4-Atomic-Tree.

### 5.1 Design-Philosophie

**Atomic-first, V3-Fallback:** Jedes Widget wird in das V4-Atomic-Äquivalent konvertiert. Nur wenn kein V4-Äquivalent existiert (Drittanbieter-Widgets, WooCommerce-spezifische Widgets, komplexe Template-Parts), bleibt das V3-Widget mit einer Warning im Output erhalten.

**Nicht-destruktiv via Rollback:** Vor der Konvertierung wird `rollback-build` aufgerufen (Snapshot der aktuellen `_elementor_data`). So kann bei Problemen zurückgerollt werden.

**Design-System-Integration:** Die Ability nutzt die `variable_map` aus `kit-convert-v3-to-v4`, um Farb- und Typography-Werte direkt an die entsprechenden Global Variables zu binden.

### 5.2 Widget-Mapping-Tabelle

| V3 elType | V3 widgetType | V4 elType | V4 widgetType | Hinweis |
|---|---|---|---|---|
| `section` | — | `e-flexbox` | — | flex-direction: row für Columns |
| `column` | — | `e-div-block` | — | width aus content_width |
| `container` | — | `e-flexbox` | — | 1:1 wenn flex_direction gesetzt |
| `widget` | `heading` | `widget` | `e-heading` | title bleibt, typography → styles |
| `widget` | `text-editor` | `widget` | `e-paragraph` | `editor` → `paragraph` |
| `widget` | `button` | `widget` | `e-button` | text, link migrieren |
| `widget` | `image` | `widget` | `e-image` | image.id → src.id (Invariante IV!) |
| `widget` | `spacer` | `widget` | `e-spacer` | space → height.size |
| `widget` | `divider` | `widget` | `e-divider` | style, weight, color migrieren |
| `widget` | `icon` | `widget` | `e-icon` | icon, color migrieren |
| `widget` | `video` | `widget` | `e-self-hosted-video` | url → source |
| `widget` | `youtube` | `widget` | `e-youtube` | url → source |
| `widget` | `icon-box` | ❌ kein V4-Äquivalent | — | Warning + V3-Widget behalten |
| `widget` | `image-box` | ❌ kein V4-Äquivalent | — | Warning + V3-Widget behalten |
| Alle anderen | — | ❌ unbekannt | — | Warning + V3-Widget behalten |

### 5.3 Style-Migration-Regeln

V3 speichert Styles direkt in `settings`. V4 nutzt eine `styles`-Map mit `$$type`-Wrapping.

```
V3 settings.color              → V4 styles.color   {$$type:'color', value:'#HEX'} 
                                  ODER wenn GV vorhanden: {$$type:'global-color-variable', value:'e-gv-abc123'}

V3 settings.typography_font_family → V4 styles.font-family {$$type:'string', value:'Poppins'}
                                      ODER {$$type:'global-font-variable', value:'e-gv-...'}

V3 settings.typography_font_size   → V4 styles.font-size {$$type:'size', value:{size:24, unit:'px'}}
V3 settings.typography_font_weight → V4 styles.font-weight {$$type:'string', value:'700'}
V3 settings.typography_line_height → V4 styles.line-height {$$type:'size', value:{size:1.5, unit:'em'}}

V3 settings._margin  → V4 styles.margin  {$$type:'box-shadow', value:{block-start:X, …}} 
V3 settings._padding → V4 styles.padding (analog)

V3 settings.background_color  → V4 styles.background-color (wie color oben)
V3 settings.background_image  → V4 styles.background-image {$$type:'image', value:{id:N}}

V3 settings.border_border     → V4 styles.border-style
V3 settings.border_width      → V4 styles.border-width
V3 settings.border_color      → V4 styles.border-color

V3 settings.text_align        → V4 styles.text-align {$$type:'string', value:'center'}
```

**Responsive Breakpoints:**  
V3 hat `_tablet`- und `_mobile`-Suffix-Keys in `settings` (z.B. `typography_font_size_tablet`).  
V4 speichert Breakpoint-Varianten in Global Classes (über `add-global-class-variant`).  
→ Strategie: Desktop-Werte direkt in `styles`, Breakpoint-Werte als Warning zurückgeben und in einem Post-Convert-Schritt über `add-global-class-variant` setzen.

### 5.4 Ability-Schema

**Input:**
```json
{
  "post_id": { "type": "integer", "description": "Die V3-Seite. Pflicht." },
  "dry_run": { "type": "boolean", "description": "Preview ohne Schreiben. Default: false." },
  "variable_map": {
    "type": "object",
    "description": "Output von kit-convert-v3-to-v4 → variable_map. Optional. Wenn gesetzt werden Farb/Font-Werte an GVs gebunden."
  },
  "class_map": {
    "type": "object",
    "description": "Output von kit-convert-v3-to-v4 → class_map. Optional. Für Typography-Klassen-Bindung."
  },
  "unknown_widget_strategy": {
    "type": "string",
    "enum": ["keep_v3", "skip", "error"],
    "description": "Was mit unbekannten Widgets tun. Default: keep_v3."
  },
  "create_rollback": {
    "type": "boolean",
    "description": "Snapshot vor der Konvertierung erstellen. Default: true."
  }
}
```

**Output:**
```json
{
  "post_id": "integer",
  "dry_run": "boolean",
  "success": "boolean",
  "rollback_snapshot_id": "integer|null",
  "stats": {
    "elements_total": "integer",
    "elements_converted": "integer",
    "elements_kept_v3": "integer",
    "elements_skipped": "integer",
    "colors_bound_to_gv": "integer",
    "fonts_bound_to_gv": "integer"
  },
  "warnings": ["string"],
  "kept_v3_elements": [
    { "id": "string", "widgetType": "string", "reason": "string" }
  ],
  "responsive_todo": [
    { "element_id": "string", "breakpoint": "tablet|mobile", "props": {} }
  ]
}
```

### 5.5 Implementierungs-Schritte

**Phase A: Basis-Konvertierung (Core)**

```php
// Datei: includes/abilities/elementor/class-convert-page-v3-to-v4.php
namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers\Elementor_Version_Resolver;
use Novamira\AdrianV2\Helpers\Elementor_Document_Saver;
use Novamira\AdrianV2\Helpers\V4_Props;
use Novamira\AdrianV2\Helpers\Guards;

class Convert_Page_V3_To_V4 {
    
    // Widget-Typ-Mapping (V3 widgetType → V4 widgetType)
    const WIDGET_MAP = [
        'heading'     => 'e-heading',
        'text-editor' => 'e-paragraph',
        'button'      => 'e-button',
        'image'       => 'e-image',
        'spacer'      => 'e-spacer',
        'divider'     => 'e-divider',
        'icon'        => 'e-icon',
        'video'       => 'e-self-hosted-video',
        'youtube'     => 'e-youtube',
    ];
    
    // elType-Mapping (V3 elType → V4 elType)
    const EL_TYPE_MAP = [
        'section'   => 'e-flexbox',
        'column'    => 'e-div-block',
        'container' => 'e-flexbox',
    ];
    
    public static function execute(array $input = []): array|\WP_Error {
        // 1. V4 Guard
        if (!Elementor_Version_Resolver::site_is_v4()) {
            return new \WP_Error('v4_required', 'Elementor 4.0+ required.');
        }
        
        // 2. Page-Version prüfen (muss V3 sein)
        $post_id = (int)($input['post_id'] ?? 0);
        if (Elementor_Version_Resolver::page_is_v4($post_id)) {
            return new \WP_Error('already_v4', 'Page is already V4 atomic.');
        }
        
        // 3. Rollback-Snapshot
        if ($input['create_rollback'] ?? true) {
            $snapshot_id = wp_save_post_revision($post_id);
        }
        
        // 4. Tree lesen
        $data = get_post_meta($post_id, '_elementor_data', true);
        $tree = json_decode($data, true);
        
        // 5. Tree rekursiv konvertieren
        $stats = ['elements_total'=>0, 'elements_converted'=>0, ...];
        $warnings = [];
        $kept_v3 = [];
        $responsive_todo = [];
        
        $converted = self::convert_tree(
            $tree, 
            $input['variable_map'] ?? [],
            $input['class_map'] ?? [],
            $input['unknown_widget_strategy'] ?? 'keep_v3',
            $stats, $warnings, $kept_v3, $responsive_todo
        );
        
        // 6. Schreiben (wenn nicht dry_run)
        if (!($input['dry_run'] ?? false)) {
            $result = Elementor_Document_Saver::save_data($post_id, $converted);
            if (!$result['success']) {
                return new \WP_Error('save_failed', implode(', ', $result['warnings']));
            }
        }
        
        return [
            'success' => true,
            'stats' => $stats,
            'warnings' => $warnings,
            'kept_v3_elements' => $kept_v3,
            'responsive_todo' => $responsive_todo,
        ];
    }
    
    private static function convert_tree(array $elements, array $variable_map, array $class_map, string $unknown_strategy, array &$stats, array &$warnings, array &$kept_v3, array &$responsive_todo): array {
        $result = [];
        foreach ($elements as $el) {
            $stats['elements_total']++;
            $converted = self::convert_element($el, $variable_map, $class_map, $unknown_strategy, $stats, $warnings, $kept_v3, $responsive_todo);
            if ($converted !== null) {
                // Rekursiv Kinder konvertieren
                if (!empty($converted['elements'])) {
                    $converted['elements'] = self::convert_tree($converted['elements'], $variable_map, $class_map, $unknown_strategy, $stats, $warnings, $kept_v3, $responsive_todo);
                }
                $result[] = $converted;
            }
        }
        return $result;
    }
    
    private static function convert_element(array $el, array $variable_map, ...): ?array {
        $el_type = $el['elType'] ?? '';
        
        // Container-Typen (section, column, container)
        if (isset(self::EL_TYPE_MAP[$el_type])) {
            return self::convert_container($el, $variable_map);
        }
        
        // Widgets
        if ($el_type === 'widget') {
            $widget_type = $el['widgetType'] ?? '';
            if (isset(self::WIDGET_MAP[$widget_type])) {
                return self::convert_widget($el, $widget_type, $variable_map);
            }
            // Unbekanntes Widget
            return self::handle_unknown($el, $unknown_strategy, $kept_v3, $warnings);
        }
        
        return $el; // Unbekannter elType → unverändert
    }
}
```

**Phase B: Style-Konvertierung (der Kern)**

```php
private static function migrate_styles(array $v3_settings, array $variable_map): array {
    $styles = [];
    
    // Farben: prüfe ob GV verfügbar
    foreach (['color', 'background_color', 'border_color'] as $key) {
        if (!empty($v3_settings[$key])) {
            $hex = $v3_settings[$key];
            // Suche in variable_map nach übereinstimmender Farbe
            $gv_id = self::find_color_gv($hex, $variable_map);
            $css_key = self::v3_to_css_prop($key);
            $styles[$css_key] = $gv_id 
                ? ['$$type' => 'global-color-variable', 'value' => $gv_id]
                : ['$$type' => 'color', 'value' => $hex];
        }
    }
    
    // Schriften
    if (!empty($v3_settings['typography_font_family'])) {
        $ff = $v3_settings['typography_font_family'];
        $gv_id = self::find_font_gv($ff, $variable_map);
        $styles['font-family'] = $gv_id
            ? ['$$type' => 'global-font-variable', 'value' => $gv_id]
            : ['$$type' => 'string', 'value' => $ff];
    }
    
    if (!empty($v3_settings['typography_font_size'])) {
        $fs = $v3_settings['typography_font_size'];
        $styles['font-size'] = ['$$type' => 'size', 'value' => [
            'size' => (float)($fs['size'] ?? 16),
            'unit' => $fs['unit'] ?? 'px',
        ]];
    }
    
    // Spacing: _margin, _padding
    foreach (['_margin' => 'margin', '_padding' => 'padding'] as $v3_key => $css_key) {
        if (!empty($v3_settings[$v3_key])) {
            $val = $v3_settings[$v3_key];
            $styles[$css_key] = ['$$type' => 'dimensions', 'value' => [
                'block-start' => ['size' => (float)($val['top'] ?? 0), 'unit' => $val['unit'] ?? 'px'],
                'block-end'   => ['size' => (float)($val['bottom'] ?? 0), 'unit' => $val['unit'] ?? 'px'],
                'inline-start'=> ['size' => (float)($val['left'] ?? 0), 'unit' => $val['unit'] ?? 'px'],
                'inline-end'  => ['size' => (float)($val['right'] ?? 0), 'unit' => $val['unit'] ?? 'px'],
            ]];
        }
    }
    
    return $styles;
}
```

**Phase C: Widget-spezifische Konvertierungen**

```php
// e-heading: title bleibt, tag aus header_size
private static function convert_heading(array $v3): array {
    $settings = $v3['settings'] ?? [];
    return [
        'title' => $settings['title'] ?? '',
        'tag'   => self::header_size_to_tag($settings['header_size'] ?? 'h2'),
    ];
}

// e-paragraph: editor → paragraph
private static function convert_text_editor(array $v3): array {
    return ['paragraph' => ['$$type' => 'string', 'value' => $v3['settings']['editor'] ?? '']];
}

// e-image: INVARIANTE IV (kein url wenn id gesetzt)
private static function convert_image(array $v3): array {
    $img = $v3['settings']['image'] ?? [];
    $id = (int)($img['id'] ?? 0);
    if ($id > 0) {
        return ['src' => ['$$type' => 'image-attachment-id', 'value' => $id]]; // KEIN url-Key!
    }
    if (!empty($img['url'])) {
        return ['src' => ['$$type' => 'image', 'value' => ['url' => $img['url']]]];
    }
    return [];
}
```

### 5.6 Vollständiger Workflow (für Agents)

```
Schritt 1: Backup + Pre-Audit
  → novamira-adrianv2/layout-audit { post_id }   (Score festhalten)
  → novamira-adrianv2/design-audit { post_id }   (Score festhalten)

Schritt 2: Design-System migrieren (falls noch nicht geschehen)
  → novamira-adrianv2/kit-convert-v3-to-v4 { dry_run: true }   (Vorschau)
  → novamira-adrianv2/kit-convert-v3-to-v4 { dry_run: false }  (Ausführen)
  → variable_map und class_map aus dem Response sichern!

Schritt 3: Seite konvertieren (NEU)
  → novamira-adrianv2/convert-page-v3-to-v4 {
      post_id: 1234,
      dry_run: true,           // Vorschau
      variable_map: { … },    // aus Schritt 2
      class_map: { … },       // aus Schritt 2
      unknown_widget_strategy: "keep_v3"
    }
  → Warnings und kept_v3_elements prüfen
  → novamira-adrianv2/convert-page-v3-to-v4 { dry_run: false, … }  // Ausführen

Schritt 4: V4 Foundation sicherstellen
  → novamira-adrianv2/setup-v4-foundation {}

Schritt 5: Global Classes zuweisen
  → novamira-adrianv2/batch-class {
      post_id: 1234,
      element_class_map: {
        "hero-section": ["e-flexbox-base", "surface-primary"],
        ...
      }
    }

Schritt 6: Responsive Breakpoints (aus responsive_todo)
  → Für jeden Eintrag in responsive_todo:
    novamira-adrianv2/add-global-class-variant {
      class_id: ...,
      breakpoint: "tablet",
      props: { ... }
    }

Schritt 7: Post-Conversion Audits
  → novamira-adrianv2/layout-audit { post_id }    (Score vergleichen)
  → novamira-adrianv2/class-audit { post_id }     (Class-Coverage)
  → novamira-adrianv2/variable-audit { post_id }  (GV-Drift)
  → novamira-adrianv2/visual-qa { post_id }       (Kontrast, Spacing)
```

### 5.7 Gotchas & Edge Cases

**Invariante IV (Image):** Wenn `id` gesetzt ist, darf `url` GAR NICHT vorkommen — auch nicht als `null`. Das ist die häufigste Fehlerquelle bei Image-Konvertierungen.

**V3 `container` ≠ V4 `e-flexbox` intern:** V3-Container können `flex_direction: column` haben — das muss in `styles.flex-direction` mit `$$type: 'string'` übernommen werden.

**Section → e-flexbox mit Columns:** V3-Sections haben typisch `flex_direction: row` (für nebeneinanderliegende Columns). V4 `e-flexbox` default ist column. → Explizit `styles.flex-direction: {$$type:'string', value:'row'}` setzen.

**Custom CSS:** V3 `_element_custom_css` → `_elementor_page_custom_css` (Page-Level) oder direkt in Element-Properties. Muss separat behandelt werden über `Elementor_CSS_Override::inject_page_custom_css()`.

**IDs erhalten:** Elementor-Element-IDs (`id`-Feld) sollten nicht verändert werden — andere Abilities (assign-class-to-containers, edit-element) referenzieren Elemente über diese IDs.

**Rollback:** Die Ability nutzt `wp_save_post_revision()` vor dem Write. Bei Problemen kann über `rollback-build { post_id, revision_id }` zurückgegangen werden.

---

## 6. Weitere empfohlene Improvements (Priorisiert)

### 6.1 🔴 SOFORT: `detect-elementor-version` Ability registrieren

```php
// 15 Zeilen, in atomic/bootstrap.php oder utilities/
wp_register_ability('novamira-adrianv2/detect-elementor-version', [
    'label'            => 'Detect Elementor Version',
    'description'      => 'Returns whether the site and/or a specific page runs Elementor V3 or V4 atomic.',
    'category'         => 'novamira-adrianv2-utilities',
    'input_schema'     => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer']]],
    'execute_callback' => function($input) {
        $post_id = (int)($input['post_id'] ?? 0);
        return [
            'site_is_v4'        => Elementor_Version_Resolver::site_is_v4(),
            'site_version'      => Elementor_Version_Resolver::site_version_string(),
            'atomic_supported'  => Elementor_Version_Resolver::site_is_v4(),
            'page_is_v4'        => $post_id > 0 ? Elementor_Version_Resolver::page_is_v4($post_id) : null,
        ];
    },
    // ...
]);
```

### 6.2 🟠 KURZFRISTIG: Fehlende Dokumentation (Phase 4)

Drei Markdown-Dateien anlegen:

**`docs/SKILLS-INVENTORY.md`** — Tabelle aller 9 Skills mit Slug, Elementor-Welt, Trigger-Phrasen, Beispiel-Aufruf.

**`docs/V3-V4-DECISION-TREE.md`** — ASCII-Entscheidungsbaum:
```
Neue Seite erstellen?
├── Ja  → setup-v4-foundation → elementor-set-content (V4-Tree)
└── Nein → detect-elementor-version
           ├── V3  → convert-page-v3-to-v4 → V4 danach
           └── V4  → elementor-set-content (V4-Tree) oder patch-element-styles
```

**`docs/CHANGELOG-v2-detailed.md`** — Detaillierte Migrations-History (V3/V4-Trennung).

### 6.3 🟠 MITTELFRISTIG: GitHub Actions CI

```yaml
# .github/workflows/ci.yml
name: CI
on: [push, pull_request]
jobs:
  phpunit:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - run: composer install
      - run: ./vendor/bin/phpunit --testdox
  phpcs:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - run: composer install
      - run: ./vendor/bin/phpcs --standard=phpcs.xml
  psalm:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - run: composer install
      - run: ./vendor/bin/psalm --no-progress
```

### 6.4 🟠 MITTELFRISTIG: `elementor-check-setup` Ability

Pattern von `wpcode-check-setup` auf Elementor ausrollen:

```
Output:
- elementor_version (string)
- elementor_pro_active (bool)
- atomic_supported (bool)
- active_kit_id (int)
- global_classes_count (int)
- global_variables_count (int)
- v4_foundation_present (bool)  ← prüft ob e-flexbox-base GC existiert
- pages_with_elementor (int)
- pages_v3 (int)
- pages_v4 (int)
- pages_mixed (int)   ← hat sowohl V3 als auch V4 Widgets!
- issues ([])
```

Das ist der perfekte Pre-Check bevor `convert-page-v3-to-v4` aufgerufen wird.

### 6.5 🟡 LANGFRISTIG: Bulk-Konvertierung `convert-site-v3-to-v4`

Nutzt `list-elementor-pages` → für jede Seite `convert-page-v3-to-v4`. Mit Progress-Reporting (da MCP-Calls timeout-gefährdet bei vielen Seiten):

```json
{
  "strategy": "sequential",
  "post_ids": [1234, 2345, 3456],   // oder alle V3-Seiten
  "stop_on_error": false,
  "dry_run": true
}
```

### 6.6 🟡 LANGFRISTIG: `design-token-remap` Ability

Wenn der Kunde das Design-System umstrukturiert (neue GV-IDs nach Rebranding), muss jede Seite gescannt und alle GV-Referenzen aktualisiert werden. Das ist der nächste logische Schritt nach der V3→V4-Konvertierung.

---

## 7. Test-Plan für `convert-page-v3-to-v4`

### PHPUnit-Tests (neues File: `tests/ConvertPageV3ToV4Test.php`)

| Test | Prüft |
|---|---|
| `test_refuses_v3_site()` | Ability gibt `WP_Error(v4_required)` wenn Elementor <4.0 |
| `test_refuses_v4_page()` | Gibt `WP_Error(already_v4)` bei bereits konvertierter Seite |
| `test_converts_section_to_e_flexbox()` | `section` → `e-flexbox` mit flex-direction: row |
| `test_converts_column_to_e_div_block()` | `column` → `e-div-block` |
| `test_converts_heading()` | `widget/heading` → `widget/e-heading`, `title` erhalten, `tag` aus `header_size` |
| `test_converts_text_editor()` | `editor` → `paragraph` prop |
| `test_converts_image_without_url_key()` | Invariante IV: kein `url` wenn `id` gesetzt |
| `test_binds_color_to_global_variable()` | Wenn `variable_map` übergeben, wird Farbe an GV gebunden |
| `test_keeps_unknown_widget_v3()` | Unbekanntes Widget bleibt V3, erscheint in `kept_v3_elements` |
| `test_dry_run_does_not_write()` | `dry_run:true` schreibt keine `_elementor_data` |
| `test_creates_rollback_snapshot()` | `wp_save_post_revision` wird aufgerufen |
| `test_recursive_nesting()` | 3-Level-Verschachtelung wird korrekt konvertiert |
| `test_styles_from_v3_settings()` | `typography_font_size` → `styles.font-size` mit `$$type:size` |
| `test_responsive_todo_populated()` | `typography_font_size_mobile` landet in `responsive_todo` |
| `test_ids_preserved()` | Elementor Element-IDs bleiben erhalten |

Gesamt: **15 neue Tests** → damit 52 + 15 = **67 PHPUnit-Tests**.

---

## 8. Zusammenfassung: Prioritätenliste

| # | Aufgabe | Aufwand | Wert |
|---|---|---|---|
| 1 | `convert-page-v3-to-v4` Ability implementieren | L (3–5 Tage) | ⭐⭐⭐⭐⭐ |
| 2 | `detect-elementor-version` Ability (fehlt, aber referenziert) | S (1h) | ⭐⭐⭐⭐ |
| 3 | `elementor-check-setup` Ability | M (2–3h) | ⭐⭐⭐⭐ |
| 4 | Phase 4 Docs (SKILLS-INVENTORY, V3-V4-DECISION-TREE) | M (2–3h) | ⭐⭐⭐ |
| 5 | GitHub Actions CI | M (2h) | ⭐⭐⭐ |
| 6 | Security-Findings (B8, B9, SAST) | M (pro Finding) | ⭐⭐⭐ |
| 7 | `check-setup` für Elementor/AIOSEO/Yoast | M (pro Plugin) | ⭐⭐ |
| 8 | `convert-site-v3-to-v4` (Bulk) | L (2–3 Tage) | ⭐⭐ |
| 9 | SEO-Mutation-Abilities (G1–G2) | M (pro Ability) | ⭐⭐ |
| 10 | `design-token-remap` | XL | ⭐⭐ |

**Empfohlene Reihenfolge:**  
Zuerst `detect-elementor-version` (1h, unblocks alle Skills die es erwähnen) → dann `convert-page-v3-to-v4` (das Kernfeature) → dann `elementor-check-setup` (macht den Workflow komplett) → dann Phase 4 Docs.

---

*Dokument erstellt durch Claude-Analyse von `github.com/Adilinu94/novamira-adrianv2` und `github.com/use-novamira/novamira`. Alle Code-Snippets sind Pseudo-Code/Referenz — die tatsächliche Implementierung folgt den Plugin-Coding-Standards (phpcs.xml, namespace `Novamira\AdrianV2\...`, strict_types=1).*
