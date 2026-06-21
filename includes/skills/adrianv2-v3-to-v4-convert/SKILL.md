---
name: adrianv2-v3-to-v4-convert
description: Strategy for V3 kit migration and V3 page rebuilds into V4 Atomic, including pre/post audits. One-way trip — irreversible.
---

# AdrianV2 Skill: V3 → V4 Conversion

> **Plugin:** novamira-adrianv2 (Adrian V2 — V2 wegen "zweites Adrian-Plugin", NICHT Elementor V2)
> **Elementor-Welt:** mixed
> **Required Capabilities:** manage_options
> **Required Abilities:** `novamira-adrianv2/detect-elementor-version`, `novamira-adrianv2/kit-convert-v3-to-v4`, `novamira-adrianv2/convert-page-v3-to-v4`, `novamira-adrianv2/batch-build-page`, `novamira-adrianv2/layout-audit`, `novamira-adrianv2/class-audit`, `novamira-adrianv2/design-audit`, `novamira/elementor-get-content`

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

### Schritt 2: Kit konvertieren (Design Tokens & Global Classes)
```json
{
  "ability": "novamira-adrianv2/kit-convert-v3-to-v4",
  "parameters": { "dry_run": true, "strategy": "skip" }
}
```
→ **Kit-Konvertierung**: Konvertiert NUR das Elementor Global Kit — V3-Farben und V3-Typografie-Presets werden zu V4 Variables und Global Classes.
→ **Konvertiert KEINE Seitenstruktur** — keine Sections, keine Columns, keine Widgets.
→ Das Ergebnis (`variable_map`, `class_map`) ist die Design-System-Basis für die anschließende Seiten-Konvertierung.

### Schritt 3: Seitenstruktur nach V4 Atomic konvertieren
```json
{
  "ability": "novamira-adrianv2/convert-page-v3-to-v4",
  "parameters": {
    "post_id": 1234,
    "dry_run": true,
    "unknown_widget_strategy": "keep_v3"
  }
}
```
→ **Seiten-Konvertierung**: Liest `_elementor_data` und konvertiert den kompletten Seitenbaum:
→ `section`/`container` → `e-flexbox`, `column` → `e-div-block`
→ `heading` → `e-heading`, `text-editor` → `e-paragraph`, `button` → `e-button`, `image` → `e-image`, `icon` → `e-svg`, `video` → `e-youtube`/`e-self-hosted-video`, `divider` → `e-divider`, `spacer` → `e-div-block`
→ Unbekannte Widgets bleiben als V3-Fallback erhalten (`keep_v3`) oder können per `unknown_widget_strategy` gesteuert werden.
→ Default `dry_run=true` — erst nach Prüfung der Vorschau auf `dry_run=false` setzen.
→ Optional `target_post_id` nutzen um in eine Kopie zu schreiben statt das Original zu überschreiben.

**Alternativ: Manueller Seitenumbau** (wenn automatische Konvertierung nicht ausreicht):
```json
{
  "ability": "novamira/elementor-get-content",
  "parameters": { "post_id": 1234, "full_dump": true }
}
```
→ V3-Struktur analysieren und Abschnitt für Abschnitt in einen V4 Atomic Tree umbauen.
→ Global Classes und Variables aus Schritt 2 zuweisen.

```json
{
  "ability": "novamira-adrianv2/batch-build-page",
  "parameters": { "post_id": 1234, "elements": [] }
}
```

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
- **Kit ≠ Page**: `kit-convert-v3-to-v4` = Design Tokens & Global Classes. `convert-page-v3-to-v4` = Seitenstruktur (Sections, Columns, Widgets). Beide sind getrennte Abilities mit klar unterschiedlichen Aufgaben.
- **Schriftskalierung**: V3 `typography_*`-Werte werden zu V4 `styles.font-size` — prüfen ob die Einheiten (px/em/rem) korrekt übernommen wurden.
- **Column→Div-Block Nesting**: V3 hatte `section > column > widget`; V4 hat `e-flexbox > e-div-block > widget`. Die Verschachtelung wird automatisch erzeugt, kann aber anders aussehen.
