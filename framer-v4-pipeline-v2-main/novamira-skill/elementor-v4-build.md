---
slug: elementor-v4-build
title: Elementor V4 Atomic Builder
description: Expert workflow for building and rebuilding Elementor pages using V4 Atomic Widgets with Novamira AdrianV2 Abilities. Use whenever a V3 page needs V4 rebuild, a new page should use Atomic Widgets only, Global Classes or Variables are needed, or batch-build-page is called. This skill is the "ground truth" for novamira-adrianv2/* ability names on solar.local.
version: "2.0"
tags: [elementor, v4, atomic, build, novamira, adrianv2]
---

# Elementor V4 Atomic Page Builder

Dieses Skill beschreibt das vollständige System zum Bauen von Elementor V4 Seiten
mit Atomic Widgets, Global Classes und Variables via Novamira MCP (AdrianV2 Plugin).

**Ground Truth für solar.local:** Alle `novamira/adrians-*` Ability-Namen existieren NICHT mehr.
Sie heißen jetzt `novamira-adrianv2/*` (ohne "adrians-" Prefix).

---

## Architektur-Entscheidung

ALLE Framer-Trees werden via `novamira/elementor-set-content` gebaut (NICHT batch-build-page).
Normale Seiten (Screenshot, Website Creator, manuelle Builds) verwenden `novamira-adrianv2/batch-build-page`.

**Framer-Pipeline:**
```
novamira-adrianv2/setup-v4-foundation { post_id }
novamira/elementor-set-content { post_id, content: [...] }
```

**Normale Seiten:**
```
novamira-adrianv2/setup-v4-foundation { post_id, create_missing: true }
novamira-adrianv2/batch-build-page { post_id, title, status, elements: [...] }
```

---

## Kritische Regeln (niemals brechen)

1. `setup-v4-foundation` NIEMALS cachen (GV-IDs + GC-IDs sind session-live)
2. `export-design-system` DARF 5 Minuten gecacht werden (read-only)
3. NIEMALS `url: null` in image-src → url-Key komplett weglassen
4. Style-IDs OHNE Hyphens: `shero` nicht `s-hero`
5. Visuelle Props NUR in `styles`, nie in `settings`
6. `custom_css` immer `{"raw":"..."}` Format, nie plain String

---

## Layout-Entscheidungsbaum (KRITISCH - vor jedem Abschnitt beachten!)

**HARDREGEL: Maximale DOM-Tiefe = 3 Ebenen**
```
Ebene 1: Abschnitt (section)    → e-div-block mit display:grid oder display:flex
Ebene 2: Spalte/Zelle           → e-div-block oder e-flexbox für Inhalt einer Zelle
Ebene 3: Widget                 → e-heading, e-paragraph, e-button etc.
```
Wenn du mehr als 3 Ebenen planst: STOP. Du löst es mit dem falschen Layout-Werkzeug.

### Welches Container-Element wofür?

| Szenario | Element | display | Warum |
|---|---|---|---|
| 2- oder 3-Spalten nebeneinander | `e-div-block` | `grid` | 2D-Layout → Grid |
| Karten-Raster (gleiche Größe) | `e-div-block` | `grid` | 2D-Layout → Grid |
| Widgets untereinander (vertikal) | `e-div-block` | `flex`, `flex-direction:column` | 1D → Flex |
| Icon + Text in einer Zeile | `e-flexbox` ODER flex-direction auf Eltern | `flex`, `flex-direction:row` | 1D → Flex |
| Hintergrund-Ebene | KEIN eigenes Element! | Direkt als Style-Prop | Kein Wrapper-Element |
| Overflow:hidden | KEIN eigenes Element! | Direkt als Style-Prop auf Eltern | Kein Wrapper-Element |
| Inhalt zentrieren | `e-div-block` | `grid`, `place-items:center` | Einfacher als Flex-Trick |
| Background-Image-Section | `e-div-block` | `grid` oder `flex` + background Style | Hintergrund ist Prop, kein Element |

### Grid vs. Flexbox - Die Grundregel

```
NUR EINE RICHTUNG (reine Zeile ODER reine Spalte)?  → Flexbox (e-flexbox)
ZWEI RICHTUNGEN gleichzeitig (Zeilen UND Spalten)?  → CSS Grid (e-div-block mit display:grid)
PASST IN KEIN SCHEMA + max. 1 Kind?                 → e-div-block (block, kein display:flex/grid)
```

### Typische Abschnitt-Muster (fertig zum Kopieren)

**Hero: Text links, Bild rechts (2 Spalten)**
```
e-div-block [section-wrapper]
  display: grid
  grid-template-columns: 1fr 1fr
  gap: 60px
  padding: 120px 80px
  |
  +-- e-div-block [text-col]
  |     display: flex, flex-direction: column, gap: 24px
  |     |
  |     +-- e-heading (h1)
  |     +-- e-paragraph
  |     +-- e-button
  |
  +-- e-div-block [image-col]
        (Bild als Background oder e-image direkt)
```
DOM-Tiefe: 3. Kein hero-bg, kein hero-overflow, kein hero-content-row.

**Kicker (Icon + Text in einer Zeile) - KEIN extra Wrapper!**
```
Der Kicker lebt als flex-row DIREKT in der text-col:
  e-flexbox [kicker] ODER text-col bekommt align-items:flex-start
  display: flex, flex-direction: row, align-items: center, gap: 8px
  |
  +-- e-svg (Icon, 18x18px)
  +-- e-heading (tag:span, Kicker-Text)
```
DOM-Tiefe: 2 ab text-col.

**Stats-Grid (3 oder 4 gleichgroße Kacheln)**
```
e-div-block [stats]
  display: grid
  grid-template-columns: repeat(3, 1fr)
  gap: 32px
  |
  +-- e-div-block [stat-item]  (3x)
        display: flex, flex-direction: column, gap: 8px
        |
        +-- e-heading (Zahl, h3)
        +-- e-heading (Label, tag:p)
```
DOM-Tiefe: 3.

**Feature-Cards in einer Reihe**
```
e-div-block [cards]
  display: grid
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr))
  gap: 24px
  |
  +-- e-div-block [card]  (N x)
        padding, border-radius, background
        display: flex, flex-direction: column, gap: 16px
        |
        +-- e-svg (Icon)
        +-- e-heading (Title)
        +-- e-paragraph (Text)
```

---

## Anti-Patterns (NIEMALS so bauen)

### ❌ Background-Layer als eigenes Element
```json
// FALSCH: Wrapper nur für Hintergrund
{ "widgetType": "e-flexbox", "id": "hero",
  "elements": [
    { "widgetType": "e-flexbox", "id": "hero-bg" },
    { "widgetType": "e-flexbox", "id": "content" }
  ]
}
```
**Richtig:** Background direkt als Style-Prop auf `hero` setzen.

### ❌ Overflow-Wrapper
```json
// FALSCH: overflow:hidden als separater Wrapper
{ "widgetType": "e-flexbox", "id": "col",
  "elements": [
    { "widgetType": "e-flexbox", "id": "overflow-wrapper",
      "elements": [{ "widgetType": "e-flexbox", "id": "text" }]
    }
  ]
}
```
**Richtig:** `overflow: hidden` direkt auf `col` als Style-Prop.

### ❌ Kicker-Row als separate Ebene
```json
// FALSCH wenn text-col bereits Flex ist und kicker-row nur 1 Kind ist
{ "widgetType": "e-flexbox", "id": "text-col",
  "elements": [
    { "widgetType": "e-flexbox", "id": "kicker-row",
      "elements": [{ "widgetType": "e-svg" }, { "widgetType": "e-heading" }]
    }
  ]
}
```
**Richtig:** e-svg und e-heading direkt als Kinder von text-col mit `flex-direction:row`.

### ❌ "Accent-Col" als Wrapper einer einzelnen Spalte
```json
// FALSCH: Wrapper der nur 1 Kind hat
{ "widgetType": "e-flexbox", "id": "col",
  "elements": [
    { "widgetType": "e-flexbox", "id": "accent-col",
      "elements": [{ "widgetType": "e-flexbox", "id": "content" }]
    }
  ]
}
```
**Richtig:** Direkt das Content-Element, oder Grid-Zelle ohne Zwischen-Wrapper.

### ❌ Flexbox für 2D-Layout erzwingen
```json
// FALSCH für 3-spaltig mit festen Breiten
{ "widgetType": "e-flexbox", "id": "row",
  "elements": [
    { "widgetType": "e-flexbox", "id": "col1", "styles": { "width": "33%" } },
    { "widgetType": "e-flexbox", "id": "col2", "styles": { "width": "33%" } },
    { "widgetType": "e-flexbox", "id": "col3", "styles": { "width": "33%" } }
  ]
}
```
**Richtig:** `e-div-block` mit `display:grid; grid-template-columns: 1fr 1fr 1fr`.

---

## Verfügbare Atomic Widgets

| Widget Type            | Props (settings)                          | Wann benutzen          |
|------------------------|-------------------------------------------|------------------------|
| `e-heading`            | `title`, `tag`, `classes`, `link`         | Alle Überschriften    |
| `e-paragraph`          | `paragraph`, `tag`, `classes`, `link`     | Fließtext / Body       |
| `e-button`             | `text`, `link`, `tag`, `classes`          | CTAs, Links            |
| `e-image`              | `image`, `link`, `classes`                | Bilder                 |
| `e-svg`                | `svg`, `link`, `classes`                  | Icons, SVG-Grafiken    |
| `e-divider`            | `classes`                                 | Trennlinien            |
| `e-youtube`            | `source`, `autoplay`, `mute`, `classes`   | YouTube Videos         |
| `e-self-hosted-video`  | `source`, `autoplay`, `controls`, etc.    | Eigene Videos          |

**REGEL:** Immer Atomic Widgets verwenden. Kein `heading`, `text-editor`, `html` (V3).
Wenn kein passendes Atomic Widget existiert → Custom Atomic Widget entwickeln.

---

## Das $$type System

Jeder Wert in `settings` und `styles.props` ist typisiert:

```json
{ "$$type": "string",   "value": "h1" }
{ "$$type": "boolean",  "value": true }
{ "$$type": "html-v3",  "value": { "content": { "$$type": "string", "value": "Mein Text" }, "children": [] } }
{ "$$type": "size",     "value": { "size": 48, "unit": "px" } }
{ "$$type": "color",    "value": "#FF0101" }
{ "$$type": "global-color-variable", "value": "e-gv-XXXXXXX" }
{ "$$type": "global-size-variable",  "value": "e-gv-XXXXXXX" }
{ "$$type": "global-font-variable",  "value": "e-gv-XXXXXXX" }
{ "$$type": "classes",  "value": ["gc-XXXXX", "s-local-id"] }
{ "$$type": "link",     "value": { "href": { "$$type": "string", "value": "#" }, "isTargetBlank": { "$$type": "boolean", "value": false }, "tag": { "$$type": "string", "value": "a" } } }
```

Dimensions (padding/margin):
```json
{ "$$type": "dimensions", "value": {
    "block-start":  { "$$type": "size", "value": { "size": 60, "unit": "px" } },
    "block-end":    { "$$type": "size", "value": { "size": 60, "unit": "px" } },
    "inline-start": { "$$type": "size", "value": { "size": 40, "unit": "px" } },
    "inline-end":   { "$$type": "size", "value": { "size": 40, "unit": "px" } }
}}
```

---

## Vollständige Widget-Struktur (Datenbankformat)

```json
{
  "id": "hero-title",
  "elType": "widget",
  "widgetType": "e-heading",
  "settings": {
    "classes": { "$$type": "classes", "value": ["gc-GLOBAL_ID", "s-local-id"] },
    "tag":     { "$$type": "string",  "value": "h1" },
    "title":   { "$$type": "html-v3", "value": { "content": { "$$type": "string", "value": "Überschrift" }, "children": [] } },
    "link":    { "$$type": "link",    "value": [] }
  },
  "styles": {
    "s-local-id": {
      "id": "s-local-id",
      "type": "class",
      "label": "Base",
      "variants": [
        {
          "meta": { "breakpoint": "desktop", "state": null },
          "props": {
            "color":     { "$$type": "global-color-variable", "value": "e-gv-XXXXXXX" },
            "font-size": { "$$type": "size", "value": { "size": 60, "unit": "px" } }
          },
          "custom_css": null
        },
        {
          "meta": { "breakpoint": "mobile", "state": null },
          "props": {
            "font-size": { "$$type": "size", "value": { "size": 36, "unit": "px" } }
          },
          "custom_css": null
        }
      ]
    }
  },
  "elements": []
}
```

**Kritisch:**
- `settings` enthält KEINE Style-Werte (das wäre V3). Styles gehören in `styles`.
- `settings.classes` referenziert BEIDE: globale Klassen (`gc-`) UND lokale Styles (`s-`)
- Lokale Styles werden im `styles` Objekt des gleichen Widgets definiert
- Global Classes müssen vorher existieren (via `novamira/elementor-create-global-class`)

---

## Klassen-System (3 Ebenen - Priorität beachten)

### Ebene 1: Global Variables (Design Tokens)
ID-Format: `e-gv-XXXXXXX` (7 Hex-Chars nach `e-gv-`)
Referenz in style props: `{ "$$type": "global-color-variable", "value": "e-gv-ID" }`
Abilities: `novamira/elementor-list-variables`, `novamira/elementor-create-variable`, `novamira-adrianv2/batch-create-variables`

### Ebene 2: Global Classes (Kit-weit, wiederverwendbar)
ID-Format: `gc-XXXXXXXXXXXXXXXXX` (lange Hex-ID)
Referenz in settings.classes: `{ "$$type": "classes", "value": ["gc-ID"] }`
Sollen Variables referenzieren, KEINE Hardcode-Hex-Werte!
Abilities: `novamira/elementor-list-global-classes`, `novamira/elementor-create-global-class`

### Ebene 3: Local Styles (Widget-spezifisch)
ID-Format: `s-` + beliebiger Name (z.B. `s-hero-title`)
Im `styles` Objekt des Widgets definiert, in `settings.classes` referenziert.
Nur für einmalige Widget-spezifische Overrides.

**Aufbau-Reihenfolge:**
1. Variables anlegen (Farben, Fonts, Größen)
2. Global Classes erstellen die Variables referenzieren
3. Seite aufbauen, Global Classes in `settings.classes` zuweisen
4. Nur für Ausnahmen: Local Styles im `styles` Objekt

---

## Pre-Build Pipeline Scripts (v0.8.0)

Die Framer→Elementor Pipeline wurde um folgende Features erweitert:

### Pre-Flight Checks

| Script | Neues Feature | Beschreibung |
|--------|--------------|-------------|
| `check-v4-requirements.js` | `--server-info` | Prüft `php_max_input_vars` (≥5000), `memory_limit` (≥256M), Tree-Größe (>500KB Warnung) |
| `framer-pre-build-validate.js` | 13. Guard `GC_POTENTIAL` | Zählt Style-Duplikate im Tree, warnt bei >10, blockt Build bei >20 |
| `validate-v4-tree.js` | 7. Check `DOM-DEPTH` | Maximale Nesting-Tiefe: ≤3 OK, 4-5 Warnung, ≥6 Error (Server-Timeout-Risiko) |

### Konvertierung & GC

| Script | Neues Feature | Beschreibung |
|--------|--------------|-------------|
| `convert-xml-to-v4.js` | `--gc` jetzt Default `true` | GC-Generierung läuft automatisch. `--no-gc` zum Deaktivieren |
| `convert-xml-to-v4.js` | RC-08 Root-Container-Schutz | Root-Container (depth=0) behalten ihre Positionierung |
| `generate-global-classes.js` | `--apply` Modus | Lokale Tree-Deduplizierung ohne MCP: ersetzt Style-Duplikate durch GC-Referenzen |

### Post-Build QA & Fixes

| Script | Neues Feature | Beschreibung |
|--------|--------------|-------------|
| `run-post-build-qa.js` | `--tree` Deep-Checks | 4 neue Checks: GC_COVERAGE (≥60%), DOM_DEPTH (≤5), RESPONSIVE_COVERAGE (≥30%), UNUSED_STYLES (0) |
| `post-build-auto-fix.js` | `--fix-dom-depth` | Rekursives Flatten von Single-Child-Pass-Through-Containern. `--max-depth 3` (Default) |

### Responsive & Animation

| Script | Neues Feature | Beschreibung |
|--------|--------------|-------------|
| `extract-responsive-breakpoints.js` | `--container-queries` | @container Query Support (modernes CSS) zusätzlich zu @media |
| `framer-animation-extractor.js` | RC-20 Extended Mapping | +6 Einträge: rotate, skew, opacity+translateX, opacity+scale, opacity+rotate, Triple-Compound |
| `parallel-pre-build.js` | `--gc-output` | Custom Pfad für GC-Plan (statt hardcoded `gc-plan.json`) |

---

## Breakpoints

| Key       | Viewport         |
|-----------|-----------------|
| `desktop` | 1025px+          |
| `tablet`  | 768px - 1024px   |
| `mobile`  | 0 - 767px        |

States: `null`, `hover`, `focus`, `active`

Immer alle 3 Breakpoints definieren wenn responsive Unterschiede existieren.

---

## V3 → V4 Mapping

### Widget-Mapping

| V3 Widget     | V4 Atomic Equivalent              | Notiz                              |
|---------------|-----------------------------------|------------------------------------|
| `heading`     | `e-heading`                       | title → html-v3 Format            |
| `text-editor` | `e-paragraph`                     | editor → paragraph html-v3        |
| `button`      | `e-button`                        | text → html-v3, link separat      |
| `image`       | `e-image`                         | image → image $$type              |
| `divider`     | `e-divider`                       | Nur Styling via classes            |
| `video`       | `e-youtube` / `e-self-hosted-video` | Je nach Quelle                   |
| `icon`        | `e-svg`                           | SVG direkt oder Media Library      |
| `spacer`      | e-div-block mit min-height Style  | Kein spacer Widget                 |
| `html`        | KEIN Equivalent → Custom Widget  | Eigene Atomic Widget Klasse bauen  |
| `icon-list`   | KEIN Equivalent → Custom Widget  | Eigene Atomic Widget Klasse bauen  |
| `counter`     | KEIN Equivalent → Custom Widget  | Eigene Atomic Widget Klasse bauen  |

### Struktur-Mapping (WICHTIG - häufigste Fehlerquelle)

| V3 Struktur                        | Schlechte V4-Umsetzung           | Richtige V4-Umsetzung              |
|------------------------------------|----------------------------------|------------------------------------|
| Section > Column > Column          | 3 verschachtelte e-flexbox       | 1 e-div-block mit display:grid     |
| Section mit Background-Image       | e-flexbox [bg] + e-flexbox [con] | 1 e-div-block, Background als Prop |
| Section > Inner Section > Columns  | 4+ Ebenen e-flexbox              | 2 Ebenen: grid-Container > flex-Col|
| 3 gleich breite Spalten            | e-flexbox width:33% x3           | grid-template-columns:1fr 1fr 1fr  |
| Icon + Text nebeneinander (Kicker) | Eigenes e-flexbox als Wrapper    | flex-direction:row auf Eltern      |
| overflow:hidden Abschnitt          | Eigenes e-flexbox als Wrapper    | overflow:hidden als Style-Prop     |

### Konvertierungs-Checkliste pro Abschnitt
```
Vor dem Bauen jeden Abschnitts:
[ ] Wie viele Spalten? → Wenn 2+: e-div-block mit display:grid verwenden
[ ] Hintergrundbild/-farbe? → Als Style-Prop, kein Extra-Wrapper-Element
[ ] overflow:hidden nötig? → Als Style-Prop, kein Wrapper-Element
[ ] Kicker (Icon+Text)? → flex-direction:row auf dem nächsten Elternelement
[ ] DOM-Tiefe > 3? → Layout-Ansatz überdenken, Grid einsetzen
```

---

## Pflicht-Workflows

### A) Neue Seite von Grund auf

```
1. Kit prüfen         → novamira/elementor-check-setup (atomic.runtime_available prüfen!)
2. Foundation          → novamira-adrianv2/setup-v4-foundation { post_id, create_missing: true }
3. Design System       → novamira/elementor-list-variables + novamira/elementor-list-global-classes
   Falls leer/neu      → novamira-adrianv2/kit-convert-v3-to-v4 ODER novamira-adrianv2/batch-create-variables + create-global-class
4. Layout planen       → ERST Entscheidungsbaum (oben), DANN Element-Tree
5. Seite bauen         → novamira-adrianv2/batch-build-page (erstellt + baut in einem Call)
6. Page-Settings       → novamira-adrianv2/page-settings (Template, hide_title, body_classes)
7. QA ausführen        → novamira-adrianv2/page-audit + novamira-adrianv2/visual-qa + novamira-adrianv2/responsive-audit
8. Iterativ patchen    → novamira-adrianv2/patch-element-styles (niemals für Korrekturen neu aufbauen!)
```

### B) V3-Seite nach V4 konvertieren

```
1. Kit prüfen         → novamira/elementor-check-setup
2. Design System       → novamira-adrianv2/kit-convert-v3-to-v4 (falls noch nicht geschehen)
3. Seite analysieren   → novamira/elementor-get-content (Skeleton), dann je Abschnitt full_dump
4. Abschnitt für Abschnitt konvertieren → add-element tree: (transformierter Baum)
5. QA                  → novamira-adrianv2/page-audit + novamira-adrianv2/visual-qa + novamira-adrianv2/responsive-audit
6. Korrekturen         → novamira-adrianv2/patch-element-styles
```

### C) HTML / Screenshot / Figma → V4

```
1. Quelle analysieren  → novamira-adrianv2/html-to-elementor-widget-plan (HTML-Input)
2. Foundation          → novamira-adrianv2/setup-v4-foundation { create_missing: true }
3. Design System       → wie A), Schritt 3
4. Seite bauen         → novamira-adrianv2/batch-build-page basierend auf dem Widget-Plan
5. QA + Patch          → wie A), Schritt 7+8
```

### D) Design-System von Null aufbauen

Reihenfolge ist zwingend:
```
1. Farb-Variablen       → novamira-adrianv2/batch-create-variables (type:color, alle Markenfarben)
2. Font-Variablen       → novamira-adrianv2/batch-create-variables (type:font, alle Schriftfamilien)
3. Größen-Variablen     → novamira-adrianv2/batch-create-variables (type:size, Spacings + Font-Sizes)
4. Global Classes       → novamira/elementor-create-global-class (referenziert Variablen, keine Hex-Werte!)
5. Variable in Klasse   → novamira-adrianv2/apply-variable-to-class (Binding erstellen)
6. Responsive Variante  → novamira-adrianv2/add-global-class-variant (tablet/mobile Overrides)
7. Export sichern       → novamira-adrianv2/export-design-system (Backup vor Seiten-Arbeit)
```

REGEL: Global Classes enthalten NIEMALS Hardcode-Hex-Farben. Immer Variable-Referenzen.
PRÜFUNG: `novamira-adrianv2/list-class-variants` zeigt ob Variablen korrekt gebunden sind.

---

## Iterativer Patch-Workflow

**WICHTIG: nie neu aufbauen für Korrekturen.**
Nach dem ersten Build: `novamira-adrianv2/patch-element-styles` verwenden.

```
Schritt 1: Element-ID kennen   → aus batch-build-page Rückgabe oder elementor-get-content
Schritt 2: Patch erstellen     → { element_id, style_id, breakpoint, state, props }
Schritt 3: Ausführen           → novamira-adrianv2/patch-element-styles
```

Patch-Fähigkeiten:
- `props`: Style-Props ändern/ergänzen (pro Breakpoint + State)
- `settings`: Widget-Settings ändern (title, tag, classes)
- `custom_css`: Raw CSS pro Variant injizieren
- `add_style`: Neuen Style-Eintrag zum Element hinzufügen
- `add_class`: Global Class ID zum Element hinzufügen

Beispiel: Nur die mobile Schriftgröße einer Überschrift anpassen:
```json
{
  "post_id": 4520,
  "patches": [{
    "element_id": "hero-title",
    "style_id": "s-hero-title-base",
    "breakpoint": "mobile",
    "state": null,
    "props": {
      "font-size": { "$$type": "size", "value": { "size": 32, "unit": "px" } }
    }
  }]
}
```

---

## QA-Workflow (nach jedem Build)

Jede neue/konvertierte Seite MUSS diese 5 Checks durchlaufen:

```
1. novamira-adrianv2/layout-audit { post_id }
   Prüft: Pass-through, Deep-Nesting, Grid-Kandidaten

2. novamira-adrianv2/visual-qa { post_id, breakpoints: ["desktop","tablet","mobile"] }
   Prüft: Overflow-Risiken, Z-Index-Konflikte, Negative Margins, Overlap

3. novamira-adrianv2/responsive-audit { post_id }
   Prüft: Breakpoint-Varianten, Sichtbarkeit pro Breakpoint, V4 vs V3 Responsive-Settings

4. novamira-adrianv2/variable-audit { report: "drift" }
   Prüft: e-gv-* Drift zwischen Kit und Tree

5. novamira-adrianv2/class-audit { scope: "post_ids", post_ids: [ID] }
   Prüft: Unused Global Classes, fehlende Class-Referenzen
```

Kritische Findings → sofort mit `novamira-adrianv2/patch-element-styles` beheben, nicht neu aufbauen.

---

## Vollständige Ability-Referenz — Novamira AdrianV2

### Setup & Foundation (PFLICHT vor jedem Build)

| Ability | Zweck |
|---------|-------|
| `novamira/elementor-check-setup` | V4 Atomic Verfügbarkeit prüfen |
| `novamira-adrianv2/setup-v4-foundation` | GV-IDs + GC-IDs + Base-Classes (NIEMALS cachen!) |

### Design System & Variables

| Ability | Zweck |
|---------|-------|
| `novamira/elementor-list-variables` | Alle Global Variables (e-gv-XXX IDs) |
| `novamira/elementor-create-variable` | Eine Variable einzeln anlegen |
| `novamira/elementor-edit-variable` | Variable bearbeiten (Label oder Wert) |
| `novamira/elementor-delete-variable` | Variable löschen |
| `novamira-adrianv2/batch-create-variables` | Mehrere Variables auf einmal (strategy: "skip") |
| `novamira-adrianv2/apply-variable-to-class` | Variable in Global Class binden |
| `novamira-adrianv2/export-design-system` | Design-System exportieren (Backup) |
| `novamira-adrianv2/import-design-system` | Design-System aus JSON importieren |

### Global Classes

| Ability | Zweck |
|---------|-------|
| `novamira/elementor-list-global-classes` | Alle GCs (gc-XXX IDs) |
| `novamira/elementor-create-global-class` | Neue GC anlegen |
| `novamira/elementor-edit-global-class` | GC bearbeiten (label oder styles) |
| `novamira/elementor-delete-global-class` | GC löschen |
| `novamira/elementor-apply-global-class` | GC auf Atomic Widget anwenden |
| `novamira-adrianv2/add-global-class-variant` | Responsive/State Variant hinzufügen |
| `novamira-adrianv2/edit-global-class-variant` | Bestehende Variant bearbeiten |
| `novamira-adrianv2/list-class-variants` | Varianten einer GC inspizieren |
| `novamira-adrianv2/batch-class` | GC auf mehrere Elemente gleichzeitig anwenden |
| `novamira-adrianv2/remove-global-class` | GC von Element entfernen |
| `novamira-adrianv2/class-audit` | Unused + fehlende Class-Referenzen |

### V3 Global Styles (nur lesen/migrieren)

| Ability | Zweck |
|---------|-------|
| `novamira/elementor-list-v3-styles` | V3 Farben + Typographie lesen |
| `novamira/elementor-create-v3-color` | V3 Farbe anlegen (nur wenn benötigt) |
| `novamira/elementor-edit-v3-color` | V3 Farbe bearbeiten |
| `novamira/elementor-create-v3-typography` | V3 Typographie-Preset anlegen |
| `novamira-adrianv2/kit-convert-v3-to-v4` | V3 Kit → V4 Variables + Classes (BEVORZUGT!) |

### Seite bauen & bearbeiten

| Ability | Zweck |
|---------|-------|
| `novamira-adrianv2/batch-build-page` | Komplette Seite in einem Call (NICHT für Framer!) |
| `novamira/elementor-add-element` | Einzelnes Element einfügen (tree: für Subtree) |
| `novamira/elementor-edit-element` | Element-Settings ändern (partial merge) |
| `novamira/elementor-set-content` | Kompletten Seitenbaum ersetzen (Framer-Trees!) |
| `novamira/elementor-get-content` | Content auslesen (Skeleton + full_dump) |
| `novamira-adrianv2/batch-get-content` | N Posts in einem Call (max 50, modes: skeleton/settings/full) |
| `novamira/elementor-delete-element` | Element löschen |
| `novamira-adrianv2/patch-element-styles` | Iterative Korrekturen (Style, Settings, CSS) |
| `novamira-adrianv2/page-settings` | Template, hide_title, custom_css, body_classes |
| `novamira-adrianv2/get-page-markdown` | Seite als Markdown (Elementor 4.1+) |

### Atomic Widgets (Einzeln)

| Ability | Widget |
|---------|--------|
| `novamira-adrianv2/add-flexbox` | e-flexbox direkt |
| `novamira-adrianv2/add-div-block` | e-div-block direkt |
| `novamira-adrianv2/add-atomic-heading` | e-heading direkt |
| `novamira-adrianv2/add-atomic-paragraph` | e-paragraph direkt |
| `novamira-adrianv2/add-atomic-button` | e-button direkt |
| `novamira-adrianv2/add-atomic-image` | e-image direkt |
| `novamira-adrianv2/add-atomic-svg` | e-svg direkt |
| `novamira-adrianv2/add-atomic-divider` | e-divider direkt |

### Elemente kopieren & wiederverwenden

| Ability | Zweck |
|---------|-------|
| `novamira-adrianv2/clone-element` | Element + Kinder kopieren |
| `novamira-adrianv2/reorder-element` | Element innerhalb/zwischen Parents verschieben |
| `novamira-adrianv2/duplicate-page` | Ganze Seite duplizieren |
| `novamira-adrianv2/list-elementor-pages` | Alle Elementor-Seiten auflisten (mit V4-Stats) |
| `novamira/elementor-apply-dynamic-tag` | Dynamic Tag auf Widget-Setting anwenden |

### Media

| Ability | Zweck |
|---------|-------|
| `novamira-adrianv2/batch-media-upload` | Mehrere Dateien (max 30, 10MB/Datei) |
| `novamira-adrianv2/media-upload` | Einzelne Datei hochladen |
| `novamira-adrianv2/list-media` | Media Library durchsuchen |
| `novamira-adrianv2/edit-media` | Alt-Text, Titel, Caption bearbeiten |
| `novamira-adrianv2/delete-media` | Medien löschen (mit Safety-Check!) |
| `novamira-adrianv2/media-usage` | Wo wird ein Attachment verwendet? |
| `novamira-adrianv2/featured-image` | Featured Image lesen/setzen/entfernen |

### QA & Audit (NACH jedem Build ausführen!)

| Ability | Zweck |
|---------|-------|
| `novamira-adrianv2/layout-audit` | Pass-through, Deep-Nesting, Grid-Kandidaten |
| `novamira-adrianv2/visual-qa` | Overflow, Z-Index, Negative Margins, Overlap |
| `novamira-adrianv2/responsive-audit` | Breakpoint-Coverage, V4 Variants |
| `novamira-adrianv2/variable-audit` | e-gv-* Drift-Check (report: "drift") |
| `novamira-adrianv2/page-audit` | Leer-Container, Alt-Texte, Heading-Hierarchie |
| `novamira-adrianv2/class-audit` | Unused Global Classes |

**Zusätzlich: Pipeline Deep-Checks** (`run-post-build-qa.js --tree`):
- **GC_COVERAGE**: ≥60% der Styles sollten Global Classes nutzen
- **DOM_DEPTH**: Maximale Nesting-Tiefe ≤5 (≥6 = Timeout-Risiko)
- **RESPONSIVE_COVERAGE**: ≥30% der Elemente mit Mobile/Tablet Varianten
- **UNUSED_STYLES**: 0 ungebundene lokale Styles

### SEO & A11y

| Ability | Zweck |
|---------|-------|
| `novamira-adrianv2/audit-page-seo` | SEO-Score + Meta-Tag-Analyse |
| `novamira-adrianv2/audit-page-a11y` | WCAG 2.2 Accessibility-Audit |
| `novamira-adrianv2/fix-color-contrast` | Kontrast-Fix mit Preview-Mode |

### Konvertierung & Planung

| Ability | Zweck |
|---------|-------|
| `novamira-adrianv2/html-to-elementor-widget-plan` | HTML/CSS → Elementor-Plan |
| `novamira-adrianv2/kit-convert-v3-to-v4` | V3 Kit → V4 Design System |
| `novamira-adrianv2/execute-build-plan` | Mega-Ability: 1 Call statt 18+ Agent-Turns |

### System

| Ability | Zweck |
|---------|-------|
| `novamira/create-post` | Neue WordPress-Seite anlegen |
| `novamira/update-post` | Bestehende Seite aktualisieren |
| `novamira/execute-php` | PHP direkt ausführen |
| `novamira/read-file` | Datei lesen |
| `novamira/write-file` | Datei schreiben |
| `novamira/elementor-get-schema` | Widget-Schemas (list/get) |
| `novamira/elementor-get-style-schema` | V4 Style-Props Schema |
| `novamira/elementor-list-dynamic-tags` | Verfügbare Dynamic Tags |

---

## Fehlerbehebung

| Fehler | Ursache | Fix |
|--------|---------|-----|
| Widget zeigt keine Styles | Global Class ID falsch oder `classes: []` | `list-global-classes` → ID prüfen, in `settings.classes` eintragen |
| Farbe ignoriert Variable | Hardcode `#hex` statt Variable-Ref | `novamira-adrianv2/apply-variable-to-class` verwenden |
| Responsive bricht | Nur desktop Variant | `novamira-adrianv2/add-global-class-variant` für tablet + mobile |
| Text fehlt | Falsches $$type | `html-v3` Format prüfen |
| Container zu schmal | `content_width` fehlt | `"content_width": "full"` setzen |
| Spalten brechen auf mobile | Flexbox statt Grid | `display:grid` + `grid-template-columns:"1fr"` für mobile |
| DOM zu tief (>3 Ebenen) | Alles mit Flexbox gelöst | Grid für 2D-Layouts einsetzen |
| Hintergrund nicht sichtbar | Background in separatem Element | Background als Style-Prop auf Sektion |
| Kicker-Icon und Text-Versatz | Verschachtelter Flex-Wrapper | `flex-direction:row` direkt auf Elternelement |
| `atomic.runtime_available` = false | V4 nicht aktiviert | WP Admin > Elementor > Settings > Atomic Editor aktivieren |
| Global Class ändert sich site-weit | Statt Local Style verwendet | Für einmalige Styles: Local Style im `styles`-Objekt des Widgets |
| Patch schlägt fehl (not_found) | `style_id` existiert nicht | `elementor-get-content element_id:X` → style_id aus `styles` lesen |
| Variable in Klasse nicht gebunden | `create-global-class` mit Hex, kein Binding | `novamira-adrianv2/apply-variable-to-class` nachträglich ausführen |
| Kit-Konvertierung überschreibt | `strategy: overwrite` statt `skip` | `dry_run: true` zuerst, dann Strategie wählen |
| Unused Global Classes | Klassen angelegt, nie angewendet | `novamira-adrianv2/class-audit` → unused bereinigen |
| Bild lädt nicht | `url: null` in image-src | `url`-Key komplett entfernen |
| class_name_contains_spaces | Style-ID mit Hyphen | Style-ID ohne Hyphen generieren (`shero` nicht `s-hero`) |
| STYLE_CLASSES_BINDING FAIL | Style-ID nicht in classes.value | In `settings.classes.value` eintragen |
| elementor-set-content Timeout | Tree zu groß | Section-weise bauen |
| custom_css crasht Site | Plain String | `{"raw": "..."}` Format |
| Pass-through nach Build | Zu tiefe Verschachtelung | `novamira-adrianv2/layout-audit` → `patch-element-styles` |
| Bild-Position geraten | V3-Original nicht gelesen | `elementor-get-content full_dump` → `background_position` exakt übernehmen |
| `<p>`-Tag in e-paragraph | Block-Level-Wrapper im Content | Nur reinen Text oder Inline-HTML (`<strong>`, `<em>`) übergeben |
| Leer-classes auf ALLEN Widgets | GC erstellt aber nie zugewiesen | Jeden Widget-Node: `settings.classes` muss echte `gc-*` ID enthalten |
| Duplizierte Local Styles | V3-Denken: gleicher Style mehrfach | Eine Global Class, überall referenziert |
| Hardcodierte Farben/Fonts | Hex-Werte statt e-gv-* IDs | `elementor-list-variables` → korrekte `e-gv-*` IDs verwenden |
| DOM-Depth ≥6 | Zu tiefe Flexbox-Verschachtelung | `post-build-auto-fix.js --fix-dom-depth` ODER Layout mit Grid neu planen |
| GC_POTENTIAL BLOCKED (>20 Duplikate) | Zu viele Style-Duplikate im Tree | `generate-global-classes.js --apply` VOR dem Build ausführen |
| GC Coverage <60% | Zu wenige Global Classes verwendet | `generate-global-classes.js --tree <tree> --apply` für lokale Deduplizierung |
| Responsive Coverage <30% | Zu wenige Elemente mit Mobile/Tablet Varianten | `auto-scale-responsive.js` ausführen |
| php_max_input_vars <2000 | Server-Kapazität unzureichend | php.ini → `max_input_vars = 5000` → Server neustarten |
| memory_limit <128M | Server-Kapazität unzureichend | wp-config.php → `define('WP_MEMORY_LIMIT', '256M')` |
| `--no-gc` vergessen? | GC wurde deaktiviert, Tree hat Duplikate | `convert-xml-to-v4.js` ohne `--no-gc` neu ausführen (GC ist jetzt Default!) |
| @container Queries nicht erkannt | CSS nutzt moderne Container Queries | `extract-responsive-breakpoints.js --container-queries --css ...` |
