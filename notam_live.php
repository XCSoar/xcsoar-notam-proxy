<?php

declare(strict_types=1);

require_once __DIR__ . '/notam_lib.php';

try {
    $enabled = getenv('NOTAM_ALLOW_LIVE_PROXY');
    if (!in_array(strtolower((string)$enabled), ['1', 'true', 'yes', 'on'], true)) {
        throw new RuntimeException('Live FAA proxy is disabled');
    }

    notam_require_optional_shared_secret('NOTAM_LIVE_PROXY_KEY');

    $pdo = notam_get_db_connection();
    notam_ensure_schema($pdo);
    notam_require_geojson_format();

    $query = notam_parse_query_parameters();
    [$deltaRequest, $knownNotams] = notam_parse_delta_request();

    $items = notam_fetch_live_features($pdo, $query['latitude'], $query['longitude'], $query['radius']);
    $response = $deltaRequest
        ? notam_build_delta_response_payload($items, $knownNotams)
        : notam_build_response_payload($items);

    header('Content-Type: application/json');
    header('X-NOTAM-Source: faa-live');
    echo json_encode($response);
} catch (InvalidArgumentException $e) {
    notam_send_error_response(400, $e);
} catch (RuntimeException $e) {
    notam_send_error_response(503, $e);
} catch (Throwable $e) {
    notam_send_error_response(500, $e);
}
