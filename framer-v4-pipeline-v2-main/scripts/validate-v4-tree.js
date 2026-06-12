#!/usr/bin/env node
/**
 * validate-v4-tree.js
 *
 * Pre-build client validator for Elementor V4 Atomic Widget trees.
 * Runs 7 checks against a V4 element tree JSON file before sending
 * to elementor-set-content.
 *
 * Usage:
 *   node scripts/validate-v4-tree.js <tree.json>
 *   node scripts/validate-v4-tree.js <tree.json> --mode=warn
 *   node scripts/validate-v4-tree.js <tree.json> --schema=path/to/schema.json
 *
 * Exit code: 0 = pass, 1 = blocked (score < 85)
 *
 * The 7 checks (in order of error yield):
 *   1. $$type correctness — Plain values where $$type wrapper required
 *   2. Styles-classes binding — Local style IDs not in settings.classes
 *   3. Hyphen in style IDs       — Invalid style names that break the parser
 *   4. Responsive coverage       — Large values without mobile variant
 *   5. Widget/settings congruence — Wrong required key for widgetType
 *   6. Verbose style format      — Style entries missing id/type/label, null breakpoint, or plain-string custom_css
 *   7. DOM depth                 — Max nesting depth (≤3=OK, 4-5=WARNING, ≥6=ERROR)
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);

// ─── Configuration ─────────────────────────────────────────────────

const PASS_THRESHOLD = 85;
const SCHEMA_PATH_DEFAULT = path.join(__dirname, '..', 'schemas', 'v4-prop-type-schema.json');

// ─── Parse arguments ────────────────────────────────────────────────

const args = process.argv.slice(2);
if (args.length === 0 || args[0] === '--help' || args[0] === '-h') {
  console.log('Usage: node scripts/validate-v4-tree.js <tree.json> [--mode=warn] [--schema=path]');
  console.log('');
  console.log('  tree.json   V4 element tree JSON file');
  console.log('  --mode      strict (default, exit 1 if score < 85%) or warn (always exit 0)');
  console.log('  --schema    Path to prop-type-schema JSON (default: .commandcode/schemas/v4-prop-type-schema.json)');
  console.log('');
  console.log('Runs 7 checks against a V4 element tree before sending to elementor-set-content.');
  process.exit(0);
}

const treePath = args[0];
let mode = 'strict';
let schemaPath = SCHEMA_PATH_DEFAULT;

for (const arg of args.slice(1)) {
  if (arg.startsWith('--mode=')) mode = arg.replace('--mode=', '');
  if (arg.startsWith('--schema=')) schemaPath = arg.replace('--schema=', '');
}

// ─── Load inputs ────────────────────────────────────────────────────

let tree;
try {
  const raw = fs.readFileSync(treePath, 'utf8');
  tree = JSON.parse(raw);
} catch (e) {
  console.error(`FATAL: Cannot read tree file "${treePath}": ${e.message}`);
  process.exit(1);
}

let schema;
try {
  const raw = fs.readFileSync(schemaPath, 'utf8');
  schema = JSON.parse(raw);
} catch (e) {
  console.error(`FATAL: Cannot read schema file "${schemaPath}": ${e.message}`);
  process.exit(1);
}

if (!Array.isArray(tree)) tree = [tree];

// ─── Tree traversal ─────────────────────────────────────────────────

function walkTree(elements, callback, indexPath = '') {
  if (!Array.isArray(elements)) return;
  for (let i = 0; i < elements.length; i++) {
    const el = elements[i];
    if (!el || typeof el !== 'object') continue;
    const currentPath = indexPath ? `${indexPath}.${i}` : String(i);
    callback(el, currentPath);
    const children = el.children || el.elements;
    if (Array.isArray(children)) {
      walkTree(children, callback, currentPath);
    }
  }
}

function getElementType(el) {
  // Unterstuetzt beide Formate:
  // Pipeline (camelCase): widgetType, elType
  // MCP elementor-get-content (snake_case): widget_type, el_type, type
  if (el.elType === 'widget' && el.widgetType) return el.widgetType;
  if (el.el_type === 'widget' && el.widget_type) return el.widget_type;
  if (el.widgetType) return el.widgetType;
  if (el.widget_type) return el.widget_type;
  if (el.type) return el.type;
  return el.elType || el.el_type || 'unknown';
}

function getElementId(el) {
  return el.id || 'unknown';
}

function isContainer(type) {
  return type === 'e-flexbox' || type === 'e-div-block';
}

// ─── Get classes array from settings ─────────────────────────────────

function getClassesArray(el) {
  const settings = el.settings || {};
  const classes = settings.classes;
  if (classes && Array.isArray(classes.value)) return classes.value;
  if (Array.isArray(classes)) return classes;
  return [];
}

// ─── CHECK 1: $$type correctness ────────────────────────────────────

function checkTypeCorrectness(el, path, errors) {
  const propSchema = schema.properties || {};
  const commonErrors = schema.common_errors || [];

  // Check style properties
  if (!el.styles || typeof el.styles !== 'object') return;

  for (const [styleId, styleDef] of Object.entries(el.styles)) {
    if (!styleDef || typeof styleDef !== 'object') continue;
    const variants = styleDef.variants || [];
    for (const variant of variants) {
      const props = variant.props || {};
      for (const [propName, propValue] of Object.entries(props)) {
        if (propValue === null || propValue === undefined) continue;

        const spec = propSchema[propName];
        if (!spec) continue; // Unknown property, skip

        // Special case: custom_css
        if (propName === 'custom_css') {
          if (typeof propValue === 'string') {
            errors.push({
              check: 1, rule: '$$TYPE-CORRECTNESS', elementId: getElementId(el), path,
              styleId, prop: propName,
              message: `custom_css is a plain string — will cause Site-Crash 500. Must be null or {raw: base64_string}.`,
              actual: (propValue || '').substring(0, 60),
              fix: 'Wrap as {raw: base64_encode(string)} or set to null'
            });
          }
          continue;
        }

        // If propValue has no $$type, it might be auto-wrap-compatible
        if (!propValue || typeof propValue !== 'object' || !propValue['$$type']) {
          // Scalars that can be auto-wrapped: string, number, boolean
          const typeStr = typeof propValue === 'number' ? 'number' :
                         typeof propValue === 'boolean' ? 'boolean' :
                         typeof propValue === 'string' ? 'string' : null;
          if (typeStr) {
            // Check if this property expects a type that needs explicit wrapping
            const expected = spec.expected_type;
            if (expected && expected !== typeStr && expected !== 'raw-object') {
              // For colors: bare "#FF0000" auto-wraps to color, OK
              if (expected === 'color' && typeStr === 'string' && propValue.startsWith('#')) {
                continue; // Auto-wraps, OK
              }
              if (expected === 'color' && typeStr === 'string' && propValue.match(/^e-gv-/)) {
                errors.push({
                  check: 1, rule: '$$TYPE-CORRECTNESS', elementId: getElementId(el), path,
                  styleId, prop: propName,
                  message: `${propName}="${propValue}" — looks like a variable ID but missing $type:global-color-variable wrapper. Auto-wrap will NOT detect this.`,
                  actual: propValue,
                  fix: `Use {"$$type":"global-color-variable","value":"${propValue}"}`
                });
                continue;
              }
              if (expected === 'dimensions' || expected === 'background' || expected === 'image-src') {
                // These need explicit typing, but auto-wrap may handle some
                // For dimensions: {block-start: 80, ...} auto-wraps
                if (expected === 'dimensions' && typeof propValue === 'object' && propValue['block-start'] !== undefined) {
                  continue; // Auto-wraps to dimensions
                }
              }
            }
          }
          continue;
        }

        // PropValue has $$type — validate it matches expected
        const actualType = propValue['$$type'];
        const specExpected = spec.expected_type;
        const specAlso = spec.also_accepts || [];

        if (specExpected && actualType !== specExpected && !specAlso.includes(actualType)) {
          // Special: size props accept global-size-variable (listed in also_accepts)
          errors.push({
            check: 1, rule: '$$TYPE-CORRECTNESS', elementId: getElementId(el), path,
            styleId, prop: propName,
            message: `${propName}: expected $$type "${specExpected}" but got "${actualType}"`,
            actual: actualType,
            expected: specAlso.length ? [specExpected, ...specAlso].join('|') : specExpected
          });
        }

        // Deep-check: does the value shape match the type?
        const typeShape = (schema.types || {})[actualType];
        if (typeShape && typeShape.shape) {
          validateTypeShape(propValue, typeShape.shape, propName, path, el, styleId, errors);
        }
      }
    }
  }

  // Check: visual values in container settings (settings ∩ styles violation)
  const elType = getElementType(el);
  if (isContainer(elType)) {
    const settings = el.settings || {};
    const forbiddenInSettings = [
      'color', 'font-size', 'font-family', 'font-weight', 'line-height', 'letter-spacing',
      'padding', 'margin', 'gap', 'width', 'height', 'min-height', 'max-width',
      'background-color', 'background', 'background-overlay',
      'flex-direction', 'align-items', 'justify-content', 'flex-wrap',
      'border-radius', 'border-width', 'border-color', 'border-style',
      'box-shadow', 'opacity', 'position', 'overflow'
    ];
    for (const key of Object.keys(settings)) {
      if (forbiddenInSettings.includes(key)) {
        errors.push({
          check: 1, rule: 'SETTINGS-STYLES-SPLIT', elementId: getElementId(el), path,
          prop: key,
          message: `${key} in settings of ${elType} — visual properties must be in styles, not settings. Invariant III violation.`
        });
      }
    }
  }
}

function validateTypeShape(value, shape, propName, path, el, styleId, errors) {
  if (!shape || typeof shape !== 'object') return;
  const val = value.value || value;
  if (typeof val !== 'object' || val === null) return; // scalar values don't need shape validation

  for (const [key, expected] of Object.entries(shape)) {
    if (key === '$$type') continue;
    if (!(key in val)) {
      // Some keys are optional
      if (key === 'url' && val.id !== undefined) continue; // url optional when id present
      if (key === 'id' && val.url !== undefined) continue; // id optional when url present
      if (key === 'basis') continue; // Optional in flex
      if (key === 'spread') continue; // Optional in box-shadow
      if (key === 'inline-end' || key === 'inline-start') continue; // May be in value sub-object
      continue;
    }

    const actualVal = val[key];
    if (typeof expected === 'string' && !expected.includes('|')) {
      // Check for size-type validation
      if (expected === 'size' || expected === 'color') {
        if (actualVal && typeof actualVal === 'object' && actualVal['$$type'] === expected) {
          continue; // OK
        }
      }
      // For image-src with id
      if (expected === 'image-attachment-id|null' && actualVal !== null && typeof actualVal === 'number') {
        continue;
      }
    }
  }
}

// ─── CHECK 2: Styles-classes binding ────────────────────────────────

function checkStylesClassesBinding(el, path, errors, warnings) {
  if (!el.styles || typeof el.styles !== 'object') return;

  const localStyleIds = [];
  for (const [sid, def] of Object.entries(el.styles)) {
    if (sid.startsWith('gc-')) continue;
    if (typeof def === 'object' && def.variants) localStyleIds.push(sid);
  }

  if (localStyleIds.length === 0) return;

  const classes = getClassesArray(el);
  const unbound = localStyleIds.filter(sid => !classes.includes(sid));

  for (const sid of unbound) {
    errors.push({
      check: 2, rule: 'STYLES-CLASSES-BINDING', elementId: getElementId(el), path,
      styleId: sid,
      message: `Local style "${sid}" defined in styles but NOT in settings.classes.value[] — Invariant I violation. Style will never render.`,
      classes: classes,
      fix: `Add "${sid}" to settings.classes.value array`
    });
  }

  // Also catch: class references that don't exist (orphaned references)
  for (const c of classes) {
    if (c.startsWith('gc-')) continue; // Global classes checked server-side
    if (!el.styles[c]) {
      warnings.push({
        check: 2, rule: 'ORPHANED-CLASS-REFERENCE', elementId: getElementId(el), path,
        classId: c,
        message: `Class "${c}" referenced in settings.classes but not defined in styles.`
      });
    }
  }
}

// ─── CHECK 3: Hyphen in style IDs ───────────────────────────────────

function checkStyleIdHyphen(el, path, errors) {
  if (!el.styles || typeof el.styles !== 'object') return;

  for (const sid of Object.keys(el.styles)) {
    if (sid.startsWith('gc-')) continue; // Global classes have hyphens by design
    if (!/^[a-z][a-z0-9_]*$/i.test(sid)) {
      const issue = sid.includes('-') ? 'contains hyphen (forbidden)' : 'invalid characters';
      errors.push({
        check: 3, rule: 'STYLE-ID-HYPHEN', elementId: getElementId(el), path,
        styleId: sid,
        message: `Style ID "${sid}" ${issue} — only [a-z0-9_]+ allowed. Hyphens break the parser suffix system.`
      });
    }
  }
}

// ─── CHECK 4: Responsive coverage ───────────────────────────────────

function checkResponsiveCoverage(el, path, warnings) {
  if (!el.styles || typeof el.styles !== 'object') return;

  const rules = (schema.responsive_rules || {}).mandatory_mobile_if_oversize || {};

  for (const [styleId, styleDef] of Object.entries(el.styles)) {
    if (styleId.startsWith('gc-')) continue;
    if (!styleDef || !Array.isArray(styleDef.variants)) continue;

    const desktopVariant = styleDef.variants.find(v => {
      const bp = (v.meta && v.meta.breakpoint) || '';
      const st = (v.meta && v.meta.state) || null;
      return (bp === 'desktop' || bp === '') && (!st || st === null);
    });

    const hasMobile = styleDef.variants.some(v => {
      const bp = (v.meta && v.meta.breakpoint) || '';
      return bp === 'mobile';
    });

    if (!desktopVariant) continue;
    const props = desktopVariant.props || {};

    // Check font-size oversize
    if (props['font-size']) {
      const fs = props['font-size'];
      const size = (fs.value && fs.value.size !== undefined) ? fs.value.size : null;
      if (size && Number(size) > (rules['font-size'] && rules['font-size'].threshold_px || 28)) {
        if (!hasMobile) {
          warnings.push({
            check: 4, rule: 'RESPONSIVE-COVERAGE', elementId: getElementId(el), path,
            styleId, prop: 'font-size',
            message: `font-size: ${size}px on desktop but no mobile variant. Browser keeps this value — text will overflow on 375px viewport.`
          });
        }
      }
    }

    // Check min-height oversize
    if (props['min-height']) {
      const mh = props['min-height'];
      const mhSize = (mh.value && mh.value.size !== undefined) ? mh.value.size : null;
      if (mhSize && Number(mhSize) > (rules['min-height'] && rules['min-height'].threshold_px || 200)) {
        if (!hasMobile) {
          warnings.push({
            check: 4, rule: 'RESPONSIVE-COVERAGE', elementId: getElementId(el), path,
            styleId, prop: 'min-height',
            message: `min-height: ${mhSize}px on desktop but no mobile variant. Creates empty space on 375px viewport.`
          });
        }
      }
    }

    // Check padding oversize (horizontal)
    if (props.padding) {
      const pad = props.padding;
      const padVal = pad.value || pad;
      const inlineMax = Math.max(
        (padVal['inline-start'] && padVal['inline-start'].value && padVal['inline-start'].value.size) || 0,
        (padVal['inline-end'] && padVal['inline-end'].value && padVal['inline-end'].value.size) || 0
      );
      if (inlineMax > (rules['padding_inline'] && rules['padding_inline'].threshold_px || 20)) {
        if (!hasMobile) {
          warnings.push({
            check: 4, rule: 'RESPONSIVE-COVERAGE', elementId: getElementId(el), path,
            styleId, prop: 'padding',
            message: `Horizontal padding ${inlineMax}px on desktop but no mobile variant. Eats ${inlineMax * 2}px from 375px viewport.`
          });
        }
      }
    }

    // Check flex-direction: row without mobile column
    if (props['flex-direction']) {
      const fd = props['flex-direction'].value || props['flex-direction'];
      if (fd === 'row') {
        if (!hasMobile) {
          warnings.push({
            check: 4, rule: 'RESPONSIVE-COVERAGE', elementId: getElementId(el), path,
            styleId, prop: 'flex-direction',
            message: 'flex-direction: row on desktop but no mobile variant. Children will be squashed on narrow viewports. Add a mobile variant with column.'
          });
        }
      }
    }

    // Check width as fixed px
    if (props.width) {
      const w = props.width;
      const wSize = (w.value && w.value.size !== undefined) ? w.value.size : null;
      const wUnit = (w.value && w.value.unit !== undefined) ? w.value.unit : null;
      if (wSize && wUnit === 'px' && Number(wSize) > 100 && !hasMobile) {
        warnings.push({
          check: 4, rule: 'RESPONSIVE-COVERAGE', elementId: getElementId(el), path,
          styleId, prop: 'width',
          message: `width: ${wSize}px on desktop but no mobile variant. Fixed-width container overflows 375px viewport.`
        });
      }
    }
  }
}

// ─── CHECK 5: Widget/settings congruence ────────────────────────────

function checkWidgetSettings(el, path, errors) {
  const elType = getElementType(el);
  const widgetReqs = (schema.widget_requirements || {})[elType];
  if (!widgetReqs) return;

  const settings = el.settings || {};

  // Check required settings
  for (const reqKey of widgetReqs.required || []) {
    if (elType === 'e-button' && reqKey === 'title' && (settings.title || settings.text)) continue;
    if (!settings[reqKey]) {
      errors.push({
        check: 5, rule: 'WIDGET-SETTINGS', elementId: getElementId(el), path,
        widgetType: elType,
        message: `${elType} missing required setting "${reqKey}".`
      });
    }
  }

  // Check forbidden content
  if (widgetReqs.forbidden_content) {
    if (widgetReqs.forbidden_content.includes('<p>')) {
      const paragraph = settings.paragraph;
      if (paragraph && paragraph.value && paragraph.value.content) {
        const content = paragraph.value.content.value || paragraph.value.content;
        if (typeof content === 'string' && /<p[>\s]/i.test(content)) {
          errors.push({
            check: 5, rule: 'P-IN-PARAGRAPH', elementId: getElementId(el), path,
            message: 'e-paragraph contains <p> tags — only inline elements allowed. Nesting <p> in <p> breaks HTML rendering.'
          });
        }
      }
    }
  }
}

// ─── CHECK 6: Verbose style format ──────────────────────────────────

/**
 * Validates that every per-element style entry (non-gc- prefix) uses the
 * VERBOSE format required by elementor-set-content:
 *   {id: "<styleId>", type: "class", label: "local", variants: [...]}
 *
 * Catches three classes of bug seen in production:
 *   a) ERGONOMIC format leakage — `$$type` at style level instead of `type: "class"`
 *   b) Missing id/type/label — server rejects the whole subtree
 *   c) Plain-string custom_css — crashes Elementor renderer
 */
function checkVerboseStyleFormat(el, path, errors) {
  if (!el.styles || typeof el.styles !== 'object') return;

  for (const [styleId, styleDef] of Object.entries(el.styles)) {
    if (styleId.startsWith('gc-')) continue; // Global classes are server-validated
    if (!styleDef || typeof styleDef !== 'object') continue;

    const missing = [];

    // (a) ERGONOMIC format detection: $$type at top level = old format
    if (styleDef['$$type'] !== undefined) {
      missing.push(`has $$type:"${styleDef['$$type']}" at style level — old ERGONOMIC format. Replace with type:"class".`);
    }

    // (b) Required VERBOSE fields
    if (!styleDef.id) missing.push('missing "id" field');
    else if (styleDef.id !== styleId) missing.push(`id "${styleDef.id}" does not match style key "${styleId}"`);

    if (!styleDef.type) missing.push('missing "type" field (should be "class")');
    else if (styleDef.type !== 'class') missing.push(`type is "${styleDef.type}" but must be "class" for per-element styles`);

    if (styleDef.label === undefined) missing.push('missing "label" field (should be "local")');

    // Variant-level checks
    const variants = styleDef.variants;
    if (!Array.isArray(variants)) {
      missing.push('variants is not an array');
    } else {
      for (let vi = 0; vi < variants.length; vi++) {
        const v = variants[vi];
        if (!v || typeof v !== 'object') continue;
        const vpath = `variants[${vi}]`;

        // meta.breakpoint must not be null (server rejects)
        if (!v.meta || v.meta.breakpoint === null || v.meta.breakpoint === undefined) {
          missing.push(`${vpath}.meta.breakpoint is ${v.meta?.breakpoint ?? 'undefined'} — must be "desktop" or a named breakpoint`);
        }

        // meta.state must be present (PHP auto-fills, but missing it is a format gap)
        if (v.meta && !('state' in v.meta)) {
          missing.push(`${vpath}.meta.state is missing — set to null`);
        }

        // (c) custom_css must be null or {raw: "<base64>"}, never a plain string
        if (typeof v.custom_css === 'string') {
          missing.push(`${vpath}.custom_css is a plain string — will crash Elementor renderer. Must be null or {raw: base64_string}.`);
        }
        if (v.custom_css === undefined) {
          missing.push(`${vpath} missing "custom_css" field (set to null)`);
        }
      }
    }

    if (missing.length > 0) {
      errors.push({
        check: 6,
        rule: 'VERBOSE-STYLE-FORMAT',
        elementId: getElementId(el),
        path,
        styleId,
        message: `Style "${styleId}" has ${missing.length} format issue(s): ${missing.join('; ')}`,
        issues: missing,
        fix: 'Use VERBOSE format: {id: "<styleId>", type: "class", label: "local", variants: [{meta: {breakpoint: "desktop", state: null}, props: {...}, custom_css: null}]}'
      });
    }
  }
}

// ─── CHECK 7: DOM Depth ───────────────────────────────────────────

/**
 * P0-2 Fix: Validates maximum DOM nesting depth.
 * Depth ≤ 3: OK (optimal for V4 Atomic)
 * Depth 4-5: WARNING (performance degradation, consider Grid instead of Flex nesting)
 * Depth ≥ 6: ERROR (server timeout risk, exponential reflow cost)
 */
function checkDomDepth(elements, errors, warnings) {
  let maxDepth = 0;
  let deepestPath = '';
  let deepestEl = null; // Cache element ref during traversal (avoids fragile re-traversal)

  function walk(el, depth, indexPath) {
    if (!el || typeof el !== 'object') return;
    if (depth > maxDepth) {
      maxDepth = depth;
      deepestPath = indexPath;
      deepestEl = el;
    }
    // Use same child accessor priority as walkTree: children || elements
    const children = el.children || el.elements || [];
    for (let i = 0; i < children.length; i++) {
      const cpath = indexPath ? `${indexPath}.${i}` : String(i);
      walk(children[i], depth + 1, cpath);
    }
  }

  const roots = Array.isArray(elements) ? elements : [elements];
  for (let i = 0; i < roots.length; i++) {
    walk(roots[i], 1, String(i));
  }

  // Use getElementType helper for consistent type detection (works for both camelCase and snake_case)
  const elType = deepestEl ? (deepestEl.id || getElementType(deepestEl) || 'unknown') : 'root';

  if (maxDepth >= 6) {
    errors.push({
      check: 7, rule: 'DOM-DEPTH', elementId: elType, path: deepestPath,
      maxDepth,
      message: `DOM depth ${maxDepth} >= 6 — server timeout risk and exponential reflow cost. Use CSS Grid (e-div-block) to reduce nesting.`,
      fix: 'Replace deeply nested Flex containers with a single e-div-block using display:grid.'
    });
  } else if (maxDepth >= 4) {
    warnings.push({
      check: 7, rule: 'DOM-DEPTH', elementId: elType, path: deepestPath,
      maxDepth,
      message: `DOM depth ${maxDepth} >= 4 — performance degradation. Consider flattening with Grid.`,
      fix: 'Review nested containers and use Grid where possible to reduce depth to ≤3.'
    });
  }
}

// ─── Check: Hardcoded hex (collected as warnings) ───────────────────

function checkHardcodedHex(el, path, warnings) {
  if (!el.styles || typeof el.styles !== 'object') return;

  for (const [styleId, styleDef] of Object.entries(el.styles)) {
    if (!styleDef || !Array.isArray(styleDef.variants)) continue;
    for (const variant of styleDef.variants) {
      const props = variant.props || {};
      scanForHardcodedHex(props, path, el, styleId, warnings);
    }
  }
}

function scanForHardcodedHex(obj, path, el, styleId, warnings, keyPath = '') {
  if (!obj || typeof obj !== 'object') return;
  for (const [key, val] of Object.entries(obj)) {
    if (['color', 'background-color', 'border-color'].includes(key)) {
      if (val && val['$$type'] === 'color' && typeof val.value === 'string' && /^#[0-9A-Fa-f]{3,8}$/.test(val.value)) {
        warnings.push({
          check: 'placebo', rule: 'HARDCODED-HEX', elementId: getElementId(el), path,
          styleId, prop: key,
          message: `${key}: ${val.value} is hardcoded. Use global-color-variable reference instead.`
        });
      }
    }
    if (typeof val === 'object' && val !== null && !val['$$type']) {
      scanForHardcodedHex(val, path, el, styleId, warnings, keyPath ? `${keyPath}.${key}` : key);
    }
  }
}

// ─── Main validation ────────────────────────────────────────────────

function validate() {
  const errors = [];
  const warnings = [];

  walkTree(tree, (el, path) => {
    const elType = getElementType(el);

    // Run all checks
    checkTypeCorrectness(el, path, errors);
    checkStylesClassesBinding(el, path, errors, warnings);
    checkStyleIdHyphen(el, path, errors);
    checkResponsiveCoverage(el, path, warnings);
    checkWidgetSettings(el, path, errors);
    checkVerboseStyleFormat(el, path, errors);
    checkHardcodedHex(el, path, warnings);
  });

  // Check 7: DOM Depth (tree-level, runs once)
  checkDomDepth(tree, errors, warnings);

  // Scoring: 7 vital checks, each ~14.3%
  // Checks 1-7 are "vital" (check 7 is DOM depth)
  const checkErrorCounts = {};
  const checkWarnCounts = {};
  for (const e of errors) {
    const ck = e.check === 'placebo' ? 'placebo' : `C${e.check}`;
    checkErrorCounts[ck] = (checkErrorCounts[ck] || 0) + 1;
  }
  for (const w of warnings) {
    const ck = w.check === 'placebo' ? 'placebo' : `C${w.check}`;
    checkWarnCounts[ck] = (checkWarnCounts[ck] || 0) + 1;
  }

  // Score: each of the 7 vital checks passes if it has 0 errors
  const vitalPassed = [1, 2, 3, 4, 5, 6, 7].filter(ck => !checkErrorCounts[`C${ck}`]).length;
  const score = Math.round((vitalPassed / 7) * 100);
  const passed = score >= PASS_THRESHOLD;
  const blocked = mode === 'strict' && !passed;

  const totalErrors = errors.length;
  const totalWarnings = warnings.length;

  // Build output
  const result = {
    passed,
    score,
    threshold: PASS_THRESHOLD,
    blocked,
    mode,
    treePath,
    schemaPath,
    summary: passed
      ? `PASSED: Score ${score}% >= ${PASS_THRESHOLD}%. ${totalErrors} errors, ${totalWarnings} warnings.`
      : `BLOCKED: Score ${score}% < ${PASS_THRESHOLD}%. ${totalErrors} errors, ${totalWarnings} warnings across 7 checks.`,
    stats: {
      totalElements: countElements(tree),
      totalErrors,
      totalWarnings,
      errorsByCheck: checkErrorCounts,
      warningsByCheck: checkWarnCounts
    },
    errors: errors.slice(0, 100),
    warnings: warnings.slice(0, 100)
  };

  // Add check summaries
  const checkNames = {
    C1: { name: '$$TYPE-CORRECTNESS', vital: true, weight: 14 },
    C2: { name: 'STYLES-CLASSES-BINDING', vital: true, weight: 14 },
    C3: { name: 'STYLE-ID-HYPHEN', vital: true, weight: 14 },
    C4: { name: 'RESPONSIVE-COVERAGE', vital: true, weight: 14 },
    C5: { name: 'WIDGET-SETTINGS', vital: true, weight: 14 },
    C6: { name: 'VERBOSE-STYLE-FORMAT', vital: true, weight: 14 },
    C7: { name: 'DOM-DEPTH', vital: true, weight: 14 },
    placebo: { name: 'HARDCODED-HEX', vital: false, weight: 0 }
  };

  result.checks = Object.entries(checkNames).map(([ck, info]) => {
    const errs = checkErrorCounts[ck] || 0;
    const warns = checkWarnCounts[ck] || 0;
    const ckPassed = errs === 0;
    return {
      check: ck,
      name: info.name,
      passed: ckPassed,
      vital: info.vital,
      errors: errs,
      warnings: warns,
      status: ckPassed ? '✅' : '❌'
    };
  });

  console.log(JSON.stringify(result, null, 2));
  return blocked ? 1 : 0;
}

function countElements(elements) {
  let count = 0;
  walkTree(elements, () => { count++; });
  return count;
}

// ─── Run ────────────────────────────────────────────────────────────

try {
  const exitCode = validate();
  process.exit(exitCode);
} catch (e) {
  console.error(`FATAL: Validation crashed: ${e.message}`);
  console.error(e.stack);
  process.exit(1);
}
