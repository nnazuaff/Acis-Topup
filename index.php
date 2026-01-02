<?php

require_once __DIR__ . '/app/bootstrap.php';

auth_session_start();

$csrf = csrf_token();
$isLoggedIn = auth_current_user_id() !== null;
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
    <?php if ($isLoggedIn): ?>
      <form method="post" action="logout.php" style="margin:0">
        <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>" />
        <button type="submit">Logout</button>
      </form>
    <?php else: ?>
      <a href="login.php?next=<?php echo urlencode('index.php'); ?>" style="text-decoration:none">
        <button type="button">Login</button>
      </a>
    <?php endif; ?>
  </div>

  <div id="games" class="grid" style="margin-top:18px">
    <div class="box" style="grid-column:1/-1">
      <div style="font-weight:600">Loading...</div>
      <div class="muted" style="margin-top:6px">Mengambil daftar Voucher Game.</div>
    </div>
  </div>

  <script>
    (async () => {
      const wrap = document.getElementById('games');
      try {
        const res = await fetch('app/api/games_voucher_game.php', { method: 'GET' });
        const json = await res.json();

        if (!json || !json.ok || !Array.isArray(json.games)) {
          throw new Error((json && json.error) ? json.error : 'Gagal mengambil data game');
        }

        wrap.innerHTML = '';
        for (const g of json.games) {
          const op = String(g.operator_id || '');
          const name = String(g.name || op || '-');
          if (!op) continue;

          const a = document.createElement('a');
          a.className = 'card';
          a.href = 'order.php?op=' + encodeURIComponent(op);
          a.innerHTML = `
            <div class="box">
              <div style="font-weight:600">${name}</div>
              <div class="muted" style="margin-top:6px">${op}</div>
            </div>
          `;
          wrap.appendChild(a);
        }

        if (!wrap.children.length) {
          wrap.innerHTML = '<div class="box" style="grid-column:1/-1">Tidak ada game.</div>';
        }
      } catch (e) {
        wrap.innerHTML = `<div class="box" style="grid-column:1/-1"><div style="font-weight:600">Gagal</div><div class="muted" style="margin-top:6px">${String(e.message || e)}</div></div>`;
      }
    })();
  </script>
</body>
</html>
