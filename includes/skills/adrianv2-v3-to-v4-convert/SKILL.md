---
name: adrianv2-v3-to-v4-convert
description: Strategy for V3 kit migration and V3 page rebuilds into V4 Atomic, including pre/post audits. One-way trip — irreversible.
---

# AdrianV2 Skill: V3 → V4 Conversion

> **Plugin:** novamira-adrianv2 (Adrian V2 — V2 wegen "zweites Adrian-Plugin", NICHT Elementor V2)
> **Elementor-Welt:** mixed
> **Required Capabilities:** manage_options
> **Required Abilities:** `novamira-adrianv2/detect-elementor-version`, `novamira-adrianv2/kit-convert-v3-to-v4`, `novamira-adrianv2/convert-page-v3-to-v4`, `novamira-adrianv2/layout-audit`, `novamira-adrianv2/class-audit`, `novamira-adrianv2/design-audit`

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
  "parameters": { "dry_run": true, "strategy": "skip" }
}
```
→ Konvertiert NUR das Elementor Global Kit: V3-Farben und V3-Typografie-Presets werden zu V4 Variables und Global Classes.
→ Diese Ability konvertiert KEINE Seitenstruktur, KEINE Sections, KEINE Columns und KEINE Widgets.
→ Das Ergebnis (`variable_map`, `class_map`) ist die Design-System-Basis für die anschließende Seiten-Konvertierung.

### Schritt 3: Seitenbaum nach V4 Atomic umbauen

Zuerst Dry Run — kein Schreibzugriff, nur Vorschau und Stats:
```json
{
  "ability": "novamira-adrianv2/convert-page-v3-to-v4",
  "parameters": { "post_id": 1234, "dry_run": true, "unknown_widget_strategy": "keep_v3" }
}
```
→ Gibt `converted_tree`, `stats` und `warnings` zurück. `stats.unsupported_widgets` prüfen — sind die akzeptabel?

Dann schreiben — empfohlen in eine Kopie (nicht das Original überschreiben):
```json
{
  "ability": "novamira-adrianv2/convert-page-v3-to-v4",
  "parameters": { "post_id": 1234, "target_post_id": 5678, "dry_run": false, "unknown_widget_strategy": "keep_v3" }
}
```
→ Mapping: `section` → `e-flexbox`, `column` → `e-div-block`, `heading` → `e-heading`, `text-editor` → `e-paragraph`, `button` → `e-button`, `image` → `e-image`, `divider` → `e-divider`, `spacer` → `e-div-block+padding`.
→ V3 Widgets ohne Atomic-Pendant werden nach `unknown_widget_strategy` behandelt (`keep_v3` = unverändert übernehmen).

### Schritt 4: Post-Conversion Audit
```json
{ "ability": "novamira-adrianv2/layout-audit", "parameters": { "post_id": 1234 } },
{ "ability": "novamira-adrianv2/class-audit", "parameters": { "post_id": 1234 } },
{ "ability": "novamira-adrianv2/design-audit", "parameters": { "post_id": 1234 } }
```
→ Mit Pre-Conversion-Scores vergleichen. Score DARF nicht drastisch sinken.

### Schritt 5: Foundation + Global Classes prüfen
```json
{ "ability": "novamira-adrianv2/setup-v4-foundation", "parameters": {} }
```
→ Foundation-Klassen/Variablen müssen existieren. Klassen direkt im V4 Tree über `settings.classes` zuweisen.

## Was NICHT konvertiert wird

- Custom CSS/JS (muss manuell migriert werden)
- Theme Builder Conditions (bleiben erhalten)
- Widget-spezifische Einstellungen die kein V4-Pendant haben
- Drittanbieter-Widgets

## Gotchas

- **Irreversibel**: Es gibt KEIN `kit-convert-v4-to-v3`. Backup vorher ist Pflicht.
- **Kit ≠ Page**: `kit-convert-v3-to-v4` migriert nur Design Tokens und Global Classes. Der Seitenbaum muss separat in Atomic Elements konvertiert werden.
- **Schriftskalierung**: V3 `typography_*`-Werte werden zu V4 `styles.font-size` — prüfen ob die Einheiten (px/em/rem) korrekt übernommen wurden.
- **Column→Div-Block Nesting**: V3 hatte `section > column > widget`; V4 hat `e-flexbox > e-div-block > widget`. Die Verschachtelung wird automatisch erzeugt, kann aber anders aussehen.
