#!/usr/bin/env node
/**
 * scripts/lib/split-large-tree.js — Phase 1.2 Fix: MCP-Plan-Generator
 *
 * Teilt einen V4-Elementor-Tree in Sections auf und generiert
 * MCP-Execution-Pläne für den Claude-Agenten.
 *
 * ARCHITEKTUR (v0.6.0+):
 *   Kein direktes mcp.call() — stattdessen werden MCP-Pläne (JSON)
 *   generiert, die der Agent ausführt.
 *
 * Usage:
 *   import { splitLargeTree, buildPlan } from './lib/split-large-tree.js';
 *   const sections = splitLargeTree(v4Tree, { maxElementsPerSection: 50 });
 *   const plan = buildPlan(sections, postId);
 *   // Agent führt plan.mcp_calls aus
 */

import { RollbackManager } from './rollback.js';

const DEFAULT_MAX_ELEMENTS = 50;

// Timeout-Fallback: Trees mit >400 Top-Level-Elementen oder >800KB JSON
// werden in Batches aufgeteilt, um Server-Timeouts zu vermeiden.
// Schwellen sind hoch angesetzt — nur echte Extremfälle triggern.
const TIMEOUT_ELEMENT_THRESHOLD = 400;
const TIMEOUT_SIZE_THRESHOLD_KB = 800;

function countElements(tree) {
  if (!Array.isArray(tree)) return 0;
  let n = 0;
  for (const el of tree) {
    n++;
    if (el.elements || el.children) n += countElements(el.elements || el.children);
  }
  return n;
}

function findTopLevelContainers(tree) {
  if (!Array.isArray(tree)) return [];
  return tree.filter(el => {
    const type = el.elType || el.widgetType || '';
    return ['container', 'e-flexbox', 'e-div-block', 'section'].includes(type)
      || (el.elements && el.elements.length > 0)
      || (el.children && el.children.length > 0);
  });
}

export function splitLargeTree(tree, options = {}) {
  const maxEl = options.maxElementsPerSection || DEFAULT_MAX_ELEMENTS;
  const rootArr = Array.isArray(tree) ? tree : [tree];
  const total = countElements(rootArr);

  if (total <= maxEl) {
    return [{
      index: 0,
      label: 'full-page',
      elementCount: total,
      elements: rootArr,
    }];
  }

  process.stderr.write(`[split] Tree has ${total} elements (>${maxEl}), splitting into sections...\n`);

  const containers = findTopLevelContainers(rootArr);
  const sections = [];
  let currentBatch = [];
  let currentCount = 0;
  let sectionIndex = 0;

  const nonContainer = rootArr.filter(el => !containers.includes(el));
  if (nonContainer.length > 0) {
    const nc = countElements(nonContainer);
    sections.push({
      index: sectionIndex++,
      label: 'root-elements',
      elementCount: nc,
      elements: nonContainer,
    });
  }

  for (const container of containers) {
    const containerCount = countElements(container.elements || container.children || [container]);

    if (currentCount + containerCount > maxEl && currentBatch.length > 0) {
      sections.push({
        index: sectionIndex++,
        label: `section-group-${sectionIndex}`,
        elementCount: currentCount,
        elements: [...currentBatch],
      });
      currentBatch = [];
      currentCount = 0;
    }

    currentBatch.push(container);
    currentCount += containerCount;
  }

  if (currentBatch.length > 0) {
    sections.push({
      index: sectionIndex++,
      label: `section-group-${sectionIndex}`,
      elementCount: currentCount,
      elements: currentBatch,
    });
  }

  process.stderr.write(`[split] Split into ${sections.length} sections\n`);
  return sections;
}

/**
 * Baut einen MCP-Execution-Plan für die Sections.
 *
 * Sections werden IM SPEICHER zusammengeführt → 1× elementor-set-content.
 * Der Agent führt den zurückgegebenen Plan aus.
 *
 * @param {Array} sections - Output von splitLargeTree()
 * @param {number} postId - Ziel-Post-ID
 * @param {object} options
 * @param {boolean} options.rollback - Rollback-Plan inkludieren (default true)
 * @returns {object} { plan: { mcp_calls[], agent_instruction, rollback? }, sections }
 */
/**
 * Schätzt die JSON-Größe des Trees in KB (grob, ohne Stringify).
 */
function estimateTreeSizeKb(tree) {
  try {
    const json = JSON.stringify(tree);
    return Math.ceil(json.length / 1024);
  } catch {
    return 9999; // Fallback: als zu groß behandeln
  }
}

export function buildPlan(sections, postId, options = {}) {
  const useRollback = options.rollback !== false;
  const rb = useRollback ? new RollbackManager() : null;

  const totalElements = sections.reduce((sum, s) => sum + s.elementCount, 0);

  // Merge all sections in memory
  const merged = [];
  for (const section of sections) {
    merged.push(...section.elements);
  }

  const mcp_calls = [];

  // Rollback-Backup-Plan (wenn gewünscht)
  if (useRollback) {
    const { plan: backupPlan } = rb.backupPlan(postId);
    if (backupPlan?.mcp_calls) {
      mcp_calls.push(...backupPlan.mcp_calls.map(c => ({
        ...c,
        phase: 'pre-build',
      })));
    }
  }

  // ── Timeout-Fallback: Prüfe ob der Tree zu groß für einen Call ist ──
  const topLevelCount = merged.length;
  const estimatedSizeKb = estimateTreeSizeKb(merged);
  const needsBatching = topLevelCount > TIMEOUT_ELEMENT_THRESHOLD
    || estimatedSizeKb > TIMEOUT_SIZE_THRESHOLD_KB;

  if (needsBatching) {
    process.stderr.write(
      `[split] ⚠️  Tree too large: ${topLevelCount} elements, ~${estimatedSizeKb}KB ` +
      `(thresholds: ${TIMEOUT_ELEMENT_THRESHOLD} elements / ${TIMEOUT_SIZE_THRESHOLD_KB}KB)\n`
    );

    // Batches: ~50 Elemente pro Batch
    // Batch 0 via set-content, Batches 1+ via add-element (gruppiert)
    const batchSize = Math.min(50, Math.ceil(topLevelCount / Math.ceil(topLevelCount / 50)));
    const batches = [];
    for (let i = 0; i < merged.length; i += batchSize) {
      batches.push(merged.slice(i, i + batchSize));
    }

    process.stderr.write(
      `[split] Split into ${batches.length} batches (~${batchSize} elements each)\n`
    );

    // Batch 0: elementor-set-content (initialer Content)
    mcp_calls.push({
      ability: 'novamira/elementor-set-content',
      params: { post_id: postId, content: batches[0] },
      phase: 'build',
      batch: 0,
      description: `Batch 1/${batches.length}: set-content (${batches[0].length} elements)`,
    });

    // Batches 1+: Pro Batch EIN elementor-add-element Call mit dem gesamten Batch-Array.
    // elementor-add-element akzeptiert Arrays von Elementen (wie set-content).
    for (let i = 1; i < batches.length; i++) {
      mcp_calls.push({
        ability: 'novamira/elementor-add-element',
        params: {
          post_id: postId,
          element: batches[i],
          position: 'end',
        },
        phase: 'build',
        batch: i,
        description: `Batch ${i + 1}/${batches.length}: add-element (${batches[i].length} Elemente)`,
      });
    }

    const agent_instruction = [
      `Tree zu groß für 1 Call (${topLevelCount} Elemente, ~${estimatedSizeKb}KB).`,
      `1. Führe elementor-set-content (Batch 0, ${batches[0].length} Elemente).`,
      `2. Führe ${batches.length - 1}× elementor-add-element (Batches 1-${batches.length - 1}, je ~${batchSize} Elemente).`,
      batches.length > 2 ? `   Tipp: add-element Batches können parallel ausgeführt werden.` : '',
    ].filter(Boolean).join('\n');

    return {
      plan: {
        step: 'split-build-batched',
        description: `Baue ${batches.length} Batches auf Post ${postId} (Timeout-Fallback)`,
        mcp_calls,
        agent_instruction,
        batchCount: batches.length,
        rollback: useRollback ? {
          note: 'Bei Build-Fehler: RollbackManager.restorePlan() aufrufen',
        } : null,
      },
      sections,
      totalElements,
      batched: true,
    };
  }

  // ── Normaler Pfad: 1× set-content ──
  const buildCall = {
    ability: 'novamira/elementor-set-content',
    params: { post_id: postId, content: merged },
    phase: 'build',
    description: sections.length === 1
      ? `Baue ${sections[0].elementCount} Elemente in 1 Call`
      : `Baue ${sections.length} Sections (${totalElements} Elemente gesamt) via merge → 1× set-content`,
  };

  mcp_calls.push(buildCall);

  const agent_instruction = sections.length === 1
    ? `Führe elementor-set-content mit ${sections[0].elementCount} Elementen aus.`
    : `Führe elementor-set-content mit dem gemergten Tree (${sections.length} Sections, ${totalElements} Elemente) aus.`;

  process.stderr.write(
    `[split] Build-Plan: ${sections.length} section(s), ${totalElements} elements → 1× set-content\n`
  );

  return {
    plan: {
      step: 'split-build',
      description: `Baue ${sections.length} Section(s) auf Post ${postId}`,
      mcp_calls,
      agent_instruction,
      rollback: useRollback ? {
        note: 'Bei Build-Fehler: RollbackManager.restorePlan() aufrufen',
        backup_plan: rb ? 'RollbackManager.backupPlan() vor Build ausführen' : null,
      } : null,
    },
    sections,
    totalElements,
    batched: false,
  };
}

export { countElements, findTopLevelContainers };

// ── CLI: Plan aus Tree-Datei generieren ──────────────────────────────────────

if (process.argv.includes('--plan')) {
  const treePath = process.argv[process.argv.indexOf('--plan') + 1];
  const postId   = parseInt(process.argv[process.argv.indexOf('--post-id') + 1] || '0', 10);

  if (!treePath || !postId) {
    process.stderr.write('Usage: node scripts/lib/split-large-tree.js --plan <tree.json> --post-id <id>\n');
    process.exit(2);
  }

  import('node:fs').then(({ readFileSync }) => {
    const tree = JSON.parse(readFileSync(treePath, 'utf8'));
    const sections = splitLargeTree(tree);
    const result = buildPlan(sections, postId);
    process.stdout.write(JSON.stringify(result, null, 2) + '\n');
    process.exit(0);
  }).catch(err => {
    process.stderr.write('[split] Error: ' + err.message + '\n');
    process.exit(1);
  });
}
