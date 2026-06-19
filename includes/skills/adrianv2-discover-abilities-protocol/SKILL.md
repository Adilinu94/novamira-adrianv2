---
title: adrianv2-discover-abilities-protocol
description: How to correctly read mcp-adapter-discover-abilities output, filter by capability, detect V3/V4 categories, and match abilities to tasks.
---

# AdrianV2 Skill: Discover Abilities Protocol

> **Plugin:** novamira-adrianv2 (Adrian V2 — V2 wegen "zweites Adrian-Plugin", NICHT Elementor V2)
> **Elementor-Welt:** mixed
> **Required Capabilities:** manage_options
> **Required Abilities:** `novamira-adrianv2/detect-elementor-version`, `novamira/elementor-check-setup`

## Wann aktivieren

- Vor JEDEM ersten Build auf einer Site
- Nach Plugin-Updates (neue Abilities könnten registriert sein)
- Bei "ability not found"-Fehlern
- Wenn der Agent nicht weiß welche Abilities verfügbar sind

## Wie lesen

### 1. Setup check
```json
{ "ability": "novamira/elementor-check-setup", "parameters": {} }
```
→ Gibt WP-Version, Elementor-Version, Plugin-Versionen, aktive Plugins zurück.

### 2. Version detection
```json
{ "ability": "novamira-adrianv2/detect-elementor-version", "parameters": {} }
```
→ Gibt Site-Level-Felder wie `elementor_version`, `atomic_supported`, `supports_atomic`, `global_classes_available` und `global_variables_available` zurück.

Für eine konkrete Seite:
```json
{ "ability": "novamira-adrianv2/detect-elementor-version", "parameters": { "post_id": 1234 } }
```
→ Gibt zusätzlich `page_version`, `page_is_v4`, `detected` und `recommended_page_action` zurück.

### 3. Abilities-Discovery (via MCP Adapter)
Der MCP-Adapter listet alle registrierten Abilities. Filtern nach:
- **Namespace:** `novamira-adrianv2/*` (AdrianV2), `novamira/*` (Core), `novamira-pro/*` (Pro)
- **Category:** `adrianv2-*` Categories mit `meta.elementor_version`:
  - `v4`: Nur auf V4-Sites sinnvoll (global-classes, v4-management, variables, atomic)
  - `mixed`: Auf V3 und V4 (elementor, batch, media, audit, seo, a11y, utilities, design-audit, design-utilities, templates, site-tools, pro)
- **Capability:** Fähigkeiten filtern die `manage_options` requiren (Admin-only)

## Entscheidungsmatrix

| Du willst... | Fähigkeit |
|-------------|-----------|
| Seite lesen | `novamira/elementor-get-content` |
| Seite schreiben (V4) | `novamira/elementor-set-content` |
| Seite schreiben (V3) | `novamira/elementor-set-content` |
| Ein Element hinzufügen | `novamira/elementor-add-element` |
| Ein Element editieren | `novamira/elementor-edit-element` |
| Element löschen | `novamira/elementor-delete-element` |
| Global Class anlegen | `novamira-adrianv2/add-global-class-variant` |
| Variable anlegen | `novamira-adrianv2/batch-create-variables` |
| Audit laufen lassen | `novamira-adrianv2/layout-audit` |
| Cache leeren | `novamira-adrianv2/clear-cache` |

## Gotchas

- **MCP-Adapter filtert NUR nach `mcp.public=true` und `mcp.type='tool'`**: Nicht nach Category, Priority, Namespace. Wenn eine Ability fehlt, ist sie NICHT registriert.
- **Category muss existieren**: `wp_register_ability()` lehnt Abilities still ab wenn die Category nicht in `wp_get_ability_categories()` ist.
- **Namespace prüfen bei alten Builds**: `Guards` muss als `Novamira\AdrianV2\Helpers\Guards` importiert werden. Wenn ein Host noch `Class "Novamira\AdrianV2\Guards" not found` meldet, ist das Plugin veraltet.
- **`novamira/*` vs `novamira-adrianv2/*`**: Core-Abilities sind im `novamira`-Namespace. V2-spezifische im `novamira-adrianv2`-Namespace. Nicht verwechseln.
