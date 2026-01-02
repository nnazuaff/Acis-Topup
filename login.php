<?php

require_once __DIR__ . '/app/bootstrap.php';

auth_session_start();

// Prevent proxy/browser caching (cached HTML can cause stale CSRF tokens)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$next = trim((string)($_GET['next'] ?? ''));
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $params0 = read_body_params();
  $next = trim((string)($params0['next'] ?? $next));
}

$isSafeNext = static function (string $u): bool {
  if ($u === '') {
    return false;
  }
  if (str_contains($u, "\r") || str_contains($u, "\n")) {
    return false;
  }
  if (preg_match('#^https?://#i', $u) || str_starts_with($u, '//')) {
    return false;
  }
  return true;
};

// If already logged in
if (auth_current_user_id() !== null) {
  header('Location: ' . ($isSafeNext($next) ? $next : 'index.php'));
    exit;
}

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $params = read_body_params();
    $token = (string)($params['_csrf'] ?? '');
    if (!csrf_verify($token)) {
    append_log('logs/auth.log', '[' . now_mysql() . '] LOGIN_CSRF_FAIL host=' . (string)($_SERVER['HTTP_HOST'] ?? '-') . ' ip=' . (string)($_SERVER['REMOTE_ADDR'] ?? '-') . ' sid=' . session_id() . ' has_cookie=' . (isset($_COOKIE[session_name()]) ? '1' : '0'));
        $error = 'CSRF token tidak valid. Refresh halaman.';
    } else {
        $login = (string)($params['login'] ?? '');
        $password = (string)($params['password'] ?? '');
        $res = auth_login($login, $password);
        if (!($res['ok'] ?? false)) {
            $error = (string)($res['error'] ?? 'Login gagal');
        } else {
          header('Location: ' . ($isSafeNext($next) ? $next : 'index.php'));
            exit;
        }
    }
}

$csrf = csrf_token();
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Login</title>
  <style>
    body{font-family:system-ui,Segoe UI,Arial;max-width:520px;margin:32px auto;padding:0 16px}
    label{display:block;margin:12px 0 6px}
    input{width:100%;padding:10px;border:1px solid #ccc;border-radius:8px}
    button{margin-top:14px;padding:10px 14px;border:0;border-radius:8px;background:#111;color:#fff;cursor:pointer;width:100%}
    .box{padding:14px;border:1px solid #ddd;border-radius:10px}
    .err{margin-top:12px;color:#b00020}
    a{color:#111}
  </style>
</head>
<body>
  <h1>Login</h1>

  <div class="box">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>" />
      <input type="hidden" name="next" value="<?php echo htmlspecialchars($next, ENT_QUOTES); ?>" />

      <label>Email / Username</label>
      <input name="login" autocomplete="username" required />

      <label>Password</label>
      <input type="password" name="password" autocomplete="current-password" required />

      <button type="submit">Masuk</button>

      <?php if ($error !== ''): ?>
        <div class="err"><?php echo htmlspecialchars($error, ENT_QUOTES); ?></div>
      <?php endif; ?>

      <p style="margin-top:12px">Belum punya akun? <a href="register.php">Daftar</a></p>
    </form>
  </div>
</body>
</html>
