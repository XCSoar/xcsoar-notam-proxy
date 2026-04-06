<?php

declare(strict_types=1);

require_once __DIR__ . '/notam_lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "reconcile_notams.php must be run from CLI.\n";
    exit();
}

$memoryLimit = getenv('NOTAM_RECONCILE_MEMORY_LIMIT') ?: '1024M';
$batchSize = max(50, (int)(getenv('NOTAM_RECONCILE_BATCH_SIZE') ?: 500));
ini_set('memory_limit', $memoryLimit);
set_time_limit(0);

$lockName = 'xcsoar_notam_sync';

try {
    $pdo = notam_get_db_connection();
    notam_ensure_schema($pdo);
    notam_require_geojson_format();
    notam_acquire_lock($pdo, $lockName);
    notam_set_sync_activity($pdo, 'reconcile', true);

    $classifications = notam_get_reconcile_classifications();
    $fullSyncRun = gmdate('YmdHis') . '_' . bin2hex(random_bytes(4));
    $reconcileStartedAt = notam_now_iso();
    notam_set_state($pdo, 'faa_last_full_reconcile_started_at', $reconcileStartedAt);
    $stageTable = notam_get_reconcile_stage_table();
    notam_reset_reconcile_stage($pdo, $stageTable);
    $storeStmt = notam_prepare_store_feature_statement($pdo, $stageTable);

    $storedCount = 0;
    $fetchedCount = 0;
    $scriptStartedAt = microtime(true);
    foreach ($classifications as $classification) {
        $classificationStartedAt = microtime(true);
        $batchCount = 0;
        $pdo->beginTransaction();
        $classificationFetched = notam_process_classification_items(
            $pdo,
            $classification,
            static function (array $item) use ($pdo, $storeStmt, $fullSyncRun, &$storedCount, &$batchCount, $batchSize): void {
                if (notam_store_feature_with_statement($storeStmt, $item, $fullSyncRun)) {
                    $storedCount++;
                    $batchCount++;
                }

                if ($batchCount >= $batchSize) {
                    $pdo->commit();
                    $pdo->beginTransaction();
                    $batchCount = 0;
                }
            }
        );
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
        $fetchedCount += $classificationFetched;

        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        $classificationElapsed = microtime(true) - $classificationStartedAt;
        fwrite(STDOUT, sprintf(
            "[%s] Classification %s OK | fetched=%d | time=%.2fs\n",
            notam_now_iso(),
            $classification,
            $classificationFetched,
            $classificationElapsed
        ));
        fflush(STDOUT);
    }

    notam_swap_reconcile_stage_into_live($pdo, $stageTable, notam_get_live_items_table(), notam_get_reconcile_backup_table());
    $deactivatedCount = 0;
    $purgedCount = 0;
    notam_purge_expired_cache($pdo);

    $reconcileCompletedAt = notam_now_iso();
    notam_set_state($pdo, 'faa_last_full_reconcile_completed_at', $reconcileCompletedAt);
    notam_set_state($pdo, 'faa_last_delta_cursor', notam_compute_reconcile_delta_cursor($reconcileStartedAt));
    notam_set_state($pdo, 'faa_last_delta_sync_at', $reconcileCompletedAt);
    notam_set_sync_activity($pdo, 'reconcile', false);

    $scriptElapsed = microtime(true) - $scriptStartedAt;
    fwrite(STDOUT, sprintf(
        "[%s] Full reconcile OK | classifications=%d | fetched=%d | stored=%d | deactivated=%d | purged=%d | total_time=%.2fs\n",
        $reconcileCompletedAt,
        count($classifications),
        $fetchedCount,
        $storedCount,
        $deactivatedCount,
        $purgedCount,
        $scriptElapsed
    ));
    fflush(STDOUT);

    notam_release_lock($pdo, $lockName);
    exit(0);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        try {
            notam_set_sync_activity($pdo, 'reconcile', false);
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
