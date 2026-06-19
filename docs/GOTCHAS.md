# Novamira AdrianV2 — Gotchas

**Quelle:** Extracted aus `HANDOFF.md` §6 (Stand 2026-06-12) + ergänzt durch Live-Erfahrungen.
**Zweck:** Schnellreferenz für typische Fallstricke beim Debugging des V2-Plugins.

> Hinweis: Diese Datei ersetzt die HANDOFF.md §6 komplett. Wichtige Lessons aus der ersten
> Konsolidierung (Phase 1-6, abgeschlossen 2026-06-10).

---

## 1. PHP / Build

### 1.1 Heredoc + Backticks brechen ab
PHP-Code mit Backticks (`// Shell exec via backtick`, Strings wie `` `cmd` ``) lässt `cat > file << EOF` stillschweigend abbrechen. Die Datei wird unvollständig geschrieben, `basher` meldet aber „success".

**Workaround:** Für PHP-Dateien mit Backticks immer `write_file` verwenden, dann `basher cp`. Backtick-Detection: grep nach `` '`' `` im Content.

### 1.2 `sed -i 'Nd' + cat >>` repariert kaputte Dateien NICHT zuverlässig
Zeilennummern verschieben sich bei jedem Edit. Besser: **Datei komplett neu schreiben**.

### 1.3 `class_exists('...')` braucht exakte Namespace-Übereinstimmung
Inklusive `\\` Backslash-Escaping. Schreibfehler in `is_available()` = Ability nicht registriert = taucht nicht in Discovery auf.

### 1.4 MCP-Adapter filtert nur nach `mcp.public=true` und `mcp.type='tool'`
**Nicht** nach Category, Priority, Schema, Namespace. Wenn eine Ability fehlt, ist sie NIE registriert (nicht herausgefiltert).

**Diagnose:**
1. `php -l` über die Ability-Klasse
2. Prüfen ob `register()` `wp_register_ability()` aufruft
3. `wp_get_abilities()` direkt abfragen

### 1.5 IDs mit `bin2hex(random_bytes(4))`
Statt `wp_generate_password()` — schneller, deterministischer, keine WP-Dependency.

### 1.6 PHP-Sandbox-Validator Catch-Hierarchy
Nutzt `token_get_all(..., TOKEN_PARSE)`. Wirft `\ParseError` (nicht `Error`), und zusätzlich `\Throwable` als Catch-All. **Beide** müssen gecatcht werden.

```php
try {
    $tokens = token_get_all($code, TOKEN_PARSE);
} catch (\ParseError $e) {
    // syntax error
} catch (\Throwable $e) {
    // fallback
}
```

---

## 2. Elementor V4 Atomic Invarianten

### 2.1 Atomic `image()` Invariant IV
Wenn `id` gesetzt ist, darf der `url`-Key **GAR NICHT** im Array vorkommen (auch nicht als `null`). Sonst schlägt `Image_Src_Prop_Type::validate_value()` fehl.

```php
// RICHTIG
$image = ['id' => 123, 'alt' => 'foo'];

// FALSCH
$image = ['id' => 123, 'url' => null, 'alt' => 'foo'];  // ❌
```

### 2.2 `wp_register_ability()` prüft Category-Existenz im Registry
Wenn die `category` nicht in `wp_get_ability_categories()` auftaucht, wird die Registrierung stillschweigend abgelehnt (kein Fatal, keine Notice).

**Fix:** Immer erst `wp_register_ability_category($slug, $args)` für jede verwendete Category aufrufen, dann die Abilities registrieren.

**Symptom:** V2_CNT bleibt niedrig obwohl `register()` läuft (klassisch: 16 → 73 nach Category-Fix).

### 2.3 V4-Color-Contrast akzeptiert 3-, 6- und 8-stellige Hex-Codes
Der Alpha-Kanal von 8-stelligen wird ignoriert (semi-transparent kann nicht zuverlässig analysiert werden).

---

## 3. Security (aus Phase 0.5)

| Fix | Datei | Status |
|---|---|---|
| XSS in `page_js` (B5) | `includes/abilities/elementor/class-batch-build-page.php` | ✅ done |
| MIME-Spoofing (B7) | `includes/abilities/media/class-media-upload.php` | ✅ done |
| Path-Traversal (D6) | `includes/abilities/media/class-media-upload.php` | ✅ done |
| PHP-Sandbox-Audit (B8) | `includes/abilities/php-sandbox/` | offen |
| XSS via `add-custom-js` (B9) | `includes/abilities/custom-code/class-custom-code.php` | offen |
| SAST-Integration (D1) | `psalm.xml --taint-analysis` | offen |
| axe-core in Visual-QA (F1) | `scripts/visual-qa.js` | offen |

Details zu Fix-Implementierungen und Test-Matrizen: siehe `FRAMER-PIPELINE-IMPROVEMENT-PLAN.md` (Stand 2026-06-10) bzw. die Test-Specs in `includes/abilities/{elementor,media}/test-*.md`.

---

## 4. Debugging-Quickref

| Problem | Wo nachschauen |
|---|---|
| PHP-Syntaxfehler | `php -l <datei>` |
| Klasse nicht gefunden | `grep -r "class ClassName" includes/` + `grep -r "use.*ClassName" includes/` |
| Ability registriert sich nicht | `var_dump(class_exists('Full\\Namespace\\ClassName'));` vor dem `register()`-Call |
| WordPress-Fatal beim Aktivieren | `wp-content/debug.log` auf solar.local |
| MCP-Adapter findet Ability nicht | Erst prüfen ob sie in `wp_get_abilities()` auftaucht (nicht in der MCP-Adapter-Liste); wenn nicht, ist sie nicht registriert |
| V2_CNT zu niedrig | Category im Registry prüfen (siehe 2.2) |
| `token_get_all`-Fehler | Beide Catch-Blöcke (`ParseError` + `Throwable`) vorhanden? (siehe 1.6) |
| Image-Src-Validierung failt | `url`-Key komplett entfernt wenn `id` gesetzt? (siehe 2.1) |
