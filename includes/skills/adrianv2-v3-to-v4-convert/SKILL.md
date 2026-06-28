---
name: adrianv2-v3-to-v4-convert
description: Strategy for V3 kit migration and V3 page rebuilds into V4 Atomic, including pre/post audits. One-way trip — irreversible.
---

# AdrianV2 Skill: V3 → V4 Conversion

> **Plugin:** novamira-adrianv2 (Adrian V2 — V2 wegen "zweites Adrian-Plugin", NICHT Elementor V2)
> **Elementor-Welt:** mixed
> **Required Capabilities:** manage_options
> **Required Abilities:** `novamira-adrianv2/detect-elementor-version`, `novamira-adrianv2/kit-convert-v3-to-v4`, `novamira-adrianv2/convert-page-v3-to-v4`, `novamira-adrianv2/batch-build-page`, `novamira-adrianv2/layout-audit`, `novamira-adrianv2/class-audit`, `novamira-adrianv2/design-audit`, `novamira-adrianv2/validate-v4-tree`, `novamira-adrianv2/ensure-atomic-experiments`, `novamira-adrianv2/fix-orphan-styles`, `novamira-adrianv2/clear-cache`, `novamira/elementor-get-content`

## Wann aktivieren

- User fragt: "konvertier meine V3-Seite zu V4", "migrier zu Atomic", "mach aus den alten Containern e-flexbox"
- Eine bestehende V3-Site soll auf V4 Atomic umgestellt werden
- Der User versteht dass die Konvertierung **irreversibel** ist

## ⚠️ Vor der Konvertierung

1. **Backup erzwingen:** `elementor-get-content` auf der Ziel-Seite → JSON lokal speichern
2. **User bestätigen lassen:** "Diese Konvertierung ist irreversibel. Fortfahren?"
3. **Pre-Conversion Audit:** `layout-audit` + `class-audit` → dokumentieren

## Empfohlener Workflow: Vollautomatisch mit `convert-page-v3-to-v4`

### Schritt 1: Neue Zielseite anlegen

```json
{ "ability": "novamira/create-post", "parameters": { "post_type": "page", "status": "draft", "title": "Seitenname – V4 Atomic" } }
```

Dann Page-Template kopieren (als execute-php):
```php
$tpl = get_page_template_slug(SOURCE_ID) ?: 'elementor_canvas';
update_post_meta(TARGET_ID, '_wp_page_template', $tpl);
wp_update_post(['ID' => TARGET_ID, 'post_status' => 'publish']);
```

### Schritt 2: Dry-Run (zur Prüfung)

```json
{
  "ability": "novamira-adrianv2/convert-page-v3-to-v4",
  "parameters": {
    "post_id": 1234,
    "target_post_id": 5678,
    "dry_run": true,
    "run_kit_convert": true,
    "unknown_widget_strategy": "keep_v3",
    "auto_fix": true
  }
}
```
→ Prüfe: `converted`, `kept_v3`, `errors`, `warnings`

### Schritt 3: Live-Konvertierung

```json
{
  "ability": "novamira-adrianv2/convert-page-v3-to-v4",
  "parameters": {
    "post_id": 1234,
    "target_post_id": 5678,
    "dry_run": false,
    "run_kit_convert": true,
    "unknown_widget_strategy": "keep_v3",
    "auto_fix": true
  }
}
```

**⚠️ WICHTIG:** `run_kit_convert: true` nur beim ERSTEN Mal auf einer Site setzen!
Bei weiteren Seiten auf derselben Site `run_kit_convert: false` + die `variable_map`/`class_map`
aus dem ersten Lauf aus dem Memory übergeben — sonst entstehen Duplikat-Variablen. Siehe Gotchas #4.

### Schritt 4: Experiments aktivieren + Cache leeren

```json
{ "ability": "novamira-adrianv2/ensure-atomic-experiments", "parameters": { "ensure": ["e_atomic_elements", "e_nested_atomic_repeaters"], "dry_run": false } }
{ "ability": "novamira-adrianv2/clear-cache", "parameters": { "post_id": 5678, "scope": "css" } }
```

### Schritt 5: Post-Conversion Audit

```json
{ "ability": "novamira-adrianv2/validate-v4-tree", "parameters": { "post_id": 5678 } }
{ "ability": "novamira-adrianv2/layout-audit", "parameters": { "post_id": 5678 } }
{ "ability": "novamira-adrianv2/evaluate-design", "parameters": { "post_id": 5678 } }
{ "ability": "novamira-adrianv2/fix-orphan-styles", "parameters": { "post_id": 5678, "dry_run": false } }
```

→ Mit Pre-Conversion-Scores vergleichen.

---

## Manueller Seitenumbau (wenn automatische Konvertierung nicht ausreicht)

```json
{ "ability": "novamira/elementor-get-content", "parameters": { "post_id": 1234, "full_dump": true } }
```
→ V3-Struktur analysieren und Abschnitt für Abschnitt in einen V4 Atomic Tree umbauen.
→ Global Classes und Variables aus Schritt 2 zuweisen.

```json
{ "ability": "novamira-adrianv2/batch-build-page", "parameters": { "post_id": 1234, "elements": [] } }
```

---

## Was NICHT konvertiert wird (komplett)

| V3 Feature | Status | Lösung |
|---|---|---|
| `heading` | ✅ → `e-heading` | Automatisch |
| `text-editor` | ✅ → `e-paragraph` | Automatisch |
| `button` | ✅ → `e-button` | Automatisch |
| `image` | ✅ → `e-image` | Automatisch |
| `divider` | ✅ → `e-divider` | Automatisch |
| `icon-box` | ⚠️ keep_v3 | Mit Atomic primitives nachbauen |
| `icon` | ⚠️ keep_v3 | → `e-svg` manuell |
| `icon-list` | ⚠️ keep_v3 | → e-flexbox + e-paragraph |
| `counter` | ⚠️ keep_v3 | Kein V4-Äquivalent |
| `rating` | ⚠️ keep_v3 | Kein V4-Äquivalent |
| `testimonial` | ⚠️ keep_v3 | Kein V4-Äquivalent |
| `accordion` | ⚠️ keep_v3 | Kein V4-Äquivalent |
| `elementskit-icon-box` | ⚠️ keep_v3 | Third-party, kein V4-Äquivalent |
| `elementskit-video` | ⚠️ keep_v3 | Third-party, kein V4-Äquivalent |
| `elementskit-accordion` | ⚠️ keep_v3 | Third-party, kein V4-Äquivalent |
| Custom CSS/JS | ⚠️ teilweise | Style-Prop migrieren, Rest als `custom_css.raw` |
| `motion_fx_*` | ❌ verloren | Manuell per `elementor-add-interaction` neu anlegen |
| `shape_divider_*` | ❌ verloren | Kein V4-Äquivalent |
| `background_overlay` (Gradient+Opacity) | ❌ teilweise | Manuell per `::before` custom_css nachbauen |

---

## ⚠️ Praxis-Erkenntnisse (Field-tested, Juni 2026)

Diese Probleme wurden bei der Konvertierung test4.nick-webdesign.de
(post 176 → post 2119, 200 Elemente, 8 Hauptsektionen) entdeckt.

### Problem 1: V3-Tiefenverschachtelung überträgt sich 1:1 → KRITISCH

V3-Seiten nutzen routinemäßig 5–7-stufige Container-Kaskaden:
`Section → Container → Inner Container → Container → Widget`

Die Konvertierung übernimmt das 1:1 in e-flexbox-Nesting.
`layout-audit` flaggt alles über Tiefe 3 als Error (68 Errors bei 200 Elementen).

**Ursache:** V3-Flexbox-Pattern war idiomatisch — jede Spalte brauchte
einen eigenen Container. V4 sollte CSS Grid für Multi-Spalten nutzen,
um Zwischenwrapper zu eliminieren.

**Aktuelles Converter-Verhalten:** Nesting wird 1:1 übernommen, `auto_fix`
flacht es NICHT ab.

**Post-Conversion Workaround:**
1. `layout-audit` laufen → `grid_candidate` Issues identifizieren
2. Equal-width 2/3/4-spaltige Flex-Rows auf CSS Grid umstellen:
```json
{ "ability": "novamira-adrianv2/patch-element-styles",
  "parameters": { "element_id": "CONTAINER_ID", "post_id": 5678,
    "styles": { "custom_css": { "raw": "selector { display: grid; grid-template-columns: repeat(3, 1fr); }" } }
  }
}
```
3. Kinder-Containers können dann auf `width` verzichten → Tiefe sinkt.

**Geplante Converter-Verbesserung:** Equal-width Flex-Rows automatisch als
CSS Grid emittieren. Intermediate Wrapper-Container entfernen wenn der
Eltern-Container bereits ein Grid ist.

---

### Problem 2: Drittanbieter-Widgets (ElementsKit etc.) haben kein V4-Äquivalent

`elementskit-icon-box`, `elementskit-video`, `elementskit-accordion` → kein atomares
Gegenstück. Sie werden als V3 behalten (funktionieren im V4-Kontext, aber der Baum
ist dann gemischt).

Auf typischen ElementsKit-Heavy-Seiten bleiben 30–40% der Widgets als V3.

**Empfehlung nach Konvertierung:** Für jeden kept_v3 Widget prüfen:
- V3 lassen → einfachste Option, funktioniert
- Mit Atomic Primitives nachbauen (e-flexbox + e-svg + e-heading + e-paragraph)
- Custom Atomic Widget via `elementor-create-atomic-widget` erstellen

---

### Problem 3: V3 tablet-only Settings → V4 fehlt mobile Variante

V3-Elemente haben oft `padding_tablet` oder `width_tablet`, aber kein `_mobile`.
Der Converter erstellt V4-Varianten nur für vorhandene V3-Settings. Ergebnis:
Desktop- + Tablet-Variante, aber KEINE Mobile-Variante.

`validate-v4-tree` und `layout-audit` flaggen das als Responsive-Warnung (65+ auf
einer komplexen Seite).

**Manueller Fix** für die wichtigsten Elemente (Hero-Textgröße, Section-Padding):
```json
{ "ability": "novamira/elementor-add-global-class-variant",
  "parameters": {
    "class_id": "CLASS_ID",
    "breakpoint": "mobile",
    "props": { "padding": { "block-start": 32, "block-end": 32, "inline-start": 20, "inline-end": 20 } }
  }
}
```

**Geplante Converter-Verbesserung:** Mobile-Variante auto-generieren wenn
Tablet-Variante vorhanden aber Mobile fehlt (z.B. Tablet-Wert × 0.85 als Startpunkt).

---

### Problem 4: Kit-Konvertierung erzeugt Duplikat-Variablen bei mehrfachem Aufruf

`run_kit_convert: true` bei jeder Seite auf derselben Site → bei der zweiten
Seite entstehen NEUE Variablen (mit leicht anderen Labels), weil die Deduplizierung
nur auf exakte ID-Übereinstimmung prüft, nicht auf Label-Ähnlichkeit.

Nach 2 Konvertierungen: 45+ Variablen statt 20.

**Prävention:**
- `run_kit_convert: true` nur EINMAL pro Site
- `variable_map` + `class_map` aus dem ersten Lauf im Memory speichern
- Folge-Seiten mit `variable_map` aus Memory konvertieren

**Wenn bereits passiert:** `variable-audit` → Drift finden → `design-token-remap` konsolidieren.

---

### Problem 5: V3 `background_overlay` (Gradient-Overlay) geht teilweise verloren

V3 unterstützt unabhängige Overlay-Kontrolle: Farbe + Gradient + Opacity auf Containern.
V4's `background` Style Prop unterstützt `background-image-overlay` für Bilder,
aber NICHT:
- Unabhängige Overlay-Opacity vs. Haupt-Hintergrund
- Gradient-Overlays mit Blend-Modes
- `background_overlay_attachment: fixed` (Parallax-Effekt)

**Impact:** Hero-Sections mit Hintergrundbild + Farb-Overlay können nach Konvertierung
anders aussehen (Overlay fehlt oder Bild ist zu hell).

**Manueller Fix für Hero-Overlays:**
```json
{ "ability": "novamira-adrianv2/patch-element-styles",
  "parameters": {
    "element_id": "HERO_ID", "post_id": 5678,
    "styles": {
      "custom_css": { "raw": "selector::before { content: ''; position: absolute; inset: 0; background: linear-gradient(360deg, rgba(14,47,42,0.7) 0%, rgba(23,79,70,0.36) 100%); z-index: 0; } selector { position: relative; }" }
    }
  }
}
```

---

### Problem 6: Motion Effects (`motion_fx_*`) werden still gedroppt

V3 hat ein eingebautes Motion-Effects-System (Scroll-Trigger-Transforms, Parallax, Sticky).
Der Converter entfernt diese still — kein Error, kein Warning.

**Erkennung:** Seiten mit Parallax/Sticky-Sections sehen nach Konvertierung flach aus.
V3-Quelle auf `motion_fx_*` in den Container-Settings prüfen.

**Manueller Fix:** Per `elementor-add-interaction` auf V4-Elementen neu anlegen.
Unterstützt: load, scrollIn, scrollOut, hover, click-Trigger mit fade/slide/scale-Effekten.

---

## Gotchas

- **Irreversibel**: Es gibt KEIN `kit-convert-v4-to-v3`. Backup vorher ist Pflicht.
- **Kit ≠ Page**: `kit-convert-v3-to-v4` = Design Tokens & Global Classes. `convert-page-v3-to-v4` = Seitenstruktur (Sections, Columns, Widgets). Beide sind getrennte Abilities mit klar unterschiedlichen Aufgaben.
- **Schriftskalierung**: V3 `typography_*`-Werte werden zu V4 `styles.font-size` — prüfen ob die Einheiten (px/em/rem) korrekt übernommen wurden.
- **Column→Div-Block Nesting**: V3 hatte `section > column > widget`; V4 hat `e-flexbox > e-div-block > widget`. Die Verschachtelung wird automatisch erzeugt, kann aber bei tiefen V3-Strukturen die V4-Tiefengrenze (3) überschreiten. Siehe Praxis-Erkenntnisse #1.
- **Experiments nach Konvertierung prüfen**: `e_nested_atomic_repeaters` ist oft nicht aktiv → immer `ensure-atomic-experiments` laufen lassen. Sonst rendert Elementor bestimmte V4-Widgets nicht korrekt.
- **Kit-Konvertierung: NUR EINMAL pro Site**: Mehrfaches `run_kit_convert: true` erzeugt Duplikat-Variablen. Variable-Map im Memory speichern und weitergeben. Siehe Praxis-Erkenntnisse #4.
- **Visual Diff nach Konvertierung**: Das `site-clone-to-v3` Tool kann V3 vs. V4 visuell vergleichen (runV3V4Diff). Setzt voraus, dass der Screenshot-Server Netzwerkzugang zur Site hat. Bei Sandbox-Umgebungen ohne Egress-Zugang muss stattdessen `evaluate-render-context` + `validate-v4-tree` genutzt werden.
