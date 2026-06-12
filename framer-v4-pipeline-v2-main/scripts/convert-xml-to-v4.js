#!/usr/bin/env node
/**
 * convert-xml-to-v4.js  —  Phase 2: Framer XML → Elementor V4 Widget-Tree
 * Konvertiert Framer getNodeXml() Output direkt in V4 JSON.
 *
 * Usage:
 *   node scripts/convert-xml-to-v4.js \
 *     --xml      FramerExport/hero-section.xml \
 *     --tokens   FramerExport/tokens/token-mapping.json \
 *     --fonts    FramerExport/tokens/font-resolution.json \
 *     --image-map FramerExport/assets/image-map.json \
 *     --output   FramerExport/v4-tree/hero-section.json
 */

import fs   from 'node:fs';
import os   from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseArgs } from 'node:util';
import { spawnSync } from 'node:child_process';

const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);
import {
  normalizeHex, resolveCssVar, generateStyleId,
  wrapSize, wrapUnitless, wrapDimensions, wrapBorderRadius, wrapGvColor, wrapGvFont,
  wrapColor, wrapType, wrapImageSrc, isDimensionValue, wrapImage,
} from './lib/framer-utils.js';

// ─────────────────────────────────────────────
// CLI
// ─────────────────────────────────────────────

const { values: args } = parseArgs({
  options: {
    xml:            { type: 'string' },
    'xml-string':   { type: 'string' },
    tokens:         { type: 'string' },
    fonts:          { type: 'string' },
    'image-map':    { type: 'string' },
    output:         { type: 'string' },
    validate:       { type: 'boolean', default: false },
    verbose:        { type: 'boolean', default: false },
    'gc':           { type: 'boolean', default: true },
    'no-gc':        { type: 'boolean', default: false },
    'gc-output':    { type: 'string' },
    'gc-min-dups':  { type: 'string', default: '2' },
    'tokens-report': { type: 'boolean', default: false },
  },
  strict: false,
});

// P0-1 Fix: --no-gc overrides gc default (true)
if (args['no-gc']) args.gc = false;

// Help
if (process.argv.includes('--help') || process.argv.includes('-h')) { console.log('Usage: node scripts/convert-xml-to-v4.js [--help for options]'); console.log('Run with --help for full usage.'); process.exit(0); }

const log  = (...m) => { if (args.verbose) process.stderr.write('[verbose] ' + m.join(' ') + '\n'); };
const warn = (m)    => process.stderr.write(`⚠ ${m}\n`);

if (!args.xml && !args['xml-string']) {
  process.stderr.write('Error: --xml oder --xml-string erforderlich\n'); process.exit(2);
}

// ─────────────────────────────────────────────
// XML TOKENIZER  (character-by-character, handles quoted values)
// ─────────────────────────────────────────────

function tokenizeXml(xml) {
  const tokens = [];
  let i = 0;

  while (i < xml.length) {
    // Collect text content between tags
    if (xml[i] !== '<') {
      const textStart = i;
      while (i < xml.length && xml[i] !== '<') i++;
      const text = xml.slice(textStart, i).replace(/\s+/g, ' ').trim();
      if (text) tokens.push({ type: 'text', value: text });
      continue;
    }

    // XML declaration
    if (xml.startsWith('<?', i)) {
      const end = xml.indexOf('?>', i); i = end >= 0 ? end + 2 : xml.length; continue;
    }
    // Comment
    if (xml.startsWith('<!--', i)) {
      const end = xml.indexOf('-->', i); i = end >= 0 ? end + 3 : xml.length; continue;
    }
    // CDATA — treat as text
    if (xml.startsWith('<![CDATA[', i)) {
      const end = xml.indexOf(']]>', i);
      const cdata = end >= 0 ? xml.slice(i + 9, end) : '';
      if (cdata.trim()) tokens.push({ type: 'text', value: cdata.trim() });
      i = end >= 0 ? end + 3 : xml.length; continue;
    }

    i++; // skip <

    // Closing tag?
    const isClose = i < xml.length && xml[i] === '/';
    if (isClose) i++;

    // Read tag name
    const nameStart = i;
    while (i < xml.length && /[A-Za-z0-9_:-]/.test(xml[i])) i++;
    const tagName = xml.slice(nameStart, i);
    if (!tagName) { i++; continue; }

    if (isClose) {
      while (i < xml.length && xml[i] !== '>') i++;
      i++; // skip >
      tokens.push({ type: 'close', tagName });
      continue;
    }

    // Read attributes
    const attrs = {};
    while (i < xml.length) {
      // Skip whitespace
      while (i < xml.length && /[\s\r\n]/.test(xml[i])) i++;
      if (i >= xml.length || xml[i] === '>' || (xml[i] === '/' && xml[i+1] === '>')) break;
      // Skip HTML comments inside tags (Bug 8: Framer XML embeds <!-- --> between attrs)
      if (xml.startsWith('<!--', i)) {
        const end = xml.indexOf('-->', i);
        i = end >= 0 ? end + 3 : xml.length;
        continue;
      }

      // Attr name
      const attrStart = i;
      while (i < xml.length && xml[i] !== '=' && xml[i] !== '>' && !/[\s\r\n]/.test(xml[i])) i++;
      const attrName = xml.slice(attrStart, i).trim();

      if (xml[i] === '=') {
        i++; // skip =
        if (i < xml.length && (xml[i] === '"' || xml[i] === "'")) {
          const q = xml[i]; i++;
          const valStart = i;
          while (i < xml.length && xml[i] !== q) i++;
          if (attrName) attrs[attrName] = xml.slice(valStart, i);
          i++; // skip closing quote
        }
      } else if (attrName) {
        attrs[attrName] = 'true';
      }
    }

    const isSelfClose = i < xml.length && xml[i] === '/';
    if (isSelfClose) i++;             // skip /
    if (i < xml.length && xml[i] === '>') i++; // skip >

    tokens.push({ type: isSelfClose ? 'selfclose' : 'open', tagName, attrs });
  }

  return tokens;
}

function buildTree(tokens) {
  const root = { tagName: '_root', attrs: {}, children: [] };
  const stack = [root];
  let pendingText = '';
  for (const tok of tokens) {
    if (tok.type === 'text') {
      // Accumulate text content between tags
      pendingText += tok.value;
    } else if (tok.type === 'close') {
      // Attach accumulated text to the element being closed
      if (pendingText.trim() && stack.length > 1) {
        stack[stack.length - 1]._textContent = (stack[stack.length - 1]._textContent || '') + pendingText.trim();
      }
      pendingText = '';
      if (stack.length > 1) stack.pop();
    } else {
      pendingText = '';
      const node = { tagName: tok.tagName, attrs: tok.attrs, children: [] };
      stack[stack.length - 1].children.push(node);
      if (tok.type === 'open') stack.push(node);
    }
  }
  return root.children;
}

// ─────────────────────────────────────────────
// WIDGET TYPE DETERMINATION
// ─────────────────────────────────────────────

// Native SVG tag names — these map directly to e-svg regardless of parent
const SVG_NATIVE_TAGS = new Set([
  'svg', 'circle', 'ellipse', 'rect', 'path', 'polygon', 'polyline',
  'line', 'g', 'defs', 'use', 'symbol', 'text', 'tspan', 'mask',
  'clippath', 'lineargradient', 'radialgradient', 'stop', 'pattern',
]);

// Framer Component Name → V4 Widget Type Mapping (RC-16 Fix)
// Explicitly maps known Framer component patterns to corresponding V4 atomic widgets.
// Falls through to heuristic detection if no match found.
// NOTE: 'svg' and 'icon' are NOT mapped here — SVG detection is handled by
// SVG_NATIVE_TAGS check below (avoids false positives on containers named "Icons").
const COMPONENT_TYPE_MAP = {
  'heading': 'e-heading',
  'paragraph': 'e-paragraph',
  'button': 'e-button',
  'cta': 'e-button',
  'image': 'e-image',
  'img': 'e-image',
  'divider': 'e-divider',
  'card': 'e-flexbox',
  'stats': 'e-flexbox',
  'testimonial': 'e-flexbox',
  'hero': 'e-flexbox',
  'section': 'e-flexbox',
};

function determineWidgetType(attrs, xmlNode) {
  const name    = (attrs.name || '').toLowerCase();
  const tagName = (xmlNode?.tagName || '').toLowerCase();

  // ── Explicit Component Name Mapping (RC-16 Fix) ──
  // Check if the Framer component name maps directly to a V4 widget type.
  // Falls through to heuristic if the map entry's guard condition isn't met.
  for (const [pattern, widgetType] of Object.entries(COMPONENT_TYPE_MAP)) {
    if (name === pattern || name.includes(pattern)) {
      // Guard: button entry only applies when href is present.
      // Use break (not continue) so guarded-out matches fall through to the existing heuristic.
      if (widgetType === 'e-button' && !attrs.href && name !== 'button' && name !== 'cta') break;
      // Guard: image entry only applies when image source is present
      if (widgetType === 'e-image' && !attrs.backgroundImage && !attrs.src) break;
      return widgetType;
    }
  }

  // ── SVG: ONLY when the tag itself is a native SVG element ──
  // Framer uses PascalCase tags (Frame, Text, Image, Stack) — SVG uses lowercase.
  // We check the ORIGINAL (non-lowercased) tagName to avoid matching Framer's
  // <Text> element against SVG's <text> element.
  const rawTagName = (xmlNode?.tagName || '');
  if (SVG_NATIVE_TAGS.has(rawTagName.toLowerCase()) && rawTagName === rawTagName.toLowerCase()) {
    return 'e-svg';
  }

  if (attrs.href || name.includes('button') || name.includes('cta')) return 'e-button';

  // Text detection: attribute OR child-text (Bug 1 Fix)
  const hasText = attrs.text !== undefined || xmlNode?._textContent;
  if (hasText) {
    if (/\bh[1-6]\b|heading/.test(name)) return 'e-heading';
    if (/\bbody|paragraph|text|description|content/.test(name)) return 'e-paragraph';
    return 'e-heading'; // default for text nodes
  }
  if (attrs.backgroundImage || attrs.src) return 'e-image';

  // RC-09 Grid Detection: multi-child containers with grid-like naming patterns
  // or explicit grid attributes should use e-div-block with display:grid.
  // This enables proper 2D layouts (cards, stats, galleries) instead of
  // forcing everything into nested flexboxes.
  const childCount = (xmlNode?.children || []).filter(c => c.tagName && c.tagName !== '_root').length;
  if (childCount >= 2) {
    if (/\b(grid|gallery|cards|stats|features|logos|columns)\b/.test(name)) {
      return 'e-div-block';
    }
    // Detect repeated child patterns (2+ children with same or similar names)
    // which suggests a grid/card layout rather than sequential flexbox
    const childNames = (xmlNode.children || [])
      .filter(c => c.tagName && c.tagName !== '_root')
      .map(c => (c.attrs?.name || '').toLowerCase().replace(/\d+$/, ''))
      .filter(n => n);
    const uniqueNames = new Set(childNames);
    // If children share a naming pattern (e.g., "card-1", "card-2", "card-3"),
    // this is likely a grid layout. 3+ children with 2 or fewer unique base names
    // strongly suggests a repeated pattern.
    if (childCount >= 3 && uniqueNames.size <= 2) {
      return 'e-div-block';
    }
  }

  return 'e-flexbox'; // default container
}

function determineHtmlTag(attrs) {
  const name = (attrs.name || '').toLowerCase();
  if (/\bh1\b|heading.?1|title/.test(name))   return 'h1';
  if (/\bh2\b|heading.?2/.test(name))          return 'h2';
  if (/\bh3\b|heading.?3/.test(name))          return 'h3';
  if (/\bh4\b|heading.?4/.test(name))          return 'h4';
  if (/\bh5\b|heading.?5/.test(name))          return 'h5';
  if (/\bh6\b|heading.?6/.test(name))          return 'h6';
  if (/paragraph|body|text/.test(name))         return 'p';
  return 'h2'; // default heading
}

function wrapHtmlContent(content) {
  return {
    '$$type': 'html-v3',
    value: { content: { '$$type': 'string', value: content || '' } },
  };
}

function wrapLink(href, targetBlank = false) {
  // Elementor V4 nativer Link-Prop: 'destination' + 'tag', NICHT 'href'
  // EMCP class-atomic-props.php link() Methode bestaetigt dieses Format
  const value = {
    destination: { '$$type': 'url', value: href || '' },
    tag:         { '$$type': 'string', value: 'a' },
  };
  if (targetBlank) value.isTargetBlank = { '$$type': 'boolean', value: true };
  return { '$$type': 'link', value };
}

// Bug 6 Fix: serialize an XML node back to SVG markup for e-svg content
function serializeSvgNode(xmlNode) {
  const { tagName, attrs, children } = xmlNode;
  if (!tagName || tagName === '_root') return '';
  const attrStr = Object.entries(attrs || {})
    .filter(([k]) => k !== 'name' && k !== 'nodeId')
    .map(([k, v]) => `${k}="${String(v).replace(/"/g, '&quot;')}"`)
    .join(' ');
  const childContent = (children || []).map(serializeSvgNode).join('');
  if (childContent || tagName.toLowerCase() !== 'circle') {
    return `<${tagName}${attrStr ? ' ' + attrStr : ''}>${childContent}</${tagName}>`;
  }
  return `<${tagName}${attrStr ? ' ' + attrStr : ''}/>`;
}

// ─────────────────────────────────────────────
// COLOR RESOLUTION
// ─────────────────────────────────────────────

const warnings = [];

function resolveColor(value, tokenMapping) {
  if (!value) return null;
  const resolved = resolveCssVar(value, tokenMapping);
  if (!resolved) {
    const hex = normalizeHex(value);
    if (hex) { warn(`Hardcoded hex used: ${hex} (no token match)`); return wrapColor(hex); }
    return null;
  }
  if (resolved.gvId) return wrapGvColor(resolved.gvId);
  if (resolved.hex)  {
    warn(`Token found but no gv_id for value: ${value} → ${resolved.hex}`);
    return wrapColor(resolved.hex);
  }
  return null;
}

// ─────────────────────────────────────────────
// FONT RESOLUTION
// ─────────────────────────────────────────────

function resolveFont(family, tokenMapping, fontResolution) {
  if (!family) return null;
  // Try token mapping first
  if (tokenMapping?.fonts?.[family]?.gv_id) return wrapGvFont(tokenMapping.fonts[family].gv_id);
  if (typeof tokenMapping?.fonts?.[family] === 'string') return wrapGvFont(tokenMapping.fonts[family]);
  if (typeof tokenMapping?.[family] === 'string' && tokenMapping[family].startsWith('e-gv-')) return wrapGvFont(tokenMapping[family]);
  if (typeof tokenMapping?.[family.toLowerCase?.()] === 'string' && tokenMapping[family.toLowerCase()].startsWith('e-gv-')) {
    return wrapGvFont(tokenMapping[family.toLowerCase()]);
  }
  // Try font resolution
  const fontEntry = (fontResolution?.fonts || []).find(f => f.family === family);
  if (fontEntry?.gv_id) return wrapGvFont(fontEntry.gv_id);
  warn(`Font '${family}' not found in token-mapping or font-resolution. Using string fallback.`);
  return wrapType('string', family);
}

// ─────────────────────────────────────────────
// IMAGE URL RESOLUTION
// ─────────────────────────────────────────────

function extractImageUrl(imageAttr) {
  if (!imageAttr) return null;
  const raw = String(imageAttr).trim();
  const urlMatch = raw.match(/url\(['"]?([^'")\s]+)['"]?\)/i);
  return urlMatch ? urlMatch[1] : raw;
}

function findImageMapEntry(url, imageMap) {
  if (!url || !imageMap) return null;
  const filename = url.split('/').pop().split('?')[0];
  if (imageMap[url]) return imageMap[url];
  if (imageMap.images?.[filename]) return imageMap.images[filename];
  if (imageMap.videos?.[filename]) return imageMap.videos[filename];
  if (Array.isArray(imageMap.assets)) {
    return imageMap.assets.find(a => a.url === url || a.filename === filename) || null;
  }
  if (Array.isArray(imageMap.images)) {
    return imageMap.images.find(a => a.url === url || a.filename === filename) || null;
  }
  return null;
}

function resolveImageSrc(bgImageAttr, imageMap) {
  if (!bgImageAttr) return null;
  const url = extractImageUrl(bgImageAttr);
  if (!url) return null;

  // Try to find in image-map
  const entry = findImageMapEntry(url, imageMap);
  if (entry?.wp_media_id) return wrapImageSrc({ id: entry.wp_media_id });
  if (entry?.id) return wrapImageSrc({ id: entry.id });

  return wrapImageSrc({ url });
}


function resolveLineHeight(lineHeight) {
  if (!lineHeight) return null;
  const raw = String(lineHeight).trim();
  if (/^-?[\d.]+$/.test(raw)) return wrapUnitless(raw);
  if (/^-?[\d.]+%$/.test(raw)) return wrapUnitless(parseFloat(raw) / 100);
  return wrapSize(raw);
}

// ─────────────────────────────────────────────
// PROPERTY MAPPER
// ─────────────────────────────────────────────

// RC-09 Helper: determines grid-template-columns value from attrs + child structure
function detectGridLayout(xmlNode, attrs) {
  const childCount = (xmlNode?.children || []).filter(c => c.tagName && c.tagName !== '_root').length;
  if (childCount < 2) return null;
  if (childCount === 2) return '1fr 1fr';
  if (childCount === 3) return '1fr 1fr 1fr';
  if (childCount === 4) return '1fr 1fr 1fr 1fr';
  return 'repeat(auto-fit, minmax(250px, 1fr))';
}

function buildStyleProps(attrs, widgetType, tokenMapping, fontResolution, imageMap, xmlNode = null, depth = 0) {
  const props  = {};
  const { stackDirection, stackGap, padding, maxWidth, width, height,
          backgroundColor, 'background-color': bgColor,
          borderRadius, 'border-radius': borderRadiusAlt,
          position, top, right, bottom, left,
          color, 'font-family': fontFamily, 'font-size': fontSize,
          'font-weight': fontWeight, 'line-height': lineHeight,
          'letter-spacing': letterSpacing, opacity } = attrs;

  // ── Layout (flexbox / grid) ──
  if (widgetType === 'e-div-block') {
    // RC-09 Fix: Grid support for multi-child containers
    // detectGridLayout determines grid-template-columns from child count
    const gridColumns = detectGridLayout(xmlNode, attrs);
    if (gridColumns) {
      props['display'] = wrapType('string', 'grid');
      props['grid-template-columns'] = wrapType('string', gridColumns);
    } else {
      props['display'] = wrapType('string', 'block');
    }
    if (stackGap) props['gap'] = wrapSize(stackGap);
    if (padding)  props['padding'] = wrapDimensions(padding);
    if (maxWidth && isDimensionValue(maxWidth)) props['max-width'] = wrapSize(maxWidth);
    if (width    && isDimensionValue(width))    props['width']    = wrapSize(width);
    if (height   && isDimensionValue(height))   props['height']   = wrapSize(height);

    const bgVal = backgroundColor || bgColor;
    if (bgVal) {
      const resolved = resolveColor(bgVal, tokenMapping);
      if (resolved) {
        warn(`background.color '${bgVal}' muss als Global Class gesetzt werden (Bug 3). \u00dcbersprungen.`);
      }
    }
  }

  if (widgetType === 'e-flexbox' || widgetType === 'e-button') {
    // RC-02 Fix: Explicit display property required by Elementor V4
    // flex-direction without display:flex is ineffective CSS
    props['display'] = wrapType('string', 'flex');
    if (stackDirection) {
      props['flex-direction'] = stackDirection === 'vertical' ? 'column' : 'row';
    }
    if (stackGap) props['gap'] = wrapSize(stackGap);
    if (padding)  props['padding'] = wrapDimensions(padding);
    // Filter non-numeric CSS keywords (fit-content, auto, etc.) — Elementor
    // Style_Parser rejects $$type:"string" for dimension properties.
    if (maxWidth && isDimensionValue(maxWidth)) props['max-width'] = wrapSize(maxWidth);
    if (width    && isDimensionValue(width))    props['width']    = wrapSize(width);
    if (height   && isDimensionValue(height))   props['height']   = wrapSize(height);

    const bgVal = backgroundColor || bgColor;
    if (bgVal) {
      // Bug 3: background.color NUR in Global Classes, nie in lokalen Styles
      const resolved = resolveColor(bgVal, tokenMapping);
      if (resolved) {
        warn(`background.color '${bgVal}' muss als Global Class gesetzt werden (Bug 3). Übersprungen.`);
      }
    }
  }

  // ── Typography (heading / text) ──
  if (widgetType === 'e-heading' || widgetType === 'e-paragraph') {
    if (fontSize)      props['font-size']    = wrapSize(fontSize);
    if (fontWeight)    props['font-weight']  = wrapType('string', fontWeight);
    if (lineHeight)    props['line-height']  = resolveLineHeight(lineHeight);
    if (letterSpacing) props['letter-spacing'] = wrapSize(letterSpacing);
    if (fontFamily) {
      const resolved = resolveFont(fontFamily.split(',')[0].trim().replace(/['"]/g,''), tokenMapping, fontResolution);
      if (resolved) props['font-family'] = resolved;
    }
    if (color) {
      const resolved = resolveColor(color, tokenMapping);
      if (resolved) props['color'] = resolved;
    }
  }

  // ── Image ──
  if (widgetType === 'e-image') {
    if (width  && isDimensionValue(width))  props['width']  = wrapSize(width);
    if (height && isDimensionValue(height)) props['height'] = wrapSize(height);
  }

  // ── Border radius (all widget types) ──
  const br = borderRadius || borderRadiusAlt;
  if (br) props['border-radius'] = wrapBorderRadius(br);

  // ── Positioning ──
  // RC-08 Fix: Only set position:absolute for true overlay elements.
  // Framer uses absolute positioning as its canvas default — this should NOT
  // be carried over to Elementor V4 which expects normal DOM flow with flex/grid.
  // Heuristic: only set position when it's NOT 'absolute' (relative/fixed/sticky),
  // OR when the element has explicit offset values (top/right/bottom/left) that
  // indicate it's an intentional overlay (e.g. text on top of an image).
  // P1-2 Fix: Root containers (depth=0) always keep their positioning to
  // preserve Framer's intended layout structure at the top level.
  // NOTE: Uses !== undefined (not truthiness) so zero values like top:"0" work.
  if (position) {
    const hasExplicitOffsets = top !== undefined || right !== undefined || bottom !== undefined || left !== undefined;
    // Always keep non-absolute positioning (relative, fixed, sticky)
    // Always keep root container positioning (depth === 0)
    // For absolute: only keep if there are explicit offsets (true overlay) or it's the root
    if (position !== 'absolute' || hasExplicitOffsets || depth === 0) {
      props['position'] = wrapType('string', position);
      if (top !== undefined)    props['top']    = wrapSize(top);
      if (right !== undefined)  props['right']  = wrapSize(right);
      if (bottom !== undefined) props['bottom'] = wrapSize(bottom);
      if (left !== undefined)   props['left']   = wrapSize(left);
    }
  }

  // ── Opacity ──
  if (opacity !== undefined) props['opacity'] = wrapUnitless(opacity);

  // RC-11 Fix: Minimum default styles for widgets with empty props.
  // Widgets with {} props render with browser defaults (Times New Roman, no sizing).
  // Set sane fallbacks that match typical Framer designs.
  if (Object.keys(props).length === 0) {
    if (widgetType === 'e-heading') {
      props['font-family'] = wrapType('string', 'Inter');
      props['font-size'] = wrapSize('32px');
      props['font-weight'] = wrapType('string', '600');
      props['color'] = wrapColor('#111111');
    } else if (widgetType === 'e-paragraph') {
      props['font-family'] = wrapType('string', 'Inter');
      props['font-size'] = wrapSize('16px');
      props['line-height'] = wrapUnitless(1.6);
      props['color'] = wrapColor('#444444');
    } else if (widgetType === 'e-button') {
      props['color'] = wrapColor('#ffffff');
    }
  }

  return props;
}

// ─────────────────────────────────────────────
// NODE → V4 CONVERTER  (recursive)
// ─────────────────────────────────────────────

const usedStyleIds  = new Map(); // base-id → count
const usedWidgetIds = new Map(); // base-id → count  (Bug 5 Fix)

function uniqueStyleId(name) {
  const base = generateStyleId(name);
  const n    = (usedStyleIds.get(base) || 0) + 1;
  usedStyleIds.set(base, n);
  return n === 1 ? base : `${base}${n}`;
}

// Bug 5 Fix: unique widget IDs with counter
function uniqueWidgetId(raw) {
  const base = raw.toLowerCase().replace(/[^a-z0-9]/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '').slice(0, 20) || 'node';
  const n    = (usedWidgetIds.get(base) || 0) + 1;
  usedWidgetIds.set(base, n);
  return n === 1 ? base : `${base}-${n}`;
}

// Bug 3 Fix: detect pass-through containers (single child, no meaningful layout props set)
// RC-07 Fix: position, width, and height at 100% are Framer canvas defaults that
// don't fundamentally change layout. When a container has exactly 1 child and only
// these default props, flatten it to reduce DOM depth.
function isPassThroughContainer(xmlNode, widgetType) {
  if (widgetType !== 'e-flexbox') return false;
  const { attrs } = xmlNode;
  const meaningfulChildren = (xmlNode.children || []).filter(c => c.tagName && c.tagName !== '_root');
  // Only flatten if exactly one child (pure wrapper)
  if (meaningfulChildren.length !== 1) return false;

  // These props genuinely change layout and block pass-through
  const hasMeaningfulLayout = attrs.stackGap || attrs.padding || attrs.maxWidth
    || attrs.backgroundColor || attrs['background-color']
    || attrs.borderRadius || attrs['border-radius'];
  if (hasMeaningfulLayout) return false;

  // position, width, and height are Framer canvas defaults — only block
  // pass-through when they're explicitly non-default (non-absolute, non-100%)
  if (attrs.position && attrs.position !== 'absolute') return false;
  if (attrs.width && attrs.width !== '100%' && attrs.width !== '100vw') return false;
  if (attrs.height && attrs.height !== '100%' && attrs.height !== '100vh') return false;

  return true;
}

// Bug 3 Fix (improved): recursively unwrap a chain of pass-through containers.
// Returns an array of { node, depth } — either the original node or its
// eventually-meaningful descendant(s), skipping every pure wrapper in between.
function resolvePassThrough(xmlNode, depth) {
  const widgetType = determineWidgetType(xmlNode.attrs, xmlNode);
  if (!isPassThroughContainer(xmlNode, widgetType)) {
    return [{ node: xmlNode, depth }];
  }
  log(`[${'  '.repeat(depth)}] FLATTENED pass-through: ${xmlNode.attrs.name || 'unnamed'}`);
  const meaningful = (xmlNode.children || []).filter(c => c.tagName && c.tagName !== '_root');
  // Single child guaranteed by isPassThroughContainer — recurse into it
  return resolvePassThrough(meaningful[0], depth);
}

// ── Bug 8 Fix: Extract text from Framer Component Instance attributes ──
// Framer components store text in dynamically-named attributes
// (e.g. nUjzUoV6a="See how we work with you"). This heuristic scans
// all attrs of component instances for the best text candidate.
function extractComponentText(attrs) {
  if (!attrs.componentId && !attrs.variant) return undefined;

  let bestText = undefined;

  for (const [key, val] of Object.entries(attrs)) {
    // Skip known system/meta keys
    if (['componentId', 'variant', 'name', 'id', 'nodeId', 'tag', 'href',
          'target', 'layout', 'overflow', 'position', 'opacity'].includes(key)) continue;
    // Skip style-reference keys (uppercase-camel identifiers like `backgroundColor`)
    if (/^[A-Z]/.test(key)) continue;

    const str = String(val).trim();

    if (str.length < 3) continue;                // Too short
    if (str === 'true' || str === 'false') continue; // Boolean
    if (str.startsWith('http') || str.startsWith('/') || str.startsWith('#')) continue; // URL / style path / hash
    if (/^-?\d*\.?\d+$/.test(str)) continue;     // Numeric
    if (/^[a-zA-Z0-9_-]{8,15}$/.test(str) && !str.includes(' ')) continue; // Gen-ID pattern
    // RC-04 Fix: Skip XML/HTML fragments that were incorrectly extracted as text.
    // Framer's internal XML attributes (e.g. 'backgroundColor="..." overflow="clip" />')
    // end up here when the heuristic scans component attrs. Real text never contains
    // self-closing tags or attribute-style equals.
    if (str.includes('/>') || str.includes('</') || /^[a-zA-Z]+="[^"]*"(\s+[a-zA-Z]+="[^"]*")*\s*\/?>/.test(str) || /\w+="[^"]*"/.test(str)) continue;

    // Pick longest — component text attrs are typically the longest readable string
    if (!bestText || str.length > bestText.length) {
      bestText = str;
    }
  }

  return bestText;
}

function convertNode(xmlNode, tokenMapping, fontResolution, imageMap, depth = 0) {
  const { attrs } = xmlNode;
  // Bug 1+8 Fix: resolve text from component attrs > explicit text attr > child text
  const compText = extractComponentText(attrs);
  const textContent = compText !== undefined
    ? compText
    : (attrs.text !== undefined ? attrs.text : (xmlNode._textContent || undefined));
  // Build enriched attrs with resolved text for type detection
  const enrichedAttrs = textContent !== undefined ? { ...attrs, text: textContent } : attrs;

  const name       = attrs.name || `node-${depth}`;
  const nodeId     = attrs.nodeId || attrs.id;
  const widgetType = determineWidgetType(enrichedAttrs, xmlNode);
  const styleId    = uniqueStyleId(name);

  // Bug 5 Fix: unique widget ID
  const rawId  = nodeId || name;
  const widgetId = uniqueWidgetId(rawId);

  log(`[${'  '.repeat(depth)}] ${name} → ${widgetType} (${styleId})`);

  // Build base props (pass xmlNode for grid detection in RC-09)
  const props = buildStyleProps(enrichedAttrs, widgetType, tokenMapping, fontResolution, imageMap, xmlNode, depth);



  // ── Settings ──
  const settings = {
    classes: { '$$type': 'classes', value: [styleId] },
  };

  if (widgetType === 'e-flexbox') {
    settings.tag = attrs.tag || (depth === 0 ? 'section' : 'div');
  }

  if (widgetType === 'e-div-block') {
    settings.tag = attrs.tag || 'div';
  }

  if (widgetType === 'e-button') {
    settings.tag = attrs.tag || (attrs.href ? 'a' : 'button');
    settings.text = wrapHtmlContent(textContent || name || '');
    if (attrs.href) settings.link = wrapLink(attrs.href, attrs.target === '_blank');
  }

  if (widgetType === 'e-heading') {
    settings.tag   = determineHtmlTag(enrichedAttrs);
    settings.title = wrapHtmlContent(textContent || '');
  }

  if (widgetType === 'e-paragraph') {
    // Prop-Name ist 'paragraph' (nicht 'editor') — EMCP Bug-Fix #56 bestaetigt
    settings.paragraph = wrapHtmlContent(textContent || '');
  }

  if (widgetType === 'e-image') {
    const imgSrc = resolveImageSrc(attrs.backgroundImage || attrs.src, imageMap);
    if (imgSrc) settings['image'] = wrapImage(imgSrc);
    else        settings['image'] = wrapImage(wrapImageSrc({ id: 0 }));
  }

  if (widgetType === 'e-svg') {
    // Serialize the SVG sub-tree back to markup for e-svg content
    settings['svg-icon'] = { '$$type': 'string', value: serializeSvgNode(xmlNode) };
    if (attrs.width)  settings.width  = wrapSize(attrs.width);
    if (attrs.height) settings.height = wrapSize(attrs.height);
  }

  // ── Style variants (VERBOSE format: id/type/label required by elementor-set-content) ──
  const baseVariant = {
    meta:  { breakpoint: 'desktop', state: null },
    props: Object.keys(props).length > 0 ? props : {},
    custom_css: null,
  };

  const styles = {
    [styleId]: {
      id: styleId,
      type: 'class',
      label: 'local',
      variants: [baseVariant],
    },
  };

  // ── Recurse into children ──
  // e-svg: SVG sub-tree already serialized to markup — no V4 children
  const rawChildren = widgetType === 'e-svg'
    ? []
    : (xmlNode.children || []).filter(c => c.tagName && c.tagName !== '_root');

  const v4Children = [];
  for (const child of rawChildren) {
    // Bug 3 Fix: recursively unwrap any chain of pass-through containers
    const resolved = resolvePassThrough(child, depth + 1);
    for (const r of resolved) {
      const converted = convertNode(r.node, tokenMapping, fontResolution, imageMap, r.depth);
      if (converted) v4Children.push(converted);
    }
  }

  // ── Determine elType (required by elementor-set-content) ──
  // Atomic containers (e-flexbox, e-div-block) are Elementor element types.
  // Atomic widgets (e-heading, e-paragraph, ...) use elType:"widget" + widgetType.
  const ATOMIC_ELEMENT_TYPES = new Set(['e-flexbox', 'e-div-block']);
  const elType = ATOMIC_ELEMENT_TYPES.has(widgetType) ? widgetType : 'widget';

  // RC-01 Fix: type field required by server-side batch-build-page.php
  // Without 'type', the server falls back to 'container' for ALL widgets
  // Also: elementor-set-content uses elType+widgetType, batch-build-page uses type
  // Adding 'type' makes the output compatible with BOTH abilities (RC-03 Fix)
  const node = { type: widgetType, elType, widgetType, id: widgetId, settings, styles };
  if (v4Children.length > 0) node.elements = v4Children;

  return node;
}

// ─────────────────────────────────────────────
// RC-13: TOKEN USAGE ANALYZER
// ─────────────────────────────────────────────

function analyzeTokenUsage(treeNodes) {
  const report = {
    hardcoded_colors: new Map(),
    hardcoded_fonts: new Map(),
    hardcoded_sizes: new Map(),
    total_elements: 0,
    total_hardcoded: 0,
    suggestions: [],
  };

  function walk(node) {
    if (!node || typeof node !== 'object') return;
    // Only count actual element nodes (have widgetType or elType)
    if (node.widgetType || node.elType) {
      report.total_elements++;
    }

    const styles = node.styles || {};
    for (const [styleId, styleDef] of Object.entries(styles)) {
      const variants = styleDef.variants || [];
      for (const variant of variants) {
        const props = variant.props || {};
        for (const [prop, value] of Object.entries(props)) {
          if (!value || typeof value !== 'object') continue;

          // Detect hardcoded colors
          if (value['$$type'] === 'color') {
            const hex = (value.value?.hex || value.value || '').toString();
            if (hex && !hex.startsWith('e-gv-') && !hex.startsWith('var(')) {
              const key = hex.slice(0, 7);
              if (!report.hardcoded_colors.has(key)) {
                report.hardcoded_colors.set(key, { value: hex, count: 0, elements: [], prop });
              }
              const entry = report.hardcoded_colors.get(key);
              entry.count++;
              if (entry.elements.length < 5) entry.elements.push(node.id || node.widgetType || '?');
              report.total_hardcoded++;
            }
          }

          // Detect hardcoded font-families
          if (prop === 'font-family') {
            if (value['$$type'] === 'string') {
              const family = (value.value || '').toString();
              if (family && !family.startsWith('e-gv-') && !family.startsWith('var(')) {
                if (!report.hardcoded_fonts.has(family)) {
                  report.hardcoded_fonts.set(family, { value: family, count: 0, elements: [] });
                }
                const entry = report.hardcoded_fonts.get(family);
                entry.count++;
                if (entry.elements.length < 5) entry.elements.push(node.id || node.widgetType || '?');
                report.total_hardcoded++;
              }
            } else if (value['$$type'] === 'gv-font') {
              // GV font reference — already using design tokens, good!
            }
          }

          // Detect hardcoded sizes (px values that could be tokens)
          if (prop === 'font-size' || prop === 'width' || prop === 'height' || prop === 'gap' || prop === 'padding') {
            if (value['$$type'] === 'size') {
              const sizeVal = (value.value?.size || value.value || '').toString();
              const pxMatch = sizeVal.match(/^(\d+)px$/);
              if (pxMatch) {
                const px = parseInt(pxMatch[1], 10);
                if (px >= 16 && px % 4 === 0) {
                  const key = `${prop}:${sizeVal}`;
                  if (!report.hardcoded_sizes.has(key)) {
                    report.hardcoded_sizes.set(key, { prop, value: sizeVal, count: 0 });
                  }
                  report.hardcoded_sizes.get(key).count++;
                }
              }
            }
          }
        }
      }
    }

    const children = node.elements || [];
    for (const child of children) walk(child);
  }

  const roots = Array.isArray(treeNodes) ? treeNodes : [treeNodes];
  for (const root of roots) walk(root);

  // Generate suggestions
  const colorEntries = [...report.hardcoded_colors.entries()]
    .sort((a, b) => b[1].count - a[1].count);
  const fontEntries = [...report.hardcoded_fonts.entries()]
    .sort((a, b) => b[1].count - a[1].count);

  for (const [hex, data] of colorEntries) {
    if (data.count >= 2) {
      report.suggestions.push({
        type: 'color',
        severity: data.count >= 3 ? 'high' : 'medium',
        value: data.value,
        occurrences: data.count,
        action: `Erstelle e-gv-color Variable für ${data.value} (${data.count}x verwendet). Ersetze alle Hardcodes mit var(--gv-<id>).`,
      });
    }
  }

  for (const [family, data] of fontEntries) {
    if (data.count >= 2) {
      report.suggestions.push({
        type: 'font',
        severity: data.count >= 3 ? 'high' : 'medium',
        value: family,
        occurrences: data.count,
        action: `Erstelle e-gv-font Variable für "${family}" (${data.count}x verwendet).`,
      });
    }
  }

  // Summary
  report.summary = {
    unique_colors: report.hardcoded_colors.size,
    unique_fonts: report.hardcoded_fonts.size,
    unique_sizes: report.hardcoded_sizes.size,
    total_hardcoded_values: report.total_hardcoded,
    high_severity_suggestions: report.suggestions.filter(s => s.severity === 'high').length,
    medium_severity_suggestions: report.suggestions.filter(s => s.severity === 'medium').length,
  };

  return report;
}

// ─────────────────────────────────────────────
// LOAD INPUTS
// ─────────────────────────────────────────────

// XML
let xmlContent;
if (args['xml-string']) {
  xmlContent = args['xml-string'];
} else {
  if (!fs.existsSync(args.xml)) {
    process.stderr.write(`Error: XML nicht gefunden: ${args.xml}\n`); process.exit(2);
  }
  xmlContent = fs.readFileSync(args.xml, 'utf8');
}

// Token mapping
let tokenMapping = null;
if (args.tokens) {
  if (!fs.existsSync(args.tokens)) {
    warn(`token-mapping.json nicht gefunden: ${args.tokens}. Tokens werden nicht aufgelöst.`);
  } else {
    tokenMapping = JSON.parse(fs.readFileSync(args.tokens, 'utf8'));
    log(`Token mapping loaded: ${Object.keys(tokenMapping.colors || {}).length} colors, ${Object.keys(tokenMapping.fonts || {}).length} fonts`);
  }
}

// Font resolution
let fontResolution = null;
if (args.fonts) {
  if (!fs.existsSync(args.fonts)) {
    warn(`font-resolution.json nicht gefunden: ${args.fonts}.`);
  } else {
    fontResolution = JSON.parse(fs.readFileSync(args.fonts, 'utf8'));
    log(`Font resolution loaded: ${(fontResolution.fonts || []).length} fonts`);
  }
}

// Image map (optional)
let imageMap = null;
if (args['image-map']) {
  if (fs.existsSync(args['image-map'])) {
    imageMap = JSON.parse(fs.readFileSync(args['image-map'], 'utf8'));
    log(`Image map loaded: ${Object.keys(imageMap.images || {}).length} images`);
  }
}

// ─────────────────────────────────────────────
// CONVERT
// ─────────────────────────────────────────────

let xmlRoots;
try {
  const tokens = tokenizeXml(xmlContent);
  xmlRoots     = buildTree(tokens);
} catch (e) {
  process.stderr.write(`Error: XML parse fehlgeschlagen: ${e.message}\n`); process.exit(2);
}

if (xmlRoots.length === 0) {
  process.stderr.write('Error: Keine Nodes im XML gefunden.\n'); process.exit(2);
}

log(`XML nodes parsed: ${xmlRoots.length} root node(s)`);

// Convert each root node
const v4Tree = xmlRoots
  .filter(n => n.tagName && n.tagName !== '_root')
  .map(n => convertNode(n, tokenMapping, fontResolution, imageMap, 0));

// ─────────────────────────────────────────────
// OUTPUT
// ─────────────────────────────────────────────

// Single root or array
const result = v4Tree.length === 1 ? v4Tree[0] : v4Tree;
const output = JSON.stringify(result, null, 2);

// Determine output path — use temp file when validating without --output
const outputPath = args.output || (args.validate ? path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'v4tree-')), 'tree.json') : null);

if (outputPath) {
  fs.mkdirSync(path.dirname(path.resolve(outputPath)), { recursive: true });
  fs.writeFileSync(outputPath, output, 'utf8');
  if (args.output) process.stderr.write(`Saved to ${outputPath}\n`);
}

// --validate: run validate-v4-tree.js on the output
let validationPassed = true;
if (args.validate && outputPath) {
  const validatorScript = path.join(__dirname, 'validate-v4-tree.js');
  process.stderr.write(`Validating ${outputPath} …\n`);
  const val = spawnSync('node', [validatorScript, outputPath], { stdio: 'pipe', encoding: 'utf8' });
  if (val.stderr) process.stderr.write(val.stderr);
  if (val.stdout) {
    try {
      const result = JSON.parse(val.stdout);
      const icon = result.passed ? '✅' : '❌';
      process.stderr.write(`${icon} Score: ${result.score}% | ${result.stats.totalErrors} errors, ${result.stats.totalWarnings} warnings\n`);
      if (!result.passed) validationPassed = false;
    } catch {
      process.stderr.write(val.stdout.slice(0, 500) + '\n');
      validationPassed = false;
    }
  }
  if (val.status !== 0) validationPassed = false;
}

// Print to stdout when no --output
if (!args.output) {
  process.stdout.write(output + '\n');
}

// ─────────────────────────────────────────────
// RC-13: TOKENS REPORT
// ─────────────────────────────────────────────

let tokensReport = null;
if (args['tokens-report'] && v4Tree.length > 0) {
  tokensReport = analyzeTokenUsage(v4Tree);
  const tokensReportPath = path.join(path.dirname(outputPath || '.'), 'tokens-report.json');
  try {
    const reportOutput = {
      generated_at: new Date().toISOString(),
      source: args.xml || 'inline',
      summary: tokensReport.summary,
      hardcoded_colors: Object.fromEntries(tokensReport.hardcoded_colors),
      hardcoded_fonts: Object.fromEntries(tokensReport.hardcoded_fonts),
      suggestions: tokensReport.suggestions,
    };
    fs.writeFileSync(tokensReportPath, JSON.stringify(reportOutput, null, 2), 'utf8');
    process.stderr.write(`\n📊 Tokens Report → ${path.relative(process.cwd(), tokensReportPath)}\n`);
    process.stderr.write(`   ${tokensReport.summary.unique_colors} unique hardcoded colors, ${tokensReport.summary.unique_fonts} fonts, ${tokensReport.summary.total_hardcoded_values} total\n`);
    if (tokensReport.suggestions.length > 0) {
      process.stderr.write(`   🔔 ${tokensReport.suggestions.length} token suggestions (${tokensReport.summary.high_severity_suggestions} high-priority)\n`);
      for (const s of tokensReport.suggestions.filter(s => s.severity === 'high').slice(0, 3)) {
        process.stderr.write(`     • ${s.type}: ${s.value.slice(0,40)} (${s.occurrences}x)\n`);
      }
    }
  } catch (e) {
    process.stderr.write(`⚠ Tokens report write failed: ${e.message}\n`);
  }
}

// ─────────────────────────────────────────────
// RC-12: GLOBAL CLASSES INTEGRATION
// ─────────────────────────────────────────────

if (args.gc && outputPath) {
  const gcScript = path.join(__dirname, 'generate-global-classes.js');
  const gcOutput = args['gc-output'] || path.join(path.dirname(outputPath), 'global-class-plan.json');
  const minDups = args['gc-min-dups'] || '2';

  if (!fs.existsSync(gcScript)) {
    process.stderr.write('⚠ generate-global-classes.js not found — skipping GC analysis.\n');
  } else {
    process.stderr.write(`\n🔍 Running Global Classes analysis (min-dups=${minDups})…\n`);
    try {
      const gcResult = spawnSync('node', [gcScript, '--tree', outputPath, '--min-dups', minDups, '--output', gcOutput], {
        stdio: 'pipe', encoding: 'utf8', timeout: 30000,
      });
      if (gcResult.stderr) {
        const gcStderr = gcResult.stderr.toString();
        const summaryMatch = gcStderr.match(/\[gen-gc\] (\d+) GC-Vorschläge/);
        if (summaryMatch) {
          process.stderr.write(`✅ GC Analysis: ${summaryMatch[1]} Global Class suggestions\n`);
          process.stderr.write(`   Plan → ${path.relative(process.cwd(), gcOutput)}\n`);
        } else {
          const noDupMatch = gcStderr.match(/Keine Duplikate gefunden/);
          if (noDupMatch) {
            process.stderr.write('ℹ️  No duplicate styles found — all styles are unique. GCs not needed.\n');
          } else {
            process.stderr.write(gcStderr.slice(0, 500) + '\n');
          }
        }
      }
    } catch (e) {
      process.stderr.write(`⚠ GC analysis failed: ${e.message}\n`);
    }
  }
}

// Cleanup temp dir
if (!args.output && outputPath) {
  try { fs.rmSync(path.dirname(outputPath), { recursive: true, force: true }); } catch { /* ignore */ }
}

process.stderr.write(`✓ ${usedStyleIds.size} V4 nodes converted, ${warnings.length} warnings\n`);
if (warnings.length > 0 && args.verbose) {
  warnings.forEach(w => process.stderr.write(`  ⚠ ${w}\n`));
}

process.exit(warnings.length > 0 || !validationPassed ? 1 : 0);
