# V3 → V4 Entscheidungsbaum

> **Stand:** 2026-06-21  
> **Plugin:** novamira-adrianv2  
> **Zweck:** KI-Agent Entscheidungshilfe — welche Abilities wann aufrufen

---

## Haupt-Entscheidungsbaum

```
START: User will eine Seite bearbeiten / erstellen / konvertieren
│
├── 1. IMMER ZUERST: Fähigkeiten entdecken
│   └── mcp-adapter-discover-abilities
│       └── ✅ Abilities-Liste bekannt → weiter
│
├── 2. Elementor-Version prüfen
│   └── novamira-adrianv2/detect-elementor-version { post_id: X }
│       │
│       ├── V4 (Elementor 4.0+)
│       │   ├── Kit bereits konvertiert?
│       │   │   ├── JA → Schritt 5 (Seiten-Workflow)
│       │   │   └── NEIN → Schritt 3 (Kit-Konvertierung)
│       │   └── Neue Seite bauen? → Schritt 6 (V4 Build)
│       │
│       └── V3 (Elementor < 4.0)
│           ├── Konvertierung gewünscht? 
│           │   ├── JA → Schritt 3 (Migrations-Workflow)
│           │   └── NEIN → Schritt 7 (V3 Edit Workflow)
│           └── Elementor < 4.0 → V4 nicht verfügbar, nur V3-Editing
│
│
├── 3. KIT KONVERTIERUNG (Design System → Global Variables)
│   ├── ⚠️ NUR EINMAL pro Site/Kit aufrufen!
│   ├── novamira-adrianv2/kit-convert-v3-to-v4 { dry_run: true }
│   ├── → Ausgabe prüfen: variable_map + class_map
│   ├── novamira-adrianv2/kit-convert-v3-to-v4 { dry_run: false, strategy: "rename" }
│   ├── → variable_map SICHERN (für alle weiteren Seiten-Konvertierungen)
│   └── → weiter zu Schritt 4
│
│
├── 4. V4 FOUNDATION SETUP
│   ├── novamira-adrianv2/setup-v4-foundation {}
│   ├── → base_classes (e-flexbox-base, e-div-block-base) erhalten
│   ├── → quick_ref SICHERN
│   └── → weiter zu Schritt 5
│
│
├── 5. SEITE KONVERTIEREN (V3 → V4)
│   ├── Test-Kopie anlegen (empfohlen)
│   │   └── novamira-adrianv2/duplicate-page { source_id: X }
│   │       └── → neue Post-ID erhalten
│   │
│   ├── Pre-Audit
│   │   ├── novamira-adrianv2/audit-layout { post_id: X }
│   │   └── novamira-adrianv2/audit-page { post_id: X }
│   │
│   ├── Dry-Run Konvertierung
│   │   └── novamira-adrianv2/convert-page-v3-to-v4 {
│   │           post_id: X,
│   │           dry_run: true,
│   │           variable_map: {...},  ← aus Schritt 3
│   │           unknown_widget_strategy: "keep_v3"
│   │       }
│   │       ├── Warnings vorhanden?
│   │       │   ├── Kritisch → Abbrechen / manuell prüfen
│   │       │   └── Nicht-Kritisch → Fortfahren
│   │       └── Audit-Issues prüfen
│   │
│   ├── Live-Konvertierung
│   │   └── novamira-adrianv2/convert-page-v3-to-v4 {
│   │           post_id: X,
│   │           dry_run: false
│   │       }
│   │
│   ├── Base Classes zuweisen
│   │   └── novamira-adrianv2/batch-class {
│   │           post_id: X,
│   │           element_class_map: { "CONTAINER_ID": ["gc-e8dfc2c41ef4cc02"] }
│   │       }
│   │
│   └── Post-Audit
│       ├── novamira-adrianv2/audit-layout { post_id: X }
│       ├── novamira-adrianv2/audit-class { post_id: X }
│       └── novamira-adrianv2/audit-visual-qa { post_id: X }
│
│
├── 6. NEUE SEITE BAUEN (V4 Atomic)
│   ├── Voraussetzung: Foundation vorhanden (Schritt 4)
│   ├── Structure planen
│   │   └── Abschnitte definieren: Hero, Features, CTA, Footer...
│   ├── Page bauen
│   │   └── novamira-adrianv2/batch-build-page {
│   │           post_id: X,
│   │           elements: [ ... V4 Atomic Element-Tree ... ]
│   │       }
│   ├── Global Classes zuweisen
│   │   └── novamira-adrianv2/batch-class { ... }
│   └── Audit
│       └── novamira-adrianv2/audit-layout + audit-visual-qa
│
│
└── 7. V3 SEITE BEARBEITEN (ohne Konvertierung)
    ├── Content lesen
    │   └── novamira/elementor-get-content { post_id: X }
    ├── Änderungen vornehmen
    │   └── novamira-adrianv2/patch-element-styles { ... }
    └── Schreiben
        └── novamira/elementor-set-content { post_id: X, data: [...] }
```

---

## Kurzreferenz: Welche Ability für welchen Use-Case?

| Use-Case | Primäre Ability | Parameter |
|----------|----------------|-----------|
| Site-Version prüfen | `detect-elementor-version` | `{}` |
| Kit zu V4 migrieren | `kit-convert-v3-to-v4` | `{dry_run: true/false}` |
| Seite zu V4 migrieren | `convert-page-v3-to-v4` | `{post_id, dry_run, variable_map}` |
| Alle V3-Seiten migrieren | `convert-site-v3-to-v4` | `{dry_run, limit}` |
| Foundation einrichten | `setup-v4-foundation` | `{}` |
| Neue V4-Seite bauen | `batch-build-page` | `{post_id, elements}` |
| Seite duplizieren (sicher) | `duplicate-page` | `{source_id}` |
| Global Classes setzen | `batch-class` | `{post_id, element_class_map}` |
| Layout audit | `audit-layout` | `{post_id}` |
| Visual QA | `audit-visual-qa` | `{post_id}` |
| V3-Seite lesen | `novamira/elementor-get-content` | `{post_id}` |
| Rollback | `rollback-build` | `{post_id}` |

---

## Kritische Warnung: Kit-Convert nur EINMAL

```
⚠️  kit-convert-v3-to-v4 generiert neue e-gv-* IDs bei JEDEM Aufruf!
    → Zweimaliger Aufruf = andere IDs = Inkonsistenz im Design-System

    REGEL: Kit nur 1× konvertieren, variable_map in der Session speichern,
           für ALLE Seiten-Konvertierungen dieselbe variable_map verwenden.
```

---

## Fehlerfall-Entscheidungen

| Problem | Symptom | Lösung |
|---------|---------|--------|
| Converter nicht gefunden | PHP Fatal: Class not found | helpers/bootstrap.php prüfen — alle Converter sind dort require_once'd |
| Farben nicht als GV-Referenzen | Inline `$$type:color` statt `global-color-variable` | variable_map an convert-page-v3-to-v4 übergeben |
| CSS nicht generiert | Seite rendert ohne Styles | Elementor 4.1.3 Atomic-CSS-Pipeline-Limitation; siehe `docs/atomic-css-pipeline.md` |
| Widget bleibt V3 | `kept_v3` in Stats | V4-Äquivalent existiert nicht; manuell ersetzen oder `keep_v3` akzeptieren |
| Audit-Issues nach Konvertierung | `e-flexbox contains direct widgets` | Wrap-Fix bereits im Converter: `wrap_direct_widget_children()` |

---

*Dokument erstellt 2026-06-21. Basiert auf Code-Review und E2E-Test-Ergebnissen.*

---

## KRITISCHE LEARNINGS (2026-06 – V3→V4 Live-Test)

### Bug #1: Falscher elType für e-flexbox
**FALSCH:** `{ "elType": "widget", "widgetType": "e-flexbox" }` → rendert NICHT
**RICHTIG:** `{ "elType": "e-flexbox" }` – kein widgetType für Container!

**Warum:** In V4 Atomic sind Container kein `elType: "widget"`. Sie haben `elType: "e-flexbox"` direkt.
Gleiche gilt für `e-div-block`.

### Bug #2: `$$type` in `bash -e "..."` wird zu `[PID]type`
```bash
# FALSCH - $$ = Prozess-ID (z.B. 556) → "556type"
node -e "const x = {'$$type':'classes','value':[]}"
# → {"556type":"classes","value":[]}

# RICHTIG - immer .js Datei nutzen!
cat > /tmp/gen.js << 'EOF'
const x = {"$$type":"classes","value":[]}
EOF
node /tmp/gen.js
```

### Bug #3: `document->save(['elements'=>$data])` verliert V4 Daten
```php
// FALSCH - V4 Daten verschwinden:
$document->save(['elements' => $data]);

// RICHTIG:
update_post_meta($post_id, '_elementor_data', wp_slash(json_encode($data)));
\Elementor\Plugin::$instance->files_manager->clear_cache();
```

### Unsupported V3 Widgets (convert-page-v3-to-v4 behält als V3):
- `counter`, `rating`, `icon-list`, `icon-box`, `elementskit-icon-box`
- `elementskit-video`, `elementskit-accordion`, `testimonial`

### Workarounds:
- `counter` → statische `e-heading` ("478+")
- `rating` → `e-heading` mit Stern-Emoji
- `elementskit-video` → `e-youtube`
- `elementskit-accordion` → HTML Widget `<details>/<summary>`
- `icon-list` / `icon-box` → `e-flexbox` + children manuell
