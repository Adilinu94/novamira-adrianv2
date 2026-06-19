---
title: adrianv2-v3-to-v4-convert
description: Strategy for kit-convert-v3-to-v4 migration including pre/post audits. One-way trip — irreversible.
---

# AdrianV2 Skill: V3 → V4 Conversion

> **Plugin:** novamira-adrianv2 (Adrian V2 — V2 wegen "zweites Adrian-Plugin", NICHT Elementor V2)
> **Elementor-Welt:** mixed
> **Required Capabilities:** manage_options
> **Required Abilities:** `novamira-adrianv2/detect-elementor-version`, `novamira-adrianv2/kit-convert-v3-to-v4`, `novamira-adrianv2/layout-audit`, `novamira-adrianv2/class-audit`, `novamira-adrianv2/design-audit`, `novamira/elementor-get-content`

## Wann aktivieren

- User fragt: "konvertier meine V3-Seite zu V4", "migrier zu Atomic", "mach aus den alten Containern e-flexbox"
- Eine bestehende V3-Site soll auf V4 Atomic umgestellt werden
- Der User versteht dass die Konvertierung **irreversibel** ist

## ⚠️ Vor der Konvertierung

1. **Backup erzwingen:** `elementor-get-content` auf der Ziel-Seite → JSON lokal speichern
2. **User bestätigen lassen:** "Diese Konvertierung ist irreversibel. Fortfahren?"
3. **Pre-Conversion Audit:** `layout-audit` + `class-audit` → dokumentieren

## Was tun

### Schritt 1: Pre-Audit
```json
{ "ability": "novamira-adrianv2/layout-audit", "parameters": { "post_id": 1234 } },
{ "ability": "novamira-adrianv2/design-audit", "parameters": { "post_id": 1234 } }
```
→ Score und Issues dokumentieren. Dient als Baseline für Post-Conversion-Vergleich.

### Schritt 2: Kit konvertieren
```json
{
  "ability": "novamira-adrianv2/kit-convert-v3-to-v4",
  "parameters": { "post_id": 1234 }
}
```
→ Konvertiert: `section` → `e-flexbox`, `column` → `e-div-block` (verschachtelt), `container` → `e-flexbox`.
→ Widgets: `heading` → `e-heading`, `text-editor` → `e-paragraph`, `button` → `e-button`, `image` → `e-image`.
→ Styles werden von `settings` nach `styles` migriert und in `$$type`-Format gewrappt.

### Schritt 3: Post-Conversion Audit
```json
{ "ability": "novamira-adrianv2/layout-audit", "parameters": { "post_id": 1234 } },
{ "ability": "novamira-adrianv2/class-audit", "parameters": { "post_id": 1234 } },
{ "ability": "novamira-adrianv2/design-audit", "parameters": { "post_id": 1234 } }
```
→ Mit Pre-Conversion-Scores vergleichen. Score DARF nicht drastisch sinken.

### Schritt 4: Foundation + Global Classes
```json
{ "ability": "novamira-adrianv2/setup-v4-foundation", "parameters": {} },
{ "ability": "novamira-adrianv2/batch-class", "parameters": { "post_id": 1234, "element_class_map": { ... } } }
```
→ `e-flexbox-base` und `e-div-block-base` auf Root-Container anwenden.

## Was NICHT konvertiert wird

- Custom CSS/JS (muss manuell migriert werden)
- Theme Builder Conditions (bleiben erhalten)
- Widget-spezifische Einstellungen die kein V4-Pendant haben
- Drittanbieter-Widgets

## Gotchas

- **Irreversibel**: Es gibt KEIN `kit-convert-v4-to-v3`. Backup vorher ist Pflicht.
- **V4-Site = No-Op**: Auf bereits konvertierten Seiten liefert die Ability `WP_Error` mit Code `no_op`.
- **Schriftskalierung**: V3 `typography_*`-Werte werden zu V4 `styles.font-size` — prüfen ob die Einheiten (px/em/rem) korrekt übernommen wurden.
- **Column→Div-Block Nesting**: V3 hatte `section > column > widget`; V4 hat `e-flexbox > e-div-block > widget`. Die Verschachtelung wird automatisch erzeugt, kann aber anders aussehen.
