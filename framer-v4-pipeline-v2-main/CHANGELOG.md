# Changelog — framer-v4-pipeline-v2

## [v0.9.0] — 2026-06-12

### Repo-Cleanup & Infrastruktur

- **Root `.gitignore`**: node_modules, vendor, results/, *.zip, *.log, IDE-Dateien
- **V1-Plugins geloescht**: `novamira-adrians/`, `novamira-adrians-extra/`, redundante `includes/`
- **`results/` geloescht**: 19 JSON-Analyse-Artefakte entfernt
- **Leere Directories**: `batch/`, `v4-management/` entfernt
- **Plugin-Infrastruktur**: `composer.json`, `phpcs.xml`, `README.md`, `CHANGELOG.md`
- **Skills auf solar.local**: 3 Skills per MCP-Bridge installiert
- **BLUEPRINT.md**: v0.8.0→v0.9.0, 3 bekannte Issues abgehakt
- **HANDOFF.md**: Alte `novamira-adrians-extra` Referenzen bereinigt

### Pipeline-Verbesserungen

- **Rollback Cleanup**: `cleanupOldBackups(24)` + CLI `--cleanup --max-age 48`
  - Verwaiste/korrupte Backups anhand Datei-mtime bereinigt
- **Split-Large-Tree Timeout-Fallback**: Batching wenn Tree >400 Elemente / >800KB
  - Batch 0: `elementor-set-content`, Batches 1+: gruppierte `elementor-add-element`
- **Pipeline unstaged Changes**: 17 Modifikationen + 3 veraltete Dateien committed
- **V4 Research-Dokumente**: `V4_DEEP_RESEARCH.md` + `V4_DESIGN_SCHEMA_REPORT.md`

### Plugin CI/CD & Testing

- **GitHub Actions CI**: `.github/workflows/novamira-adrianv2-ci.yml`
  - 3 Jobs: PHPCS + Psalm + PHPUnit mit Composer-Cache
- **PHPUnit Bootstrap**: `phpunit.xml.dist` + `tests/bootstrap.php` + `tests/mock-functions.php`
- **20+ WordPress-Mock-Funktionen**: `add_action`, `add_filter`, `get_option`, `wp_insert_post`, etc.
- **Smoke-Test**: `SetupV4FoundationTest.php` mit `#[CoversClass]` (PHPUnit 10+)
- **`class-convert-kit-to-v4.php`** → `_deprecated/`

### Plugin-Sync mit solar.local

- `class-build-versioning.php` → WP-Plugin kopiert
- `class-v4-color-contrast-22.php` → WP-Plugin kopiert
- `bootstrap.php` (Deprecated-Pfad korrigiert) → WP-Plugin kopiert

### Novamira-Plugins Repo

- `emcp-tools/` + `novamira/` + `novamira-pro/` in separates Repo ausgelagert
- Repo: `github.com/Adilinu94/Novamira-Plugins`

### Test-Status
- `npm test` → 52/52 ✅
- `npm run test:e2e` → 12/12 ✅
- `npm run test:all` → 64/64 ✅

## [v0.8.0] — 2026-06-12

### PIPELINE_AUDIT_REPORT — 15 Verbesserungen implementiert

Basierend auf [PIPELINE_AUDIT_REPORT](./PIPELINE_AUDIT_REPORT.md) (Deep Research, Post-4943).

#### 🔴 P0 — Kritisch
| Fix | Datei | Änderung |
|-----|-------|----------|
| P0-1 | `convert-xml-to-v4.js` | `--gc` Default `true`, `--no-gc` zum Deaktivieren |
| P0-2 | `validate-v4-tree.js` | 7. Vital-Check `DOM_DEPTH`: ≤3 OK, 4-5 Warnung, ≥6 Error |
| P0-3 | `lib/framer-utils.js` | `wrapHtmlContent` bereits vorhanden — kein Code nötig |

#### 🟡 P1 — Performance / Korrektheit
| Fix | Datei | Änderung |
|-----|-------|----------|
| P1-1 | `generate-global-classes.js` | `--apply` Modus: lokale Tree-Deduplizierung ohne MCP |
| P1-2 | `convert-xml-to-v4.js` | RC-08: Root-Container (`depth===0`) vor Positions-Filterung geschützt |
| P1-3 | `post-build-auto-fix.js` | `fixDomDepth()` + `--fix-dom-depth`: Single-Child-Container rekursiv flatten |
| P1-4 | `run-post-build-qa.js` | `--tree` + 4 Deep-Checks: GC_COVERAGE, DOM_DEPTH, RESPONSIVE_COVERAGE, UNUSED_STYLES |
| P1-5 | `framer-pre-build-validate.js` | 13. Guard `GC_POTENTIAL`: warnt bei >10, blockt Build bei >20 Duplikaten |

#### 🟢 P2 — DX / Robustheit
| Fix | Datei | Änderung |
|-----|-------|----------|
| P2-1 | `auto-scale-responsive.js` | Bereits gut (RC-14 + RC-19) — kein Fix nötig |
| P2-2 | `check-v4-requirements.js` | `--server-info`: php_max_input_vars, memory_limit, Tree-Größe |
| P2-3 | `parallel-pre-build.js` | `--gc-output` Flag statt hardcoded `gc-plan.json` |
| P2-4 | `framer-animation-extractor.js` | RC-20: +6 Mappings (rotate, skew, opacity+translateX/scale/rotate, Triple) |
| P2-5 | `tests/pipeline.test.js` | 5 neue Test-Blöcke für P0/P1 (52→52 Tests, alle grün) |
| P2-6 | `extract-responsive-breakpoints.js` | `--container-queries` + `extractAtBlock()` für @container Support |
| P2-7 | `section-compare.js` | Bereits ausgereift — kein Fix nötig |

### Skill Update
- `novamira-skill/elementor-v4-build.md` → v2.0: 13 neue Pipeline-Features, 4 Deep-Checks, 9 Fehler-Einträge
- `novamira-ability-code-injector/` → archiviert als `_archived-novamira-ability-code-injector/`

### Ability-Migration abgeschlossen
- ~120+ alte `novamira/adrians-*` → `novamira-adrianv2/*` in 20+ Dateien aktualisiert
- `PIPELINE_AUDIT_REPORT.md`: 1 letzter alter Eintrag gefixt
- `V4_DEEP_RESEARCH.md`, `V4_DESIGN_SCHEMA_REPORT.md`: bereinigt

### Test-Status
- `npm test` → 52/52 ✅
- `npm run test:e2e` → 12/12 ✅
- `npm run test:all` → 64/64 ✅

## [v0.7.0] — 2026-06-12

### Added
- `html-to-widget-plan.js`: Brücke zu `novamira-adrianv2/html-to-elementor-widget-plan` mit `--execute` (McpBridge) + Plan-Fallback + Wizard-Integration
- `widget-plan` + `widget-plan-execute` npm-Scripts
- `lint:version` Script: checkt `package.json` Version gegen `CHANGELOG.md`
- `.env.example`: 16 Variablen (Workspace, MCP, Validation, Performance)
- `wizard.js preflight` Subcommand: 8 Checks, farbige ✓/✗-Tabelle, `--format=json`
- `wizard.js dry-run` Subcommand: Build-Plan ohne Schreibzugriff
- `wizard.js preview` Subcommand: Preview-Page via McpBridge (get→create→set)
- `wizard.js promote` Subcommand: Backup + Content-Transfer auf Live-Seite
- `wizard.js` interaktive Error Recovery: [R]etry/[S]kip/[F]ix/[A]bort + `runWithRecovery()`
- `wizard.js serve` Subcommand: HTTP-API (`GET /health`, `POST /build`, `GET /builds/:id`)
- `scripts/parallel-pre-build.js`: `Promise.allSettled` für 5 parallele Sub-Steps
- `scripts/lib/mcp-cache.js`: MCP-Discovery-Cache mit TTL + atomic write
- `tests/mcp-mock-server.js`: Lokaler Mock mit 15 Ability-Responses
- `.github/workflows/ci.yml`: 7 CI-Jobs (test, e2e, schema, mcp-mock, visual, lint, syntax)

### V2-Plugin (novamira-adrianv2)
- `class-execute-build-plan.php`: Mega-Ability — 18+ Agent-Turns → 1 Turn
- `class-build-versioning.php`: CPT `elementor_build` mit Meta-Boxes
- `class-v4-color-contrast-22.php`: WCAG 2.2 — Target Size + Focus Appearance
- `resolve_background_color()`: Öffentliche A11y-Methode mit Parent-Chain-Walking + `inconclusive` Flag
- `fix-color-contrast` preview-Mode: HTML Side-by-Side Diff + Backward-Compat `proposed` Feld

### Changed
- **Versionsdrift behoben**: `package.json` → `0.7.0`, alle Doku-Stamps synchronisiert
- `wizard.js`: Preflight + Dry-Run + Serve Subcommands integriert
- `bootstrap.php` (main): `class-build-versioning.php` + `class-v4-color-contrast-22.php` geladen
- `bootstrap.php` (elementor): `class-execute-build-plan.php` in Dateiliste + Auto-Registration
- `BLUEPRINT.md`, `INTEGRATION-PLAN.md`, `SESSION-STATE.md`: auf v0.7.0 synchronisiert

### Infrastructure
- GSD-Projekt initialisiert (`.planning/`) — 20 Pläne, 7 Phasen, 100% completed
- Altes Plugin-Projekt archiviert (`.planning-novamira-plugin/`)
- `CHANGELOG.md` als Release-Historie

## [v0.6.0] — 2026-06-11

### Added
- **Integration Fixes A-H** (INTEGRATION-PLAN.md vollständig umgesetzt):
  - **Fix A**: `mcp-bridge.js` — JSON-RPC 2.0 Protokoll + Session-Handshake (`--self-test`)
  - **Fix B**: `asset-to-wp-media.js` — `--execute` Batch-Upload via McpBridge
  - **Fix D**: `check-v4-requirements.js` — `--auto-call` via McpBridge + `wizard.js` 3-stufiger Fallback
  - **Fix E**: `generate-global-classes.js` — `--execute` direkte GC-Erstellung + Tree-Rückschreibung
  - **Fix G**: `novamira-skill/framer-v4-pipeline.md` — Cache-Regel-Doku + npm-Shortcuts
  - **Fix H**: `mcp-bridge.js` — WP REST Fallback für 12 Endpoints
- npm-Scripts: `gc-execute`, `asset-upload`, `check-v4-auto`, `test:bridge`, `widget-plan`, `widget-plan-execute`
- `schemas/v4-prop-type-schema.json` Fixture für E2E-Tests
- `.mcp.json` Konfigurationsdatei für solar.local

### Fixed
- **Windows ESM Bug** (`ERR_UNSUPPORTED_ESM_URL_SCHEME`): `pipeline.test.js` nutzt `pathToFileURL()`
- **E2E-10 Check-Count**: 6→7 (A1 a11y hinzugekommen)
- `.gitignore`: `.mcp.json` hinzugefügt

### Tested
- MCP-Bridge `--self-test` + `check-v4 --auto-call` live gegen solar.local ✅
- `npm run test:all` → 56/56 ✅ | `npm run test:integration` → 4/4 ✅
