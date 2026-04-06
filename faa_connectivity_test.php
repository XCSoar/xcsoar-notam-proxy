<?php

declare(strict_types=1);

require_once __DIR__ . '/notam_lib.php';

function connectivity_out(string $line = ''): void
{
    echo $line . "\n";
}

function connectivity_bool(bool $value): string
{
    return $value ? 'yes' : 'no';
}

function connectivity_trim_body($body, int $max = 240): string
{
    if (!is_string($body)) {
        return '';
    }

    $body = trim(preg_replace('/\s+/', ' ', $body) ?? '');
    if (strlen($body) > $max) {
        return substr($body, 0, $max) . '...';
    }

    return $body;
}

function connectivity_require_access(): void
{
    $requiredKey = getenv('FAA_CONNECTIVITY_KEY');
    if (!is_string($requiredKey) || $requiredKey === '') {
        if ((getenv('APP_ENV') ?: 'production') === 'production') {
            http_response_code(503);
            header('Content-Type: text/plain; charset=UTF-8');
            echo "Connectivity test disabled until FAA_CONNECTIVITY_KEY is configured.\n";
            exit();
        }
        return;
    }

    $providedKey = $_GET['key'] ?? ($_SERVER['HTTP_X_CONNECTIVITY_KEY'] ?? '');
    if (!is_string($providedKey) || !hash_equals($requiredKey, $providedKey)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Forbidden\n";
        exit();
    }
}

function connectivity_get_env(string $name, ?string $default = null): ?string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }
    return $value;
}

function connectivity_request(string $url, string $method = 'GET', array $headers = [], ?string $body = null): array
{
    return notam_http_request($url, [
        'http' => [
            'method' => $method,
            'headers' => $headers,
            'content' => $body,
            'timeout' => 30,
        ],
    ], true);
}

function connectivity_fetch_token(string $authUrl, string $clientId, string $clientSecret): array
{
    $basicAuth = base64_encode($clientId . ':' . $clientSecret);
    [$response, $statusCode, $errorMessage] = connectivity_request(
        $authUrl,
        'POST',
        [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . $basicAuth,
        ],
        http_build_query(['grant_type' => 'client_credentials'])
    );

    $token = null;
    if (is_string($response) && $statusCode >= 200 && $statusCode < 300) {
        $decoded = json_decode($response, true);
        if (is_array($decoded) && !empty($decoded['access_token']) && is_string($decoded['access_token'])) {
            $token = $decoded['access_token'];
        }
    }

    return [
        'status' => $statusCode,
        'error' => $errorMessage,
        'body' => connectivity_trim_body($response),
        'token' => $token,
    ];
}

function connectivity_probe_api(string $baseUrl, string $token): array
{
    $url = rtrim($baseUrl, '/') . '/v1/notams/checklist?location=KJFK&classification=DOMESTIC';
    [$response, $statusCode, $errorMessage] = connectivity_request($url, 'GET', [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ]);

    return [
        'url' => $url,
        'status' => $statusCode,
        'error' => $errorMessage,
        'body' => connectivity_trim_body($response),
    ];
}

connectivity_require_access();

$authUrl = connectivity_get_env('FAA_AUTH_URL', 'https://api-nms.aim.faa.gov/v1/auth/token');
$baseUrl = connectivity_get_env('FAA_API_BASE', 'https://api-nms.aim.faa.gov/nmsapi');
$clientId = connectivity_get_env('FAA_ID');
$clientSecret = connectivity_get_env('FAA_SECRET');

$hasCurl = function_exists('curl_init');
$hasOpenSsl = extension_loaded('openssl');
$ok = true;

header('Content-Type: text/plain; charset=UTF-8');

connectivity_out('FAA connectivity diagnostics');
connectivity_out('php_version: ' . PHP_VERSION);
connectivity_out('curl_enabled: ' . connectivity_bool($hasCurl));
connectivity_out('openssl_enabled: ' . connectivity_bool($hasOpenSsl));
connectivity_out('faa_auth_url: ' . $authUrl);
connectivity_out('faa_api_base: ' . $baseUrl);
connectivity_out('faa_id_present: ' . connectivity_bool(is_string($clientId) && $clientId !== ''));
connectivity_out('faa_secret_present: ' . connectivity_bool(is_string($clientSecret) && $clientSecret !== ''));

if (!$hasCurl || !$hasOpenSsl || !is_string($clientId) || $clientId === '' || !is_string($clientSecret) || $clientSecret === '') {
    http_response_code(500);
    connectivity_out('result: FAILED');
    exit(1);
}

$dnsHost = parse_url($authUrl, PHP_URL_HOST);
if (is_string($dnsHost) && $dnsHost !== '') {
    $resolved = gethostbyname($dnsHost);
    connectivity_out('auth_dns: ' . $dnsHost . ' -> ' . $resolved);
}

$auth = connectivity_fetch_token($authUrl, $clientId, $clientSecret);
connectivity_out('auth_post_status: ' . $auth['status']);
if (is_string($auth['error']) && $auth['error'] !== '') {
    connectivity_out('auth_post_error: ' . $auth['error']);
}
connectivity_out('auth_post_body: ' . $auth['body']);

if (!is_string($auth['token']) || $auth['token'] === '') {
    $ok = false;
} else {
    $probe = connectivity_probe_api($baseUrl, $auth['token']);
    connectivity_out('auth_api_probe_url: ' . $probe['url']);
    connectivity_out('auth_api_probe_status: ' . $probe['status']);
    if (is_string($probe['error']) && $probe['error'] !== '') {
        connectivity_out('auth_api_probe_error: ' . $probe['error']);
    }
    connectivity_out('auth_api_probe_body: ' . $probe['body']);

    if ($probe['status'] < 200 || $probe['status'] >= 300) {
        $ok = false;
    }
}

http_response_code($ok ? 200 : 502);
connectivity_out('result: ' . ($ok ? 'OK' : 'FAILED'));
