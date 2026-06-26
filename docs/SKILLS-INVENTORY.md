# Skills Inventory — novamira-adrianv2

> Stand: v1.8.0 | 13 Skills registriert
> Auto-Install: läuft beim ersten `init`-Hook nach Version-Bump

## Core V4 Skills

| Slug | Titel | Elementor | Trigger-Keywords |
|------|-------|-----------|-----------------|
| `adrianv2-v4-invariants` | V4 Atomic Invariants | v4 | Immer aktiv bei V4-Writes |
| `adrianv2-v4-atomic-build` | V4 Atomic Page Build | v4 | baue Seite, V4 Atomic, batch-build, e-flexbox |
| `adrianv2-token-mapping` | Token Mapping ($$type System) | v4 | $$type, PropType, size-Objekt, Breakpoint |

## Core V3 Skills

| Slug | Titel | Elementor | Trigger-Keywords |
|------|-------|-----------|-----------------|
| `adrianv2-v3-page-edit` | V3 Page Editing | v3 | bearbeite V3, Seite editieren, Widget |
| `adrianv2-v3-to-v4-convert` | V3 → V4 Conversion | mixed | migriere, konvertiere V3 zu V4 |

## Pipeline-Integration Skills (NEU v1.8.0)

| Slug | Titel | Elementor | Trigger-Keywords |
|------|-------|-----------|-----------------|
| `adrianv2-framer-pipeline-import` | Framer Pipeline → WP Deploy | v4 | deploy Pipeline-Output, V4-Tree injizieren, Pipeline fertig pushen |
| `adrianv2-site-clone-import` | site-clone-to-v3 → WP Deploy | mixed | deploy Clone, geklonte Seite pushen, Clone-Output, cloned-page-v3.json |
| `adrianv2-novamira-context-page` | Novamira Context Page Setup | mixed | Context Page einrichten, site-weite Instruktionen, Novamira v1.7 |

## Utilities und Workflow Skills

| Slug | Titel | Elementor | Trigger-Keywords |
|------|-------|-----------|-----------------|
| `adrianv2-discover-abilities-protocol` | Discover Abilities Protocol | mixed | Session-Start, welche Abilities gibt es |
| `adrianv2-self-audit` | Self-Audit (Plugin Health) | mixed | Plugin-Status, Diagnose, Health-Check |
| `adrianv2-rollback-build` | Rollback Build | v4 | rückgängig, rollback, restore |
| `adrianv2-clonerlabs` | ClonerLabs Import | mixed | ClonerLabs JSON, importiere Template |
| `adrianv2-live-edit` | Live-Edit (WPCode + Elementor) | mixed | live bearbeiten, WPCode, inline edit |

---

## Installer-Lifecycle

```
Plugin-Datei: novamira-adrianv2.php
    NOVAMIRA_ADRIANV2_VERSION = '1.8.0'
    ↓
WordPress init-Hook (Priorität 20)
    novamira_adrianv2_install_skills_on_init()
    ↓
    get_option('novamira_adrianv2_skills_installed_version') != '1.8.0'?
    ├── JA  → Installer::install() → alle 13 Skills als novamira_skill CPT upsert
    │         → update_option(..., '1.8.0')
    └── NEIN → skip
```

**Neuen Skill hinzufügen (3 Schritte):**
1. `includes/skills/<slug>/SKILL.md` erstellen
2. Slug + Titel + Elementor-Version in `installer.php` in 3 Arrays ergänzen
3. Version in `novamira-adrianv2.php` bumpen → Auto-Install beim nächsten WP-Seitenaufruf

---

## Pipeline-Skill-Mapping

```
Framer-to-Elementor-V4-Pipeline Output (v4-tree.json)
    └── adrianv2-framer-pipeline-import
        Reihenfolge: v4-preflight → inject-calibrated-page/batch-build-page
                   → validate-v4-tree → clear-cache (nested) → layout-audit

site-clone-to-v3 Output (cloned-page-v3.json)
    └── adrianv2-site-clone-import
        Reihenfolge: batch-media-upload → elementor-set-content
                   → repair-clonerlabs-page → clear-cache → layout-audit

site-clone-to-v3 Output (dryrun-page-v4.json)
    └── V4-Pipeline --input-format v4-json
        → dann: adrianv2-framer-pipeline-import (s.o.)
```

## Novamira Context Page (v1.7.0+)

Unter WordPress Admin → Novamira → Context:
Permanente Site-Instruktionen die der Agent bei JEDEM MCP-Call automatisch liest.
Templates: Skill adrianv2-novamira-context-page
