# STATE — framer-v4-pipeline-v2

> **Letztes Update:** 2026-06-14 — ENH-16 Complete (v0.14.0)

---

## Aktueller Status

```
Phase:     ✅ Alle 9 Sprints abgeschlossen + ENH-16 FramerExport
Branch:    main
HEAD:      (ENH-16: FramerExport CLI + spawnWithRetry + S14 E2E)
Tests:     114/114 ✅ (Pipeline) + 18/18 ✅ (E2E) + 7 Integration = 139 total
Version:   v0.14.0 (package.json ≡ CHANGELOG.md ≡ BLUEPRINT.md)
Remote:    origin https://github.com/Adilinu94/Test1206.git
```

---

## Aktiver Fokus

**Sprint 9: Performance, A11y & Security — ABGESCHLOSSEN** ✅
1. ✅ ENH-14: Pipeline Performance Profiler — profile-pipeline.js + 5 S35 Tests
2. ✅ ENH-15: axe-core A11y — visual-qa.js --a11y/--a11y-output + 4 S36 Tests
3. ✅ FIX-15: WCAG 2.2 PHPUnit — V4ColorContrast22Test.php (16 Assertions)
4. ✅ FIX-16: Media Filename Sanitization — guard_filename() + Extension-Whitelist
5. ✅ FIX-17: Media File-Type Validation — guard_file_content() + guard_mime_buffer()
6. ✅ Docs: BLUEPRINT, CHANGELOG, STATE synchronisiert auf v0.13.0
7. ✅ Tests: 110→114 Pipeline (5 S35 + 4 S36), 114/114 ✅

Naechster Milestone: ✅ ENH-16 abgeschlossen — FramerExport CLI integriert, Wizard --non-interactive funktionsfähig. Naechster: Plugin REST-Endpoint auf solar.local aktivieren für vollständigen Build-Durchlauf.

---

## Bekannte Issues

| Issue | Schwere | Status |
|-------|---------|--------|
| FramerExport CLI muss installiert werden | 🟡 Mittel | Blockiert echten E2E-Durchlauf |
| Live Integration --live benoetigt solar.local lokal | 🟢 Niedrig | --live Flag implementiert, wartet auf Umgebung |

---

## Letzte Aenderungen

- **2026-06-14**: ENH-16 abgeschlossen — FramerExport CLI (v4.3.8), Wizard --non-interactive läuft, spawnWithRetry mit shell:true, 3 S14 E2E Tests, v0.14.0
- **2026-06-14**: Sprint 9 abgeschlossen — ENH-14 Profile-Pipeline, ENH-15 A11y, FIX-15 WCAG 2.2, FIX-16/17 Media-Security, 114 Tests, v0.13.0
- **2026-06-14**: Sprint 8 abgeschlossen — ENH-12/13, FIX-13/14, Docs, 105→127 Tests, v0.12.0
- **2026-06-14**: Sprint 8 gestartet — PLAN-7.md committet
- **2026-06-13**: Sprint 7 abgeschlossen — FIX-10 --format markdown, FIX-11 wizard --help (6 cmd-*.js), FIX-12 token_name dedup (+12 Tests)
- **2026-06-13**: Sprint 6 abgeschlossen — preflight-check.js standalone, wizard.js batch, Wizard modular (8 files) (+5 Tests)
- **2026-06-13**: Sprint 5 abgeschlossen — FIX-7 p-limit, ENH-10 dark-mode-extractor, ENH-11 JSDoc (+6 Tests)
- **2026-06-13**: Sprint 4 abgeschlossen — C3 Native Routing, structuralHash Dedup, A2 v4-tree Mode (+6 Tests)
- **2026-06-13**: Sprint 3 abgeschlossen — A3 Forms, B4 create-atomic-form, D2 Native Coverage (+4 Tests)
- **2026-06-13**: Sprint 2 abgeschlossen — A1 Components, A2 Interactions, C1 Preservation, C3 Easing, D1 Reuse (+6 Tests)
- **2026-06-13**: Sprint 1 abgeschlossen — C2 Grid, C4 Semantic GC, C5 Breakpoint, C6 GV-Sub, D3 Grid/Flex (+12 Tests)

---

## Offene Entscheidungen

- [ ] End-to-End Test: FramerExport CLI installieren → echten Durchlauf starten
- [ ] Naechster Sprint: Performance-Profiling, A11y-Integration, oder CI-Erweiterung?

---

## Naechster Schritt

```
npm test               # 114 Pipeline-Tests (36 Suiten)
npm run test:e2e       # 18 E2E-Tests (inkl. 3 S14)
npm run test:all       # Finale Regression (139 Tests)
npm run lint:version   # v0.14.0 bestaetigen
```
