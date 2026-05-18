<?php

declare(strict_types=1);

function notam_load_dotenv(?string $path = null): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }

    $candidates = [];
    if (is_string($path) && $path !== '') {
        $candidates[] = $path;
    }
    foreach (['NOTAM_ENV_FILE', 'ENV_FILE'] as $envVar) {
        $candidate = getenv($envVar);
        if (is_string($candidate) && $candidate !== '') {
            $candidates[] = $candidate;
        }
    }
    $candidates[] = dirname(__DIR__) . '/.env';
    $candidates[] = __DIR__ . '/.env';
    $candidates = array_values(array_unique($candidates));

    $dotenvPath = null;
    foreach ($candidates as $candidate) {
        if (is_file($candidate) && is_readable($candidate)) {
            $dotenvPath = $candidate;
            break;
        }
    }

    if (!is_string($dotenvPath)) {
        $loaded = true;
        return;
    }

    $lines = file($dotenvPath, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        $loaded = true;
        return;
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        $separator = strpos($trimmed, '=');
        if ($separator === false) {
            continue;
        }

        $key = trim(substr($trimmed, 0, $separator));
        $value = trim(substr($trimmed, $separator + 1));

        if ($key === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
            continue;
        }

        if ($value !== '' && (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        )) {
            $value = substr($value, 1, -1);
        }

        $existing = getenv($key);
        if ($existing !== false && $existing !== '') {
            continue;
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    $loaded = true;
}

/**
 * getenv() with optional default; strips surrounding quotes (systemd EnvironmentFile / dotenv).
 */
function notam_env(string $key, ?string $default = null): string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default ?? '';
    }

    $value = trim($value);
    if ($value !== '' && (
        (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
        (str_starts_with($value, "'") && str_ends_with($value, "'"))
    )) {
        $value = substr($value, 1, -1);
    }

    return $value;
}

notam_load_dotenv();

function notam_get_db_connection(): PDO
{
    $host = getenv('DB_SERVER') ?: 'localhost';
    $port = getenv('DB_PORT');
    $db = getenv('DB_NAME') ?: 'notamcache';
    $user = getenv('DB_USER');
    $pass = getenv('DB_PASS');
    $charset = 'utf8mb4';

    $dsn = notam_build_db_dsn($host, $db, $charset, is_string($port) && $port !== '' ? $port : null);
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    return new PDO($dsn, $user, $pass, $options);
}

function notam_build_db_dsn(string $host, string $db, string $charset = 'utf8mb4', ?string $port = null): string
{
    $dsn = "mysql:host=$host";
    if (is_string($port) && $port !== '') {
        $dsn .= ";port=$port";
    }
    $dsn .= ";dbname=$db;charset=$charset";
    return $dsn;
}

function notam_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS notam_cache (
            cache_key VARCHAR(128) NOT NULL,
            cache_value LONGTEXT NOT NULL,
            expiration DATETIME NOT NULL,
            PRIMARY KEY (cache_key),
            KEY idx_expiration (expiration)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS notam_state (
            state_key VARCHAR(128) NOT NULL,
            state_value LONGTEXT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (state_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    notam_ensure_items_table($pdo);
}

function notam_quote_identifier(string $name): string
{
    if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
        throw new InvalidArgumentException('Invalid SQL identifier');
    }

    return '`' . $name . '`';
}

function notam_get_live_items_table(): string
{
    return 'notam_items';
}

function notam_get_reconcile_stage_table(): string
{
    return 'notam_items_reconcile';
}

function notam_get_reconcile_backup_table(): string
{
    return 'notam_items_previous';
}

function notam_build_items_table_ddl(string $tableName): string
{
    $table = notam_quote_identifier($tableName);

    return "CREATE TABLE IF NOT EXISTS $table (
            notam_id VARCHAR(64) NOT NULL,
            last_updated VARCHAR(40) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            classification VARCHAR(32) DEFAULT NULL,
            effective_start DATETIME DEFAULT NULL,
            effective_end DATETIME DEFAULT NULL,
            min_lat DOUBLE DEFAULT NULL,
            max_lat DOUBLE DEFAULT NULL,
            min_lon DOUBLE DEFAULT NULL,
            max_lon DOUBLE DEFAULT NULL,
            full_sync_run VARCHAR(64) DEFAULT NULL,
            payload LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (notam_id),
            KEY idx_active_bounds (is_active, min_lat, max_lat, min_lon, max_lon),
            KEY idx_last_updated (last_updated),
            KEY idx_full_sync_run (full_sync_run)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
}

function notam_ensure_items_table(PDO $pdo, string $tableName = 'notam_items'): void
{
    $pdo->exec(notam_build_items_table_ddl($tableName));
}

function notam_purge_expired_cache(PDO $pdo): void
{
    $stmt = $pdo->prepare("DELETE FROM notam_cache WHERE expiration < UTC_TIMESTAMP()");
    $stmt->execute();
}

function notam_get_token_cache_key(): string
{
    $authUrl = getenv('FAA_AUTH_URL') ?: 'https://api-nms.aim.faa.gov/v1/auth/token';
    $clientId = getenv('FAA_ID');
    if (!is_string($clientId) || $clientId === '') {
        throw new InvalidArgumentException('Missing FAA credentials');
    }

    return 'faa_token_' . md5($authUrl . '|' . $clientId);
}

function notam_http_request(string $url, array $opts = [], bool $captureHeaders = false): array
{
    $httpOpts = isset($opts['http']) && is_array($opts['http']) ? $opts['http'] : [];
    $method = strtoupper($httpOpts['method'] ?? 'GET');
    $headerLines = $httpOpts['headers'] ?? [];
    $content = $httpOpts['content'] ?? null;
    $timeout = (int)($httpOpts['timeout'] ?? 30);

    if (!function_exists('curl_init')) {
        return [false, 0, 'cURL extension not available', []];
    }

    $responseHeaders = [];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    if (!empty($headerLines)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
    }
    if ($content !== null && $content !== '' && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
    }
    if ($captureHeaders) {
        curl_setopt(
            $ch,
            CURLOPT_HEADERFUNCTION,
            static function ($curl, $headerLine) use (&$responseHeaders) {
                $trimmed = trim($headerLine);
                if ($trimmed === '' || strpos($trimmed, ':') === false) {
                    return strlen($headerLine);
                }
                [$name, $value] = explode(':', $trimmed, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
                return strlen($headerLine);
            }
        );
    }

    $response = curl_exec($ch);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errorMessage = $response === false ? curl_error($ch) : null;

    return [$response, $statusCode, $errorMessage, $responseHeaders];
}

function notam_http_request_to_file(string $url, array $opts, string $outputPath, bool $captureHeaders = false): array
{
    $httpOpts = isset($opts['http']) && is_array($opts['http']) ? $opts['http'] : [];
    $method = strtoupper($httpOpts['method'] ?? 'GET');
    $headerLines = $httpOpts['headers'] ?? [];
    $content = $httpOpts['content'] ?? null;
    $timeout = (int)($httpOpts['timeout'] ?? 60);

    if (!function_exists('curl_init')) {
        return [false, 0, 'cURL extension not available', []];
    }

    $handle = fopen($outputPath, 'wb');
    if ($handle === false) {
        return [false, 0, 'Failed to open temporary output file', []];
    }

    $responseHeaders = [];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_FILE, $handle);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    if (!empty($headerLines)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
    }
    if ($content !== null && $content !== '' && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
    }
    if ($captureHeaders) {
        curl_setopt(
            $ch,
            CURLOPT_HEADERFUNCTION,
            static function ($curl, $headerLine) use (&$responseHeaders) {
                $trimmed = trim($headerLine);
                if ($trimmed === '' || strpos($trimmed, ':') === false) {
                    return strlen($headerLine);
                }
                [$name, $value] = explode(':', $trimmed, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
                return strlen($headerLine);
            }
        );
    }

    $success = curl_exec($ch);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errorMessage = $success === false ? curl_error($ch) : null;
    fclose($handle);

    return [$success !== false, $statusCode, $errorMessage, $responseHeaders];
}

function notam_invalidate_access_token(PDO $pdo): void
{
    $stmt = $pdo->prepare("DELETE FROM notam_cache WHERE cache_key = ?");
    $stmt->execute([notam_get_token_cache_key()]);
}

function notam_get_access_token(PDO $pdo, bool $forceRefresh = false): string
{
    $authUrl = getenv('FAA_AUTH_URL') ?: 'https://api-nms.aim.faa.gov/v1/auth/token';
    $clientId = getenv('FAA_ID');
    $clientSecret = getenv('FAA_SECRET');

    if (!$clientId || !$clientSecret) {
        throw new InvalidArgumentException('Missing FAA credentials');
    }

    $cacheKey = notam_get_token_cache_key();
    if ($forceRefresh) {
        notam_invalidate_access_token($pdo);
    } else {
        $stmt = $pdo->prepare("SELECT cache_value FROM notam_cache WHERE cache_key = ? AND expiration > UTC_TIMESTAMP()");
        $stmt->execute([$cacheKey]);
        $cached = $stmt->fetchColumn();
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
    }

    $basicAuth = base64_encode($clientId . ':' . $clientSecret);
    [$response, $statusCode, $requestError] = notam_http_request($authUrl, [
        'http' => [
            'method' => 'POST',
            'headers' => [
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . $basicAuth,
            ],
            'content' => http_build_query(['grant_type' => 'client_credentials']),
            'timeout' => 30,
        ],
    ]);

    if ($response === false || $statusCode < 200 || $statusCode >= 300) {
        $message = 'Failed to get FAA bearer token. Status code: ' . $statusCode;
        if (is_string($requestError) && $requestError !== '') {
            $message .= ' Error: ' . $requestError;
        }
        throw new RuntimeException($message);
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded) || empty($decoded['access_token'])) {
        throw new RuntimeException('Failed to decode FAA auth response');
    }

    $expiresIn = isset($decoded['expires_in']) ? (int)$decoded['expires_in'] : 1800;
    $ttl = max(60, $expiresIn - 120);

    $stmt = $pdo->prepare(
        "INSERT INTO notam_cache (cache_key, cache_value, expiration)
         VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND))
         ON DUPLICATE KEY UPDATE
         cache_value = VALUES(cache_value),
         expiration = VALUES(expiration)"
    );
    $stmt->execute([$cacheKey, $decoded['access_token'], $ttl]);

    return $decoded['access_token'];
}

function notam_require_geojson_format(): string
{
    $responseFormat = notam_env('NMS_RESPONSE_FORMAT', 'GEOJSON');
    $responseFormat = strtoupper(trim($responseFormat));
    if ($responseFormat !== 'GEOJSON') {
        throw new InvalidArgumentException('Only GEOJSON is supported in local-cache mode');
    }
    return $responseFormat;
}

function notam_build_faa_headers(PDO $pdo, bool $forceRefresh = false): array
{
    $token = notam_get_access_token($pdo, $forceRefresh);
    $responseFormat = notam_require_geojson_format();

    return [
        'Authorization: Bearer ' . $token,
        'nmsResponseFormat: ' . $responseFormat,
        'Accept: application/json',
    ];
}

function notam_authorized_http_request(PDO $pdo, string $url, array $httpOptions, bool $captureHeaders = false): array
{
    $attempt = 0;
    $forceRefresh = false;
    while (true) {
        $httpOptions['headers'] = notam_build_faa_headers($pdo, $forceRefresh);
        [$response, $statusCode, $requestError, $responseHeaders] = notam_http_request(
            $url,
            ['http' => $httpOptions],
            $captureHeaders
        );

        if ($statusCode !== 401 || $attempt >= 1) {
            return [$response, $statusCode, $requestError, $responseHeaders];
        }

        $attempt++;
        $forceRefresh = true;
    }
}

function notam_authorized_http_request_to_file(PDO $pdo, string $url, array $httpOptions, string $outputPath, bool $captureHeaders = false): array
{
    $attempt = 0;
    $forceRefresh = false;
    while (true) {
        if (file_exists($outputPath)) {
            file_put_contents($outputPath, '');
        }
        $httpOptions['headers'] = notam_build_faa_headers($pdo, $forceRefresh);
        [$success, $statusCode, $requestError, $responseHeaders] = notam_http_request_to_file(
            $url,
            ['http' => $httpOptions],
            $outputPath,
            $captureHeaders
        );

        if ($statusCode !== 401 || $attempt >= 1) {
            return [$success, $statusCode, $requestError, $responseHeaders];
        }

        $attempt++;
        $forceRefresh = true;
    }
}

function notam_get_api_base(): string
{
    return rtrim(getenv('FAA_API_BASE') ?: 'https://api-nms.aim.faa.gov/nmsapi', '/');
}

function notam_decode_json(string $payload): array
{
    $decoded = json_decode($payload, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Failed to decode JSON payload');
    }
    return $decoded;
}

function notam_is_list_array(array $value): bool
{
    $expectedKey = 0;
    foreach ($value as $key => $_unused) {
        if ($key !== $expectedKey) {
            return false;
        }
        $expectedKey++;
    }
    return true;
}

function notam_maybe_decode_compressed_payload(string $payload): string
{
    if (strncmp($payload, "\x1f\x8b", 2) === 0) {
        $decoded = gzdecode($payload);
        if ($decoded !== false) {
            return $decoded;
        }
    }

    return $payload;
}

function notam_extract_items_from_payload(string $payload): array
{
    $payload = notam_maybe_decode_compressed_payload($payload);
    $decoded = notam_decode_json($payload);

    if (isset($decoded['status']) && $decoded['status'] !== 'Success') {
        $errorDetails = '';
        if (isset($decoded['errors']) && is_array($decoded['errors']) && !empty($decoded['errors'])) {
            $errorDetails = ': ' . json_encode($decoded['errors']);
        }
        throw new RuntimeException('FAA NMS API returned failure' . $errorDetails);
    }

    if (isset($decoded['data']['geojson']) && is_array($decoded['data']['geojson'])) {
        return $decoded['data']['geojson'];
    }

    if (($decoded['type'] ?? null) === 'FeatureCollection' && isset($decoded['features']) && is_array($decoded['features'])) {
        return $decoded['features'];
    }

    if (notam_is_list_array($decoded)) {
        return $decoded;
    }

    throw new RuntimeException('FAA payload did not contain a GeoJSON item list');
}

function notam_fetch_delta_batch(PDO $pdo, string $lastUpdatedDate): array
{
    $baseUrl = notam_get_api_base();
    $query = http_build_query(['lastUpdatedDate' => $lastUpdatedDate]);
    $url = $baseUrl . '/v1/notams?' . $query;

    [$response, $statusCode, $requestError, $responseHeaders] = notam_authorized_http_request($pdo, $url, [
            'method' => 'GET',
            'timeout' => 30,
    ], true);

    if ($response === false || $statusCode < 200 || $statusCode >= 300) {
        $message = 'Failed to get delta data from FAA NMS API. Status code: ' . $statusCode;
        if (is_string($requestError) && $requestError !== '') {
            $message .= ' Error: ' . $requestError;
        }
        throw new RuntimeException($message);
    }

    return [
        'items' => notam_extract_items_from_payload($response),
        'response_headers' => $responseHeaders,
    ];
}

function notam_fetch_delta_items(PDO $pdo, string $lastUpdatedDate): array
{
    $batch = notam_fetch_delta_batch($pdo, $lastUpdatedDate);
    return $batch['items'];
}

function notam_fetch_classification_items(PDO $pdo, string $classification): array
{
    $baseUrl = notam_get_api_base();
    $query = http_build_query([
        'classification' => $classification,
        'allowRedirect' => 'false',
    ]);
    $url = $baseUrl . '/v1/notams?' . $query;

    [$response, $statusCode, $requestError, $responseHeaders] = notam_authorized_http_request($pdo, $url, [
            'method' => 'GET',
            'timeout' => 30,
    ], true);

    if ($response === false || ($statusCode < 200 || $statusCode >= 400)) {
        $message = 'Failed to get classification data from FAA NMS API. Status code: ' . $statusCode;
        if (is_string($requestError) && $requestError !== '') {
            $message .= ' Error: ' . $requestError;
        }
        throw new RuntimeException($message);
    }

    if ($statusCode === 307 && !empty($responseHeaders['location'])) {
        return notam_fetch_relative_content_items($pdo, $responseHeaders['location']);
    }

    $decoded = notam_decode_json((string)$response);
    if (isset($decoded['data']['geojson']) && is_array($decoded['data']['geojson'])) {
        return $decoded['data']['geojson'];
    }

    $relativeUrl = $decoded['data']['url'] ?? null;
    if (!is_string($relativeUrl) || $relativeUrl === '') {
        throw new RuntimeException('FAA classification response missing content URL');
    }

    return notam_fetch_relative_content_items($pdo, $relativeUrl);
}

function notam_fetch_relative_content_items(PDO $pdo, string $relativeUrl): array
{
    $absoluteUrl = notam_join_api_url(notam_get_api_base(), $relativeUrl);

    [$response, $statusCode, $requestError] = notam_authorized_http_request($pdo, $absoluteUrl, [
            'method' => 'GET',
            'timeout' => 60,
    ]);

    if ($response === false || $statusCode < 200 || $statusCode >= 300) {
        $message = 'Failed to download FAA content URL. Status code: ' . $statusCode;
        if (is_string($requestError) && $requestError !== '') {
            $message .= ' Error: ' . $requestError;
        }
        throw new RuntimeException($message);
    }

    return notam_extract_items_from_payload($response);
}

function notam_create_temp_file(string $prefix): string
{
    $tmpDir = getenv('TMPDIR') ?: sys_get_temp_dir();
    $tempPath = tempnam($tmpDir, $prefix);
    if (!is_string($tempPath) || $tempPath === '') {
        throw new RuntimeException('Failed to create temporary file');
    }

    return $tempPath;
}

function notam_download_relative_content_to_temp_file(PDO $pdo, string $relativeUrl): string
{
    $absoluteUrl = notam_join_api_url(notam_get_api_base(), $relativeUrl);
    $tempPath = notam_create_temp_file('notam_');

    [$success, $statusCode, $requestError] = notam_authorized_http_request_to_file($pdo, $absoluteUrl, [
            'method' => 'GET',
            'timeout' => 120,
    ], $tempPath);

    if (!$success || $statusCode < 200 || $statusCode >= 300) {
        @unlink($tempPath);
        $message = 'Failed to download FAA content URL. Status code: ' . $statusCode;
        if (is_string($requestError) && $requestError !== '') {
            $message .= ' Error: ' . $requestError;
        }
        throw new RuntimeException($message);
    }

    return $tempPath;
}

function notam_process_json_array_file(string $filePath, callable $consumer): int
{
    $handle = fopen($filePath, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Failed to open JSON array file');
    }

    $inString = false;
    $escape = false;
    $depth = 0;
    $sawArrayStart = false;
    $collecting = false;
    $buffer = '';
    $count = 0;

    try {
        while (!feof($handle)) {
            $chunk = fread($handle, 65536);
            if ($chunk === false) {
                throw new RuntimeException('Failed reading JSON array file');
            }
            $length = strlen($chunk);
            for ($i = 0; $i < $length; $i++) {
                $char = $chunk[$i];

                if (!$sawArrayStart) {
                    if (ctype_space($char)) {
                        continue;
                    }
                    if ($char !== '[') {
                        throw new RuntimeException('Expected JSON array payload');
                    }
                    $sawArrayStart = true;
                    continue;
                }

                if (!$collecting) {
                    if (ctype_space($char) || $char === ',') {
                        continue;
                    }
                    if ($char === ']') {
                        return $count;
                    }

                    $collecting = true;
                    $buffer = $char;
                    $inString = false;
                    $escape = false;
                    $depth = ($char === '{' || $char === '[') ? 1 : 0;

                    if ($depth === 0) {
                        throw new RuntimeException('Unsupported JSON array item format');
                    }
                    continue;
                }

                $buffer .= $char;
                if ($inString) {
                    if ($escape) {
                        $escape = false;
                    } elseif ($char === '\\') {
                        $escape = true;
                    } elseif ($char === '"') {
                        $inString = false;
                    }
                    continue;
                }

                if ($char === '"') {
                    $inString = true;
                    continue;
                }
                if ($char === '{' || $char === '[') {
                    $depth++;
                    continue;
                }
                if ($char === '}' || $char === ']') {
                    $depth--;
                    if ($depth === 0) {
                        $item = json_decode($buffer, true);
                        if (!is_array($item)) {
                            throw new RuntimeException('Failed to decode streamed JSON item');
                        }
                        $consumer($item);
                        $count++;
                        $collecting = false;
                        $buffer = '';
                    }
                }
            }
        }
    } finally {
        fclose($handle);
    }

    if (!$sawArrayStart) {
        throw new RuntimeException('Empty JSON payload');
    }
    if ($collecting || $depth !== 0 || $inString) {
        throw new RuntimeException('Incomplete JSON array payload');
    }

    return $count;
}

function notam_maybe_decompress_file(string $filePath): string
{
    $handle = fopen($filePath, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Failed to open downloaded file');
    }

    $magic = fread($handle, 2);
    fclose($handle);
    if ($magic !== "\x1f\x8b") {
        return $filePath;
    }

    $outputPath = notam_create_temp_file('notam_json_');
    $gz = gzopen($filePath, 'rb');
    if ($gz === false) {
        @unlink($outputPath);
        throw new RuntimeException('Failed to open gzip payload');
    }

    $out = fopen($outputPath, 'wb');
    if ($out === false) {
        gzclose($gz);
        @unlink($outputPath);
        throw new RuntimeException('Failed to create decompressed payload file');
    }

    try {
        while (!gzeof($gz)) {
            $chunk = gzread($gz, 65536);
            if ($chunk === false) {
                throw new RuntimeException('Failed reading gzip payload');
            }
            if ($chunk !== '' && fwrite($out, $chunk) === false) {
                throw new RuntimeException('Failed writing decompressed payload');
            }
        }
    } finally {
        gzclose($gz);
        fclose($out);
    }

    return $outputPath;
}

function notam_process_relative_content_items(PDO $pdo, string $relativeUrl, callable $consumer): int
{
    $tempPath = notam_download_relative_content_to_temp_file($pdo, $relativeUrl);
    try {
        $jsonPath = notam_maybe_decompress_file($tempPath);
        try {
            $count = notam_process_json_array_file($jsonPath, $consumer);
        } finally {
            if ($jsonPath !== $tempPath) {
                @unlink($jsonPath);
            }
        }
    } finally {
        @unlink($tempPath);
    }

    return $count;
}

function notam_process_classification_items(PDO $pdo, string $classification, callable $consumer): int
{
    $baseUrl = notam_get_api_base();
    $query = http_build_query([
        'classification' => $classification,
        'allowRedirect' => 'false',
    ]);
    $url = $baseUrl . '/v1/notams?' . $query;

    [$response, $statusCode, $requestError, $responseHeaders] = notam_authorized_http_request(
        $pdo,
        $url,
        [
            'method' => 'GET',
            'timeout' => 30,
        ],
        true
    );

    if ($response === false || ($statusCode < 200 || $statusCode >= 400)) {
        $message = 'Failed to get classification data from FAA NMS API. Status code: ' . $statusCode;
        if (is_string($requestError) && $requestError !== '') {
            $message .= ' Error: ' . $requestError;
        }
        throw new RuntimeException($message);
    }

    if ($statusCode === 307 && !empty($responseHeaders['location'])) {
        return notam_process_relative_content_items($pdo, $responseHeaders['location'], $consumer);
    }

    $decoded = notam_decode_json((string)$response);
    if (isset($decoded['data']['geojson']) && is_array($decoded['data']['geojson'])) {
        $count = 0;
        foreach ($decoded['data']['geojson'] as $item) {
            if (is_array($item)) {
                $consumer($item);
                $count++;
            }
        }
        return $count;
    }

    $relativeUrl = $decoded['data']['url'] ?? null;
    if (!is_string($relativeUrl) || $relativeUrl === '') {
        throw new RuntimeException('FAA classification response missing content URL');
    }

    return notam_process_relative_content_items($pdo, $relativeUrl, $consumer);
}

function notam_join_api_url(string $baseUrl, string $path): string
{
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    $parts = parse_url($baseUrl);
    $scheme = $parts['scheme'] ?? 'https';
    $host = $parts['host'] ?? '';
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    if ($host === '') {
        throw new RuntimeException('Invalid FAA API base URL');
    }

    if ($path !== '' && $path[0] !== '/') {
        $path = '/' . $path;
    }

    return $scheme . '://' . $host . $port . $path;
}

function notam_get_state(PDO $pdo, string $key, ?string $default = null): ?string
{
    $stmt = $pdo->prepare("SELECT state_value FROM notam_state WHERE state_key = ?");
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return is_string($value) ? $value : $default;
}

function notam_set_state(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO notam_state (state_key, state_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE
         state_value = VALUES(state_value)"
    );
    $stmt->execute([$key, $value]);
}

function notam_set_sync_activity(PDO $pdo, string $mode, bool $inProgress, ?string $startedAt = null): void
{
    notam_set_state($pdo, 'faa_sync_in_progress', $inProgress ? '1' : '0');
    notam_set_state($pdo, 'faa_sync_mode', $inProgress ? $mode : '');
    notam_set_state($pdo, 'faa_sync_started_at', $inProgress ? ($startedAt ?: notam_now_iso()) : '');
}

function notam_acquire_lock(PDO $pdo, string $name): void
{
    $stmt = $pdo->prepare("SELECT GET_LOCK(?, 0)");
    $stmt->execute([$name]);
    $acquired = (int)$stmt->fetchColumn();
    if ($acquired !== 1) {
        throw new RuntimeException('Another NOTAM sync is already running');
    }
}

function notam_release_lock(PDO $pdo, string $name): void
{
    $stmt = $pdo->prepare("SELECT RELEASE_LOCK(?)");
    $stmt->execute([$name]);
}

function notam_is_lock_held(PDO $pdo, string $name): bool
{
    $stmt = $pdo->prepare("SELECT IS_USED_LOCK(?)");
    $stmt->execute([$name]);
    return $stmt->fetchColumn() !== null;
}

function notam_get_reconcile_classifications(): array
{
    $configured = getenv('FAA_RECONCILE_CLASSIFICATIONS') ?: 'INTERNATIONAL,MILITARY,LOCAL_MILITARY,DOMESTIC,FDC';
    $parts = array_filter(array_map('trim', explode(',', $configured)));
    $result = [];
    foreach ($parts as $part) {
        $result[] = strtoupper($part);
    }
    if (empty($result)) {
        throw new InvalidArgumentException('FAA_RECONCILE_CLASSIFICATIONS is empty');
    }
    return array_values(array_unique($result));
}

function notam_iso_to_mysql(?string $value): ?string
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    try {
        $dt = new DateTimeImmutable($value);
    } catch (Exception $e) {
        return null;
    }

    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function notam_now_utc(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

function notam_now_iso(): string
{
    return notam_now_utc()->format('Y-m-d\TH:i:s\Z');
}

function notam_format_iso_utc(DateTimeImmutable $value): string
{
    return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}

function notam_parse_timestamp(string $value): ?DateTimeImmutable
{
    try {
        return new DateTimeImmutable($value);
    } catch (Exception $e) {
        return null;
    }
}

function notam_get_delta_overlap_seconds(): int
{
    $configured = getenv('NOTAM_DELTA_OVERLAP_SECONDS');
    if ($configured === false || $configured === '') {
        return 60;
    }

    $value = (int)$configured;
    return $value >= 0 ? $value : 60;
}

function notam_extract_max_last_updated(array $items): ?DateTimeImmutable
{
    $max = null;
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $lastUpdated = notam_extract_last_updated($item);
        if (!is_string($lastUpdated) || $lastUpdated === '') {
            continue;
        }
        $parsed = notam_parse_timestamp($lastUpdated);
        if ($parsed === null) {
            continue;
        }
        if ($max === null || $parsed > $max) {
            $max = $parsed;
        }
    }

    return $max;
}

function notam_compute_next_delta_cursor(
    string $previousCursor,
    ?string $responseDateHeader,
    array $items,
    ?DateTimeImmutable $fallbackNow = null,
    ?int $overlapSeconds = null
): string {
    $previous = notam_parse_timestamp($previousCursor);
    if ($previous === null) {
        throw new InvalidArgumentException('Invalid previous delta cursor');
    }

    $upperBound = null;
    if (is_string($responseDateHeader) && trim($responseDateHeader) !== '') {
        $upperBound = notam_parse_timestamp($responseDateHeader);
    }
    if ($upperBound === null) {
        $upperBound = $fallbackNow ?: notam_now_utc();
    }

    if ($upperBound < $previous) {
        $upperBound = $previous;
    }

    $maxSeenLastUpdated = notam_extract_max_last_updated($items);
    if ($maxSeenLastUpdated !== null && $maxSeenLastUpdated > $upperBound) {
        $upperBound = $maxSeenLastUpdated;
    }

    $overlap = $overlapSeconds ?? notam_get_delta_overlap_seconds();
    $next = $upperBound;
    if ($overlap > 0) {
        $next = $next->sub(new DateInterval('PT' . $overlap . 'S'));
    }
    if ($next < $previous) {
        $next = $previous;
    }

    return notam_format_iso_utc($next);
}

function notam_compute_reconcile_delta_cursor(?string $startedAt = null, ?int $overlapSeconds = null): string
{
    $start = is_string($startedAt) && $startedAt !== '' ? notam_parse_timestamp($startedAt) : null;
    if ($start === null) {
        $start = notam_now_utc();
    }

    $overlap = $overlapSeconds ?? notam_get_delta_overlap_seconds();
    if ($overlap > 0) {
        $start = $start->sub(new DateInterval('PT' . $overlap . 'S'));
    }

    return notam_format_iso_utc($start);
}

function notam_extract_notam_data(array $feature): ?array
{
    $notam = $feature['properties']['coreNOTAMData']['notam'] ?? null;
    return is_array($notam) ? $notam : null;
}

function notam_extract_notam_id(array $feature): ?string
{
    $notam = notam_extract_notam_data($feature);
    $id = is_array($notam) ? ($notam['id'] ?? null) : null;
    return is_string($id) && $id !== '' ? $id : null;
}

function notam_extract_last_updated(array $feature): ?string
{
    $notam = notam_extract_notam_data($feature);
    $lastUpdated = is_array($notam) ? ($notam['lastUpdated'] ?? null) : null;
    return is_string($lastUpdated) && $lastUpdated !== '' ? $lastUpdated : null;
}

function notam_infer_is_active(array $feature): bool
{
    $notam = notam_extract_notam_data($feature);
    if (!is_array($notam)) {
        return true;
    }

    $type = strtoupper((string)($notam['type'] ?? ''));
    if ($type === 'C') {
        return false;
    }

    $status = strtoupper((string)($notam['status'] ?? ''));
    if (in_array($status, ['D', 'DELETED', 'CANCELLED', 'CANCELED', 'INACTIVE'], true)) {
        return false;
    }

    $now = notam_now_utc();
    $cancelationDate = notam_iso_to_mysql($notam['cancelationDate'] ?? null);
    if ($cancelationDate !== null && $cancelationDate <= $now->format('Y-m-d H:i:s')) {
        return false;
    }

    $effectiveEnd = notam_iso_to_mysql($notam['effectiveEnd'] ?? null);
    if ($effectiveEnd !== null && $effectiveEnd < $now->format('Y-m-d H:i:s')) {
        return false;
    }

    return true;
}

function notam_collect_geometry_points($geometry, array &$points): void
{
    if (!is_array($geometry)) {
        return;
    }

    $type = $geometry['type'] ?? null;
    if (!is_string($type)) {
        return;
    }

    if ($type === 'GeometryCollection') {
        $geometries = $geometry['geometries'] ?? [];
        if (is_array($geometries)) {
            foreach ($geometries as $child) {
                notam_collect_geometry_points($child, $points);
            }
        }
        return;
    }

    $coordinates = $geometry['coordinates'] ?? null;
    if (!is_array($coordinates)) {
        return;
    }

    notam_collect_coordinate_pairs($coordinates, $points);
}

function notam_collect_coordinate_pairs($value, array &$points): void
{
    if (!is_array($value)) {
        return;
    }

    if (count($value) >= 2 && is_numeric($value[0]) && is_numeric($value[1])) {
        $points[] = [(float)$value[0], (float)$value[1]];
        return;
    }

    foreach ($value as $child) {
        notam_collect_coordinate_pairs($child, $points);
    }
}

function notam_compute_feature_bounds(array $feature): ?array
{
    $geometry = $feature['geometry'] ?? null;
    $points = [];
    notam_collect_geometry_points($geometry, $points);
    if (empty($points)) {
        return null;
    }

    $minLon = null;
    $maxLon = null;
    $minLat = null;
    $maxLat = null;
    $longitudes = [];
    foreach ($points as $point) {
        [$lon, $lat] = $point;
        $minLon = $minLon === null ? $lon : min($minLon, $lon);
        $maxLon = $maxLon === null ? $lon : max($maxLon, $lon);
        $minLat = $minLat === null ? $lat : min($minLat, $lat);
        $maxLat = $maxLat === null ? $lat : max($maxLat, $lat);
        $longitudes[] = $lon;
    }

    return [
        'min_lat' => $minLat,
        'max_lat' => $maxLat,
        'min_lon' => $minLon,
        'max_lon' => $maxLon,
        'lon_segments' => notam_compute_longitude_segments($longitudes),
    ];
}

function notam_store_feature(PDO $pdo, array $feature, ?string $fullSyncRun = null): bool
{
    $stmt = notam_prepare_store_feature_statement($pdo);
    return notam_store_feature_with_statement($stmt, $feature, $fullSyncRun);
}

function notam_prepare_store_feature_statement(PDO $pdo, string $tableName = 'notam_items'): PDOStatement
{
    $table = notam_quote_identifier($tableName);

    return $pdo->prepare(
        "INSERT INTO $table (
            notam_id,
            last_updated,
            is_active,
            classification,
            effective_start,
            effective_end,
            min_lat,
            max_lat,
            min_lon,
            max_lon,
            full_sync_run,
            payload
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            last_updated = VALUES(last_updated),
            is_active = VALUES(is_active),
            classification = VALUES(classification),
            effective_start = VALUES(effective_start),
            effective_end = VALUES(effective_end),
            min_lat = VALUES(min_lat),
            max_lat = VALUES(max_lat),
            min_lon = VALUES(min_lon),
            max_lon = VALUES(max_lon),
            full_sync_run = COALESCE(VALUES(full_sync_run), full_sync_run),
            payload = VALUES(payload)"
    );
}

function notam_store_feature_with_statement(PDOStatement $stmt, array $feature, ?string $fullSyncRun = null): bool
{
    $notamId = notam_extract_notam_id($feature);
    $lastUpdated = notam_extract_last_updated($feature);
    if ($notamId === null || $lastUpdated === null) {
        return false;
    }

    $payload = json_encode($feature, JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        throw new RuntimeException('Failed to encode NOTAM payload');
    }

    $notam = notam_extract_notam_data($feature) ?? [];
    $bounds = notam_compute_feature_bounds($feature);

    $stmt->execute([
        $notamId,
        $lastUpdated,
        notam_infer_is_active($feature) ? 1 : 0,
        isset($notam['classification']) && is_string($notam['classification']) ? $notam['classification'] : null,
        notam_iso_to_mysql($notam['effectiveStart'] ?? null),
        notam_iso_to_mysql($notam['effectiveEnd'] ?? null),
        $bounds['min_lat'] ?? null,
        $bounds['max_lat'] ?? null,
        $bounds['min_lon'] ?? null,
        $bounds['max_lon'] ?? null,
        $fullSyncRun,
        $payload,
    ]);

    return true;
}

function notam_mark_missing_full_sync_inactive(PDO $pdo, string $fullSyncRun): int
{
    return notam_mark_missing_full_sync_inactive_in_table($pdo, $fullSyncRun, notam_get_live_items_table());
}

function notam_mark_missing_full_sync_inactive_in_table(PDO $pdo, string $fullSyncRun, string $tableName): int
{
    $table = notam_quote_identifier($tableName);
    $stmt = $pdo->prepare(
        "UPDATE $table
         SET is_active = 0
         WHERE full_sync_run IS NULL OR full_sync_run <> ?"
    );
    $stmt->execute([$fullSyncRun]);
    return $stmt->rowCount();
}

function notam_purge_old_inactive_items(PDO $pdo, int $days = 7): int
{
    return notam_purge_old_inactive_items_in_table($pdo, $days, notam_get_live_items_table());
}

function notam_purge_old_inactive_items_in_table(PDO $pdo, int $days = 7, string $tableName = 'notam_items'): int
{
    $table = notam_quote_identifier($tableName);
    $stmt = $pdo->prepare(
        "DELETE FROM $table
         WHERE is_active = 0 AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)"
    );
    $stmt->execute([$days]);
    return $stmt->rowCount();
}

function notam_reset_reconcile_stage(PDO $pdo, string $stageTable = 'notam_items_reconcile'): void
{
    $stage = notam_quote_identifier($stageTable);
    $pdo->exec("DROP TABLE IF EXISTS $stage");
    notam_ensure_items_table($pdo, $stageTable);
}

function notam_swap_reconcile_stage_into_live(
    PDO $pdo,
    string $stageTable = 'notam_items_reconcile',
    string $liveTable = 'notam_items',
    string $backupTable = 'notam_items_previous'
): void {
    $stage = notam_quote_identifier($stageTable);
    $live = notam_quote_identifier($liveTable);
    $backup = notam_quote_identifier($backupTable);

    $pdo->exec("DROP TABLE IF EXISTS $backup");
    $pdo->exec("RENAME TABLE $live TO $backup, $stage TO $live");
    $pdo->exec("DROP TABLE $backup");
}

function notam_longitude_to_360(float $longitude): float
{
    $value = fmod($longitude, 360.0);
    if ($value < 0) {
        $value += 360.0;
    }
    return $value;
}

function notam_longitude_to_signed(float $longitude360): float
{
    $value = fmod($longitude360, 360.0);
    if ($value < 0) {
        $value += 360.0;
    }
    if ($value > 180.0) {
        $value -= 360.0;
    }
    return $value;
}

function notam_compute_longitude_segments(array $longitudes): array
{
    if (empty($longitudes)) {
        return [];
    }

    $normalized = [];
    foreach ($longitudes as $longitude) {
        if (is_numeric($longitude)) {
            $normalized[] = notam_longitude_to_360((float)$longitude);
        }
    }
    if (empty($normalized)) {
        return [];
    }

    sort($normalized, SORT_NUMERIC);
    if (count($normalized) === 1) {
        $lon = notam_longitude_to_signed($normalized[0]);
        return [['min' => $lon, 'max' => $lon]];
    }

    $largestGap = -1.0;
    $largestGapIndex = 0;
    $count = count($normalized);
    for ($i = 0; $i < $count; $i++) {
        $current = $normalized[$i];
        $next = $normalized[($i + 1) % $count];
        if ($i === $count - 1) {
            $next += 360.0;
        }
        $gap = $next - $current;
        if ($gap > $largestGap) {
            $largestGap = $gap;
            $largestGapIndex = $i;
        }
    }

    $start = $normalized[($largestGapIndex + 1) % $count];
    $end = $normalized[$largestGapIndex];
    if ($end < $start) {
        $end += 360.0;
    }

    $span = $end - $start;
    if ($span >= 359.999999) {
        return [['min' => -180.0, 'max' => 180.0]];
    }

    if ($end <= 180.0 || $start >= 180.0) {
        return [[
            'min' => notam_longitude_to_signed($start),
            'max' => notam_longitude_to_signed($end),
        ]];
    }

    return [
        ['min' => notam_longitude_to_signed($start), 'max' => 180.0],
        ['min' => -180.0, 'max' => notam_longitude_to_signed($end)],
    ];
}

function notam_circle_bounds(float $latitude, float $longitude, float $radiusNm): array
{
    $latDelta = $radiusNm / 60.0;
    $cosLat = cos(deg2rad($latitude));
    $lonDelta = abs($cosLat) < 0.000001 ? 180.0 : $radiusNm / (60.0 * abs($cosLat));

    return [
        'min_lat' => max(-90.0, $latitude - $latDelta),
        'max_lat' => min(90.0, $latitude + $latDelta),
        'lon_segments' => $lonDelta >= 180.0
            ? [['min' => -180.0, 'max' => 180.0]]
            : notam_compute_longitude_segments([$longitude - $lonDelta, $longitude + $lonDelta]),
    ];
}

function notam_segments_overlap(array $segmentsA, array $segmentsB): bool
{
    foreach ($segmentsA as $segmentA) {
        foreach ($segmentsB as $segmentB) {
            if ($segmentA['max'] >= $segmentB['min'] && $segmentA['min'] <= $segmentB['max']) {
                return true;
            }
        }
    }

    return false;
}

function notam_bounds_overlap(array $a, array $b): bool
{
    if ($a['max_lat'] < $b['min_lat'] || $a['min_lat'] > $b['max_lat']) {
        return false;
    }

    $segmentsA = $a['lon_segments'] ?? [];
    $segmentsB = $b['lon_segments'] ?? [];
    if (empty($segmentsA) || empty($segmentsB)) {
        return true;
    }

    return notam_segments_overlap($segmentsA, $segmentsB);
}

function notam_haversine_nm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadiusNm = 3440.065;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 +
        cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
    return $earthRadiusNm * $c;
}

function notam_normalize_longitude_relative(float $longitude, float $referenceLongitude): float
{
    $normalized = $longitude;
    while (($normalized - $referenceLongitude) > 180.0) {
        $normalized -= 360.0;
    }
    while (($normalized - $referenceLongitude) < -180.0) {
        $normalized += 360.0;
    }
    return $normalized;
}

function notam_project_point_to_query_plane(float $latitude, float $longitude, float $queryLatitude, float $queryLongitude): array
{
    $normalizedLongitude = notam_normalize_longitude_relative($longitude, $queryLongitude);
    $lonDelta = $normalizedLongitude - $queryLongitude;
    $latDelta = $latitude - $queryLatitude;
    $scale = cos(deg2rad($queryLatitude));
    $x = $lonDelta * 60.0 * $scale;
    $y = $latDelta * 60.0;
    return [$x, $y];
}

function notam_distance_point_to_segment_nm(array $point, array $segmentStart, array $segmentEnd): float
{
    [$px, $py] = $point;
    [$ax, $ay] = $segmentStart;
    [$bx, $by] = $segmentEnd;

    $dx = $bx - $ax;
    $dy = $by - $ay;
    $lengthSquared = ($dx * $dx) + ($dy * $dy);
    if ($lengthSquared <= 0.0) {
        return sqrt((($px - $ax) ** 2) + (($py - $ay) ** 2));
    }

    $t = ((($px - $ax) * $dx) + (($py - $ay) * $dy)) / $lengthSquared;
    $t = max(0.0, min(1.0, $t));
    $closestX = $ax + ($t * $dx);
    $closestY = $ay + ($t * $dy);
    return sqrt((($px - $closestX) ** 2) + (($py - $closestY) ** 2));
}

function notam_point_in_ring_projected(array $point, array $ring): bool
{
    $count = count($ring);
    if ($count < 3) {
        return false;
    }

    [$px, $py] = $point;
    $inside = false;
    for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
        [$xi, $yi] = $ring[$i];
        [$xj, $yj] = $ring[$j];
        $intersects = (($yi > $py) !== ($yj > $py))
            && ($px < (($xj - $xi) * ($py - $yi) / (($yj - $yi) ?: 0.0000000001)) + $xi);
        if ($intersects) {
            $inside = !$inside;
        }
    }

    return $inside;
}

function notam_ring_intersects_query_circle(array $ringCoordinates, float $queryLatitude, float $queryLongitude, float $radiusNm): bool
{
    $projectedRing = [];
    foreach ($ringCoordinates as $coordinate) {
        if (!is_array($coordinate) || count($coordinate) < 2 || !is_numeric($coordinate[0]) || !is_numeric($coordinate[1])) {
            continue;
        }
        $projectedRing[] = notam_project_point_to_query_plane((float)$coordinate[1], (float)$coordinate[0], $queryLatitude, $queryLongitude);
    }

    $count = count($projectedRing);
    if ($count === 0) {
        return false;
    }

    foreach ($projectedRing as $point) {
        if (sqrt(($point[0] ** 2) + ($point[1] ** 2)) <= $radiusNm) {
            return true;
        }
    }

    if ($count >= 3 && notam_point_in_ring_projected([0.0, 0.0], $projectedRing)) {
        return true;
    }

    for ($i = 0; $i < $count - 1; $i++) {
        if (notam_distance_point_to_segment_nm([0.0, 0.0], $projectedRing[$i], $projectedRing[$i + 1]) <= $radiusNm) {
            return true;
        }
    }

    if ($count >= 2 && $projectedRing[0] !== $projectedRing[$count - 1]) {
        if (notam_distance_point_to_segment_nm([0.0, 0.0], $projectedRing[$count - 1], $projectedRing[0]) <= $radiusNm) {
            return true;
        }
    }

    return false;
}

function notam_linestring_intersects_query_circle(array $coordinates, float $queryLatitude, float $queryLongitude, float $radiusNm): bool
{
    $projected = [];
    foreach ($coordinates as $coordinate) {
        if (!is_array($coordinate) || count($coordinate) < 2 || !is_numeric($coordinate[0]) || !is_numeric($coordinate[1])) {
            continue;
        }
        $projected[] = notam_project_point_to_query_plane((float)$coordinate[1], (float)$coordinate[0], $queryLatitude, $queryLongitude);
    }

    $count = count($projected);
    if ($count === 0) {
        return false;
    }

    foreach ($projected as $point) {
        if (sqrt(($point[0] ** 2) + ($point[1] ** 2)) <= $radiusNm) {
            return true;
        }
    }

    for ($i = 0; $i < $count - 1; $i++) {
        if (notam_distance_point_to_segment_nm([0.0, 0.0], $projected[$i], $projected[$i + 1]) <= $radiusNm) {
            return true;
        }
    }

    return false;
}

function notam_polygon_contains_query_center(array $polygonCoordinates, float $queryLatitude, float $queryLongitude): bool
{
    if (empty($polygonCoordinates) || !is_array($polygonCoordinates[0] ?? null)) {
        return false;
    }

    $outerRing = [];
    foreach ($polygonCoordinates[0] as $coordinate) {
        if (!is_array($coordinate) || count($coordinate) < 2 || !is_numeric($coordinate[0]) || !is_numeric($coordinate[1])) {
            continue;
        }
        $outerRing[] = notam_project_point_to_query_plane((float)$coordinate[1], (float)$coordinate[0], $queryLatitude, $queryLongitude);
    }
    if (!notam_point_in_ring_projected([0.0, 0.0], $outerRing)) {
        return false;
    }

    $holeCount = count($polygonCoordinates);
    for ($i = 1; $i < $holeCount; $i++) {
        $holeRing = [];
        foreach ($polygonCoordinates[$i] as $coordinate) {
            if (!is_array($coordinate) || count($coordinate) < 2 || !is_numeric($coordinate[0]) || !is_numeric($coordinate[1])) {
                continue;
            }
            $holeRing[] = notam_project_point_to_query_plane((float)$coordinate[1], (float)$coordinate[0], $queryLatitude, $queryLongitude);
        }
        if (notam_point_in_ring_projected([0.0, 0.0], $holeRing)) {
            return false;
        }
    }

    return true;
}

function notam_geometry_intersects_query_circle($geometry, float $queryLatitude, float $queryLongitude, float $radiusNm): bool
{
    if (!is_array($geometry)) {
        return false;
    }

    $type = $geometry['type'] ?? null;
    if (!is_string($type)) {
        return false;
    }

    $coordinates = $geometry['coordinates'] ?? null;
    switch ($type) {
        case 'Point':
            return is_array($coordinates)
                && count($coordinates) >= 2
                && is_numeric($coordinates[0])
                && is_numeric($coordinates[1])
                && notam_haversine_nm($queryLatitude, $queryLongitude, (float)$coordinates[1], (float)$coordinates[0]) <= $radiusNm;

        case 'MultiPoint':
            if (!is_array($coordinates)) {
                return false;
            }
            foreach ($coordinates as $point) {
                if (is_array($point) && count($point) >= 2 && is_numeric($point[0]) && is_numeric($point[1])) {
                    if (notam_haversine_nm($queryLatitude, $queryLongitude, (float)$point[1], (float)$point[0]) <= $radiusNm) {
                        return true;
                    }
                }
            }
            return false;

        case 'LineString':
            return is_array($coordinates)
                && notam_linestring_intersects_query_circle($coordinates, $queryLatitude, $queryLongitude, $radiusNm);

        case 'MultiLineString':
            if (!is_array($coordinates)) {
                return false;
            }
            foreach ($coordinates as $line) {
                if (is_array($line) && notam_linestring_intersects_query_circle($line, $queryLatitude, $queryLongitude, $radiusNm)) {
                    return true;
                }
            }
            return false;

        case 'Polygon':
            if (!is_array($coordinates)) {
                return false;
            }
            if (notam_polygon_contains_query_center($coordinates, $queryLatitude, $queryLongitude)) {
                return true;
            }
            foreach ($coordinates as $ring) {
                if (is_array($ring) && notam_ring_intersects_query_circle($ring, $queryLatitude, $queryLongitude, $radiusNm)) {
                    return true;
                }
            }
            return false;

        case 'MultiPolygon':
            if (!is_array($coordinates)) {
                return false;
            }
            foreach ($coordinates as $polygon) {
                if (!is_array($polygon)) {
                    continue;
                }
                if (notam_polygon_contains_query_center($polygon, $queryLatitude, $queryLongitude)) {
                    return true;
                }
                foreach ($polygon as $ring) {
                    if (is_array($ring) && notam_ring_intersects_query_circle($ring, $queryLatitude, $queryLongitude, $radiusNm)) {
                        return true;
                    }
                }
            }
            return false;

        case 'GeometryCollection':
            $geometries = $geometry['geometries'] ?? [];
            if (!is_array($geometries)) {
                return false;
            }
            foreach ($geometries as $child) {
                if (notam_geometry_intersects_query_circle($child, $queryLatitude, $queryLongitude, $radiusNm)) {
                    return true;
                }
            }
            return false;
    }

    return false;
}

function notam_feature_matches_query(array $feature, float $latitude, float $longitude, float $radiusNm, array $queryBounds): bool
{
    $bounds = notam_compute_feature_bounds($feature);
    if ($bounds === null) {
        return false;
    }
    if (!notam_bounds_overlap($bounds, $queryBounds)) {
        return false;
    }

    return notam_geometry_intersects_query_circle($feature['geometry'] ?? null, $latitude, $longitude, $radiusNm);
}

function notam_fetch_local_features(PDO $pdo, float $latitude, float $longitude, float $radiusNm): array
{
    $queryBounds = notam_circle_bounds($latitude, $longitude, $radiusNm);

    $sql = "SELECT payload
            FROM notam_items
            WHERE is_active = 1
              AND (
                  min_lat IS NULL OR max_lat IS NULL
                  OR NOT (max_lat < :min_lat OR min_lat > :max_lat)
              )";
    $params = [
        'min_lat' => $queryBounds['min_lat'],
        'max_lat' => $queryBounds['max_lat'],
    ];

    $lonSegments = $queryBounds['lon_segments'] ?? [];
    if (!empty($lonSegments)) {
        $segmentClauses = [];
        foreach ($lonSegments as $index => $segment) {
            $minKey = 'lon_min_' . $index;
            $maxKey = 'lon_max_' . $index;
            $segmentClauses[] = "(min_lon IS NULL OR max_lon IS NULL OR NOT (max_lon < :$minKey OR min_lon > :$maxKey))";
            $params[$minKey] = $segment['min'];
            $params[$maxKey] = $segment['max'];
        }
        $sql .= ' AND (' . implode(' OR ', $segmentClauses) . ')';
    }
    $sql .= ' ORDER BY notam_id';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $features = [];
    while ($row = $stmt->fetch()) {
        $payload = $row['payload'] ?? null;
        if (!is_string($payload) || $payload === '') {
            continue;
        }
        $feature = json_decode($payload, true);
        if (!is_array($feature)) {
            continue;
        }
        if (notam_feature_matches_query($feature, $latitude, $longitude, $radiusNm, $queryBounds)) {
            $features[] = $feature;
        }
    }

    return $features;
}

function notam_filter_features_for_query(array $features, float $latitude, float $longitude, float $radiusNm, bool $onlyActive = true): array
{
    $queryBounds = notam_circle_bounds($latitude, $longitude, $radiusNm);
    $filtered = [];

    foreach ($features as $feature) {
        if (!is_array($feature)) {
            continue;
        }
        if ($onlyActive && !notam_infer_is_active($feature)) {
            continue;
        }
        if (notam_feature_matches_query($feature, $latitude, $longitude, $radiusNm, $queryBounds)) {
            $filtered[] = $feature;
        }
    }

    return $filtered;
}

function notam_validate_degree_string($input): bool
{
    return preg_match('/^(-?\d+(\.\d+)?)$/', (string)$input) === 1;
}

function notam_validate_latitude($input): bool
{
    return notam_validate_degree_string($input) && $input >= -90 && $input <= 90;
}

function notam_validate_longitude($input): bool
{
    return notam_validate_degree_string($input) && $input >= -180 && $input <= 180;
}

function notam_validate_radius($input): bool
{
    return is_numeric($input) && $input >= 0 && $input <= 100;
}

function notam_parse_query_parameters(): array
{
    $longitude = filter_input(INPUT_GET, 'locationLongitude', FILTER_VALIDATE_FLOAT);
    if ($longitude === null || $longitude === false) {
        $longitude = filter_input(INPUT_GET, 'lon', FILTER_VALIDATE_FLOAT);
    }

    $latitude = filter_input(INPUT_GET, 'locationLatitude', FILTER_VALIDATE_FLOAT);
    if ($latitude === null || $latitude === false) {
        $latitude = filter_input(INPUT_GET, 'lat', FILTER_VALIDATE_FLOAT);
    }

    $radius = filter_input(INPUT_GET, 'locationRadius', FILTER_VALIDATE_FLOAT);
    if ($radius === null || $radius === false) {
        $radius = filter_input(INPUT_GET, 'radius', FILTER_VALIDATE_FLOAT);
    }

    if (
        $latitude === null || $latitude === false ||
        $longitude === null || $longitude === false ||
        $radius === null || $radius === false ||
        !notam_validate_longitude($longitude) ||
        !notam_validate_latitude($latitude) ||
        !notam_validate_radius($radius)
    ) {
        throw new InvalidArgumentException('Invalid input parameters');
    }

    return [
        'latitude' => (float)$latitude,
        'longitude' => (float)$longitude,
        'radius' => (float)$radius,
    ];
}

function notam_parse_delta_request(): array
{
    $deltaRequest = false;
    $knownNotams = [];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return [$deltaRequest, $knownNotams];
    }

    $maxBodySize = 1048576;
    if (isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > $maxBodySize) {
        throw new InvalidArgumentException('Request body too large');
    }

    $inputStream = fopen('php://input', 'r');
    if ($inputStream === false) {
        throw new RuntimeException('Failed to read request body');
    }

    $rawBody = stream_get_contents($inputStream, $maxBodySize);
    fclose($inputStream);

    if ($rawBody === false || trim($rawBody) === '') {
        return [$deltaRequest, $knownNotams];
    }

    $payload = json_decode($rawBody, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new InvalidArgumentException('Invalid JSON in POST body: ' . json_last_error_msg());
    }
    if (!is_array($payload) || !isset($payload['known']) || !is_array($payload['known'])) {
        throw new InvalidArgumentException("POST body must contain a 'known' array for delta mode");
    }

    $known = $payload['known'];
    $isList = array_keys($known) === range(0, count($known) - 1);
    if ($isList) {
        foreach ($known as $entry) {
            if (
                is_array($entry) &&
                isset($entry['id'], $entry['lastUpdated']) &&
                is_string($entry['id']) &&
                is_string($entry['lastUpdated'])
            ) {
                $knownNotams[$entry['id']] = $entry['lastUpdated'];
            }
        }
    } else {
        foreach ($known as $id => $lastUpdated) {
            if (is_string($id) && is_string($lastUpdated)) {
                $knownNotams[$id] = $lastUpdated;
            }
        }
    }

    if (empty($knownNotams)) {
        throw new InvalidArgumentException('Delta payload contains no valid entries');
    }

    return [true, $knownNotams];
}

function notam_require_optional_shared_secret(string $envVarName): void
{
    $requiredKey = getenv($envVarName);
    if (!is_string($requiredKey) || $requiredKey === '') {
        if ((getenv('APP_ENV') ?: 'production') === 'production') {
            throw new RuntimeException('Endpoint is disabled until a shared secret is configured');
        }
        return;
    }

    $providedKey = $_GET['key'] ?? ($_SERVER['HTTP_X_NOTAM_KEY'] ?? '');
    if (!is_string($providedKey) || !hash_equals($requiredKey, $providedKey)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Forbidden']);
        exit();
    }
}

function notam_fetch_live_features(PDO $pdo, float $latitude, float $longitude, float $radiusNm): array
{
    $baseUrl = notam_get_api_base();
    $query = http_build_query([
        'longitude' => $longitude,
        'latitude' => $latitude,
        'radius' => $radiusNm,
    ]);
    $url = $baseUrl . '/v1/notams?' . $query;

    [$response, $statusCode, $requestError] = notam_authorized_http_request($pdo, $url, [
        'method' => 'GET',
        'timeout' => 30,
    ]);

    if ($response === false || $statusCode < 200 || $statusCode >= 300) {
        $message = 'Failed to get data from FAA NMS API. Status code: ' . $statusCode;
        if (is_string($requestError) && $requestError !== '') {
            $message .= ' Error: ' . $requestError;
        }
        throw new RuntimeException($message);
    }

    return notam_extract_items_from_payload((string)$response);
}

function notam_index_items_by_id(array $items): array
{
    $indexed = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = notam_extract_notam_id($item);
        if (!is_string($id) || $id === '') {
            continue;
        }
        $indexed[$id] = $item;
    }

    ksort($indexed);
    return $indexed;
}

function notam_compare_item_sets(array $cachedItems, array $liveItems): array
{
    $cachedById = notam_index_items_by_id($cachedItems);
    $liveById = notam_index_items_by_id($liveItems);

    $cachedIds = array_keys($cachedById);
    $liveIds = array_keys($liveById);

    $cachedOnly = array_values(array_diff($cachedIds, $liveIds));
    $liveOnly = array_values(array_diff($liveIds, $cachedIds));
    $commonIds = array_values(array_intersect($cachedIds, $liveIds));

    $changed = [];
    foreach ($commonIds as $id) {
        $cachedPayload = json_encode($cachedById[$id], JSON_UNESCAPED_SLASHES);
        $livePayload = json_encode($liveById[$id], JSON_UNESCAPED_SLASHES);
        if ($cachedPayload !== $livePayload) {
            $changed[] = $id;
        }
    }

    return [
        'cachedCount' => count($cachedItems),
        'liveCount' => count($liveItems),
        'cachedOnlyIds' => $cachedOnly,
        'liveOnlyIds' => $liveOnly,
        'changedIds' => $changed,
        'cachedOnlyCount' => count($cachedOnly),
        'liveOnlyCount' => count($liveOnly),
        'changedCount' => count($changed),
    ];
}

function notam_cache_is_initialized(PDO $pdo): bool
{
    $stmt = $pdo->query("SELECT 1 FROM notam_items WHERE is_active = 1 LIMIT 1");
    return $stmt->fetchColumn() !== false;
}

function notam_build_response_payload(array $items): array
{
    return [
        'pageNum' => 1,
        'totalCount' => count($items),
        'totalPages' => 1,
        'items' => array_values($items),
    ];
}

function notam_build_delta_response_payload(array $items, array $knownNotams): array
{
    $response = notam_build_response_payload($items);
    $filteredItems = [];
    $serverIds = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = notam_extract_notam_id($item);
        $lastUpdated = notam_extract_last_updated($item);

        if (is_string($id)) {
            $serverIds[$id] = is_string($lastUpdated) ? $lastUpdated : null;
        }

        $knownLastUpdated = is_string($id) ? ($knownNotams[$id] ?? null) : null;
        if (!is_string($id) || !is_string($lastUpdated) || $knownLastUpdated === null || $knownLastUpdated !== $lastUpdated) {
            $filteredItems[] = $item;
        }
    }

    $removedIds = [];
    foreach ($knownNotams as $id => $lastUpdated) {
        if (!array_key_exists($id, $serverIds)) {
            $removedIds[] = $id;
        }
    }

    $response['items'] = array_values($filteredItems);
    $response['totalCount'] = count($filteredItems);
    $response['delta'] = true;
    $response['removedIds'] = array_values($removedIds);

    return $response;
}

function notam_get_sync_status(PDO $pdo): array
{
    return [
        'last_delta_cursor' => notam_get_state($pdo, 'faa_last_delta_cursor'),
        'last_delta_sync_at' => notam_get_state($pdo, 'faa_last_delta_sync_at'),
        'last_full_reconcile_started_at' => notam_get_state($pdo, 'faa_last_full_reconcile_started_at'),
        'last_full_reconcile_completed_at' => notam_get_state($pdo, 'faa_last_full_reconcile_completed_at'),
        'sync_in_progress' => notam_get_state($pdo, 'faa_sync_in_progress', '0'),
        'sync_mode' => notam_get_state($pdo, 'faa_sync_mode', ''),
        'sync_started_at' => notam_get_state($pdo, 'faa_sync_started_at', ''),
    ];
}

function notam_format_bytes_human(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    $units = ['KB', 'MB', 'GB', 'TB'];
    $value = (float)$bytes;
    $unitIndex = -1;
    while ($value >= 1024.0 && $unitIndex < count($units) - 1) {
        $value /= 1024.0;
        $unitIndex++;
    }

    return number_format($value, $value >= 10 ? 1 : 2) . ' ' . $units[$unitIndex];
}

function notam_get_max_sync_age_seconds(): int
{
    $configured = getenv('NOTAM_MAX_SYNC_AGE_SECONDS');
    if ($configured === false || $configured === '') {
        return 900;
    }

    $value = (int)$configured;
    return $value > 0 ? $value : 900;
}

function notam_get_reconcile_grace_seconds(): int
{
    $configured = getenv('NOTAM_RECONCILE_GRACE_SECONDS');
    if ($configured === false || $configured === '') {
        return 21600;
    }

    $value = (int)$configured;
    return $value > 0 ? $value : 21600;
}

function notam_should_allow_stale_during_reconcile(
    bool $isStale,
    bool $isInitialized,
    bool $syncInProgress,
    string $syncMode,
    ?string $syncStartedAt,
    ?DateTimeImmutable $now = null,
    ?int $graceSeconds = null
): bool {
    if (!$isStale || !$isInitialized || !$syncInProgress || $syncMode !== 'reconcile' || !is_string($syncStartedAt) || $syncStartedAt === '') {
        return false;
    }

    try {
        $started = new DateTimeImmutable($syncStartedAt);
    } catch (Exception $e) {
        return false;
    }

    $referenceNow = $now ?? notam_now_utc();
    $allowedAge = $graceSeconds ?? notam_get_reconcile_grace_seconds();
    $reconcileAgeSeconds = max(0, $referenceNow->getTimestamp() - $started->getTimestamp());
    return $reconcileAgeSeconds <= $allowedAge;
}

function notam_get_sync_health(PDO $pdo): array
{
    $syncStatus = notam_get_sync_status($pdo);
    $referenceTimestamp = $syncStatus['last_delta_sync_at'] ?: $syncStatus['last_full_reconcile_completed_at'];
    $ageSeconds = null;
    if (is_string($referenceTimestamp) && $referenceTimestamp !== '') {
        try {
            $reference = new DateTimeImmutable($referenceTimestamp);
            $ageSeconds = max(0, notam_now_utc()->getTimestamp() - $reference->getTimestamp());
        } catch (Exception $e) {
            $ageSeconds = null;
        }
    }

    $maxSyncAgeSeconds = notam_get_max_sync_age_seconds();
    $isInitialized = notam_cache_is_initialized($pdo);
    $isStale = !$isInitialized || $ageSeconds === null || $ageSeconds > $maxSyncAgeSeconds;
    $isMaintenance = notam_should_allow_stale_during_reconcile(
        $isStale,
        $isInitialized,
        ($syncStatus['sync_in_progress'] ?? '0') === '1' && notam_is_lock_held($pdo, 'xcsoar_notam_sync'),
        (string)($syncStatus['sync_mode'] ?? ''),
        is_string($syncStatus['sync_started_at'] ?? null) ? $syncStatus['sync_started_at'] : null
    );
    if ($isMaintenance) {
        $isStale = false;
    }

    return [
        'initialized' => $isInitialized,
        'stale' => $isStale,
        'maintenance' => $isMaintenance,
        'ageSeconds' => $ageSeconds,
        'maxSyncAgeSeconds' => $maxSyncAgeSeconds,
        'syncStatus' => $syncStatus,
    ];
}

function notam_send_error_response(int $statusCode, Throwable $exception): void
{
    http_response_code($statusCode);
    $appEnv = getenv('APP_ENV') ?: 'production';
    $isProduction = $appEnv === 'production';

    if ($isProduction) {
        if ($statusCode === 400) {
            $message = 'Invalid request';
        } elseif ($statusCode === 503) {
            $message = 'Service unavailable';
        } else {
            $message = 'Internal server error';
        }
    } else {
        $message = $exception->getMessage();
    }

    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
    error_log($exception->getMessage());
    exit();
}
