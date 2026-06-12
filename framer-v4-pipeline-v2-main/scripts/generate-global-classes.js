#!/usr/bin/env node
/**
 * generate-global-classes.js
 * Analysiert einen V4 Widget-Tree (Output von convert-xml-to-v4.js) und
 * findet wiederkehrende Style-Patterns → schlägt Global Classes vor.
 *
 * Kernregeln (aus AGENTS.md):
 *   - ≥2 Elemente mit gleicher Style-Signatur → GC-Vorschlag
 *   - background.color → IMMER GC, auch bei nur 1 Element (Bug 3)
 *   - Structure GC + Color GC trennen
 *   - Naming: gc-<semantic>-<variant>
 */

import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { createHash } from 'node:crypto';
import { parseArgs } from 'node:util';

// ── CLI ────────────────────────────────────────────────────────────────────

const { values: args } = parseArgs({
  options: {
    tree:             { type: 'string' },
    variables:        { type: 'string' },
    output:           { type: 'string' },
    'min-dups':       { type: 'string', default: '2' },
    execute:          { type: 'boolean', default: false },
    'apply-results':  { type: 'string' },  // NEU: gc-results.json -> Tree
    'apply':          { type: 'boolean', default: false }, // P1-1: lokale Tree-Deduplizierung aus gc-plan.json
    'plan':           { type: 'string' },                  // P1-1: gc-plan.json Input fuer --apply
    'check-abilities':{ type: 'boolean', default: false }, // RC-12: query MCP for ability availability
    'hide-missing':   { type: 'boolean', default: false }, // RC-12: drop mcp_calls with missing abilities
    'mcp-config':     { type: 'string' },                  // path to .mcp.json
    verbose:          { type: 'boolean', default: false },
    help:             { type: 'boolean', default: false },
  },
  strict: false,
});

if (args.help) {
  console.log(`

generate-global-classes.js

ZWECK:
  Analysiert einen V4 Widget-Tree, findet wiederkehrende Style-Patterns
  und schlaegt Global Classes vor.

OPTIONEN:
  --tree FILE             V4 Widget-Tree JSON (von convert-xml-to-v4.js)  [required]
  --variables FILE        token-mapping.json (fuer Variable-Aufloesung, optional)
  --output FILE           Output-Pfad fuer gc-plan.json
  --min-dups N            Mindest-Duplikate fuer GC-Vorschlag  [default: 2]
  --execute               MCP-Plan fuer Agent ausgeben (statt nur suggest)
  --apply-results FILE    gc-results.json -> GC-IDs in Tree schreiben
  --apply                 Lokale Tree-Deduplizierung (liest gc-plan.json, kein MCP noetig)
  --plan FILE             gc-plan.json Input fuer --apply [default: gc-plan.json]
  --check-abilities       MCP-Bridge befragen welche referenced abilities existieren
  --hide-missing          mcp_calls mit nicht-existierenden abilities ausblenden
  --mcp-config FILE       Pfad zu .mcp.json (sonst ./ oder ../)
  --verbose               Ausfuehrliche Logs
  --help                  Diese Hilfe

WORKFLOW:
  1. node generate-global-classes.js --tree v4-tree.json --check-abilities --output gc-plan.json
  2. node generate-global-classes.js --tree v4-tree.json --execute --output gc-plan.json
  3. Agent fuehrt gc-plan.json aus:
     - novamira/elementor-create-global-class (pro GC)
     - novamira-adrianv2/add-global-class-variant (Varianten)
     - novamira-adrianv2/apply-variable-to-class (Token-Bindungen)
  4. Agent speichert GC-IDs als gc-results.json: { "label": "gc-<id>", ... }
  5. node generate-global-classes.js --apply-results gc-results.json --tree v4-tree.json

  # P1-1: Lokale Tree-Deduplizierung (kein MCP noetig):
  6. node generate-global-classes.js --tree v4-tree.json --apply --plan gc-plan.json --output v4-tree-deduped.json

EXIT-CODES:
  0   Analyse abgeschlossen, Vorschläge vorhanden
  1   Keine Duplikate gefunden (alle Styles sind unique)
  2   Tree nicht gefunden oder fehlerhaft
`);
  process.exit(0);
}

// ── Helpers ────────────────────────────────────────────────────────────────

const log = (...a) => args.verbose && process.stderr.write('[gen-gc] ' + a.join(' ') + '\n');
const warn = (...a) => process.stderr.write('[WARN] ' + a.join(' ') + '\n');
const fatal = (msg, code = 2) => { process.stderr.write('[FATAL] ' + msg + '\n'); process.exit(code); };

const MIN_DUPS = parseInt(args['min-dups'] ?? '2', 10);

// Props nach Kategorie
const TYPOGRAPHY_PROPS = new Set([
  'font-size', 'font-family', 'font-weight', 'font-style',
  'line-height', 'letter-spacing', 'text-transform',
  'text-decoration', 'color',
]);

const STRUCTURE_PROPS = new Set([
  'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
  'padding-inline-start', 'padding-inline-end', 'padding-block-start', 'padding-block-end',
  'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
  'gap', 'row-gap', 'column-gap',
  'max-width', 'min-width', 'max-height', 'min-height',
  'width', 'height',
  'flex-direction', 'flex-wrap', 'flex-grow', 'flex-shrink',
  'justify-content', 'align-items', 'align-self',
  'display', 'position',
]);

const BACKGROUND_PROPS = new Set([
  'background', 'background-color',
]);

// Prop-Kategorie ermitteln
function propCategory(prop) {
  if (TYPOGRAPHY_PROPS.has(prop)) return 'typography';
  if (STRUCTURE_PROPS.has(prop)) return 'structure';
  if (BACKGROUND_PROPS.has(prop)) return 'background';
  return 'other';
}

// Stabilen Hash einer Style-Signatur erstellen
function hashSignature(obj) {
  const stableStringify = (value) => {
    if (Array.isArray(value)) return `[${value.map(stableStringify).join(',')}]`;
    if (value && typeof value === 'object') {
      return `{${Object.keys(value).sort().map(k => `${JSON.stringify(k)}:${stableStringify(value[k])}`).join(',')}}`;
    }
    return JSON.stringify(value);
  };
  const str = stableStringify(obj);
  return createHash('md5').update(str).digest('hex').slice(0, 12);
}

// Semantischen GC-Namen aus Props ableiten
function suggestName(type, props, index) {
  const propKeys = Object.keys(props);

  if (type === 'typography') {
    const fontSize = props['font-size'];
    let size = '';
    if (fontSize) {
      const v = typeof fontSize === 'object' ? (fontSize.value?.size ?? '') : fontSize;
      const n = parseInt(String(v), 10);
      if (!isNaN(n)) {
        if (n >= 60) size = 'xxl';
        else if (n >= 48) size = 'xl';
        else if (n >= 36) size = 'lg';
        else if (n >= 24) size = 'md';
        else if (n >= 18) size = 'sm';
        else if (n >= 14) size = 'xs';
        else size = 'tiny';
      }
    }
    const hasColor = propKeys.includes('color');
    const base = hasColor ? 'text' : 'type';
    return `gc-${base}-${size || index}`;
  }

  if (type === 'structure') {
    const hasMaxWidth = propKeys.includes('max-width');
    const hasGap = propKeys.includes('gap');
    const hasPadding = propKeys.some(k => k.startsWith('padding'));
    if (hasMaxWidth && hasPadding) return `gc-section-${index}`;
    if (hasGap) return `gc-grid-${index}`;
    if (hasPadding) return `gc-pad-${index}`;
    return `gc-layout-${index}`;
  }

  if (type === 'background') {
    return `gc-bg-${index}`;
  }

  return `gc-style-${index}`;
}

// Stellt sicher dass GC-Namen keine Leerzeichen/Bindestriche enthalten
// (Elementor V4 Regel: kein Hyphen in Style-IDs → aber GC-Namen sind anders)
function sanitizeGcName(name) {
  // GC-Namen DÜRFEN Bindestriche enthalten (gc-heading-xl ist valide)
  // Nur Leerzeichen und Sonderzeichen entfernen
  return name.replace(/[^a-z0-9\-]/g, '').replace(/-+/g, '-').replace(/^-|-$/g, '');
}

// ── Tree durchlaufen ───────────────────────────────────────────────────────

function walkTree(node, cb) {
  if (!node || typeof node !== 'object') return;
  cb(node);
  const children = node.elements ?? node.children ?? [];
  if (Array.isArray(children)) {
    for (const child of children) walkTree(child, cb);
  }
}

// Style-Props aus einem V4-Element extrahieren
// V4 Format: element.styles = { "styleId": { variants: [{ meta:{breakpoint:null}, props: {...} }] } }
function extractStyleProps(element) {
  const styles = element.styles ?? {};
  const allProps = {};

  for (const [styleId, styleDef] of Object.entries(styles)) {
    const variants = styleDef.variants ?? [];
    const baseVariant = variants.find(v => v?.meta?.breakpoint === null) || variants.find(v => v.breakpoint === null || v.breakpoint == null);
    if (!baseVariant) continue;

    const props = baseVariant.props ?? baseVariant.values ?? {};
    for (const [prop, value] of Object.entries(props)) {
      allProps[prop] = value;
    }
  }

  return allProps;
}

// ── Load tree ──────────────────────────────────────────────────────────────

if (!args.tree) fatal('--tree ist required');

const treePath = resolve(args.tree);
if (!existsSync(treePath)) fatal(`Tree nicht gefunden: ${treePath}`);

let tree;
try {
  tree = JSON.parse(readFileSync(treePath, 'utf8'));
} catch (e) {
  fatal(`JSON-Parse-Fehler: ${e.message}`);
}

// Optionales token-mapping laden
let tokenMapping = {};
if (args.variables && existsSync(resolve(args.variables))) {
  try {
    tokenMapping = JSON.parse(readFileSync(resolve(args.variables), 'utf8'));
    log(`Token-Mapping geladen: ${Object.keys(tokenMapping).length} Tokens`);
  } catch (e) {
    warn(`token-mapping.json konnte nicht geladen werden: ${e.message}`);
  }
}

// ── Analyse ────────────────────────────────────────────────────────────────

const allElements = [];
// Unterstuetzt sowohl einzelne Root-Nodes als auch Arrays von Roots
const treeRoots = Array.isArray(tree) ? tree : [tree];
for (const root of treeRoots) {
  walkTree(root, (node) => {
    const id = node.id ?? node.settings?.id;
    if (!id) return;
    const props = extractStyleProps(node);
    if (Object.keys(props).length === 0) return;
    allElements.push({ id, widget: node.widget ?? node.widgetType ?? 'unknown', props });
  });
}

log(`${allElements.length} Elemente mit Styles gefunden`);

// Props nach Kategorie aufteilen
const typographySignatures = new Map(); // hash → [elements]
const structureSignatures = new Map();
const backgroundElements = [];        // background.color immer GC

for (const el of allElements) {
  const typoProps = {};
  const structProps = {};
  const bgProps = {};

  for (const [prop, value] of Object.entries(el.props)) {
    const cat = propCategory(prop);
    if (cat === 'typography') typoProps[prop] = value;
    else if (cat === 'structure') structProps[prop] = value;
    else if (cat === 'background') bgProps[prop] = value;
    // 'other' ignorieren
  }

  // KRITISCH: background.color immer GC (Bug 3)
  if (Object.keys(bgProps).length > 0) {
    backgroundElements.push({ ...el, filteredProps: bgProps });
  }

  if (Object.keys(typoProps).length > 0) {
    const sig = hashSignature(typoProps);
    if (!typographySignatures.has(sig)) {
      typographySignatures.set(sig, { props: typoProps, elements: [] });
    }
    typographySignatures.get(sig).elements.push(el.id);
  }

  if (Object.keys(structProps).length > 0) {
    const sig = hashSignature(structProps);
    if (!structureSignatures.has(sig)) {
      structureSignatures.set(sig, { props: structProps, elements: [] });
    }
    structureSignatures.get(sig).elements.push(el.id);
  }
}

// ── Global-Class-Vorschläge erstellen ──────────────────────────────────────

const suggestedClasses = [];
const ungroupedElements = [];
let gcIndex = 1;

// Typo-GCs (≥ MIN_DUPS Duplikate)
for (const [sig, { props, elements }] of typographySignatures) {
  if (elements.length >= MIN_DUPS) {
    const name = sanitizeGcName(suggestName('typography', props, gcIndex++));
    const reason = `${elements.length} Elemente mit identischer Typografie: ${
      Object.keys(props).slice(0, 3).join(', ')
    }${Object.keys(props).length > 3 ? ', ...' : ''}`;

    suggestedClasses.push({
      name,
      type: 'typography',
      reason,
      element_ids: elements,
      props,
      mcp_calls: [
        {
          ability: 'novamira-adrianv2/add-global-class-variant', // Variant auf bestehende GC setzen; GC selbst per execute-php oder Kit-Editor erstellen
          params: { name, type: 'class', props },
        },
      ],
    });
    log(`GC Typography: ${name} (${elements.length} Elemente)`);
  } else {
    // Einzelne Elemente als ungrouped markieren
    for (const id of elements) {
      ungroupedElements.push({
        element_id: id,
        reason: `Unique Typografie, nur ${elements.length} Element${elements.length > 1 ? 'e' : ''} — lokaler Style`,
      });
    }
  }
}

// Struktur-GCs (≥ MIN_DUPS Duplikate)
for (const [sig, { props, elements }] of structureSignatures) {
  if (elements.length >= MIN_DUPS) {
    const name = sanitizeGcName(suggestName('structure', props, gcIndex++));
    const reason = `${elements.length} Container mit identischem Layout: ${
      Object.keys(props).slice(0, 3).join(', ')
    }${Object.keys(props).length > 3 ? ', ...' : ''}`;

    suggestedClasses.push({
      name,
      type: 'structure',
      reason,
      element_ids: elements,
      props,
      mcp_calls: [
        {
          ability: 'novamira-adrianv2/add-global-class-variant', // Variant auf bestehende GC setzen; GC selbst per execute-php oder Kit-Editor erstellen
          params: { name, type: 'class', props },
        },
      ],
    });
    log(`GC Structure: ${name} (${elements.length} Elemente)`);
  } else {
    for (const id of elements) {
      const already = ungroupedElements.find(u => u.element_id === id);
      if (!already) {
        ungroupedElements.push({
          element_id: id,
          reason: `Unique Layout, nur ${elements.length} Element${elements.length > 1 ? 'e' : ''} — lokaler Style`,
        });
      }
    }
  }
}

// Background-GCs (IMMER, auch bei 1 Element — Bug 3 Schutz)
// Duplikate zusammenfassen
const bgSignatureMap = new Map();
for (const el of backgroundElements) {
  const sig = hashSignature(el.filteredProps);
  if (!bgSignatureMap.has(sig)) {
    bgSignatureMap.set(sig, { props: el.filteredProps, elements: [] });
  }
  bgSignatureMap.get(sig).elements.push(el.id);
}

for (const [sig, { props, elements }] of bgSignatureMap) {
  const name = sanitizeGcName(suggestName('background', props, gcIndex++));
  const reason = elements.length > 1
    ? `${elements.length} Elemente mit identischer Hintergrundfarbe (background.color → IMMER GC)`
    : `background.color → IMMER GC (Bug 3 Schutz), auch bei nur 1 Element`;

  suggestedClasses.push({
    name,
    type: 'background',
    reason,
    element_ids: elements,
    props,
    mcp_calls: [
      {
        ability: 'novamira-adrianv2/add-global-class-variant', // Variant auf bestehende GC setzen; GC selbst per execute-php oder Kit-Editor erstellen
        params: { name, type: 'class', props },
      },
    ],
  });
  log(`GC Background: ${name} (${elements.length} Elemente, Bug-3-Schutz)`);
}

// ── Doppelte ungrouped-Eintraege bereinigen ────────────────────────────────

// Ein Element kann in typo UND struktur unique sein → nur einmal melden
const ungroupedIds = new Set();
const dedupedUngrouped = ungroupedElements.filter(u => {
  if (ungroupedIds.has(u.element_id)) return false;
  ungroupedIds.add(u.element_id);
  return true;
});

// Elemente die in einer GC sind aus ungrouped entfernen
const gcElementIds = new Set(suggestedClasses.flatMap(gc => gc.element_ids));
const finalUngrouped = dedupedUngrouped.filter(u => !gcElementIds.has(u.element_id));

// ── Ability-Existenz-Prüfung (RC-12) ────────────────────────────────────────
async function probeAbilities(abilityNames) {
  const result = {};
  for (const name of abilityNames) result[name] = 'unknown';
  if (abilityNames.length === 0) return result;
  let McpBridge;
  try { const mod = await import('./lib/mcp-bridge.js'); McpBridge = mod.McpBridge; }
  catch (e) { log(`McpBridge nicht ladbar: ${e.message}`); return result; }
  let mcp;
  try { mcp = args['mcp-config'] ? await McpBridge.fromConfig(resolve(args['mcp-config'])) : await McpBridge.fromConfig(); }
  catch (e) { log(`MCP-Init fehlgeschlagen: ${e.message}`); return result; }
  await Promise.all(abilityNames.map(async (name) => {
    try {
      const r = await mcp.call(name, {});
      const errStr = String(r?.error || r?.data?.error || '');
      result[name] = /not found|ability.*not.*registered/i.test(errStr) ? 'missing' : 'available';
    } catch (e) {
      const msg = String(e?.message || e);
      result[name] = /not found|ability.*not.*registered/i.test(msg) ? 'missing' : 'error';
    }
  }));
  return result;
}

if (args['check-abilities']) {
  const allAbilityNames = [...new Set(
    suggestedClasses.flatMap(gc => (gc.mcp_calls || []).map(c => c.ability).filter(Boolean))
  )];
  process.stderr.write(`[gen-gc] Prüfe ${allAbilityNames.length} abilities via MCP-Bridge...\n`);
  const abilityStatus = await probeAbilities(allAbilityNames);
  for (const gc of suggestedClasses) {
    for (const call of gc.mcp_calls || []) {
      call.status = abilityStatus[call.ability] || 'unknown';
    }
  }
  for (const [name, status] of Object.entries(abilityStatus)) {
    process.stderr.write(`[gen-gc]   ${status === 'available' ? '✅' : status === 'missing' ? '❌' : '⚠️ '} ${name} → ${status}\n`);
  }
  if (args['hide-missing']) {
    for (const gc of suggestedClasses) {
      gc.mcp_calls = (gc.mcp_calls || []).filter(c => c.status !== 'missing');
    }
    process.stderr.write(`[gen-gc] --hide-missing: mcp_calls mit missing abilities ausgeblendet.\n`);
  }
}

// ── Output ─────────────────────────────────────────────────────────────────

const inlineReductionPct = allElements.length > 0
  ? Math.round((gcElementIds.size / allElements.length) * 100)
  : 0;

const plan = {
  meta: {
    totalElements: allElements.length,
    elementsWithStyles: allElements.length,
    uniqueTypographyPatterns: typographySignatures.size,
    uniqueStructurePatterns: structureSignatures.size,
    backgroundElements: backgroundElements.length,
    suggestedClasses: suggestedClasses.length,
    potentialInlineStyleReduction: `${inlineReductionPct}%`,
    minDuplicatesThreshold: MIN_DUPS,
    generatedAt: new Date().toISOString(),
  },
  suggested_classes: suggestedClasses,
  ungrouped_elements: finalUngrouped,
  requires_abilities: args['check-abilities']
    ? [...new Set(suggestedClasses.flatMap(gc => (gc.mcp_calls || []).map(c => c.ability).filter(Boolean)))]
        .map(name => {
          const sample = suggestedClasses.find(gc => (gc.mcp_calls || []).some(c => c.ability === name));
          const status = sample?.mcp_calls?.find(c => c.ability === name)?.status || 'unknown';
          return { ability: name, status };
        })
    : undefined,
  agentInstructions: [
    'SCHRITT 1: suggested_classes[] reviewen (Namen ggf. anpassen)',
    'SCHRITT 2: Für jede GC in suggested_classes[]:',
    '  1. novamira/execute-php: Global Class registrieren (post_type e_global_class)',
      '  2. novamira-adrianv2/add-global-class-variant: Breakpoint-Varianten + Props setzen',
      '  3. novamira-adrianv2/apply-variable-to-class: GV-Referenzen setzen (fuer Token-Bindung)',
    'SCHRITT 3: V4 Tree aktualisieren:',
    '  Lokale Props aus element_ids[] entfernen',
    '  settings.classes.value[] mit GC-Name ergänzen',
    'SCHRITT 4: elementor-set-content aufrufen',
    '',
    '── SCHRITT 2: GC-Vorschläge prüfen ───────────────────────────────────────',
    'suggested_classes[] reviewen — Namen ggf. anpassen (gc-<semantic>-<variant>)',
    '',
    '── SCHRITT 3: GCs via elementor-set-content anlegen ──────────────────────',
    'WICHTIG: Es gibt keine eigene "create-global-class" Ability.',
    'GCs entstehen implizit wenn der Tree via elementor-set-content geschrieben wird.',
    'Der V4 Tree muss die GC-Props in settings.classes.value[] referenzieren.',
    '',
    '── SCHRITT 4: Responsive Varianten ergänzen ──────────────────────────────',
    'Nach Build für jede GC: novamira-adrianv2/add-global-class-variant',
    '  → breakpoint: "tablet" oder "mobile", props: { <skalierte Werte> }',
    '',
    '── SCHRITT 5: GC auf alle Elemente batch-anwenden ────────────────────────',
    'novamira-adrianv2/batch-class: { class_id: "<gc-id>", element_ids: [...], action: "apply" }',
    'Viel effizienter als einzelne remove-global-class Calls.',
    '',
    '── SCHRITT 6: Post-Build QA ──────────────────────────────────────────────',
    'novamira-adrianv2/visual-qa { post_id }     → overflow, z-index, negative margins',
    'novamira-adrianv2/responsive-audit { post_id } → Breakpoint-Coverage prüfen',
    'novamira-adrianv2/class-audit { scope: "post_ids", post_ids: [<ID>] } → unused GCs',
    'novamira-adrianv2/export-design-system { what: "classes" } → Design-System sichern',
    '',
    '── WICHTIG: Bug-3 Schutz ─────────────────────────────────────────────────',
    'background.color NIE als lokalen Style in props setzen.',
    'IMMER als GC anlegen, auch bei nur 1 Element.',
  ],
};

if (suggestedClasses.length === 0) {
  process.stderr.write('[gen-gc] Keine Duplikate gefunden — alle Styles sind unique.\n');
  process.stderr.write('[gen-gc] Hinweis: background.color-Elemente wurden trotzdem als GC markiert.\n');
  process.exit(1);
}

// ── Plan-Fallback (wenn McpBridge nicht verfügbar) ───────────────────────────

function writeGcPlan() {
  const execPlan = {
    type: 'global-class-creation-plan',
    class_count: suggestedClasses.length,
    agent_instruction: [
      'Fuer jeden step in steps[]:',
      '  1. novamira/execute-php: Global Class registrieren (post_type e_global_class)',
      '  2. novamira-adrianv2/add-global-class-variant: Varianten + Props setzen',
      '  3. novamira-adrianv2/apply-variable-to-class: GV-Referenzen setzen',
      'Ergebnisse als gc-results.json: { "<gc-name>": "<gc-id>", ... }',
      'Dann: node scripts/generate-global-classes.js --apply-results gc-results.json --tree <tree>',
    ],
    steps: suggestedClasses.map(gc => ({
      label: gc.name,
      create_ability: 'novamira/execute-php',
      create_params: {
        label: gc.name,
        styles: gc.props || {},
      },
      variants: (gc.variants || []).map(v => ({
        ability: 'novamira-adrianv2/add-global-class-variant',
        params: { class_id: '{{gc_id}}', breakpoint: v.breakpoint || 'mobile', props: v.props || {} },
      })),
      variable_bindings: (gc.variable_bindings || []).filter(b => b.gv_id).map(b => ({
        ability: 'novamira-adrianv2/apply-variable-to-class',
        params: { class_id: '{{gc_id}}', breakpoint: 'desktop', prop: b.prop, variable_id: b.gv_id },
      })),
    })),
  };

  const planPath = args.output || 'gc-plan.json';
  writeFileSync(resolve(planPath), JSON.stringify(execPlan, null, 2), 'utf8');
  process.stderr.write(`[gc-execute] GC-Plan → ${planPath}\n`);
}

// ── Direkte GC-Execution (Fix E) ─────────────────────────────────────────────

async function executeGcPlan(gcList, treeFilePath, treeData, mcp, tokenMapping) {
  const gcIdMap = {}; // gc-name → gc-id

  // 1. setup-v4-foundation → bestehende GC-IDs abrufen
  process.stderr.write('[gc-execute] Rufe setup-v4-foundation auf...\n');
  let foundation;
  try {
    foundation = await mcp.call('novamira-adrianv2/setup-v4-foundation', {});
  } catch (err) {
    process.stderr.write(`[gc-execute] ⚠️  setup-v4-foundation fehlgeschlagen: ${err.message}\n`);
    process.stderr.write('[gc-execute] Fahre ohne bestehende GC-IDs fort.\n');
    foundation = { classes: {} };
  }

  const existingClasses = foundation.classes || {};
  process.stderr.write(`[gc-execute] ${Object.keys(existingClasses).length} bestehende GCs gefunden.\n`);

  let created = 0, skipped = 0, failed = 0;

  for (const gc of gcList) {
    const gcName = gc.name;
    process.stderr.write(`[gc-execute] GC: ${gcName} (${gc.type})...`);

    // Überspringe wenn GC bereits existiert
    if (existingClasses[gcName]) {
      gcIdMap[gcName] = existingClasses[gcName];
      process.stderr.write(` ✅ existiert (${existingClasses[gcName]})\n`);
      skipped++;
      continue;
    }

    try {
      // 2. GC via execute-php registrieren
      const escapedName = gcName.replace(/'/g, "\\'");
      const phpCode = [
        `$existing = get_page_by_path('${escapedName}', OBJECT, 'e_global_class');`,
        `if ($existing) { echo json_encode(['id' => 'gc-' . $existing->ID, 'post_id' => $existing->ID, 'existed' => true]); exit; }`,
        `$post_id = wp_insert_post([`,
        `  'post_title'  => '${escapedName}',`,
        `  'post_name'   => '${escapedName}',`,
        `  'post_type'   => 'e_global_class',`,
        `  'post_status' => 'publish',`,
        `]);`,
        `if (is_wp_error($post_id)) { echo json_encode(['error' => $post_id->get_error_message()]); exit; }`,
        `echo json_encode(['id' => 'gc-' . $post_id, 'post_id' => $post_id]);`,
      ].join('');

      let result;
      try {
        result = await mcp.call('novamira/execute-php', { code: phpCode });
      } catch (err) {
        // Fallback: execute-php nicht verfügbar → Plan-Modus
        throw new Error(`execute-php fehlgeschlagen: ${err.message}`);
      }

      const parsed = typeof result === 'string' ? JSON.parse(result) : result;
      if (parsed.error) {
        throw new Error(`PHP-Fehler: ${parsed.error}`);
      }

      const gcId = parsed.id || `gc-${parsed.post_id}`;
      gcIdMap[gcName] = gcId;
      created++;
      process.stderr.write(` ✅ ${gcId}`);

      // 3. Basis-Variant setzen (desktop, keine state)
      if (gc.props && Object.keys(gc.props).length > 0) {
        try {
          await mcp.call('novamira-adrianv2/add-global-class-variant', {
            class_id: gcId,
            breakpoint: 'desktop',
            props: gc.props,
          });
          process.stderr.write(' +variant');
        } catch (err) {
          process.stderr.write(` ⚠️variant:${err.message.slice(0, 60)}`);
        }
      }

      // 4. Responsive Varianten setzen
      for (const variant of gc.variants || []) {
        try {
          await mcp.call('novamira-adrianv2/add-global-class-variant', {
            class_id: gcId,
            breakpoint: variant.breakpoint,
            props: variant.props || {},
          });
          process.stderr.write(` +${variant.breakpoint}`);
        } catch (err) {
          process.stderr.write(` ⚠️${variant.breakpoint}:${err.message.slice(0, 40)}`);
        }
      }

      // 5. Variable-Bindungen setzen
      for (const binding of gc.variable_bindings || []) {
        if (!binding.gv_id) continue;
        try {
          await mcp.call('novamira-adrianv2/apply-variable-to-class', {
            class_id: gcId,
            breakpoint: 'desktop',
            prop: binding.prop,
            variable_id: binding.gv_id,
          });
          process.stderr.write(` +gv:${binding.prop}`);
        } catch (err) {
          process.stderr.write(` ⚠️gv:${err.message.slice(0, 40)}`);
        }
      }

      process.stderr.write('\n');

    } catch (err) {
      process.stderr.write(` ❌ ${err.message.slice(0, 200)}\n`);
      failed++;
    }
  }

  // 6. GC-IDs in Tree zurückschreiben
  // Baut element_id → gcId Map aus suggestedClasses[].element_ids.
  // Schreibt GC-Referenzen in settings.classes.value[] zurück.
  const elementGcMap = {}; // element_id → [gcId]
  for (const gc of gcList) {
    const gcId = gcIdMap[gc.name];
    if (!gcId) continue;
    for (const elemId of gc.element_ids) {
      if (!elementGcMap[elemId]) elementGcMap[elemId] = [];
      if (!elementGcMap[elemId].includes(gcId)) elementGcMap[elemId].push(gcId);
    }
  }

  let replacements = 0;

  if (Object.keys(elementGcMap).length > 0) {
    // Lokale walkTree (nicht framer-utils — konsistent mit Analyse)
    // Unterstützt Arrays am Root für mehr-Root Trees
    const roots = Array.isArray(treeData) ? treeData : [treeData];
    for (const root of roots) {
      walkTree(root, node => {
        const nodeId = node.id ?? node.settings?.id;
        if (!nodeId || !elementGcMap[nodeId]) return;

        const gcIds = elementGcMap[nodeId];

        // settings.classes anlegen/erweitern
        if (!node.settings) node.settings = {};
        if (!node.settings.classes) {
          node.settings.classes = { '$$type': 'classes', value: [] };
        }
        const classes = node.settings.classes.value;
        if (!Array.isArray(classes)) {
          node.settings.classes.value = [...gcIds];
          replacements += gcIds.length;
        } else {
          for (const gcId of gcIds) {
            if (!classes.includes(gcId)) {
              classes.push(gcId);
              replacements++;
            }
          }
        }
      });
    }

    // Tree zurückschreiben
    const outputTreePath = args.output && args.output !== 'gc-plan.json'
      ? resolve(args.output)
      : treeFilePath;
    writeFileSync(outputTreePath, JSON.stringify(treeData, null, 2), 'utf8');
    process.stderr.write(`[gc-execute] Tree aktualisiert: ${replacements} GC-Referenzen auf ${Object.keys(elementGcMap).length} Elemente → ${outputTreePath}\n`);
  }

  process.stderr.write(
    `[gc-execute] ✅ ${created} erstellt, ${skipped} übersprungen, ${failed} fehlgeschlagen\n`
  );
}

// ── --execute (Fix E): Direkte GC-Erstellung via McpBridge ───────────────────
// Ersetzt den alten Plan-Generator. Erstellt Global Classes direkt:
//   1. setup-v4-foundation → bestehende GC-IDs abrufen
//   2. execute-php → neue GCs via wp_insert_post registrieren
//   3. novamira-adrianv2/add-global-class-variant → Varianten setzen
//   4. novamira-adrianv2/apply-variable-to-class → Token-Bindungen
//   5. GC-IDs in v4-tree.json zurückschreiben (kein extra --apply-results nötig)
if (args.execute) {
  // Dynamic import (Top-Level await in ESM)
  let McpBridge;
  try {
    const mod = await import('./lib/mcp-bridge.js');
    McpBridge = mod.McpBridge;
  } catch (e) {
    process.stderr.write(`[gc-execute] ⚠️  McpBridge nicht ladbar (${e.message}), wechsle zu Plan-Modus.\n`);
    writeGcPlan();
    process.exit(0);
  }

  let mcp;
  try {
    mcp = await McpBridge.fromConfig();
    process.stderr.write(`[gc-execute] MCP-Bridge verbunden: ${mcp.mcpUrl}\n`);
  } catch (e) {
    process.stderr.write(`[gc-execute] ⚠️  MCP-Konfiguration fehlgeschlagen: ${e.message}\n`);
    process.stderr.write('[gc-execute] Generiere GC-Plan fuer manuelle Agent-Ausfuehrung...\n');
    writeGcPlan();
    process.exit(0);
  }

  try {
    await executeGcPlan(suggestedClasses, treePath, tree, mcp, tokenMapping);
    process.exit(0);
  } catch (err) {
    process.stderr.write(`[gc-execute] ❌ GC-Execution fehlgeschlagen: ${err.message}\n`);
    process.stderr.write('[gc-execute] Fallback: GC-Plan fuer manuelle Ausfuehrung...\n');
    writeGcPlan();
    process.exit(1);
  }
}

// Standard: Plan-Datei schreiben
const defaultOut = join(treePath, '..', 'global-class-plan.json');
const outPath = args.output ? resolve(args.output) : defaultOut;
writeFileSync(outPath, JSON.stringify(plan, null, 2), 'utf8');
process.stderr.write(`[gen-gc] Plan geschrieben: ${outPath}\n`);
process.stderr.write(`[gen-gc] ${suggestedClasses.length} GC-Vorschläge, ${finalUngrouped.length} ungrouped\n`);

// ── --apply-results: Agent-GC-IDs → Tree zurueckschreiben ────────────────────
// Agent hat gc-results.json erstellt: { "label": "gc-<id>", ... }
// Dieses Script schreibt die IDs als settings.classes in den V4-Tree.
if (args['apply-results']) {
  const { walkTree } = await import('./lib/framer-utils.js');
  const resultsPath = resolve(args['apply-results']);
  const treeInputPath = resolve(args.tree || treePath);

  let gcIdMap;
  try {
    gcIdMap = JSON.parse(readFileSync(resultsPath, 'utf8'));
  } catch (e) {
    process.stderr.write(`[gc-apply] Ungültiges JSON: ${resultsPath}: ${e.message}\n`);
    process.exit(1);
  }

  let treeData;
  try {
    treeData = JSON.parse(readFileSync(treeInputPath, 'utf8'));
  } catch (e) {
    process.stderr.write(`[gc-apply] Tree nicht lesbar: ${treeInputPath}: ${e.message}\n`);
    process.exit(1);
  }

  let replacements = 0;
  walkTree(treeData, node => {
    if (!node.styles || typeof node.styles !== 'object') return;
    for (const styleId of Object.keys(node.styles)) {
      const gcId = gcIdMap[styleId];
      if (!gcId) continue;
      if (!node.settings) node.settings = {};
      if (!node.settings.classes) node.settings.classes = { '$$type': 'classes', value: [] };
      const classes = node.settings.classes.value;
      if (!Array.isArray(classes)) node.settings.classes.value = [gcId];
      else if (!classes.includes(gcId)) classes.push(gcId);
      delete node.styles[styleId];
      replacements++;
    }
  });

  const outputPath = args.output || treeInputPath;
  writeFileSync(resolve(outputPath), JSON.stringify(treeData, null, 2), 'utf8');
  process.stderr.write(
    `[gc-apply] ✅ ${Object.keys(gcIdMap).length} GC-IDs verknuepft, ` +
    `${replacements} Tree-Referenzen ersetzt → ${outputPath}\n`
  );
  process.exit(0);
}

// ── --apply (P1-1): Lokale Tree-Deduplizierung ──────────────────────────────
// Liest gc-plan.json (oder nutzt den gerade analysierten Plan) und
// dedupliziert den Tree: Style-Duplikate werden durch GC-Referenzen ersetzt,
// ungenutzte lokale Styles werden entfernt.
// KEIN MCP-Call — rein lokale JSON-Transformation.
if (args.apply) {
  let gcPlan;
  if (args.plan) {
    const planPath = resolve(args.plan);
    if (!existsSync(planPath)) fatal(`gc-plan.json nicht gefunden: ${planPath}`);
    try {
      gcPlan = JSON.parse(readFileSync(planPath, 'utf8'));
    } catch (e) {
      fatal(`gc-plan.json ungültig: ${e.message}`);
    }
  } else {
    gcPlan = plan; // Nutze den gerade analysierten Plan
  }

  const gcList = gcPlan.suggested_classes || [];
  if (gcList.length === 0) {
    process.stderr.write('[gc-apply] Keine GC-Vorschläge im Plan — Tree bleibt unverändert.\n');
    process.exit(0);
  }

  process.stderr.write(`[gc-apply] Dedupliziere Tree mit ${gcList.length} GC-Vorschlägen...\n`);

  // Baue element_id → [{gcName, category, propKeys}] Map
  // Jedes Element kann mehrere GCs erhalten (typo + structure + background)
  const elementGcMap = {};
  const gcStyleRegistry = {}; // gcName → { type, props } für spätere Tree-Injektion

  for (const gc of gcList) {
    const gcName = gc.name;
    gcStyleRegistry[gcName] = { type: gc.type, props: gc.props || {} };
    for (const elemId of gc.element_ids) {
      if (!elementGcMap[elemId]) elementGcMap[elemId] = [];
      // Vermeide Duplikate: gleiche GC nicht doppelt auf gleiches Element
      if (!elementGcMap[elemId].some(e => e.gcName === gcName)) {
        elementGcMap[elemId].push({
          gcName,
          category: gc.type,
          propKeys: Object.keys(gc.props || {}),
        });
      }
    }
  }

  if (Object.keys(elementGcMap).length === 0) {
    process.stderr.write('[gc-apply] Keine Elemente mit GC-Vorschlägen — Tree bleibt unverändert.\n');
    process.exit(0);
  }

  // Tree laden (ggf. anderen Tree als Analyse-Input)
  const applyTreePath = args.tree ? resolve(args.tree) : treePath;
  let applyTreeData;
  try {
    applyTreeData = JSON.parse(readFileSync(applyTreePath, 'utf8'));
  } catch (e) {
    fatal(`Tree nicht lesbar: ${applyTreePath}: ${e.message}`);
  }

  let gcRefs = 0;
  let stylesRemoved = 0;
  const roots = Array.isArray(applyTreeData) ? applyTreeData : [applyTreeData];

  for (const root of roots) {
    walkTree(root, node => {
      const nodeId = node.id ?? node.settings?.id;
      if (!nodeId || !elementGcMap[nodeId]) return;

      const gcEntries = elementGcMap[nodeId];

      // 1. GC-Referenzen in settings.classes.value[] schreiben
      if (!node.settings) node.settings = {};
      if (!node.settings.classes) {
        node.settings.classes = { '$$type': 'classes', value: [] };
      }
      const classes = node.settings.classes.value;
      if (!Array.isArray(classes)) {
        node.settings.classes.value = [];
      }

      for (const entry of gcEntries) {
        if (!node.settings.classes.value.includes(entry.gcName)) {
          node.settings.classes.value.push(entry.gcName);
          gcRefs++;
        }
      }

      // 2. Entferne lokale Styles die jetzt durch GCs abgedeckt sind
      if (node.styles && typeof node.styles === 'object') {
        const gcPropKeys = new Set(gcEntries.flatMap(e => e.propKeys));
        const localStyleIds = Object.keys(node.styles).filter(sid => !sid.startsWith('gc-'));

        for (const styleId of localStyleIds) {
          const styleDef = node.styles[styleId];
          if (!styleDef || !Array.isArray(styleDef.variants)) continue;

          // Prüfe ob ALLE Props dieses lokalen Styles in einer GC enthalten sind
          const baseVariant = styleDef.variants.find(
            v => v?.meta?.breakpoint === null || v?.meta?.breakpoint === 'desktop'
          );
          if (!baseVariant) continue;

          const localPropKeys = Object.keys(baseVariant.props || {});
          // Entferne den lokalen Style nur wenn ALLE seine Props durch GCs abgedeckt sind
          if (localPropKeys.length > 0 && localPropKeys.every(k => gcPropKeys.has(k))) {
            delete node.styles[styleId];
            stylesRemoved++;
          }
        }
      }
    });
  }

  // Tree zurückschreiben
  const applyOutputPath = args.output
    ? resolve(args.output)
    : (args.tree ? resolve(args.tree) : treePath);
  writeFileSync(applyOutputPath, JSON.stringify(applyTreeData, null, 2), 'utf8');

  process.stderr.write(
    `[gc-apply] ✅ ${gcRefs} GC-Referenzen auf ${Object.keys(elementGcMap).length} Elemente gesetzt, ` +
    `${stylesRemoved} lokale Styles entfernt → ${applyOutputPath}\n`
  );

  const dedupedCount = allElements.length - Object.keys(elementGcMap).length;
  const coveragePct = Math.round((Object.keys(elementGcMap).length / Math.max(allElements.length, 1)) * 100);
  process.stderr.write(
    `[gc-apply] 📊 GC-Coverage: ${coveragePct}% (${Object.keys(elementGcMap).length}/${allElements.length} Elemente)\n`
  );
  process.stderr.write(
    `[gc-apply] 💡 Nächster Schritt: novamira-adrianv2/batch-class um GCs serverseitig zu registrieren\n`
  );

  process.exit(0);
}

// Standard-Modus: nur Plan ausgeben
console.log(JSON.stringify(plan, null, 2));
process.exit(0);
