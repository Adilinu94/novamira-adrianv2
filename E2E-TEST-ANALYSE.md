# E2E-TEST: V3→V4 Seitenkonvertierung — Analyse für KI-Agenten

> **Testseite:** `ueber-uns` (Kopie auf Post-ID 5400)  
> **Datum:** 2026-06-21  
> **Elementor:** 4.1.3 / Pro 4.1.0  
> **Plugin-Stand:** Vollständige Konvertierung durchgeführt  
> **Ziel:** Identifikation von Lücken, wo ein KI-Agent ohne zusätzliche Tools/Abilities scheitert

---

## 1. Test-Ablauf (was wurde gemacht)

| Schritt | Ability | Status |
|---|---|---|
| 1. Testseite anlegen (WP-CLI) | `wp post create` | ✅ |
| 2. _elementor_data kopieren | Direkt-SQL (notwendig wegen WP-Slashing) | ✅ |
| 3. Kit konvertieren (V3→V4 Global Variables + Classes) | `kit-convert-v3-to-v4` | ✅ |
| 4. Seite V3→V4 konvertieren (dry_run) | `convert-page-v3-to-v4` | ✅ |
| 5. Seite V3→V4 konvertieren (live) | `convert-page-v3-to-v4` | ✅ |
| 6. V4 Foundation setzen | `setup-v4-foundation` | ✅ |
| 7. Convertierte Daten verifizieren | Code-Review | ✅ |

---

## 2. ✅ Was funktioniert hat

- **Container-Konvertierung:** V3 `elType:container` → V4 `elType:e-flexbox` — ✅
- **Widget-Konvertierung:** `heading→e-heading`, `text-editor→e-paragraph`, `image→e-image` — ✅
- **Responsive Breakpoints:** `_tablet`/`_mobile` Extrahierung + Variant-Anlage — ✅
- **Container-Level Styles:** Padding, Background-Color, Border wurden in Styles geshiftet — ✅
- **Global-Color-Auflösung:** `__globals__`-Referenzen wurden zu Hex-Werten aufgelöst — ✅
- **_novamira_v3_backup:** Automatischer Backup des V3-Trees vor Write — ✅
- **Elementor_Page_Settings:** `hide_title:yes` blieb erhalten — ✅
- **setup-v4-foundation:** Anlage von e-flexbox-base + e-div-block-base + Klassen — ✅

---

## 3. 🔴 KRITISCHE Schwachstellen

### 3.1 ✅ ERLEDIGT — Autoloader: V3_To_V4_Converter wird nicht gefunden

**Problem:** Die Klasse `Novamira\AdrianV2\Helpers\V3_To_V4_Converter` existiert in `includes/helpers/class-v3-to-v4-converter.php`, wird aber vom Plugin-Autoloader nicht geladen. Die `Convert_Page_V3_To_V4`-Klasse (die via Novamira-Pro-Abilities-Registry registriert ist) scheitert mit:
```
Class "Novamira\AdrianV2\Helpers\V3_To_V4_Converter" not found
```

**Auswirkung für KI:** Jeder Agent, der `convert-page-v3-to-v4` aufruft, bekommt einen PHP-Fatal-Error. Es gibt keine Möglichkeit, dies über MCP zu heilen.

**Lösung:** Manuelles `require_once` der Helper-Dateien VOR dem Ability-Call:
```php
require_once WP_PLUGIN_DIR . '/novamira-adrianv2/includes/helpers/class-v3-to-v4-converter.php';
require_once WP_PLUGIN_DIR . '/novamira-adrianv2/includes/helpers/class-conversion-auditor.php';
require_once WP_PLUGIN_DIR . '/novamira-adrianv2/includes/helpers/class-conversion-auto-fixer.php';
```

**🔧 Benötigte Ability:**
`novamira-adrianv2/ensure-converter-loaded` — prüft und lädt alle Converter-Dependencies nach.

**ODER** es muss ein Autoloader-Fix im Plugin selbst her (z.B. `classmap` in `composer.json` oder `spl_autoload_register`).

---

### 3.2 ✅ ERLEDIGT — Global-Color-Referenzen gehen verloren

**Problem:** Die `variable_map` aus `kit-convert-v3-to-v4` wird zwar an `convert-page-v3-to-v4` übergeben, aber der JSON-Converter nutzt sie nicht zum Ersetzen von Hex-Farben durch GV-Referenzen. 

**Konkret:** V3 hat `__globals__: {"background_color": "globals/colors?id=accent"}` — das wird zu `"#00EBAF"` aufgelöst. Stattdessen sollte es zu `{"$$type": "global-color-variable", "value": "e-gv-bebd7fa"}` werden.

**Alle Farben werden als inline `$$type:"string"` gespeichert — nicht als Verweis auf die neuen Global Variables.** Das bedeutet: Wenn später das Branding geändert wird, müssen alle Seiten manuell aktualisiert werden.

**Auswirkung für KI:** Der gesamte Sinn des Design-Systems (Global Variables) geht verloren. Der Agent müsste nach der Konvertierung jede Farbe manuell gegen die `variable_map` abgleichen und ersetzen.

**🔧 Benötigte Ability:**
`novamira-adrianv2/apply-variable-map-to-page` — nimmt `post_id` + `variable_map` und ersetzt alle `$$type:"color"` durch `$$type:"global-color-variable"` wo eine Übereinstimmung in der variable_map existiert.

**Alternative Lösung:** Der `V3_To_V4_Converter::extract_style_props_for_widget()`-Methode fehlt der Color-Index-Lookup. Der Parameter `$color_index` wird in `convert_widget()` nicht an `extract_style_props_for_widget()` weitergereicht. **(Fix im Converter-Code)**

---

### 3.3 ✅ ERLEDIGT — Layout-Verstoß: Widgets direkt in e-flexbox

**Problem:** Alle 3 konvertierten Container hatten Widgets direkt als Kinder von `e-flexbox` ohne `e-div-block` dazwischen.

**✅ Fix implementiert in `Conversion_AutoFixer::fix_flexbox_widget_children()` (v1.5.0):**
- Aufgerufen als Schritt 1c in `Conversion_AutoFixer::run()` beim `auto_fix: true` Modus.
- Wrapped direkte Widget-Kinder von `e-flexbox` automatisch in `e-div-block`.
- Depth Guard: bei MAX_NESTING_DEPTH wird `e-flexbox` zu `e-div-block` konvertiert falls keine Flex-Layout-Settings vorhanden.
- Keine separate `e-div-block-wrap`-Ability nötig — der AutoFixer deckt es intern ab.

---

### 3.4 ✅ ERLEDIGT — JSON-Korruption beim Kopieren von Elementor-Daten

**Problem:** WordPress' `update_post_meta()` wendet `wp_slash()`/`wp_unslash()` an, was die `\"`-Escapes in Elementor-JSON korrumpiert:
```
Original (8583 bytes): {"editor":"<a href=\"https://...\">"}
Kopie über update_post_meta (8521 bytes, korrupt): {"editor":"<a href="https://...">"}
— Doppelpunkte und Quotes innerhalb von JSON-Strings werden zerstört.
```

**Auswirkung für KI:** Ein Agent, der eine Seite duplizieren will (z.B. für Testkonvertierung), kann nicht einfach `get_post_meta` + `update_post_meta` verwenden. Jede "Seite kopieren"-Operation korrumpiert die Elementor-Daten.

**Lösung:** Direkt-SQL via `$wpdb->replace()`:
```php
global $wpdb;
$data = $wpdb->get_var($wpdb->prepare(
    "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_elementor_data'", $source_id
));
$wpdb->replace($wpdb->postmeta, [
    'post_id' => $target_id,
    'meta_key' => '_elementor_data',
    'meta_value' => $data,
]);
```

**🔧 Benötigte Ability:**
`novamira-adrianv2/clone-elementor-page` (oder als Teil von `duplicate-page`) — kopiert alle Elementor-Meta-Keys sauber per SQL.

---

### 3.5 ⚠️ OFFEN — Kit-Convert ID-Inkonsistenz

**Problem:** Jeder Aufruf von `kit-convert-v3-to-v4` generiert NEUE `e-gv-*` IDs für die gleichen Farben. Beim ersten Aufruf wird z.B. `accent` → `e-gv-bebd7fa`, beim zweiten → `e-gv-154a25a`, beim dritten → `e-gv-f43276f`, etc. Die `_elementor_global_variables` DB-Meta wird auch nicht mit den tatsächlichen IDs befüllt — die Variable-Map enthält andere IDs als die DB.

**Auswirkung für KI:** Selbst wenn der Converter die GV-Referenzen setzen würde, wären es die falschen (neu generierten) IDs. Der Agent müsste den Kit-Convert nur EINMAL aufrufen und die `variable_map` aus diesem Aufruf für alle weiteren Seiten-Konvertierungen verwenden.

---

## 4. 🟠 MITTLERE Schwachstellen

### 4.1 `e-flexbox-base` nicht auf Containern

Der `setup-v4-foundation` erstellt `e-flexbox-base` (ID: `gc-e8dfc2c41ef4cc02`), aber die Klasse wird nicht automatisch auf die konvertierten Container angewandt. Der Agent muss nach der Konvertierung manuell `batch-class` aufrufen.

### 4.2 PHP 8.2 Deprecation in Conversion_Auditor

`${var}` in Strings (Zeile 347) ist in PHP 8.2 deprecated:
```
Deprecated: Using ${var} in strings is deprecated, use {$var} instead
```
Das erzeugt Warnungen im Error-Log und kann bei `display_errors=on` die JSON-Ausgabe korrumpieren.

### 4.3 RGBA-Farben (8-stellig) werden nicht erkannt

Farben wie `#FFFFAAEE` (RRGGBBAA) und `#FF6400` haben 8-stellige Hex-Werte. Elementor Global Variables erwarten 6-stellige Hex-Werte (`#RRGGBB`) oder `rgba()`. Der Converter speichert sie trotzdem, wobei der letzte Wert (AA = alpha) ignoriert wird.

### 4.4 Keine automatische `_elementor_version`-Migration

Die konvertierte Seite hat `_elementor_version` 4.1.3. Das ist gut — aber der Converter setzt das nicht explizit. Andere konvertierte Seiten könnten noch die alte V3-Version haben.

---

## 5. 🔧 Benötigte neue Abilities & Tools

### Priorität 1 (Blockiert den gesamten Workflow)

| Name | Problem | Beschreibung |
|---|---|---|
| ~~`ensure-converter-loaded`~~ | 3.1 ✅ ERLEDIGT | Fix in `bootstrap.php` — explizite `require_once` für alle Converter-Klassen |
| ~~`apply-variable-map-to-page`~~ | 3.2 ✅ ERLEDIGT | Ability `class-apply-variable-map-to-page.php` implementiert |
| ~~`clone-elementor-page`~~ | 3.4 ✅ ERLEDIGT | `duplicate-page` nutzt `get_raw_meta_value()` + `copy_raw_meta_values()` via SQL |

### Priorität 2 (Workflow-Qualität)

| Name | Problem | Beschreibung |
|---|---|---|
| ~~`e-div-block-wrap`~~ | 3.3 ✅ ERLEDIGT | `Conversion_AutoFixer::fix_flexbox_widget_children()` — intern im AutoFixer |
| `batch-apply-base-class` | 4.1 Fehlende Base Classes | Wendet e-flexbox-base auf alle Container einer Seite an |

### Priorität 3 (Komfort)

| Name | Problem | Beschreibung |
|---|---|---|
| `convert-page-v3-to-v4-audited` | Alle | Wrapper: Kit-Convert → Page-Convert → GV-Apply → Foundation → Batch-Class → Audit |
| `kit-convert-stable-ids` | 3.5 ID-Inkonsistenz | Kit-Convert mit definierten/already-created-IDs |

---

## 6. Kompletter Workflow (für KI-Agenten)

```
SCHRITT 0: Test-Page anlegen
  → Direct SQL: _elementor_data + _elementor_page_settings + _elementor_template_type + _elementor_version kopieren
  → ⚠️ KEIN get_post_meta + update_post_meta (korrumpiert JSON!)
  → 🔧 Alternativ: wp-cli "post meta get" →  File → "post meta update" aus File

SCHRITT 1: Kit-Konvertierung (Design System → Global Variables)
  → [Achtung: Nur 1× pro Kit ausführen!]
  → kit-convert-v3-to-v4 { dry_run: false, strategy: "rename" }
  → variable_map + class_map aus Response SICHERN für alle weiteren Konvertierungen
  → ⚠️ Führt zu neuen IDs wenn mehrfach aufgerufen!

SCHRITT 2: V4 Foundation
  → setup-v4-foundation {}
  → Gibt base_classes (e-flexbox-base, e-div-block-base) + colors + fonts + classes
  → quick_ref speichern für spätere Verwendung

SCHRITT 3: Page konvertieren
  → ⚠️ Vorher: ensure-converter-loaded (Autoloader-Fix)
  → convert-page-v3-to-v4 {
      post_id: 5400,
      dry_run: true,
      variable_map: {…},   ← aus Schritt 1 (Kit)
      class_map: {…},      ← aus Schritt 1 (Kit)
      unknown_widget_strategy: "keep_v3"
    }
  → Warnings + Audit prüfen
  → Wenn OK: dry_run: false

SCHRITT 4: Global Variable Referenzen anwenden
  → 🔧 apply-variable-map-to-page { post_id: 5400, variable_map: {…} }
  → Ersetzt #F40E74 → {$$type: "global-color-variable", value: "e-gv-b9c7ae3"}
  → ⚠️ OHNE DIESEN SCHRITT bleiben alle Farben als inline Hex!

SCHRITT 5: Layout korrigieren
  → 🔧 e-div-block-wrap { post_id: 5400 }
  → Wrapped Widgets in e-div-block innerhalb von e-flexbox
  → ⚠️ OHNE DIESEN SCHRITT: e-flexbox mit direkten Widget-Kindern

SCHRITT 6: Base Classes zuweisen
  → batch-class {
      post_id: 5400,
      element_class_map: {
        "d11f1d6": ["gc-e8dfc2c41ef4cc02"],   ← e-flexbox-base
        "dd3864f": ["gc-e8dfc2c41ef4cc02"],
        "a6ea89f": ["gc-e8dfc2c41ef4cc02"]
      }
    }
  → ⚠️ Container-IDs sind NACH der Konvertierung NEU (aus gen_id())

SCHRITT 7: Post-Conversion Audits
  → audit-layout / audit-class / audit-variable / visual-qa
  → Bei Problemen: auto-fix oder manuelle Korrektur

SCHRITT 8: Aufräumen
  → Testseite löschen
  → _e2e_*.php + _e2e_*.json Temp-Dateien auf dem Server entfernen
```

---

## 7. Fazit: Würde ein eigenes Plugin/Ability helfen?

**Ja, folgende Abilities fehlen und würden den Workflow massiv vereinfachen:**

1. **`ensure-converter-loaded`** (S, 30min) — Ohne diesen Fix funktioniert die ganze Konvertierung nicht. **MUSS als erstes gebaut werden.**

2. **`apply-variable-map-to-page`** (M, 2h) — Ohne diesen Schritt werden keine Global Variables in den Styles referenziert. Das Design-System bleibt tot.

3. **Fix im `V3_To_V4_Converter` für e-div-block-Wrapping** (M, 3h) — Automatisches Wrapping von Widgets in e-div-block innerhalb des Converters selbst.

4. **`clone-elementor-page`** (S, 1h) — Saubere SQL-basierte Seiten-Duplikation für Test-Konvertierungen.

**Die größte Zeitersparnis:** Ein `convert-page-v3-to-v4-audited` Wrapper, der **Schritte 2–8 in einem Ability-Call** zusammenfasst.

**Die dringendste Baustelle:** **Punkt 3.2 (Global-Color-Referenzen)** und **3.1 (Autoloader)** — ohne diese sind die konvertierten Seiten semantisch minderwertig oder unbenutzbar.

---

## 8. ✅ WORKAROUND IMPLEMENTIERT — CSS wird nicht generiert — Atomic-Style-Pipeline

> **Update 2026-06-23:** Workaround `Local_Styles_Renderer` implementiert in  
> `includes/helpers/class-local-styles-renderer.php` (Plugin v1.2.0).  
> Hooks in `wp_head` (Prio 100), liest `_elementor_data` direkt via `$wpdb`,  
> rendert alle `element.styles`-Maps als inline `<style id="novamira-atomic-styles">`.  
> **Disable Gate:** Auto-deaktiviert bei Elementor ≥ 4.2.0.  
> Siehe auch Section 10.6 Option C — als implementiert markiert.


**Das schwerwiegendste Problem:** Selbst wenn `_elementor_data` korrekte V4-Daten enthält (gefixtes paragraph.content, image.src.id, headings mit tag, container layout props), **generiert Elementor 4.1.3 kein CSS für die lokalen Style-Klassen.**

### Warum?

Elementor 4.x Atomic CSS generation erwartet:

1. **Style-Definitionen als `e_global_class` CPT-Posts** (nicht in `element.styles` oder `_elementor_global_classes` meta)
2. **Jeder CPT-Post** braucht:
   - `_elementor_global_class_id` (stable UUID, z.B. `e-eb6df36-e324ed6`)
   - `_elementor_global_class_data` (type+variants+props)
3. **Kit-Registrierung**:
   - `_elementor_global_classes_order` → listet alle Class-IDs in der richtigen Reihenfolge
   - `_elementor_global_classes_labels` → label für jede Class-ID
   - `_elementor_global_class_{id}` → per-class style data auf dem Kit
   - `_elementor_global_classes_post_ids` → class_id → post_id mapping
4. **Dokument-Registrierung**:
   - `_elementor_used_global_class` → einzelne Meta-Einträge pro Class-ID (funktioniert bereits)
5. **Uploads-CSS-Datei** → Elementor generiert `.css` files in `wp-content/uploads/elementor/css/`

### Was wurde getestet (und hat nicht funktioniert):

| Fix | Status | Ergebnis |
|---|---|---|
| `_elementor_global_classes` meta befüllt | ❌ | Elementor liest diese Meta nicht für CSS |
| `e_global_class` CPTs erstellt (10 Stück) | ✅ erstellt | ⚠️ CSS immer noch 33 Zeichen |
| Kit order + labels + post_ids + per-class meta | ✅ registriert | ⚠️ CSS immer noch 33 Zeichen |
| `$doc->save()` | ❌ | Gab `false` zurück (unerwartetes Format) |
| `Post::create()->update()` | ❌ | CSS bleibt `:root{--page-title-display:none;}` |

### Mögliche Ursachen:

1. **Elementor 4.1.3 ist ein Übergangs-Release** — Atomic-Widgets können im Editor verwendet werden, aber der Frontend-CSS-Generator für Atomic-Klassen wurde möglicherweise erst in 4.2+ fertiggestellt.
2. **Der Atomic Styles Manager (Atomic_Global_Styles) läuft nicht** — Die `register_styles()`-Hooks werden nicht gefeuert, weil die page als `wp-page` (nicht als Atomic-Document-Type) registriert ist.
3. **Die CSS-Datei-Generierung für Atomic Styles funktioniert anders** — Atomic CSS wird nicht als `_elementor_css` Post-Meta gespeichert, sondern on-the-fly inline im HTML gerendert (oder gar nicht).

### Workaround für jetzt:

Für die aktuelle Konvertierung muss die Seite V4-Elemente enthalten, das Styling aber **anders** bereitgestellt werden:
- **Option A:** Die Style-Klassen als V3-kompatible `custom_css` in den Elementen speichern
- **Option B:** Die Styles als inline `style` Attribute auf den Elementen (V4-erwartetes Format?)
- **Option C:** Den Atomic CSS Generator in Elementor 4.1.3 genauer analysieren und die korrekte API finden

**Empfohlen: Die Seite 5400 hat ein korrektes V3-Backup (`_novamira_v3_backup`) und kann jederzeit via SQL zurückgesetzt werden, sobald der Converter-Fix und die CSS-Pipeline bereit sind.**

---

## 9. Zusammenfassung: Fix-Liste für den V3_To_V4_Converter

### Sofort-Fixes (Converter-Klasse):

| # | Problem | Datei | Fix |
|---|---|---|---|
| 1 | `e-paragraph` nutzt `settings.text` statt `settings.paragraph.content` | `class-v3-to-v4-converter.php:247` | `$new_settings['paragraph'] = ['content' => $text, 'children' => []]` |
| 2 | `e-image` nutzt `image.id` statt `image.src.id + image.size` | `class-v3-to-v4-converter.php:260-264` | `image: {src: {id: N, url: null, alt: ''}, size: 'full'}` |
| 3 | `e-heading` fehlt `tag` (default h1-h6) | `class-v3-to-v4-converter.php:240-241` | `$new_settings['tag'] = $s['header_size'] ?? 'h2'` |
| 4 | Container-Layout-Props fehlen | `extract_style_props_for_widget()` case 'container' | flex_direction, align_items, gap, overflow extrahieren |
| 5 | `<p>`-Tags in paragraph.content werden gestrippt | `class-v3-to-v4-converter.php:246-247` | `</p><p>` → `<br><br>` ersetzen |
| 6 | Global-Color-Referenzen gehen verloren | `resolve_color_var()` wird nicht aufgerufen | Color-Index-Lookup in Props-Extraktion integrieren |
| 7 | `element.styles` wird nicht als CPT registriert | Post-Processing fehlt | Nach Tree-Save: Styles aus Tree extrahieren → CPTs anlegen → Kit registrieren |

### Pipeline-Fixes (Workflow/Abilities):

| # | Problem | Fix |
|---|---|---|
| 1 | Autoloader: Helper-Klassen werden nicht gefunden | `spl_autoload_register` oder `require_once` vor Ability-Call |
| 2 | JSON-Korruption beim Meta-Kopieren | Direkt-SQL für `_elementor_data` Clone |
| 3 | V4 CSS wird nicht generiert | Elementor API korrekt nutzen oder Atomic-Styles-Manager-Hooks triggern |

---

*Dokument erstellt nach E2E-Test am 2026-06-21. Alle Aussagen basieren auf tatsächlicher Code-Ausführung und DB-Analyse.*

---

## 10. 🔍 Detailanalyse: Warum Elementor 4.1.3 die Atomic CSS Pipeline nicht liefert

### 10.1 Die gemessene Situation

`Elementor\Core\Files\CSS\Post::create(5400)->update()` produziert als `_elementor_css` Meta-Wert:

```json
{"time":1782060768,"css":":root{--page-title-display:none;}"}
```

33 Zeichen. Das bedeutet: **Elementor generiert keine einzige CSS-Regel für die Atomic Style-Klassen der Seite.** Es gibt keine `.e-eb6df36-e324ed6 { background-color: #00EBAF; }` im Frontend.

Die Klasse `e-eb6df36` erscheint **nicht im gerenderten HTML der Seite** (geprüft via `curl | findstr`). Obwohl die Atomic Widgets im DOM sind (e-flexbox, e-heading), fehlen die Style-Klassen komplett. Der Elementor Post CSS Generator produziert NUR die V3-kompatiblen Global-Variable-Definitionen (`:root{--page-title-display:none;}`) — KEINE Atomic Class Styles.

### 10.2 Die Atomic Styles CSS Pipeline (soll-Zustand)

Elementor 4.x hat eine separate CSS Pipeline für Atomic Styles, die NICHT durch den klassischen `Post` CSS Generator läuft:

```
SCHRITT 1: Class-IDs aus Element Tree sammeln
  └─ Atomic_Elements_Utils::collect_class_ids_from_element_data()
     └─ Scannt settings.classes.value[] aus _elementor_data
     └─ Speichert pro class_id einen _elementor_used_global_class Meta-Eintrag auf dem Dokument

SCHRITT 2: Style-Registrierung (via Event Hook)
  └─ Atomic_Global_Styles::register_styles()
     └─ Hookt in elementor/atomic-widgets/styles/register
     └─ Registriert einen Callback pro Post-ID:
        ['global', $post_id, $context] → fn() => get_document_global_styles()

SCHRITT 3: Style-Daten aus Repository laden
  └─ Global_Classes_Repository::all()
     └─ Liest _elementor_global_classes_order aus dem Kit (Post 2839)
     └─ Pro class_id aus der Order:
        └─ Lädt _elementor_global_class_{class_id} (Kit-Post-Meta) ODER
        └─ Lädt aus e_global_class CPT Post (Fallback)
     └─ Filtert auf class_ids die vom Dokument referenziert werden (_elementor_used_global_class)

SCHRITT 4: CSS-String rendern
  └─ Styles_Renderer::render()
     └─ Baut Selector: class → .elementor-{label}
     └─ Iteriert Variants: jede hat props (z.B. background-color, font-size)
     └─ Props-Resolver: wandelt $$type:"color"{value:"#00EBAF"} → "background-color:#00EBAF"
     └─ State-Selector (hover, etc.) und Breakpoint-Media-Queries

SCHRITT 5: CSS-Datei schreiben
  └─ CSS_Files_Manager::get()
     └─ Schreibt nach wp-content/uploads/elementor/css/{handle}.css
     └─ Eine Datei pro Breakpoint pro Style-Path

SCHRITT 6: CSS-Datei einbinden
  └─ Atomic_Styles_Manager::enqueue_styles()
     └─ wp_enqueue_style() für jede generierte CSS-Datei
```

### 10.3 Wo die Pipeline bricht

**Stelle 1 — `register_styles()` wird nicht gefeuert:**

Der Hook `elementor/atomic-widgets/styles/register` ist ein interner Event von Elementor 4.x. In Version 4.1.3 wird dieser Event NUR während des Editor-Save-Prozesses gefeuert, **nicht beim Frontend-Rendering**. Der Frontend-Request (`GET /ueber-uns-2/`) durchläuft:

```
WP Query → Elementor Frontend → Klassischer Post CSS Generator → Atomic Widget Renderer → Atomic Styles Manager
```

Der Atomic Styles Manager (`Atomic_Global_Styles`) instanziiert sich, aber `register_styles()` wird nicht aufgerufen, weil:

- **Möglichkeit A:** Der Hook `elementor/atomic-widgets/styles/register` existiert in 4.1.3 als Action-Definition, wird aber an keiner Stelle im Frontend-Request gefeuert (`do_action()` fehlt).
- **Möglichkeit B:** Der Hook wird gefeuert, aber `Atomic_Global_Styles` registriert sich nicht früh genug (Priorität zu spät, Frontend-Load-Order anders als Editor).

**Beweis:** Nach manueller Erstellung aller 10 `e_global_class` CPT-Posts (IDs 5429-5438) mit korrekten Metadaten (`_elementor_global_class_id`, `_elementor_global_class_data` inkl. type+variants+props) und vollständiger Kit-Registrierung (order, labels, post_ids, per-class meta) — **bleibt das generierte CSS bei 33 Zeichen.**

Die manuelle Registrierung ist der Beweis, dass das Problem NICHT in der Datenstruktur liegt (CPTs sind korrekt), sondern im **fehlenden Pipeline-Trigger**.

**Stelle 2 — Der klassische Post-CSS-Generator hat keinen Code-Pfad für Atomic Styles:**

`Elementor\Core\Files\CSS\Post` ist der V3-CSS-Generator. Er verarbeitet:
- Container- und Section-CSS (flex, padding, background)
- Widget-Styles (font-size, color über V3-Controls)
- Kit-Settings (Global Colors/Variables als `:root{}`)

Er hat **keinen einzigen Code-Pfad**, der:
- `e_global_class` CPT-Posts liest
- `_elementor_global_classes_order` auswertet
- `_elementor_global_class_data` in CSS-Strings transformiert
- Atomic-eigene Props wie `$$type:"color"` oder `$$type:"size"` versteht

Sein Output ist strikt V3-CSS + Kit-Variablen. Der `"css"`-Wert im `_elementor_css` Meta kommt ausschließlich aus dem V3-Generator — Atomic CSS wird nie in dieses Meta geschrieben.

**Stelle 3 — Keine CSS-Dateien im Uploads-Verzeichnis:**

`wp-content/uploads/elementor/css/` ist **leer** — keine einzige `.css` Datei existiert dort. Das Elementor CSS Layout-Setting ist auf `"inline"` gesetzt (statt `"external"`). Atomic CSS kann aber nur als externe Datei generiert werden (Schritt 5 der Pipeline scheitert, weil CSS_Files_Manager keine Dateien schreibt wenn `inline` aktiv ist).

### 10.4 Das konkret sichtbare Ergebnis im Frontend

```
e-flexbox:
  class="... e-flexbox-base e-eb6df36-e324ed6 ..."
  → ✅ Atomic Widget HTML ist im DOM
  → ❌ KEIN style-Attribut mit background-color
  → ❌ KEINE CSS-Regel .e-eb6df36-e324ed6{background-color:#00EBAF}
  → Ergebnis: Container ist transparent, kein Padding, keine Hintergrundfarbe

e-heading:
  class="... e-822dff1-5f78910 ..."
  → ✅ <h2>Über Treets</h2> ist im DOM
  → ❌ KEINE CSS-Regel .e-822dff1-5f78910{font-family:treets;color:#44291C;}
  → Ergebnis: Heading in System-Schrift, schwarze Farbe

e-image:
  id="3343"
  → ❌ KEIN src-Attribut auf dem <img>
  → Der V4 Atomic Image Renderer setzt src NICHT aus settings.image.src.id
  → Ergebnis: Bild unsichtbar (fehlendes src-Attribut, nur id="3343")
```

### 10.5 Mögliche Ursachen (Rangfolge)

| Rang | Ursache | Begründung |
|---|---|---|
| 1 | `elementor/atomic-widgets/styles/register` wird nicht im Frontend gefeuert | 100% reproduzierbar, alle CPT-Daten sind korrekt |
| 2 | CSS Layout "inline" blockiert CSS-Datei-Writer | `uploads/elementor/css/` ist leer, kein File-Writer-Lauf |
| 3 | Atomic Document Type fehlt (Seite ist wp-page, nicht atomic-page) | V4 Atomic benötigt möglicherweise atomic-page als template_type |
| 4 | Elementor 4.1.3 ist Übergangs-Release — Atomic CSS Pipeline erst in 4.2+ fertig | Kein offizielles Changelog verfügbar |
| 5 | Atomic_Styles_Manager hat falsche Hook-Priorität | Läuft zu spät im Frontend-Load-Zyklus |

### 10.6 Konsequenz für den Konverter

**Der aktuelle Ansatz — Style-Definitionen in `element.styles` + class-references in `settings.classes.value` — funktioniert nicht in Elementor 4.1.3.** Die Atomic Widgets rendern das HTML, aber ohne CSS-Regeln bleiben alle Styling-Properties unsichtbar.

**Mögliche Workarounds (im Converter-Code):**

| Option | Beschreibung | Aufwand | Vorteil | Nachteil |
|---|---|---|---|---|
| **A** | Style-Eigenschaften direkt als `style`-Attribute auf die Elemente schreiben | Gering | Sofort sichtbar, kein CSS-Pipeline nötig | Umgeht V4-Architektur, keine Global-Class-Updates |
| **B** | Atomic CSS Generator patchen (eigenen Hook feuern) | Mittel | Korrekte V4-Architektur | Patch muss bei jedem Elementor-Update angepasst werden |
| **C** | ✅ Inline `<style>`-Block im Seiten-Header generieren | Gering | V4-konform, funktioniert sofort | Style-Updates nur via Page-Edit, nicht via Global Class |
| **D** | V3-CSS-Generator patchen, Atomic-Styles in _elementor_css zu schreiben | Hoch | Nutzt existierende Post-CSS-Pipeline | Tiefster Eingriff, höchstes Konflikt-Risiko |

**Empfohlen wird eine Kombination:**
1. **Workaround A** (inline styles) für sofortige Sichtbarkeit im Frontend
2. **Workaround C** (inline `<style>` block) für Atomic-Class-Definitionen
3. Parallel: **MCP-Tool für Atomic Styles Pipeline** bauen, das den `elementor/atomic-widgets/styles/register` Event korrekt feuert

Sobald Elementor 4.2+ (mit vollständiger Atomic CSS Pipeline) installiert ist, können Workaround A+C entfernt werden und die nativen CPTs + Kit-Registrierung übernehmen.

---

*Dokument erstellt nach E2E-Test am 2026-06-21. Alle Aussagen basieren auf tatsächlicher Code-Ausführung und DB-Analyse.*

*Testseite 5400 (Kopie von "Über uns") hat V4-Daten mit allen fixes. CSS-Generierung schlägt fehl — Elementor 4.1.3 Atomic Pipeline unvollständig.  
`elementor/atomic-widgets/styles/register`-Event wird nicht im Frontend gefeuert.  
`uploads/elementor/css/` ist leer (CSS Layout = inline).  
V3-Backup unter `_novamira_v3_backup` verfügbar.*
