<?php

require_once __DIR__ . '/app/bootstrap.php';

auth_session_start();
$csrf = csrf_token();
$userId = auth_current_user_id();
$isLoggedIn = $userId !== null;

$operatorId = trim((string)($_GET['op'] ?? ''));
$operatorIdUpper = strtoupper($operatorId);

$balance = null;
if ($isLoggedIn) {
  try {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT balance FROM users WHERE id = :id');
    $stmt->execute([':id' => (int)$userId]);
    $row = $stmt->fetch();
    if (is_array($row)) {
      $balance = (int)($row['balance'] ?? 0);
    }
  } catch (Throwable $e) {
    $balance = null;
  }
}

$next = 'order.php?op=' . urlencode($operatorId);
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
    select{width:100%;padding:10px;border:1px solid #ccc;border-radius:8px}
  </style>
</head>
<body>
  <div class="top">
    <div>
      <h1 style="margin:0">Order Topup</h1>
      <div class="muted">
        <?php if ($operatorIdUpper !== ''): ?>
          Operator: <strong><?php echo htmlspecialchars($operatorIdUpper, ENT_QUOTES); ?></strong>
          <?php if ($isLoggedIn && $balance !== null): ?>
            &middot; Saldo: <strong>Rp <?php echo number_format($balance, 0, ',', '.'); ?></strong>
          <?php endif; ?>
        <?php else: ?>
          Pilih game dulu di halaman utama.
        <?php endif; ?>
      </div>
    </div>
    <div>
      <a href="index.php">&larr; Kembali</a>
    </div>
  </div>

  <?php if ($operatorIdUpper === ''): ?>
    <div style="margin-top:18px" class="box">
      <div style="font-weight:600">Operator belum dipilih</div>
      <div class="muted" style="margin-top:6px">Kembali ke halaman utama dan pilih game.</div>
    </div>
  <?php else: ?>
    <form id="orderForm" style="margin-top:18px">
      <input type="hidden" name="operator_id" value="<?php echo htmlspecialchars($operatorIdUpper, ENT_QUOTES); ?>" />

      <label>Target (Game ID)</label>
      <input name="target" placeholder="Contoh: 12345678|1234" required />

      <label>Pilih Produk</label>
      <select name="product_code" id="productSelect" required>
        <option value="">Loading...</option>
      </select>

      <button type="submit">Buat Order</button>
      <?php if (!$isLoggedIn): ?>
        <div class="muted" style="margin-top:8px">Untuk bayar, kamu akan diminta login.</div>
      <?php endif; ?>
    </form>
  <?php endif; ?>

  <h3>Result</h3>
  <pre id="result">-</pre>

  <script>
    const csrf = <?php echo json_encode($csrf); ?>;
    const isLoggedIn = <?php echo json_encode($isLoggedIn); ?>;
    const nextUrl = <?php echo json_encode($next); ?>;
    const operatorId = <?php echo json_encode($operatorIdUpper); ?>;
    const form = document.getElementById('orderForm');
    const result = document.getElementById('result');
    const productSelect = document.getElementById('productSelect');

    async function loadProducts() {
      if (!operatorId || !productSelect) return;
      try {
        const res = await fetch('app/api/products_voucher_game.php?operator_id=' + encodeURIComponent(operatorId), { method: 'GET' });
        const json = await res.json();
        if (!json || !json.ok) throw new Error((json && json.error) ? json.error : 'Gagal ambil produk');

        const row = Array.isArray(json.rows) ? json.rows.find(r => String(r.operator_id || '').toUpperCase() === operatorId) : null;
        const items = row && Array.isArray(row.items) ? row.items : [];
        productSelect.innerHTML = '<option value="">-- pilih --</option>';

        for (const it of items) {
          if (String(it.status || '').toUpperCase() !== 'ACTIVE') continue;
          const code = String(it.code || '');
          const name = String(it.name || code);
          const price = Number(it.price || 0);
          if (!code) continue;
          const opt = document.createElement('option');
          opt.value = code;
          opt.textContent = `${name} (Rp ${price.toLocaleString('id-ID')})`;
          productSelect.appendChild(opt);
        }
      } catch (e) {
        productSelect.innerHTML = '<option value="">Gagal load produk</option>';
        result.textContent = 'Gagal load produk: ' + String(e.message || e);
      }
    }

    loadProducts();

    if (form) form.addEventListener('submit', async (e) => {
      e.preventDefault();
      result.textContent = 'Loading...';

      if (!isLoggedIn) {
        location.href = 'login.php?next=' + encodeURIComponent(nextUrl);
        return;
      }

      const fd = new FormData(form);
      const payload = {
        operator_id: fd.get('operator_id') || '',
        product_code: fd.get('product_code') || '',
        target: fd.get('target'),
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
