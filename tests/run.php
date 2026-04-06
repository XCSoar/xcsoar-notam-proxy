<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/notam_lib.php';

function test_assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function test_assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual:   " . var_export($actual, true));
    }
}

function test_make_feature(string $id, string $lastUpdated, ?array $geometry, array $notamOverrides = []): array
{
    $notam = array_merge([
        'id' => $id,
        'lastUpdated' => $lastUpdated,
        'type' => 'N',
        'classification' => 'DOMESTIC',
    ], $notamOverrides);

    return [
        'type' => 'Feature',
        'properties' => [
            'coreNOTAMData' => [
                'notam' => $notam,
            ],
        ],
        'geometry' => $geometry,
    ];
}

function test_run(string $name, callable $fn): array
{
    try {
        $fn();
        return ['name' => $name, 'status' => 'PASS'];
    } catch (Throwable $e) {
        return ['name' => $name, 'status' => 'FAIL', 'message' => $e->getMessage()];
    }
}

$tests = [];

$tests[] = test_run('cursor uses FAA date with overlap', function (): void {
    $next = notam_compute_next_delta_cursor(
        '2026-04-06T12:00:00Z',
        'Mon, 06 Apr 2026 12:03:00 GMT',
        [],
        new DateTimeImmutable('2026-04-06T12:10:00Z'),
        60
    );
    test_assert_same('2026-04-06T12:02:00Z', $next, 'Delta cursor should advance from FAA response time, not local wall clock.');
});

$tests[] = test_run('cursor never moves backwards', function (): void {
    $next = notam_compute_next_delta_cursor(
        '2026-04-06T12:00:00Z',
        'Mon, 06 Apr 2026 12:00:20 GMT',
        [],
        null,
        60
    );
    test_assert_same('2026-04-06T12:00:00Z', $next, 'Delta cursor must not move backwards when overlap exceeds the step.');
});

$tests[] = test_run('cursor honors latest item timestamp', function (): void {
    $items = [
        test_make_feature('A', '2026-04-06T12:03:20Z', [
            'type' => 'Point',
            'coordinates' => [8.0, 50.0],
        ]),
    ];
    $next = notam_compute_next_delta_cursor(
        '2026-04-06T12:00:00Z',
        'Mon, 06 Apr 2026 12:03:00 GMT',
        $items,
        null,
        60
    );
    test_assert_same('2026-04-06T12:02:20Z', $next, 'Delta cursor should not fall behind the newest FAA item timestamp.');
});

$tests[] = test_run('reconcile cursor applies overlap', function (): void {
    $cursor = notam_compute_reconcile_delta_cursor('2026-04-06T12:00:00Z', 60);
    test_assert_same('2026-04-06T11:59:00Z', $cursor, 'Reconcile should restart delta sync slightly before the reconcile start.');
});

$tests[] = test_run('response payload keeps compatibility fields', function (): void {
    $response = notam_build_response_payload([
        test_make_feature('A', '2026-04-06T12:00:00Z', [
            'type' => 'Point',
            'coordinates' => [8.0, 50.0],
        ]),
    ]);
    test_assert_same(1, $response['pageNum'], 'pageNum should remain present.');
    test_assert_same(1, $response['totalPages'], 'totalPages should remain present.');
    test_assert_same(1, $response['totalCount'], 'totalCount should equal item count.');
});

$tests[] = test_run('db dsn includes port when configured', function (): void {
    $dsn = notam_build_db_dsn('db.example.test', 'xcsoar_notam', 'utf8mb4', '3307');
    test_assert_same(
        'mysql:host=db.example.test;port=3307;dbname=xcsoar_notam;charset=utf8mb4',
        $dsn,
        'DB DSN should include the configured port.'
    );
});

$tests[] = test_run('delta payload reports changed and removed ids', function (): void {
    $items = [
        test_make_feature('A', '2026-04-06T12:00:00Z', [
            'type' => 'Point',
            'coordinates' => [8.0, 50.0],
        ]),
        test_make_feature('B', '2026-04-06T12:05:00Z', [
            'type' => 'Point',
            'coordinates' => [8.1, 50.1],
        ]),
    ];
    $known = [
        'A' => '2026-04-06T12:00:00Z',
        'B' => '2026-04-06T12:00:01Z',
        'C' => '2026-04-06T11:00:00Z',
    ];
    $response = notam_build_delta_response_payload($items, $known);
    test_assert_same(1, $response['totalCount'], 'Only the changed item should be returned.');
    test_assert_same('B', $response['items'][0]['properties']['coreNOTAMData']['notam']['id'], 'Changed item should be returned.');
    test_assert_same(['C'], $response['removedIds'], 'Removed IDs should be reported.');
});

$tests[] = test_run('antimeridian bounds overlap for complex geometry', function (): void {
    $feature = test_make_feature('A', '2026-04-06T12:00:00Z', [
        'type' => 'MultiPoint',
        'coordinates' => [
            [-179.95, 0.00],
            [-179.85, 0.05],
        ],
    ]);
    $queryBounds = notam_circle_bounds(0.0, 179.9, 20.0);
    test_assert_true(
        notam_feature_matches_query($feature, 0.0, 179.9, 20.0, $queryBounds),
        'Complex geometry near the antimeridian should match wrapped queries.'
    );
});

$tests[] = test_run('geometry-less notam does not match globally', function (): void {
    $feature = test_make_feature('A', '2026-04-06T12:00:00Z', null);
    $queryBounds = notam_circle_bounds(50.0, 8.0, 5.0);
    test_assert_true(
        !notam_feature_matches_query($feature, 50.0, 8.0, 5.0, $queryBounds),
        'Geometry-less NOTAMs must not match arbitrary geospatial queries.'
    );
});

$tests[] = test_run('line geometry near query circle matches by segment distance', function (): void {
    $feature = test_make_feature('LINE_HIT', '2026-04-06T12:00:00Z', [
        'type' => 'LineString',
        'coordinates' => [
            [8.0000, 50.1000],
            [8.0000, 49.9000],
        ],
    ]);
    $queryBounds = notam_circle_bounds(50.0, 8.05, 3.5);
    test_assert_true(
        notam_feature_matches_query($feature, 50.0, 8.05, 3.5, $queryBounds),
        'Line segments crossing the search circle should match even when no vertex lies inside the radius.'
    );
});

$tests[] = test_run('polygon with bbox overlap but no circle intersection does not match', function (): void {
    $feature = test_make_feature('POLY_MISS', '2026-04-06T12:00:00Z', [
        'type' => 'Polygon',
        'coordinates' => [[
            [8.0500, 50.0400],
            [8.0700, 50.0400],
            [8.0700, 50.0600],
            [8.0500, 50.0600],
            [8.0500, 50.0400],
        ]],
    ]);
    $queryBounds = notam_circle_bounds(50.0, 8.0, 3.0);
    test_assert_true(
        !notam_feature_matches_query($feature, 50.0, 8.0, 3.0, $queryBounds),
        'Bounding-box overlap alone must not make a polygon match when it stays outside the search circle.'
    );
});

$tests[] = test_run('polygon containing query center matches', function (): void {
    $feature = test_make_feature('POLY_CENTER', '2026-04-06T12:00:00Z', [
        'type' => 'Polygon',
        'coordinates' => [[
            [7.9800, 49.9800],
            [8.0200, 49.9800],
            [8.0200, 50.0200],
            [7.9800, 50.0200],
            [7.9800, 49.9800],
        ]],
    ]);
    $queryBounds = notam_circle_bounds(50.0, 8.0, 1.0);
    test_assert_true(
        notam_feature_matches_query($feature, 50.0, 8.0, 1.0, $queryBounds),
        'A polygon containing the query center should match regardless of vertex distance.'
    );
});

$tests[] = test_run('live normalization applies local geometry and active filter', function (): void {
    $features = [
        test_make_feature('IN_RANGE_ACTIVE', '2026-04-06T12:00:00Z', [
            'type' => 'Point',
            'coordinates' => [-122.37541666, 37.61880555],
        ], [
            'type' => 'N',
        ]),
        test_make_feature('OUT_OF_RANGE_ACTIVE', '2026-04-06T12:00:00Z', [
            'type' => 'Point',
            'coordinates' => [-121.72151693, 38.21695963],
        ], [
            'type' => 'N',
        ]),
        test_make_feature('IN_RANGE_CANCELLED', '2026-04-06T12:00:00Z', [
            'type' => 'Point',
            'coordinates' => [-122.37541666, 37.61880555],
        ], [
            'type' => 'C',
        ]),
    ];

    $filtered = notam_filter_features_for_query($features, 37.6188, -122.3754, 5.0, true);
    test_assert_same(1, count($filtered), 'Only active in-range features should remain after live normalization.');
    test_assert_same(
        'IN_RANGE_ACTIVE',
        $filtered[0]['properties']['coreNOTAMData']['notam']['id'] ?? null,
        'The in-range active feature should survive normalization.'
    );
});

$tests[] = test_run('reconcile grace keeps cache available during maintenance', function (): void {
    $allowed = notam_should_allow_stale_during_reconcile(
        true,
        true,
        true,
        'reconcile',
        '2026-04-06T12:00:00Z',
        new DateTimeImmutable('2026-04-06T12:20:00Z'),
        1800
    );
    test_assert_true($allowed, 'Stale cache should stay available while reconcile is actively running within the grace window.');
});

$tests[] = test_run('reconcile grace expires for long-running reconcile', function (): void {
    $allowed = notam_should_allow_stale_during_reconcile(
        true,
        true,
        true,
        'reconcile',
        '2026-04-06T12:00:00Z',
        new DateTimeImmutable('2026-04-06T13:00:01Z'),
        1800
    );
    test_assert_true(!$allowed, 'Reconcile grace should not hide indefinitely stale data.');
});

$tests[] = test_run('stream parser handles top-level feature array', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'notam_test_');
    if (!is_string($tmp) || $tmp === '') {
        throw new RuntimeException('Failed to create temp file for stream parser test.');
    }

    $payload = json_encode([
        test_make_feature('A', '2026-04-06T12:00:00Z', [
            'type' => 'Point',
            'coordinates' => [8.0, 50.0],
        ]),
        test_make_feature('B', '2026-04-06T12:01:00Z', [
            'type' => 'Point',
            'coordinates' => [8.1, 50.1],
        ]),
    ], JSON_PRETTY_PRINT);
    file_put_contents($tmp, $payload);

    $ids = [];
    try {
        $count = notam_process_json_array_file($tmp, static function (array $item) use (&$ids): void {
            $ids[] = $item['properties']['coreNOTAMData']['notam']['id'] ?? null;
        });
    } finally {
        @unlink($tmp);
    }

    test_assert_same(2, $count, 'Stream parser should emit all top-level items.');
    test_assert_same(['A', 'B'], $ids, 'Stream parser should preserve item order.');
});

$failures = array_filter($tests, static fn(array $result): bool => $result['status'] !== 'PASS');
foreach ($tests as $result) {
    echo $result['status'] . ' ' . $result['name'];
    if (isset($result['message'])) {
        echo "\n" . $result['message'];
    }
    echo "\n";
}

if (!empty($failures)) {
    exit(1);
}
