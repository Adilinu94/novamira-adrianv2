# Host adapter: local dev (DDEV / LocalWP / wp-env / Studio)
Local environments are the easiest target — `wp` runs directly, no SSH, no SFTP chroot,
and you write the JSON file straight to disk. Ideal for learning the technique and for
building/iterating before promoting to a live site.
`wp` invocation differs per tool; everything else in `SKILL.md` is identical.
## DDEV
```bash
ddev wp core version # wp runs inside the web container
ddev wp option get elementor_active_kit
ddev wp post meta get <id> _elementor_data --format=json
```
- **File path:** your project dir is mounted at `/var/www/html` in the container, so a
file you write locally is readable by `wp` at the same relative path.
```bash
# write page.json into the project, then:
ddev wp eval-file inject.php $PID page.json "$(ddev wp plugin get elementor --field=version)"
ddev wp elementor flush-css
```
- Frontend: `https://<project>.ddev.site`.
## LocalWP (by Flywheel)
- **Open a shell:** right-click the site → **Open Site Shell** (gives a shell with `wp`
on PATH for that site), or use the site's SSH details.
```bash
wp core version
wp eval-file ~/path/inject.php $PID ~/path/page.json "$(wp plugin get elementor --field=version)"
wp elementor flush-css
```
- **Webroot:** `~/Local Sites/<site>/app/public`.
- **Push to live:** LocalWP **Connect** (to Flywheel/WP Engine) or MainWP/migration plugin.
## wp-env (@wordpress/env)
```bash
wp-env run cli wp core version
wp-env run cli wp eval-file inject.php $PID page.json "$(wp-env run cli wp plugin get elementor --field=version)"
wp-env run cli wp elementor flush-css
```
- The project mounts at `/var/www/html`; reference files by their in-container path.
## Studio (by WordPress.com)
- Studio bundles WP-CLI; open the site's terminal from the app, then use plain `wp ...`.
- Webroot is the app-managed site folder shown in Studio.
## Why start local
- No bot-protection, no chroot, no key provisioning — fastest calibrate→inject→verify loop.
- Safe sandbox to confirm the site's container model, atoms, and kit IDs before touching a
client's live/staging environment.
- Build once locally, export the page as an Elementor template `.json`, and import it on
the destination site if you can't CLI into it (see `hosts/managed-wp.md`).
