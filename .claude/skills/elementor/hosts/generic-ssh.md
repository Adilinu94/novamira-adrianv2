# Host adapter: generic SSH + WP-CLI
For any host where you have SSH and WP-CLI is (or can be) installed: a VPS,
DigitalOcean droplet, Linode, AWS Lightsail/EC2, self-managed Linux, most cPanel
plans with SSH, etc. This is the portable baseline the core technique was written for.
## 1. Get a shell
```bash
ssh USER@HOST # key-based preferred; cPanel sometimes uses a custom port: -p 2222
```
If you only have a password, `ssh-copy-id USER@HOST` to install your key first.
## 2. Find the webroot
The directory containing `wp-config.php`. Common locations:
- `/var/www/html`, `/var/www/<site>/public_html`
- cPanel: `/home/<cpuser>/public_html`
- nginx/Apache vhost root
```bash
# locate it from a known URL, or:
find /var/www /home -maxdepth 4 -name wp-config.php 2>/dev/null
```
Set `WEBROOT=<that dir>`.
## 3. Confirm WP-CLI
```bash
ssh USER@HOST "cd $WEBROOT && wp core version"
```
No `wp`? Install it for your user:
```bash
ssh USER@HOST 'curl -sO https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
&& chmod +x wp-cli.phar && mkdir -p ~/bin && mv wp-cli.phar ~/bin/wp && echo "PATH=\$HOME/bin:\$PATH" >> ~/.bashrc'
# then use ~/bin/wp, or re-login
```
## 4. Run everything from the webroot
```bash
ssh USER@HOST "cd $WEBROOT && wp <cmd>"
```
**Why `cd` and not `--path`:** some installs `require` salts/config with a relative
path, so `wp --path=$WEBROOT` fatals on `wp-salt.php`/`wp-config.php`. `cd` first is safe.
## 5. Push the page JSON
```bash
ssh USER@HOST "cat > ~/page.json" < local.json # portable; no scp needed
ssh USER@HOST "cd $WEBROOT && wp eval-file ~/path-to/inject.php \$PID ~/page.json \
\"\$(wp plugin get elementor --field=version)\" elementor_header_footer && wp elementor flush-css"
```
(Put `inject.php` in your home dir the same way: `ssh USER@HOST "cat > ~/inject.php" < inject.php`.)
## 6. Staging
No managed staging here — roll your own: clone the site into a subdir/subdomain, or
`wp db export backup.sql` before any change so you can restore. Build pages as
`--post_status=draft` and noindex them until approved, then publish.
## Permissions note
Files written by your SSH user are owned by that user. If WordPress (php-fpm) runs as
`www-data`/`nobody`, DB writes (pages, meta) are fine, but `wp media import` may create
files your web user can't manage — run media imports as the web user (`sudo -u www-data wp ...`)
where you have sudo.
