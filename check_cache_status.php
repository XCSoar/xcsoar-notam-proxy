<?php

declare(strict_types=1);

require_once __DIR__ . '/notam_lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "check_cache_status.php must be run from CLI.\n";
    exit();
}

try {
    $pdo = notam_get_db_connection();
    notam_ensure_schema($pdo);

    $counts = [
        'active' => (int)$pdo->query("SELECT COUNT(*) FROM notam_items WHERE is_active = 1")->fetchColumn(),
        'total' => (int)$pdo->query("SELECT COUNT(*) FROM notam_items")->fetchColumn(),
    ];
    $dbName = getenv('DB_NAME') ?: 'notamcache';
    $sizeStmt = $pdo->prepare(
        "SELECT
            COALESCE(DATA_LENGTH, 0) AS data_length,
            COALESCE(INDEX_LENGTH, 0) AS index_length
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'notam_items'"
    );
    $sizeStmt->execute([$dbName]);
    $sizeRow = $sizeStmt->fetch() ?: ['data_length' => 0, 'index_length' => 0];
    $dataBytes = (int)($sizeRow['data_length'] ?? 0);
    $indexBytes = (int)($sizeRow['index_length'] ?? 0);
    $totalBytes = $dataBytes + $indexBytes;

    $avgPayloadBytes = $pdo->query("SELECT COALESCE(AVG(CHAR_LENGTH(payload)), 0) FROM notam_items")->fetchColumn();
    $avgPayloadBytes = $avgPayloadBytes !== false ? (float)$avgPayloadBytes : 0.0;

    $sync = notam_get_sync_status($pdo);
    $health = notam_get_sync_health($pdo);
    $lockStmt = $pdo->prepare("SELECT IS_USED_LOCK(?)");
    $lockStmt->execute(['xcsoar_notam_sync']);
    $lockOwner = $lockStmt->fetchColumn();
    $lockStatus = $lockOwner === null ? 'free' : 'held';

    echo "Cache status\n";
    echo "active=" . $counts['active'] . "\n";
    echo "total=" . $counts['total'] . "\n";
    echo "table_data_size=" . notam_format_bytes_human($dataBytes) . "\n";
    echo "table_index_size=" . notam_format_bytes_human($indexBytes) . "\n";
    echo "table_total_size=" . notam_format_bytes_human($totalBytes) . "\n";
    echo "avg_payload_size=" . notam_format_bytes_human((int)round($avgPayloadBytes)) . "\n";
    echo "initialized=" . ($health['initialized'] ? 'yes' : 'no') . "\n";
    echo "stale=" . ($health['stale'] ? 'yes' : 'no') . "\n";
    echo "maintenance=" . (($health['maintenance'] ?? false) ? 'yes' : 'no') . "\n";
    echo "age_seconds=" . ($health['ageSeconds'] === null ? '-' : (string)$health['ageSeconds']) . "\n";
    echo "max_sync_age_seconds=" . $health['maxSyncAgeSeconds'] . "\n";
    echo "sync_lock_status=" . $lockStatus . "\n";
    echo "sync_lock_owner=" . ($lockOwner === null ? '-' : (string)$lockOwner) . "\n";
    echo "sync_in_progress=" . ($sync['sync_in_progress'] ?? '-') . "\n";
    echo "sync_mode=" . (($sync['sync_mode'] ?? '') === '' ? '-' : $sync['sync_mode']) . "\n";
    echo "sync_started_at=" . (($sync['sync_started_at'] ?? '') === '' ? '-' : $sync['sync_started_at']) . "\n";
    echo "last_delta_cursor=" . ($sync['last_delta_cursor'] ?? '-') . "\n";
    echo "last_delta_sync_at=" . ($sync['last_delta_sync_at'] ?? '-') . "\n";
    echo "last_full_reconcile_started_at=" . ($sync['last_full_reconcile_started_at'] ?? '-') . "\n";
    echo "last_full_reconcile_completed_at=" . ($sync['last_full_reconcile_completed_at'] ?? '-') . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
