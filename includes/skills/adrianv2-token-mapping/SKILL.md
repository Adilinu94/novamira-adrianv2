---
name: adrianv2-token-mapping
description: How V4_Props token wrapping works — when $$type, when scalar, when var(--e-global-*). Required knowledge for any V4 settings authoring.
---

# AdrianV2 Skill: Token Mapping ($$type System)

> **Plugin:** novamira-adrianv2 (Adrian V2 — V2 wegen "zweites Adrian-Plugin", NICHT Elementor V2)
> **Elementor-Welt:** V4
> **Required Capabilities:** manage_options
> **Required Abilities:** `novamira/elementor-set-content`, `novamira-adrianv2/sync-schema`

## Wann aktivieren

- User fragt: "wie setze ich eine Farbe in V4?", "was ist $$type?", "wie wrappe ich Styles richtig?"
- Ein Agent baut V4-Settings manuell (nicht über `set-content`-Auto-Wrap)
- Debugging: "meine Styles werden nicht übernommen"

## Die $$type-Logik

Jeder Style-Wert in V4 wird als `{ "$$type": "<type>", "value": <wert> }` gespeichert:

| $$type | value-Format | Beispiel |
|--------|-------------|---------|
| `string` | `"text"` | `{ "$$type": "string", "value": "h1" }` |
| `number` | `42` | `{ "$$type": "number", "value": 1.5 }` |
| `boolean` | `true/false` | `{ "$$type": "boolean", "value": true }` |
| `color` | `"#FFFFFF"` | `{ "$$type": "color", "value": "#FF5500" }` |
| `size` | `{ "size": 16, "unit": "px" }` | `{ "$$type": "size", "value": { "size": 32, "unit": "px" } }` |
| `image` | `{ "src": { "id": 123 } }` | Siehe Invariante IV |
| `html-v3` | `{ "content": ..., "children": [] }` | Rich-Text |
| `link` | `{ "destination": ..., "tag": "a" }` | Button-Links |
| `classes` | `["class-id-1", "class-id-2"]` | Global Classes |
| `image-src` | speziell | Internes Format |
| `image-attachment-id` | `123` | Media-Library-ID |

## Auto-Wrap vs Manuell

**`novamira/elementor-set-content` macht Auto-Wrap:**
```json
{
  "styles": {
    "padding": 32,
    "color": "#FF5500",
    "font-size": "24px"
  }
}
```
→ Server wrappt automatisch zu `$$type: size`, `$$type: color`, etc.

**Direktes Schreiben (execute-php, add-element):**
Muss manuell im `$$type`-Format sein:
```json
{
  "styles": {
    "padding": { "$$type": "size", "value": { "size": 32, "unit": "px" } },
    "color": { "$$type": "color", "value": "#FF5500" }
  }
}
```

## var() und Design Tokens

`var(--e-global-color-primary)` wird als `$$type: color` mit String-Value gespeichert:
```json
{ "$$type": "color", "value": "var(--e-global-color-primary)" }
```

## Schema abfragen

Für die autoritative Liste aller Props und ihrer Types:
```json
{
  "ability": "novamira-adrianv2/sync-schema",
  "parameters": { "format": "compact", "sections": ["all"] }
}
```

## Gotchas

- **`font-size: "24px"` als String**: `set-content` Auto-Wrap parsed `"24px"` → `size(24, "px")`. Funktioniert nur bei `set-content`.
- **`color: "#FFF"`**: 3-stellige Hex-Codes werden NICHT expandiert. Elementor erwartet 6-stellig (`#FFFFFF`).
- **`value`-Key vergessen**: `{ "$$type": "color" }` ohne `value` → Render-Fehler, keine Warnung.
- **Responsive in `value.responsive`**: Tablet/Mobile-Varianten sind NIE Top-Level-Keys, sondern Nested in `value.responsive.tablet` / `value.responsive.mobile`.
