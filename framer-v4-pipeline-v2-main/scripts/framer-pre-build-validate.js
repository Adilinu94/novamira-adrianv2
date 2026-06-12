#!/usr/bin/env node
/**
 * framer-pre-build-validate.js  —  Phase 1.5: Framer-spezifische Pre-Build Validation
 * Führt 12 Guards auf einem V4 Widget-Tree aus. Blockiert den Build bei Fehlern.
 *
 * Usage:
 *   node scripts/framer-pre-build-validate.js \
 *     --tree        FramerExport/v4-tree/hero-section.json \
 *     --tokens      FramerExport/tokens/token-mapping.json \
 *     --fonts       FramerExport/tokens/font-resolution.json \
 *     --breakpoints FramerExport/tokens/responsive-breakpoints.json \
 *     --output      FramerExport/tokens/pre-build-validation.json
 *
 * Exit-Codes:
 *   0 = Score ≥ 85% (Build erlaubt)
 *   1 = Score < 85% (Build blockiert)
 */

import fs   from 'node:fs';
import path from 'node:path';
import { parseArgs } from 'node:util';
import { normalizeHex, walkTree, extractGvIds } from './lib/framer-utils.js';

// ─────────────────────────────────────────────
// CLI
// ─────────────────────────────────────────────

const { values: args } = parseArgs({
  options: {
    tree:         { type: 'string' },
    tokens:       { type: 'string' },
    fonts:        { type: 'string' },
    breakpoints:  { type: 'string' },
    output:       { type: 'string' },
    verbose:      { type: 'boolean', default: false },
  },
  strict: false,
});

// Help
if (process.argv.includes('--help') || process.argv.includes('-h')) { console.log('Usage: node scripts/framer-pre-build-validate.js [--help for options]'); console.log('Run with --help for full usage.'); process.exit(0); }

const log = (...m) => { if (args.verbose) process.stderr.write('[verbose] ' + m.join(' ') + '\n'); };

if (!args.tree) {
  process.stderr.write('Error: --tree erforderlich\n'); process.exit(1);
}

// ─────────────────────────────────────────────
// LOAD INPUTS
// ─────────────────────────────────────────────

function loadJson(filePath, label) {
  if (!filePath) return null;
  if (!fs.existsSync(filePath)) {
    process.stderr.write(`Warning: ${label} nicht gefunden: ${filePath}\n`);
    return null;
  }
  try { return JSON.parse(fs.readFileSync(filePath, 'utf8')); }
  catch (e) { process.stderr.write(`Error: ${label} JSON parse failed: ${e.message}\n`); return null; }
}

const tree         = loadJson(args.tree,        'V4 Tree');
const tokenMapping = loadJson(args.tokens,      'Token Mapping');
const fontData     = loadJson(args.fonts,       'Font Resolution');
const breakpointData = loadJson(args.breakpoints, 'Breakpoints');

if (!tree) { process.stderr.write('Error: Tree konnte nicht geladen werden.\n'); process.exit(1); }

// ─────────────────────────────────────────────
// HELPER: Build Sets from mapping files
// ─────────────────────────────────────────────

// All valid gv_ids from token-mapping.json
const knownGvIds = new Set();
if (tokenMapping) {
  for (const c of Object.values(tokenMapping.colors || {})) {
    if (typeof c === 'string' && c.startsWith('e-gv-')) knownGvIds.add(c);
    if (c?.gv_id) knownGvIds.add(c.gv_id);
  }
  for (const f of Object.values(tokenMapping.fonts  || {})) {
    if (typeof f === 'string' && f.startsWith('e-gv-')) knownGvIds.add(f);
    if (f?.gv_id) knownGvIds.add(f.gv_id);
  }
  for (const val of Object.values(tokenMapping)) {
    if (typeof val === 'string' && val.startsWith('e-gv-')) knownGvIds.add(val);
  }
}
log(`Known gv_ids: ${knownGvIds.size}`);

// Resolved font gv_ids
const resolvedFontGvIds = new Set();
if (fontData) {
  for (const f of (fontData.fonts || [])) if (f.status === 'RESOLVED' && f.gv_id) resolvedFontGvIds.add(f.gv_id);
  // Also load from tokenMapping for cross-reference
  for (const f of Object.values(tokenMapping?.fonts || {})) if (f.gv_id) resolvedFontGvIds.add(f.gv_id);
}

// Valid breakpoint names
const validBreakpoints = new Set([null, 'tablet', 'mobile', 'desktop']);

// ─────────────────────────────────────────────
// TREE COLLECTION HELPERS
// ─────────────────────────────────────────────

/** Collect all nodes in tree */
function collectNodes(tree) {
  const nodes = [];
  const root = Array.isArray(tree) ? tree : [tree];
  for (const n of root) walkTree(n, node => nodes.push(node));
  return nodes;
}

/** Recursively walk all values in a JSON object with path tracking */
function walkValues(obj, callback, path = 'root', seen = new WeakSet()) {
  if (!obj || typeof obj !== 'object' || seen.has(obj)) return;
  seen.add(obj);
  for (const [key, val] of Object.entries(obj)) {
    const p = `${path}.${key}`;
    callback(key, val, obj, p);
    if (val && typeof val === 'object') walkValues(val, callback, p, seen);
  }
}

/** Collect all variants from all style objects in tree */
function collectVariants(nodes) {
  const variants = [];
  for (const node of nodes) {
    for (const [styleId, styleDef] of Object.entries(node.styles || {})) {
      for (const variant of (styleDef.variants || [])) {
        variants.push({ nodeId: node.id, styleId, variant });
      }
    }
  }
  return variants;
}

// ─────────────────────────────────────────────
// THE 12 GUARDS
// ─────────────────────────────────────────────

const nodes = collectNodes(tree);
const allVariants = collectVariants(nodes);
log(`Nodes collected: ${nodes.length}, variants: ${allVariants.length}`);

function guard(id, severity, fn) {
  try {
    return fn(id, severity);
  } catch (e) {
    return { id, status: 'ERROR', severity: 'error', message: `Guard threw: ${e.message}` };
  }
}

// ── 1. TOKEN_EXISTENCE ──────────────────────────
function g1_TokenExistence() {
  const missing = [];
  for (const node of nodes) {
    const gvIds = extractGvIds(node);
    for (const gvId of gvIds) {
      if (!knownGvIds.has(gvId)) missing.push({ nodeId: node.id, gvId });
    }
  }
  if (missing.length === 0) {
    return { id: 'TOKEN_EXISTENCE', status: 'PASS', message: `All ${knownGvIds.size} e-gv-* IDs found in token-mapping.json` };
  }
  return {
    id: 'TOKEN_EXISTENCE', status: 'FAIL', severity: 'error',
    message: `${missing.length} e-gv-* ID(s) not found in token-mapping.json`,
    details: { missing: missing.slice(0, 10) },
  };
}

// ── 2. COLOR_CONSISTENCY ────────────────────────
function g2_ColorConsistency() {
  const mismatches = [];
  // Walk all props looking for global-color-variable references
  for (const node of nodes) {
    walkValues(node.styles, (key, val, parent, p) => {
      if (parent['$$type'] === 'global-color-variable' && typeof val === 'string' && val.startsWith('e-gv-')) {
        // Find the color entry in tokenMapping
        const entry = Object.values(tokenMapping?.colors || {}).find(c => c.gv_id === val);
        if (entry && entry.hex) {
          const normalized = normalizeHex(entry.hex);
          if (!normalized) mismatches.push({ path: p, gvId: val, issue: `Hex ungültig: ${entry.hex}` });
        } else if (!entry) {
          mismatches.push({ path: p, gvId: val, issue: 'gv_id nicht in tokenMapping.colors' });
        }
      }
    });
  }
  if (mismatches.length === 0) {
    return { id: 'COLOR_CONSISTENCY', status: 'PASS', message: 'All color references resolve to valid hex values' };
  }
  return {
    id: 'COLOR_CONSISTENCY', status: 'FAIL', severity: 'error',
    message: `${mismatches.length} color consistency issue(s) found`,
    details: { mismatches: mismatches.slice(0, 5) },
  };
}

// ── 3. FONT_RESOLUTION ──────────────────────────
function g3_FontResolution() {
  const unresolved = [];
  for (const node of nodes) {
    walkValues(node.styles, (key, val, parent, p) => {
      if (parent['$$type'] === 'global-font-variable' && typeof val === 'string' && val.startsWith('e-gv-')) {
        if (!knownGvIds.has(val)) {
          unresolved.push({ path: p, gvId: val, nodeId: node.id });
        }
      }
    });
  }
  if (unresolved.length === 0) {
    return { id: 'FONT_RESOLUTION', status: 'PASS', message: 'All font variables resolved' };
  }
  return {
    id: 'FONT_RESOLUTION', status: 'FAIL', severity: 'error',
    message: `${unresolved.length} font variable(s) not resolved`,
    details: { unresolved },
  };
}

// ── 4. BREAKPOINT_CONSISTENCY ───────────────────
function g4_BreakpointConsistency() {
  const invalidBps = [];
  for (const { nodeId, styleId, variant } of allVariants) {
    const bp = variant?.meta?.breakpoint;
    if (!validBreakpoints.has(bp)) {
      invalidBps.push({ nodeId, styleId, breakpoint: bp });
    }
  }
  if (invalidBps.length === 0) {
    return { id: 'BREAKPOINT_CONSISTENCY', status: 'PASS', message: 'All breakpoint values are valid' };
  }
  return {
    id: 'BREAKPOINT_CONSISTENCY', status: 'WARN', severity: 'warning',
    message: `${invalidBps.length} variant(s) with unknown breakpoint value`,
    details: { invalidBreakpoints: invalidBps },
  };
}

// ── 5. STYLE_CLASSES_BINDING ────────────────────
function g5_StyleClassesBinding() {
  const unbound = [];
  for (const node of nodes) {
    const classes = node.settings?.classes;
    const classesValue = Array.isArray(classes) ? classes : (classes?.value || []);
    for (const styleId of Object.keys(node.styles || {})) {
      if (!classesValue.includes(styleId)) {
        unbound.push({ nodeId: node.id, styleId, classesValue });
      }
    }
  }
  if (unbound.length === 0) {
    return { id: 'STYLE_CLASSES_BINDING', status: 'PASS', message: 'All style IDs are bound in settings.classes.value[]' };
  }
  return {
    id: 'STYLE_CLASSES_BINDING', status: 'FAIL', severity: 'error',
    message: `${unbound.length} style ID(s) not bound in settings.classes.value[]`,
    details: { unbound },
  };
}

// ── 6. NO_HARDCODED_HEX ─────────────────────────
function g6_NoHardcodedHex() {
  const found = [];
  for (const node of nodes) {
    walkValues(node.styles, (key, val, parent, p) => {
      if (typeof val === 'string' && /^#[0-9a-fA-F]{3,6}$/.test(val.trim())) {
        found.push({ path: p, value: val, nodeId: node.id, parentType: parent['$$type'] || null });
      }
    });
  }
  if (found.length === 0) {
    return { id: 'NO_HARDCODED_HEX', status: 'PASS', message: 'No hardcoded hex colors found in tree styles' };
  }
  return {
    id: 'NO_HARDCODED_HEX', status: 'FAIL', severity: 'error',
    message: `${found.length} hardcoded hex color(s) found (use global-color-variable instead)`,
    details: { found: found.slice(0, 10) },
  };
}

// ── 7. NO_PLAIN_STRINGS ─────────────────────────
function g7_NoPlainStrings() {
  const found = [];
  for (const node of nodes) {
    walkValues({ styles: node.styles, settings: node.settings }, (key, val, parent, p) => {
      if (typeof val === 'string' && val.startsWith('e-gv-')) {
        // OK only if parent has $$type: global-*-variable
        const parentType = parent['$$type'] || '';
        if (!parentType.includes('variable')) {
          found.push({ path: p, value: val, parentType: parentType || '(none)' });
        }
      }
    });
  }
  if (found.length === 0) {
    return { id: 'NO_PLAIN_STRINGS', status: 'PASS', message: 'No plain e-gv-* strings found (all properly $$type-wrapped)' };
  }
  return {
    id: 'NO_PLAIN_STRINGS', status: 'FAIL', severity: 'error',
    message: `${found.length} plain e-gv-* string(s) not wrapped in {$$type: "global-*-variable"}`,
    details: { found },
  };
}

// ── 8. FONT_NAMES_QUOTED ────────────────────────
function g8_FontNamesQuoted() {
  const unquoted = [];
  for (const node of nodes) {
    walkValues(node.styles, (key, val, parent, p) => {
      // font-family props with string values
      if (key === 'font-family' && parent['$$type'] === 'string') {
        const family = parent.value;
        if (typeof family === 'string' && family.includes(' ') && !family.startsWith('"') && !family.startsWith("'")) {
          unquoted.push({ path: p, family, nodeId: node.id });
        }
      }
    });
  }
  if (unquoted.length === 0) {
    return { id: 'FONT_NAMES_QUOTED', status: 'PASS', message: 'All multi-word font names are quoted or use gv variables' };
  }
  return {
    id: 'FONT_NAMES_QUOTED', status: 'WARN', severity: 'warning',
    message: `${unquoted.length} multi-word font name(s) missing quotes (will fail CSS output)`,
    details: { unquoted },
  };
}

// ── 9. BASE_VARIANT_NULL ────────────────────────
function g9_BaseVariantNull() {
  // Rebuild check per style
  const wrongBase = [];
  for (const node of nodes) {
    for (const [styleId, styleDef] of Object.entries(node.styles || {})) {
      const variants = styleDef.variants || [];
      if (variants.length > 0 && variants[0].meta?.breakpoint !== null) {
        wrongBase.push({ nodeId: node.id, styleId, firstBreakpoint: variants[0].meta?.breakpoint });
      }
    }
  }
  if (wrongBase.length === 0) {
    return { id: 'BASE_VARIANT_NULL', status: 'PASS', message: 'All base variants have breakpoint: null' };
  }
  return {
    id: 'BASE_VARIANT_NULL', status: 'FAIL', severity: 'error',
    message: `${wrongBase.length} style(s) have a non-null breakpoint as first variant`,
    details: { wrongBase },
  };
}

// ── 10. TABLET_VARIANTS ─────────────────────────
function g10_TabletVariants() {
  const missingTablet = [];
  for (const node of nodes) {
    for (const [styleId, styleDef] of Object.entries(node.styles || {})) {
      const variants  = styleDef.variants || [];
      const hasMobile = variants.some(v => v.meta?.breakpoint === 'mobile');
      const hasTablet = variants.some(v => v.meta?.breakpoint === 'tablet');
      if (hasMobile && !hasTablet) {
        missingTablet.push({ nodeId: node.id, styleId });
      }
    }
  }
  if (missingTablet.length === 0) {
    return { id: 'TABLET_VARIANTS', status: 'PASS', message: 'All responsive elements have tablet variant' };
  }
  return {
    id: 'TABLET_VARIANTS', status: 'WARN', severity: 'warning',
    message: `${missingTablet.length} element(s) have mobile variant but no tablet variant`,
    details: { missing: missingTablet.map(m => m.nodeId) },
  };
}

// ── 11. BACKGROUND_COLOR_GC ─────────────────────
function g11_BackgroundColorGC() {
  // background.color in local props should always reference a global variable, never be hardcoded
  const hardcoded = [];
  for (const node of nodes) {
    walkValues(node.styles, (key, val, parent, p) => {
      if (key === 'background.color' && parent['$$type'] === 'color') {
        hardcoded.push({ path: p, value: parent.value, nodeId: node.id });
      }
    });
  }
  if (hardcoded.length === 0) {
    return { id: 'BACKGROUND_COLOR_GC', status: 'PASS', message: 'All background.color props use global color variables' };
  }
  return {
    id: 'BACKGROUND_COLOR_GC', status: 'FAIL', severity: 'error',
    message: `${hardcoded.length} background.color prop(s) use hardcoded color instead of global-color-variable`,
    details: { hardcoded },
  };
}

// ── 13. GC_POTENTIAL (P1-5) ─────────────────────
// Schätzt wie viele Style-Duplikate im Tree existieren und
// ob sich GC-Generierung lohnt. Verhindert Builds mit
// >20 Duplikaten (die manuell GCs brauchen).
function g13_GcPotential() {
  const styleHashes = new Map(); // hash → count
  let totalStyledElements = 0;

  for (const node of nodes) {
    if (!node.styles || typeof node.styles !== 'object') continue;
    for (const [styleId, styleDef] of Object.entries(node.styles)) {
      if (styleId.startsWith('gc-')) continue; // GC-Referenzen zählen nicht
      if (!styleDef || !Array.isArray(styleDef.variants)) continue;

      const baseVariant = styleDef.variants.find(
        v => v?.meta?.breakpoint === null || v?.meta?.breakpoint === 'desktop'
      );
      if (!baseVariant?.props) continue;

      const props = baseVariant.props;
      // Stabilen Hash der Props erstellen (nur keys + types, nicht values)
      const propSig = Object.keys(props).sort().map(k => {
        const v = props[k];
        return `${k}:${v?.['$$type'] || typeof v}`;
      }).join('|');
      if (!propSig) continue;

      // Simple string hash (no crypto needed for pre-build estimate)
      let hash = 0;
      for (let i = 0; i < propSig.length; i++) {
        hash = ((hash << 5) - hash) + propSig.charCodeAt(i);
        hash |= 0;
      }
      const hashKey = Math.abs(hash).toString(16).slice(0, 8);
      styleHashes.set(hashKey, (styleHashes.get(hashKey) || 0) + 1);
      totalStyledElements++;
    }
  }

  // Zähle Duplikate (Hash mit count > 1)
  const duplicatePatterns = [...styleHashes.entries()].filter(([, c]) => c > 1);
  const totalDuplicates = duplicatePatterns.reduce((sum, [, c]) => sum + c - 1, 0);
  const uniquePatterns = styleHashes.size - duplicatePatterns.length;

  if (totalDuplicates === 0) {
    return {
      id: 'GC_POTENTIAL', status: 'PASS',
      message: `Keine Style-Duplikate gefunden (${uniquePatterns} unique Patterns, ${totalStyledElements} styled elements). GCs nicht nötig.`,
    };
  }

  const dupPct = Math.round((totalDuplicates / Math.max(totalStyledElements, 1)) * 100);

  if (totalDuplicates > 20) {
    return {
      id: 'GC_POTENTIAL', status: 'FAIL', severity: 'error',
      message: `${totalDuplicates} Style-Duplikate (${dupPct}%) — GC-Generierung PFLICHT vor Build. Führe convert-xml-to-v4.js mit --gc aus.`,
      details: { totalStyledElements, uniquePatterns, duplicatePatterns: duplicatePatterns.length, totalDuplicates, recommendation: 'generate-global-classes.js --tree <tree> --apply' },
    };
  }

  if (totalDuplicates > 10) {
    return {
      id: 'GC_POTENTIAL', status: 'WARN', severity: 'warning',
      message: `${totalDuplicates} Style-Duplikate (${dupPct}%) — GC-Generierung empfohlen. Reduziert JSON um ~${dupPct}%.`,
      details: { totalStyledElements, uniquePatterns, duplicatePatterns: duplicatePatterns.length, totalDuplicates },
    };
  }

  return {
    id: 'GC_POTENTIAL', status: 'PASS',
    message: `${totalDuplicates} Style-Duplikate (${dupPct}%) — unter der Warn-Schwelle von 10.`,
  };
}

// ── 12. IMAGE_SRC_FORMAT ────────────────────────
function g12_ImageSrcFormat() {
  const invalid = [];
  for (const node of nodes) {
    const seenPaths = new Set();
    walkValues({ settings: node.settings, styles: node.styles }, (key, val, parent, p) => {
      if (parent['$$type'] === 'image-src') {
        const parentPath = p.slice(0, p.lastIndexOf('.')) || p;
        if (seenPaths.has(parentPath)) return;
        seenPaths.add(parentPath);
        const imageValue = parent.value && typeof parent.value === 'object' ? parent.value : parent;
        // Invariant IV: exactly-one-non-null(id, url)
        const hasId = imageValue.id !== undefined && imageValue.id !== null;
        const hasUrl = imageValue.url !== undefined && imageValue.url !== null;
        // Both missing/null → error
        if (!hasId && !hasUrl) {
          invalid.push({ path: p, issue: 'Both id and url are missing/null — exactly one must be non-null', nodeId: node.id });
        }
        // Both non-null → error (violates exactly-one constraint)
        if (hasId && hasUrl) {
          invalid.push({ path: p, issue: 'Both id and url are non-null — exactly one must be non-null. Omit url when id is set.', nodeId: node.id });
        }
        // Check for url: null explicitly (PHP sanitize strips null values)
        if ('url' in imageValue && imageValue.url === null) {
          invalid.push({ path: p, issue: 'url: null is present — omit the url key entirely (PHP sanitize strips null)', nodeId: node.id });
        }
      }
    });
  }
  if (invalid.length === 0) {
    return { id: 'IMAGE_SRC_FORMAT', status: 'PASS', message: 'All image-src objects have correct format (exactly-one-non-null, no url: null)' };
  }
  return {
    id: 'IMAGE_SRC_FORMAT', status: 'FAIL', severity: 'error',
    message: `${invalid.length} image-src format issue(s) found`,
    details: { invalid },
  };
}

// ─────────────────────────────────────────────
// RUN ALL GUARDS
// ─────────────────────────────────────────────

const guards = [
  g1_TokenExistence(),
  g2_ColorConsistency(),
  g3_FontResolution(),
  g4_BreakpointConsistency(),
  g5_StyleClassesBinding(),
  g6_NoHardcodedHex(),
  g7_NoPlainStrings(),
  g8_FontNamesQuoted(),
  g9_BaseVariantNull(),
  g10_TabletVariants(),
  g11_BackgroundColorGC(),
  g12_ImageSrcFormat(),
  g13_GcPotential(),
];

// ─────────────────────────────────────────────
// SCORE + SUMMARY
// ─────────────────────────────────────────────

const passed   = guards.filter(g => g.status === 'PASS').length;
const warnings = guards.filter(g => g.status === 'WARN').length;
const errors   = guards.filter(g => g.status === 'FAIL').length;
const score    = Math.round((passed / guards.length) * 100);
const blocked  = score < 85 || errors > 0;

const errorGuards   = guards.filter(g => g.status === 'FAIL');
const warningGuards = guards.filter(g => g.status === 'WARN');

const result = {
  meta: {
    treeFile:   args.tree,
    treeNodes:  nodes.length,
    checksRun:  guards.length,
    passed,
    warnings,
    errors,
    score,
  },
  guards,
  summary: {
    status: blocked ? 'BLOCKED' : 'OK',
    reason: blocked
      ? (errors > 0
          ? `${errors} error(s) found: ${errorGuards.map(g=>g.id).join(', ')}`
          : `Score ${score}% below 85% threshold`)
      : 'All critical checks passed',
    action: blocked
      ? `Fix ${errors} error(s) before running elementor-set-content`
      : warnings > 0 ? `${warnings} warning(s) to review (build allowed)` : 'Ready to build',
  },
};

// ─────────────────────────────────────────────
// OUTPUT
// ─────────────────────────────────────────────

const output = JSON.stringify(result, null, 2);
if (args.output) {
  fs.mkdirSync(path.dirname(path.resolve(args.output)), { recursive: true });
  fs.writeFileSync(args.output, output, 'utf8');
  process.stderr.write(`Saved to ${args.output}\n`);
} else {
  process.stdout.write(output + '\n');
}

// Console summary
const statusIcon = blocked ? '✗' : '✓';
process.stderr.write(`\n${statusIcon} Score: ${score}% (${passed}/${guards.length} checks passed)\n`);
if (errors   > 0) process.stderr.write(`  ✗ ${errors} error(s):   ${errorGuards.map(g=>g.id).join(', ')}\n`);
if (warnings > 0) process.stderr.write(`  ⚠ ${warnings} warning(s): ${warningGuards.map(g=>g.id).join(', ')}\n`);
process.stderr.write(`  → ${result.summary.action}\n\n`);

process.exit(blocked ? 1 : 0);
