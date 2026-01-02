<?php

declare(strict_types=1);

/**
 * Trigger event ke Pusher Channels via REST API (tanpa library).
 * Docs: https://pusher.com/docs/channels/library_auth_reference/rest-api/
 */
function pusher_trigger(string $channel, string $event, array $data): array
{
    $cfg = require __DIR__ . '/../config/pusher.php';

    $appId = (string)$cfg['app_id'];
    $key = (string)$cfg['key'];
    $secret = (string)$cfg['secret'];
    $cluster = (string)($cfg['cluster'] ?? 'mt1');

    $path = "/apps/{$appId}/events";
    $host = "api-{$cluster}.pusher.com";
    $baseUrl = "https://{$host}{$path}";

    // Pusher mengharuskan 'data' berupa string JSON.
    $bodyArr = [
        'name' => $event,
        'channels' => [$channel],
        'data' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
    $body = json_encode($bodyArr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $bodyMd5 = md5($body);
    $timestamp = time();

    $query = [
        'auth_key' => $key,
        'auth_timestamp' => (string)$timestamp,
        'auth_version' => '1.0',
        'body_md5' => $bodyMd5,
    ];
    ksort($query);
    $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

    $stringToSign = "POST\n{$path}\n{$queryString}";
    $signature = hash_hmac('sha256', $stringToSign, $secret);

    $url = $baseUrl . '?' . $queryString . '&auth_signature=' . $signature;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_POSTFIELDS => $body,
    ]);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $ok = ($err === '') && ($http >= 200 && $http < 300);
    return [
        'ok' => $ok,
        'http' => $http,
        'error' => $err !== '' ? $err : null,
        'raw' => is_string($raw) ? $raw : null,
        'url' => $url,
    ];
}
