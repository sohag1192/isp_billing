<?php
// /public/bkash_sms_tester.php
// UI: English; Comments: বাংলা
// Note: Absolute endpoint URL ব্যবহার করে "No host part" এরর এড়ানো হয়েছে।

declare(strict_types=1);

$defaultToken = getenv('BKASH_SMS_TOKEN') ?: 'CHANGE_ME_NOW_32CHARS';

/* Build default absolute URL */
$scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
/*
  বাংলা: আপনার webhook যদি /public/api/bkash_sms_webhook.php এ থাকে,
  তাহলে default endpoint হবে: http(s)://HOST/api/bkash_sms_webhook.php?token=...
  (যদি আপনার URL-এ /public দেখা লাগে, ফর্মে আপনি নিজেই endpoint override করতে পারবেন।)
*/
$defaultEndpoint = $scheme . '://' . $host . '/api/bkash_sms_webhook.php?token=' . urlencode($defaultToken);

$response = ''; 
$error    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $endpoint = trim($_POST['endpoint'] ?? $defaultEndpoint);
  $message  = $_POST['message']   ?? '';
  $from     = $_POST['from']      ?? 'bKash';
  $to       = $_POST['to']        ?? '';
  $ts       = $_POST['timestamp'] ?? date('Y-m-d H:i:s');

  // Absolute URL guard
  $hasScheme = (bool)parse_url($endpoint, PHP_URL_SCHEME);
  $hasHost   = (bool)parse_url($endpoint, PHP_URL_HOST);
  if (!$hasScheme || !$hasHost) {
    $error = 'Endpoint must be an absolute URL like http://localhost/api/bkash_sms_webhook.php?token=YOUR_SECRET';
  } else {
    $payload = ['message'=>$message,'from'=>$from,'to'=>$to,'timestamp'=>$ts];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST => true,
      CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
      CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
      CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($cerr) $error = $cerr;
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>bKash SMS Tester</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-3">
  <div class="container">
    <h5 class="mb-3">bKash SMS Tester</h5>

    <form method="post" class="row g-2">
      <div class="col-12">
        <label class="form-label">Endpoint URL (absolute)</label>
        <input name="endpoint" class="form-control"
               value="<?= htmlspecialchars($_POST['endpoint'] ?? $defaultEndpoint, ENT_QUOTES, 'UTF-8') ?>">
        <div class="form-text">
          Example (local): <code>http://localhost/api/bkash_sms_webhook.php?token=YOUR_SECRET</code><br>
          যদি আপনার পাবলিক URL-এ <code>/public</code> লাগে, দিন:
          <code>http://localhost/public/api/bkash_sms_webhook.php?token=YOUR_SECRET</code>
        </div>
      </div>

      <div class="col-12">
        <label class="form-label">Message</label>
        <textarea name="message" class="form-control" rows="3"><?= htmlspecialchars($_POST['message'] ?? ('You have received Tk 1200.00 from 01712345678. TrxID TSTABC3 on '.date('Y-m-d').'. Ref INV202509-001'), ENT_QUOTES, 'UTF-8') ?></textarea>
      </div>
      <div class="col-md-4">
        <label class="form-label">From</label>
        <input name="from" class="form-control" value="<?= htmlspecialchars($_POST['from'] ?? 'bKash', ENT_QUOTES, 'UTF-8') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">To (Your bKash SIM)</label>
        <input name="to" class="form-control" value="<?= htmlspecialchars($_POST['to'] ?? '01XXXXXXXXX', ENT_QUOTES, 'UTF-8') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Timestamp</label>
        <input name="timestamp" class="form-control" value="<?= htmlspecialchars($_POST['timestamp'] ?? date('Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8') ?>">
      </div>

      <div class="col-12 mt-2">
        <button class="btn btn-primary">Send Test</button>
      </div>
    </form>

    <div class="mt-3">
      <h6>Response</h6>
      <pre class="border p-2 bg-light"><?= htmlspecialchars($error ?: $response ?: '', ENT_QUOTES, 'UTF-8') ?></pre>
    </div>
  </div>
</body>
</html>
