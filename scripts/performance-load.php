<?php

declare(strict_types=1);

if (! extension_loaded('curl')) {
    fwrite(STDERR, "The cURL PHP extension is required.\n");
    exit(2);
}

$base = rtrim(getenv('PERFORMANCE_BASE_URL') ?: 'http://127.0.0.1:8002', '/');
$email = getenv('PERFORMANCE_EMAIL') ?: 'performance@sellchaze.test';
$password = getenv('PERFORMANCE_PASSWORD') ?: 'PerformanceOnly123!';
$host = getenv('PERFORMANCE_STOREFRONT_HOST') ?: 'performance-store.sellchase.com';
$requests = max(10, (int) (getenv('PERFORMANCE_REQUESTS') ?: 40));
$concurrency = max(1, (int) (getenv('PERFORMANCE_CONCURRENCY') ?: 4));

function single(string $url, string $method = 'GET', array $headers = [], ?string $body = null): array
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $body, CURLOPT_TIMEOUT => 15]);
    $response = curl_exec($curl);
    $result = ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'body' => (string) $response];
    curl_close($curl);

    return $result;
}

$login = single($base.'/api/v1/auth/login', 'POST', ['Content-Type: application/json'], json_encode(['email' => $email, 'password' => $password]));
$loginPayload = json_decode($login['body'], true);
$token = is_array($loginPayload) ? ($loginPayload['access_token'] ?? null) : null;
if ($login['status'] !== 200 || ! is_string($token)) {
    $keys = is_array($loginPayload) ? implode(',', array_keys($loginPayload)) : 'invalid-json:'.json_last_error_msg();
    $prefix = bin2hex(substr($login['body'], 0, 8));
    $preamble = strstr($login['body'], '{', true);
    $diagnostic = trim((string) preg_replace('/\s+/', ' ', strip_tags($preamble === false ? '' : $preamble)));
    $diagnostic = substr($diagnostic, 0, 300);
    fwrite(STDERR, "Performance login failed ({$login['status']}; bytes=".strlen($login['body'])."; prefix={$prefix}; keys={$keys}; diagnostic={$diagnostic}). Run: php artisan performance:seed\n");
    exit(2);
}

function runLoad(string $url, int $count, int $concurrency, array $headers, string $method = 'GET'): array
{
    $samples = [];
    for ($offset = 0; $offset < $count; $offset += $concurrency) {
        $multi = curl_multi_init();
        $handles = [];
        for ($i = $offset; $i < min($count, $offset + $concurrency); $i++) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 15]);
            curl_multi_add_handle($multi, $curl);
            $handles[] = $curl;
        }
        do {
            $status = curl_multi_exec($multi, $active);
            if ($active) {
                curl_multi_select($multi, 0.2);
            }
        } while ($active && $status === CURLM_OK);
        foreach ($handles as $curl) {
            $samples[] = [
                'status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
                'total_ms' => curl_getinfo($curl, CURLINFO_TOTAL_TIME) * 1000,
                'ttfb_ms' => curl_getinfo($curl, CURLINFO_STARTTRANSFER_TIME) * 1000,
            ];
            curl_multi_remove_handle($multi, $curl);
            curl_close($curl);
        }
        curl_multi_close($multi);
    }

    return $samples;
}

function percentile(array $values, float $percent): float
{
    sort($values, SORT_NUMERIC);

    return $values[max(0, (int) ceil(count($values) * $percent) - 1)];
}

$auth = ['Accept: application/json', 'Authorization: Bearer '.$token];
$scenarios = [
    'storefront_ttfb' => ['url' => $base.'/', 'headers' => ['Host: '.$host], 'metric' => 'ttfb_ms', 'percent' => .75, 'limit' => 800, 'method' => 'GET'],
    'store_search_read' => ['url' => $base.'/api/v1/storefront/products?q=Performance', 'headers' => ['Accept: application/json', 'Host: '.$host], 'metric' => 'total_ms', 'percent' => .95, 'limit' => 300, 'method' => 'GET'],
    'feed_read' => ['url' => $base.'/api/v1/feed?cursor=&per_page=20', 'headers' => $auth, 'metric' => 'total_ms', 'percent' => .95, 'limit' => 300, 'method' => 'GET'],
    'notifications_read' => ['url' => $base.'/api/v1/notifications?per_page=20', 'headers' => $auth, 'metric' => 'total_ms', 'percent' => .95, 'limit' => 300, 'method' => 'GET'],
    // A single account cannot meaningfully mark the same inbox read four times
    // at once. Keep reads concurrent while measuring the representative write
    // path sequentially; database write saturation belongs in deployment tests.
    'notification_write' => ['url' => $base.'/api/v1/notifications/read-all', 'headers' => $auth, 'metric' => 'total_ms', 'percent' => .95, 'limit' => 500, 'method' => 'POST', 'concurrency' => 1],
];

$report = ['generated_at' => gmdate(DATE_ATOM), 'base_url' => $base, 'requests_per_scenario' => $requests, 'concurrency' => $concurrency, 'scenarios' => []];
$failed = false;
foreach ($scenarios as $name => $scenario) {
    single($scenario['url'], $scenario['method'], $scenario['headers']); // warm cache/connection
    $scenarioConcurrency = (int) ($scenario['concurrency'] ?? $concurrency);
    $samples = runLoad($scenario['url'], $requests, $scenarioConcurrency, $scenario['headers'], $scenario['method']);
    $badStatuses = count(array_filter($samples, fn (array $sample) => $sample['status'] < 200 || $sample['status'] >= 300));
    $measured = percentile(array_column($samples, $scenario['metric']), $scenario['percent']);
    $passed = $badStatuses === 0 && $measured <= $scenario['limit'];
    $report['scenarios'][$name] = ['measured_ms' => round($measured, 2), 'limit_ms' => $scenario['limit'], 'concurrency' => $scenarioConcurrency, 'errors' => $badStatuses, 'passed' => $passed];
    $failed = $failed || ! $passed;
}

$target = __DIR__.'/../storage/app/performance/latest.json';
if (! is_dir(dirname($target))) {
    mkdir(dirname($target), 0775, true);
}
file_put_contents($target, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed ? 1 : 0);
