#!/usr/bin/env node
/**
 * parallel-pre-build.js — Phase 2.2: Parallel-Phase-Execution
 *
 * Führt 5 unabhängige Pre-Build Sub-Steps parallel via Promise.allSettled aus:
 *   XML-Conversion, Design-System-Export, Token-Extraction,
 *   Global-Classes, Asset-Upload
 *
 * Speedup: Phase 2 ~5 Min → ~1.5 Min
 *
 * Usage:
 *   node scripts/parallel-pre-build.js --tree v4-tree.json --export-dir ./FramerExport/
 */

import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { parseArgs } from 'node:util';
import { execFile } from 'child_process';
import { promisify } from 'util';

const execFileAsync = promisify(execFile);
const nodeBin = process.execPath;

const { values: args } = parseArgs({
  options: {
    tree:        { type: 'string' },
    'export-dir':{ type: 'string' },
    'gc-output':  { type: 'string' },  // P2-3: custom path for gc-plan.json
    verbose:     { type: 'boolean', default: false },
    help:        { type: 'boolean', default: false },
  },
  strict: false,
});

if (args.help) {
  console.log('parallel-pre-build.js — Parallel execution of 5 independent pre-build steps');
  console.log('  --tree FILE       v4-tree.json');
  console.log('  --export-dir DIR  FramerExport directory');
  console.log('  --gc-output FILE  Custom path for gc-plan.json (default: gc-plan.json)');
  console.log('  --verbose         Detailed logs');
  process.exit(0);
}

const log = (...m) => args.verbose && process.stderr.write('[parallel] ' + m.join(' ') + '\n');

async function runStep(label, script, scriptArgs, cwd) {
  const start = Date.now();
  try {
    await execFileAsync(nodeBin, [script, ...scriptArgs], { cwd, maxBuffer: 20 * 1024 * 1024, timeout: 120000 });
    return { label, ok: true, ms: Date.now() - start };
  } catch (e) {
    return { label, ok: false, ms: Date.now() - start, error: (e.stderr || e.message || '').slice(0, 200) };
  }
}

const treePath = args.tree || 'v4-tree.json';
const exportDir = args['export-dir'] || '.';

if (!existsSync(treePath)) {
  process.stderr.write('[parallel] v4-tree.json nicht gefunden. Bitte zuerst convert-xml-to-v4.js ausführen.\n');
  process.exit(1);
}

const gcOutput = args['gc-output'] || 'gc-plan.json';

const steps = [
  { label: 'convert',             script: 'scripts/convert-xml-to-v4.js',   args: ['--xml', treePath.replace('.json', '.xml'), '--output', treePath] },
  { label: 'gc-generate',         script: 'scripts/generate-global-classes.js', args: ['--tree', treePath, '--output', gcOutput] },
  { label: 'asset-upload',        script: 'scripts/asset-to-wp-media.js',   args: ['--assets-dir', resolve(exportDir, 'assets'), '--output', 'image-map.json'] },
  { label: 'token-extract',       script: 'scripts/design-token-extractor.js', args: ['--html', resolve(exportDir, 'index.html'), '--output', resolve(exportDir, 'tokens', 'token-mapping.json')] },
  { label: 'widget-plan',         script: 'scripts/html-to-widget-plan.js', args: ['--html', resolve(exportDir, 'index.html'), '--output', resolve(exportDir, 'tokens', 'widget-plan.json')] },
];

log(`Starte ${steps.length} Sub-Steps parallel...`);
const startTotal = Date.now();

const results = await Promise.allSettled(
  steps.map(s => runStep(s.label, s.script, s.args, process.cwd()))
);

const outcomes = results.map(r => r.value || { label: r.reason?.label || 'unknown', ok: false, error: r.reason?.message });

const ok = outcomes.filter(r => r.ok).length;
const fail = outcomes.filter(r => !r.ok).length;
const totalMs = Date.now() - startTotal;

console.log(`\n${'═'.repeat(50)}`);
console.log('  PARALLEL PRE-BUILD RESULTS');
console.log(`${'═'.repeat(50)}`);
for (const r of outcomes) {
  console.log(`  ${r.ok ? '✅' : '❌'} ${r.label.padEnd(20)} ${(r.ms / 1000).toFixed(1)}s${r.error ? ` — ${r.error}` : ''}`);
}
console.log(`${'═'.repeat(50)}`);
console.log(`  ${ok}/${steps.length} OK, ${fail} FAIL — ${(totalMs / 1000).toFixed(1)}s total`);

if (fail > 0) process.exit(1);
