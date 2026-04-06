#!/bin/sh

set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
ENV_FILE=${ENV_FILE:-"$ROOT_DIR/.env"}
DB_SERVER_OVERRIDE=${DB_SERVER_OVERRIDE:-server2.febas.net}
STATE_DIR=${STATE_DIR:-"$ROOT_DIR/tmp"}
STATE_FILE=${STATE_FILE:-"$STATE_DIR/check_remote_state"}

mkdir -p "$STATE_DIR"

if [ ! -f "$ENV_FILE" ]; then
    echo ".env file not found: $ENV_FILE" >&2
    exit 1
fi

OUTPUT=$(env DB_SERVER="$DB_SERVER_OVERRIDE" sh -c '
    set -eu
    set -a
    . "$1"
    set +a
    exec php "$2"
' sh "$ENV_FILE" "$ROOT_DIR/check_cache_status.php")

printf '%s\n' "$OUTPUT"

NOW_TS=$(date +%s)
ACTIVE=$(printf '%s\n' "$OUTPUT" | awk -F= '/^active=/{print $2}')
TOTAL=$(printf '%s\n' "$OUTPUT" | awk -F= '/^total=/{print $2}')

if [ -f "$STATE_FILE" ]; then
    PREV_TS=$(awk -F= '/^timestamp=/{print $2}' "$STATE_FILE" 2>/dev/null || true)
    PREV_ACTIVE=$(awk -F= '/^active=/{print $2}' "$STATE_FILE" 2>/dev/null || true)
    PREV_TOTAL=$(awk -F= '/^total=/{print $2}' "$STATE_FILE" 2>/dev/null || true)

    if [ -n "${PREV_TS:-}" ] && [ -n "${PREV_ACTIVE:-}" ] && [ -n "${PREV_TOTAL:-}" ] && [ "$NOW_TS" -gt "$PREV_TS" ]; then
        DELTA_SECONDS=$((NOW_TS - PREV_TS))
        DELTA_ACTIVE=$((ACTIVE - PREV_ACTIVE))
        DELTA_TOTAL=$((TOTAL - PREV_TOTAL))
        ACTIVE_PER_MIN=$((DELTA_ACTIVE * 60 / DELTA_SECONDS))
        TOTAL_PER_MIN=$((DELTA_TOTAL * 60 / DELTA_SECONDS))

        echo "growth_since_last_check_active=${DELTA_ACTIVE} rows in ${DELTA_SECONDS}s (${ACTIVE_PER_MIN}/min)"
        echo "growth_since_last_check_total=${DELTA_TOTAL} rows in ${DELTA_SECONDS}s (${TOTAL_PER_MIN}/min)"
    fi
fi

cat > "$STATE_FILE" <<EOF
timestamp=$NOW_TS
active=$ACTIVE
total=$TOTAL
EOF
