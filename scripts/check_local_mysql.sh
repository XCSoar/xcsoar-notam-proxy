#!/bin/sh

set -eu

LOCAL_DB_SERVER=${LOCAL_DB_SERVER:-127.0.0.1}
LOCAL_DB_PORT=${LOCAL_DB_PORT:-3306}
LOCAL_DB_NAME=${LOCAL_DB_NAME:-xcsoar_notam_local}
LOCAL_DB_USER=${LOCAL_DB_USER:-root}
LOCAL_DB_PASS=${LOCAL_DB_PASS:-}

MYSQL_ARGS="--host=$LOCAL_DB_SERVER --port=$LOCAL_DB_PORT --user=$LOCAL_DB_USER --protocol=tcp"

if [ -n "$LOCAL_DB_PASS" ]; then
    MYSQL_ARGS="$MYSQL_ARGS --password=$LOCAL_DB_PASS"
fi

echo "Checking local MySQL connectivity"
echo "server=$LOCAL_DB_SERVER"
echo "port=$LOCAL_DB_PORT"
echo "database=$LOCAL_DB_NAME"
echo "user=$LOCAL_DB_USER"

# shellcheck disable=SC2086
mysql $MYSQL_ARGS -e "SELECT VERSION() AS mysql_version;"

echo "Ensuring database exists"
# shellcheck disable=SC2086
mysql $MYSQL_ARGS -e "CREATE DATABASE IF NOT EXISTS \`$LOCAL_DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "Checking database access"
# shellcheck disable=SC2086
mysql $MYSQL_ARGS "$LOCAL_DB_NAME" -e "SELECT DATABASE() AS current_database;"

echo "OK"
