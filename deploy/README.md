# Deploy xcsoar-notam-proxy to notam.xcsoar.org

Salt `services.www` (pillar `www.domains` for this host) creates:

- **Document root:** `/var/wwwusers/notam.xcsoar.org/public_html/`
- **Logs:** `/var/wwwusers/notam.xcsoar.org/logs/`
- **Unix user:** `notam` (group `wwwusers`; `public_html` is writable by `notam`, group `www-data`)

After `state.apply`, issue a **Dehydrated** / ACME cert for `notam.xcsoar.org` (same flow as your other vhosts). DNS is already covered by your **wildcard** for `*.xcsoar.org`.

Apache must be able to run PHP (e.g. `libapache2-mod-php8.2` + `php8.2-mysql` / `php8.2-curl`) if not already provided by another state on the box.

## Salt deploy (preferred)

On the XCSoar web host, highstate includes **`notam-proxy`**: it runs `git.latest` on pillar `notam_proxy.repo` into `/opt/xcsoar-notam-proxy`, then **rsync** into `public_html` (excludes `.git`, `.github`, `.cursor`, `.env`, and `.htaccess` when Salt manages it), then `chown` to the `notam` user. Salt also lays down **`public_html/.htaccess`** (Apache hardening; same rules as repo `.htaccess.example`) and **`../.env`** for DB. Pillar (e.g. `pillar/hosts/v2202404131730263328.sls`): `notam_proxy.repo` / `notam_proxy.rev`; optional `notam_proxy.identity` for private repos; `notam_proxy:htaccess_managed` / `notam_proxy:env_file_managed` can be set to `False` to skip those files.

## Manual rsync (optional)

Adjust `HOST` and use a key that can `sudo` or deploy as `notam` if you use `rsync` over SSH:

```bash
HOST=v2202404131730263328.bestsrv.de
DEST=notam@${HOST}:/var/wwwusers/notam.xcsoar.org/public_html/

rsync -avz --delete \
  --exclude '.git/' --exclude 'deploy/' --exclude 'tmp/' --exclude 'log/' --exclude '.env' --exclude '.htaccess' \
  ./ "${DEST}"
```

Keep `.env` on the server only (path outside webroot or permissions); do not rsync your local `.env`.

## Cron on server

Salt **`notam/cron.sls`** (included from top-level `notam` state) installs the jobs from [CRON.md](../CRON.md) for the `notam` user:

- **Delta sync:** every 3 minutes — `sync_notams.php` in `public_html`, log `../log/xcsoar-notam-sync.log`
- **Reconcile:** daily at 02:17 — `reconcile_notams.php`, log `../log/xcsoar-notam-reconcile.log`

Run **`reconcile_notams.php` once manually** before relying on the 3-minute delta job. Pillar: `notam_proxy:cron_managed: False` disables both; `cron_sync_enabled` / `cron_reconcile_enabled` toggle individually; `php_bin`, `log_dir` override defaults.

For non-Salt hosts, use the shell examples in CRON.md.
