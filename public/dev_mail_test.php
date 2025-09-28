<?php
// /public/dev_mail_test.php
declare(strict_types=1);

$root = dirname(__DIR__);
$mailerPath = $root . '/app/mailer.php';

$loaded = false; $loadMsg = '';
if (is_file($mailerPath)) {
  require_once $mailerPath;
  $loaded = function_exists('send_mail');
  if (!$loaded) $loadMsg = 'mailer.php loaded but send_mail() not found.';
} else {
  $loadMsg = "Missing file: $mailerPath";
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$err=''; $ok='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!$loaded) {
    $err = 'Mailer not loaded: '.$loadMsg;
  } else {
    $to = trim((string)($_POST['to'] ?? ''));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
      $err = 'Invalid email address.';
    } else {
      [$good,$e] = send_mail($to, 'ISP Billing Mail Test', '<p><b>Test</b> email.</p>', 'Test email');
      if ($good) $ok='Sent OK. Check Inbox/Spam.';
      else $err = 'Send failed: '.$e;
    }
  }
}
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><title>Mail Diagnostic</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css">
</head><body class="p-4">
<div class="container" style="max-width:760px">
  <h1 class="h4 mb-3">Mail Diagnostic</h1>

  <div class="card mb-3">
    <div class="card-header">Loader status</div>
    <div class="card-body">
      <div>Project root: <code><?=h($root)?></code></div>
      <div>Mailer path: <code><?=h($mailerPath)?></code></div>
      <div>Status: <span class="badge bg-<?= $loaded?'success':'danger' ?>"><?= $loaded?'Loaded (send_mail OK)':'Not loaded' ?></span></div>
      <?php if(!$loaded): ?><div class="text-danger mt-2"><?= h($loadMsg) ?></div><?php endif; ?>
    </div>
  </div>

  <?php if ($ok): ?><div class="alert alert-success"><?=h($ok)?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger"><?=h($err)?></div><?php endif; ?>

  <form method="post" class="card">
    <div class="card-header">Send a test mail</div>
    <div class="card-body">
      <div class="mb-3">
        <label class="form-label">Recipient</label>
        <input type="email" name="to" class="form-control" required placeholder="you@example.com">
      </div>
      <button class="btn btn-primary" type="submit">Send test</button>
      <a class="btn btn-outline-secondary" href="/public/register.php">Back</a>
    </div>
  </form>
</div>
</body></html>
