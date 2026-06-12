#!/usr/bin/env node
/**
 * run-post-build-qa.js  —  Post-Build QA Report Generator
 *
 * Liest QA-Ergebnisse die der Agent gesammelt hat und konsolidiert
 * sie zu einem qa-report.json mit priorisierten Action-Items.
 *
 * ARCHITEKTUR:
 *   Der Agent ruft alle QA-Abilities direkt via novamira-solar-local auf,
 *   speichert die Ergebnisse und uebergibt sie diesem Script.
 *   Dieses Script ist reiner Report-Generator — kein MCP-Call.
 *
 * AGENT-WORKFLOW:
 *   1. Agent ruft parallel auf (alle via novamira-solar-local:mcp-adapter-execute-ability):
 *      - novamira-adrianv2/layout-audit     { post_id }
 *      - novamira-adrianv2/visual-qa        { post_id, breakpoints: [desktop,tablet,mobile] }
 *      - novamira-adrianv2/responsive-audit { post_id }
 *      - novamira-adrianv2/variable-audit   { report: "drift" }
 *      - novamira-adrianv2/page-audit       { post_id }
 *   2. Ergebnisse als qa-results.json speichern (Format: { layout, visual, responsive, variables, page })
 *   3. Dieses Script aufrufen:
 *      node scripts/run-post-build-qa.js --post-id 123 --qa-results qa-results.json
 *
 * ALTERNATIV (stdin):
 *   echo '<qa-results-json>' | node scripts/run-post-build-qa.js --post-id 123 --stdin
 *
 * Exit-Codes:
 *   0 = Alle QA-Checks OK (oder nur Infos)
 *   1 = QA-Fehler die manuellen Fix brauchen
 *   2 = Eingabefehler
 */

import { parseArgs }    from 'node:util';
import { writeFileSync, readFileSync, mkdirSync, existsSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));

const { values: args } = parseArgs({
  options: {
    'post-id':     { type: 'string' },
    'qa-results':  { type: 'string' },
    'stdin':       { type: 'boolean', default: false },
    'output':      { type: 'string' },
    'breakpoints': { type: 'string', default: 'desktop,tablet,mobile' },
    'tree':         { type: 'string' },  // P1-4: V4 tree JSON für Deep-Checks
    'verbose':     { type: 'boolean', default: false },
    'help':        { type: 'boolean', default: false },
  },
  strict: false,
});

if (args.help) {
  process.stdout.write(`
run-post-build-qa.js — Post-Build QA Report Generator

AGENT-WORKFLOW:
  1. Agent ruft via novamira-solar-local auf:
     - novamira-adrianv2/layout-audit     { post_id }
     - novamira-adrianv2/visual-qa        { post_id, breakpoints: ["desktop","tablet","mobile"] }
     - novamira-adrianv2/responsive-audit { post_id }
     - novamira-adrianv2/variable-audit   { report: "drift" }
     - novamira-adrianv2/page-audit       { post_id }
  2. Ergebnisse als qa-results.json: { layout, visual, responsive, variables, page }
  3. Dieses Script: node scripts/run-post-build-qa.js --post-id 123 --qa-results qa-results.json

USAGE:
  node scripts/run-post-build-qa.js --post-id <ID> --qa-results <datei>
  node scripts/run-post-build-qa.js --post-id <ID> --stdin

OPTIONEN:
  --post-id ID          WordPress Post-ID  [required]
  --qa-results FILE     JSON-Datei mit QA-Ergebnissen vom Agent
  --stdin               QA-Ergebnisse von stdin lesen
  --output FILE         Pfad fuer qa-report.json  [default: qa-report.json]
  --tree FILE           V4 Widget-Tree JSON (fuer Deep-Checks: GC-Coverage, DOM-Depth)
  --verbose             Detaillierte Ausgabe
  --help                Diese Hilfe

EXIT-CODES:
  0 = OK
  1 = QA-Fehler (manuelle Fixes noetig)
  2 = Konfigurationsfehler
`);
  process.exit(0);
}

if (!args['post-id']) {
  process.stderr.write('[qa] --post-id ist erforderlich.\n');
  process.stderr.write('Nutzung: node scripts/run-post-build-qa.js --post-id 123 --qa-results qa-results.json\n');
  process.exit(2);
}

const postId = parseInt(args['post-id'], 10);
if (!Number.isFinite(postId) || postId <= 0) {
  process.stderr.write(`[qa] Ungueltige Post-ID: ${args['post-id']}\n`);
  process.exit(2);
}

const log = (...m) => { if (args.verbose) process.stderr.write('[qa] ' + m.join(' ') + '\n'); };

// ── QA-Ergebnisse laden ────────────────────────────────────────────────────

let qaResults = {};

if (args.stdin) {
  let buf = '';
  process.stdin.setEncoding('utf8');
  for await (const chunk of process.stdin) buf += chunk;
  try {
    qaResults = JSON.parse(buf);
    log('QA-Ergebnisse von stdin geladen');
  } catch (e) {
    process.stderr.write(`[qa] Ungültiges JSON auf stdin: ${e.message}\n`);
    process.exit(2);
  }
} else if (args['qa-results']) {
  const qaPath = resolve(args['qa-results']);
  if (!existsSync(qaPath)) {
    process.stderr.write(`[qa] Datei nicht gefunden: ${qaPath}\n`);
    process.stderr.write(`[qa] Agent muss zuerst die QA-Abilities aufrufen und Ergebnisse speichern.\n`);
    process.stderr.write(`[qa] Format: { layout, visual, responsive, variables, page }\n`);
    process.exit(2);
  }
  try {
    qaResults = JSON.parse(readFileSync(qaPath, 'utf8'));
    log(`QA-Ergebnisse geladen aus: ${qaPath}`);
  } catch (e) {
    process.stderr.write(`[qa] Ungültiges JSON in ${qaPath}: ${e.message}\n`);
    process.exit(2);
  }
} else {
  // Kein Input — gibt Instruktion fuer den Agent aus
  process.stdout.write(`
[qa] Kein --qa-results oder --stdin angegeben.

AGENT-INSTRUKTION: Rufe diese Abilities via novamira-solar-local auf:

  1. novamira-adrianv2/layout-audit     { post_id: ${postId} }
  2. novamira-adrianv2/visual-qa        { post_id: ${postId}, breakpoints: ["desktop","tablet","mobile"] }
  3. novamira-adrianv2/responsive-audit { post_id: ${postId} }
  4. novamira-adrianv2/variable-audit   { report: "drift" }
  5. novamira-adrianv2/page-audit       { post_id: ${postId} }

Dann speichere die Ergebnisse als JSON:
  { "layout": <...>, "visual": <...>, "responsive": <...>, "variables": <...>, "page": <...> }

Dann: node scripts/run-post-build-qa.js --post-id ${postId} --qa-results qa-results.json
`);
  process.exit(0);
}

// ── Ergebnisse normalisieren ──────────────────────────────────────────────

// Jedes Feld kann direkt die Ability-Antwort oder via .data wrapper sein
function unwrapData(val) {
  if (!val) return null;
  return val?.data ?? val;
}

const layout    = unwrapData(qaResults.layout);
const visual    = unwrapData(qaResults.visual);
const responsive = unwrapData(qaResults.responsive);
const variables = unwrapData(qaResults.variables);
const page      = unwrapData(qaResults.page);

log('layout:', JSON.stringify(layout)?.slice(0, 80));
log('visual:', JSON.stringify(visual)?.slice(0, 80));

// ── Visual QA deduplizieren ───────────────────────────────────────────────

let deduplicateVisualIssues = null;
try {
  const dedupMod = await import('./deduplicate-visual-qa.js');
  deduplicateVisualIssues = dedupMod.deduplicateVisualIssues;
} catch {
  // Fallback: kein Dedup
}

const rawVisualIssues = visual?.issues || [];
const dedupedVisualIssues = deduplicateVisualIssues
  ? deduplicateVisualIssues(rawVisualIssues)
  : rawVisualIssues;

// ── Action Items priorisieren ─────────────────────────────────────────────

const actionItems = [];

// ── P1-4: DEEP CHECKS (benötigen V4 Tree) ──────────────────────────────
// Diese Checks laufen nur wenn --tree angegeben ist.
// GC_COVERAGE: Anteil der Styles die Global Classes nutzen (min 60%)
// DOM_DEPTH: Maximale Nesting-Tiefe (≤5 OK)
// RESPONSIVE_COVERAGE: Anteil der Elemente mit Mobile/Tablet Varianten (min 30%)
// UNUSED_STYLES: Lokale Styles ohne Binding in settings.classes

let deepChecks = null;

if (args.tree) {
  const treePath = resolve(args.tree);
  if (!existsSync(treePath)) {
    process.stderr.write(`[qa] Tree nicht gefunden: ${treePath} — Deep-Checks übersprungen.\n`);
  } else {
    try {
      const rawTree = JSON.parse(readFileSync(treePath, 'utf8'));
      const treeRoots = Array.isArray(rawTree) ? rawTree : [rawTree];

      let totalElements = 0;
      let gcUsers = 0;
      let responsiveElements = 0;
      let styledElements = 0;
      let unboundStyles = 0;
      let maxDepth = 0;

      function deepWalk(node, depth) {
        if (!node || typeof node !== 'object') return;
        totalElements++;
        if (depth > maxDepth) maxDepth = depth;

        // GC_COVERAGE: Prüfe ob settings.classes GC-Referenzen enthält
        const classes = node.settings?.classes;
        const classValues = Array.isArray(classes) ? classes : (classes?.value || []);
        const hasGc = classValues.some(c => typeof c === 'string' && c.startsWith('gc-'));
        if (hasGc) gcUsers++;

        // RESPONSIVE_COVERAGE: Prüfe ob Element mindestens einen responsive Style hat
        let elementHasResponsive = false;
        if (node.styles && typeof node.styles === 'object') {
          const styleIds = Object.keys(node.styles);
          if (styleIds.length > 0) styledElements++;

          for (const [styleId, styleDef] of Object.entries(node.styles)) {
            if (!styleDef || !Array.isArray(styleDef.variants)) continue;
            const hasMobile = styleDef.variants.some(v => v?.meta?.breakpoint === 'mobile');
            const hasTablet = styleDef.variants.some(v => v?.meta?.breakpoint === 'tablet');
            if (hasMobile || hasTablet) elementHasResponsive = true;

            // UNUSED_STYLES: Lokale Styles (kein gc- Prefix) ohne Binding
            if (!styleId.startsWith('gc-') && !classValues.includes(styleId)) {
              unboundStyles++;
            }
          }
        }

        const children = node.elements || node.children || [];
        if (Array.isArray(children)) {
          for (const child of children) deepWalk(child, depth + 1);
        }
        // After processing all styles for this element, count responsive once
        if (elementHasResponsive) responsiveElements++;
      }

      for (const root of treeRoots) deepWalk(root, 1);

      const gcCoveragePct = styledElements > 0 ? Math.round((gcUsers / styledElements) * 100) : 0;
      const responsivePct = styledElements > 0 ? Math.round((responsiveElements / styledElements) * 100) : 0;

      deepChecks = {
        gc_coverage: {
          value: `${gcCoveragePct}%`,
          target: '60%',
          pass: gcCoveragePct >= 60,
          detail: `${gcUsers}/${styledElements} styled elements use Global Classes`,
        },
        dom_depth: {
          value: maxDepth,
          target: '≤5',
          pass: maxDepth <= 5,
          detail: maxDepth >= 6 ? `CRITICAL: depth ${maxDepth} risks server timeout` : `Max nesting depth ${maxDepth}`,
        },
        responsive_coverage: {
          value: `${responsivePct}%`,
          target: '30%',
          pass: responsivePct >= 30,
          detail: `${responsiveElements}/${styledElements} styled elements have mobile/tablet variants`,
        },
        unused_styles: {
          value: unboundStyles,
          target: '0',
          pass: unboundStyles === 0,
          detail: unboundStyles > 0 ? `${unboundStyles} local styles not bound in settings.classes` : 'All styles properly bound',
        },
      };

      // Füge Deep-Check Issues als Warning Action-Items hinzu
      if (!deepChecks.gc_coverage.pass) {
        actionItems.push({
          priority: 2, type: 'gc-coverage',
          fix: `GC Coverage ${gcCoveragePct}% < 60% target — run generate-global-classes.js --apply`,
          ability: 'novamira-adrianv2/batch-class',
        });
      }
      if (!deepChecks.dom_depth.pass) {
        actionItems.push({
          priority: 1, type: 'dom-depth',
          fix: `DOM depth ${maxDepth} > 5 — use post-build-auto-fix.js --fix-dom-depth`,
          ability: 'novamira-adrianv2/patch-element-styles',
        });
      }
      if (!deepChecks.responsive_coverage.pass) {
        actionItems.push({
          priority: 3, type: 'responsive-coverage',
          fix: `Responsive coverage ${responsivePct}% < 30% — run auto-scale-responsive.js`,
          ability: 'novamira-adrianv2/add-global-class-variant',
        });
      }
      if (!deepChecks.unused_styles.pass) {
        actionItems.push({
          priority: 2, type: 'unused-styles',
          fix: `${unboundStyles} unbound local styles — run validate-v4-tree.js to find them`,
          ability: 'novamira-adrianv2/patch-element-styles',
        });
      }

      log(`Deep Checks: GC=${gcCoveragePct}% DOM=${maxDepth} Resp=${responsivePct}% Unbound=${unboundStyles}`);
    } catch (e) {
      process.stderr.write(`[qa] Deep-Check Fehler: ${e.message}\n`);
    }
  }
}

// ── Action Items priorisieren (Fortsetzung) ────────────────────────────

// Layout-Fehler → patch-element-styles
for (const issue of layout?.issues || []) {
  actionItems.push({
    priority: issue.severity === 'error' ? 1 : 2,
    type: 'layout',
    element_id: issue.element_id,
    fix: issue.suggestion || issue.message,
    ability: 'novamira-adrianv2/patch-element-styles',
  });
}

// Visual-Fehler → patch-element-styles oder GC-Variant
for (const issue of dedupedVisualIssues) {
  if (issue.severity === 'error' || issue.type === 'overflow') {
    actionItems.push({
      priority: issue.severity === 'error' ? 1 : 2,
      type: 'visual',
      element_id: issue.element_id || issue.element_ids?.join(','),
      fix: issue.message,
      ability: 'novamira-adrianv2/patch-element-styles',
    });
  }
}

// Page-Audit Issues (neu: novamira-adrianv2/page-audit)
for (const issue of page?.issues || []) {
  const prio = issue.type === 'broken_link' ? 2 : issue.type === 'heading_hierarchy' ? 3 : 3;
  actionItems.push({
    priority: prio,
    type: `page:${issue.type || 'audit'}`,
    element_id: issue.element_id,
    fix: issue.message || issue.description,
    ability: issue.type === 'missing_alt'
      ? 'novamira-adrianv2/edit-media'
      : 'novamira-adrianv2/patch-element-styles',
  });
}

// Variable-Drift → re-export + cross-validate
const driftVars = variables?.drift || [];
if (driftVars.length > 0) {
  actionItems.push({
    priority: 1,
    type: 'variable-drift',
    fix: `${driftVars.length} e-gv-* Referenzen nicht im Design-System: ${driftVars.slice(0, 3).join(', ')}${driftVars.length > 3 ? '...' : ''}`,
    ability: 'novamira-adrianv2/export-design-system',
    next_step: 'node scripts/design-token-extractor.js --apply-response',
  });
}

// Responsive-Luecken → add-global-class-variant
const missingBreakpoints = responsive?.missing_breakpoints || [];
if (missingBreakpoints.length > 0) {
  actionItems.push({
    priority: 3,
    type: 'responsive',
    fix: `${missingBreakpoints.length} fehlende Breakpoint-Varianten`,
    ability: 'novamira-adrianv2/add-global-class-variant',
  });
}

// Nach Prioritaet sortieren
actionItems.sort((a, b) => a.priority - b.priority);

// ── Gesamtstatus ──────────────────────────────────────────────────────────

const hasErrors   = actionItems.some(i => i.priority === 1);
const hasWarnings = actionItems.some(i => i.priority === 2);
const overallStatus = hasErrors ? 'errors' : hasWarnings ? 'warnings' : 'ok';

// ── Report schreiben ──────────────────────────────────────────────────────

const report = {
  post_id: postId,
  timestamp: new Date().toISOString(),
  overall_status: overallStatus,
  summary: {
    layout_issues:       layout?.total_issues    ?? layout?.issues?.length ?? 0,
    visual_issues:       dedupedVisualIssues.length,
    visual_raw:          rawVisualIssues.length,
    page_issues:         page?.issues?.length ?? 0,
    variable_drift:      driftVars.length,
    missing_breakpoints: missingBreakpoints.length,
    action_items:        actionItems.length,
    deep_checks:         deepChecks,  // P1-4: GC_COVERAGE, DOM_DEPTH, RESPONSIVE, UNUSED_STYLES
  },
  layout:    { issues: layout?.issues || [],    total_issues: layout?.total_issues ?? 0 },
  visual:    { issues: dedupedVisualIssues,      total_raw: rawVisualIssues.length },
  responsive: { missing_breakpoints: missingBreakpoints, raw: responsive },
  variables: { drift: driftVars, unused: variables?.unused || [] },
  page:      { issues: page?.issues || [] },
  action_items: actionItems,
};

const outputPath = resolve(args.output || 'qa-report.json');
mkdirSync(dirname(outputPath), { recursive: true });
writeFileSync(outputPath, JSON.stringify(report, null, 2), 'utf8');

// ── Console-Zusammenfassung ───────────────────────────────────────────────
const statusIcon = overallStatus === 'ok' ? '✅' : overallStatus === 'warnings' ? '⚠️ ' : '❌';
process.stderr.write(`\n[qa] ${statusIcon} Post-Build QA Report — Post ${postId}\n`);
process.stderr.write(`[qa]   Layout-Issues:      ${report.summary.layout_issues}\n`);
process.stderr.write(`[qa]   Visual-Issues:      ${report.summary.visual_issues} (raw: ${report.summary.visual_raw})\n`);
process.stderr.write(`[qa]   Page-Issues:        ${report.summary.page_issues}\n`);
process.stderr.write(`[qa]   Variable-Drift:     ${report.summary.variable_drift}\n`);
process.stderr.write(`[qa]   Action-Items:       ${actionItems.length}\n`);
if (deepChecks) {
  process.stderr.write(`[qa]   Deep Checks:\n`);
  process.stderr.write(`[qa]     GC-Coverage:        ${deepChecks.gc_coverage.value} ${deepChecks.gc_coverage.pass ? '✅' : '❌'} (target: ${deepChecks.gc_coverage.target})\n`);
  process.stderr.write(`[qa]     DOM-Depth:          ${deepChecks.dom_depth.value} ${deepChecks.dom_depth.pass ? '✅' : '❌'} (target: ${deepChecks.dom_depth.target})\n`);
  process.stderr.write(`[qa]     Responsive:         ${deepChecks.responsive_coverage.value} ${deepChecks.responsive_coverage.pass ? '✅' : '❌'} (target: ${deepChecks.responsive_coverage.target})\n`);
  process.stderr.write(`[qa]     Unused Styles:      ${deepChecks.unused_styles.value} ${deepChecks.unused_styles.pass ? '✅' : '❌'} (target: ${deepChecks.unused_styles.target})\n`);
}
process.stderr.write(`[qa]   Report:             ${outputPath}\n\n`);

if (actionItems.length > 0) {
  process.stderr.write('[qa] Prioritaere Fixes:\n');
  for (const item of actionItems.slice(0, 5)) {
    process.stderr.write(`[qa]   [P${item.priority}] ${item.type}: ${item.fix}\n`);
    process.stderr.write(`[qa]          Ability: ${item.ability}\n`);
  }
  if (actionItems.length > 5) {
    process.stderr.write(`[qa]   ... und ${actionItems.length - 5} weitere (siehe ${outputPath})\n`);
  }
}

process.exit(hasErrors ? 1 : 0);
