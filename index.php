<?php

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login_page();
auth_session_start();

$csrf = csrf_token();
$games = require __DIR__ . '/app/config/games.php';
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Topup Games</title>
  <style>
    body{font-family:system-ui,Segoe UI,Arial;max-width:720px;margin:32px auto;padding:0 16px}
    .top{display:flex;align-items:center;justify-content:space-between;gap:12px}
    .box{padding:14px;border:1px solid #ddd;border-radius:10px}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    a.card{display:block;text-decoration:none;color:#111}
    a.card .box:hover{background:#f6f8fa}
    button{padding:10px 14px;border:0;border-radius:8px;background:#111;color:#fff;cursor:pointer}
    .muted{color:#444}
  </style>
</head>
<body>
  <div class="top">
    <div>
      <h1 style="margin:0">Topup Games</h1>
      <div class="muted">Pilih game untuk mulai topup.</div>
    </div>
    <form method="post" action="logout.php" style="margin:0">
      <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>" />
      <button type="submit">Logout</button>
    </form>
  </div>

  <div class="grid" style="margin-top:18px">
    <?php foreach ($games as $g): ?>
      <a class="card" href="order.php?game=<?php echo urlencode((string)($g['key'] ?? '')); ?>">
        <div class="box">
          <div style="font-weight:600"><?php echo htmlspecialchars((string)($g['name'] ?? '-'), ENT_QUOTES); ?></div>
          <div class="muted" style="margin-top:6px">Klik untuk order</div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
</body>
</html>
