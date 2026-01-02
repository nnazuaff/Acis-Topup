<?php

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login_page();

auth_session_start();
$csrf = csrf_token();

$games = require __DIR__ . '/app/config/games.php';
$gameKey = trim((string)($_GET['game'] ?? ''));
$game = null;
foreach ($games as $g) {
    if (($g['key'] ?? '') === $gameKey) {
        $game = $g;
        break;
    }
}

$targetHint = $game['target_hint'] ?? 'UserID / NoHP';
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Topup - Order</title>
  <style>
    body{font-family:system-ui,Segoe UI,Arial;max-width:720px;margin:32px auto;padding:0 16px}
    label{display:block;margin:12px 0 6px}
    input{width:100%;padding:10px;border:1px solid #ccc;border-radius:8px}
    button{margin-top:14px;padding:10px 14px;border:0;border-radius:8px;background:#111;color:#fff;cursor:pointer}
    pre{background:#f6f8fa;padding:12px;border-radius:8px;overflow:auto}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .top{display:flex;align-items:center;justify-content:space-between;gap:12px}
    .muted{color:#444}
    a{color:#111}
  </style>
</head>
<body>
  <div class="top">
    <div>
      <h1 style="margin:0">Order Topup</h1>
      <div class="muted"><?php echo $game ? htmlspecialchars($game['name'], ENT_QUOTES) : 'Pilih game dulu di halaman utama.'; ?></div>
    </div>
    <div>
      <a href="index.php">&larr; Kembali</a>
    </div>
  </div>

  <form id="orderForm" style="margin-top:18px">
    <input type="hidden" name="game" value="<?php echo htmlspecialchars($gameKey, ENT_QUOTES); ?>" />

    <div class="row">
      <div>
        <label>Product Code</label>
        <input name="product_code" placeholder="Contoh: H2HSRVP5" required />
      </div>
      <div>
        <label>Price (optional)</label>
        <input name="price" placeholder="15000" inputmode="numeric" />
      </div>
    </div>

    <label>Target</label>
    <input name="target" placeholder="<?php echo htmlspecialchars((string)$targetHint, ENT_QUOTES); ?>" required />

    <button type="submit">Buat Order</button>
  </form>

  <h3>Result</h3>
  <pre id="result">-</pre>

  <script>
    const csrf = <?php echo json_encode($csrf); ?>;
    const form = document.getElementById('orderForm');
    const result = document.getElementById('result');

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      result.textContent = 'Loading...';

      const fd = new FormData(form);
      const payload = {
        game: fd.get('game') || '',
        product_code: fd.get('product_code'),
        target: fd.get('target'),
        price: fd.get('price') ? Number(fd.get('price')) : 0,
      };

      const res = await fetch('app/api/order.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrf,
        },
        body: JSON.stringify(payload),
      });

      const json = await res.json().catch(() => ({}));
      result.textContent = JSON.stringify(json, null, 2);

      if (json && json.ok && json.trx_id) {
        const url = 'status.php?trx_id=' + encodeURIComponent(json.trx_id);
        result.textContent += '\n\nBuka status realtime: ' + location.origin + '/' + url;
      }
    });
  </script>
</body>
</html>
