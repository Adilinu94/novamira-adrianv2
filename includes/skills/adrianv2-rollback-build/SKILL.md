---
title: adrianv2-rollback-build
description: Build_Versioning::rollback() pattern — when to snapshot, how to rollback, and what rollback CANNOT undo. V4-only.
---

# AdrianV2 Skill: Rollback Build

> **Plugin:** novamira-adrianv2 (Adrian V2 — V2 wegen "zweites Adrian-Plugin", NICHT Elementor V2)
> **Elementor-Welt:** V4
> **Required Capabilities:** manage_options
> **Required Abilities:** `novamira-adrianv2/rollback-build`, `novamira/elementor-get-content`

## Wann aktivieren

- **Vor jedem destruktiven Build** (`batch-build-page`, `patch-element-styles`, `elementor-set-content` auf bestehender Seite)
- Wenn ein Build fehlschlagen KÖNNTE und die vorherige Version wiederherstellbar sein muss
- Bei experimentellen Änderungen an Produktionsseiten

## Was tun

### Schritt 1: Snapshot ERSTELLEN (vor dem Write)

```json
{
  "ability": "novamira-adrianv2/rollback-build",
  "parameters": { "post_id": 1234 }
}
```
→ Erstellt einen WP-Revision-Snapshot von `_elementor_data` mit `_novamira_rollback_status=good`.
→ Gibt `revision_id` zurück — notieren!

### Schritt 2: Build ausführen

```json
{
  "ability": "novamira/elementor-set-content",
  "parameters": { "post_id": 1234, "content": [...] }
}
```

### Schritt 3: Build validieren

- `elementor-get-content` → Tree prüfen
- `layout-audit` → Score prüfen

### Schritt 4: Rollback (NUR WENN NÖTIG)

```json
{
  "ability": "novamira-adrianv2/rollback-build",
  "parameters": { "post_id": 1234 }
}
```
→ OHNE `revision_id` → rollt zur letzten Revision mit `_novamira_rollback_status=good` zurück.
→ MIT `revision_id` → rollt zur spezifischen Revision zurück.

## Was Rollback KANN

- `_elementor_data` auf den Snapshot-Stand zurücksetzen
- CSS-Cache invalidieren (damit Frontend den alten Stand zeigt)

## Was Rollback NICHT KANN

- **Global Classes rückgängig machen**: Wenn der Build Global Classes angelegt hat, bleiben die.
- **Variables rückgängig machen**: Wenn der Build Variablen geändert hat, bleiben die.
- **Media-Library-Änderungen rückgängig machen**: Hochgeladene Bilder bleiben.
- **Andere Post-Meta rückgängig machen**: Nur `_elementor_data` wird zurückgesetzt.
- **WPCode-Snippets rückgängig machen**: Separat behandeln.

## Gotchas

- **Snapshots sind WP-Revisions**: Werden von WP-Revision-Cleanup-Plugins gelöscht. Nach 60 Tagen (Default) sind Snapshots weg.
- **Nur V4**: Auf V3-Seiten liefert `rollback-build` einen `WP_Error`.
- **`revision_id` = null**: Rollt zur letzten "good" Revision zurück. Wenn KEINE "good" Revision existiert → Fehler.
- **Memory**: Sehr große Trees (>500 Elemente) können die Revision-Tabelle aufblähen.
