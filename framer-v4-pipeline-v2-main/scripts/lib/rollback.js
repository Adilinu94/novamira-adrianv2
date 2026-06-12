#!/usr/bin/env node
/**
 * scripts/lib/rollback.js — Phase 1.2 Fix: MCP-Plan-Generator
 *
 * Rollback-Modul für den Elementor V4 Build.
 * Sichert den aktuellen Seiteninhalt VOR dem Build und stellt ihn
 * bei Fehlern automatisch wieder her.
 *
 * ARCHITEKTUR (v0.6.0+):
 *   Alle MCP-Calls laufen über den Claude-Agenten. Dieses Modul
 *   generiert MCP-Execution-Pläne (JSON), die der Agent ausführt.
 *   Lokale Backup-Dateien werden unabhängig davon geschrieben.
 *
 * Workflow:
 *   1. backupPlan(postId) → Generiert MCP-Plan für elementor-get-content
 *      + novamira-adrianv2/page-settings, speichert Backup lokal.
 *   2. restorePlan(postId) → Generiert MCP-Plan für elementor-set-content
 *      aus dem lokalen Backup.
 *   3. discardBackup(postId) → Löscht lokales Backup.
 *
 * Usage:
 *   import { RollbackManager } from './lib/rollback.js';
 *   const rb = new RollbackManager();
 *   const plan = await rb.backupPlan(postId, { content: {...}, pageSettings: {...} });
 *   // Agent führt plan.mcp_calls aus
 *   await rb.discardBackup(postId);
 */

import fs from 'node:fs';
import path from 'node:path';

export class RollbackManager {
  constructor(rollbackDir = null) {
    this.dir = rollbackDir || path.resolve(process.cwd(), '.rollback');
    this._ensureDir();
  }

  _ensureDir() {
    if (!fs.existsSync(this.dir)) {
      fs.mkdirSync(this.dir, { recursive: true });
    }
  }

  _backupPath(postId) {
    return path.join(this.dir, `backup-${postId}.json`);
  }

  _backupExists(postId) {
    return fs.existsSync(this._backupPath(postId));
  }

  /**
   * Generates an MCP execution plan for backing up a page.
   *
   * The agent must execute the returned mcp_calls BEFORE the build.
   * Results should be passed to backupPlan() as agentResults.
   *
   * @param {number} postId
   * @param {object} [agentResults] - Results from agent-executed MCP calls.
   * @param {object} [agentResults.getContent] - Result of novamira/elementor-get-content.
   * @param {object} [agentResults.pageSettings] - Result of novamira-adrianv2/page-settings.
   * @returns {object} { plan: { mcp_calls, agent_instruction }, backup: object|null }
   */
  backupPlan(postId, agentResults = null) {
    // If agent already provided results, persist them
    if (agentResults) {
      const content      = agentResults.getContent?.content
        || agentResults.getContent?.data?.content
        || agentResults.getContent
        || [];
      const pageSettings = agentResults.pageSettings?.settings
        || agentResults.pageSettings?.data?.settings
        || agentResults.pageSettings
        || null;

      const backup = {
        postId,
        timestamp: new Date().toISOString(),
        content: Array.isArray(content) ? content : [],
        pageSettings,
        elementCount: this._countElements(content),
      };

      fs.writeFileSync(this._backupPath(postId), JSON.stringify(backup, null, 2), 'utf8');
      process.stderr.write(`[rollback] Backup saved for post ${postId} (${backup.elementCount} elements)\n`);

      return {
        plan: null, // already persisted
        backup,
      };
    }

    // Agent hasn't provided results yet — return execution plan
    const plan = {
      step: 'rollback-backup',
      description: `Sichere Post ${postId} vor dem Build für Rollback`,
      mcp_calls: [
        {
          ability: 'novamira/elementor-get-content',
          params: { post_id: postId, full_dump: true },
          save_as: 'getContent',
          description: 'Hole aktuellen Elementor-Inhalt',
        },
        {
          ability: 'novamira-adrianv2/page-settings',
          params: { post_id: postId },
          save_as: 'pageSettings',
          description: 'Hole Page-Settings (optional, nicht kritisch)',
        },
      ],
      agent_instruction: `
Führe beide MCP-Calls aus und übergib die Ergebnisse an RollbackManager.backupPlan(postId, agentResults).
Der agentResults-Parameter erwartet:
  {
    getContent: <ergebnis von elementor-get-content>,
    pageSettings: <ergebnis von novamira-adrianv2/page-settings>
  }
`.trim(),
    };

    process.stderr.write(`[rollback] MCP backup plan generated for post ${postId}\n`);
    return { plan, backup: null };
  }

  /**
   * Generates an MCP execution plan for restoring a backup.
   *
   * @param {number} postId
   * @returns {object} { plan: { mcp_calls, agent_instruction } }
   */
  restorePlan(postId) {
    const backupPath = this._backupPath(postId);
    if (!fs.existsSync(backupPath)) {
      return {
        plan: null,
        error: `No backup found for post ${postId} at ${backupPath}`,
      };
    }

    const backup = JSON.parse(fs.readFileSync(backupPath, 'utf8'));
    const content = Array.isArray(backup.content) ? backup.content : [];

    process.stderr.write(`[rollback] Restore plan for post ${postId} (${backup.elementCount || 0} elements)\n`);

    return {
      plan: {
        step: 'rollback-restore',
        description: `Stelle Post ${postId} aus Backup wieder her`,
        mcp_calls: [
          {
            ability: 'novamira/elementor-set-content',
            params: {
              post_id: postId,
              content,
            },
            description: `Rollback: stelle ${content.length} Top-Level-Elemente wieder her`,
          },
        ],
        // Include page settings restore if available
        ...(backup.pageSettings ? {
          page_settings_restore: {
            ability: 'novamira-adrianv2/page-settings',
            params: {
              post_id: postId,
              settings: backup.pageSettings,
            },
            note: 'Optionales Restore der Page-Settings',
          },
        } : {}),
        agent_instruction: 'Führe elementor-set-content aus, um das Rollback durchzuführen.',
      },
      backup,
    };
  }

  /**
   * Löscht ein Backup nach erfolgreichem Build.
   * @param {number} postId
   */
  discardBackup(postId) {
    const backupPath = this._backupPath(postId);
    if (fs.existsSync(backupPath)) {
      fs.unlinkSync(backupPath);
      process.stderr.write(`[rollback] Backup discarded for post ${postId}\n`);
    }
  }

  /**
   * Lädt ein gespeichertes Backup (nur lokal, kein MCP-Call).
   * @param {number} postId
   * @returns {object|null}
   */
  loadBackup(postId) {
    const backupPath = this._backupPath(postId);
    if (!fs.existsSync(backupPath)) return null;
    return JSON.parse(fs.readFileSync(backupPath, 'utf8'));
  }

  /**
   * Listet alle vorhandenen Backups.
   * @returns {Array<{postId, timestamp, elementCount}>}
   */
  listBackups() {
    if (!fs.existsSync(this.dir)) return [];
    const files = fs.readdirSync(this.dir).filter(f => f.startsWith('backup-') && f.endsWith('.json'));
    return files.map(f => {
      try {
        const data = JSON.parse(fs.readFileSync(path.join(this.dir, f), 'utf8'));
        return { postId: data.postId, timestamp: data.timestamp, elementCount: data.elementCount };
      } catch {
        return null;
      }
    }).filter(Boolean);
  }

  /**
   * Prüft ob ein Backup für eine Post-ID existiert.
   * @param {number} postId
   * @returns {boolean}
   */
  hasBackup(postId) {
    return this._backupExists(postId);
  }

  /**
   * Zählt Elemente rekursiv im Elementor-Baum.
   */
  _countElements(tree) {
    if (!Array.isArray(tree)) return 0;
    let count = 0;
    for (const el of tree) {
      count++;
      if (el.elements || el.children) {
        count += this._countElements(el.elements || el.children);
      }
    }
    return count;
  }

  /**
   * Bereinigt Backups älter als maxAgeHours Stunden.
   *
   * @param {number} [maxAgeHours=24] - Maximales Backup-Alter in Stunden.
   * @returns {{ deleted: number, kept: number, files: string[] }}
   */
  cleanupOldBackups(maxAgeHours = 24) {
    const cutoff = Date.now() - (maxAgeHours * 60 * 60 * 1000);
    const backups = this.listBackups();
    let deleted = 0;
    const kept = [];

    for (const backup of backups) {
      const age = backup.timestamp ? new Date(backup.timestamp).getTime() : 0;
      if (age < cutoff) {
        const backupPath = this._backupPath(backup.postId);
        try {
          fs.unlinkSync(backupPath);
          deleted++;
          process.stderr.write(
            `[rollback] Deleted old backup for post ${backup.postId} ` +
            `(${Math.round((Date.now() - age) / 3600000)}h old)\n`
          );
        } catch (err) {
          process.stderr.write(`[rollback] Failed to delete ${backupPath}: ${err.message}\n`);
        }
      } else {
        kept.push(backup.postId);
      }
    }

    // ── Zusaetzlich: Verwaiste korrupte Backups anhand Datei-mtime loeschen ──
    if (fs.existsSync(this.dir)) {
      const allFiles = fs.readdirSync(this.dir).filter(f => f.startsWith('backup-') && f.endsWith('.json'));
      const knownPaths = new Set(backups.map(b => this._backupPath(b.postId)));
      for (const file of allFiles) {
        const filePath = path.join(this.dir, file);
        if (knownPaths.has(filePath)) continue; // already handled
        try {
          const stat = fs.statSync(filePath);
          if (stat.mtimeMs < cutoff) {
            fs.unlinkSync(filePath);
            deleted++;
            process.stderr.write(`[rollback] Deleted orphaned/corrupt backup: ${file} (${Math.round((Date.now() - stat.mtimeMs) / 3600000)}h old)\n`);
          }
        } catch { /* skip unreadable files */ }
      }
    }

    process.stderr.write(
      `[rollback] Cleanup complete: ${deleted} deleted, ${kept.length} kept (< ${maxAgeHours}h)\n`
    );

    return {
      deleted,
      kept: kept.length,
      deleted_ids: backups.filter(b => {
        const age = b.timestamp ? new Date(b.timestamp).getTime() : 0;
        return age < cutoff;
      }).map(b => b.postId),
      kept_ids: kept,
    };
  }
}

export default RollbackManager;

// ── CLI: Cleanup aufrufen ────────────────────────────────────────────────────

if (process.argv.includes('--cleanup')) {
  const hoursIdx = process.argv.indexOf('--max-age');
  const maxAge = hoursIdx !== -1 ? parseInt(process.argv[hoursIdx + 1], 10) || 24 : 24;

  const rb = new RollbackManager();
  const result = rb.cleanupOldBackups(maxAge);
  process.stdout.write(JSON.stringify(result, null, 2) + '\n');
  process.exit(0);
}
