<?php

require_once __DIR__ . '/../bootstrap.php';

require_method('POST');

// Wajib response cepat: lakukan validasi ringan + update DB + trigger pusher
$headers = get_request_headers();
$params = read_body_params();
$raw = read_raw_body();

$cfg = require __DIR__ . '/../config/serpul.php';
$expectedApiKey = (string)$cfg['api_key'];
$expectedPin = (string)$cfg['pin'];

$incomingApiKey = (string)($params['apikey'] ?? $params['api_key'] ?? ($headers['X-API-KEY'] ?? $headers['X-Api-Key'] ?? ''));
$incomingPin = (string)($params['pin'] ?? ($headers['X-PIN'] ?? $headers['X-Pin'] ?? ''));

if ($expectedApiKey !== '' && !safe_equals($expectedApiKey, $incomingApiKey)) {
    append_log('logs/callback.log', '[' . now_mysql() . '] INVALID_APIKEY ip=' . ($_SERVER['REMOTE_ADDR'] ?? '-') . ' raw=' . $raw);
    json_response(['ok' => false, 'error' => 'Invalid API Key'], 401);
    exit;
}

if ($expectedPin !== '' && !safe_equals($expectedPin, $incomingPin)) {
    append_log('logs/callback.log', '[' . now_mysql() . '] INVALID_PIN ip=' . ($_SERVER['REMOTE_ADDR'] ?? '-') . ' raw=' . $raw);
    json_response(['ok' => false, 'error' => 'Invalid PIN'], 401);
    exit;
}

// Field callback (sesuaikan dengan dokumentasi Serpul Anda)
$trxId = trim((string)($params['trx_id'] ?? $params['trxId'] ?? ''));
$status = strtoupper(trim((string)($params['status'] ?? '')));
$message = trim((string)($params['message'] ?? $params['msg'] ?? ''));

if ($trxId === '' || $status === '') {
    append_log('logs/callback.log', '[' . now_mysql() . '] INVALID_PAYLOAD ip=' . ($_SERVER['REMOTE_ADDR'] ?? '-') . ' raw=' . $raw);
    json_response(['ok' => false, 'error' => 'Invalid payload'], 422);
    exit;
}

// Normalisasi status
$normalized = 'PENDING';
if (in_array($status, ['SUCCESS', 'SUKSES', 'DONE', 'COMPLETED'], true)) {
    $normalized = 'SUCCESS';
} elseif (in_array($status, ['FAILED', 'GAGAL', 'ERROR'], true)) {
    $normalized = 'FAILED';
}

$now = now_mysql();

try {
    $pdo = db();
    $pdo->beginTransaction();

    $tx = $pdo->prepare('SELECT user_id, wallet_debited, wallet_refunded FROM transactions WHERE trx_id = :trx_id FOR UPDATE');
    $tx->execute([':trx_id' => $trxId]);
    $row = $tx->fetch();

    $stmt = $pdo->prepare('UPDATE transactions SET status = :status, message = :message, updated_at = :updated_at WHERE trx_id = :trx_id');
    $stmt->execute([
        ':status' => $normalized,
        ':message' => $message !== '' ? $message : ('Callback: ' . $status),
        ':updated_at' => $now,
        ':trx_id' => $trxId,
    ]);

    // Refund wallet if FAILED and not refunded yet
    if ($normalized === 'FAILED' && is_array($row)) {
        $uid = (int)($row['user_id'] ?? 0);
        $debited = (int)($row['wallet_debited'] ?? 0);
        $refunded = (int)($row['wallet_refunded'] ?? 0);
        if ($uid > 0 && $debited > 0 && $refunded <= 0) {
            $pdo->prepare('UPDATE users SET balance = balance + :amt, updated_at = :updated_at WHERE id = :id')
                ->execute([':amt' => $debited, ':updated_at' => $now, ':id' => $uid]);
            $pdo->prepare('UPDATE transactions SET wallet_refunded = :amt, updated_at = :updated_at WHERE trx_id = :trx_id')
                ->execute([':amt' => $debited, ':updated_at' => $now, ':trx_id' => $trxId]);
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
    append_log('logs/callback.log', '[' . $now . '] DB_ERROR trx_id=' . $trxId . ' err=' . $e->getMessage() . ' raw=' . $raw);
    json_response(['ok' => false, 'error' => 'DB error'], 500);
    exit;
}

// Log callback
append_log('logs/callback.log', '[' . $now . '] OK trx_id=' . $trxId . ' status=' . $normalized . ' ip=' . ($_SERVER['REMOTE_ADDR'] ?? '-') . ' raw=' . $raw);

// Trigger realtime: callback diterima
pusher_trigger('transaction-' . $trxId, 'status-update', [
    'trx_id' => $trxId,
    'status' => $normalized,
    'message' => $message,
    'at' => $now,
]);

json_response(['ok' => true]);
