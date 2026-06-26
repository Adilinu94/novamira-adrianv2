---
name: adrianv2-framer-pipeline-import
description: Handoff-Guide for receiving Framer-to-Elementor-V4-Pipeline output and deploying it to WordPress via novamira. Use when the agent receives a V4 tree JSON from the Node.js pipeline, needs to run v4-preflight, inject-calibrated-page or batch-build-page, validate the tree, and clear element cache after deploy.
---

# AdrianV2 Skill: Framer Pipeline → WordPress Deploy

> **Plugin:** novamira-adrianv2
> **Elementor-Welt:** V4
> **Required Abilities:** `novamira-adrianv2/v4-preflight`, `novamira-adrianv2/elementor-inject-calibrated-page`, `novamira-adrianv2/validate-v4-tree`, `novamira-adrianv2/layout-audit`, `novamira-adrianv2/class-audit`

## Wann aktivieren

- Der Agent empfängt den Output des Framer-to-Elementor-V4-Pipeline Node.js-Projekts
- Eine `v4-tree.json` oder `batch-build-plan.json` soll in WordPress deployed werden
- Der User sagt: "deploy den Pipeline-Output", "injiziere den V4-Tree", "Pipeline fertig, jetzt pushen"
- Guard-Score aus der Pipeline liegt bei ≥ 85% (sonst: Schritt 0 zuerst)

## Guard-Score Interpretation (Pipeline-Output)

Vor dem Deploy prüft die Node.js-Pipeline mit 14 Guards:
- **Score ≥ 85%**: Deploy erlaubt → Schritt 1 ausführen
- **Score 70–84%**: Nur mit expliziter User-Bestätigung deployen
- **Score < 70%**: ABBRUCH — User informieren, Pipeline neu ausführen
- **G5 (CSS-Logical-Properties) FAIL**: Kritisch — padding/margin falsch formatiert
- **G9 (GC-Binding) FAIL**: Global Classes nicht gebunden — setup-v4-foundation nötig

## Deploy-Ablauf

### Schritt 0 (nur wenn nötig): Foundation sicherstellen
```json
{ "ability": "novamira-adrianv2/setup-v4-foundation", "parameters": {} }
```
Nur wenn GC-Binding Guard scheitert oder erste Deployment auf dieser Site.

### Schritt 1: V4-Preflight
```json
{
  "ability": "novamira-adrianv2/v4-preflight",
  "parameters": {}
}
```
→ Gibt zurück: `atomic_supported`, `experiments_active`, `version_ok`.
Wenn `atomic_supported: false` → STOP, User informieren.

### Schritt 2: Seite anlegen (wenn nötig)
```json
{
  "ability": "novamira/create-post",
  "parameters": { "post_type": "page", "title": "<Seitentitel>", "status": "draft" }
}
```
→ Notiere `post_id` für alle folgenden Calls.

### Schritt 3: V4-Tree injizieren
**Option A** — Kalibrierten Page-Tree (via inject-calibrated-page):
```json
{
  "ability": "novamira-adrianv2/elementor-inject-calibrated-page",
  "parameters": {
    "post_id": 1234,
    "tree": "<V4_TREE_JSON_ARRAY>",
    "calibration_mode": "v4_atomic"
  }
}
```

**Option B** — Batch-Build-Plan (wenn Pipeline batch-build-plan.json geliefert hat):
```json
{
  "ability": "novamira-adrianv2/batch-build-page",
  "parameters": {
    "post_id": 1234,
    "plan": "<BATCH_BUILD_PLAN>"
  }
}
```

### Schritt 4: Tree validieren
```json
{
  "ability": "novamira-adrianv2/validate-v4-tree",
  "parameters": { "post_id": 1234 }
}
```
→ Bei `errors: []` → weiter. Bei Fehlern → `fix_orphan_styles` oder User-Feedback.

### Schritt 5: Cache leeren
```json
{
  "ability": "novamira-adrianv2/clear-cache",
  "parameters": { "post_id": 1234, "include_nested": true }
}
```
Wichtig: Elementor Pro cached Element-Render-Ergebnisse. Ohne Cache-Clear sieht man die Änderungen nicht im Frontend. `include_nested: true` leert auch verschachtelte Templates.

### Schritt 6: Post-Deploy Audits
```json
{ "ability": "novamira-adrianv2/layout-audit", "parameters": { "post_id": 1234 } }
{ "ability": "novamira-adrianv2/class-audit", "parameters": { "post_id": 1234 } }
```
→ Score ≥ 90%: Deploy erfolgreich. Score < 80%: `design-repair` ausführen.

## Häufige Fehler aus Pipeline-Deploys

| Fehler | Ursache | Fix |
|--------|---------|-----|
| `elType: container` statt `e-flexbox` | Pipeline-Output noch V3 | `--output-format v4` in Pipeline |
| `styles[id].type` fehlt | Kein `type: "class"` | Pipeline `validate-v4-tree` erneut |
| GV-IDs stimmen nicht | Session-IDs veraltet | `setup-v4-foundation` erneut ausführen |
| Bilder fehlen | Media-IDs nicht gemappt | `batch-media-upload` zuerst |
| Cache-alte Ansicht | Elementor Pro Cache | `clear-cache` mit `include_nested: true` |

## V3-Mode (wenn Pipeline mit --output-format v3 läuft)

Wenn die Pipeline einen V3-kompatiblen Tree ausgibt:
```json
{
  "ability": "novamira/elementor-set-content",
  "parameters": {
    "post_id": 1234,
    "content": "<V3_CONTENT_ARRAY>"
  }
}
```
Dann `clear-cache` und V3-Audit über `layout-audit`.