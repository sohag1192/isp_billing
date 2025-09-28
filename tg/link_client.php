<?php
// /tg/link_client.php — make a subscribe link for a client
// UI: English; Comments: বাংলা

declare(strict_types=1);
$ROOT = __DIR__ . '/..';
require_once $ROOT.'/app/require_login.php';
require_once $ROOT.'/app/db.php';
require_once __DIR__.'/telegram.php';
$acl_file = $ROOT.'/app/acl.php'; if (is_file($acl_file)) require_once $acl_file;
if (function_exists('require_perm')) { require_perm('notify.manage'); }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Ensure link_tokens table (if tools_migrate না চালানো থাকে)
if (!tg_tbl_exists($pdo,'telegram_link_tokens')) {
  $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_link_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(64) NOT NULL UNIQUE,
    client_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    used_at DATETIME NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

$client_id = (int)($_GET['client_id'] ?? 0);
$link = '';
$err  = '';
$cfg  = tg_cfg();
$bot  = $cfg['bot_user'] ?: 'YourBot';

if ($client_id > 0) {
  $token = tg_link_create($pdo, $client_id);
  if ($token) {
    $link = "https://t.me/{$bot}?start={$token}";
  } else {
    $err = 'Failed to create token (check DB permission).';
  }
}

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Telegram Subscribe Link</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
  <style>
    body{background:#f7f8fb}
    .card{border-radius:12px; box-shadow:0 8px 30px rgba(0,0,0,.08)}
  </style>
</head>
<body class="p-3 p-md-4">
<div class="container" style="max-width:720px">
  <h4 class="mb-3">Telegram Subscribe Link</h4>

  <form class="row g-2 mb-3" method="get" action="/tg/link_client.php">
    <div class="col-md-4">
      <label class="form-label">Client ID</label>
      <input type="number" name="client_id" class="form-control" value="<?= $client_id ?: '' ?>" required>
    </div>
    <div class="col-md-3 d-flex align-items-end">
      <button class="btn btn-primary"><i class="bi bi-link-45deg"></i> Generate</button>
    </div>
  </form>

  <?php if ($err): ?>
    <div class="alert alert-danger"><?= h($err) ?></div>
  <?php endif; ?>

  <?php if ($link): ?>
    <div class="card">
      <div class="card-body">
        <div class="mb-2">Send this link to the customer. Ask them to open and press <strong>Start</strong> in your bot.</div>
        <input type="text" class="form-control" value="<?= h($link) ?>" readonly onclick="this.select()">
        <div class="form-text mt-1">Bot: @<?= h($bot) ?></div>
      </div>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
