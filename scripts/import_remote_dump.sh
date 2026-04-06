#!/bin/sh

set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
ENV_FILE=${ENV_FILE:-"$ROOT_DIR/.env"}
DUMP_FILE=${DUMP_FILE:-"$ROOT_DIR/tmp/xcsoar_notam_local.sql"}
DB_SERVER_OVERRIDE=${DB_SERVER_OVERRIDE:-server2.febas.net}
MYSQL_EXTRA_ARGS=${MYSQL_EXTRA_ARGS:-}

if [ ! -f "$ENV_FILE" ]; then
    echo ".env file not found: $ENV_FILE" >&2
    exit 1
fi

if [ ! -f "$DUMP_FILE" ]; then
    echo "dump file not found: $DUMP_FILE" >&2
    exit 1
fi

echo "Preparing remote import"
echo "host=$DB_SERVER_OVERRIDE"
echo "dump=$DUMP_FILE"

if [ "${IMPORT_FORCE:-0}" != "1" ]; then
    echo "This will replace notam_cache, notam_items, and notam_state in the remote DB."
    printf "Continue? [y/N] "
    read -r answer
    case "$answer" in
        y|Y|yes|YES)
            ;;
        *)
            echo "Aborted."
            exit 1
            ;;
    esac
fi

case "$DUMP_FILE" in
    *.sql)
        IMPORT_CMD='cat "$2"'
        ;;
    *.sql.gz|*.gz)
        IMPORT_CMD='gzip -dc "$2"'
        ;;
    *)
        echo "unsupported dump format: $DUMP_FILE" >&2
        echo "expected .sql or .sql.gz" >&2
        exit 1
        ;;
esac

exec env DB_SERVER="$DB_SERVER_OVERRIDE" \
    MYSQL_EXTRA_ARGS="$MYSQL_EXTRA_ARGS" \
    sh -c '
        set -eu
        set -a
        . "$1"
        set +a
        MYSQL_ARGS="--host=$DB_SERVER --port=${DB_PORT:-3306} --user=$DB_USER --protocol=tcp"
        if [ -n "${DB_PASS:-}" ]; then
            MYSQL_ARGS="$MYSQL_ARGS --password=$DB_PASS"
        fi
        if [ -n "${MYSQL_EXTRA_ARGS:-}" ]; then
            MYSQL_ARGS="$MYSQL_ARGS $MYSQL_EXTRA_ARGS"
        fi
        # shellcheck disable=SC2086
        '"$IMPORT_CMD"' | mysql $MYSQL_ARGS "$DB_NAME"
    ' sh "$ENV_FILE" "$DUMP_FILE"
