#!/usr/bin/env node
/**
 * scripts/post-build-auto-fix.js  —  Phase 2: Post-Build Auto-Fix
 *
 * Liest einen QA-Report (qa-report.json) und generiert MCP-Execution-Pläne
 * für automatisch behebbare Issues.
 *
 * Unterstützte QA-Report-Formate:
 *   1. MCP-Format (layout.issues[], visual.issues[], action_items[])
 *      → von novamira-adrianv2/layout-audit, novamira-adrianv2/visual-qa etc.
 *   2. visual-qa.js-Format (meta, results[], a11y)
 *      → von scripts/visual-qa.js mit axe-core A11y-Integration
 *
 * Auto-Fix Kategorien + zugeordnete Novamira-Abilities:
 *   Color Contrast   → novamira-extra/fix-color-contrast
 *   Missing Alt Text → novamira-extra/add-alt-text-from-context
 *   SEO Meta Tags    → novamira-extra/generate-meta-tags
 *   Schema Markup    → novamira-extra/generate-schema-markup
 *   Layout/Style     → novamira-adrianv2/patch-element-styles
 *   Variable Drift   → novamira-adrianv2/patch-element-styles
 *
 * Usage:
 *   # Plan generieren (dry-run — keine Änderungen):
 *   node scripts/post-build-auto-fix.js \
 *     --qa-report qa-report.json \
 *     --post-id 123 \
 *     --output auto-fix-plan.json
 *
 *   # Nur bestimmte Fix-Typen:
 *   node scripts/post-build-auto-fix.js \
 *     --qa-report qa-report.json \
 *     --post-id 123 \
 *     --fix-types contrast,alt-text,seo
 *
 *   # Apply-Results-Modus (Ergebnisse vom Agent einlesen):
 *   node scripts/post-build-auto-fix.js --apply-results auto-fix-results.json
 *
 * Output: auto-fix-plan.json (MCP-Execution-Plan für den Claude-Agenten)
 */

import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { resolve, basename } from 'node:path';
import { parseArgs } from 'node:util';

// ─── CLI ARGS ─────────────────────────────────────────────────────────────────

const { values: args } = parseArgs({
  options: {
    'qa-report':       { type: 'string' },
    'post-id':         { type: 'string' },
    output:            { type: 'string' },
    'fix-types':       { type: 'string', default: 'contrast,alt-text,seo,layout,variables' },
    'apply-results':   { type: 'string' },
    'tree':            { type: 'string' },  // P1-3: V4 tree JSON für fixDomDepth
    'fix-dom-depth':   { type: 'boolean', default: false }, // P1-3: DOM-Tiefen-Flattening aktivieren
    'max-depth':       { type: 'string', default: '3' },   // P1-3: maximale DOM-Tiefe
    'dry-run':         { type: 'boolean', default: false },
    verbose:           { type: 'boolean', default: false },
    help:              { type: 'boolean', default: false },
  },
  strict: false,
});

if (args.help) {
  showHelp();
  process.exit(0);
}

const log = (...m) => { if (args.verbose) process.stderr.write('[auto-fix] ' + m.join(' ') + '\n'); };

// ─── Apply-Results Modus ─────────────────────────────────────────────────────

if (args['apply-results']) {
  const resultsFile = args['apply-results'];
  if (!existsSync(resultsFile)) {
    console.error(`ERROR: Datei nicht gefunden: ${resultsFile}`);
    process.exit(1);
  }

  const results = JSON.parse(readFileSync(resultsFile, 'utf8'));
  const fixes = Array.isArray(results) ? results : results.results || [];

  const summary = {
    generated_at: new Date().toISOString(),
    applied: fixes.filter(f => f.applied !== false).length,
    dry_run: fixes.filter(f => f.applied === false).length,
    failed:  fixes.filter(f => f.error).length,
    details: fixes.map(f => ({
      ability:    f.ability,
      element_id: f.element_id || null,
      applied:    f.applied !== false,
      count:      f.count || 0,
      error:      f.error || null,
    })),
  };

  const outputFile = 'auto-fix-summary.json';
  writeFileSync(outputFile, JSON.stringify(summary, null, 2));
  console.log(`\n✅ Auto-Fix-Summary gespeichert: ${outputFile}`);
  console.log(`   ${summary.applied} Fixes applied`);
  console.log(`   ${summary.dry_run} dry-runs`);
  console.log(`   ${summary.failed} Fehler`);
  process.exit(0);
}

// ─── Validierung ──────────────────────────────────────────────────────────────

if (!args['qa-report']) {
  console.error('Error: --qa-report <pfad> required');
  process.exit(2);
}

if (!existsSync(args['qa-report'])) {
  console.error(`Error: QA-Report nicht gefunden: ${args['qa-report']}`);
  process.exit(2);
}

const postId = args['post-id'] ? parseInt(args['post-id'], 10) : undefined;
const enabledTypes = new Set(args['fix-types'].split(',').map(t => t.trim().toLowerCase()));
const maxDepth = parseInt(args['max-depth'] || '3', 10);

// ─── P1-3: DOM DEPTH FIX ─────────────────────────────────────────────────────

/**
 * Flacht Single-Child-Container rekursiv, um DOM-Tiefe zu reduzieren.
 * Portiert die isPassThroughContainer / resolvePassThrough Logik aus
 * convert-xml-to-v4.js für Post-Build Trees (V4 JSON Format).
 *
 * Ein Container ist "pass-through" wenn:
 *   - Er genau 1 Child hat
 *   - Er keine meaningful Layout-Props hat (gap, padding, bg, border-radius)
 *   - Er keine non-default Positionierung hat
 */
function fixDomDepth(treeData, maxAllowedDepth = 3) {
  function hasMeaningfulLayout(node) {
    if (!node.styles || typeof node.styles !== 'object') return false;
    for (const [, styleDef] of Object.entries(node.styles)) {
      if (!styleDef || !Array.isArray(styleDef.variants)) continue;
      for (const v of styleDef.variants) {
        if (!v?.props) continue;
        const keys = Object.keys(v.props);
        // Props die Layout grundlegend ändern
        if (keys.some(k => ['gap', 'padding', 'padding-top', 'padding-right',
          'padding-bottom', 'padding-left', 'padding-inline-start', 'padding-inline-end',
          'padding-block-start', 'padding-block-end',
          'background-color', 'background', 'border-radius',
          'max-width'].includes(k))) {
          return true;
        }
      }
    }
    return false;
  }

  function getElementChildren(node) {
    return node.elements || node.children || [];
  }

  function isPassThrough(node, depth) {
    const elType = node.elType || node.widgetType || node.type;
    // Nur Container können pass-through sein
    if (!elType || !['e-flexbox', 'e-div-block', 'container'].includes(elType)) return false;
    const children = getElementChildren(node);
    if (children.length !== 1) return false;
    if (hasMeaningfulLayout(node)) return false;
    // P1-3: Nur flachen wenn Tiefe über dem Limit
    if (depth <= maxAllowedDepth) return false;
    return true;
  }

  let flattened = 0;

  function flatten(node, depth) {
    if (!node || typeof node !== 'object') return { node, depth };

    // Rekursiv Kinder zuerst flachen (bottom-up)
    const children = getElementChildren(node);
    const newChildren = [];
    for (const child of children) {
      const result = flatten(child, depth + 1);
      // Wenn das Kind selbst ein pass-through war, dessen Kinder direkt übernehmen
      if (result.node && Array.isArray(getElementChildren(result.node))) {
        const grandChildren = getElementChildren(result.node);
        for (const gc of grandChildren) {
          newChildren.push(gc);
        }
        if (grandChildren.length > 0) flattened++;
      } else {
        newChildren.push(result.node);
      }
    }

    // Kinder zurücksetzen
    if (node.elements) node.elements = newChildren;
    else if (node.children) node.children = newChildren;

    // Prüfe ob dieser Node selbst pass-through ist
    const updatedChildren = getElementChildren(node);
    if (isPassThrough(node, depth) && updatedChildren.length === 1) {
      // Merge: Kind-Properties übernehmen, Node selbst entfernen
      const child = updatedChildren[0];
      // Child's classes mit Node's classes mergen
      if (node.settings?.classes && child.settings) {
        const nodeClasses = node.settings.classes;
        const childClasses = child.settings.classes;
        const nodeValues = Array.isArray(nodeClasses) ? nodeClasses : (nodeClasses?.value || []);
        const childValues = Array.isArray(childClasses) ? childClasses : (childClasses?.value || []);
        if (childClasses && Array.isArray(childClasses)) {
          child.settings.classes = [...new Set([...nodeValues, ...childValues])];
        } else if (childClasses?.value) {
          child.settings.classes.value = [...new Set([...nodeValues, ...childClasses.value])];
        }
      }
      flattened++;
      return { node: child, depth };
    }

    return { node, depth };
  }

  const roots = Array.isArray(treeData) ? treeData : [treeData];
  const newRoots = [];
  for (const root of roots) {
    const result = flatten(root, 1);
    if (result.node) newRoots.push(result.node);
  }

  return { tree: newRoots.length === 1 ? newRoots[0] : newRoots, flattened };
}

// ─── --fix-dom-depth Modus (P1-3) ────────────────────────────────────────────

if (args['fix-dom-depth']) {
  if (!args.tree) {
    console.error('Error: --tree <file> required for --fix-dom-depth');
    process.exit(2);
  }
  const treePath = resolve(args.tree);
  if (!existsSync(treePath)) {
    console.error(`Error: Tree nicht gefunden: ${treePath}`);
    process.exit(2);
  }

  let treeData;
  try {
    treeData = JSON.parse(readFileSync(treePath, 'utf8'));
  } catch (e) {
    console.error(`Error: Tree JSON ungültig: ${e.message}`);
    process.exit(2);
  }

  // Count depth before
  let depthBefore = 0;
  function countDepth(node, d) {
    if (!node || typeof node !== 'object') return;
    if (d > depthBefore) depthBefore = d;
    (node.elements || node.children || []).forEach(c => countDepth(c, d + 1));
  }
  (Array.isArray(treeData) ? treeData : [treeData]).forEach(r => countDepth(r, 1));

  const { tree: flattenedTree, flattened } = fixDomDepth(treeData, maxDepth);

  // Count depth after
  let depthAfter = 0;
  (Array.isArray(flattenedTree) ? flattenedTree : [flattenedTree]).forEach(r => countDepth(r, 1));

  const outputPath = args.output || treePath;
  writeFileSync(resolve(outputPath), JSON.stringify(flattenedTree, null, 2), 'utf8');

  process.stderr.write(`[auto-fix] 🏗️  DOM-Depth: ${depthBefore} → ${depthAfter} (${flattened} Container geflattet)\n`);
  process.stderr.write(`[auto-fix] Tree → ${outputPath}\n`);

  process.exit(0);
}
const qaReport = JSON.parse(readFileSync(args['qa-report'], 'utf8'));

if (!postId && !qaReport.post_id && !qaReport.meta?.post_id) {
  console.error('Error: Keine Post-ID gefunden. Setze --post-id <id> oder stelle sicher, dass der QA-Report eine post_id enthält.');
  process.exit(2);
}

log('QA-Report geladen:', basename(args['qa-report']));
log('Fix-Typen:', [...enabledTypes].join(', '));

// ─── ISSUE CLASSIFICATION ─────────────────────────────────────────────────────

/**
 * Erkennt das QA-Report-Format und extrahiert klassifizierte Issues.
 */
function classifyIssues(report) {
  const issues = [];

  // ── Format 1: MCP-Format (layout, visual, action_items) ─────────────
  if (report.layout?.issues?.length) {
    for (const issue of report.layout.issues) {
      if (issue.check === 'deep_nesting' && enabledTypes.has('layout')) {
        issues.push({
          category: 'layout',
          subcategory: 'deep_nesting',
          element_id: issue.element_id,
          severity: issue.severity,
          suggestion: issue.suggestion,
          ability: 'novamira-adrianv2/patch-element-styles',
          params: {
            post_id: postId || report.post_id,
            element_id: issue.element_id,
            patches: [{ property: 'layout', action: 'restructure', note: issue.suggestion }],
          },
        });
      }
    }
  }

  if (report.visual?.issues?.length) {
    for (const issue of report.visual.issues) {
      // Fixed dimensions → style fix
      if (issue.type === 'fixed_dimensions' && enabledTypes.has('layout')) {
        issues.push({
          category: 'layout',
          subcategory: 'fixed_dimensions',
          element_id: issue.element_id,
          severity: issue.severity,
          message: issue.message,
          ability: 'novamira-adrianv2/patch-element-styles',
          params: {
            post_id: postId || report.post_id,
            element_id: issue.element_id,
            patches: [{ property: 'height', action: 'remove_fixed', note: issue.message }],
          },
        });
      }
    }
  }

  // Action items (bereits vom MCP klassifiziert)
  if (report.action_items?.length) {
    for (const item of report.action_items) {
      const category = mapActionType(item.type);
      if (category && enabledTypes.has(category)) {
        issues.push({
          category,
          subcategory: item.type,
          element_id: item.element_id,
          priority: item.priority,
          ability: item.ability || mapAbilityForType(item.type),
          params: {
            post_id: postId || report.post_id,
            element_id: item.element_id,
            ...(item.fix ? { patches: [{ note: item.fix }] } : {}),
          },
        });
      }
    }
  }

  // Variable drift
  if (report.variables?.drift?.length && enabledTypes.has('variables')) {
    issues.push({
      category: 'variables',
      subcategory: 'drift',
      count: report.variables.drift.length,
      ability: 'novamira-adrianv2/patch-element-styles',
      params: {
        post_id: postId || report.post_id,
        patches: report.variables.drift.map(v => ({
          property: v.variable || 'variable',
          action: 'reassign',
          note: `Drift: ${v.from || '?'} → ${v.to || '?'}`,
        })),
      },
    });
  }

  // ── Format 2: visual-qa.js-Format (a11y violations) ──────────────────
  if (report.results?.length) {
    for (const r of report.results) {
      const violations = r.details?.a11y?.violations || [];
      for (const v of violations) {
        // Color contrast violations
        if (isColorContrastViolation(v) && enabledTypes.has('contrast')) {
          issues.push({
            category: 'contrast',
            subcategory: v.id,
            impact: v.impact,
            help: v.help,
            helpUrl: v.helpUrl,
            breakpoint: r.breakpoint,
            ability: 'novamira-extra/fix-color-contrast',
            params: {
              post_id: postId || report.meta?.post_id,
              apply: !args['dry-run'],
              target_ratio: 4.5,
            },
          });
        }

        // Image alt violations
        if (isImageAltViolation(v) && enabledTypes.has('alt-text')) {
          issues.push({
            category: 'alt-text',
            subcategory: v.id,
            impact: v.impact,
            help: v.help,
            breakpoint: r.breakpoint,
            ability: 'novamira-extra/add-alt-text-from-context',
            params: {
              post_id: postId || report.meta?.post_id,
              apply: !args['dry-run'],
            },
          });
        }
      }
    }
  }

  return issues;
}

// ─── DEDUPLICATION ────────────────────────────────────────────────────────────

/**
 * Dedupliziert Issues pro Ability.
 * Contrast/Alt-Text/SEO werden auf einen Call pro Ability reduziert.
 * Layout-Style-Issues behalten individuelle element_ids.
 */
function deduplicateIssues(issues) {
  const groups = new Map();

  for (const issue of issues) {
    const key = issue.ability;

    if (!groups.has(key)) {
      groups.set(key, []);
    }
    groups.get(key).push(issue);
  }

  const mcpCalls = [];

  for (const [ability, groupIssues] of groups) {
    // Global-fix abilities: ein Call für alle
    if (ability === 'novamira-extra/fix-color-contrast') {
      mcpCalls.push({
        ability,
        params: {
          post_id: groupIssues[0].params.post_id,
          apply: groupIssues[0].params.apply,
          target_ratio: 4.5,
        },
        note: `${groupIssues.length} contrast violation(s) detected`,
        phase: 'post-build-auto-fix',
        dry_run: !groupIssues[0].params.apply,
      });
    } else if (ability === 'novamira-extra/add-alt-text-from-context') {
      mcpCalls.push({
        ability,
        params: {
          post_id: groupIssues[0].params.post_id,
          apply: groupIssues[0].params.apply,
        },
        note: `${groupIssues.length} image(s) missing alt text`,
        phase: 'post-build-auto-fix',
        dry_run: !groupIssues[0].params.apply,
      });
    } else if (ability === 'novamira-adrianv2/patch-element-styles') {
      // Per-element calls (jeder element_id ist ein eigener Fix)
      // Gruppiere nach element_id um Duplikate zu vermeiden
      const seen = new Set();
      for (const issue of groupIssues) {
        const elId = issue.params.element_id;
        if (elId && !seen.has(elId)) {
          seen.add(elId);
          mcpCalls.push({
            ability,
            params: {
              post_id: issue.params.post_id,
              element_id: elId,
              patches: issue.params.patches || [],
            },
            note: issue.subcategory || issue.category,
            phase: 'post-build-auto-fix',
          });
        }
      }
    }
  }

  return mcpCalls;
}

// ─── HELPER ───────────────────────────────────────────────────────────────────

function isColorContrastViolation(violation) {
  const id = (violation.id || '').toLowerCase();
  return id.includes('color-contrast') || id.includes('contrast');
}

function isImageAltViolation(violation) {
  const id = (violation.id || '').toLowerCase();
  return id.includes('image-alt') || id.includes('image-redundant-alt') ||
         id.includes('alt') || id.includes('aria-input');
}

function mapActionType(type) {
  const map = {
    layout: 'layout',
    style: 'layout',
    color: 'contrast',
    contrast: 'contrast',
    alt: 'alt-text',
    'alt-text': 'alt-text',
    seo: 'seo',
    meta: 'seo',
    schema: 'seo',
    variable: 'variables',
    drift: 'variables',
  };
  return map[type?.toLowerCase()] || null;
}

function mapAbilityForType(type) {
  const map = {
    layout: 'novamira-adrianv2/patch-element-styles',
    style: 'novamira-adrianv2/patch-element-styles',
    color: 'novamira-extra/fix-color-contrast',
    contrast: 'novamira-extra/fix-color-contrast',
    alt: 'novamira-extra/add-alt-text-from-context',
    'alt-text': 'novamira-extra/add-alt-text-from-context',
    seo: 'novamira-extra/generate-meta-tags',
    variable: 'novamira-adrianv2/patch-element-styles',
  };
  return map[type?.toLowerCase()] || 'novamira-adrianv2/patch-element-styles';
}

// ─── MAIN ─────────────────────────────────────────────────────────────────────

const issues = classifyIssues(qaReport);
log(`${issues.length} classifiable issues found`);

const mcpCalls = deduplicateIssues(issues);
log(`${mcpCalls.length} unique MCP calls after dedup`);

const effectivePostId = postId || qaReport.post_id || qaReport.meta?.post_id;

// SEO-Abilities zusätzlich einplanen (wenn enabled)
if (enabledTypes.has('seo') && effectivePostId) {
  const hasMetaCalls = mcpCalls.some(c => c.ability === 'novamira-extra/generate-meta-tags');
  const hasSchemaCalls = mcpCalls.some(c => c.ability === 'novamira-extra/generate-schema-markup');

  if (!hasMetaCalls) {
    mcpCalls.push({
      ability: 'novamira-extra/generate-meta-tags',
      params: { post_id: effectivePostId },
      note: 'SEO Meta-Tags vorsorglich generieren (keine Issues im Report, aber Best Practice)',
      phase: 'post-build-auto-fix',
    });
    log('SEO meta-tags: added (best practice)');
  }

  if (!hasSchemaCalls) {
    mcpCalls.push({
      ability: 'novamira-extra/generate-schema-markup',
      params: { post_id: effectivePostId },
      note: 'Schema-Markup vorsorglich generieren',
      phase: 'post-build-auto-fix',
    });
    log('Schema markup: added (best practice)');
  }
}

// ─── BUILD PLAN ───────────────────────────────────────────────────────────────

const plan = {
  description: 'Post-Build Auto-Fix MCP-Plan',
  generated_at: new Date().toISOString(),
  source: basename(args['qa-report']),
  post_id: postId || qaReport.post_id || null,
  dry_run: args['dry-run'],
  stats: {
    total_issues: issues.length,
    unique_calls: mcpCalls.length,
    by_category: countBy(issues, 'category'),
    by_ability: countBy(mcpCalls.map(c => ({ category: c.ability })), 'category'),
  },
  mcp_calls: mcpCalls,
  agent_instruction:
    `Führe ${mcpCalls.length} MCP-Calls aus auto-fix-plan.json sequenziell aus.\n` +
    `Tool: novamira-solar-local:mcp-adapter-execute-ability\n` +
    (args['dry-run']
      ? `Alle contrast/alt-text/seo Calls sind DRY-RUN (keine Änderungen).\n`
        + `Prüfe die Ergebnisse und führe mit --apply-results erneut aus.\n`
      : `Contrast/alt-text Calls sind auf apply:true gesetzt (Änderungen werden geschrieben).\n`) +
    `\nErgebnisse als auto-fix-results.json speichern, dann:\n` +
    `  node scripts/post-build-auto-fix.js --apply-results auto-fix-results.json`,
};

// ─── OUTPUT ───────────────────────────────────────────────────────────────────

const output = JSON.stringify(plan, null, 2);

if (args.output) {
  writeFileSync(resolve(args.output), output, 'utf8');
  process.stderr.write(`[auto-fix] Saved to ${args.output}\n`);
} else {
  process.stdout.write(output + '\n');
}

// ─── HUMAN SUMMARY ────────────────────────────────────────────────────────────

console.log(`\n╔══════════════════════════════════════════════════════╗`);
console.log(`║  post-build-auto-fix.js                              ║`);
console.log(`╚══════════════════════════════════════════════════════╝`);
console.log(`\n  📋 Source:        ${basename(args['qa-report'])}`);
console.log(`  🎯 Post-ID:       ${postId || qaReport.post_id || '(nicht gesetzt)'}`);
console.log(`  🐛 Issues total:  ${issues.length}`);
console.log(`  🔧 MCP-Calls:     ${mcpCalls.length} (dedupliziert)`);

if (mcpCalls.length === 0) {
  console.log(`\n  ✅ Keine auto-fixbaren Issues gefunden.`);
  console.log(`  → QA-Report ist clean oder enthält nur manuell behebbare Issues.`);
} else {
  console.log(`\n  ─── Calls ──────────────────────────────────────────`);
  for (const call of mcpCalls) {
    const dryFlag = call.dry_run ? ' 🔍[DRY-RUN]' : '';
    const note = call.note ? `  (${call.note})` : '';
    console.log(`  ${call.ability.padEnd(46)}${dryFlag}${note}`);
  }

  console.log(`\n  ─── Nächster Schritt ───────────────────────────────`);
  console.log(`  Agent: Führe MCP-Calls aus auto-fix-plan.json aus`);
  if (args['dry-run']) {
    console.log(`  → ALLE Calls sind DRY-RUN — keine Änderungen.`);
    console.log(`  → Ergebnisse prüfen, dann ohne --dry-run wiederholen.`);
  }
}

console.log('');

process.exit(0);

// ─── HELPERS ──────────────────────────────────────────────────────────────────

function countBy(arr, key) {
  const counts = {};
  for (const item of arr) {
    const val = item[key] || 'unknown';
    counts[val] = (counts[val] || 0) + 1;
  }
  return counts;
}

function showHelp() {
  console.log(`
post-build-auto-fix.js — QA-Report → Auto-Fix MCP-Plan

Liest einen QA-Report und generiert MCP-Execution-Pläne für
automatisch behebbare Issues mit Novamira-Abilities.  Auto-Fix Kategorien:
  contrast   → novamira-extra/fix-color-contrast
  alt-text   → novamira-extra/add-alt-text-from-context
  seo        → novamira-extra/generate-meta-tags + generate-schema-markup
  layout     → novamira-adrianv2/patch-element-styles
  variables  → novamira-adrianv2/patch-element-styles

  # P1-3: DOM-Tiefen-Fix (--fix-dom-depth):
  node scripts/post-build-auto-fix.js \\
    --qa-report qa-report.json \\
    --tree v4-tree.json \\
    --fix-dom-depth \\
    --output v4-tree-flattened.json

Usage:
  node scripts/post-build-auto-fix.js \\
    --qa-report qa-report.json \\
    --post-id 123 \\
    --output auto-fix-plan.json

  # Nur bestimmte Fix-Typen:
  node scripts/post-build-auto-fix.js \\
    --qa-report qa-report.json \\
    --post-id 123 \\
    --fix-types contrast,alt-text

  # Dry-Run (keine Änderungen):
  node scripts/post-build-auto-fix.js \\
    --qa-report qa-report.json \\
    --post-id 123 \\
    --dry-run

  # Ergebnisse einlesen:
  node scripts/post-build-auto-fix.js --apply-results auto-fix-results.json

Output: auto-fix-plan.json — direkt vom Agent ausführbar via:
  novamira-solar-local:mcp-adapter-execute-ability
`);
}
