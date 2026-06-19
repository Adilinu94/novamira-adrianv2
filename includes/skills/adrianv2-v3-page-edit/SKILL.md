---
title: adrianv2-v3-page-edit
description: How to safely edit an existing Elementor V3 page without accidentally mixing in V4 atomic widgets. Mixed containers are forbidden.
---

# AdrianV2 Skill: V3 Page Editing

> **Plugin:** novamira-adrianv2 (Adrian V2 — V2 wegen "zweites Adrian-Plugin", NICHT Elementor V2)
> **Elementor-Welt:** V3
> **Required Capabilities:** manage_options
> **Required Abilities:** `novamira-adrianv2/detect-elementor-version`, `novamira/elementor-get-content`, `novamira/elementor-set-content`, `novamira/elementor-add-element`, `novamira/elementor-edit-element`

## Wann aktivieren

- Der User will eine BESTEHENDE V3-Seite editieren
- `detect-elementor-version` gibt `v3` für die Ziel-Seite zurück
- KEINE neue Seite, KEINE V4-Migration

## Was tun

### Schritt 1: Page-Version verifizieren
```json
{ "ability": "novamira-adrianv2/detect-elementor-version", "parameters": {} }
```
→ Muss `elementor_version: "3.x.x"` oder `detected: "v3"` zurückgeben.

### Schritt 2: Bestehenden Tree lesen
```json
{
  "ability": "novamira/elementor-get-content",
  "parameters": { "post_id": 1234 }
}
```
→ Liefert den kompletten Elementor-Tree. **Wichtig:** Den Tree verstehen BEVOR editiert wird.

### Schritt 3: Edit-Strategie wählen

**Granular (empfohlen für kleine Änderungen):**
```json
{
  "ability": "novamira/elementor-edit-element",
  "parameters": {
    "post_id": 1234,
    "element_id": "heading-abc123",
    "settings": { "title": "Neuer Titel" }
  }
}
```

**Full-Tree (für umfangreiche Änderungen):**
1. Tree aus Schritt 2 modifizieren
2. `elementor-set-content` mit dem modifizierten Tree aufrufen

### Schritt 4: NACH dem Edit Version prüfen
Stelle sicher, dass KEINE `e-flexbox`, `e-div-block`, `e-heading`, `e-button` etc. im Tree gelandet sind. Wenn doch: der Tree ist jetzt Mixed — das führt zu Render-Fehlern.

## V3 Widget-Typen (Referenz)

| elType | widgetType | Beschreibung |
|--------|-----------|-------------|
| `section` | — | V3 Section (Top-Level) |
| `column` | — | V3 Column (in Section) |
| `container` | — | V3 Flexbox Container |
| `widget` | `heading` | V3 Heading |
| `widget` | `text-editor` | V3 Text |
| `widget` | `button` | V3 Button |
| `widget` | `image` | V3 Image |
| `widget` | `spacer` | V3 Spacer |
| `widget` | `divider` | V3 Divider |

## Gotchas

- **Niemals `e-*` Widgets in V3-Tree mischen**: Führt zu weißen Seiten oder JS-Fehlern im Editor.
- **V3 `settings` vs V4 `styles`**: V3 speichert ALLES in `settings` (CSS direkt, kein `$$type`-Wrapping). V3 `heading` hat z.B. `title`, `header_size`, `align`, `typography_*` in `settings`.
- **V3 `container` ≠ V4 `e-flexbox`**: Obwohl beide Flexbox sind, ist das interne Datenformat inkompatibel.
- **Kein `patch-element-styles` auf V3-Seiten**: Diese Ability arbeitet nur mit V4 `styles`-Maps.
