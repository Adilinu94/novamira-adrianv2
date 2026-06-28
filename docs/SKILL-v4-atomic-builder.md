---
name: elementor-v4-atomic-builder
description: >
  Expert workflow for building and rebuilding Elementor pages using V4 Atomic Widgets.
  Use this skill whenever:
  - A V3 Elementor page needs to be rebuilt in V4
  - A new page should be created with Atomic Widgets only
  - Global Classes or Variables need to be created/applied
  - adrians-batch-build-page, adrians-patch-element-styles or adrians-setup-v4-foundation is used
  - Someone asks "build this in Elementor V4" or "use atomic widgets"
  Always use this skill before writing any Elementor V4 element tree.
---

# Elementor V4 Atomic Page Builder
# Zuletzt aktualisiert: Juni 2026 – V3→V4 Konvertierungstest (testseite/test4)

---

## PFLICHT-WORKFLOW

```
1. adrians-setup-v4-foundation   -> gibt alle IDs (Variables, Classes, Base-Classes)
2. V3-Seite analysieren          -> elementor-get-content
3. Struktur AUFSCHREIBEN         -> Flexbox-Checkliste, dann erst Code
4. adrians-batch-build-page      -> Seite bauen  (ODER elementor-inject-calibrated-page für großen Tree)
5. Pruefen + Fixes               -> adrians-patch-element-styles
```

**ALTERNATIV – automatische V3→V4 Konvertierung:**
```
novamira-adrianv2/convert-page-v3-to-v4 { post_id: X, dry_run: true }
→ Stats + Warnings lesen (unsupported widgets!)
novamira-adrianv2/convert-page-v3-to-v4 { post_id: X, dry_run: false }
```

---

## !! KRITISCH: elType-Regeln für V4 !!

### CONTAINER-Widgets (`e-flexbox`, `e-div-block`)
```json
{
  "id": "herowrap",
  "elType": "e-flexbox",
  "settings": {
    "classes": {"$$type":"classes","value":["gc-FLEXBASE","herowrap"]},
    "tag":     {"$$type":"string","value":"div"}
  },
  "styles": { ... },
  "elements": [ ... ],
  "isInner": false,
  "interactions": [],
  "editor_settings": [],
  "version": "4.2.0-beta1"
}
```

**KEIN `widgetType` bei Containern!** `elType: "e-flexbox"` IST der Typ.

### LEAF-Widgets (e-heading, e-paragraph, e-button, etc.)
```json
{
  "id": "sherotitle",
  "elType": "widget",
  "widgetType": "e-heading",
  "settings": { ... },
  "styles": { ... },
  "elements": [],
  "isInner": false,
  "interactions": [],
  "editor_settings": [],
  "version": "4.2.0-beta1"
}
```

**Leaf-Widgets behalten `elType: "widget"` + `widgetType`!**

### Häufigster Bug: falsche elType-Kombination
```
FALSCH:  { "elType": "widget", "widgetType": "e-flexbox" }  ← rendert NICHT!
RICHTIG: { "elType": "e-flexbox" }                          ← kein widgetType!
```

---

## ATOMIC WIDGETS

| Widget       | elType         | widgetType    | Kinder? | Wann                |
|-------------|---------------|--------------|---------|---------------------|
| e-flexbox   | `e-flexbox`   | —            | JA      | Container/Layout    |
| e-div-block | `e-div-block` | —            | JA      | Div-Container       |
| e-heading   | `widget`      | `e-heading`  | NEIN    | H1-H6               |
| e-paragraph | `widget`      | `e-paragraph`| NEIN    | Fliestext           |
| e-button    | `widget`      | `e-button`   | NEIN    | CTAs                |
| e-image     | `widget`      | `e-image`    | NEIN    | Bilder              |
| e-svg       | `widget`      | `e-svg`      | NEIN    | Icons               |
| e-divider   | `widget`      | `e-divider`  | NEIN    | Trennlinien         |
| e-youtube   | `widget`      | `e-youtube`  | NEIN    | YouTube-Videos      |

---

## $$type FORMAT

String:     {"$$type":"string","value":"h1"}
Text:       {"$$type":"html-v3","value":{"content":{"$$type":"string","value":"Text"},"children":[]}}
Groesse:    {"$$type":"size","value":{"size":48,"unit":"px"}}
Farbe:      {"$$type":"color","value":"#FF0101"}
Color-Var:  {"$$type":"global-color-variable","value":"e-gv-XXXXXXX"}
Size-Var:   {"$$type":"global-size-variable","value":"e-gv-XXXXXXX"}
Font-Var:   {"$$type":"global-font-variable","value":"e-gv-XXXXXXX"}
Klassen:    {"$$type":"classes","value":["gc-XXXXX","sherotitle"]}
Link:       {"$$type":"link","value":{"href":{"$$type":"string","value":"#"},"isTargetBlank":{"$$type":"boolean","value":false},"tag":{"$$type":"string","value":"a"}}}
Dimensions: {"$$type":"dimensions","value":{"block-start":{"$$type":"size","value":{"size":60,"unit":"px"}},"block-end":...,"inline-start":...,"inline-end":...}}

---

## BACKGROUND-IMAGE NATIV

```json
"background": {
  "$$type": "background",
  "value": {
    "background-overlay": {
      "$$type": "background-overlay",
      "value": [
        {
          "$$type": "background-image-overlay",
          "value": {
            "image": {
              "$$type": "image",
              "value": {
                "src": {
                  "$$type": "image-src",
                  "value": {
                    "id": {"$$type": "image-attachment-id", "value": 4544},
                    "url": null
                  }
                },
                "size": {"$$type": "string", "value": "large"}
              }
            },
            "position": {"$$type": "string", "value": "center center"},
            "size":     {"$$type": "string", "value": "cover"},
            "repeat":   {"$$type": "string", "value": "no-repeat"}
          }
        },
        {
          "$$type": "background-color-overlay",
          "value": {"color": {"$$type": "color", "value": "rgba(14,42,59,0.82)"}}
        }
      ]
    }
  }
}
```

Reihenfolge: Bild ZUERST, Color-Overlay DANACH. `url: null` wenn ID gesetzt.

---

## FLEXBOX: SO WENIG WIE MOEGLICH

Checkliste vor jedem Flexbox:
- Hat er mind. 2 Kinder die unterschiedlich layoutet werden muessen?
- Loest er ein anderes Layout-Problem als sein Parent?
- Kann ich padding-inline-end des Parents nutzen statt Inner-Container?

Statt Inner-Container fuer Breite:
SCHLECHT: hero > content-wrap(70%) > heading
GUT:      hero(padding-inline-end:30%) > heading + paragraph + button

Properties: flex-direction, justify-content, align-items, gap

---

## KRITISCHE REGELN

### 1. Style-IDs: KEINE HYPHENS
FALSCH:  "s-hero-title"  ← class_name_contains_spaces Fehler!
RICHTIG: "sherotitle"

### 2. custom_css IMMER als Objekt
FALSCH:  "custom_css": "background: red;"     ← Site-Crash!
RICHTIG: "custom_css": {"raw": "background: red;"}
NULL:    "custom_css": null

### 3. settings hat KEINE Style-Werte
Alles in das `styles` Objekt. `settings` enthält nur widget-Props (title, tag, link etc.)

### 4. e-flexbox-base IMMER zuweisen
`gc-a2386847ca992ed2` (test4) auf jeden Flexbox in classes → kein ungewolltes Default-Padding.
**IMMER setup-v4-foundation aufrufen** um die aktuelle Base-Class-ID zu erhalten!

### 5. $$type NICHT in bash `-e "..."` Strings!
```bash
# FALSCH - $$ wird zu Prozess-ID (z.B. 556 → "556type")
node -e "const x = {'$$type':'classes'}"

# RICHTIG - immer .js Datei verwenden
cat > /tmp/gen.js << 'EOF'
const x = {"$$type":"classes","value":[]}
EOF
node /tmp/gen.js
```

### 6. PHP save API: wp_slash + update_post_meta
```php
# FALSCH - verliert V4 Daten:
$document->save(['elements' => $data]);

# RICHTIG für programmatischen V4-Inject:
$json_raw = file_get_contents($url);  // oder json_encode($data)
update_post_meta($post_id, '_elementor_edit_mode', 'builder');
update_post_meta($post_id, '_elementor_data', wp_slash($json_raw));
\Elementor\Plugin::$instance->files_manager->clear_cache();
```

### 7. CSS Props via custom_css (nicht als style props)
Diese Props funktionieren NICHT als `props` in V4 styles → in `custom_css.raw` schreiben:
- `position`, `top`, `right`, `bottom`, `left`
- `box-shadow`
- `border` (shorthand)
- `object-fit`
- `flex-shrink`
- `max-width`, `box-sizing`
- `flex` (shorthand), `aspect-ratio`, `width` (für Images)

---

## V3 → V4 WIDGET-MAPPING

### Auto-konvertierbar:
| V3 Widget        | V4 Equivalent  | Hinweis                        |
|-----------------|---------------|-------------------------------|
| heading         | e-heading      | Direkt                        |
| text-editor     | e-paragraph    | Direkt                        |
| button          | e-button       | Direkt                        |
| image           | e-image        | Direkt                        |
| divider         | e-divider      | Direkt                        |
| container       | e-flexbox      | elType ändert sich!           |

### Manuell (kein V4 Equivalent – Workaround nötig):
| V3 Widget              | V4 Workaround                                          |
|-----------------------|-------------------------------------------------------|
| `counter`             | Statische `e-heading` mit Zahl ("478+")               |
| `rating`              | `e-heading` mit Stern-Emoji ("★★★★★ 4.7/5")           |
| `icon-list`           | `e-flexbox` (column) + `e-paragraph` pro Item         |
| `icon-box`            | `e-flexbox` + `e-heading` + `e-paragraph` + `e-button`|
| `elementskit-icon-box`| `e-flexbox` + `e-heading` + `e-paragraph`             |
| `elementskit-video`   | `e-youtube` (source URL übergeben)                    |
| `elementskit-accordion`| HTML Widget mit `<details>/<summary>` (V3 Fallback)  |
| `testimonial`         | `e-flexbox` Grid manuell aufbauen                     |
| `elementskit-slider`  | Kein V4 Equivalent → HTML Widget oder Section belassen|

**HINWEIS:** `convert-page-v3-to-v4` konvertiert automatisch, aber lässt unsupported Widgets als V3-Fallback.

---

## V4 RENDER-VERIFIKATION

Nach dem Inject prüfen ob V4 Atomic rendert:
```php
$html = wp_remote_retrieve_body(wp_remote_get(get_permalink($post_id)));
// V4 Atomic rendert korrekt wenn:
preg_match('/class="[^"]*s-[a-z]/', $html) // → "YES" für s-* CSS Klassen
```

Falls `has_s_class: NO` → V4 rendert NICHT. Prüfe:
1. `_elementor_edit_mode` ist "builder"?
2. Erste Sektion hat `elType: "e-flexbox"` (nicht "widget")?
3. `validate-v4-tree` aufrufen

---

## VOLLSTAENDIGE WIDGET-STRUKTUREN

### e-flexbox Container:
```json
{
  "id": "herowrap",
  "elType": "e-flexbox",
  "settings": {
    "classes": {"$$type":"classes","value":["gc-FLEXBASE","herowrap"]},
    "tag":     {"$$type":"string","value":"div"}
  },
  "styles": {
    "herowrap": {
      "id": "herowrap",
      "type": "class",
      "label": "Hero Wrapper",
      "variants": [
        {
          "meta": {"breakpoint": "desktop", "state": null},
          "props": {
            "flex-direction": {"$$type":"string","value":"column"},
            "padding": {"$$type":"dimensions","value":{
              "block-start":  {"$$type":"size","value":{"size":100,"unit":"px"}},
              "block-end":    {"$$type":"size","value":{"size":100,"unit":"px"}},
              "inline-start": {"$$type":"size","value":{"size":15,"unit":"px"}},
              "inline-end":   {"$$type":"size","value":{"size":15,"unit":"px"}}
            }},
            "background": {"$$type":"background","value":{"background-color":{"$$type":"global-color-variable","value":"e-gv-XXXXXXX"}}}
          },
          "custom_css": null
        }
      ]
    }
  },
  "elements": [ ... ],
  "isInner": false,
  "interactions": [],
  "editor_settings": [],
  "version": "4.2.0-beta1"
}
```

### e-heading (Leaf Widget):
```json
{
  "id": "sherotitle",
  "elType": "widget",
  "widgetType": "e-heading",
  "settings": {
    "classes": {"$$type": "classes", "value": ["gc-GLOBAL", "sherotitle"]},
    "tag":     {"$$type": "string",  "value": "h1"},
    "title":   {"$$type": "html-v3", "value": {"content": {"$$type": "string", "value": "Titel"}, "children": []}},
    "link":    {"$$type": "string",  "value": ""}
  },
  "styles": {
    "sherotitle": {
      "id": "sherotitle",
      "type": "class",
      "label": "Hero Title",
      "variants": [
        {
          "meta": {"breakpoint": "desktop", "state": null},
          "props": {
            "color":       {"$$type": "global-color-variable", "value": "e-gv-XXXXXXX"},
            "font-size":   {"$$type": "size", "value": {"size": 52, "unit": "px"}},
            "font-weight": {"$$type": "string", "value": "700"}
          },
          "custom_css": null
        }
      ]
    }
  },
  "elements": [],
  "isInner": false,
  "interactions": [],
  "editor_settings": [],
  "version": "4.2.0-beta1"
}
```

---

## BREAKPOINTS

desktop: 1025px+ | tablet: 768-1024px | mobile: 0-767px
States: null, hover, focus, active

---

## ABILITIES

```
IMMER ZUERST:  novamira-adrianv2/setup-v4-foundation
Konvertieren:  novamira-adrianv2/convert-page-v3-to-v4
Bauen:         novamira-adrianv2/batch-build-page (oder elementor-inject-calibrated-page)
Fixen:         novamira-adrianv2/patch-element-styles
Validieren:    novamira-adrianv2/validate-v4-tree
Render-Check:  novamira-adrianv2/evaluate-render-context
Lesen:         novamira/elementor-list-variables, elementor-list-global-classes, elementor-get-content
Variables:     novamira/elementor-create-variable, novamira-adrianv2/batch-create-variables
Classes:       novamira/elementor-create-global-class, novamira-adrianv2/add-class-variant
Debug:         novamira/execute-php, novamira/write-file
```

**INJECT GROSSER TREES (>50KB):**
```php
// 1. JSON auf GitHub hochladen
// 2. Auf dem Server herunterladen + in DB schreiben:
$url = 'https://raw.githubusercontent.com/USER/REPO/master/data/page.json';
$json_raw = file_get_contents($url);
update_post_meta($post_id, '_elementor_edit_mode', 'builder');
update_post_meta($post_id, '_elementor_data', wp_slash($json_raw));
\Elementor\Plugin::$instance->files_manager->clear_cache();
```

---

## HAEUFIGE FEHLER

| Fehler                       | Ursache                             | Fix                                    |
|-----------------------------|-------------------------------------|----------------------------------------|
| Seite rendert leer           | `elType:"widget"` für Flexbox       | `elType:"e-flexbox"` verwenden         |
| class_name_contains_spaces   | Hyphen in Style-ID                  | `sherotitle` statt `s-hero-title`      |
| Site-Crash 500               | `custom_css` als String             | `{"raw":"..."}` verwenden              |
| Bild zeigt nicht             | custom_css statt native             | `background-image-overlay` nutzen      |
| Keine Styles sichtbar        | Class-ID falsch                     | `list-global-classes` → ID prüfen     |
| Zu viele Container           | V3-Denkweise                        | `padding-inline-end` nutzen            |
| $$type → 556type             | `node -e "..."` mit `$$` in bash    | .js Datei verwenden!                   |
| V4 Data nach Save weg        | `document->save()` für V4           | `wp_slash + update_post_meta`         |
| `has_s_class: NO`            | V4 rendert nicht                    | `elType` prüfen, `evaluate-render-context` |
