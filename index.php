<?php
// Halaman order topup (testing)
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Topup - Order (Testing)</title>
  <style>
    body{font-family:system-ui,Segoe UI,Arial;max-width:720px;margin:32px auto;padding:0 16px}
    label{display:block;margin:12px 0 6px}
    input{width:100%;padding:10px;border:1px solid #ccc;border-radius:8px}
    button{margin-top:14px;padding:10px 14px;border:0;border-radius:8px;background:#111;color:#fff;cursor:pointer}
    pre{background:#f6f8fa;padding:12px;border-radius:8px;overflow:auto}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  </style>
</head>
<body>
  <h1>Order Topup (Testing)</h1>

  <form id="orderForm">
    <div class="row">
      <div>
        <label>Product Code</label>
        <input name="product_code" placeholder="ML5" required />
      </div>
      <div>
        <label>Price (optional)</label>
        <input name="price" placeholder="15000" inputmode="numeric" />
      </div>
    </div>
    <label>Target (UserID / NoHP)</label>
    <input name="target" placeholder="123456789" required />

    <button type="submit">Buat Order</button>
  </form>

  <h3>Result</h3>
  <pre id="result">-</pre>

  <script>
    const form = document.getElementById('orderForm');
    const result = document.getElementById('result');

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      result.textContent = 'Loading...';

      const fd = new FormData(form);
      const payload = {
        product_code: fd.get('product_code'),
        target: fd.get('target'),
        price: fd.get('price') ? Number(fd.get('price')) : 0,
      };

      const res = await fetch('app/api/order.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
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
