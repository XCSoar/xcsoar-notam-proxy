# Scheduled sync (systemd / cron)

## Salt (notam.xcsoar.org)

Salt state `notam` installs **systemd timers** (`notam/timers.sls`):

| Unit | Schedule | Script |
|------|----------|--------|
| `notam-sync.timer` | every 3 minutes | `sync_notams.php` |
| `notam-reconcile.timer` | daily 02:17 | `reconcile_notams.php` |

Services run as the `notam` user, load **`/var/wwwusers/notam.xcsoar.org/.env`** via `EnvironmentFile`, and log to **journald** (no log files under `log/`).

```bash
journalctl -u notam-sync.service -u notam-reconcile.service -f
systemctl list-timers notam-sync.timer notam-reconcile.timer
```

Disable via pillar `notam_proxy:cron_managed: False`. See `deploy/README.md`.

## Manual cron (non-Salt)

Example entries if you do not use Salt timers:

```cron
*/3 * * * * cd /path/to/public_html && /usr/bin/php sync_notams.php
17 2 * * * cd /path/to/public_html && /usr/bin/php reconcile_notams.php
```

Ensure `.env` is beside `public_html` (or set `NOTAM_ENV_FILE`). On systemd hosts, prefer timers and journald over `>> logfile`.

## Notes

- Run **`reconcile_notams.php` once manually** before relying on the 3-minute delta job.
- Keep the daily reconcile at no more than once every 24 hours unless the FAA explicitly approves more.
- Use the real PHP path from `which php` on the target server.
- Ensure `.env` exists on the target server and is readable by the job user (Salt: mode `0640`, `notam` user).
