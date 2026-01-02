<?php

require_once __DIR__ . '/../bootstrap.php';

require_method('POST');

$params = read_body_params();

$productCode = trim((string)($params['product_code'] ?? ''));
$target = trim((string)($params['target'] ?? ''));
$price = (int)($params['price'] ?? 0);

if ($productCode === '' || $target === '') {
    json_response(['ok' => false, 'error' => 'product_code dan target wajib diisi'], 422);
    exit;
}

$trxId = gen_trx_id();
$now = now_mysql();

try {
    $pdo = db();
    $stmt = $pdo->prepare(
        'INSERT INTO transactions (trx_id, product_code, target, price, status, message, created_at, updated_at)
         VALUES (:trx_id, :product_code, :target, :price, :status, :message, :created_at, :updated_at)'
    );
    $stmt->execute([
        ':trx_id' => $trxId,
        ':product_code' => $productCode,
        ':target' => $target,
        ':price' => $price,
        ':status' => 'PENDING',
        ':message' => 'Order created',
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'DB error', 'detail' => $e->getMessage()], 500);
    exit;
}

// Trigger realtime: order dibuat
pusher_trigger('transaction-' . $trxId, 'status-update', [
    'trx_id' => $trxId,
    'status' => 'PENDING',
    'message' => 'Order created',
    'at' => $now,
]);

// Kirim request order ke Serpul
$serpulCfg = require __DIR__ . '/../config/serpul.php';
$orderMethod = strtoupper((string)($serpulCfg['order_method'] ?? 'GET'));

$publicBaseUrl = rtrim((string)($serpulCfg['public_base_url'] ?? ''), '/');
if ($publicBaseUrl === '') {
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    $publicBaseUrl = $host !== '' ? ('https://' . $host) : '';
}
$callbackPath = (string)($serpulCfg['callback_path'] ?? '/callback');
$callbackUrl = $publicBaseUrl !== '' ? ($publicBaseUrl . $callbackPath) : $callbackPath;

$serpulPayload = [
    'trx_id' => $trxId,
    'product_code' => $productCode,
    'target' => $target,
    'price' => $price,
    // Callback harus public HTTPS
    'callback_url' => $callbackUrl,
];

$serpulResp = serpul_h2h_trx($serpulPayload);

$immediateStatus = 'PENDING';
$immediateMessage = $serpulResp['ok'] ? 'Sent to Serpul' : ('Serpul error: ' . ($serpulResp['error'] ?? ('HTTP ' . $serpulResp['http'])));
if ($serpulResp['ok'] && is_string($serpulResp['raw'])) {
    $parsed = serpul_parse_trx_status($serpulResp['raw']);
    $immediateStatus = (string)($parsed['status'] ?? 'PENDING');
    $immediateMessage = (string)($parsed['message'] ?? $immediateMessage);
}

// Simpan message response (untuk debugging testing)
try {
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE transactions SET status = :status, message = :message, updated_at = :updated_at WHERE trx_id = :trx_id');
    $stmt->execute([
        ':status' => $immediateStatus,
        ':message' => $immediateMessage,
        ':updated_at' => now_mysql(),
        ':trx_id' => $trxId,
    ]);
} catch (Throwable $e) {
    // ignore
}

// Trigger realtime: update dari respon langsung Serpul (jika bukan PENDING)
if ($immediateStatus !== 'PENDING') {
    pusher_trigger('transaction-' . $trxId, 'status-update', [
        'trx_id' => $trxId,
        'status' => $immediateStatus,
        'message' => $immediateMessage,
        'at' => now_mysql(),
    ]);
}

json_response([
    'ok' => true,
    'trx_id' => $trxId,
    'status' => $immediateStatus,
    'serpul' => [
        'ok' => $serpulResp['ok'],
        'http' => $serpulResp['http'],
        'error' => $serpulResp['error'],
        'json' => $serpulResp['json'],
        'raw' => $serpulResp['raw'],
    ],
]);
