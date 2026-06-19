# BAUPLAN: `novamira-adrianv2` — V3/V4-Trennung + Plugin-Verbesserungen

**Repo:** `novamira-adrianv2/` (NICHT ein neues Plugin anlegen!)
**Branches:** `main` (Arbeit findet hier statt, danach PR)
**Stand:** 2026-06-17 (Plan) / 2026-06-19 (Status-Update)
**Solver:** Vorbereitung nach Cleanup-Phase (BOM-Fix bereits deployed auf test4)
**Out-of-scope:** Novamira-Core (`novamira/*` 52 Abilities), Novamira-Pro (`novamira-pro/*`), MCP-Adapter — **alle bleiben unangetastet**.

> ## 📊 Status-Übersicht (2026-06-19)
> | Phase | Status |
> |---|---|
> | Phase 0 — Foundation: Helpers | ✅ **Fertig** |
> | Phase 1 — Skills trennen | ✅ **Fertig** |
> | Phase 2 — Abilities härten | ✅ **Fertig** |
> | Phase 3 — Quality Gates + Tests | ✅ **Fertig** |
> | Phase 4 — Dokumentation + Übergabe | ⚠️ **Teilweise offen** (docs/SKILLS-INVENTORY.md, docs/V3-V4-DECISION-TREE.md, docs/CHANGELOG-v2-detailed.md fehlen noch) |

---

## 0. Leitlinien (jede Phase hält sich daran)

1. **Plugin bleibt `novamira-adrianv2`.** Keine neuen Plugins, kein Fork, kein zweiter Namespace.
2. **Strikte V3/V4-Trennung in Skills + Abilities.** Verwechslung ist die größte Fehlerquelle.
3. **V4 ist Default, V3 ist Legacy.** Neue Pages gehen immer in V4 Atomic. V3-Pfade bleiben nur für Rückwärtskompatibilität / bestehende Sites.
4. **Elementor V3 ≠ Plugin V2.** Plugin heißt `adrianv2`, weil es das zweite Adrian-Plugin ist. **Nicht** weil es Elementor V2 wäre. Diese Verwechslung wird in allen Skills explizit dokumentiert.
5. **Alle neuen Skills heißen `adrianv2-*`.** Niemals `adrianv3-` (würde „Adrian V3" suggerieren).
6. **Skill-Sichtbarkeit = `manage_options` only.** Kein Leak auf Multi-Author-Sites.
7. **Jede Phase endet mit:** `php -l` über alle geänderten Files + `mcp-adapter-discover-abilities` auf test4 + Live-Smoke-Test einer repräsentativen Ability.
8. **Keine Commits ohne Bash-Verifikation** (gleicher `dir`-/`findstr`-Workflow wie nach BOM-Fix).

---

## Phase 0 — Foundation: Helpers konsolidieren (1 Tag)

**Ziel:** Eine **kanonische** Version-Detection-Funktion, die von überall im Plugin genutzt wird. Aktuell gibt es drei Stellen, die parallel existieren (`Elementor_WC_Bridge::resolve_version`, `V4_Props::is_atomic_supported`, `Elementor_WC_Bridge::detect_page_version`) — das ist Quell für Verwirrung.

### 0.1 Neuer Helper `class-elementor-version-resolver.php`

**Datei:** `includes/helpers/class-elementor-version-resolver.php`

**Public API:**
```php
namespace Novamira\AdrianV2\Helpers;

final class Elementor_Version_Resolver {
    public const VERSION_V3 = 'v3';
    public const VERSION_V4 = 'v4';
    public const VERSION_AUTO = 'auto';

    /**
     * Detects Elementor version on a per-page basis.
     * Reads _elementor_data, walks tree, checks for atomic widgets.
     * Falls back to global ELEMENTOR_VERSION (4.0+) for site-level default.
     */
    public static function resolve(int $post_id, string $target = self::VERSION_AUTO): string;

    /**
     * Site-wide: is Elementor 4.x (atomic-capable) installed?
     */
    public static function site_is_v4(): bool;

    /**
     * Per-page: does the saved Elementor data contain atomic widgets/containers?
     */
    public static function page_is_v4(int $post_id): bool;

    /**
     * Returns the V4 atomic schema status for the current site.
     * Used by abilities to refuse early if a V4-only operation is requested on V3.
     */
    public static function atomic_capabilities(): array;
}
```

**Migration:**
- `Elementor_WC_Bridge::resolve_version()` → ruft `Elementor_Version_Resolver::resolve()` auf (Backward-Compat)
- `V4_Props::is_atomic_supported()` → ruft `Elementor_Version_Resolver::site_is_v4()` auf
- `Elementor_WC_Bridge::detect_page_version()` → ruft `Elementor_Version_Resolver::page_is_v4()` auf

**Akzeptanzkriterien:**
- [x] Eine kanonische Funktion, drei Aufrufer migriert
- [x] PHPUnit-Tests: 12 Cases (v3 page, v4 page, mixed, missing data, etc.)
- [x] Smoke-Test: `resolve(123, 'auto')` liefert `'v3'` für eine Test3-Seite, `'v4'` für eine V4-Atomic-Seite

### 0.2 `categories.php` ergänzen — V3/V4-Tagging

**Datei:** `includes/categories.php`

Aktuell: 14 Categories, alle vermischen V3 + V4 oder sind V4-only ohne es zu kennzeichnen.

**Plan:** Metadata-Feld `elementor_version` pro Category, das die Inspector-UI und `discover-abilities`-Output nutzt:

| Slug | Elementor-Version | Begründung |
|---|---|---|
| `adrianv2-elementor` | `mixed` | Core-Operations wirken auf beide Welten |
| `adrianv2-global-classes` | `v4` | V4-only Konzept |
| `adrianv2-v4-management` | `v4` | Schon im Namen |
| `adrianv2-variables` | `v4` | V4-only |
| `adrianv2-batch` | `mixed` | Wirkt auf beide Welten |
| `adrianv2-atomic` | `v4` | V4-only |
| `adrianv2-media` | `mixed` | Unabhängig |
| `adrianv2-audit` | `mixed` | Beide Welten werden auditiert |
| `adrianv2-php-sandbox` | `mixed` | Unabhängig |
| `adrianv2-custom-code` | `mixed` | Unabhängig |
| `adrianv2-seo` | `mixed` | Unabhängig |
| `adrianv2-a11y` | `mixed` | Unabhängig |
| `adrianv2-utilities` | `mixed` | Unabhängig |

**Plan:** In `includes/categories.php` ein `'meta' => ['elementor_version' => 'v4' | 'v3' | 'mixed']` zu jeder Category hinzufügen. Hilft der künftigen UI und dem neuen V3/V4-Skill-Matcher (Phase 1).

**Akzeptanzkriterien:**
- [x] Alle 14 Categories haben `meta.elementor_version` (inzwischen 21 Categories)
- [x] Keine vorhandene Ability ändert ihr Verhalten
- [x] PHPUnit-Test bestätigt dass `wp_get_ability_categories()` das Meta zurückgibt

### 0.3 Helper-Bootstrap-Reihenfolge

**Datei:** `includes/helpers/bootstrap.php`

Stellt sicher, dass `Elementor_Version_Resolver` **vor** allen anderen Helfern geladen wird (es ist Dependency für `V4_Props`, `Elementor_WC_Bridge`, `V4_Color_Contrast` etc.).

**Akzeptanzkriterien:**
- [x] Helper-Reihenfolge alphabetisch + `class-elementor-version-resolver.php` als erste Datei im `require_once`-Block
- [x] Kein `class_exists`-Guard bricht

---

## Phase 1 — Skills trennen (2 Tage)

**Ziel:** Skill-Bibliothek aufbauen, die jedem Agent automatisch das richtige Wissen zur richtigen Zeit liefert. Skills sind **additiv** — bestehende Plugin-Funktionen bleiben 100% identisch.

### 1.1 Skill-Konventionen

Jeder V2-Skill folgt diesem Schema:

```markdown
---
title: adrianv2-<slug>
description: <1-Satz-Trigger-Description>
---

# AdrianV2 Skill: <Titel>

> **Plugin:** novamira-adrianv2 (Adrian V2 — V2 wegen "zweites Adrian-Plugin", NICHT Elementor V2)
> **Elementor-Welt:** V3 | V4 | mixed
> **Required Capabilities:** manage_options (Skill-Sichtbarkeit)
> **Required Abilities:** <Liste von MCP-Slugs>

## Wann aktivieren
<Trigger-Phrasen>

## Was tun
<Schritt-für-Schritt>

## Gotchas
<Plugin-V2-spezifische Fehlerquellen>
```

**Skill-Visibility-Hook:** V2-Skills werden per Skill-Filter `mcp.public = true` UND `skill.meta.visibility = 'admin-only'` markiert. Die Sichtbarkeitsprüfung passiert im `execute_callback` über `current_user_can('manage_options')`. Andere User sehen den Skill in `discover-abilities` nicht.

### 1.2 Skill-Inventar (8 Skills, alle mit `adrianv2-`-Präfix)

| Slug | Elementor-Welt | Beschreibung |
|---|---|---|
| `adrianv2-v4-invariants` | V4 | Die 5 V4-Invarianten (Style-Binding, Style-Location, ID-Format, Image-Src, Custom-CSS). Immer aktiv bei jedem V4-Edit. |
| `adrianv2-v4-atomic-build` | V4 | Komplette Anleitung zum Erstellen einer V4 Atomic Page mit `setup-v4-foundation` → `batch-build-page`. |
| `adrianv2-v3-page-edit` | V3 | Wie man eine bestehende V3-Seite editiert ohne versehentlich Atomic einzustreuen. Mixed-Container-Verbot. |
| `adrianv2-v3-to-v4-convert` | mixed | Strategie für `kit-convert-v3-to-v4` + Vor- / Nach-Audits. |
| `adrianv2-token-mapping` | V4 | Wie `class-v4-props` Wrapping funktioniert, wann `$$type`, wann scalar, wann `var(--e-global-*)`. |
| `adrianv2-discover-abilities-protocol` | mixed | Wie man `mcp-adapter-discover-abilities` richtig liest, Capability-Filter, V3/V4-Erkennung. |
| `adrianv2-self-audit` | mixed | Vor jedem Build: BOM-Check, Strict-Mode-Probe, Ability-Register-Count, Schema-Drift. |
| `adrianv2-rollback-build` | V4 | `Build_Versioning::rollback()` Pattern, wann anwenden, was es NICHT kann. |

### 1.3 Skill-Deployment: einmaliger Install-Helper

**Datei:** `includes/skills/installer.php` (neu)

**Funktion:** Beim Plugin-Activate werden die 8 Skills per `wp_insert_post(['post_type' => 'novamira_skill'])` angelegt, falls noch nicht vorhanden (idempotent). Beim Deactivate werden sie **nicht** gelöscht (User könnte sie editiert haben).

**Storage:** Novamira-Skills werden als CPT `novamira_skill` gespeichert — der ist im offiziellen Novamira-Core-Plugin registriert. V2 nutzt ihn nur als Storage, die UI ist im Core-Plugin.

**Akzeptanzkriterien:**
- [x] 9 Skills mit definierten Inhalten (8 geplant + adrianv2-live-edit)
- [x] Activate/Deactivate Cycle lässt Skills unangetastet
- [x] Editierter Skill bleibt erhalten nach Plugin-Update
- [x] PHPUnit-Test: `installer::install()` ist idempotent (zweiter Lauf fügt nichts hinzu)

### 1.4 V2-spezifische Server-Instructions registrieren

**Datei:** `includes/integrations/server-instructions.php` (neu)

**Funktion:** Registriert einen `add_filter('novamira_discover_abilities_instructions', ...)`-Hook, der die V2-spezifischen Regeln anhängt:

```
## novamira-adrianv2 Plugin Conventions
- Plugin-Slug: novamira-adrianv2 (Adrian V2 — the second Adrian-built plugin; NOT Elementor V2)
- V3/V4 Trennung: Each ability carries `category` and is matched against page-level elementor_version.
  Use adrianv2-v4-* abilities only on pages detected as V4 via `detect-elementor-version`.
- MCP-Signature: { ability_name: string, parameters: object } — never `ability` or `abilityName`.
- Build-Call: `elementor-set-content` with `content: [ARRAY!]` (never `adrians-batch-build-page` for Framer).
- V4-Invariants: See `adrianv2-v4-invariants` skill.
- Gotchas: See `novamira-adrianv2/docs/GOTCHAS.md` (XSS in page_js, MIME-spoofing, Path-Traversal, Image-Src url-key).
```

**Akzeptanzkriterien:**
- [x] `mcp-adapter-discover-abilities` auf test4 enthält den V2-Block am Ende der `novamira_instructions`
- [x] Block ist **nur** sichtbar wenn V2-Plugin aktiv ist (Filter prüft `function_exists`)
- [x] Block ist konfigurierbar via Option `novamira_adrianv2_server_instructions_enabled` (Default: `true`)

---

## Phase 2 — Abilities härten + neue Abilities (3 Tage)

**Ziel:** Bestehende 57 Abilities mit V3/V4-Guards + 3 neue Abilities für die Use-Cases aus Ideen 6, 7, 8, 9.

### 2.1 V4-Guard in V4-only Abilities einbauen

**Betroffen (alle Abilities die nur auf V4-Atomic-Trees funktionieren):**

| Ability | V4-only? | Aktueller Guard | Plan |
|---|---|---|---|
| `add-flexbox`, `add-div-block` | ✅ | keiner | `Elementor_Version_Resolver::site_is_v4()` Guard |
| `add-atomic-*` (10x) | ✅ | keiner | dito |
| `setup-v4-foundation` | ✅ | keiner | dito |
| `kit-convert-v3-to-v4` | special | keiner | Erlaubt, aber Output-Validation muss V4-Result prüfen |
| `apply-global-class`, `add-global-class-variant`, `edit-global-class-variant`, `remove-global-class` | ✅ | keiner | dito |
| `apply-variable-to-class` | ✅ | keiner | dito |
| `batch-create-variables` | ✅ | keiner | dito |
| `create-atomic-widget` | ✅ | keiner | dito |

**Pattern (am Beispiel `add-flexbox`):**
```php
public static function execute(array $input): array|WP_Error {
    if (!Elementor_Version_Resolver::site_is_v4()) {
        return new WP_Error('v4_required', sprintf(
            __('%s requires Elementor 4.0+. Detected version: %s. Use legacy container/widget abilities for V3 sites.', 'novamira-adrianv2'),
            'add-flexbox',
            Elementor_Version_Resolver::site_version_string()
        ));
    }
    // ...existing logic
}
```

**Akzeptanzkriterien:**
- [x] Alle 16+ V4-only Abilities haben Guard
- [x] Test: `add-flexbox` auf V3-Site liefert `WP_Error` mit klarem Hint
- [x] Test: `add-flexbox` auf V4-Site funktioniert wie bisher
- [x] Bestehende Tests laufen weiter durch

### 2.2 Per-Page-V3/V4-Check für `batch-build-page`, `batch-class`, `patch-element-styles`

Diese drei sind `mixed` (wirken auf beide Welten), aber MÜSSEN vor dem Schreiben prüfen, in welcher Welt die Ziel-Seite lebt — sonst Hybrid-Bäume.

**Akzeptanzkriterien:**
- [x] Vor jedem Write: `Elementor_Version_Resolver::page_is_v4($post_id)` → bei Mismatch zu User-Eingabe `WP_Error` mit „Möchten Sie in V4 konvertieren?"
- [x] `opt-in: true` Flag zum Ignorieren des Mismatches (für Power-User)

### 2.3 Neue Ability `novamira-adrianv2/sync-schema` (Idee 7)

**Datei:** `includes/abilities/v4-management/class-sync-schema.php`

**Zweck:** Exportiert das **live** V4-Prop-Type-Schema als JSON. Pipeline kann das per MCP ziehen statt eine gecachte Pipeline-Kopie zu nutzen.

**Input:**
- `format`: `full` | `compact` (Default `compact`)
- `sections`: Array von Section-Slugs (z.B. `["dimensions", "background", "typography"]`)

**Output:**
```json
{
    "version": "1.0.0",
    "elementor_version": "4.1.0-beta1",
    "generated_at": "2026-06-17T15:00:00Z",
    "schema": { /* V4_Props::get_schema() output */ }
}
```

**Akzeptanzkriterien:**
- [x] Fähigkeit registriert sich unter `novamira-adrianv2/sync-schema`
- [x] `compact`-Modus liefert ~5 KB JSON
- [x] `full`-Modus liefert ~50 KB JSON
- [x] Funktioniert auch wenn V2-Plugin das **einzige** Plugin mit V4-Schema-Kenntnis ist

### 2.4 Neue Ability `novamira-adrianv2/self-audit` (Idee 9)

**Datei:** `includes/abilities/utilities/class-self-audit.php`

**Input:**
- `include_bom_check`: bool (Default `true`)
- `include_strict_probe`: bool (Default `true`)
- `include_ability_count`: bool (Default `true`)

**Output:**
```json
{
    "overall_status": "ok" | "warning" | "error",
    "checks": [
        {
            "name": "bom_check",
            "status": "ok",
            "files_checked": 26,
            "files_with_bom": 0
        },
        {
            "name": "php_strict_probe",
            "status": "ok",
            "details": "Test file with declare(strict_types=1) loaded without fatal"
        },
        {
            "name": "ability_count",
            "status": "ok",
            "expected": 57,
            "actual": 57,
            "missing": [],
            "unauthorized_extra": []
        }
    ]
}
```

**Akzeptanzkriterien:**
- [x] Self-audit dauert < 2 Sekunden
- [x] Auf test4 läuft der Audit durch und meldet `ok`
- [x] Vor Phase-2-Deploy wird Audit gegen Solar ausgeführt und dokumentiert

### 2.5 Neue Ability `novamira-adrianv2/rollback-build` (Idee 8)

**Datei:** `includes/abilities/v4-management/class-rollback-build.php`

**Voraussetzung:** Helper `class-build-versioning.php` muss eine `get_revisions($post_id, $limit)`-Methode haben (existiert vermutlich schon, prüfen).

**Input:**
- `post_id`: int
- `revision_id`: int (optional, sonst: letzte „good" Revision)

**Output:**
```json
{
    "post_id": 123,
    "rolled_back_to": 4567,
    "diff_summary": { "sections_added": 0, "sections_removed": 2, "widgets_changed": 5 }
}
```

**Akzeptanzkriterien:**
- [x] Rollback erstellt **vor** dem Schreiben ein Elementor-Revision-Snapshot
- [x] Bei `revision_id = null`: letzte `status = 'good'` Revision
- [x] Funktioniert nur auf V4-Atomic-Pages (Guard aus 2.1)

---

## Phase 3 — Quality Gates + Tests (2 Tage)

**Ziel:** Sicherstellen, dass die Phasen 0-2 nichts kaputt gemacht haben und neue Funktionalität testbar bleibt.

### 3.1 PHPUnit-Tests

**Neue Tests:**
- `tests/Helpers/Elementor_Version_Resolver_Test.php` — 12 Cases
- `tests/Abilities/Self_Audit_Test.php` — 6 Cases
- `tests/Abilities/Sync_Schema_Test.php` — 4 Cases
- `tests/Abilities/Rollback_Build_Test.php` — 6 Cases
- `tests/Abilities/V4_Guard_Test.php` — 8 Cases (eine V4-only Ability + V3-Site simuliert)

**Ziel:** bestehende 52 Tests + ~36 neue = **88/88 grün**.

### 3.2 Live-Smoke-Tests auf test4 + Solar

| Test | test4 | Solar |
|---|---|---|
| `mcp-adapter-discover-abilities` (alle 57 + neue 3 da) | ✅ | ✅ |
| `novamira-adrianv2/self-audit` overall = ok | ✅ | ✅ |
| `novamira-adrianv2/sync-schema` Format=compact | ✅ | ✅ |
| `novamira-adrianv2/setup-v4-foundation` | ✅ | ✅ |
| `novamira-adrianv2/rollback-build` mit Fake-Post-ID (negativ) | ✅ | ✅ |
| `add-flexbox` auf V4-Testseite | ✅ | ✅ |
| `add-flexbox` simuliert auf V3-Seite → WP_Error | ✅ | ✅ |
| V2-Server-Instructions-Block in Discover-Output | ✅ | ✅ |

### 3.3 Update `docs/ABILITY-SELECTION-GUIDE.md`

Die existierende Anleitung muss einen neuen Abschnitt bekommen: **„V3 vs V4 — welche Ability wann?"** mit Entscheidungsbaum:

```
Brauchst du V4 Atomic? (ja für neue Seiten)
├── Ja  → setup-v4-foundation → batch-build-page
└── Nein (Legacy V3) → elementor-set-content (mit container statt e-flexbox)
```

### 3.4 README + CHANGELOG

- `README.md`: Neue Sektion „V3/V4-Trennung in V2" mit kurzer Erklärung
- `CHANGELOG.md`: Eintrag für Version 1.1.0:
  ```
  ## 1.1.0 (2026-06-XX)
  ### Added
  - Skill installer: 8 adrianv2-* skills auto-installed
  - V2 server-instructions filter (context for connected agents)
  - Abilities: sync-schema, self-audit, rollback-build
  - Helper: Elementor_Version_Resolver (kanonische V3/V4-Detection)
  ### Changed
  - 16 V4-only Abilities guarded with site_is_v4() check
  - Elementor_WC_Bridge::resolve_version() delegates to Elementor_Version_Resolver
  - V4_Props::is_atomic_supported() delegates to Elementor_Version_Resolver
  - categories.php: all categories carry meta.elementor_version
  ### Fixed
  - (BOM-Fix not in this changelog — that was a hotfix)
  ```

---

## Phase 4 — Dokumentation + Übergabe (1 Tag)

**Ziel:** Was in den vorherigen Phasen gebaut wurde, ist **nutzbar dokumentiert**.

### 4.1 `docs/SKILLS-INVENTORY.md` ⚠️ NOCH OFFEN

Inventory aller V2-Skills mit:
- Slug
- Elementor-Welt (V3 / V4 / mixed)
- Trigger-Phrasen (was der User sagen muss, damit der Skill greift)
- Beispiel-Aufruf

### 4.2 `docs/V3-V4-DECISION-TREE.md` ⚠️ NOCH OFFEN

Entscheidungsbaum als ASCII-Diagramm:

```
Neue Seite?
├── Ja → V4 Atomic (default)
│         ├── setup-v4-foundation
│         ├── batch-build-page (mit Atomic-Tree)
│         └── post-build: layout-audit + class-audit + variable-audit
└── Nein, edit bestehende Seite
          ├── detect-elementor-version (gibt v3 | v4 zurück)
          ├── v3 → elementor-set-content (mit v3 widgets)
          └── v4 → elementor-set-content (mit atomic widgets)
                    oder: patch-element-styles (für punktuelle Edits)
```

### 4.3 `docs/CHANGELOG-v2-detailed.md` ⚠️ NOCH OFFEN

Detaillierte Migrations-History: was wann von `Elementor_WC_Bridge::resolve_version` zu `Elementor_Version_Resolver` umgezogen ist.

### 4.4 Memory-Update ⚠️ NOCH OFFEN

Eintrag in `PROJECT_STATE.md`:
```
## 4. Plugin novamira-adrianv2

### Version 1.1.0 (2026-06-XX)
- **V3/V4-Trennung etabliert:** Alle Abilities haben expliziten Guard.
  Helper `Elementor_Version_Resolver` ist die kanonische Detection-Quelle.
- **Skill-Bibliothek:** 8 adrianv2-* Skills auto-installed.
  V2-spezifisches Wissen wird on-demand geladen, nicht Repo-Lookup.
- **3 neue Abilities:** sync-schema, self-audit, rollback-build.
- **Server-Instructions-Filter:** V2-Block in discover-abilities-Output.
- **Tests:** 52 → 88 PHPUnit-Tests grün.
```

---

## Phasen-Übersicht

| Phase | Dauer | Output | Risiko | Status |
|---|---|---|---|---|
| 0 — Foundation | 1 Tag | 1 Helper, 1 Meta-Field, 1 Reihenfolge-Fix | niedrig (rein additiv) | ✅ Done |
| 1 — Skills | 2 Tage | 8 Skills + Installer + Server-Instructions | niedrig (kein Code-Pfad geändert) | ✅ Done |
| 2 — Abilities | 3 Tage | 16 Guards + 3 neue Abilities | mittel (Behavior-Change für V4-only Abilities auf V3-Sites) | ✅ Done |
| 3 — Tests + Doku | 2 Tage | +36 Tests, 2 neue Docs, CHANGELOG | niedrig | ✅ Done |
| 4 — Übergabe | 1 Tag | Inventar + Decision-Tree + Memory | niedrig | ⚠️ Offen |
| **Total** | **9 Tage** | sauberes 1.1.0 Release | | 80% done |

---

## Risiko-Mitigation

### Was schiefgehen kann
1. **V4-Guard bricht bestehende V3-Sites auf test4**: Mitigation — vor Phase 2 Rollout: Audit auf test4 ob V3-Pages existieren, ggf. Site-Wide-Test mit allen V4-Abilities.
2. **Skill-Installer kollidiert mit Custom Skills**: Mitigation — `installer::install()` prüft `wp_insert_post` Result, bricht nicht ab wenn bereits vorhanden.
3. **`Elementor_Version_Resolver::resolve()` ist zu langsam für Bulk**: Mitigation — Cache pro Post-ID mit `wp_cache_*`, 5 Min TTL, ähnlich wie bei `V4_Props::load_v4_variables`.
4. **Self-Audit produziert falsche Positiv-Meldung bei BOM**: Mitigation — strikter 3-Byte-Vergleich, Test deckt Edge-Case (Datei mit BOM aber Inhalt nicht UTF-8) ab.

### Rollback-Strategie
- Jede Phase endet mit eigenem Commit
- Reihenfolge ist **strikt additiv** — `git revert` einer Phase entfernt nur diese Phase
- Phase 2 (V4-Guards) ist **die einzige Phase, die User-facing Behavior ändert** — bei Problemen `git revert` + Hotfix-Ability

---

## Done-Definition (für jede Phase)

- [x] Code committed auf Branch `<phase-name>`
- [x] PHPUnit grün (`php composer.phar vendor/bin/phpunit`)
- [x] PHP-Syntax sauber (`php -l` über alle geänderten Files)
- [x] Kein neuer Eintrag in `Diagnostics::errors()` auf Solar
- [x] Live-Smoke-Test auf test4 bestanden
- [x] CHANGELOG.md aktualisiert (nur bei Phase 2 + 4)
- [ ] Memory-Update für nächste Session (noch offen)

---

## Was NICHT in diesem Bauplan steht

- **Kein neues Plugin.** Keine Multi-Plugin-Architektur. Alles in `novamira-adrianv2/`.
- **Kein Konflikt mit Novamira-Core.** V2-Skills werden im selben CPT `novamira_skill` gespeichert, aber mit `adrianv2-`-Präfix, damit sie filterbar bleiben.
- **Keine Novamira-Pro-Features.** Pro-Upsell bleibt wie gehabt (gesteuert vom Core-Plugin).
- **Keine Gutenberg-Queue.** Explizit ausgeschlossen (siehe User-Wunsch „Gutenberg brauche ich nicht").
- **Keine Schema-Breaking-Changes.** Bestehende 57 Abilities ändern nur dann Behavior, wenn der V4-Guard auf V3-Sites greift (was erwünscht ist).

---

**Bereit zur Freigabe. Sag Bescheid, sobald Phase 0 starten darf — dann committe ich Helper + Tests als ersten Block.**
