<?php

require_once __DIR__ . '/app/bootstrap.php';

auth_session_start();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

$params = read_body_params();
$token = (string)($params['_csrf'] ?? '');
if (!csrf_verify($token)) {
    http_response_code(419);
    echo 'Invalid CSRF token';
    exit;
}

auth_logout();

header('Location: login.php');
exit;
