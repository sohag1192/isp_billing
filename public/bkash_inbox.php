<?php
// /public/bkash_inbox.php
// UI: English; Comments: বাংলা

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$rows = $pdo->query("SELECT * FROM bkash_inbox WHERE status IN ('parsed','matched') ORDER BY id DESC LIMIT 200")
            ->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>bKash Inbox</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="bg-light">
<div class="container py-4">
  <h4 class="mb-3">bKash Inbox (Pending/Unmatched)</h4>
  <div class="table-responsive bg-white shadow-sm rounded">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>ID</th><th>Time</th><th>MSISDN</th><th>Amount</th><th>TrxID</th><th>Status</th><th>Text</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <td><?= htmlspecialchars($r['sms_time'] ?? '', ENT_QUOTES) ?></td>
          <td><?= htmlspecialchars($r['sender_msisdn'] ?? '', ENT_QUOTES) ?></td>
          <td><?= number_format((float)($r['amount'] ?? 0), 2) ?></td>
          <td><code><?= htmlspecialchars($r['trxid'] ?? '', ENT_QUOTES) ?></code></td>
          <td><span class="badge bg-secondary"><?= htmlspecialchars($r['status'] ?? '', ENT_QUOTES) ?></span></td>
          <td style="max-width:480px"><?= htmlspecialchars($r['raw_text'] ?? '', ENT_QUOTES) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="text-muted mt-3">Tip: কোনো ক্লায়েন্ট মিসিং হলে <code>clients.bkash_msisdn</code> আপডেট দিন।</p>
</div>
</body></html>
