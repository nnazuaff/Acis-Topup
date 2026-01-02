<?php

declare(strict_types=1);

function json_response(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function require_method(string $method): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
        json_response(['ok' => false, 'error' => 'Method Not Allowed'], 405);
        exit;
    }
}

function get_request_headers(): array
{
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            return $headers;
        }
    }

    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) {
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            $headers[$name] = $value;
        }
    }
    return $headers;
}

function read_raw_body(): string
{
    $raw = file_get_contents('php://input');
    return is_string($raw) ? $raw : '';
}

function read_body_params(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = read_raw_body();
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
    return $_POST;
}

function now_mysql(): string
{
    return date('Y-m-d H:i:s');
}

function safe_equals(string $a, string $b): bool
{
    if (function_exists('hash_equals')) {
        return hash_equals($a, $b);
    }
    if (strlen($a) !== strlen($b)) {
        return false;
    }
    $res = 0;
    for ($i = 0; $i < strlen($a); $i++) {
        $res |= ord($a[$i]) ^ ord($b[$i]);
    }
    return $res === 0;
}

function append_log(string $relativePathFromApp, string $line): void
{
    $path = __DIR__ . '/../' . ltrim($relativePathFromApp, '/');
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($path, $line . "\n", FILE_APPEND);
}

function gen_trx_id(): string
{
    try {
        $rnd = bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        $rnd = uniqid('', true);
        $rnd = preg_replace('/[^a-zA-Z0-9]/', '', $rnd);
        $rnd = substr($rnd, 0, 16);
    }
    return 'TRX' . strtoupper($rnd);
}
