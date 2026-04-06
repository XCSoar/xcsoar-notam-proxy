# Cron Setup

Example cron entries for this project:

```cron
*/3 * * * * cd /path/to/xcsoar-notam-proxy && /usr/bin/php sync_notams.php >> ./log/xcsoar-notam-sync.log 2>&1
17 2 * * * cd /path/to/xcsoar-notam-proxy && /usr/bin/php reconcile_notams.php >> ./log/xcsoar-notam-reconcile.log 2>&1
```

## Notes

- These cron examples assume `.env` is present in the project root. The PHP entry points load it automatically.
- Run `/usr/bin/php reconcile_notams.php` once manually before enabling the 3-minute delta job.
- Keep the daily reconcile at no more than once every 24 hours unless the FAA explicitly approves more.
- Use the real PHP path from `which php` on the target server.
- Pick log paths that exist and are writable by the cron user.
- Ensure the `log/` directory exists and is writable by the cron user.
- Ensure `.env` exists on the target server and remains readable by the cron user.
- If your host provides a control-panel cron UI instead of shell cron, use the same commands there.
