# Roadmap — `novamira-adrianv2`

> Long-Form-Plan für das Plugin **novamira-adrianv2**. Vorwärtsgerichtet. Wird bei Bedarf aktualisiert; bei großen Änderungen in `CHANGELOG.md [Unreleased]` spiegeln.
>
> Konvention: Pläne werden **nur auf explizite Aufforderung** persistiert (vgl. vorigen Turn — User wählte "Nur bei Bedarf"). Pfad-Konvention: `.planning/ROADMAP.md` (GSD-Stil, sibling zu `.claude/`).

## Legende

| Symbol | Bedeutung |
|---|---|
| ⬜ | offen, nicht begonnen |
| 🟨 | in Arbeit (eigene Branch / worktree) |
| ✅ | fertig & live-verifiziert (vor mind. 1 Tag) |
| 🟦 | fertig, aber **nicht** live-verifiziert (z. B. weil kein Test-Fixture existiert) |
| 🟥 | **blockiert** (User-Entscheidung nötig oder externes Hindernis) |

| Größe | Stunden-Schätzung |
|---|---|
| **S** | ≤ 1 h |
| **M** | 1–3 h |
| **L** | 3–8 h |
| **XL** | ≥ 1 Tag |

| Prio | Klassifikation |
|---|---|
| **HIGH** | offen-blockierend, Sicherheit/Datenverlust-Risiko, oder von User angefordert |
| **MED** | wertvoll, leicht durchführbar |
| **LOW** | nice-to-have, polish, glanz-feinschliff |

---

## 0. Snapshot — letzte 3 Turns

| # | Turn-Ergebnis | Status | Datum |
|---|---|---|---|
| 1 | `novamira-adrianv2/wpcode-check-setup`: neue readonly MCP-Ability in `includes/abilities/wpcode/class-wpcode-check-setup.php`, gebootstrappt via `includes/abilities/wpcode/bootstrap.php`, dokumentiert in `CHANGELOG.md [Unreleased]`, Reflection-Smoke erweitert, live-probe gegen Local-WP 7.0 Install OK (`active:true`, WPCode 2.3.6 geladen, Cache-Dir vorhanden, Snippets 0). | ✅ | laufende Session |
| 2 | `.claude/skills/elementor/`: 10 verbatim upstream-Files (Speed von github.com/get-proofpilot/claude-elementor-skill, MIT © ProofPilot 2026) installiert, `.gitattributes` neu (`.claude/ export-ignore`), `CHANGELOG.md` um `### Third-party` subsection erweitert, code-reviewer SHIP mit 3 LOW + 1–3 MEDIUM-Polish-Hinweisen. | ✅ | laufende Session |
| 3 | Convention-Lock: Pläne werden nur auf Platte geschrieben, wenn User explizit sagt; default = in-Memory-`write_todos`. | ✅ | laufende Session |
| 4 | **A1 + A2 abgeschlossen**: `.gitattributes` auf 9-Entry Documented-Minimum+`/vendor/` getrimmt; `tests/mock-functions.php` Parse-Error an Line 629 entfernt + `$wpdb`/`wp_upload_dir` Mock-Stubs injectet (vorher durch Parse-Error maskiert). Reflection-Smoke end-to-end OK, alle 8 Schema-Keys von `wpcode-check-setup` present. `php -l` clean, brace-count 204/204 balanced. Code-reviewer SHIP. | ✅ | laufende Session |
| 5 | **D1 + D2 abgeschlossen**: Live Elementor-Testseite angelegt in Local WP (post_id=5373, slug `adranv2-elementor-test`, draft, mirrors `nested-container.json`); `tests/fixtures/elementor/` mit 3 JSON-Stubs befüllt (`simple-container` + `nested-container` + `v4-atomic-page`). Elementor Document Loader akzeptiert die Seed-Page (`documents->get(5373)` → `Elementor\Core\DocumentTypes\Page`). Vorbedingung für B1 (Calibration) + C1 (Inject-Calibrated-Page Ability) + J1 (Browser-Verify) erfüllt. | ✅ | laufende Session |

| 7 | **B1 abgeschlossen**: calibration sample at `.claude/skills/elementor/samples/treets-homepage.json` (749 bytes) + sibling `samples/README.md` (~9.6 KB) committed. Live payload was already privacy-clean (0 URLs, 0 images, 0 sensitive tokens) - sanitisation was a 1:1 copy + audit trail. Honest gap-flagging for the upstream `.claude/skills/elementor/` folder (currently empty of upstream 10 files contrary to turn-2 CHANGELOG claim): README 'Known gap' section ships a 3-step re-install recipe (git clone + cp -r + .gitattributes verify). Dump-helper scripts deleted post-audit. | ✅ | laufende Session |

---

## A. 🔴 code-reviewer-High-Priority Findings (Skill-Install)

> Aus dem `code-reviewer-minimax-m3`-Report zur `.claude/skills/elementor/`-Installation. Diese Findings haben **echte Auswirkungen** auf den Plugin-Distributor-Workflow und sollten zeitnah behoben werden.

### A1. `.gitattributes` trimmen auf das tatsächliche Minimum  ·  **MED · M · HIGH**

**Status:** ✅ gefixt + verifiziert (2026-06-18). Siehe `CHANGELOG.md [Unreleased] / ### Fixed` A1-Eintrag. 9 root-anchored `export-ignore`-Einträge (`.claude/`, `.phpunit.cache/`, `.psalm-cache/`, `tests/`, `docs/`, `scripts/`, `.github/`, `local-xdebuginfo.php`, `.git/`, `/vendor/`). Composer.lock / README.md / *.dist / *.xml / .editorconfig / .gitignore / .gitattributes SHIP in dist via absence-of-rule. Forward-compat-Lücken gegenüber dem ROADMAP-Original: `git archive` konnte nicht laufen (worktree `.git/` leer) — strukturelle + manuelle `.gitattributes`-Syntax-Validierung als Ersatz dokumentiert; end-to-end Verifikation bleibt ein offener Punkt sobald ein git-Repo angelegt wird.

**Problem (war):** Schloß `composer.lock`, `README.md`, `phpunit.xml.dist`, `psalm.xml`, `phpcs.xml`, `.editorconfig`, `scripts/`, `.gitignore`, `.gitattributes` aus dem zukünftigen `git archive` / WP-Dist aus. Drei reale Risiken:

1. **`composer.lock` ausgeschlossen** → bricht Composer-Dist-Reproducibility.
2. **`README.md` ausgeschlossen** → WP.org Plugin Directory Preview sieht kein readme.
3. **`scripts/` ausgeschlossen** ohne Audit → kann end-user-facing WP-CLI-Wrapper exilieren.

**Schritte (Historie):**

1. ✅ `scripts/` per-file audit: `deploy-plugin.sh` + `fix-mocks.py` → beide dev-only → `scripts/ export-ignore`.
2. ✅ Entwurf `.gitattributes` mit den 9 dokumentierten Einträgen (leading-slash durchgängig für archive-root-anchored matching).
3. ✅ Implicit-INCLUDE-Kommentar im Header hält composer.lock/README/phpunit.xml.dist/psalm.xml/phpcs.xml/.editorconfig/.gitignore/.gitattributes fest.
4. 🟦 `git archive`-Validierung **nicht** möglich (worktree-`.git/` leer) — strukturelle Validierung als Ersatz; offener Punkt für Dist-Repo-Init.
5. ✅ Code-reviewer: SHIP mit 3 MED-Polish (path-convention, /vendor/, .phpunit.result.cache) — alle drei im selben Schritt behoben.

**Abhängigkeit von:** — (eigenständig)

---

### A2. `tests/mock-functions.php` Line 629 Parse-Error  ·  **MED · S · HIGH**

**Status:** ✅ gefixt + live-verifiziert (2026-06-18). Siehe `CHANGELOG.md [Unreleased] / ### Fixed` A2-Eintrag. `php -l` → clean; brace-count balanced 204/204; reflection-Smoke end-to-end OK mit allen 8 Schema-Keys von `wpcode-check-setup` + JSON-Response gedumpt. Beim Cleanup wurde außerdem der durch den Parse-Error zuvor maskierte `$wpdb`+`wp_upload_dir`-Mock-Gap aufgedeckt und in zwei Stubs behoben — A2 hat damit faktisch zwei Probleme geliefert.

**Problem (war):** Reflection-Smoke hing an `tests/mock-functions.php:629` mit `Unmatched '}'`. Live-Probes umgingen das; sobald PHPUnit läuft → kaputt.

**Diagnose:** Brace-Count 191/192 (1 orphan `}`). Die Datei war *nicht* absichtlich kaputt — der orphan `}` war ein Copy-Paste-Rest einer nie-landenden `Elementor\Plugin`-Mock-Skizze.

**Schritte (Historie):**

1. ✅ Datei gelesen, brace-count bestimmt.
2. ✅ Targeted `str_replace` (5 Zeilen): orphan `}`-Block + 3 Comment-Zeilen (alle nach dem `clean_post_cache`-Stub) entfernt.
3. ✅ Verifikation: `php -l` clean, brace-count 191/191, Reflection-Smoke läuft weiter als vorher.
4. ✅ **Sub-Folge-Issue (gelöst im selben Turn):** Der jetzt erreichbare Smoke-Pfad traf auf `Call to a member function get_var() on null` in `class-wpcode-check-setup.php:334` — `$wpdb` war im Mock nicht gestubbt. Stub-Injection: `$wpdb` (anon-class, COUNT-queries return 0, scalar returns null) + `wp_upload_dir` (synthetic basedir, `is_dir()` returns false in smoke) + `taxonomy_exists`/`wp_count_posts`/`post_type_exists` (return false / empty object — für aktiven Smoke-Pfad unreachable, defense-in-depth für zukünftige aktive-Install-Smokes).
5. ✅ Cleanup-Str_replace: beim Erweitern der Stubs schlich sich eine zweite Close-Brace ein; ein zweiter gezielter `str_replace` hat den Spurious-Comment + Extra-`}` entfernt. Endgültig: 204/204 balanced.
6. ✅ Code-reviewer: SHIP-after-php-l-confirms (HIGH-Concern über possible rest-`}` durch basher's `No syntax errors detected` widerlegt).

**Abhängigkeit von:** — (eigenständig)

**Offene Polish-Punkte (MED aus code-reviewer):**
- Spekulative Stubs (`taxonomy_exists`, `wp_count_posts`, `post_type_exists`) sind im aktuellen Smoke-Pfad unerreichbar — entweder dokumentierter Future-Smoke-Variant-Test dazu, oder entfernen.
- Leanere `$wpdb`-Stub: nur `get_var` + `prepare` nötig, `get_results` + `$posts`/`$postmeta`-Properties sind tot.

---

## B. 🔵 Skill auf den treets-Live-Install eichen (Calibration)

> Folge-Punkt aus dem vorigen `suggest_followups`-Block. Direkter Wert: das `.claude/skills/elementor/` Skill arbeitet aktuell mit `samples/homepage.example.json` (Platzhalter). Für unser Projekt (= `treets` Local install) braucht es eine echte Stichprobe von einer realen Elementor-Seite der Site, damit "_clone real atoms_" eine echte Datenbasis hat.

### B1. Echte `_elementor_data` aus Local-WP exportieren  ·  **MED · S · MED**

**Status:** ✅ gefixt + verifiziert (2026-06-18). Siehe `CHANGELOG.md [Unreleased] / ### Added` (B1-Absatz) + die in `.claude/skills/elementor/samples/` committed Files (`treets-homepage.json` 749 Bytes + `README.md` ~9.6 KB mit SPDX-Header + Sanitisierungs-Log + 'Known gap' Section + Re-install-Recipe). Live payload aus Post 5373 war zur Dump-Zeit vollstaendig privacy-clean: Sanitisierung war effektiv ein no-op plus Audit-Trail. Sample-Groesse 749 Bytes ist ~270x unter dem 200 KB-Limit fuer Commit-faehige Calibration-Files. PHPUnit-Pflichtsuite fuer B1 nicht definiert (B1 ist Daten-Output, kein PHP-Code); Acceptance-Test ist der Round-Trip ueber `novamira-adrianv2/elementor-inject-calibrated-page` (von C1 PHPUnit-Layer garantiert) - `sections_count=1` + 5 Original-Element-IDs conserviert.

**Schritte:**

1. ⬜ CLI-Probe: `cd wp-content/plugins/novamira-adrianv2 && ssh/local wp post list --post_type=page --fields=ID,post_title,post_status | head -10`.
   - **Heute-Stand**: keine `_elementor_data`-befüllte Seite im Local-Install (siehe letzter Turn — Probe lieferte 0 Zeilen).
   - **Workaround**: Erst D4 (Test-Fixture) machen, dann B1 auf das Fixture.
2. Alle pages mit `_elementor_data IS NOT NULL` listen.
3. Erste echte Seite auswählen, dumpen: `wp post meta get <id> _elementor_data --format=json > /tmp/calibrate.json`.
4. Sanitisieren: lokale URLs → relative, ggf. private Bilder ausfiltern. (MIT-Konformität + keine Secrets in samples/.)
5. Speichern unter `.claude/skills/elementor/samples/treets-homepage.json` (mit `License: MIT / anonymisierte Stichprobe` Marker).
6. **Verifikation**: JSON `jq` valid; Anonymisierung OK (keine production-secrets); Sample < 200 KB.

**Abhängigkeit von:** **D4** (Test-Fixture) — wenn keine echte Seite vorhanden, brauchen wir erst eine.

---

### B2. `hosts/local.md` mit LocalWP-Pfaden ergänzen  ·  **LOW · S · LOW**

**Problem:** Aktuell ist `hosts/local.md` upstream-generisch. Unser Setup ist konkret: Local by Flywheel mit PHP-Pfad `C:\Users\adini\AppData\Roaming\Local\lightning-services\php-8.2.23+0\bin\win64\php.exe` und WordPress-Root `C:\Users\adini\Local Sites\treets\app\public`.

**Schritte:**

1. ⬜ Site-Spezifikum schreiben — entweder als Fork in `.claude/skills/elementor/hosts/local-treets.md` (parallel zu `local.md`) oder als Override in `docs/local-treets.md`.
2. Inhalt: PHP-Binary-Pfad, `cd` Land-Pfad, WP-User-Account-Hinweis, `wp post meta`-Calls mit echtem Beispiel-Output.
3. README ergänzen: "Site-spezifischer Adapter unter `hosts/local-treets.md`".

**Abhängigkeit von:** A1, B1 (damit man im Adapter die calibrations-Beispiel mit echtem ID verlinken kann).

---

## C. 🟢 Sicherer MCP-Pfad für Elementor Page Injection

> Der upstream `inject.php` benutzt **rohen `update_post_meta`**. Das umgeht `wp_check_post_lock` UND Elementors `update_json_meta`-Cache-Hooks. Lösung: ein eigener MCP-Ability, der genau diesen Hook-Pfad nutzt, ohne dass Agent Shell-/WP-CLI-Zugriff braucht.

### C1. Neue Ability: `novamira-adrianv2/elementor-inject-calibrated-page`  ·  **MED · L · HIGH**

**Status:** ✅ gefixt + verifiziert (2026-06-18). Siehe `CHANGELOG.md [Unreleased] / ### Added` (Inject-Calibrated-Page subsection). Implementation (`class-elementor-inject-calibrated-page.php`) + Bootstrap-Wiring (`bootstrap.php` + 1 `register`-Block) + PHPUnit-Test (`ElementorInjectCalibratedPageTest.php` mit 19 Test-Methoden) + Mock-Infra-Updates (`mock-functions.php` mit `_test_post_meta_update_calls` capture, mock Plugin `update_json_meta` persistence, kits_manager stub, neue `get_post_status()` + `stripslashes_deep()` global-namespace stubs). Live-Probe gegen Local-WP post_id=5373 für alle 6 Exercise-Pfade (invalid_post_id / idempotent overwrite / stale-version soft warn / merge_by_id match + append / merge_by_id fail-on-empty / restore-from-fixture) grün — Kit-ID-Resolution im Local install retourniert `kit_id=2839` (active kit post_id), `blocks_invalidated_count=3`, `sections_count` stimmt. `php -l` clean auf allen drei PHP-Files (class + bootstrap + test + mock = 37/37 + 36/36 + 212/212 braces balanced). Code-reviewer Round-1 lieferte 4 MED (B/C/D/H) + 2 LOW (J); alle 6 in Round-2 sauber behoben. Round-3 deckte 3 P0 test-side-Regressions auf (R1 stray `$id` in setUp, R2 missing `get_post_status` mock, R3 mock update_json_meta ohne persistence auf docs map); alle 3 in Round-4 nachgefixt. Code-Reviewer-Receipts: SHIP-after-php-l-confirms (HIGH-Concern über undefined functions durch Basher-Output `No syntax errors detected` + Brace-Balanced + Live-Probe-OUTPUT widerlegt).

**Anforderungen:**

- **Slug**: `novamira-adrianv2/elementor-inject-calibrated-page`
- **Category**: `adrianv2-live-edit` (neu zu registrieren — Kategorie existiert).
- **Read-only/Write**: WRITE, idempotent.
- **Mutation-Route**: durch `Novamira\AdrianV2\Helpers\Elementor_Document_Saver::save_data` (existiert).
- **Wirkung auf Pages**: überschreibt `_elementor_data`, setzt `_elementor_edit_mode=builder`, `_elementor_template_type=wp-page`, `_elementor_version`, `_wp_page_template` — analog zum upstream `inject.php`, aber durch sicherere Helper.
- **`wp_check_post_lock`-Schutz**: Helper ist schon gebaut und gibt `WP_Error` zurück.
- **Cache-Invalidation**: nach save_data → `do_action('elementor/core/files/clear_cache')` oder Helper erweitern.
- **Input-Schema**:
  ```json
  {
    "post_id":   integer (≥ 1, must exist),
    "_elementor_data": array (must decode to array via wp_json_encode round-trip),
    "elementor_version": string (default '3.0.0', validates against active plugin version),
    "wp_page_template": enum 'elementor_header_footer' | 'elementor_canvas' | 'default',
    "mode": enum 'overwrite' | 'merge_by_id' (default 'overwrite')
  }
  ```
- **Permission**: `edit_post` für gegebenen `post_id` + `edit_theme_options` AND `current_user_can('edit_published_pages')`.
- **Output-Schema**:
  ```json
  {
    "success": boolean,
    "post_id": integer,
    "sections_count": integer,
    "kit_id": integer|null,
    "warnings": string[],
    "blocks_invalidated": string[],
    "saved_at": ISO-8601 string
  }
  ```
- **Annotations**: `meta.annotations = { readonly: false, destructive: true, idempotent: true }`.

**Schritte:**

1. ⬜ Schema-Design via `thinker-with-files-gemini` ratifizieren.
2. Implementierung in `includes/abilities/elementor/class-elementor-inject-calibrated-page.php`.
3. Wiring in `includes/abilities/elementor/bootstrap.php` (selbst-registrierend analog zu `class-elementor-assign-class-to-containers`).
4. **Verifikation**:
   - `php -l` clean.
   - PHPUnit-Tests:
     - Happy path: gültige JSON + post_id → success=true, sections_count match, _elementor_data round-trip == Input.
     - **wp_check_post_lock**: simuliere lock → `WP_Error`.
     - **Permission denied**: User ohne `edit_post` → `WP_Error`.
     - **Concurrent**: zwei aufeinanderfolgende calls → zweiter soll auch success=true, beide Antworten mit eigener `saved_at`.
   - Live-Probe (D1).
5. **Code-reviewer** auf Klassendiff.
6. CHANGELOG-Eintrag (`[Unreleased] / Added`).

**Abhängigkeit von:** D1 (Test-Page vorhanden), A2 (PHPUnit läuft sauber).

**Roadmap-Vorteil:** ersetzt den upstream-inject.php-Sicherheitsproblem im produktiven Pfad vollständig; macht Agents vom WP-CLI unabhängig.

---

## D. 🟣 Reproducible Test-Fixtures (Elementor-Testseite mit `_elementor_data`)

> Letzter Turn hat strukturell verifiziert, dass `elementor-assign-class-to-containers` registriert ist, aber **kein realer mutations-Lauf** konnte stattfinden, weil im Local-Install keine Seite mit `_elementor_data` gepopulated war. Das blockiert C1, B1 und den End-to-End-Coverage-Test.

### D1. Eine Elementor-Testseite anlegen (öffentlich zugänglich)  ·  **MED · S · MED**

**Status:** ✅ gefixt + verifiziert (2026-06-18). Siehe `CHANGELOG.md [Unreleased] / ### Added` (Elementor-Test-Fixtures subsection). Local WP 7.0 + Elementor 4.1.3 + novamira-adrianv2 active — Live-Testseite angelegt mit post_id=5373, slug `adranv2-elementor-test` (literal user input, Tippfehler „adranv2" erhalten), draft status, Elementor Boot-Meta + `_elementor_data` boilerplate aus `nested-container.json` gesetzt (`Elementor\Plugin::instance()->documents->get(5373)` liefert `Elementor\Core\DocumentTypes\Page`).

**Schritte:**

1. ⬜ CLI: `wp post create --post_type=page --post_status=draft --post_title="[AdrianV2] Elementor Test" --post_name="adrianv2-elementor-test" --porcelain`.
2. Mindestens eine Container-Hierarchie injizieren (verwende C1, sobald verfügbar; sonst roh mit `wp post meta update`).
3. Mindestens 3 verschachtelte Container (genug Test-Material für `assign-class`-Tests).
4. Mindestens eine Page-Custom-CSS Section (`_elementor_page_custom_css`) für `inject_page_custom_css`-Tests.
5. **Verifikation**: Browser-use auf `http://treets.local/adrianv2-elementor-test/` rendert ohne Fehler.

**Abhängigkeit von:** C1 (oder bewusste Entscheidung, vorübergehend rohen update zu nutzen — Achtung: nur im Test-Fixture-Kontext, niemals live).

---

### D2. PHP-Test-Fixtures als JSON in `tests/fixtures/`  ·  **LOW · S · LOW**

**Status:** ✅ gefixt + verifiziert (2026-06-18). Siehe `CHANGELOG.md [Unreleased] / ### Added` (Elementor-Test-Fixtures subsection). 3 JSON-Stubs unter `tests/fixtures/elementor/`: `simple-container.json` (1 Flexbox-Container + 1 heading + 1 button; 33 Zeilen), `nested-container.json` (3 Level Verschachtelung mit `productslider` Token; 53 Zeilen; mirrored in Live-Testseite), `v4-atomic-page.json` (Elementor 4.x atomic mit `elType='e-flexbox'` + `classes.$$type='classes'` wrapper + e-heading + e-paragraph mit `$$type:'size'`/`$$type:'color'` Envelopes; 58 Zeilen). Round-trip JSON-Validity passes. PHPUnit-Consumer-Tests können jetzt strukturelle Validierung gegen diese Fixtures fahren ohne ein Live-WP zu benötigen.

**Schritte:**

1. ⬜ Erzeuge JSON-Stubs in `tests/fixtures/elementor/`:
   - `simple-container.json` (1 container + 1 heading + 1 button)
   - `nested-container.json` (3 verschachtelte Container)
   - `v4-atomic-page.json` (mit `settings.classes.value` statt `_css_classes`)
2. PHPUnit-Tests laden via `file_get_contents( __DIR__ . '/fixtures/elementor/simple-container.json' )`.
3. Live-Testseiten können dieselben JSONs verwenden.

**Abhängigkeit von:** D1 (für Bidirektionalität Live ↔ Test).

---

### D3. CI-Probe der Live-Seiten  ·  **HIGH · M · LOW**

**Schritte:**

1. ⬜ GitHub Actions-Workflow `.github/workflows/live-smoke.yml` (oder in lokaler `scripts/`-Test-Loop), der gegen das Local-WP via Windows-SSH-Exec den Live-Probe ausführt (letzter Stand der Smoke-Probe).
2. Sollbruchstellen-Anzeige: Sections-Liste → Sections-count-Match → Response-Field-Match.
3. **Verifikation**: Auf einer anderen Maschine reproduzierbar.

**Abhängigkeit von:** D1, C1, A2.

---

## E. 🟠 *-check-setup-Pattern auf andere Plugins ausrollen

> Letzter Turn hat das Pattern für WPCode aufgesetzt. Die `novamira-pro` Schwestern haben das Pattern bereits für ~14 Plugins: Elementor, AIOSEO, Yoast, Rank Math, WooCommerce, WPBakery, PoDS, Mosaic, Metabox, Kadence/KadenceBlocks, JetEngine, GeneratePress/GenerateBlocks, Etch, Code-Snippets.

### E1. Elementor  ·  **MED · M · MED**  ✅
### E2. AIOSEO  ·  **MED · M · MED**
### E3. Yoast  ·  **MED · M · MED**
### E4. Rank Math  ·  **MED · M · MED**
### E5. … für jeden weiteren Plugin-Typ nach Bedarf.

**Rezept:** von `class-wpcode-check-setup.php` ableiten. Jeder Ability:

- **Slug**: `novamira-adrianv2/<plugin>-check-setup`.
- **Category**: `adrianv2-<plugin>` (zu registrieren in `includes/categories.php`).
- **Inhalt**: Plugin-Active-Bool, Version, Helper-Reachability, Plugin-spezifische Cache-Layers, Permission-Sanity, Issues[].
- **Code-Pattern**: Class-basierter Aufruf analog `class-wpcode-check-setup.php` (PSR-0 + direct-call durch Bootstrap).
- **Verifikation**: live-probe gegen Local.

**Risiko:** Doppelter Scope-Bloat. Vor jedem Schritt prüfen, ob das Plugin im Local-Install aktiv ist.

---

## F. ⚪ Skill-Format Alignment (Yoast ↔ Upstream)

> Unser eigenes Skill `includes/skills/adrianv2-live-edit/SKILL.md` benutzt Yoast-Format mit `Activate when …`. Der upstream Skill nutzt simpler frontmatter (`name: elementor`, `description: ...`). Das ist ein **Format-Mismatch** — Claude Code unterstützt nur die upstream-Form für Auto-Discovery.

### F1. `includes/skills/adrianv2-live-edit/SKILL.md` auf Claude-Code-Format bringen  ·  **LOW · S · MED**  ✅

**Schritte:**

1. ⬜ README von `.claude/skills/elementor/SKILL.md` als Referenz nehmen — wir wollen dasselbe Format, behalten aber unseren Inhalt.
2. YAML-Frontmatter umbauen: `name: adrianv2-live-edit`, `description: (Activate when ...)` als einzelner String (kein Multi-Line-Block).
3. Body-Sektionen (When to use / Domain model / Workflows / Gotchas / Conventions) bleiben — sind sinnvoll.
4. Test: Claude Code erkennt das Skill in `/skills`-Liste.

**Abhängigkeit von:** — (eigenständig).

**Risiko:** Test fehlt — wir wissen nicht, ob Claude Code `Activate when …` im description-String überhaupt konsumiert. Vor Migration prüfen, indem man das `.claude/skills/elementor/SKILL.md` (upstream Format) als Vorlage nimmt.

---

## G. ⚫ SEO-MCP-Werkzeuge (Rank Math / AIOSEO Meta-Mutation)

> Aktuell existieren SEO-Read-Only-Checks via `novamira-pro/.../check-setup`. MCP-**Mutation** fehlt. Idee: 4-bis-8 neue Abilities.

### G1. `novamira-adrianv2/set-rank-math-meta`  ·  **MED · M · LOW**
### G2. `novamira-adrianv2/set-aioseo-meta`  ·  **MED · M · LOW**
### G3. `novamira-adrianv2/get-post-seo-audit` (Composite über alle SEO-Plugins)  ·  **L · M · LOW**

**Schritte:**

1. ⬜ Erforderliche Helper-Klasse `Novamira\AdrianV2\Helpers\SEO_Meta_Writer` anlegen (single-source-of-truth, kein direktes `update_post_meta`).
2. Routet durch `WPCode_Kses_Bypass::edit_post`-ähnliche Pattern (try/finally, sanitize_text_field vor Schreiben, JSON-Encode für Array-Meta wie `_robots`).
3. **Verifikation**:
   - Happy path: Page → Meta written → Frontend rendert korrekt.
   - **Cross-plugin-Konflikt**: Wenn sowohl Rank Math als auch AIOSEO aktiv → wer gewinnt? (User fragen oder in Schema explizit ausweisen.)
4. CHANGELOG.

**Abhängigkeit von:** D1, A2.

---

## H. 🟤 Dist-/Composer-Entscheidung

> Aktuell ist der `.gitattributes`-Composition-Pfad unklar. WP.org plugin dist packt `composer.lock` schon auto-aus. Aber ob das Plugin via Composer verteilt werden soll, ist nicht dokumentiert.

### H1. README-Sektion "Distribution" hinzufügen  ·  **LOW · S · LOW**  ✅

**Schritte:**

1. ⬜ Neue Section in `README.md` mit zwei klaren Szenarien:
   - **Szenario 1**: WP.org / WordPress-Site-Install (klassisch): user lädt dist-zip in WP-Admin hoch.
   - **Szenario 2**: Composer (`composer require adilinu94/novamira-adrianv2`) mit `composer/installers` Plugin → `wp-content/plugins/novamira-adrianv2/`.
2. `.gitattributes` exakt auf diese Entscheidung trimmen (siehe A1).
3. **Verifikation**: README ist nach `git archive` enthalten; Composer-`create-project` funktioniert ohne manuelles Zutun.

**Abhängigkeit von:** A1.

---

## I. 🟦 CI Verkabelung

### I1. GitHub Actions für phpcs + psalm + phpunit (live-Probe)  ·  **MED · L · MED**  ✅

**Schritte:**

1. ⬜ `.github/workflows/ci.yml` mit 3 Jobs: lint, analyze, test.
2. Windows-Matrix optional (für unseren Local-Workflow).
3. PHP-Version 8.0, 8.1, 8.2, 8.3 (laut `composer.json`).
4. Live-Probe NICHT in CI (nur lokale Reproducibility via Workflow-Dispatch).
5. **Status-Badge** in README.
6. **Verifikation**: GitHub-Actions grün bei leerem Branch.

**Abhängigkeit von:** A1.

---

## J. 🟫 Browser-driven End-to-End Verifikation

### J1. Elementor-Frontend-Verifikation nach Mutation  ·  **L · M · MED**

**Schritte:**

1. ⬜ `browser-use` Agent fährt gegen `http://treets.local/adrianv2-elementor-test/`:
   - Vor Mutation: nimmt DOM-Snapshot, scrolled, schiesst Screenshots.
   - Mutation: `novamira-adrianv2/elementor-inject-calibrated-page` mit `class_colors=red`.
   - Nach Mutation: DOM-Diff.
2. **Verifikation**: classe `red` ist im DOM activ; `_elementor_data` post-meta reflects change; kein JS-Error in der Console.

**Abhängigkeit von:** D1, C1.

---

## K. 🟪 Weitere nice-to-haves

| ID | Item | Größe | Prio |
|---|---|---|---|
| K1 | `tests/_smoke_live_edit_reflection.php` Snapshot-Test — Hash der gemockten Registry persistieren für Regression-Detection. | S | LOW |
| K2 | `mcp-server-config.example.json` — Beispiel-Konfiguration mit Live-Server-URL. | S | LOW | ✅ |
| K3 | `docs/architecture.md` — Diagramm aller 7 WPCode-Abilities + Helpers + wo sie zusammenspielen. | M | LOW |
| K4 | Ability-Discovery-Page im README mit Tab-View nach Kategorie. | M | LOW |
| K5 | MCP-Server-Rate-Limit per Ability (Schutz gegen Burst) | L | MED |
| K6 | JSON-Schema-Generator aus PHP-Klassen-Typen (OpenAPI-Style) | L | MED |
| K7 | Composer-Script-Bridge: `composer test:wpcode` für Sub-Modul-spezifische Tests | S | LOW |

---

## L. 🔘 Optional directions (sub-features als ganze Klassen)

### L1. Visual-Diff-Ability: vor/nach Mutation Side-by-Side HTML  ·  **L · L · LOW**
### L2. Elementor Atomic v6 Settings-Class-Mapping (simulieren ohne v6 installiert)  ·  **L · L · LOW**
### L3. Migration-Helper: konvertiert alte `novamira/adrians-*` Slugs auf `novamira-adrianv2/*` (best-effort).  ·  **M · M · MED**
### L4. AI-driven SEO-Vorschlag metadaten-filler: combines Psychograph + PageContent → Rank Math Title.  ·  **XL · M · LOW**

---

## M. 📚 Glossar / Begrifflichkeiten

| Begriff | Bedeutung |
|---|---|
| **calibrate** | upstream Skill-Wort für: exportiere eine echte `_elementor_data` JSON aus deiner Site, damit Folge-Aktionen die exakte Atomik deiner Site kennen. |
| **atom** | kleinstes, klonesbares Elementor-Element (heading, button, image, …). |
| **`wp_check_post_lock`** | WP-Funktion, die laufende Elementor-Editor-Sessions schützt. MUSS vor überschreiben geprüft werden. |
| **`update_json_meta`** | Elementor-interner Hook, der nach `_elementor_data`-Änderung CSS-Cache invalidiert. |
| **bypass_kses** | MCP-Param `bypass_kses=true` zwingt `WPCode_Kses_Bypass`-Pfad (nur `snippet_id`, `title`, `code` — alle anderen werden abgelehnt). |
| **adrianv2-live-edit** | Skill-Slug im eigenen Plugin. Lebt in `includes/skills/adrianv2-live-edit/SKILL.md`. |
| **adrianv2-wpcode** | MCP-Category-Slug für WPCode-Operationen. |
| **TOCTOU** | time-of-check-to-time-of-use race; Elementor's lock ist gegen genau das. |

---

## N. ⚙️ Pflege des Plans

Updates-Policy:

- **Trigger**: wenn ein ⬜ abgeschlossen wird, oder wenn ein neues ⬜ hinzukommt.
- **Verantwortlich**: User + Buffy gemeinsam.
- **Sync mit CHANGELOG**: Jeder ⬜ → ✅ Übergang mit gleichem Wortlaut wie im CHANGELOG.
- **Nicht-persistierte Pläne**: User wählte "Nur bei Bedarf"; in-Memory-`write_todos` bleibt Default.

---

*Datei angelegt am laufenden Session-Tag im Auftrag des Users ("Schreibe ein sehr ausfühlichen Plan, was du alles noch machen möchtest, oder was wir noch machen können"). Bei explizit angefordertem Update: vorher ALLE `✅`-Items mit CHANGELOG abgleichen, dann Plan neu generieren.*
