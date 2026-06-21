# novamira-adrianv2 — Skills Inventory

> **Stand:** 2026-06-21  
> **Plugin-Version:** 1.0.0  
> **Verzeichnis:** `includes/skills/`  
> **Anzahl Skills:** 9

Alle Skills sind als `SKILL.md`-Dateien gespeichert und werden durch den Novamira MCP-Adapter dem KI-Agenten bereitgestellt. Der Agent lädt den passenden Skill über `novamira-adrianv2/get-skill` oder durch direktes Lesen der Datei via `novamira/read-file`.

---

## Übersicht aller Skills

| # | Slug | Datei | Beschreibung | Trigger-Worte |
|---|------|--------|--------------|---------------|
| 1 | `adrianv2-discover-abilities-protocol` | `includes/skills/adrianv2-discover-abilities-protocol/SKILL.md` | Protokoll: vor jedem neuen MCP-Session `mcp-adapter-discover-abilities` aufrufen, nie Ability-Namen aus Erinnerung annahmen | "discover", "abilities", "was kann du", "welche Abilities" |
| 2 | `adrianv2-live-edit` | `includes/skills/adrianv2-live-edit/SKILL.md` | Workflow für Live-Seiten-Bearbeitung: Backup → Audit → Edit → Verify | "live bearbeiten", "Seite anpassen", "edit page", "change section" |
| 3 | `adrianv2-rollback-build` | `includes/skills/adrianv2-rollback-build/SKILL.md` | Rollback eines fehlgeschlagenen Builds: Version-History lesen, V3-Backup wiederherstellen | "rückgängig", "rollback", "undo", "revert" |
| 4 | `adrianv2-self-audit` | `includes/skills/adrianv2-self-audit/SKILL.md` | Self-Audit des Plugins: Setup prüfen, Fähigkeiten verifizieren, alle Ability-Domains testen | "plugin prüfen", "self audit", "setup check", "health check" |
| 5 | `adrianv2-token-mapping` | `includes/skills/adrianv2-token-mapping/SKILL.md` | Design-Token Mapping: V4 Props-Typen (`$$type`), Global Variables, Global Classes Syntax | "token", "props", "$$type", "global variable", "design system" |
| 6 | `adrianv2-v3-page-edit` | `includes/skills/adrianv2-v3-page-edit/SKILL.md` | V3-Seiten bearbeiten ohne Konvertierung: V3-Widget-Struktur verstehen, `_elementor_data` korrekt lesen/schreiben | "V3 Seite", "elementor data", "section", "column", "widget settings" |
| 7 | `adrianv2-v3-to-v4-convert` | `includes/skills/adrianv2-v3-to-v4-convert/SKILL.md` | V3→V4 Konvertierungs-Workflow: Kit-Convert → Page-Convert → Foundation → Audit | "konvertieren", "V3 zu V4", "migrate", "atomic", "e-flexbox" |
| 8 | `adrianv2-v4-atomic-build` | `includes/skills/adrianv2-v4-atomic-build/SKILL.md` | V4 Atomic Build von Grund: e-flexbox, e-div-block, e-heading, e-paragraph, e-button, e-image bauen | "V4 bauen", "atomic widget", "neue Seite", "build page", "e-flexbox" |
| 9 | `adrianv2-v4-invariants` | `includes/skills/adrianv2-v4-invariants/SKILL.md` | V4 Invarianten: Pflichtregeln für korrekte V4-Props ($$type, Dimensionen, Farben, Image-Src) | "invariant", "V4 Regeln", "prop format", "$$type color", "image-src" |

---

## Skill-Details

### 1. `adrianv2-discover-abilities-protocol`

**Zweck:** Stellt sicher, dass der Agent nie mit veralteten Ability-Namen arbeitet.  
**Kernregel:** Bei jedem neuen MCP-Session-Start ZUERST `mcp-adapter-discover-abilities` aufrufen.  
**Wann laden:** Immer als erstes — jede andere Skill-Anwendung setzt voraus, dass Abilities bekannt sind.

```json
{ "ability": "novamira-adrianv2/discover-abilities-protocol" }
```

---

### 2. `adrianv2-live-edit`

**Zweck:** Sichere Bearbeitung von Live-Seiten ohne Produktions-Ausfälle.  
**Workflow:**
1. Backup via `rollback-build` sichern
2. `audit-page` / `audit-layout` Baseline erfassen
3. Änderungen vornehmen
4. Post-Edit Audit vergleichen  

**Wann laden:** Wenn eine bestehende Seite live geändert werden soll (nicht konvertiert).

---

### 3. `adrianv2-rollback-build`

**Zweck:** Wiederherstellen einer Seite nach fehlerhaftem Build oder Konvertierung.  
**Kernfunktion:** Liest `_novamira_v3_backup` oder `_novamira_build_history` und stellt diese wieder her.  
**Abilities:** `novamira-adrianv2/rollback-build`, `novamira-adrianv2/list-build-versions`

```json
{ "ability": "novamira-adrianv2/rollback-build", "parameters": { "post_id": 1234 } }
```

---

### 4. `adrianv2-self-audit`

**Zweck:** Vollständige Plugin-Gesundheitsprüfung.  
**Prüft:**
- Alle Ability-Domains registriert?
- Elementor V4 verfügbar?
- Kit korrekt konfiguriert?
- Global Classes vorhanden?
- WPCode, Yoast/Rank Math aktiv?

**Abilities:** `novamira-adrianv2/self-audit`, `novamira-adrianv2/elementor-check-setup`

---

### 5. `adrianv2-token-mapping`

**Zweck:** Nachschlagewerk für alle V4 `$$type`-Formate und ihre korrekte Syntax.

| $$type | Format | Beispiel |
|--------|--------|---------|
| `color` | `{$$type: 'color', value: '#HEX'}` | `{$$type: 'color', value: '#FF0000'}` |
| `global-color-variable` | `{$$type: 'global-color-variable', value: 'e-gv-XXXXXXX'}` | `{$$type: 'global-color-variable', value: 'e-gv-bebd7fa'}` |
| `size` | `{$$type: 'size', value: N, unit: 'px'/'em'/'rem'/'%'}` | `{$$type: 'size', value: 16, unit: 'px'}` |
| `string` | `{$$type: 'string', value: 'string'}` | `{$$type: 'string', value: 'Inter'}` |
| `image-attachment-id` | `{$$type: 'image-attachment-id', value: N}` | `{$$type: 'image-attachment-id', value: 42}` |
| `dimensions` | `{$$type: 'dimensions', 'block-start': size, 'block-end': size, 'inline-start': size, 'inline-end': size}` | `{$$type: 'dimensions', 'block-start': {$$type:'size',value:16,unit:'px'}, ...}` |

---

### 6. `adrianv2-v3-page-edit`

**Zweck:** V3-Seiten bearbeiten ohne Migration auf V4.  
**Wann:** Wenn der User explizit V3 behalten möchte oder die Site noch kein V4 hat.  
**Kernfähigkeiten:** `novamira/elementor-get-content`, `novamira-adrianv2/patch-element-styles`, `novamira-adrianv2/batch-class`

---

### 7. `adrianv2-v3-to-v4-convert`

**Zweck:** Vollständiger V3→V4 Migrations-Workflow.  
**Phasen:**
1. Kit-Konvertierung: `kit-convert-v3-to-v4`
2. Seiten-Konvertierung: `convert-page-v3-to-v4`
3. Foundation: `setup-v4-foundation`
4. Base Classes: `batch-class`
5. Post-Audit: `audit-layout` + `audit-class`

**⚠️ Irreversibel** — immer Backup vorher!

---

### 8. `adrianv2-v4-atomic-build`

**Zweck:** Baut neue Seiten oder Sektionen vollständig in V4 Atomic.  
**Haupt-Widgets:** `e-flexbox`, `e-div-block`, `e-heading`, `e-paragraph`, `e-button`, `e-image`, `e-svg`, `e-divider`  
**Abilities:** `novamira-adrianv2/batch-build-page`, `novamira-adrianv2/add-atomic-heading`, etc.

---

### 9. `adrianv2-v4-invariants`

**Zweck:** Pflichtregeln (Invarianten) die IMMER eingehalten werden müssen.

| Invariant | Regel |
|-----------|-------|
| I | `settings` ist immer `{}` — niemals V3-Settings in V4-Elementen |
| II | `elType` ist immer aus dem V4-Set: `e-flexbox`, `e-div-block`, `e-heading`, etc. |
| III | `styles` enthält immer `{desktop: {props: {}}}` als Minimum |
| IV | `image`: wenn `id` gesetzt, kein `url`-Key; wenn kein `id`, nur `url` |
| V | `dimensions` immer mit allen 4 Seiten: `block-start`, `block-end`, `inline-start`, `inline-end` |

---

## Skill laden — Beispiel-Aufrufe

```json
// Skill via read-file laden
{
  "ability": "novamira/read-file",
  "parameters": {
    "path": "wp-content/plugins/novamira-adrianv2/includes/skills/adrianv2-v3-to-v4-convert/SKILL.md"
  }
}
```

---

## Skills hinzufügen / bearbeiten

Skills werden über `novamira/skill-edit` bearbeitet. Inhalt startet direkt mit dem Markdown-Body (kein YAML-Frontmatter im `content`-Parameter):

```json
{
  "ability": "novamira/skill-edit",
  "parameters": {
    "skill": "adrianv2-v3-to-v4-convert",
    "content": "# AdrianV2 Skill: V3 → V4 Conversion\n\n..."
  }
}
```

---

*Dokument erstellt 2026-06-21. Alle Angaben basieren auf tatsächlichem Source-Code-Review.*
