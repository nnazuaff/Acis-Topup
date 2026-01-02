<?php

declare(strict_types=1);

/**
 * Serpul H2H request helper (REST API via cURL).
 * Catatan: struktur payload/header menyesuaikan dokumentasi Serpul Anda.
 */
function serpul_request(string $path, string $method = 'POST', array $payload = []): array
{
    $cfg = require __DIR__ . '/../config/serpul.php';
    $baseUrl = rtrim((string)$cfg['base_url'], '/');
    $url = $baseUrl . '/' . ltrim($path, '/');

    $apiKey = (string)$cfg['api_key'];
    $pin = (string)$cfg['pin'];

    $method = strtoupper($method);

    $ch = curl_init();
    $headers = [
        'Accept: application/json',
        'X-API-KEY: ' . $apiKey,
        'X-PIN: ' . $pin,
        // Variasi header yang kadang dipakai provider
        'APIKEY: ' . $apiKey,
        'PIN: ' . $pin,
        'apikey: ' . $apiKey,
        'pin: ' . $pin,
    ];
    if ($method !== 'GET') {
        $headers[] = 'Content-Type: application/json';
    }

    if ($method === 'GET') {
        if (!empty($payload)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($payload);
        }
    } else {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    // Debug log (testing): mask kredensial
    $mask = static function (array $arr): array {
        $masked = $arr;
        foreach (['pin', 'PIN', 'X-PIN', 'apikey', 'api_key', 'apiKey', 'api', 'X-API-KEY', 'APIKEY'] as $k) {
            if (array_key_exists($k, $masked)) {
                $masked[$k] = '***';
            }
        }
        return $masked;
    };
    $logUrl = $url;
    // Mask query string credentials if present
    $logUrl = preg_replace('/([?&])(apikey|api_key|apiKey|api|pin)=([^&#]*)/i', '$1$2=***', $logUrl);
    append_log('logs/serpul.log', '[' . now_mysql() . '] REQ method=' . $method . ' url=' . $logUrl . ' payload=' . json_encode($mask($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);

    // Force IPv4/IPv6 if configured (fix whitelist mismatch when server prefers IPv6).
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

    append_log('logs/serpul.log', '[' . now_mysql() . '] RES http=' . $http . ' err=' . ($err !== '' ? $err : '-') . ' raw=' . (is_string($raw) ? $raw : ''));

    $json = null;
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $json = $decoded;
        }
    }

    $ok = ($err === '') && ($http >= 200 && $http < 300);
    return [
        'ok' => $ok,
        'http' => $http,
        'error' => $err !== '' ? $err : null,
        'raw' => is_string($raw) ? $raw : null,
        'json' => $json,
        'url' => $url,
    ];
}

/**
 * Request transaksi ke Serpul H2H (template OTOMAX) via Path Center `/without-sign/trx`.
 * Mengikuti istilah GitBook: ID Member + PIN + Password.
 */
function serpul_h2h_trx(array $data): array
{
    $cfg = require __DIR__ . '/../config/serpul.php';

    $memberId = (string)($cfg['member_id'] ?? '');
    // GitBook contoh: "SP1" berarti ID member "1".
    // Normalisasi: ambil angka saja jika ada.
    $memberIdDigits = preg_replace('/[^0-9]/', '', $memberId);
    if (is_string($memberIdDigits) && $memberIdDigits !== '') {
        $memberId = $memberIdDigits;
    }
    $pin = (string)($cfg['pin'] ?? '');

    $password = (string)($cfg['password'] ?? '');
    if ($password === '') {
        $password = (string)($cfg['api_key'] ?? '');
    }

    $endpoint = (string)($cfg['endpoints']['trx'] ?? '/without-sign/trx');
    $method = strtoupper((string)($cfg['order_method'] ?? 'GET'));

    // Parameter ala template OTOMAX (GitBook contoh vendor memakai querystring).
    // Untuk menghindari mismatch penamaan (case/snake_case) di server Serpul,
    // kita kirim beberapa alias key yang umum.

    $productCode = (string)($data['product_code'] ?? '');
    $target = (string)($data['target'] ?? '');
    $trxId = (string)($data['trx_id'] ?? '');
    $qty = (string)($data['qty'] ?? '1');

    $query = [
        // product
        'product' => $productCode,
        'product_code' => $productCode,
        'kodeproduk' => $productCode,

        // qty
        'qty' => $qty,
        'denom' => $qty,

        // destination / target
        'dest' => $target,
        'tujuan' => $target,
        'target' => $target,

        // transaction reference
        'refID' => $trxId,
        'refid' => $trxId,
        'idtrx' => $trxId,
        'trx_id' => $trxId,

        // auth / member
        'memberID' => $memberId,
        'memberId' => $memberId,
        'memberid' => $memberId,
        'member_id' => $memberId,
        'id_member' => $memberId,
        'pin' => $pin,
        'password' => $password,
        'pass' => $password,
    ];

    // Callback URL (kirim beberapa variasi key untuk kompatibilitas)
    if (!empty($data['callback_url'])) {
        $query['callback'] = (string)$data['callback_url'];
        $query['callback_url'] = (string)$data['callback_url'];
        $query['url_callback'] = (string)$data['callback_url'];
    }

    return serpul_request($endpoint, $method, $query);
}

function serpul_parse_trx_status(string $raw): array
{
    $rawTrim = trim($raw);
    $upper = strtoupper($rawTrim);

    $status = 'PENDING';
    if ($rawTrim === '') {
        return ['status' => $status, 'message' => ''];
    }

    // Heuristik dari format umum vendor/H2H (SUKSES/BERHASIL/GAGAL/PROSES/PENDING)
    if (preg_match('/\b(SUKSES|SUCCESS|BERHASIL|COMPLETED|DONE)\b/i', $upper)) {
        $status = 'SUCCESS';
    } elseif (preg_match('/\b(GAGAL|FAILED|ERROR)\b/i', $upper)) {
        $status = 'FAILED';
    } elseif (preg_match('/\b(PENDING|PROSES|PROCESS|IN\s*PROGRESS|WAITING)\b/i', $upper)) {
        $status = 'PENDING';
    }

    // Message: simpan teks asli (dipotong) agar mudah debug
    $message = $rawTrim;
    if (strlen($message) > 240) {
        $message = substr($message, 0, 240) . '...';
    }

    return ['status' => $status, 'message' => $message];
}
