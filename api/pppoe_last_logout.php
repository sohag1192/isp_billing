<?php
// /api/ppp_secret_last_logout.php
// Read PPP secret last-logged-out from MikroTik and return JSON
// UI: JSON; Comments: বাংলা

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$ROOT = dirname(__DIR__);
require_once $ROOT . '/app/require_login.php';
require_once $ROOT . '/app/db.php';

// বাংলা: RouterOS API ক্লাস (প্রজেক্টে যেটা আছেই)
$apiClass = $ROOT . '/app/routeros_api.class.php';
if (!is_readable($apiClass)) {
  echo json_encode(['ok'=>false, 'error'=>'RouterOS API class missing']); exit;
}
require_once $apiClass;

/* ---------- CSRF (optional but consistent) ---------- */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($hdr) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $hdr)) {
  http_response_code(419);
  echo json_encode(['ok'=>false, 'error'=>'CSRF failed']); exit;
}

/* ---------- Inputs ---------- */
// বাংলা: client_id অথবা সরাসরি pppoe_id যে কোন একটাই লাগবে
$client_id = (int)($_GET['client_id'] ?? $_POST['client_id'] ?? 0);
$username  = trim((string)($_GET['pppoe'] ?? $_POST['pppoe'] ?? ''));

try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // বাংলা: রাউটার/ক্লায়েন্ট তথ্য আনতে হবে যদি client_id দেয়া থাকে
  if ($client_id > 0) {
    $st = $pdo->prepare("SELECT c.*, r.*
                         FROM clients c
                         LEFT JOIN routers r ON c.router_id = r.id
                         WHERE c.id = ?");
    $st->execute([$client_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo json_encode(['ok'=>false,'error'=>'Client not found']); exit; }

    // pppoe username schema-aware pick
    $candUser = ['pppoe_id','pppoe_user','pppoe_username','pppoe','username','user'];
    foreach ($candUser as $k) { if (!empty($row[$k])) { $username = (string)$row[$k]; break; } }
    if ($username === '') { echo json_encode(['ok'=>false,'error'=>'PPP username not set']); exit; }

    // router host/port/user/pass schema-aware pick
    $host = $row['ip'] ?? ($row['host'] ?? ($row['router_ip'] ?? ''));
    if (!$host) { echo json_encode(['ok'=>false,'error'=>'Router IP/host not set']); exit; }

    // api port
    $port = 8728;
    foreach (['api_port','port','api'] as $k) {
      if (!empty($row[$k]) && (int)$row[$k] > 0) { $port = (int)$row[$k]; break; }
    }

    // api user
    $apiUser = '';
    foreach (['api_user','api_username','username','user','login_user'] as $k) {
      if (!empty($row[$k])) { $apiUser = (string)$row[$k]; break; }
    }

    // api pass
    $apiPass = '';
    foreach (['api_pass','api_password','password','pass','login_pass'] as $k) {
      if (!empty($row[$k])) { $apiPass = (string)$row[$k]; break; }
    }

  } else {
    // বাংলা: সরাসরি username দেয়া হলে, রাউটার resolve করতে হবে (প্রয়োজনে ডিফল্ট রাউটার)
    // এখানে সহজ fallback: প্রথম active/default রাউটার নিন
    if ($username === '') { echo json_encode(['ok'=>false,'error'=>'pppoe is required']); exit; }

    // রাউটার পিক করার চেষ্টা (active=1 থাকলে)
    $r = $pdo->query("SELECT * FROM routers ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$r) { echo json_encode(['ok'=>false,'error'=>'No router found']); exit; }

    $host = $r['ip'] ?? ($r['host'] ?? ($r['router_ip'] ?? ''));
    if (!$host) { echo json_encode(['ok'=>false,'error'=>'Router IP/host not set']); exit; }

    $port = 8728;
    foreach (['api_port','port','api'] as $k) {
      if (!empty($r[$k]) && (int)$r[$k] > 0) { $port = (int)$r[$k]; break; }
    }

    $apiUser = '';
    foreach (['api_user','api_username','username','user','login_user'] as $k) {
      if (!empty($r[$k])) { $apiUser = (string)$r[$k]; break; }
    }
    $apiPass = '';
    foreach (['api_pass','api_password','password','pass','login_pass'] as $k) {
      if (!empty($r[$k])) { $apiPass = (string)$r[$k]; break; }
    }
  }

  if ($apiUser === '' || $apiPass === '') {
    echo json_encode(['ok'=>false,'error'=>'Router API credentials not set']); exit;
  }

  // ---------- Connect to RouterOS ----------
  $API = new RouterosAPI();
  $API->debug = false;

  if (!$API->connect($host, $apiUser, $apiPass, $port)) {
    echo json_encode(['ok'=>false,'error'=>'Router connect failed']); exit;
  }

  // ---------- Query PPP Secret ----------
  // বাংলা: name=<pppoe username> দিয়ে খুঁজে last-logged-out ফিল্ড পড়ি
  $API->write('/ppp/secret/print', false);
  $API->write('=?name=' . $username, true);
  $resp = $API->read();

  $API->disconnect();

  if (!is_array($resp) || count($resp) === 0) {
    echo json_encode(['ok'=>false,'error'=>'PPP secret not found']); exit;
  }

  // RouterOS array item খুঁজে ফিল্ড বের করা
  $item = $resp[0];
  $lastLogout = '';
  foreach (['last-logged-out','last-logout','last-out'] as $k) {
    if (isset($item[$k]) && $item[$k] !== '') { $lastLogout = (string)$item[$k]; break; }
  }

  // কিছু রাউটারে last-logged-in ও থাকতে পারে; চাইলে পাঠিয়ে দিচ্ছি
  $lastLogin = '';
  foreach (['last-logged-in','last-login','last-in'] as $k) {
    if (isset($item[$k]) && $item[$k] !== '') { $lastLogin = (string)$item[$k]; break; }
  }

  echo json_encode([
    'ok' => true,
    'pppoe' => $username,
    'last_logged_out' => $lastLogout ?: null,
    'last_logged_in'  => $lastLogin  ?: null,
  ]);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>'Server error', 'detail'=>$e->getMessage()]);
  exit;
}
