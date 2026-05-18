# Cron Setup

Example cron entries for this project:

```cron
*/3 * * * * cd /path/to/public_html && set -a && . /path/to/.env && set +a && /usr/bin/php sync_notams.php >> /path/to/log/xcsoar-notam-sync.log 2>&1
17 2 * * * cd /path/to/public_html && set -a && . /path/to/.env && set +a && /usr/bin/php reconcile_notams.php >> /path/to/log/xcsoar-notam-reconcile.log 2>&1
```

## Salt (notam.xcsoar.org)

On the XCSoar web host, Salt state `notam` (`notam/cron.sls`) installs these jobs for the `notam` user under `public_html`, with logs in `../log/` (sibling of `public_html`, outside the webroot). Disable via pillar `notam_proxy:cron_managed: False`. See `deploy/README.md`.

## Notes

- These cron examples assume `.env` is present in the project root (on Salt hosts: beside `public_html`). The PHP entry points load it automatically.
- Run `/usr/bin/php reconcile_notams.php` once manually before enabling the 3-minute delta job.
- Keep the daily reconcile at no more than once every 24 hours unless the FAA explicitly approves more.
- Use the real PHP path from `which php` on the target server.
- Pick log paths that exist and are writable by the cron user.
- Ensure the `log/` directory exists and is writable by the cron user.
- Ensure `.env` exists on the target server and remains readable by the cron user.
- If your host provides a control-panel cron UI instead of shell cron, use the same commands there.
