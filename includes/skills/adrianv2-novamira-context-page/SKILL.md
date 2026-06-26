---
name: adrianv2-novamira-context-page
description: How to use the Novamira Context Page (v1.7.0+) for stable site-wide AI guidance. Activate when setting up a new site for the first time, or when the user asks about the Context Page. Provides templates for V3 sites, V4 Atomic sites, and pipeline-managed sites.
---

# AdrianV2 Skill: Novamira Context Page Setup

> **Feature:** Novamira Core v1.7.0+ (Novamira Pro 1.3.0+ für volle Funktion)
> **Zweck:** Stabile, sitzungsübergreifende Site-Instruktionen die der Agent automatisch anwendet

## Was ist die Context Page?

Die Context Page in Novamira (Einstellungen → Context) erlaubt es, permanente Site-Instruktionen zu hinterlegen, die der Agent bei jedem MCP-Aufruf automatisch liest. Anders als Skills (die keyword-getriggert sind) werden Context-Page-Inhalte **immer** an den Agent übertragen.

**Ideal für:**
- Welcher Elementor-Modus ist aktiv (V3 oder V4)?
- Welche Plugins sind installiert?
- Welche Pipeline wird für diese Site genutzt?
- Was sind die stabilen IDs (Kit-ID, Template-IDs)?

**NICHT geeignet für:**
- Session-live IDs aus `setup-v4-foundation` (ändern sich pro Session!)
- MCP-Tokens (Sicherheitsrisiko)
- Temporäre Build-States

## Template: V4 Atomic Site

```markdown
## Site-Kontext: [Site-Name]

**Elementor-Mode:** V4 Atomic Widgets
**Atomic-Experiments:** aktiviert (container_grid, e-flexbox, e-heading, e-paragraph, e-button, e-image)
**WP-URL:** https://[site-url]/
**Kit-ID:** [kit_post_id — aus elementor-check-setup lesen]

**Workflow:**
- Neue Seiten: `setup-v4-foundation` → `batch-build-page` → `validate-v4-tree` → `clear-cache`
- Edits: `elementor-get-content` → `edit-element` oder `patch-element-styles` → `clear-cache`
- Pipeline-Deploys: Skill `adrianv2-framer-pipeline-import` verwenden

**Guard-Threshold:** 85% vor jedem Deploy (Framer-to-Elementor-V4-Pipeline)

**Design-Tokens (stabile GVs):**
- Primary: var(--e-global-color-[id])
- Secondary: var(--e-global-color-[id])
- Font-Primary: var(--e-global-typography-[id]-font-family)

**Invarianten:** Skill `adrianv2-v4-invariants` immer aktiv bei V4-Writes.
```

## Template: V3 Site (mit Clone-Pipeline)

```markdown
## Site-Kontext: [Site-Name]

**Elementor-Mode:** V3 (Flexbox Container, klassische Widgets)
**WP-URL:** https://[site-url]/
**Kit-ID:** [kit_post_id]

**Workflow für geklonte Seiten:**
1. `batch-media-upload` (externe URLs zuerst)
2. `elementor-set-content` mit V3 JSON-Array
3. `repair-clonerlabs-page` (auto_fix: true)
4. `clear-cache` (include_nested: true)
5. `layout-audit`

**Pipeline:** site-clone-to-v3 → Skill `adrianv2-site-clone-import` verwenden

**Bekannte Einschränkungen dieser Site:**
- [z.B. Spezielle Custom CSS in wp-header.php]
- [z.B. WooCommerce erfordert elementor-wc-bridge]
```

## Template: Pipeline-Managed Site (beide Pipelines)

```markdown
## Site-Kontext: [Site-Name] — Pipeline-Managed

**Primäre Pipeline:** Framer-to-Elementor-V4-Pipeline + site-clone-to-v3
**Elementor-Mode:** V4 Atomic
**WP-URL:** https://[site-url]/

**Pipeline-Handoff-Skills:**
- Framer-Export → V4: `adrianv2-framer-pipeline-import`
- Clone-Import: `adrianv2-site-clone-import`

**Abilities Hub (Novamira v1.6.0+):**
Für Pipeline-Runs diese Abilities aktivieren:
- novamira-adrianv2/v4-preflight ✅
- novamira-adrianv2/validate-v4-tree ✅
- novamira-adrianv2/clear-cache ✅
- novamira-adrianv2/batch-build-page ✅

**Media-Library-Basis-URL:** https://[site-url]/wp-content/uploads/

**Wichtig:** `setup-v4-foundation` EINMAL pro Session ausführen.
GV-IDs (e-gv-*) sind session-live und dürfen NICHT hier hardcoded werden.
```

## Wie die Context Page befüllen?

1. WordPress Admin → Novamira → Context
2. Template aus dieser Skill kopieren und anpassen
3. Speichern → Agent liest es bei jedem MCP-Call automatisch

## Was NICHT in die Context Page gehört

❌ `e-gv-abc123` IDs (session-live, ändern sich)
❌ Application Passwords / MCP-Tokens
❌ Seiten-spezifische Post-IDs die sich ändern
❌ Temporäre Build-State-Infos
❌ Lange Code-Blöcke (max ~500 Wörter empfohlen)

## Abilities Hub (Novamira v1.6.0+)

Über Novamira → Abilities Hub können einzelne Abilities ein/ausgeschaltet werden.
Für Pipeline-Betrieb empfohlen:
- **Aktivieren:** v4-preflight, validate-v4-tree, clear-cache, batch-build-page, batch-media-upload
- **Deaktivieren für Prod:** php-snippets, php-sandbox (Sicherheit)