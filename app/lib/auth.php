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

    $cookieDomain = '';
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    // Strip port
    if ($host !== '' && str_contains($host, ':')) {
        $host = explode(':', $host, 2)[0];
    }

    // If host is a domain name, share cookie across subdomains (helps avoid CSRF mismatch
    // when user accesses via different hostnames like www/non-www/subdomain).
    if ($host !== '' && filter_var($host, FILTER_VALIDATE_IP) === false && $host !== 'localhost') {
        $parts = array_values(array_filter(explode('.', $host), static fn($p) => $p !== ''));
        $n = count($parts);
        if ($n >= 2) {
            $last2 = $parts[$n - 2] . '.' . $parts[$n - 1];
            $last3 = $n >= 3 ? ($parts[$n - 3] . '.' . $last2) : $last2;

            // Basic heuristic for common multi-part TLDs
            if (preg_match('/\b(co\.id|ac\.id|sch\.id|go\.id)\b/i', $last2)) {
                $cookieDomain = '.' . $last3;
            } else {
                $cookieDomain = '.' . $last2;
            }
        }
    }

    // PHP 7.3+ supports samesite via array; older versions ignore unknown keys
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => $cookieDomain,
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

function auth_db_error_hint(Throwable $e): string
{
    $msg = $e->getMessage();
    $upper = strtoupper($msg);

    // Common MySQL errors we can give actionable steps for
    if (str_contains($upper, 'SQLSTATE[42S02]') || str_contains($upper, 'BASE TABLE') || str_contains($upper, 'DOESN\'T EXIST')) {
        if (preg_match('/\bUSERS\b/i', $msg)) {
            return 'Tabel users belum ada. Jalankan app/schema_update_2026_01_02.sql atau app/schema.sql di database.';
        }
        return 'Tabel database belum lengkap. Pastikan schema sudah di-import.';
    }

    if (str_contains($upper, 'ACCESS DENIED') || str_contains($upper, 'SQLSTATE[28000]')) {
        return 'Akses DB ditolak. Cek username/password di app/config/database.php.';
    }

    if (str_contains($upper, 'UNKNOWN DATABASE') || str_contains($upper, 'SQLSTATE[HY000]') && str_contains($upper, '1049')) {
        return 'Database tidak ditemukan. Cek dbname di app/config/database.php.';
    }

    if (str_contains($upper, 'CONNECTION') || str_contains($upper, 'SQLSTATE[HY000]') && (str_contains($upper, '2002') || str_contains($upper, '2006'))) {
        return 'Koneksi DB gagal. Cek host/port DB dan pastikan MySQL aktif.';
    }

    return 'DB error.';
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
        append_log('logs/auth.log', '[' . now_mysql() . '] REGISTER_DB_ERROR ' . $e->getMessage());
        // Duplicate key
        if (stripos($e->getMessage(), 'uniq_email') !== false || stripos($e->getMessage(), 'Duplicate') !== false) {
            return ['ok' => false, 'error' => 'Email/Username sudah terdaftar'];
        }
        return ['ok' => false, 'error' => auth_db_error_hint($e)];
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
        // PDO MySQL (native prepares) can error if the same named placeholder is reused.
        $stmt = $pdo->prepare('SELECT id, email, username, password_hash FROM users WHERE email = :email OR username = :username LIMIT 1');
        $stmt->execute([
            ':email' => strtolower($login),
            ':username' => $login,
        ]);
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
        append_log('logs/auth.log', '[' . now_mysql() . '] LOGIN_DB_ERROR ' . $e->getMessage());
        return ['ok' => false, 'error' => auth_db_error_hint($e)];
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
