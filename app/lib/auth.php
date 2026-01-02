<?php

declare(strict_types=1);

function auth_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Harden sessions (safe defaults for shared hosting)
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.cookie_httponly', '1');

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

    // PHP 7.3+ supports samesite via array; older versions ignore unknown keys
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_name('TOPUPSESSID');
    session_start();
}

function auth_current_user_id(): ?int
{
    auth_session_start();
    $uid = $_SESSION['user_id'] ?? null;
    if (is_int($uid) && $uid > 0) {
        return $uid;
    }
    if (is_string($uid) && ctype_digit($uid) && (int)$uid > 0) {
        return (int)$uid;
    }
    return null;
}

function auth_require_login_page(): void
{
    if (auth_current_user_id() !== null) {
        return;
    }
    header('Location: login.php');
    exit;
}

function auth_require_login_api(): void
{
    if (auth_current_user_id() !== null) {
        return;
    }
    json_response(['ok' => false, 'error' => 'Unauthenticated'], 401);
    exit;
}

function csrf_token(): string
{
    auth_session_start();
    $token = (string)($_SESSION['csrf_token'] ?? '');
    if ($token === '') {
        try {
            $token = bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            $token = bin2hex((string)microtime(true));
        }
        $_SESSION['csrf_token'] = $token;
    }
    return $token;
}

function csrf_verify(string $provided): bool
{
    auth_session_start();
    $expected = (string)($_SESSION['csrf_token'] ?? '');
    if ($expected === '' || $provided === '') {
        return false;
    }
    return safe_equals($expected, $provided);
}

function csrf_verify_post_or_fail(): void
{
    $params = read_body_params();
    $token = (string)($params['_csrf'] ?? '');
    if (!csrf_verify($token)) {
        json_response(['ok' => false, 'error' => 'Invalid CSRF token'], 419);
        exit;
    }
}

function csrf_verify_header_or_fail(): void
{
    $headers = get_request_headers();
    $token = (string)($headers['X-Csrf-Token'] ?? $headers['X-CSRF-TOKEN'] ?? $headers['X-CSRF-Token'] ?? '');
    if (!csrf_verify($token)) {
        json_response(['ok' => false, 'error' => 'Invalid CSRF token'], 419);
        exit;
    }
}

function auth_register_user(string $email, string $username, string $password): array
{
    $email = strtolower(trim($email));
    $username = trim($username);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Email tidak valid'];
    }
    if ($username === '' || strlen($username) < 3 || strlen($username) > 64) {
        return ['ok' => false, 'error' => 'Username minimal 3 karakter'];
    }
    if (strlen($password) < 8) {
        return ['ok' => false, 'error' => 'Password minimal 8 karakter'];
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($hash) || $hash === '') {
        return ['ok' => false, 'error' => 'Gagal memproses password'];
    }

    $now = now_mysql();

    try {
        $pdo = db();
        $stmt = $pdo->prepare('INSERT INTO users (email, username, password_hash, created_at, updated_at) VALUES (:email, :username, :hash, :created_at, :updated_at)');
        $stmt->execute([
            ':email' => $email,
            ':username' => $username,
            ':hash' => $hash,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        return ['ok' => true, 'user_id' => (int)$pdo->lastInsertId()];
    } catch (Throwable $e) {
        // Duplicate key
        if (stripos($e->getMessage(), 'uniq_email') !== false || stripos($e->getMessage(), 'Duplicate') !== false) {
            return ['ok' => false, 'error' => 'Email/Username sudah terdaftar'];
        }
        return ['ok' => false, 'error' => 'DB error'];
    }
}

function auth_login(string $emailOrUsername, string $password): array
{
    $login = trim($emailOrUsername);
    if ($login === '' || $password === '') {
        return ['ok' => false, 'error' => 'Email/Username dan password wajib diisi'];
    }

    try {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT id, email, username, password_hash FROM users WHERE email = :login OR username = :login LIMIT 1');
        $stmt->execute([':login' => strtolower($login)]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return ['ok' => false, 'error' => 'Login gagal'];
        }

        $hash = (string)($row['password_hash'] ?? '');
        if ($hash === '' || !password_verify($password, $hash)) {
            return ['ok' => false, 'error' => 'Login gagal'];
        }

        auth_session_start();
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$row['id'];

        $now = now_mysql();
        $upd = $pdo->prepare('UPDATE users SET last_login_at = :last_login_at, updated_at = :updated_at WHERE id = :id');
        $upd->execute([':last_login_at' => $now, ':updated_at' => $now, ':id' => (int)$row['id']]);

        return ['ok' => true, 'user_id' => (int)$row['id']];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'DB error'];
    }
}

function auth_logout(): void
{
    auth_session_start();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', (bool)($params['secure'] ?? false), (bool)($params['httponly'] ?? true));
    }

    session_destroy();
}
