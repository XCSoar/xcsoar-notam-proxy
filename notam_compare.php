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
    $mode = strtolower((string)($_GET['mode'] ?? 'normalized'));
    if (!in_array($mode, ['normalized', 'raw', 'both'], true)) {
        throw new InvalidArgumentException('Invalid mode. Supported values: normalized, raw, both');
    }

    $cachedRawItems = notam_fetch_local_features($pdo, $query['latitude'], $query['longitude'], $query['radius']);
    $cachedItems = notam_filter_features_for_query($cachedRawItems, $query['latitude'], $query['longitude'], $query['radius'], true);
    $liveRawItems = notam_fetch_live_features($pdo, $query['latitude'], $query['longitude'], $query['radius']);
    $liveItems = notam_filter_features_for_query($liveRawItems, $query['latitude'], $query['longitude'], $query['radius'], true);

    $normalizedComparison = notam_compare_item_sets($cachedItems, $liveItems);
    $rawComparison = notam_compare_item_sets($cachedRawItems, $liveRawItems);

    $response = [
        'query' => $query,
        'mode' => $mode,
        'normalization' => [
            'cachedRawCount' => count($cachedRawItems),
            'cachedFilteredCount' => count($cachedItems),
            'liveRawCount' => count($liveRawItems),
            'liveFilteredCount' => count($liveItems),
            'filters' => [
                'cached' => 'local-geometry-and-active-only',
                'live' => 'local-geometry-and-active-only',
            ],
        ],
    ];

    if ($mode === 'raw') {
        $response['comparison'] = $rawComparison;
    } elseif ($mode === 'both') {
        $response['comparison'] = $normalizedComparison;
        $response['comparisonRaw'] = $rawComparison;
    } else {
        $response['comparison'] = $normalizedComparison;
    }

    header('Content-Type: application/json');
    echo json_encode($response);
} catch (InvalidArgumentException $e) {
    notam_send_error_response(400, $e);
} catch (RuntimeException $e) {
    notam_send_error_response(503, $e);
} catch (Throwable $e) {
    notam_send_error_response(500, $e);
}
