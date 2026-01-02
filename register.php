<?php

require_once __DIR__ . '/app/bootstrap.php';

auth_session_start();

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

if (auth_current_user_id() !== null) {
  header('Location: ' . ($isSafeNext($next) ? $next : 'index.php'));
    exit;
}

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $params = read_body_params();
    $token = (string)($params['_csrf'] ?? '');
    if (!csrf_verify($token)) {
        $error = 'CSRF token tidak valid. Refresh halaman.';
    } else {
        $email = (string)($params['email'] ?? '');
        $username = (string)($params['username'] ?? '');
        $password = (string)($params['password'] ?? '');
        $password2 = (string)($params['password2'] ?? '');

        if ($password !== $password2) {
            $error = 'Konfirmasi password tidak sama.';
        } else {
            $res = auth_register_user($email, $username, $password);
            if (!($res['ok'] ?? false)) {
                $error = (string)($res['error'] ?? 'Daftar gagal');
            } else {
                // Auto-login
                $_SESSION['user_id'] = (int)($res['user_id'] ?? 0);
                session_regenerate_id(true);
              header('Location: ' . ($isSafeNext($next) ? $next : 'index.php'));
                exit;
            }
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
  <title>Daftar</title>
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
  <h1>Daftar Akun</h1>

  <div class="box">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>" />
      <input type="hidden" name="next" value="<?php echo htmlspecialchars($next, ENT_QUOTES); ?>" />

      <label>Email</label>
      <input name="email" type="email" autocomplete="email" required />

      <label>Username</label>
      <input name="username" autocomplete="username" required />

      <label>Password</label>
      <input type="password" name="password" autocomplete="new-password" required />

      <label>Konfirmasi Password</label>
      <input type="password" name="password2" autocomplete="new-password" required />

      <button type="submit">Daftar</button>

      <?php if ($error !== ''): ?>
        <div class="err"><?php echo htmlspecialchars($error, ENT_QUOTES); ?></div>
      <?php endif; ?>

      <p style="margin-top:12px">Sudah punya akun? <a href="login.php">Login</a></p>
    </form>
  </div>
</body>
</html>
