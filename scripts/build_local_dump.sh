#!/bin/sh

set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
ENV_FILE=${ENV_FILE:-"$ROOT_DIR/.env"}

LOCAL_DB_SERVER=${LOCAL_DB_SERVER:-127.0.0.1}
LOCAL_DB_PORT=${LOCAL_DB_PORT:-3306}
LOCAL_DB_NAME=${LOCAL_DB_NAME:-xcsoar_notam_local}
LOCAL_DB_USER=${LOCAL_DB_USER:-root}
LOCAL_DB_PASS=${LOCAL_DB_PASS:-}
LOCAL_DUMP_PATH=${LOCAL_DUMP_PATH:-"$ROOT_DIR/tmp/${LOCAL_DB_NAME}.sql"}
NOTAM_RECONCILE_BATCH_SIZE=${NOTAM_RECONCILE_BATCH_SIZE:-1000}
NOTAM_RECONCILE_MEMORY_LIMIT=${NOTAM_RECONCILE_MEMORY_LIMIT:-1024M}

mkdir -p "$ROOT_DIR/tmp"

if [ -f "$ENV_FILE" ]; then
    set -a
    . "$ENV_FILE"
    set +a
fi

export DB_SERVER="$LOCAL_DB_SERVER"
export DB_NAME="$LOCAL_DB_NAME"
export DB_USER="$LOCAL_DB_USER"
export DB_PASS="$LOCAL_DB_PASS"
export FAA_API_BASE="${FAA_API_BASE:-https://api-nms.aim.faa.gov/nmsapi}"
export FAA_AUTH_URL="${FAA_AUTH_URL:-https://api-nms.aim.faa.gov/v1/auth/token}"
export NMS_RESPONSE_FORMAT="${NMS_RESPONSE_FORMAT:-GEOJSON}"
export APP_ENV="${APP_ENV:-production}"
export NOTAM_RECONCILE_BATCH_SIZE
export NOTAM_RECONCILE_MEMORY_LIMIT

if [ -z "${FAA_ID:-}" ] || [ -z "${FAA_SECRET:-}" ]; then
    echo "FAA credentials are missing. Provide them via .env." >&2
    exit 1
fi

MYSQL_ARGS="--host=$LOCAL_DB_SERVER --port=$LOCAL_DB_PORT --user=$LOCAL_DB_USER --protocol=tcp"
MYSQLDUMP_ARGS="--host=$LOCAL_DB_SERVER --port=$LOCAL_DB_PORT --user=$LOCAL_DB_USER --protocol=tcp"

if [ -n "$LOCAL_DB_PASS" ]; then
    MYSQL_ARGS="$MYSQL_ARGS --password=$LOCAL_DB_PASS"
    MYSQLDUMP_ARGS="$MYSQLDUMP_ARGS --password=$LOCAL_DB_PASS"
fi

echo "Ensuring local database exists: $LOCAL_DB_NAME"
# shellcheck disable=SC2086
mysql $MYSQL_ARGS -e "CREATE DATABASE IF NOT EXISTS \`$LOCAL_DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "Running local full reconcile"
php "$ROOT_DIR/reconcile_notams.php"

echo "Dumping NOTAM tables to $LOCAL_DUMP_PATH"
# shellcheck disable=SC2086
mysqldump $MYSQLDUMP_ARGS \
    --single-transaction \
    --skip-lock-tables \
    --no-tablespaces \
    "$LOCAL_DB_NAME" \
    notam_cache \
    notam_items \
    notam_state > "$LOCAL_DUMP_PATH"

echo "Done"
echo "Local DB: $LOCAL_DB_NAME"
echo "Dump: $LOCAL_DUMP_PATH"
