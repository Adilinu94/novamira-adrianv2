---
title: adrianv2-self-audit
description: Pre-build plugin health check: BOM check across all PHP files, strict_types probe, and ability registration count vs expected.
---

# AdrianV2 Skill: Self-Audit (Plugin Health)

> **Plugin:** novamira-adrianv2 (Adrian V2 — V2 wegen "zweites Adrian-Plugin", NICHT Elementor V2)
> **Elementor-Welt:** mixed
> **Required Capabilities:** manage_options
> **Required Abilities:** `novamira-adrianv2/self-audit`

## Wann aktivieren

- **Vor jedem Build** auf einer frisch geupdateten Plugin-Instanz
- Nach `git pull` oder Plugin-Update
- Wenn Abilities fehlen die laut Doku da sein sollten
- Bei "Class not found"-Fehlern während eines Builds

## Was tun

```json
{
  "ability": "novamira-adrianv2/self-audit",
  "parameters": {
    "include_bom_check": true,
    "include_strict_probe": true,
    "include_ability_count": true
  }
}
```

### Output interpretieren

```json
{
  "overall_status": "ok",
  "checks": [
    {
      "name": "bom_check",
      "status": "ok",
      "files_checked": 26,
      "files_with_bom": 0
    },
    {
      "name": "php_strict_probe",
      "status": "ok",
      "details": "Test file with declare(strict_types=1) loaded without fatal"
    },
    {
      "name": "ability_count",
      "status": "ok",
      "expected": 60,
      "actual": 60,
      "missing": [],
      "unauthorized_extra": []
    }
  ]
}
```

| Status | Bedeutung | Aktion |
|--------|-----------|--------|
| `ok` | Alles sauber | Build starten |
| `warning` | Non-blocking Issue | Build trotzdem möglich, aber Issues dokumentieren |
| `error` | Kritisches Problem | Build ABBRECHEN, User informieren |

### Häufige Issues

**BOM-Files:** PHP-Dateien mit UTF-8 BOM (Byte Order Mark) am Anfang. Verursachen "headers already sent"-Fehler und korrumpieren JSON-Responses.

**strict_types-Probe:** Wenn `declare(strict_types=1)` einen Fatal Error wirft, ist der PHP-Parser beschädigt oder eine Extension fehlt.

**Ability-Count-Mismatch:**
- `actual < expected`: Eine oder mehrere Abilities wurden nicht registriert. Ursache: Category fehlt, `class_exists`-Guard schlägt fehl, Namespace-Fehler.
- `actual > expected`: Unerwartete Abilities registriert. Ursache: doppelte Registrierung, Core-Plugin hat neue Abilities.

## Gotchas

- **Self-Audit ist NUR in AdrianV2 1.1.0+**: Vorherige Versionen haben diese Ability nicht.
- **Dauert < 2 Sekunden**: Kein Grund, es zu überspringen.
- **BOM-Check ist 3-Byte-exakt**: `0xEF 0xBB 0xBF` am Dateianfang. Dateien OHNE BOM die trotzdem UTF-8 sind werden NICHT flagged.
- **Auf test4 und Solar IMMER vor Build ausführen**: Die Umgebungen haben unterschiedliche PHP-Konfigurationen.
