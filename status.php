<?php

declare(strict_types=1);

require_once __DIR__ . '/notam_lib.php';

try {
    $pdo = notam_get_db_connection();
    notam_ensure_schema($pdo);

    $health = notam_get_sync_health($pdo);
    $counts = [
        'active' => (int)$pdo->query("SELECT COUNT(*) FROM notam_items WHERE is_active = 1")->fetchColumn(),
        'total' => (int)$pdo->query("SELECT COUNT(*) FROM notam_items")->fetchColumn(),
    ];

    http_response_code($health['stale'] ? 503 : 200);
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => !$health['stale'],
        'initialized' => $health['initialized'],
        'stale' => $health['stale'],
        'maintenance' => $health['maintenance'] ?? false,
        'ageSeconds' => $health['ageSeconds'],
        'maxSyncAgeSeconds' => $health['maxSyncAgeSeconds'],
        'counts' => $counts,
        'sync' => $health['syncStatus'],
    ]);
} catch (Throwable $e) {
    notam_send_error_response(500, $e);
}
