# Roadmap — framer-v4-pipeline-v2

> **Erstellt:** 2026-06-13 | **Quelle:** V4_DESIGN_IMPROVEMENTS_RESEARCH.md (v2)
> **Start:** Sprint 1 | **Ziel:** Design-Score 25% → 90%+
> **Status:** ✅ Alle 11 Sprints abgeschlossen (184 Tests, 30 Requirements)

---

## Phase 1–7: Sprints 1–7 — Foundation ✅ Complete

Details in PLAN-1.md bis PLAN-7.md. Zusammenfassung:
- **Sprint 1:** C2 Grid, C4 Semantic GC, C5 Breakpoint, C6 GV-Sub, D3 Grid/Flex
- **Sprint 2:** A1 Components, A2 Interactions, C1 Preservation, C3 Easing
- **Sprint 3:** A3 Forms, B4 create-atomic-form, D2 Native Coverage
- **Sprint 4:** C3 Native Routing, structuralHash Dedup, A2 v4-tree Mode
- **Sprint 5:** FIX-7 p-limit, ENH-10 dark-mode, ENH-11 JSDoc
- **Sprint 6:** preflight-check.js, wizard batch, Wizard modular
- **Sprint 7:** FIX-10/11/12, 100 Tests (33 Suiten)

---

## Phase 8: Sprint 8 — Live Integration ✅ Complete

**Geschätzte Dauer:** ~4h | **Tatsächlich:** ~4h

| Task | Typ | Aufwand | Datei(en) |
|------|-----|---------|-----------|
| **ENH-12** Wizard --non-interactive | Enhancement | ~2h | `wizard.js`, `shared.js` |
| **ENH-13** measure-quality-metrics.js | Neues Script | ~1h | Neu |
| **FIX-13** Integration --live | Fix | ~0.5h | `integration.test.js` |
| **FIX-14** CI test-all | Fix | ~0.5h | `ci.yml`, `package.json` |

### Akzeptanzkriterien
- [x] `wizard.js --non-interactive --url <url> --post-id new` läuft ohne Prompts
- [x] 6 Qualitäts-Metriken (DOM, GC, GV, Grid, Components)
- [x] Live MCP-Tests gegen solar.local
- [x] CI `test-all` Job (127 Tests)
- [x] `npm test` → 105/105 (+5 Tests)
- [x] `npm run test:e2e` → 15/15

---

## Phase 9: Sprint 9 — Pipeline Hardening & Plugin Fixes ✅ Complete

**Geschätzte Dauer:** ~10h | **Tatsächlich:** ~12h
**Erwarteter Impact:** FramerExport CLI integriert, Schema-Sync funktionsfähig, Windows-Crash behoben, WCAG-Fixes, PHPUnit-Infrastruktur

| Task | Typ | Aufwand | Datei(en) |
|------|-----|---------|-----------|
| **ENH-16** FramerExport CLI | Feature | ~3h | `shared.js`, `wizard.js` |
| **Schema-Sync** REST Endpoint | Feature | ~2h | `sync-schema.js`, `bootstrap.php`, `class-v4-props.php` |
| **UV_HANDLE_CLOSING** | Fix | ~2h | `mcp-client.js`, `sync-schema.js` |
| **WCAG Threshold** 0.03928→0.04045 | Fix | ~0.5h | `class-v4-color-contrast.php` |
| **Contrast Ratio Test** #959595 | Fix | ~0.5h | `V4ColorContrast22Test.php` |
| **Extraction Exit Codes** | Fix | ~1h | 4 extraction scripts |
| **PHPUnit Setup** | Chore | ~2h | `composer.json`, `mock-functions.php` |
| **V4PropsSchemaTest** 31 Tests | Testing | ~1h | `V4PropsSchemaTest.php` |
| **Docs + PR** | Documentation | ~0.5h | `STATE.md`, `ROADMAP.md`, PR #1 |

### Akzeptanzkriterien
- [x] FramerExport CLI (v4.3.8) integriert — realer E2E-Durchlauf funktioniert
- [x] `spawnWithRetry` 3-step escalation (.cmd → bare → `shell:true`)
- [x] `GET /novamira/v1/prop-schema` → HTTP 200 mit Schema (12 types, 13 props)
- [x] Schema-Sync läuft ohne UV_HANDLE_CLOSING-Crash (Windows)
- [x] `process.exitCode` statt `process.exit()` — Node exitet natürlich
- [x] undici global dispatcher wird in `McpClient.close()` destroyed
- [x] Beide Contrast-Klassen nutzen WCAG-Threshold `0.04045`
- [x] Alle 4 Extraction-Scripte exit 0 für non-critical Results
- [x] Full Wizard Pipeline: 7/7 Extraction-Phasen SUCCESS
- [x] PHPUnit: 52 Tests, 145 Assertions, 0 Failures
- [x] `npm test` → 114/114 ✅
- [x] `npm run test:e2e` → 18/18 ✅
- [x] PR #1 offen: sprint-9-fixes → master

---

## Phase 10: Sprint 10 — CI/CD, Refactoring & Tooling ✅ Complete

**Geschätzte Dauer:** ~6h | **Tatsächlich:** ~4h
**Impact:** PHPUnit in CI, Contrast-Merge, Deploy-Script, 2 PRs

| Task | Typ | Aufwand | Datei(en) |
|------|-----|---------|-----------|
| **CI: PHPUnit Hardening** | CI/CD | ~0.5h | `novamira-adrianv2-ci.yml` |
| **CI: PHPUnit in Pipeline** | CI/CD | ~1h | `ci.yml` (8. Job) |
| **Plugin Deployment Script** | Tooling | ~1h | `deploy-plugin.sh` (neu) |
| **Contrast-Klassen mergen** | Refactoring | ~1.5h | `class-v4-color-contrast.php`, `class-v4-color-contrast-22.php` |

### Akzeptanzkriterien
- [x] PHPUnit Job in `novamira-adrianv2-ci.yml` ist mandatory gate (keine soft-fails)
- [x] PHPUnit Job in Pipeline `ci.yml` — 8. Job, `test-all` hängt davon ab
- [x] Plugin-Deployment per Script — `--dry-run`, `--force`, incremental modes
- [x] `V4_Color_Contrast_22` merged into `V4_Color_Contrast` (0 duplizierter Code)
- [x] PR #2: sprint-10 → master (4 commits)
- [x] Alle 52 PHPUnit-Tests passen
- [x] Plugin deployed nach solar.local (77 files)

---

## Phase 11: Sprint 11 — Archive Cleanup & CI Consolidation ✅ Complete

**Geschätzte Dauer:** ~2h | **Tatsächlich:** ~1h
**Impact:** 7 archived files deleted, 3 CI jobs consolidated into 1 workflow (11 jobs)

| Task | Typ | Aufwand | Datei(en) |
|------|-----|---------|-----------|
| **Archive Cleanup** | Maintenance | ~0.5h | `_archived/`, `_archived-novamira-ability-code-injector/` |
| **CI Consolidation** | CI/CD | ~1h | `ci.yml` (+phpcs, +psalm), `novamira-adrianv2-ci.yml` (deleted) |

### Akzeptanzkriterien
- [x] Alle _archived/ Verzeichnisse gelöscht (7 files)
- [x] `phpcs` + `psalm` Jobs in pipeline `ci.yml` (11 jobs total)
- [x] `.github/workflows/novamira-adrianv2-ci.yml` gelöscht
- [x] `test-all` gate includiert `phpcs` + `psalm`
- [x] PR #3: sprint-11 → master
- [x] Alle 184 Tests passen

---

## Qualitätssprung (Metriken)

| Metrik | Vorher | Sprint 1–7 | Sprint 8 | Sprint 9 | Sprint 10 | Sprint 11 |
|--------|--------|------------|----------|----------|-----------|-----------|
| DOM-Tiefe | 8 | ≤3 | ≤3 | ≤3 | ≤3 | ≤3 |
| Global Class % | 0% | ≥90% | ≥90% | ≥90% | ≥90% | ≥90% |
| GV-Substitution % | 0% | ≥95% | ≥95% | ≥95% | ≥95% | ≥95% |
| Grid-Nutzung | 0 | ≥35% | ≥35% | ≥35% | ≥35% | ≥35% |
| Components | 0 | ≥10 | ≥10 | ≥10 | ≥10 | ≥10 |
| Interaktionen | 0 | V4-native | V4-native | V4-native | V4-native | V4-native |
| **Pipeline Tests** | 49 | 100 | 105 | 114 | 114 | **114** |
| **E2E Tests** | 0 | 12 | 15 | 18 | 18 | **18** |
| **PHPUnit Tests** | 2 | 21 | 21 | 52 | 52 | **52** |
| **Total** | 51 | 133 | 141 | 184 | 184 | **184** |
