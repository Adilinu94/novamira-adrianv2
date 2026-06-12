#!/usr/bin/env node
/**
 * check-v4-requirements.js
 *
 * FIX 2: Hard-Stop Guard für Elementor V4 Atomic Widgets.
 *
 * Das Problem:
 *   elementor-check-setup gibt atomic.runtime_available: false zurück und schreibt
 *   es als String in issues[] — aber mit exit code 0. Ein Agent der das übersieht
 *   baut den kompletten V4-Tree und scheitert erst bei elementor-set-content mit
 *   "type not available" — nach Minuten Arbeit.
 *
 * Diese Lösung:
 *   Liest die JSON-Ausgabe von elementor-check-setup und bricht mit exit 1 + klarer
 *   Anweisung ab, BEVOR irgendein Script den Tree baut.
 *
 * Zwei Modi:
 *   A) --check-setup-json <file>  Liest gespeicherte Ausgabe von elementor-check-setup
 *   B) --mcp-response <file>      Wie A, alias
 *   C) Kein Input                 Gibt nur die Prüf-Checkliste aus (dry guidance)
 *
 * Usage:
 *   # Vor dem Pipeline-Start: Ausgabe von elementor-check-setup prüfen
 *   node scripts/check-v4-requirements.js --check-setup-json setup.json
 *
 *   # In wizard.js eingebettet (liest von stdin):
 *   echo '<json>' | node scripts/check-v4-requirements.js --stdin
 *
 *   # Nur Guidance ausgeben (kein File nötig):
 *   node scripts/check-v4-requirements.js --guidance
 *
 * Exit codes:
 *   0 = alle V4-Anforderungen erfüllt
 *   1 = HARD STOP — V4 nicht nutzbar, Build darf nicht starten
 *   2 = Input-Fehler
 */

'use strict';

import { parseArgs }  from 'node:util';
import { readFileSync, existsSync } from 'node:fs';

const { values: args } = parseArgs({
  options: {
    'check-setup-json': { type: 'string' },
    'mcp-response':     { type: 'string' },
    'stdin':            { type: 'boolean', default: false },
    'auto-call':        { type: 'boolean', default: false },
    'guidance':         { type: 'boolean', default: false },
    'server-info':      { type: 'string' },  // P2-2: JSON file with phpinfo() data
    'help':             { type: 'boolean', default: false },
  },
  strict: false,
});

// ─── ANSI ─────────────────────────────────────────────────────────────────────
const C = {
  reset:  '\x1b[0m',
  bold:   '\x1b[1m',
  red:    '\x1b[31m',
  yellow: '\x1b[33m',
  green:  '\x1b[32m',
  cyan:   '\x1b[36m',
};

// ─── Guidance (immer ausgeben) ─────────────────────────────────────────────────
const GUIDANCE = `
${C.bold}Elementor V4 Atomic — Voraussetzungen checken${C.reset}

Bevor die Framer-Pipeline startet, diese 3 Punkte in WordPress prüfen:

  ${C.cyan}1. Atomic Widgets Experiment einschalten${C.reset}
     Elementor → Settings → Features → "Atomic Widgets" → ON
     (Ohne das: e-heading, e-flexbox usw. sind nicht registriert → Build schlägt fehl)

  ${C.cyan}2. elementor-check-setup aufrufen${C.reset}
     MCP: novamira/elementor-check-setup {}
     Erwartete Werte:
       atomic.runtime_available: true       ← PFLICHT
       atomic.global_classes_available: true ← PFLICHT für GC-Workflow
       atomic.variables_available: true      ← PFLICHT für e-gv-* Token
       elementor.min_version_met: true       ← Elementor ≥ 3.19

  ${C.cyan}3. Ausgabe prüfen lassen${C.reset}
     node scripts/check-v4-requirements.js --check-setup-json setup.json

${C.yellow}Wenn atomic.runtime_available: false:${C.reset}
  → Elementor → Settings → Features → Atomic Widgets → Aktivieren → Speichern
  → Cache leeren (Elementor → Tools → Regenerate Files)
  → elementor-check-setup erneut aufrufen
`;

if (args.help || args.guidance) {
  process.stdout.write(GUIDANCE);
  process.exit(0);
}

// ─── Load check-setup output ──────────────────────────────────────────────────

let setupData = null;

const inputFile = args['check-setup-json'] || args['mcp-response'];

// ── NEU: --auto-call (Fix D) — Direkter elementor-check-setup via McpBridge ──
if (args['auto-call']) {
  try {
    const { McpBridge } = await import('./lib/mcp-bridge.js');
    const mcp = await McpBridge.fromConfig();
    process.stderr.write(`[check-v4] Rufe elementor-check-setup auf...\n`);
    const raw = await mcp.call('novamira/elementor-check-setup', {});
    setupData = raw.data ?? raw;
    process.stderr.write(`[check-v4] elementor-check-setup erfolgreich.\n`);
  } catch (err) {
    process.stderr.write(`${C.red}[check-v4] Auto-Call fehlgeschlagen: ${err.message}${C.reset}\n`);
    process.stderr.write(`${C.yellow}[check-v4] Fallback: Speichere die Ausgabe von elementor-check-setup als JSON und verwende --check-setup-json <datei>${C.reset}\n`);
    process.exit(2);
  }
}

if (inputFile && !setupData) {
  // --check-setup-json wird nur genutzt wenn --auto-call nicht lief oder fehlschlug
  if (!existsSync(inputFile)) {
    process.stderr.write(`${C.red}FEHLER: Datei nicht gefunden: ${inputFile}${C.reset}\n`);
    process.exit(2);
  }
  try {
    const raw = JSON.parse(readFileSync(inputFile, 'utf8'));
    // Accept both direct response and { data: {...} } wrapper
    setupData = raw.data ?? raw;
  } catch (e) {
    process.stderr.write(`${C.red}FEHLER: Ungültiges JSON in ${inputFile}: ${e.message}${C.reset}\n`);
    process.exit(2);
  }
} else if (!setupData && args.stdin) {
  let buf = '';
  process.stdin.setEncoding('utf8');
  for await (const chunk of process.stdin) buf += chunk;
  try {
    const raw = JSON.parse(buf);
    setupData = raw.data ?? raw;
  } catch (e) {
    process.stderr.write(`${C.red}FEHLER: Ungültiges JSON auf stdin: ${e.message}${C.reset}\n`);
    process.exit(2);
  }
} else if (!setupData) {
  // No input — just print guidance
  process.stdout.write(GUIDANCE);
  process.exit(0);
}

// ─── Guard: Sicherstellen dass setupData ein Objekt ist ───────────────────────
if (!setupData || typeof setupData !== 'object') {
  process.stderr.write(`${C.red}FEHLER: Keine gültigen Check-Setup-Daten. setupData ist ${typeof setupData}.${C.reset}\n`);
  process.stderr.write(`${C.yellow}Bitte elementor-check-setup erneut ausführen und Ausgabe als JSON speichern.${C.reset}\n`);
  process.exit(2);
}

// ─── P2-2: Server Capacity Checks (separate mode) ────────────────────────────

if (args['server-info']) {
  processServerInfo(args['server-info']);
  // processServerInfo exits internally
}

function processServerInfo(filePath) {
  if (!existsSync(filePath)) {
    process.stderr.write(`${C.red}FEHLER: Server-Info Datei nicht gefunden: ${filePath}${C.reset}\n`);
    process.exit(2);
  }
  let info;
  try {
    info = JSON.parse(readFileSync(filePath, 'utf8'));
    info = info.data ?? info;
  } catch (e) {
    process.stderr.write(`${C.red}FEHLER: Ungültiges JSON in ${filePath}: ${e.message}${C.reset}\n`);
    process.exit(2);
  }

  process.stderr.write(`\n${C.bold}Server-Kapazitäts-Checks${C.reset}\n\n`);

  let warnings = 0;
  let errors = 0;

  // php_max_input_vars: critical for large trees (>500KB)
  const maxInputVars = parseInt(info.php_max_input_vars || info.max_input_vars || info.max_input_nesting_level || '0', 10);
  if (maxInputVars > 0) {
    if (maxInputVars < 2000) {
      process.stderr.write(`  ${C.red}✗ php_max_input_vars: ${maxInputVars} — KRITISCH (min 2000, empfohlen 5000+)${C.reset}\n`);
      process.stderr.write(`    ${C.yellow}Fix:${C.reset} php.ini → max_input_vars = 5000 → Server neustarten\n\n`);
      errors++;
    } else if (maxInputVars < 5000) {
      process.stderr.write(`  ${C.yellow}⚠ php_max_input_vars: ${maxInputVars} — ausreichend, 5000+ empfohlen für große Trees${C.reset}\n`);
      warnings++;
    } else {
      process.stderr.write(`  ${C.green}✓ php_max_input_vars: ${maxInputVars}${C.reset}\n`);
    }
  } else {
    process.stderr.write(`  ${C.yellow}⚠ php_max_input_vars: Konnte Wert nicht ermitteln${C.reset}\n`);
    warnings++;
  }

  // memory_limit
  const memoryLimitRaw = info.memory_limit || info.wp_memory_limit || '0';
  const memoryLimitMB = parseMemoryLimit(memoryLimitRaw);
  if (memoryLimitMB > 0) {
    if (memoryLimitMB < 128) {
      process.stderr.write(`  ${C.red}✗ memory_limit: ${memoryLimitRaw} — KRITISCH (min 128M, empfohlen 256M+)${C.reset}\n`);
      process.stderr.write(`    ${C.yellow}Fix:${C.reset} wp-config.php → define('WP_MEMORY_LIMIT', '256M'); → Server neustarten\n\n`);
      errors++;
    } else if (memoryLimitMB < 256) {
      process.stderr.write(`  ${C.yellow}⚠ memory_limit: ${memoryLimitRaw} — ausreichend, 256M+ empfohlen${C.reset}\n`);
      warnings++;
    } else {
      process.stderr.write(`  ${C.green}✓ memory_limit: ${memoryLimitRaw}${C.reset}\n`);
    }
  } else {
    process.stderr.write(`  ${C.yellow}⚠ memory_limit: Konnte Wert nicht ermitteln${C.reset}\n`);
    warnings++;
  }

  // Tree size estimate check (if available)
  const treeSizeKB = info.estimated_tree_size_kb || info.tree_size_kb || 0;
  if (treeSizeKB > 0) {
    if (treeSizeKB > 500) {
      process.stderr.write(`  ${C.red}✗ Tree-Größe geschätzt: ${treeSizeKB}KB — überschreitet empfohlenes 500KB-Limit${C.reset}\n`);
      process.stderr.write(`    ${C.yellow}Empfehlung:${C.reset} --gc anwenden um Tree zu deduplizieren\n\n`);
      errors++;
    } else if (treeSizeKB > 300) {
      process.stderr.write(`  ${C.yellow}⚠ Tree-Größe geschätzt: ${treeSizeKB}KB — GC-Deduplizierung empfohlen${C.reset}\n`);
      warnings++;
    }
  }

  process.stderr.write('\n');

  if (errors > 0) {
    process.stderr.write(`${C.red}${C.bold}Server-Kapazität unzureichend — ${errors} kritische(s) Problem(e)${C.reset}\n`);
    process.stderr.write(`${C.yellow}Bitte Server-Konfiguration anpassen und erneut prüfen.${C.reset}\n\n`);
    process.exit(1);
  }

  process.stderr.write(`${C.green}${C.bold}✓ Server-Kapazität ausreichend${C.reset}`);
  if (warnings > 0) process.stderr.write(` (${warnings} Warnung(en))`);
  process.stderr.write('\n\n');

  process.stdout.write(JSON.stringify({
    pass: true,
    warnings,
    errors,
    php_max_input_vars: maxInputVars || null,
    memory_limit_mb: memoryLimitMB || null,
    estimated_tree_size_kb: treeSizeKB || null,
  }, null, 2) + '\n');

  process.exit(0);
}

function parseMemoryLimit(raw) {
  const match = String(raw).match(/^(\d+)\s*([MG])?$/i);
  if (!match) return 0;
  const num = parseInt(match[1], 10);
  const unit = (match[2] || 'M').toUpperCase();
  return unit === 'G' ? num * 1024 : num;
}

// ─── Checks ──────────────────────────────────────────────────────────────────

const atomic   = setupData.atomic   ?? {};
const elem     = setupData.elementor ?? {};
const kit      = setupData.kit       ?? {};
const issues   = setupData.issues    ?? [];

const checks = [
  {
    id:       'ATOMIC_RUNTIME',
    label:    'atomic.runtime_available',
    pass:     atomic.runtime_available === true,
    severity: 'HARD_STOP',
    fix:      'Elementor → Settings → Features → "Atomic Widgets" einschalten, dann Cache leeren',
  },
  {
    id:       'ATOMIC_GLOBAL_CLASSES',
    label:    'atomic.global_classes_available',
    pass:     atomic.global_classes_available === true,
    severity: 'HARD_STOP',
    fix:      'Global Classes sind Teil von Atomic Widgets — Atomic Widgets Experiment muss ON sein',
  },
  {
    id:       'ATOMIC_VARIABLES',
    label:    'atomic.variables_available',
    pass:     atomic.variables_available === true,
    severity: 'HARD_STOP',
    fix:      'Variables (e-gv-*) benötigen Atomic Widgets Experiment ON',
  },
  {
    id:       'ATOMIC_STYLE_SCHEMA',
    label:    'atomic.style_schema_available',
    pass:     atomic.style_schema_available === true,
    severity: 'WARNING',
    fix:      'Style-Schema nicht verfügbar — $$type-Validierung eingeschränkt',
  },
  {
    id:       'ELEMENTOR_MIN_VERSION',
    label:    'elementor.min_version_met',
    pass:     elem.min_version_met === true,
    severity: 'HARD_STOP',
    fix:      `Elementor Version zu alt (${elem.version ?? '?'}) — mindestens 3.19.0 erforderlich`,
  },
  {
    id:       'ELEMENTOR_PRO_ACTIVE',
    label:    'elementor_pro.active',
    pass:     setupData.elementor_pro?.active === true,
    severity: 'WARNING',
    fix:      'Elementor Pro nicht aktiv — GC-Workflow und Variables benötigen Pro',
  },
];

// ─── Report ──────────────────────────────────────────────────────────────────

process.stderr.write(`\n${C.bold}check-v4-requirements.js${C.reset}\n\n`);

const hardStops = [];
const warnings  = [];

for (const c of checks) {
  const icon = c.pass
    ? `${C.green}✓${C.reset}`
    : c.severity === 'HARD_STOP' ? `${C.red}✗${C.reset}` : `${C.yellow}⚠${C.reset}`;

  process.stderr.write(`  ${icon}  ${c.label.padEnd(40)} ${c.pass ? C.green + 'OK' + C.reset : C.red + 'FAIL' + C.reset}\n`);

  if (!c.pass) {
    if (c.severity === 'HARD_STOP') hardStops.push(c);
    else warnings.push(c);
  }
}

// Any issues from elementor-check-setup itself
const blockerIssues = issues.filter(i =>
  i.includes('e_atomic_elements') ||
  i.includes('not registered')    ||
  i.includes('experiment is OFF')
);

process.stderr.write('\n');

if (blockerIssues.length > 0) {
  process.stderr.write(`${C.red}${C.bold}Elementor Issues erkannt:${C.reset}\n`);
  for (const issue of blockerIssues) {
    process.stderr.write(`  ${C.red}→ ${issue}${C.reset}\n`);
  }
  process.stderr.write('\n');
}

if (hardStops.length > 0) {
  process.stderr.write(`${C.red}${C.bold}━━━ HARD STOP — Pipeline darf NICHT starten ━━━${C.reset}\n\n`);

  for (const c of hardStops) {
    process.stderr.write(`  ${C.red}✗ ${c.id}${C.reset}\n`);
    process.stderr.write(`    ${C.yellow}Fix:${C.reset} ${c.fix}\n\n`);
  }

  process.stderr.write(`${C.bold}Reihenfolge:${C.reset}\n`);
  process.stderr.write(`  1. WordPress → Elementor → Settings → Features → Atomic Widgets → ${C.green}ON${C.reset}\n`);
  process.stderr.write(`  2. Elementor → Tools → Regenerate CSS & Data → Cache leeren\n`);
  process.stderr.write(`  3. novamira/elementor-check-setup {} erneut aufrufen\n`);
  process.stderr.write(`  4. Ausgabe erneut mit diesem Script prüfen\n`);
  process.stderr.write(`  5. Erst dann: Pipeline starten\n\n`);

  process.exit(1);
}

if (warnings.length > 0) {
  process.stderr.write(`${C.yellow}${C.bold}Warnungen (Pipeline kann starten, aber überprüfen):${C.reset}\n`);
  for (const w of warnings) {
    process.stderr.write(`  ${C.yellow}⚠ ${w.id}${C.reset} — ${w.fix}\n`);
  }
  process.stderr.write('\n');
}

process.stderr.write(`${C.green}${C.bold}✓ Alle V4-Pflichtanforderungen erfüllt — Pipeline kann starten.${C.reset}\n\n`);

// Output JSON summary for scripting
process.stdout.write(JSON.stringify({
  pass: true,
  hard_stops: 0,
  warnings: warnings.length,
  atomic,
  elementor_version: elem.version,
  kit_breakpoints: kit.active_breakpoints ?? [],
}, null, 2) + '\n');

process.exit(0);
