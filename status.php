<?php

require_once __DIR__ . '/app/bootstrap.php';

$trxId = trim((string)($_GET['trx_id'] ?? ''));
$row = null;
if ($trxId !== '') {
    try {
        $stmt = db()->prepare('SELECT trx_id, status, message, updated_at, created_at FROM transactions WHERE trx_id = :trx_id LIMIT 1');
        $stmt->execute([':trx_id' => $trxId]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        $row = null;
    }
}

$pusher = require __DIR__ . '/app/config/pusher.php';
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Status Transaksi</title>
  <style>
    body{font-family:system-ui,Segoe UI,Arial;max-width:720px;margin:32px auto;padding:0 16px}
    .box{padding:14px;border:1px solid #ddd;border-radius:10px}
    code{background:#f6f8fa;padding:2px 6px;border-radius:6px}
    pre{background:#f6f8fa;padding:12px;border-radius:8px;overflow:auto}
  </style>
</head>
<body>
  <h1>Status Transaksi (Realtime)</h1>

  <div class="box">
    <div>TRX ID: <code id="trxId"><?php echo htmlspecialchars($trxId ?: '-', ENT_QUOTES); ?></code></div>
    <div>Status: <strong id="status"><?php echo htmlspecialchars($row['status'] ?? 'UNKNOWN', ENT_QUOTES); ?></strong></div>
    <div>Message: <span id="message"><?php echo htmlspecialchars($row['message'] ?? '-', ENT_QUOTES); ?></span></div>
    <div>Updated: <span id="updated"><?php echo htmlspecialchars($row['updated_at'] ?? '-', ENT_QUOTES); ?></span></div>
  </div>

  <h3>Events</h3>
  <pre id="events">Listening...
<?php
if ($trxId === '') {
    echo "\nTambahkan query: ?trx_id=TRX...";
}
?>
  </pre>

  <script src="https://js.pusher.com/8.2.0/pusher.min.js"></script>
  <script>
    const trxId = <?php echo json_encode($trxId); ?>;
    const pusherKey = <?php echo json_encode($pusher['key']); ?>;
    const cluster = <?php echo json_encode($pusher['cluster']); ?>;

    const events = document.getElementById('events');
    const elStatus = document.getElementById('status');
    const elMessage = document.getElementById('message');
    const elUpdated = document.getElementById('updated');

    if (!trxId) {
      events.textContent += "\nNo trx_id.";
    } else {
      const p = new Pusher(pusherKey, { cluster });
      const channel = p.subscribe('transaction-' + trxId);
      channel.bind('status-update', function (data) {
        events.textContent += "\n" + JSON.stringify(data);
        if (data && data.status) elStatus.textContent = data.status;
        if (data && data.message !== undefined) elMessage.textContent = data.message;
        if (data && data.at) elUpdated.textContent = data.at;
      });
    }
  </script>
</body>
</html>
