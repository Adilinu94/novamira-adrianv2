# Elementor + WP-CLI — Cheat Sheet (host-agnostic)
`wp` here means "the WP-CLI invocation for your host" — e.g. plain `wp` locally,
`ssh host "cd WEBROOT && wp ..."` over SSH, or `ddev wp ...`. See `hosts/*.md`.
## Recon / calibrate
```bash
wp core version
wp plugin list --status=active --field=name # confirm elementor, Rank Math/Yoast, popup plugins
wp theme list --status=active --field=name
wp option get elementor_active_kit # global kit post id (colors/typography)
wp post-type list --field=name # find CPTs
wp post list --post_type=page --fields=ID,post_title,post_status
wp post list --post_type=elementor_library --fields=ID,post_title # popups/templates
wp post meta get <id> _elementor_data --format=json # pull a real page to clone atoms from
```
## Build = generate + inject (see inject.php)
```bash
PID=$(wp post create --post_type=page --post_status=draft \
--post_title="T" --post_name="slug" --porcelain)
# Get the JSON to where wp can read it. Portable method (works on chrooted hosts):
# ssh host "cat > ~/page.json" < local.json
# Local: just write it.
wp eval-file inject.php $PID page.json \
"$(wp plugin get elementor --field=version)" elementor_header_footer
wp elementor flush-css
```
`_wp_page_template`: `elementor_header_footer` = keep theme nav/footer (default);
`elementor_canvas` = blank.
## Verify
```bash
# server-side render (popup hrefs are stripped here — verify those on the frontend)
wp eval "echo \Elementor\Plugin::\$instance->frontend->get_builder_content_for_display($PID);" \
| grep -ic "fatal error\|critical error" # expect 0
# frontend
curl -s URL | grep -c 'data-element_type="container"'
```
## Validate the JSON before injecting
```bash
jq 'type' page.json # must be "array" (array of containers)
jq '[.. | objects | .id? // empty] | (length) as $n
| (unique | length) as $u | {ids:$n, unique:$u}' page.json # ids == unique
```
## Popup button (Dynamic Content for Elementor) — on a button widget
```
settings.__dynamic__.link =
[elementor-tag id="HEX" name="popup" settings="%7B%22popup%22%3A%22POPUPID%22%7D"]
# settings = urlencoded {"popup":"<popup post id>"}. Inspect an existing button to copy the real tag.
```
## File transfer (portable)
```bash
ssh host "cat > ~/page.json" < local.json # no scp/SFTP needed; survives chroot
# read it back / confirm:
ssh host "wc -c ~/page.json && head -c 120 ~/page.json"
```
