<?php

declare(strict_types=1);

require_once __DIR__ . '/notam_lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "sync_notams.php must be run from CLI.\n";
    exit();
}

$lockName = 'xcsoar_notam_sync';

try {
    $scriptStartedAt = microtime(true);
    $pdo = notam_get_db_connection();
    notam_ensure_schema($pdo);
    notam_require_geojson_format();
    notam_acquire_lock($pdo, $lockName);
    notam_set_sync_activity($pdo, 'delta', true);

    $lastDeltaCursor = notam_get_state($pdo, 'faa_last_delta_cursor');
    if (!is_string($lastDeltaCursor) || $lastDeltaCursor === '') {
        throw new RuntimeException('No delta cursor found. Run reconcile_notams.php first.');
    }

    $cursorTime = new DateTimeImmutable($lastDeltaCursor);
    $minAllowed = notam_now_utc()->sub(new DateInterval('P1D'));
    if ($cursorTime < $minAllowed) {
        throw new RuntimeException('Delta cursor is older than 24 hours. Run reconcile_notams.php before resuming delta sync.');
    }

    $batch = notam_fetch_delta_batch($pdo, $lastDeltaCursor);
    $items = $batch['items'];
    $responseHeaders = is_array($batch['response_headers'] ?? null) ? $batch['response_headers'] : [];

    $storedCount = 0;
    foreach ($items as $item) {
        if (is_array($item) && notam_store_feature($pdo, $item)) {
            $storedCount++;
        }
    }

    $nextCursor = notam_compute_next_delta_cursor(
        $lastDeltaCursor,
        isset($responseHeaders['date']) && is_string($responseHeaders['date']) ? $responseHeaders['date'] : null,
        $items
    );

    $completedAt = notam_now_iso();
    notam_set_state($pdo, 'faa_last_delta_cursor', $nextCursor);
    notam_set_state($pdo, 'faa_last_delta_sync_at', $completedAt);

    if (random_int(1, 100) === 1) {
        notam_purge_expired_cache($pdo);
        notam_purge_old_inactive_items($pdo, 7);
    }

    $scriptElapsed = microtime(true) - $scriptStartedAt;
    fwrite(STDOUT, sprintf(
        "[%s] Delta sync OK | cursor=%s | next_cursor=%s | fetched=%d | stored=%d | time=%.2fs\n",
        $completedAt,
        $lastDeltaCursor,
        $nextCursor,
        count($items),
        $storedCount,
        $scriptElapsed
    ));
    fflush(STDOUT);
    notam_set_sync_activity($pdo, 'delta', false);
    notam_release_lock($pdo, $lockName);
    exit(0);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO) {
        try {
            notam_set_sync_activity($pdo, 'delta', false);
        } catch (Throwable $ignored) {
        }
        try {
            notam_release_lock($pdo, $lockName);
        } catch (Throwable $ignored) {
        }
    }
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
