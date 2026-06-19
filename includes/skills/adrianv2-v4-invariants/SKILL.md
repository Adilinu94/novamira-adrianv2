---
title: adrianv2-v4-invariants
description: The 5 V4 atomic invariants every V4 page edit must respect. Always active on any V4 write.
---

# AdrianV2 Skill: V4 Atomic Invariants

> **Plugin:** novamira-adrianv2 (Adrian V2 — V2 wegen "zweites Adrian-Plugin", NICHT Elementor V2)
> **Elementor-Welt:** V4
> **Required Capabilities:** manage_options (Skill-Sichtbarkeit)
> **Required Abilities:** `novamira-adrianv2/detect-elementor-version`, `novamira/elementor-set-content`, `novamira/elementor-get-content`

## Wann aktivieren

Immer wenn ein Agent eine V4 Atomic Page erstellt, editiert oder patched. Auch bei `patch-element-styles`, `batch-build-page` und `elementor-set-content` auf V4-Seiten. Der Skill wird automatisch geladen wenn `detect-elementor-version` `v4` zurückgibt.

## Die 5 Invarianten

### Invariante I — Style-Binding
V4-Atomic-Widgets binden Styles NICHT über `settings`, sondern über einen dedizierten `styles`-Map:
```json
{
  "elType": "e-heading",
  "settings": { "title": "Hello" },
  "styles": { "color": { "$$type": "color", "value": "#111111" } }
}
```
❌ CSS-Properties in `settings` → werden ignoriert oder überschrieben.
✅ CSS-Properties in `styles` → vom V4-Renderer ausgewertet.

### Invariante II — Style-Location
Responsive Varianten werden NICHT als Top-Level-Keys gespeichert, sondern innerhalb des `value`-Blocks als `responsive`:
```json
"styles": {
  "padding": {
    "$$type": "size",
    "value": {
      "size": 32, "unit": "px",
      "responsive": {
        "tablet": { "size": 24, "unit": "px" },
        "mobile": { "size": 16, "unit": "px" }
      }
    }
  }
}
```

### Invariante III — ID-Format
Element-IDs sind kebab-case, max 43 Zeichen:
- ✅ `hero-section`, `cta-button-primary`
- ❌ `HeroSection`, `cta_button_1234567890123456789012345678901234567890`
- IDs werden als `data-id`-Attribut und CSS-Klasse `s-<id>` gerendert.

### Invariante IV — Image-Src
Wenn `id` gesetzt ist, darf der `url`-Key GAR NICHT im Array vorkommen:
```json
// ✅ RICHTIG
"image": { "id": 123, "alt": "Hero" }
// ❌ FALSCH — url-Key trotz gesetztem id
"image": { "id": 123, "url": null, "alt": "Hero" }
```
`Image_Src_Prop_Type::validate_value()` schlägt sonst fehl.

### Invariante V — Custom-CSS
Custom-CSS auf V4-Elementen verwendet KEINEN `<style>`-Wrapper im Content, sondern die native `custom_css`-Property:
```json
"styles": {
  "custom_css": { "$$type": "string", "value": ".my-class { color: red; }" }
}
```

## Was tun

1. **Vor jedem V4-Write:** `detect-elementor-version` aufrufen um sicherzustellen dass die Ziel-Seite V4 ist.
2. **Tree validieren:** Jedes Element auf die 5 Invarianten prüfen bevor der Write-Call abgesetzt wird.
3. **Bei Batch-Build:** `batch-build-page` mit `elements`-Array — KEINE `settings`-Properties die in `styles` gehören.
4. **Bei Style-Patch:** `patch-element-styles` nur mit `styles`-Map, nicht mit `settings`.

## Gotchas

- **`padding` in `settings` statt `styles`**: Häufigster Fehler bei V4-Builds. Resultat: Element hat kein Padding, aber keine Fehlermeldung.
- **`url: null` im image-Array**: Zweithäufigster Fehler. Der Validator schlägt hart fehl, die Ability gibt einen kryptischen Error zurück.
- **ID zu lang**: `data-id` wird abgeschnitten, CSS-Klasse funktioniert nicht — schwer zu debuggen.
- **Framer-Pipeline-Output**: Der Framer-Konverter produziert manchmal V3-Style-Properties in `settings` — vor dem Write normalisieren.
- **V4_Props-Wrapping**: `$$type`-System wird von `novamira/elementor-set-content` automatisch gewrappt (Server-seitig). Manuelles Wrapping mit `V4_Props::size()` etc. ist nur bei `execute-php`-Aufrufen nötig.
