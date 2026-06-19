---
name: elementor
description: Build and edit Elementor (Flexbox Container) pages on ANY self-hosted WordPress from the CLI — generate the page's _elementor_data JSON and inject it with WP-CLI instead of clicking through the visual editor. Host-agnostic core plus access adapters for generic SSH+WP-CLI, managed WordPress (Kinsta / WP Engine / Flywheel), and local dev (DDEV / LocalWP / wp-env). Covers calibration, global-kit binding, the popup/esc_url gotcha, Rank Math meta, and a safe staging-first workflow. Use for any WordPress + Elementor page-build task — build or edit a page, generate pages at scale, set up CLI access, or work with _elementor_data. Trigger words: Elementor, WordPress page build, _elementor_data, Elementor JSON, WP-CLI Elementor, generate Elementor pages.
---
# Elementor page builder — any host
Build and edit Elementor pages on self-hosted WordPress from the CLI by
**generating the page's `_elementor_data` JSON and injecting it with WP-CLI** —
instead of clicking through the visual editor. The build technique is identical on
every host; only *how you get a shell + `wp`* changes.
- `SETUP.md` — **first-time setup**: how to export the site's design (calibration) so builds match it.
- `reference.md` — host-agnostic WP-CLI + verify cheat-sheet.
- `inject.php` — the injection template (`wp eval-file`).
- `hosts/*.md` — per-platform access adapters. **Read the one that matches your host first.**
## 0. Pick your host adapter
Everything below assumes you can run `wp` against the target site. Get there via the
matching adapter, then come back:
| Your setup | Adapter | How `wp` runs |
|---|---|---|
| VPS / DigitalOcean / self-managed Linux, you have SSH | `hosts/generic-ssh.md` | `ssh host "cd WEBROOT && wp ..."` |
| Kinsta, WP Engine, Flywheel (managed, SSH-gated) | `hosts/managed-wp.md` | SSH gateway → `wp ...` |
| Local: DDEV, LocalWP, wp-env, Studio | `hosts/local.md` | `ddev wp ...` / `wp ...` locally |
Not listed (e.g. Cloudways) — the core below still applies; you just need that
platform's way to add an SSH key / open a shell and find the webroot.
## The golden path (same on every host)
1. **Get CLI access** — via your host adapter; confirm `wp core version` works.
2. **Calibrate** — pull a real page's Elementor JSON to learn the site's exact
structure, atoms, and global-kit IDs. **Never invent Elementor setting keys.**
3. **Build on STAGING** as a draft/noindex page; clone real atoms and re-content them.
4. **Inject** via `wp eval-file inject.php` + `wp elementor flush-css`.
5. **Verify** server-side AND on the real frontend.
6. **Promote to live** via export/import once approved.
## 1. Calibrate first (non-negotiable)
An Elementor page is a JSON tree stored in postmeta `_elementor_data`. Modern sites
use the **Flexbox Container** model (`elType:"container"` nesting); older sites use
section/column. **Confirm which the target site uses before generating.**
**Get a real design sample — see `SETUP.md` for the full how-to (CLI *and* no-CLI
editor-export methods).** The fast path if you have `wp`:
```bash
wp option get page_on_front # homepage post id (static front page)
wp option get elementor_active_kit # global kit post id
wp post list --post_type=page --fields=ID,post_title,post_status
wp post meta get <id> _elementor_data --format=json # pull a real page to clone
```
No shell access? Export from the Elementor editor (**Save as Template → Templates →
Export**) and hand Claude the `.json`; the injectable array is under `.content`. (`SETUP.md`)
Extract real "atoms" (heading, button, image, icon-list, icon-box, carousel nodes)
plus the global-kit color/typography IDs. **Build by CLONING these real nodes and
re-contenting them** — this removes ~all setting-key guesswork. Bind colors/type to
the kit via `settings.__globals__` (cloning atoms carries these for free); don't
hardcode hexes where the site uses globals.
## 2. Inject `_elementor_data` (see inject.php)
```bash
PID=$(wp post create --post_type=page --post_status=draft \
--post_title="T" --post_name="slug" --porcelain)
# push the JSON to a path wp can read, then:
wp eval-file inject.php $PID page.json \
"$(wp plugin get elementor --field=version)" elementor_header_footer
wp elementor flush-css
```
`inject.php` sets `_elementor_data` = `wp_slash(wp_json_encode($data))`, plus
`_elementor_edit_mode=builder`, `_elementor_template_type=wp-page`,
`_elementor_version`, and `_wp_page_template`:
- `elementor_header_footer` — full width, keeps theme nav/footer (default)
- `elementor_canvas` — blank canvas
If **Rank Math** is active, set `rank_math_title` / `rank_math_description` /
`rank_math_focus_keyword` / `rank_math_robots` (= `array('noindex')` on staging).
Yoast is the same idea with `_yoast_wpseo_*` keys.
**Getting the JSON file to where `wp` can read it:** the portable method that works
on every host (including SFTP-chrooted managed hosts) is to stream it over the shell:
```bash
ssh host "cat > ~/page.json" < local.json # no scp/SFTP dependency
```
Locally just write the file. See your host adapter for the writable path.
## 3. Verify before claiming done
```bash
# server-side render — expect 0 fatals (popup hrefs are stripped here; see gotchas)
wp eval "echo \Elementor\Plugin::\$instance->frontend->get_builder_content_for_display($PID);" \
| grep -ic "fatal error\|critical error"
# real frontend
curl -s URL | grep -c 'data-element_type="container"' # container count
```
Check the frontend for: container count, kit CSS + page CSS loaded, images present,
`elementor-action` count (popups), and 0 errors.
## 4. Gotchas learned the hard way (universal — apply on every host)
- **Popup / "Book Now" buttons:** a button URL of
`#elementor-action:action=popup:open&settings=...` is **stripped by `esc_url`** and
renders no href. Use the site's real mechanism — often a **Dynamic Content for
Elementor** popup tag in `settings.__dynamic__.link`. **Inspect how the site's own
buttons open popups and copy that verbatim.** CLI render also strips popup hrefs, so
verify popup buttons on the REAL frontend.
- **Unique element ids:** every Elementor element needs a unique string `id`; when
generating many sections, prefix ids per-section. (Repeated *numeric* media/icon ids
are fine — those are attachment ids, not element ids.)
- **Relative internal links** (`/page/`) so they work on staging and live.
- **`wp` fatals on `wp-salt.php`?** Some installs `require` salts with a relative path,
so `wp --path=DIR` fatals. Fix: `cd` into the webroot first, then run `wp`.
- **`scp`/SFTP to `/tmp` blocked?** Common on chrooted managed hosts. Stream files over
the shell instead (`ssh host "cat > ~/file" < local`).
## 5. Staging & safety
- **Always build on staging as a draft/noindex page; `wp db export` first.** Never edit
live directly. Promote via export/import (or re-inject on live) once approved.
- Each managed host has its own staging environment + push-to-live flow — see the
host adapter.
- **Scale builds** by fanning out one agent per section (each clones atoms) → assemble →
validate (jq: array of containers, unique element ids) → inject → verify.
## Reference implementations
- lmoncany/elementor-claude-skill (WP-CLI injection technique)
- emersimeon/claude-elementor-kit (REST/MCP alternative; see docs/LESSONS.md)
- bvisible/elementor-mcp-api ( REST+MCP plugin for interactive editing)
License
[MIT](LICENSE) © ProofPilot
