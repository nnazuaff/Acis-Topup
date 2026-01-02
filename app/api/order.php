<?php

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

function lookup_product_price(string $operatorId, string $productCode): array
{
    $operatorId = strtoupper(trim($operatorId));
    $productCode = strtoupper(trim($productCode));
    if ($operatorId === '' || $productCode === '') {
        return ['ok' => false, 'error' => 'Invalid operator_id/product_code'];
    }

    $cachePath = __DIR__ . '/../cache/products_voucher_game.json';
    if (is_file($cachePath)) {
        $raw = @file_get_contents($cachePath);
        $cached = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($cached) && isset($cached['rows']) && is_array($cached['rows'])) {
            foreach ($cached['rows'] as $row) {
                if (strtoupper((string)($row['operator_id'] ?? '')) !== $operatorId) {
                    continue;
                }
                $items = (array)($row['items'] ?? []);
                foreach ($items as $it) {
                    if (strtoupper((string)($it['code'] ?? '')) === $productCode) {
                        $price = (int)($it['price'] ?? 0);
                        $status = strtoupper((string)($it['status'] ?? ''));
                        if ($status !== '' && $status !== 'ACTIVE') {
                            return ['ok' => false, 'error' => 'Product not active'];
                        }
                        return ['ok' => true, 'price' => $price, 'name' => (string)($it['name'] ?? ''), 'source' => 'cache'];
                    }
                }
            }
        }
    }

    $resp = member_api_get('/product/prabayar/product', ['operator_id' => $operatorId]);
    if (!($resp['ok'] ?? false)) {
        return ['ok' => false, 'error' => 'Failed to fetch product list', 'http' => (int)($resp['http'] ?? 0)];
    }

    $items = (array)($resp['json']['responseData'] ?? []);
    foreach ($items as $it) {
        if (strtoupper((string)($it['product_id'] ?? '')) === $productCode) {
            $price = (int)($it['product_price'] ?? 0);
            $status = strtoupper((string)($it['status'] ?? ''));
            if ($status !== '' && $status !== 'ACTIVE') {
                return ['ok' => false, 'error' => 'Product not active'];
            }
            return ['ok' => true, 'price' => $price, 'name' => (string)($it['product_name'] ?? ''), 'source' => 'member-api'];
        }
    }

    return ['ok' => false, 'error' => 'Product not found'];
}

require_method('POST');

auth_require_login_api();
csrf_verify_header_or_fail();

$params = read_body_params();

$operatorId = strtoupper(trim((string)($params['operator_id'] ?? '')));
$productCode = strtoupper(trim((string)($params['product_code'] ?? '')));
$target = trim((string)($params['target'] ?? ''));

if ($operatorId === '') {
    json_response(['ok' => false, 'error' => 'operator_id wajib diisi'], 422);
    exit;
}

if ($productCode === '' || $target === '') {
    json_response(['ok' => false, 'error' => 'product_code dan target wajib diisi'], 422);
    exit;
}

$priceInfo = lookup_product_price($operatorId, $productCode);
if (!($priceInfo['ok'] ?? false)) {
    json_response(['ok' => false, 'error' => (string)($priceInfo['error'] ?? 'Produk tidak ditemukan')], 422);
    exit;
}

$price = (int)($priceInfo['price'] ?? 0);
if ($price <= 0) {
    json_response(['ok' => false, 'error' => 'Harga produk tidak valid'], 422);
    exit;
}

$trxId = gen_trx_id();
$now = now_mysql();
$userId = auth_current_user_id();

// Debit wallet atomically (balance must be sufficient)
try {
    $pdo = db();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT balance FROM users WHERE id = :id FOR UPDATE');
    $stmt->execute([':id' => (int)$userId]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        $pdo->rollBack();
        json_response(['ok' => false, 'error' => 'User tidak ditemukan'], 404);
        exit;
    }

    $balance = (int)($row['balance'] ?? 0);
    if ($balance < $price) {
        $pdo->rollBack();
        json_response(['ok' => false, 'error' => 'Saldo tidak cukup', 'balance' => $balance, 'price' => $price], 402);
        exit;
    }

    $upd = $pdo->prepare('UPDATE users SET balance = balance - :amt, updated_at = :updated_at WHERE id = :id');
    $upd->execute([':amt' => $price, ':updated_at' => $now, ':id' => (int)$userId]);

    $pdo->commit();
} catch (Throwable $e) {
    try {
        $pdo = db();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Throwable $ignore) {
        // ignore
    }
    json_response(['ok' => false, 'error' => 'DB error (wallet)', 'detail' => $e->getMessage()], 500);
    exit;
}

try {
    $pdo = db();
    $stmt = $pdo->prepare(
        'INSERT INTO transactions (user_id, trx_id, operator_id, product_code, target, price, wallet_debited, wallet_refunded, status, message, created_at, updated_at)
         VALUES (:user_id, :trx_id, :operator_id, :product_code, :target, :price, :wallet_debited, :wallet_refunded, :status, :message, :created_at, :updated_at)'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':trx_id' => $trxId,
        ':operator_id' => $operatorId,
        ':product_code' => $productCode,
        ':target' => $target,
        ':price' => $price,
        ':wallet_debited' => $price,
        ':wallet_refunded' => 0,
        ':status' => 'PENDING',
        ':message' => 'Order created (wallet debited)',
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
} catch (Throwable $e) {
    // If insert fails, refund the wallet
    try {
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE users SET balance = balance + :amt, updated_at = :updated_at WHERE id = :id')
            ->execute([':amt' => $price, ':updated_at' => now_mysql(), ':id' => (int)$userId]);
        $pdo->commit();
    } catch (Throwable $ignore) {
        try {
            $pdo = db();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable $ignore2) {
            // ignore
        }
    }
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

$immediateStatus = $serpulResp['ok'] ? 'PENDING' : 'FAILED';
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

// Refund wallet on immediate failure
if ($immediateStatus === 'FAILED') {
    try {
        $pdo = db();
        $pdo->beginTransaction();
        $tx = $pdo->prepare('SELECT user_id, wallet_debited, wallet_refunded FROM transactions WHERE trx_id = :trx_id FOR UPDATE');
        $tx->execute([':trx_id' => $trxId]);
        $row = $tx->fetch();
        if (is_array($row)) {
            $debited = (int)($row['wallet_debited'] ?? 0);
            $refunded = (int)($row['wallet_refunded'] ?? 0);
            $uid = (int)($row['user_id'] ?? 0);
            if ($uid > 0 && $debited > 0 && $refunded <= 0) {
                $pdo->prepare('UPDATE users SET balance = balance + :amt, updated_at = :updated_at WHERE id = :id')
                    ->execute([':amt' => $debited, ':updated_at' => now_mysql(), ':id' => $uid]);
                $pdo->prepare('UPDATE transactions SET wallet_refunded = :amt, updated_at = :updated_at WHERE trx_id = :trx_id')
                    ->execute([':amt' => $debited, ':updated_at' => now_mysql(), ':trx_id' => $trxId]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        try {
            $pdo = db();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable $ignore) {
            // ignore
        }
    }
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
    'price' => $price,
    'operator_id' => $operatorId,
    'product' => [
        'code' => $productCode,
        'name' => (string)($priceInfo['name'] ?? ''),
        'source' => (string)($priceInfo['source'] ?? ''),
    ],
    'serpul' => [
        'ok' => $serpulResp['ok'],
        'http' => $serpulResp['http'],
        'error' => $serpulResp['error'],
        'json' => $serpulResp['json'],
        'raw' => $serpulResp['raw'],
    ],
]);
