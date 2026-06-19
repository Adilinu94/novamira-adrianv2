# Setup — give the skill your site's design (calibration)
Before building anything, hand the skill a **real exported page** from the target site
(the homepage is ideal — it usually has the most section variety: hero, features,
testimonials, CTA). This is called **calibration**, and it's the single most important
setup step.
## Why this matters
Elementor pages are a JSON tree (`_elementor_data`). Every site uses different widgets,
spacing, and — critically — **global kit color/typography IDs**. When you give Claude one
real page, it learns:
- whether the site uses the modern **Flexbox Container** model or old section/column,
- the exact **widgets ("atoms")** the site uses and their working setting keys,
- the **global kit IDs** so new sections inherit the site's colors/fonts (not hardcoded hexes),
- real **popup / dynamic tags**, button styles, and structural conventions.
Claude then **clones those real elements and re-contents them** — which removes ~all
setting-key guesswork and makes new pages match the existing design. **Skip this and the
output will be generic and likely mis-styled.**
> **See the shape first:** `samples/homepage.example.json` is a tiny redacted 2-section
> page (hero + 3-up feature row) in the exact **raw `_elementor_data` array** form your
> own export should take — note the nested `container` → `widget` tree, the `__globals__`
> blocks that bind to the kit, and the unique string `id` on every element. Your real
> export will be larger, but this is the structure to expect.
---
## Method A — Export from the Elementor editor (no CLI, anyone can do it)
Best for handing the skill a design when you don't have shell access.
1. Open the page you want to copy the design from (e.g. the **Home** page) in the
**Elementor editor**.
2. Bottom-left, click the **▲ arrow next to the green "Update" / "Publish" button** →
**Save as Template**. Give it a name like `calibration-home`.
3. Go to **Templates → Saved Templates** (WP admin sidebar).
4. Hover the template row → click **Export** → it downloads a `.json` file.
5. Send that `.json` to the skill (drop it in your project folder and tell Claude the path).
> **Format note:** a UI-exported template looks like
> `{"content":[ … ],"page_settings":[ … ],"version":"…","type":"page"}`.
> The injectable element array is under **`.content`**. To pull just that:
> ```bash
> jq '.content' calibration-home.json > homepage.json
> ```
> (Claude will handle this automatically if you hand it the raw export — just know
> `_elementor_data` == the `.content` array.)
### Export one section instead of a whole page
In the editor, **right-click a container/section → Save as Template** (or **Copy**),
then export the same way. Useful when you only want to clone, say, the hero.
---
## Method B — Pull the raw `_elementor_data` via WP-CLI (most accurate)
Best if you (or Claude) have shell access — this is exactly what the build uses, no
format conversion needed. `wp` = your host's WP-CLI invocation (see `hosts/*.md`).
```bash
# 1. Find the homepage's post ID
wp option get page_on_front # ID if "static homepage" is set (Settings → Reading)
# …or list pages and pick it:
wp post list --post_type=page --fields=ID,post_title,post_status
# 2. Export that page's Elementor JSON to a file
wp post meta get <ID> _elementor_data --format=json > homepage.json
# 3. (recommended) also capture the global kit so colors/fonts can be matched
wp option get elementor_active_kit # kit post ID
wp post meta get <KIT_ID> _elementor_data --format=json > kit.json # kit's color/type settings
```
Pull a few different page types if they exist (home, a service/inner page, a contact
page) so the skill sees the full vocabulary of atoms.
## Where to put the export and what to tell Claude
1. Save the export(s) where Claude can read them — e.g. your project folder, or a
`samples/` subfolder next to where you're working.
2. Then just point Claude at it in plain language:
> "Calibrate from `homepage.json` (and `kit.json`), then build a new **Services** page
> that matches this site's design — clone the hero, the 3-up feature row, and the CTA."
Claude will read the export, identify the real atoms + kit bindings, and generate a new
`_elementor_data` page by cloning them. You don't need to understand the JSON yourself.
## Quick sanity checks on an export
```bash
jq 'type' homepage.json # "array" = raw _elementor_data (ready to clone)
# "object" = UI export; use jq '.content' first
jq '[.. | objects | .widgetType? // empty] | unique' homepage.json # list the widget types used
jq '[.. | objects | select(.elType=="container")] | length' homepage.json # container count (>0 = Flexbox model)
```
## Checklist before you ask for a build
- [ ] Skill installed in `~/.claude/skills/elementor/` (see `README.md`).
- [ ] You can run `wp core version` against the site **or** you've exported a `.json` design.
- [ ] You exported a real page (homepage) — `.content` array if it's a UI export.
- [ ] (Recommended) you exported the global kit too, for color/typography matching.
- [ ] You're targeting **staging** (or local), and took a `wp db export` backup if it's live.
