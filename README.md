# xcsoar-notam-proxy

PHP proxy for FAA NOTAM data backed by a local database cache.

The web endpoint no longer calls the FAA API on user requests. Instead:

- `reconcile_notams.php` performs a full GeoJSON refresh by classification.
- `sync_notams.php` applies FAA delta updates using `lastUpdatedDate`.
- `notam.php` serves user queries from the local database.
- `status.php` reports cache freshness and record counts.
- `notam_live.php` optionally exposes a direct FAA-backed comparison endpoint.
- `notam_compare.php` optionally compares DB-backed and live FAA results for the same query.
- `faa_connectivity_test.php` verifies FAA auth and a small authenticated API probe.

This keeps the FAA request rate decoupled from user traffic.

## Usage

GET:

```text
/notam.php?locationLongitude=a&locationLatitude=b&locationRadius=c
```

Required:

- `locationLongitude` (alias: `lon`) = longitude of the search center
- `locationLatitude` (alias: `lat`) = latitude of the search center
- `locationRadius` (alias: `radius`) = search radius in nautical miles (`0-100`)

## Delta mode

POST JSON body with known IDs and `lastUpdated` timestamps:

```json
{"known":{"NOTAM_ID":"lastUpdated","NOTAM_ID_2":"lastUpdated"}}
```

Response contains only new or changed items plus `removedIds`.

## Response format

Top-level fields:

- `totalCount`
- `items` (GeoJSON Feature list)

Delta mode adds:

- `delta`
- `removedIds`

The endpoint also returns sync metadata in headers:

- `X-NOTAM-Source: database`
- `X-NOTAM-Last-Delta-Sync`
- `X-NOTAM-Last-Full-Reconcile`
- `X-NOTAM-Sync-Age-Seconds`

If the local cache is too old, `notam.php` returns `503` instead of serving indefinitely stale data. During an in-progress full reconcile, the service allows a temporary reconcile grace window before failing closed.

## Environment variables

- `DB_SERVER`
- `DB_PORT` default: `3306`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`
- `FAA_ID`
- `FAA_SECRET`
- `FAA_API_BASE` default: `https://api-nms.aim.faa.gov/nmsapi`
- `FAA_AUTH_URL` default: `https://api-nms.aim.faa.gov/v1/auth/token`
- `NMS_RESPONSE_FORMAT` must be `GEOJSON`
- `FAA_RECONCILE_CLASSIFICATIONS` default: `INTERNATIONAL,MILITARY,LOCAL_MILITARY,DOMESTIC,FDC`
- `NOTAM_MAX_SYNC_AGE_SECONDS` default: `900`
- `NOTAM_RECONCILE_GRACE_SECONDS` default: `21600`
- `NOTAM_RECONCILE_MEMORY_LIMIT` default: `1024M` for CLI reconcile runs
- `NOTAM_ALLOW_LIVE_PROXY` optional boolean to enable `notam_live.php`
- `NOTAM_LIVE_PROXY_KEY` shared secret required in production when `notam_live.php` or `notam_compare.php` is enabled
- `FAA_CONNECTIVITY_KEY` shared secret required in production for `faa_connectivity_test.php`
- `NOTAM_ENV_FILE` optional absolute path to a `.env` file outside the document root
- `APP_ENV` default: `production`

The application loads `.env` automatically on both web and CLI entry points. Real process environment variables still take precedence.

In production, do not keep `.env` in a publicly reachable web directory. Prefer setting `NOTAM_ENV_FILE` to a path outside the document root. If that is not possible, your web server must explicitly deny access to `.env`.

## Setup flow

1. Configure the environment variables in `.env`.
2. Run `php reconcile_notams.php` once to bootstrap the local store.
3. Schedule `php sync_notams.php` every 3 minutes.
4. Schedule `php reconcile_notams.php` once per day.
5. Check `status.php` to confirm the cache is healthy.
6. Point clients at `notam.php`.

`reconcile_notams.php` builds a full staging snapshot and atomically swaps it into place, so readers should not see a mixed dataset during the daily refresh.

## Tests

Run the local regression suite with:

```bash
php tests/run.php
```

## Local helper

Useful local helper commands:

```bash
chmod +x scripts/check_remote.sh
chmod +x scripts/check_local_mysql.sh
scripts/check_remote.sh
LOCAL_DB_USER=your_local_db_user LOCAL_DB_PASS=your_local_db_password scripts/check_local_mysql.sh
```

## Local bootstrap and dump

If remote MySQL writes from a separate machine are too slow, you can build the cache into a local MySQL database and export it for import into production:

```bash
chmod +x scripts/build_local_dump.sh
chmod +x scripts/import_remote_dump.sh
LOCAL_DB_USER=your_local_db_user LOCAL_DB_PASS=your_local_db_password scripts/build_local_dump.sh
```

Important variables:

- `LOCAL_DB_SERVER` default: `127.0.0.1`
- `LOCAL_DB_PORT` default: `3306`
- `LOCAL_DB_NAME` default: `xcsoar_notam_local`
- `LOCAL_DB_USER` default: `root`
- `LOCAL_DB_PASS` default: empty
- `LOCAL_DUMP_PATH` default: `tmp/xcsoar_notam_local.sql`

This script reads credentials from `.env`. It writes the local cache to a local MySQL database, then dumps `notam_cache`, `notam_items`, and `notam_state` to a SQL file for import elsewhere.

To import that dump into the remote production database from the CLI:

```bash
scripts/import_remote_dump.sh
```

Important variables:

- `ENV_FILE` default: `.env`
- `DUMP_FILE` default: `tmp/xcsoar_notam_local.sql`
- `DB_SERVER_OVERRIDE` default: `server2.febas.net`
- `IMPORT_FORCE` default: `0`
- `MYSQL_EXTRA_ARGS` default: empty

The import helper reads the remote DB credentials from `.env`, prompts before replacing `notam_cache`, `notam_items`, and `notam_state`, and supports both `.sql` and `.sql.gz` dump files.

If your remote MySQL server has a broken or expired TLS certificate and the local MariaDB client refuses the connection, pass explicit client flags, for example:

```bash
MYSQL_EXTRA_ARGS='--skip-ssl-verify-server-cert' scripts/import_remote_dump.sh
```

If the server does not accept TLS cleanly at all and allows plaintext connections, use:

```bash
MYSQL_EXTRA_ARGS='--skip-ssl' scripts/import_remote_dump.sh
```

## Status endpoint

`status.php` returns JSON health information for the local cache:

- whether the cache is initialized
- whether the cache is stale
- sync age in seconds
- active and total row counts
- last delta and reconcile timestamps

## FAA connectivity test

`faa_connectivity_test.php` performs:

- FAA auth token POST
- one authenticated FAA checklist probe

It does not print the bearer token. In production, `FAA_CONNECTIVITY_KEY` must be set or the endpoint stays disabled. When configured, the endpoint requires `?key=...` or the `X-Connectivity-Key` header.

## Live comparison endpoint

`notam_live.php` can be enabled separately to fetch directly from the FAA API for response comparison against the DB-backed `notam.php`.

This should stay disabled by default. In production, enabling it without `NOTAM_LIVE_PROXY_KEY` keeps the endpoint disabled. Use it only for controlled comparison, not normal client traffic.

`notam_compare.php` uses the same guard and returns a structured comparison between cached and live results, including:

- cached count
- live count
- IDs only present in cache
- IDs only present from live FAA data
- IDs present in both but with different payloads

Optional query parameter:

- `mode=normalized` (default): compares cached and live after applying local geometry + active-status filtering on both sides
- `mode=raw`: compares unnormalized sets (`notam_fetch_local_features` vs FAA response)
- `mode=both`: returns both normalized comparison (`comparison`) and raw comparison (`comparisonRaw`)

## Database schema

The scripts will create the required tables automatically if the database user has permission. If you prefer to create them up front:

```sql
CREATE TABLE notam_cache (
  cache_key VARCHAR(128) NOT NULL,
  cache_value LONGTEXT NOT NULL,
  expiration DATETIME NOT NULL,
  PRIMARY KEY (cache_key),
  KEY idx_expiration (expiration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE notam_state (
  state_key VARCHAR(128) NOT NULL,
  state_value LONGTEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (state_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE notam_items (
  notam_id VARCHAR(64) NOT NULL,
  last_updated VARCHAR(40) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  classification VARCHAR(32) DEFAULT NULL,
  effective_start DATETIME DEFAULT NULL,
  effective_end DATETIME DEFAULT NULL,
  min_lat DOUBLE DEFAULT NULL,
  max_lat DOUBLE DEFAULT NULL,
  min_lon DOUBLE DEFAULT NULL,
  max_lon DOUBLE DEFAULT NULL,
  full_sync_run VARCHAR(64) DEFAULT NULL,
  payload LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (notam_id),
  KEY idx_active_bounds (is_active, min_lat, max_lat, min_lon, max_lon),
  KEY idx_last_updated (last_updated),
  KEY idx_full_sync_run (full_sync_run)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## Cron example

See [CRON.md](CRON.md).
