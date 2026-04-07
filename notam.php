<?php

declare(strict_types=1);

require_once __DIR__ . '/notam_lib.php';

try {
    $pdo = notam_get_db_connection();
    notam_ensure_schema($pdo);
    notam_require_geojson_format();
    $query = notam_parse_query_parameters();

    $syncHealth = notam_get_sync_health($pdo);
    if (!$syncHealth['initialized']) {
        throw new RuntimeException('NOTAM cache is empty. Run reconcile_notams.php before serving traffic.');
    }
    if ($syncHealth['stale']) {
        throw new RuntimeException('NOTAM cache is stale. Check scheduled sync jobs.');
    }

    [$deltaRequest, $knownNotams] = notam_parse_delta_request();

    $items = notam_fetch_local_features($pdo, $query['latitude'], $query['longitude'], $query['radius']);
    // Enforce runtime active-status checks (effectiveEnd/cancelationDate) for cached rows.
    $items = notam_filter_features_for_query($items, $query['latitude'], $query['longitude'], $query['radius'], true);
    $response = $deltaRequest
        ? notam_build_delta_response_payload($items, $knownNotams)
        : notam_build_response_payload($items);

    $syncStatus = $syncHealth['syncStatus'];
    if (is_string($syncStatus['last_delta_sync_at']) && $syncStatus['last_delta_sync_at'] !== '') {
        header('X-NOTAM-Last-Delta-Sync: ' . $syncStatus['last_delta_sync_at']);
    }
    if (is_string($syncStatus['last_full_reconcile_completed_at']) && $syncStatus['last_full_reconcile_completed_at'] !== '') {
        header('X-NOTAM-Last-Full-Reconcile: ' . $syncStatus['last_full_reconcile_completed_at']);
    }
    if (is_int($syncHealth['ageSeconds'])) {
        header('X-NOTAM-Sync-Age-Seconds: ' . (string)$syncHealth['ageSeconds']);
    }
    if (!empty($syncHealth['maintenance'])) {
        header('X-NOTAM-Maintenance: reconcile');
    }

    header('Content-Type: application/json');
    header('X-NOTAM-Source: database');
    echo json_encode($response);
} catch (InvalidArgumentException $e) {
    notam_send_error_response(400, $e);
} catch (RuntimeException $e) {
    notam_send_error_response(503, $e);
} catch (Throwable $e) {
    notam_send_error_response(500, $e);
}
