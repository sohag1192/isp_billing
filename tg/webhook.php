<?php
// /tg/webhook.php — Telegram webhook endpoint (bind subscriber via /start <token>)
// UI: English; Comments: বাংলা

declare(strict_types=1);
$ROOT = __DIR__ . '/..';
require_once $ROOT.'/app/db.php';
require_once __DIR__.'/telegram.php';

// Optional secret check (?secret=XYZ must match settings.wh_secret)
$cfg = tg_cfg();
$need = trim((string)($cfg['wh_secret'] ?? ''));
if ($need !== '') {
  $got = trim((string)($_GET['secret'] ?? ''));
  if (!hash_equals($need, $got)) { http_response_code(403); exit('forbidden'); }
}

$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);

// Parse incoming update
$raw = file_get_contents('php://input');
$upd = json_decode($raw, true);
if (!$upd) { echo 'ok'; exit; }

$chat_id = $upd['message']['chat']['id'] ?? ($upd['callback_query']['message']['chat']['id'] ?? null);
$text    = trim((string)($upd['message']['text'] ?? $upd['callback_query']['data'] ?? ''));
$from    = $upd['message']['from'] ?? $upd['callback_query']['from'] ?? [];

$username   = $from['username'] ?? null;
$first_name = $from['first_name'] ?? null;
$last_name  = $from['last_name'] ?? null;

if (!$chat_id) { echo 'ok'; exit; }

// /start <token> → link to client
if (str_starts_with($text, '/start')) {
  $parts = explode(' ', $text, 2);
  $token = isset($parts[1]) ? trim($parts[1]) : '';
  if ($token !== '') {
    $cid = tg_link_consume($pdo, $token);
    if ($cid) {
      tg_subscriber_upsert($pdo, (int)$cid, (int)$chat_id, [
        'username'=>$username, 'first_name'=>$first_name, 'last_name'=>$last_name
      ]);
      tg_send_text($chat_id, "✅ Subscription linked.\nClient ID: {$cid}");
    } else {
      tg_send_text($chat_id, "⚠️ Link expired or invalid.");
    }
  } else {
    tg_send_text($chat_id, "Hi! Send your link or talk to support.");
  }
  echo 'ok'; exit;
}

// Simple /ping
if ($text === '/ping') {
  tg_send_text($chat_id, "pong ✅");
  echo 'ok'; exit;
}

echo 'ok';
