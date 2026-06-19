# Host adapter: managed WordPress (Kinsta / WP Engine / Flywheel)
Managed hosts ship WP-CLI and SSH, but gate access behind their dashboard and often
chroot SFTP. The build technique is unchanged — these are the access specifics.
## General flow (all three)
1. Enable/locate SSH in the dashboard; add your **public** key.
2. SSH in with the host's user@gateway and port.
3. `cd` to the site's webroot, confirm `wp core version`.
4. Push the page JSON over the shell (SFTP is usually chrooted): `ssh ... "cat > ~/page.json" < local.json`.
5. Inject + `wp elementor flush-css`.
6. Use the host's **staging environment** to build, then push staging → live.
Always run `wp` from inside the webroot (`cd` first) — relative salt/config requires
otherwise fatal.
---
## Kinsta
- **SSH:** MyKinsta → site → **Info** tab shows SSH host/IP, **port** (non-22), username,
and the SFTP/SSH password. Add an SSH key under the site's SSH keys, or use the password.
```bash
ssh USER@IP -p PORT
```
- **Webroot:** `/www/<site>_<id>/public` (the path is shown in the Info tab as "Path").
- **WP-CLI:** preinstalled as `wp`. `cd /www/<site>_.../public && wp core version`.
- **Staging:** MyKinsta → **Environments** → create staging; build there. Push with the
dashboard's **Push staging to live** (choose files and/or database).
## WP Engine
- **SSH gateway:** enable SSH Gateway, add your public key in the User Portal (**SSH keys**;
can take a few minutes to propagate).
```bash
ssh <environment>@<environment>.ssh.wpengine.net
```
(`<environment>` is the install name; production/staging/dev are separate installs.)
- **Webroot:** you land in the site root; WP lives under `sites/<environment>/` on some
plans, or directly in `~`. `find ~ -maxdepth 3 -name wp-config.php` to confirm.
- **WP-CLI:** preinstalled. Note WP Engine disallows some destructive `wp` commands.
- **Staging:** each install has **staging** and **dev** copies in the portal; build on
staging, then **Copy environment** staging → production.
## Flywheel / Local (Flywheel cloud)
- **SSH/SFTP:** open a support-provided SSH add-on or use **Local** (below) connected to
Flywheel. SFTP creds are in the dashboard.
- **Webroot:** `/www` (contains `wp-config.php`).
- **Staging:** dashboard **Demo/Staging** toggle; push to live from the dashboard.
---
## If the host blocks shell `wp` entirely
Some locked-down plans only expose SFTP + phpMyAdmin. Fallbacks:
- **Import via Elementor's own export:** build the page on a site you *can* CLI into
(local or staging), `wp post meta get <id> _elementor_data`, wrap it as an Elementor
**template export** (`.json`), and import through **Templates → Saved Templates → Import**
in the locked site's editor.
- **Run inject.php through a one-off must-use plugin** dropped via SFTP into
`wp-content/mu-plugins/` that reads the JSON and calls the same `update_post_meta`
sequence on chosen post ids, then delete it.
