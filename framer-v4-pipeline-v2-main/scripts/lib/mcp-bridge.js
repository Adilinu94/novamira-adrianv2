#!/usr/bin/env node
/**
 * scripts/lib/mcp-bridge.js  —  v4.0.0 (JSON-RPC 2.0)
 *
 * ARCHITEKTUR (Fix A — 2026-06-11):
 *   Direkte HTTP-Calls von Node.js zu solar.local via JSON-RPC 2.0
 *   mit Session-Handshake und Adapter-Wrapper für alle Novamira-Abilities.
 *
 *   Protokoll:
 *     POST http://solar.local/wp-json/mcp/novamira
 *     1. initialize → Session-Handshake (Mcp-Session-Id)
 *     2. tools/call  → { name: "mcp-adapter-execute-ability",
 *                        arguments: { ability_name: "...", parameters: {...} } }
 *
 *   Alle Novamira-Abilities laufen DURCH den Adapter:
 *     ❌ Direkt:   novamira/adrians-export-design-system {}
 *     ✅ Korrekt:  mcp-adapter-execute-ability {
 *                    ability_name: "novamira/adrians-export-design-system",
 *                    parameters: {}
 *                  }
 *
 * SELF-TEST:
 *   node scripts/lib/mcp-bridge.js --self-test
 *   Sendet einen echten greet-Call wenn Konfiguration gefunden wird.
 *
 * ENVIRONMENT:
 *   Liest Konfiguration aus (in dieser Reihenfolge):
 *     1. .mcp.json / mcp-server-config.json (Automattic- oder Legacy-Format)
 *     2. .env / .env.local (WP_API_URL, WP_API_USERNAME, WP_API_PASSWORD)
 */

import { readFileSync, existsSync } from 'fs';
import { join, dirname } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));

// ── Config Discovery ──────────────────────────────────────────────────────────

/**
 * Sucht nach einer MCP-Konfigurationsdatei.
 *
 * Suchpfad (erste gefundene Datei gewinnt):
 *   1. MCP_CONFIG_PATH env var
 *   2. .mcp.json im Projekt-Root
 *   3. mcp-server-config.json im Projekt-Root
 *   4. novamira-adrianv2/mcp-server-config.json
 *
 * @returns {string|null} Absoluter Pfad zur Config-Datei oder null.
 */
function findMcpConfig() {
  const projectRoot = join(__dirname, '..', '..');

  const candidates = [
    process.env.MCP_CONFIG_PATH || null,
    join(projectRoot, '.mcp.json'),
    join(projectRoot, 'mcp-server-config.json'),
    join(projectRoot, '..', 'novamira-adrianv2', 'mcp-server-config.json'),
  ].filter(Boolean);

  for (const candidate of candidates) {
    if (existsSync(candidate)) return candidate;
  }
  return null;
}

/**
 * Parst die MCP-Konfiguration und extrahiert URL + Auth.
 *
 * Unterstützt drei Formate:
 *
 * 1. Automattic (Claude Desktop .mcp.json):
 *    { "mcpServers": { "novamira-solar-local": {
 *        "url": "http://solar.local/wp-json/mcp/novamira",
 *        "headers": { "Authorization": "Basic <base64>" }
 *    } } }
 *
 * 2. Automattic (command/env):
 *    { "mcpServers": { "novamira-solar-local": {
 *        "command": "npx", "args": [...],
 *        "env": { "WP_API_URL": "...", "WP_API_USERNAME": "...",
 *                 "WP_API_PASSWORD": "..." }
 *    } } }
 *
 * 3. Legacy (einfach):
 *    { "mcpServers": { "novamira": {
 *        "url": "...", "apiKey": "...",
 *        "wp_url": "...", "wp_user": "...", "wp_app_password": "..."
 *    } } }
 *
 * @param {string} configPath Absoluter Pfad zur Config-Datei.
 * @returns {{ mcpUrl: string, authHeader: string|null, wpUrl: string }}
 */
function parseMcpConfig(configPath) {
  const raw = JSON.parse(readFileSync(configPath, 'utf8'));
  const servers = raw.mcpServers || raw.servers || {};

  if (Object.keys(servers).length === 0) {
    throw new Error(`Keine mcpServers in ${configPath} gefunden.`);
  }

  // Ersten novamira-Server finden (case-insensitive)
  const key = Object.keys(servers).find(k =>
    k.toLowerCase().includes('novamira')
  ) || Object.keys(servers)[0]; // Fallback: erster Server

  const srv = servers[key];

  // URL ermitteln
  let mcpUrl = srv.url || srv.endpoint || null;

  // Automattic command/env Format: URL aus env holen
  if (!mcpUrl && srv.env?.WP_API_URL) {
    mcpUrl = srv.env.WP_API_URL;
  }

  // Fallback: Umgebungsvariable
  if (!mcpUrl) {
    mcpUrl = process.env.WP_API_URL || null;
  }

  if (!mcpUrl) {
    throw new Error(
      `Kein URL für novamira-Server "${key}" gefunden. ` +
      `Erwartet: "url" im Config-Eintrag, env.WP_API_URL, oder WP_API_URL env var.`
    );
  }

  // Auth ermitteln
  let authHeader = null;

  // Format 1: headers.Authorization direkt
  if (srv.headers?.Authorization) {
    authHeader = srv.headers.Authorization;
  }
  // Format 2: Automattic env (WP_API_USERNAME + WP_API_PASSWORD)
  else if (srv.env?.WP_API_USERNAME && srv.env?.WP_API_PASSWORD) {
    const b64 = Buffer.from(
      `${srv.env.WP_API_USERNAME}:${srv.env.WP_API_PASSWORD}`
    ).toString('base64');
    authHeader = `Basic ${b64}`;
  }
  // Format 3: Legacy wp_user/wp_app_password
  else if (srv.wp_user && srv.wp_app_password) {
    const b64 = Buffer.from(
      `${srv.wp_user}:${srv.wp_app_password}`
    ).toString('base64');
    authHeader = `Basic ${b64}`;
  }
  // Format 4: Legacy apiKey
  else if (srv.apiKey || srv.api_key) {
    authHeader = `Bearer ${srv.apiKey || srv.api_key}`;
  }
  // Fallback: Umgebungsvariablen
  else if (process.env.WP_API_USERNAME && process.env.WP_API_PASSWORD) {
    const b64 = Buffer.from(
      `${process.env.WP_API_USERNAME}:${process.env.WP_API_PASSWORD}`
    ).toString('base64');
    authHeader = `Basic ${b64}`;
  }
  else if (process.env.NOVAMIRA_API_KEY) {
    authHeader = `Bearer ${process.env.NOVAMIRA_API_KEY}`;
  }

  // WordPress Base-URL (für REST-Fallback)
  const wpUrl = srv.wp_url || srv.env?.WP_URL || mcpUrl.replace(/\/wp-json\/mcp\/.*$/, '');

  return { mcpUrl, authHeader, wpUrl, serverKey: key };
}

// ── McpBridge ─────────────────────────────────────────────────────────────────

export class McpBridge {

  /**
   * @param {object} options
   * @param {string} options.mcpUrl         URL zum MCP-Endpoint (z.B. http://solar.local/wp-json/mcp/novamira)
   * @param {string} [options.authHeader]   Authorization-Header (Basic <b64> oder Bearer <token>)
   * @param {string} [options.wpUrl]        WordPress Base-URL für REST-Fallback
   * @param {number} [options.timeout=120000] Timeout pro Request in ms
   * @param {number} [options.concurrency=3]  Max parallele Calls (MCP_CONCURRENCY env var)
   * @param {boolean} [options.verbose=false] Debug-Logging aktivieren
   */
  constructor(options = {}) {
    this.mcpUrl       = options.mcpUrl || '';
    this._authHeader  = options.authHeader || null;
    this.wpUrl        = options.wpUrl || '';
    this.timeout      = options.timeout || 120000;
    this.verbose      = options.verbose || false;

    // FIX-7: Concurrency-Limit für callParallel()
    // Sprint 14: Default auf 5 erhöht (modern machines handle more parallel WP requests).
    // Überschreibbar via Option, MCP_CONCURRENCY env var, oder MCP_CONCURRENCY_PROFILE.
    this.defaultConcurrency = options.concurrency
      || McpBridge._resolveConcurrency();

    // Session-Management
    this._sessionId    = null;
    this._sessionExpiry = 0;
    this._requestCounter = 0;

    // Cache für read-only Abilities
    this._cache = new Map();
    this._cacheTtl = 5 * 60 * 1000; // 5 Minuten
  }

  // ── Concurrency Resolution (Sprint 14) ─────────────────────────────────

  /**
   * Resolves the default concurrency value from environment.
   * Priority: MCP_CONCURRENCY > MCP_CONCURRENCY_PROFILE > default (5).
   *
   * MCP_CONCURRENCY_PROFILE presets:
   *   low    = 2  (shared hosting, single-core)
   *   medium = 5  (default, VPS/local dev)
   *   high   = 10 (dedicated server)
   */
  static _resolveConcurrency() {
    const explicit = parseInt(process.env.MCP_CONCURRENCY || '', 10);
    if (!isNaN(explicit) && explicit > 0) return explicit;

    const profile = process.env.MCP_CONCURRENCY_PROFILE || 'medium';
    const presets = { low: 2, medium: 5, high: 10 };
    return presets[profile] || 5;
  }

  // ── Static Factory ───────────────────────────────────────────────────────

  /**
   * Erstellt eine McpBridge aus einer Konfigurationsdatei.
   *
   * Sucht automatisch nach .mcp.json oder mcp-server-config.json.
   *
   * @param {string|null} [configPath] Pfad zur Config-Datei (null = auto-detect)
   * @returns {Promise<McpBridge>}
   */
  static async fromConfig(configPath = null) {
    const resolved = configPath || findMcpConfig();

    if (!resolved) {
      // Fallback: nur Umgebungsvariablen (parseMcpConfig-Äquivalent ohne Datei)
      const mcpUrl = process.env.WP_API_URL;
      if (mcpUrl) {
        const bridge = new McpBridge({
          mcpUrl,
          authHeader: null, // wird gleich gesetzt
          wpUrl: mcpUrl.replace(/\/wp-json\/mcp\/.*$/, ''),
        });
        // Auth aus env vars (gleiche Logik wie parseMcpConfig)
        if (process.env.WP_API_USERNAME && process.env.WP_API_PASSWORD) {
          const b64 = Buffer.from(
            `${process.env.WP_API_USERNAME}:${process.env.WP_API_PASSWORD}`
          ).toString('base64');
          bridge._authHeader = `Basic ${b64}`;
        } else if (process.env.NOVAMIRA_API_KEY) {
          bridge._authHeader = `Bearer ${process.env.NOVAMIRA_API_KEY}`;
        }
        return bridge;
      }
      throw new Error(
        'Keine MCP-Konfiguration gefunden.\n' +
        'Erwartet: .mcp.json, mcp-server-config.json, oder WP_API_URL env var.\n' +
        'Siehe mcp-server-config.example.json für ein Template.'
      );
    }

    const { mcpUrl, authHeader, wpUrl } = parseMcpConfig(resolved);
    return new McpBridge({ mcpUrl, authHeader, wpUrl, verbose: process.env.MCP_VERBOSE === '1' });
  }

  // ── Session-Management ───────────────────────────────────────────────────

  /**
   * Stellt sicher, dass eine gültige MCP-Session existiert.
   * Führt den JSON-RPC initialize-Handshake aus, wenn nötig.
   *
   * Sessions laufen nach 25 Minuten ab (konservativ, Server hat 30 Min).
   */
  async _ensureSession() {
    if (this._sessionId && Date.now() < this._sessionExpiry) {
      return; // Session ist noch gültig
    }

    process.stderr.write('[mcp-bridge] Initialisiere MCP-Session...\n');

    // Node.js: TLS für self-signed Zertifikate (nur wenn NODE_TLS_REJECT_UNAUTHORIZED=0)
    const httpsAgent = this.mcpUrl.startsWith('https')
      ? this._getHttpsAgent()
      : null;

    const fetchOpts = {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        ...this._getAuthHeaders(),
      },
      body: JSON.stringify({
        jsonrpc: '2.0',
        id: 0,
        method: 'initialize',
        params: {
          protocolVersion: '2024-11-05',
          capabilities: {},
          clientInfo: {
            name: 'framer-v4-pipeline',
            version: '4.0.0',
          },
        },
      }),
      signal: AbortSignal.timeout(30000),
    };

    if (httpsAgent) {
      fetchOpts.agent = httpsAgent;
    }

    const res = await fetch(this.mcpUrl, fetchOpts);

    if (!res.ok) {
      const text = await res.text().catch(() => '');
      throw new Error(
        `MCP initialize fehlgeschlagen: HTTP ${res.status} — ${text.slice(0, 300)}`
      );
    }

    const sid = res.headers.get('mcp-session-id');
    if (!sid) {
      throw new Error(
        'MCP initialize: Kein Mcp-Session-Id im Response-Header. ' +
        'Prüfe ob der MCP-Server läuft und die Auth korrekt ist.'
      );
    }

    this._sessionId = sid;
    this._sessionExpiry = Date.now() + 25 * 60 * 1000; // 25 Minuten (konservativ)
    this._requestCounter = 0;
    process.stderr.write(`[mcp-bridge] Session initialisiert: ${sid.slice(0, 8)}...\n`);
  }

  /**
   * Erzeugt einen https.Agent der self-signed Zertifikate akzeptiert.
   * Respektiert NODE_TLS_REJECT_UNAUTHORIZED — nur wenn auf '0' gesetzt
   * wird rejectUnauthorized: false verwendet. Ergebnis wird gecacht.
   */
  _getHttpsAgent() {
    if (this._httpsAgent !== undefined) {
      return this._httpsAgent; // null = kein Agent nötig/verfügbar
    }

    // Nur wenn explizit NODE_TLS_REJECT_UNAUTHORIZED=0 gesetzt ist
    if (process.env.NODE_TLS_REJECT_UNAUTHORIZED !== '0') {
      this._httpsAgent = null;
      return null;
    }

    try {
      // require ist synchron und wird von Node.js gecacht
      const https = require('https');
      this._httpsAgent = new https.Agent({ rejectUnauthorized: false });
      return this._httpsAgent;
    } catch {
      this._httpsAgent = null;
      return null;
    }
  }

  // ── Core Call Methods ────────────────────────────────────────────────────

  /**
   * Führt einen Novamira-Ability-Call via JSON-RPC 2.0 + Adapter-Wrapper aus.
   *
   * Diese Methode ist der zentrale Einstiegspunkt für alle MCP-Calls.
   * Scripts rufen mcp.call('novamira/<ability>', { params }) auf.
   *
   * @param {string}       ability         Ability-Name (z.B. "novamira/adrians-greet")
   * @param {object}       [params={}]     Ability-Parameter
   * @param {object}       [options={}]    Call-Optionen
   * @param {boolean}      [options.cache=true]  Cache für read-only Abilities verwenden
   * @param {number}       [options.maxRetries=2] Max Retries bei 5xx/429
   * @returns {Promise<*>} Parsed Ability-Response
   */
  async call(ability, params = {}, options = {}) {
    // Cache-Regel: setup-v4-foundation NIEMALS cachen
    // design-system DARF gecacht werden (read-only)
    const isMutable = (
      ability.includes('setup-v4-foundation') ||
      ability.includes('setup-kit')
    );

    const useCache = isMutable ? false : (options.cache !== false);

    // Cache-Check
    if (useCache) {
      const cacheKey = `${ability}:${JSON.stringify(params)}`;
      const cached = this._cache.get(cacheKey);
      if (cached && Date.now() < cached.expiry) {
        this._log(`Cache-HIT: ${ability}`);
        return cached.data;
      }
    }

    const maxRetries = options.maxRetries ?? 2;

    for (let attempt = 0; attempt <= maxRetries; attempt++) {
      try {
        await this._ensureSession();

        const id = ++this._requestCounter;
        const httpsAgent = this.mcpUrl.startsWith('https')
          ? this._getHttpsAgent()
          : null;

        const fetchOpts = {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Mcp-Session-Id': this._sessionId,
            ...this._getAuthHeaders(),
          },
          body: JSON.stringify({
            jsonrpc: '2.0',
            id,
            method: 'tools/call',
            params: {
              name: 'mcp-adapter-execute-ability',
              arguments: {
                ability_name: ability,
                parameters: params,
              },
            },
          }),
          signal: AbortSignal.timeout(this.timeout),
        };

        if (httpsAgent) {
          fetchOpts.agent = httpsAgent;
        }

        const res = await fetch(this.mcpUrl, fetchOpts);

        // Session abgelaufen (401 oder 419) → einmal neu initialisieren
        if (res.status === 401 || res.status === 419) {
          if (attempt < maxRetries) {
            process.stderr.write(`[mcp-bridge] Session abgelaufen (${res.status}), initialisiere neu...\n`);
            this._sessionId = null;
            this._sessionExpiry = 0;
            continue; // _ensureSession() wird im nächsten Versuch neu initialisieren
          }
        }

        if (!res.ok) {
          const text = await res.text().catch(() => '');
          throw new Error(
            `MCP HTTP ${res.status} für "${ability}": ${text.slice(0, 300)}`
          );
        }

        const envelope = await res.json();

        if (envelope.error) {
          const err = envelope.error;
          throw new Error(
            `MCP RPC Error ${err.code || '?'}: ${err.message || 'Unbekannter Fehler'}`
          );
        }

        // tools/call response: { content: [{ type: "text", text: "..." }] }
        const result = this._parseToolResult(envelope.result);

        // Cache speichern (nur read-only)
        if (useCache) {
          const cacheKey = `${ability}:${JSON.stringify(params)}`;
          this._cache.set(cacheKey, {
            data: result,
            expiry: Date.now() + this._cacheTtl,
          });
        }

        return result;

      } catch (err) {
        // Keine Retry bei JSON-Parse-Fehlern (kein Server-Problem)
        if (err instanceof SyntaxError) {
          throw new Error(`Invalid JSON response für "${ability}": ${err.message}`);
        }

        // Keine Retry bei AbortError/Timeout
        if (err.name === 'TimeoutError' || err.name === 'AbortError') {
          throw new Error(`Timeout bei "${ability}" nach ${this.timeout}ms`);
        }

        // Letzter Versuch — REST-Fallback versuchen
        if (attempt >= maxRetries) {
          try {
            process.stderr.write(`[mcp-bridge] JSON-RPC fehlgeschlagen, versuche REST-Fallback für "${ability}"...\n`);
            const restResult = await this._wpRestCall(ability, params);
            // Bei Erfolg: Cache speichern (wenn erlaubt)
            if (useCache) {
              const cacheKey = `${ability}:${JSON.stringify(params)}`;
              this._cache.set(cacheKey, {
                data: restResult,
                expiry: Date.now() + this._cacheTtl,
              });
            }
            return restResult;
          } catch (_restErr) {
            // Beide Pfade fehlgeschlagen — ursprünglichen JSON-RPC Fehler werfen
            process.stderr.write(`[mcp-bridge] REST-Fallback ebenfalls fehlgeschlagen: ${_restErr.message.slice(0, 150)}\n`);
          }
          throw err;
        }

        // Retry mit Backoff
        const delay = Math.min(1000 * Math.pow(2, attempt), 8000);
        process.stderr.write(`[mcp-bridge] Retry ${attempt + 1}/${maxRetries} in ${delay}ms: ${err.message.slice(0, 120)}\n`);
        await new Promise(r => setTimeout(r, delay));
      }
    }
  }

  /**
   * Parst das Ergebnis eines tools/call Responses.
   *
   * tools/call gibt zurück:
   *   { content: [{ type: "text", text: "<JSON-String>" }] }
   *
   * Der text-Block enthält das eigentliche Ability-Ergebnis.
   */
  _parseToolResult(result) {
    if (!result) return null;

    const content = result.content;
    if (!Array.isArray(content)) return result;

    // Suche nach text-Blöcken
    const textBlocks = content.filter(b => b.type === 'text');

    if (textBlocks.length === 0) return result;
    if (textBlocks.length === 1) {
      const block = textBlocks[0];
      try {
        return JSON.parse(block.text);
      } catch {
        return block.text; // Plain-Text Response
      }
    }

    // Mehrere text-Blöcke — versuche zu mergen
    const parsed = textBlocks.map(b => {
      try { return JSON.parse(b.text); }
      catch { return b.text; }
    });

    // Wenn alle Objekte sind, merge sie
    if (parsed.every(p => typeof p === 'object' && p !== null && !Array.isArray(p))) {
      return Object.assign({}, ...parsed);
    }

    return parsed.length === 1 ? parsed[0] : parsed;
  }

  /**
   * Führt eine Sequenz von Calls aus (sequentiell).
   *
   * @param {Array<{ability: string, params?: object}>} calls
   * @param {object} [options]
   * @param {boolean} [options.stopOnError=false] Bei true: erster Fehler wirft sofort
   * @returns {Promise<Array<*>>} Array der Ergebnisse
   */
  async callSequence(calls, options = {}) {
    const stopOnError = options.stopOnError === true;
    const results = [];
    for (const item of calls) {
      try {
        const result = await this.call(item.ability, item.params || {});
        results.push(result);
      } catch (err) {
        process.stderr.write(`[mcp-bridge] callSequence: "${item.ability}" fehlgeschlagen: ${err.message}\n`);
        if (stopOnError) throw err;
        results.push({ __error: err.message, ability: item.ability });
      }
    }
    return results;
  }

  /**
   * Führt mehrere MCP-Calls mit konfigurierbarem Concurrency-Limit parallel aus.
   *
   * Alle Calls laufen parallel, aber maximal `concurrency` Calls sind
   * gleichzeitig aktiv. Dies verhindert Race-Conditions und PHP-Timeout
   * bei lokalen WordPress-Instanzen ohne Load-Balancer (FIX-7).
   *
   * Ohne Concurrency-Limit feuert Promise.allSettled ALLE Calls simultan —
   * bei 10+ Requests gegen solar.local kann das zu PHP max_execution_time-
   * Abbrüchen oder Session-Timeout führen.
   *
   * Der interne Worker-Pool verwendet kein externes Package (p-limit) —
   * die Concurrency-Steuerung ist mit ~20 Zeilen selbst implementiert.
   *
   * Nutze callParallel() für unabhängige Pre-Build-Schritte (z.B. parallel-pre-build.js).
   * Nutze callSequence() wenn Calls voneinander abhängen oder serielle Ausführung nötig ist.
   *
   * @param {Array<{ability: string, params?: object}>} calls
   * @param {object} [options]
   * @param {number} [options.concurrency=5]  Maximale Anzahl paralleler Calls
   * @returns {Promise<Array<{status: 'fulfilled'|'rejected', value?: any, reason?: any, ability: string}>>}
   */
  async callParallel(calls, options = {}) {
    if (!Array.isArray(calls) || calls.length === 0) return [];

    const concurrency = Math.max(1, options.concurrency ?? this.defaultConcurrency ?? 5);

    process.stderr.write(
      `[mcp-bridge] callParallel: ${calls.length} calls gestartet (concurrency=${concurrency})\n`
    );

    const start = Date.now();
    const results = new Array(calls.length);
    let cursor = 0;

    // Worker: greift den nächsten Call aus der Queue und führt ihn aus
    const worker = async () => {
      while (cursor < calls.length) {
        const idx = cursor++;
        const { ability, params = {} } = calls[idx];
        try {
          const value = await this.call(ability, params);
          results[idx] = { status: 'fulfilled', value, ability };
        } catch (reason) {
          results[idx] = { status: 'rejected', reason, ability };
        }
      }
    };

    // Starte `concurrency` Worker parallel
    const workers = Array.from(
      { length: Math.min(concurrency, calls.length) },
      () => worker()
    );
    await Promise.all(workers);

    const ms = Date.now() - start;
    const failed = results.filter(r => r.status === 'rejected').length;
    process.stderr.write(
      `[mcp-bridge] callParallel: fertig in ${ms}ms ` +
      `(${calls.length - failed} ok, ${failed} fehler, concurrency=${concurrency})\n`
    );
    return results;
  }

  // ── Spezialisierte Methoden ──────────────────────────────────────────────

  /**
   * REST-Endpoint-Map für WP REST API Fallback.
   *
   * Wenn der JSON-RPC 2.0 Pfad fehlschlägt, kann für bestimmte Abilities
   * ein direkter WP REST API Endpoint als Fallback verwendet werden.
   *
   * Hinweis: Diese Endpoints sind Schätzungen basierend auf Novamira REST-Routen.
   * Das Plugin muss die entsprechenden Routen registriert haben.
   * Wenn nicht: nur der JSON-RPC Pfad funktioniert.
   */
  static _REST_ENDPOINT_MAP = {
    // Core Elementor
    'novamira/elementor-set-content': (p) => ({
      url: `/wp-json/novamira/v1/elementor/set-content`,
      method: 'POST', body: { post_id: p.post_id, content: p.content },
    }),
    'novamira/elementor-get-content': (p) => ({
      url: `/wp-json/novamira/v1/elementor/get-content/${p.post_id}`,
      method: 'GET',
    }),

    // Design System
    'novamira/adrians-export-design-system': (p) => ({
      url: `/wp-json/novamira/v1/design-system/export${p.what ? `?what=${encodeURIComponent(p.what)}` : ''}`,
      method: 'GET',
    }),

    // Media
    'novamira/adrians-media-upload': (p) => ({
      url: '/wp-json/novamira/v1/media/upload',
      method: 'POST', body: p,
    }),
    'novamira/adrians-batch-media-upload': (p) => ({
      url: '/wp-json/novamira/v1/media/batch-upload',
      method: 'POST', body: p,
    }),

    // Foundation (cache-verboten)
    'novamira/adrians-setup-v4-foundation': (p) => ({
      url: '/wp-json/novamira/v1/elementor/foundation',
      method: 'POST', body: p,
    }),

    // QA & Audit
    'novamira/adrians-layout-audit': (p) => ({
      url: `/wp-json/novamira/v1/elementor/layout-audit/${p.post_id}`,
      method: 'GET',
    }),
    'novamira/adrians-visual-qa': (p) => ({
      url: `/wp-json/novamira/v1/elementor/visual-qa/${p.post_id}`,
      method: 'GET',
    }),
    'novamira/adrians-responsive-audit': (p) => ({
      url: `/wp-json/novamira/v1/elementor/responsive-audit/${p.post_id}`,
      method: 'GET',
    }),
    'novamira/adrians-variable-audit': (p) => ({
      url: '/wp-json/novamira/v1/elementor/variable-audit',
      method: 'POST', body: p,
    }),

    // Variables
    'novamira/adrians-batch-create-variables': (p) => ({
      url: '/wp-json/novamira/v1/elementor/variables/batch',
      method: 'POST', body: p,
    }),

    // Global Classes
    'novamira/adrians-add-global-class-variant': (p) => ({
      url: '/wp-json/novamira/v1/elementor/class-variant',
      method: 'POST', body: p,
    }),
    'novamira/adrians-apply-variable-to-class': (p) => ({
      url: '/wp-json/novamira/v1/elementor/class-variable',
      method: 'POST', body: p,
    }),
  };

  /**
   * WP REST API Fallback — wird aufgerufen wenn JSON-RPC 2.0 fehlschlägt.
   *
   * Prüft ob für die Ability ein REST-Endpoint registriert ist und
   * führt einen direkten WP REST API Call aus.
   *
   * Kein Retry: der primäre JSON-RPC Pfad hat bereits alle Retries ausgeschöpft.
   * Der REST-Call ist ein letzter Versuch (one-shot).
   *
   * @param {string} ability   Ability-Name
   * @param {object} params    Ability-Parameter
   * @returns {Promise<*>} Parsed Response
   * @throws {Error} Wenn kein REST-Endpoint existiert oder der Call fehlschlägt
   */
  async _wpRestCall(ability, params) {
    const endpointFn = McpBridge._REST_ENDPOINT_MAP[ability];
    if (!endpointFn) {
      throw new Error(`Kein REST-Endpoint für "${ability}" registriert`);
    }

    const endpoint = endpointFn(params);
    const url = `${this.wpUrl}${endpoint.url}`;

    process.stderr.write(`[mcp-bridge] REST-Fallback: ${endpoint.method} ${endpoint.url}\n`);

    const httpsAgent = this.wpUrl.startsWith('https')
      ? this._getHttpsAgent()
      : null;

    const fetchOpts = {
      method: endpoint.method,
      headers: {
        'Accept': 'application/json',
        ...this._getAuthHeaders(),
      },
      signal: AbortSignal.timeout(this.timeout),
    };

    // Body nur bei POST/PUT/PATCH
    if (endpoint.body && endpoint.method !== 'GET') {
      fetchOpts.headers['Content-Type'] = 'application/json';
      fetchOpts.body = JSON.stringify(endpoint.body);
    }

    if (httpsAgent) {
      fetchOpts.agent = httpsAgent;
    }

    const res = await fetch(url, fetchOpts);

    if (!res.ok) {
      const text = await res.text().catch(() => '');
      throw new Error(
        `REST HTTP ${res.status} für "${ability}": ${text.slice(0, 300)}`
      );
    }

    const contentType = res.headers.get('content-type') || '';
    if (contentType.includes('application/json')) {
      return res.json();
    }

    const text = await res.text();
    try {
      return JSON.parse(text);
    } catch {
      return text;
    }
  }

  // ── Spezialisierte Methoden ──────────────────────────────────────────────

  /**
   * Batch-Media-Upload via novamira/adrians-batch-media-upload.
   *
   * @param {Array<{filename: string, mime_type: string, content_base64: string}>} files
   * @returns {Promise<object>} Upload-Ergebnis mit wp_media_id-Mappings
   */
  async batchMediaUpload(files) {
    if (!Array.isArray(files) || files.length === 0) {
      return { results: [] };
    }

    this._log(`Batch-Media-Upload: ${files.length} Dateien...`);

    return this.call('novamira/adrians-batch-media-upload', { files }, {
      cache: false,
      maxRetries: 1, // Weniger Retries für Uploads (Datenvolumen)
    });
  }

  // ── Internal Helpers ─────────────────────────────────────────────────────

  /**
   * Baut den Authorization-Header.
   *
   * @returns {object} Headers-Objekt (leer wenn keine Auth konfiguriert)
   */
  _getAuthHeaders() {
    if (this._authHeader) {
      return { Authorization: this._authHeader };
    }
    return {};
  }

  /**
   * Strukturiertes Logging (auf stderr um stdout sauber zu halten).
   *
   * @param {string} message
   */
  _log(message) {
    if (this.verbose || process.env.MCP_VERBOSE === '1') {
      process.stderr.write(`[mcp-bridge] ${message}\n`);
    }
  }
}

export default McpBridge;

// ── Self-Test ─────────────────────────────────────────────────────────────────
// node scripts/lib/mcp-bridge.js --self-test

if (process.argv.includes('--self-test')) {
  (async () => {
    console.log(`
╔══════════════════════════════════════════════════════════════╗
║       framer-v4-pipeline-v2 — MCP Bridge v4.0.0             ║
║       Fix A: JSON-RPC 2.0 + Session-Handshake              ║
╚══════════════════════════════════════════════════════════════╝
`);

    // 1. Config-Discovery
    const configPath = findMcpConfig();
    if (configPath) {
      console.log(`✅ Config gefunden: ${configPath}`);
    } else {
      console.log('⚠️  Keine .mcp.json gefunden — prüfe Umgebungsvariablen.');
    }

    // 2. Bridge initialisieren
    let bridge;
    try {
      bridge = await McpBridge.fromConfig();
      console.log(`✅ Bridge initialisiert`);
      console.log(`   MCP URL: ${bridge.mcpUrl}`);
      console.log(`   Auth:    ${bridge._authHeader ? 'Konfiguriert' : 'NICHT konfiguriert'}`);
    } catch (err) {
      console.log(`❌ Bridge-Init fehlgeschlagen: ${err.message}`);
      console.log(`
📋 Konfigurations-Guide:
   Erstelle eine .mcp.json im Projekt-Root:

   {
     "mcpServers": {
       "novamira-solar-local": {
         "url": "http://solar.local/wp-json/mcp/novamira",
         "headers": {
           "Authorization": "Basic <base64-von-user:app-password>"
         }
       }
     }
   }

   ODER setze Umgebungsvariablen:
   WP_API_URL=http://solar.local/wp-json/mcp/novamira
   WP_API_USERNAME=Adrian
   WP_API_PASSWORD=<app-password>
`);
      process.exit(1);
    }

    // 3. Verbindungstest via greet
    console.log('\n🔌 Teste Verbindung (novamira/adrians-greet)...');
    try {
      const greeting = await bridge.call('novamira/adrians-greet', { name: 'Pipeline-Smoke-Test' });
      console.log(`✅ Verbindung OK: ${JSON.stringify(greeting).slice(0, 200)}`);
    } catch (err) {
      console.log(`❌ Verbindungstest fehlgeschlagen: ${err.message}`);
      console.log(`
🔧 Troubleshooting:
   1. Läuft der MCP-Server auf solar.local?
   2. Ist das App-Password gültig? (WordPress → Benutzer → Application Passwords)
   3. TLS-Problem bei https? Setze NODE_TLS_REJECT_UNAUTHORIZED=0
   4. Firewall/Netzwerk: Kann dein Rechner solar.local erreichen?
`);
      process.exit(2);
    }

    // 4. Cache-Test
    console.log('\n📦 Teste Cache (export-design-system — read-only)...');
    const startCached = Date.now();
    await bridge.call('novamira/adrians-export-design-system', {});
    const cachedDuration = Date.now() - startCached;
    console.log(`   Erster Call: ${cachedDuration}ms`);

    const startCached2 = Date.now();
    await bridge.call('novamira/adrians-export-design-system', {});
    const cachedDuration2 = Date.now() - startCached2;
    console.log(`   Zweiter Call: ${cachedDuration2}ms ${cachedDuration2 < 100 ? '(✅ gecacht)' : '(⚠️ nicht gecacht)'}`);

    console.log('\n✅ Alle Checks bestanden — MCP Bridge ist bereit.\n');
    process.exit(0);
  })();
}
