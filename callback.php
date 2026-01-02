<?php

// Endpoint publik untuk Serpul callback.
// Catatan: callback real = POST. Namun panel Serpul biasanya melakukan "Check" via GET,
// jadi GET/HEAD kita balas 200 JSON agar tidak dianggap 404/invalid.

require_once __DIR__ . '/app/bootstrap.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? ''));
if ($method === 'GET' || $method === 'HEAD') {
	json_response(['ok' => true, 'message' => 'Callback endpoint ready']);
	exit;
}

// Bridge callback POST ke handler
require_once __DIR__ . '/app/api/callback_handler.php';
