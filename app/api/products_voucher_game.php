<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

const MEMBER_API_BASE = 'https://member-api.acispay.serpul.co.id';

function member_api_get(string $path, array $query = []): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'http' => 0, 'error' => 'cURL extension is not available', 'json' => null, 'raw' => null];
    }

    $url = rtrim(MEMBER_API_BASE, '/') . '/' . ltrim($path, '/');
    if (!empty($query)) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: Mozilla/5.0 (compatible; TopupBot/1.0; +https://topup.acispayment.com)',
        ],
    ]);

    // Reuse ip_resolve setting (helps on some shared hostings that prefer IPv6)
    $cfg = require __DIR__ . '/../config/serpul.php';
    $resolve = strtolower((string)($cfg['ip_resolve'] ?? 'any'));
    if (defined('CURLOPT_IPRESOLVE')) {
        if ($resolve === 'v4' && defined('CURL_IPRESOLVE_V4')) {
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        } elseif ($resolve === 'v6' && defined('CURL_IPRESOLVE_V6')) {
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V6);
        }
    }

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = null;
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $json = $decoded;
        }
    }

    return [
        'ok' => ($err === '') && ($http >= 200 && $http < 300) && is_array($json),
        'http' => $http,
        'error' => $err !== '' ? $err : null,
        'json' => $json,
        'raw' => is_string($raw) ? $raw : null,
        'url' => $url,
    ];
}

function cache_path(): string
{
    return __DIR__ . '/../cache/products_voucher_game.json';
}

function read_cache_if_fresh(int $maxAgeSeconds): ?array
{
    $path = cache_path();
    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return null;
    }

    $fetchedAt = (int)($json['fetched_at_unix'] ?? 0);
    if ($fetchedAt <= 0) {
        return null;
    }

    if (time() - $fetchedAt > $maxAgeSeconds) {
        return null;
    }

    return $json;
}

function write_cache(array $payload): void
{
    $path = cache_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

require_method('GET');

// Keep this private to logged-in users.
auth_require_login_api();

$refresh = isset($_GET['refresh']) && (string)$_GET['refresh'] === '1';
$maxAgeSeconds = 6 * 60 * 60; // 6 hours

if (!$refresh) {
    $cached = read_cache_if_fresh($maxAgeSeconds);
    if (is_array($cached)) {
        json_response($cached);
        exit;
    }
}

$now = now_mysql();

// 1) Categories
$catResp = member_api_get('/product/prabayar/category');
if (!$catResp['ok']) {
    append_log('logs/products_voucher_game.log', '[' . $now . '] CATEGORY_FAIL http=' . (int)$catResp['http'] . ' err=' . (($catResp['error'] ?? '-') ?: '-') . ' url=' . (string)($catResp['url'] ?? '-') . ' raw=' . (string)($catResp['raw'] ?? ''));
    json_response(['ok' => false, 'error' => 'Failed to fetch categories', 'http' => (int)$catResp['http']], 502);
    exit;
}

$categories = (array)($catResp['json']['responseData'] ?? []);
$voucherCategory = null;
foreach ($categories as $c) {
    $name = strtolower(trim((string)($c['product_name'] ?? '')));
    if ($name === 'voucher game') {
        $voucherCategory = $c;
        break;
    }
}

// Fallback by known id in this panel (from UI)
if ($voucherCategory === null) {
    foreach ($categories as $c) {
        if ((int)($c['id'] ?? 0) === 7) {
            $voucherCategory = $c;
            break;
        }
    }
}

if ($voucherCategory === null) {
    json_response(['ok' => false, 'error' => 'Voucher Game category not found'], 502);
    exit;
}

$categoryId = (int)($voucherCategory['id'] ?? 0);

// 2) Operators (providers) under Voucher Game
$opResp = member_api_get('/product/prabayar/operator', ['category_id' => $categoryId]);
if (!$opResp['ok']) {
    append_log('logs/products_voucher_game.log', '[' . $now . '] OPERATOR_FAIL http=' . (int)$opResp['http'] . ' err=' . (($opResp['error'] ?? '-') ?: '-') . ' url=' . (string)($opResp['url'] ?? '-') . ' raw=' . (string)($opResp['raw'] ?? ''));
    json_response(['ok' => false, 'error' => 'Failed to fetch voucher game operators', 'http' => (int)$opResp['http']], 502);
    exit;
}

$operators = (array)($opResp['json']['responseData'] ?? []);

$rows = [];
$total = 0;

foreach ($operators as $op) {
    $operatorCode = (string)($op['product_id'] ?? '');
    $operatorName = (string)($op['product_name'] ?? '');
    if ($operatorCode === '') {
        continue;
    }

    $itemsResp = member_api_get('/product/prabayar/product', ['operator_id' => $operatorCode]);
    if (!$itemsResp['ok']) {
        append_log('logs/products_voucher_game.log', '[' . $now . '] ITEMS_FAIL operator=' . $operatorCode . ' http=' . (int)$itemsResp['http'] . ' err=' . (($itemsResp['error'] ?? '-') ?: '-') . ' url=' . (string)($itemsResp['url'] ?? '-') . ' raw=' . (string)($itemsResp['raw'] ?? ''));
        continue;
    }

    $rawItems = (array)($itemsResp['json']['responseData'] ?? []);
    $items = [];

    foreach ($rawItems as $it) {
        $items[] = [
            'code' => (string)($it['product_id'] ?? ''),
            'name' => (string)($it['product_name'] ?? ''),
            'price' => (int)($it['product_price'] ?? 0),
            'status' => strtoupper((string)($it['status'] ?? 'UNKNOWN')),
            'details' => (string)($it['product_detail'] ?? ''),
            'terms' => (string)($it['product_syarat'] ?? ''),
            'zone' => (string)($it['product_zona'] ?? ''),
            'multi' => (string)($it['product_multi'] ?? ''),
            'updated_at' => (string)($it['updated_at'] ?? ''),
        ];
    }

    $total += count($items);

    $rows[] = [
        'operator_id' => $operatorCode,
        'operator_name' => $operatorName,
        'category_id' => (string)($voucherCategory['product_id'] ?? ''),
        'category_name' => (string)($voucherCategory['product_name'] ?? 'Voucher Game'),
        'items' => $items,
    ];
}

$payload = [
    'ok' => true,
    'api_base' => MEMBER_API_BASE,
    'category' => [
        'id' => $categoryId,
        'code' => (string)($voucherCategory['product_id'] ?? ''),
        'name' => (string)($voucherCategory['product_name'] ?? 'Voucher Game'),
    ],
    'fetched_at' => $now,
    'fetched_at_unix' => time(),
    'operators_count' => count($operators),
    'items_count' => $total,
    'rows' => $rows,
];

write_cache($payload);
json_response($payload);
