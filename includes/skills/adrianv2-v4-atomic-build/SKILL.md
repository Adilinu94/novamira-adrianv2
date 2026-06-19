---
title: adrianv2-v4-atomic-build
description: Complete step-by-step guide for building a V4 Atomic Page with setup-v4-foundation → batch-build-page → post-build audits.
---

# AdrianV2 Skill: V4 Atomic Page Build

> **Plugin:** novamira-adrianv2 (Adrian V2 — V2 wegen "zweites Adrian-Plugin", NICHT Elementor V2)
> **Elementor-Welt:** V4
> **Required Capabilities:** manage_options
> **Required Abilities:** `novamira-adrianv2/detect-elementor-version`, `novamira-adrianv2/setup-v4-foundation`, `novamira-adrianv2/batch-build-page`, `novamira/elementor-set-content`, `novamira/elementor-get-content`, `novamira-adrianv2/layout-audit`, `novamira-adrianv2/class-audit`, `novamira-adrianv2/variable-audit`

## Wann aktivieren

- Der User sagt: "bau mir eine neue Landing Page", "erstell eine V4-Seite", "mach eine Atomic Page aus diesem Framer-Export"
- `detect-elementor-version` gibt `atomic_supported: true` zurück
- Eine neue Seite soll erstellt werden (KEIN Edit einer bestehenden)

## Was tun

### Schritt 1: Foundation sicherstellen
```json
{ "ability": "novamira-adrianv2/setup-v4-foundation", "parameters": {} }
```
Legt `e-flexbox-base` und `e-div-block-base` Global Classes an (idempotent). Nur beim ERSTEN Build auf einer Site nötig — danach No-Op.

### Schritt 2: Seite erstellen
```json
{
  "ability": "novamira/create-post",
  "parameters": {
    "post_type": "page",
    "title": "Meine Landing Page",
    "status": "draft"
  }
}
```
→ Notiere die zurückgegebene `post_id`.

### Schritt 3: Atomic Tree schreiben
```json
{
  "ability": "novamira/elementor-set-content",
  "parameters": {
    "post_id": 1234,
    "content": [<V4 Atomic Element Tree>]
  }
}
```
**Wichtig:** `content` ist ein ARRAY, kein String. Jedes Element hat `elType` (z.B. `e-flexbox`, `e-heading`, `e-button`), `id` (kebab-case, max 43 Zeichen), `settings` (Widget-Content), `styles` (CSS-Properties im `$$type`-Format), und `elements` (Kinder).

**Alternative:** `batch-build-page` kann Seite ERSTELLEN UND schreiben in einem Call. Für komplette V4 Trees bleibt `novamira/elementor-set-content` die bevorzugte validierende Schreibschicht.

### Schritt 4: Globale Klassen zuweisen
Bevorzugt: Global Classes direkt im V4 Tree über `settings.classes` setzen.

Optional für nachträgliche Bulk-Zuweisung:
```json
{
  "ability": "novamira-adrianv2/batch-class",
  "parameters": {
    "post_id": 1234,
    "element_ids": ["hero-section", "hero-copy"],
    "action": "add",
    "class_id": "gc-1234567890abcdef"
  }
}
```

### Schritt 5: Post-Build Audits
```json
{ "ability": "novamira-adrianv2/layout-audit", "parameters": { "post_id": 1234 } },
{ "ability": "novamira-adrianv2/class-audit", "parameters": { "post_id": 1234 } },
{ "ability": "novamira-adrianv2/variable-audit", "parameters": { "post_id": 1234 } }
```
Prüft Layout-Struktur, Class-Coverage und Variable-Nutzung. Gibt Scores und Fix-Vorschläge zurück.

## Gotchas

- **`content` MUSS ein Array sein** — `"content": "[{...}]"` (String) schlägt fehl.
- **Framer-Export vorher validieren**: Der Pipeline-Output enthält manchmal V3-Properties in `settings` die in `styles` gehören. Siehe `adrianv2-v4-invariants` Skill.
- **`e-flexbox` vs `container`**: V4 nutzt `e-flexbox`/`e-div-block`, NICHT `container`. Falscher elType → Element wird nicht gerendert.
- **Kein `batch-build-page` nach `set-content`**: Beide schreiben `_elementor_data` — der zweite Call überschreibt den ersten.
- **Foundation NUR bei neuem Site-Setup**: `setup-v4-foundation` ist idempotent aber erzeugt Log-Einträge bei jedem Call.
