<?php
// /api/sms_bkash.php
// UI: English; Comments: বাংলা
// Purpose: Receive bKash personal "Send Money" SMS via webhook, parse & insert into payments.
// Security: Shared token header + optional IP allowlist
// Idempotency: guard by trxID when present; else hash(sender+amount+time+msg)

declare(strict_types=1);

// ---------- bootstrap ----------
$ROOT = dirname(__DIR__, 1);
require_once $ROOT . '/app/db.php';

// ---------- config helpers ----------
function cfg(string $k, $def=null){
  if (defined($k)) return constant($k);
  if (isset($GLOBALS['CONFIG'][$k])) return $GLOBALS['CONFIG'][$k];
  $v = getenv($k);
  return ($v!==false && $v!=='') ? $v : $def;
}

// ---------- logging ----------
function log_sms(string $line): void {
  $base = dirname(__DIR__,1) . '/storage/logs/sms';
  $ym   = date('Y-m');
  $dir  = $base . '/' . $ym;
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $file = $dir . '/sms_' . date('Ymd') . '.log';
  $ip   = $_SERVER['REMOTE_ADDR'] ?? '-';
  $ts   = date('Y-m-d H:i:s');
  @file_put_contents($file, "[$ts][$ip] $line\n", FILE_APPEND);
}
function http_json(int $code, array $body){ http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode($body, JSON_UNESCAPED_SLASHES); exit; }

// ---------- security gates ----------
$SECRET = (string)cfg('SMS_WEBHOOK_SECRET', '');
if ($SECRET === '') {
  log_sms('CONFIG_MISSING: SMS_WEBHOOK_SECRET');
  http_json(500, ['ok'=>false,'error'=>'Server not configured']);
}
// optional IP whitelist
$ALLOW = trim((string)cfg('SMS_IP_WHITELIST',''));
if ($ALLOW !== '') {
  $ips = array_filter(array_map('trim', explode(',', $ALLOW)));
  $rip = $_SERVER['REMOTE_ADDR'] ?? '';
  if (!$rip || !in_array($rip, $ips, true)) {
    log_sms("DENY_IP ip=$rip");
    http_json(403, ['ok'=>false,'error'=>'IP not allowed']);
  }
}
// token header
$hdrTok = $_SERVER['HTTP_X_SMS_TOKEN'] ?? $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? '';
if (!hash_equals($SECRET, $hdrTok)) {
  log_sms('TOKEN_FAIL');
  http_json(401, ['ok'=>false,'error'=>'Invalid token']);
}

// ---------- read payload ----------
/*
Expected minimal fields from gateway:
- sender: 01XXXXXXXXX  (MSISDN who sent money)
- message: full SMS text (Bangla/English any)
- time: optional (ISO or "09-09-2025 13:22" etc.)
- gateway_id: optional unique id from gateway (for dedup assist)
We also accept: {"from": "...", "body": "...", "timestamp": "..."}
*/
$raw = file_get_contents('php://input') ?: '';
$ct  = $_SERVER['CONTENT_TYPE'] ?? '';
$in  = [];
if (stripos($ct, 'application/json') !== false) {
  $in = json_decode($raw, true) ?: [];
} elseif (stripos($ct, 'application/x-www-form-urlencoded') !== false) {
  $in = $_POST;
} else {
  $in = json_decode($raw, true) ?: $_POST;
}

$sender   = trim((string)($in['sender'] ?? $in['from'] ?? ''));
$message  = trim((string)($in['message'] ?? $in['body'] ?? ''));
$timeStr  = trim((string)($in['time'] ?? $in['timestamp'] ?? ''));

// Basic guard
if ($message === '') {
  log_sms('MALFORMED: empty message');
  http_json(422, ['ok'=>false,'error'=>'message required']);
}

// ---------- util: Bangla digit -> ASCII ----------
function bn2en_digits(string $s): string {
  $bn = ['০','১','২','৩','৪','৫','৬','৭','৮','৯','৳','ট','ক',' ', ' '];
  $en = ['0','1','2','3','4','5','6','7','8','9','Tk','T','k',' ',' '];
  return str_replace($bn, $en, $s);
}
$normMsg = bn2en_digits($message);

// ---------- try to parse amount, trxID, msisdn, reference ----------
$amount  = 0.0;
$trxID   = '';
$msisdn  = '';
$refHint = ''; // we will also try to find PPPoE/client code here

// Common English patterns (examples):
// "You have received Tk 500.00 from 01XXXXXXXXX. TrxID 7A1B2C at 12:34 09Sep2025."
if (preg_match('/\bTk[.\s]*([0-9]+(?:\.[0-9]{1,2})?)\b/i', $normMsg, $m)) {
  $amount = (float)$m[1];
}
if (preg_match('/\bTrxID[:\s]*([A-Za-z0-9]{6,20})\b/i', $normMsg, $m)) {
  $trxID = strtoupper($m[1]);
}
// Sender msisdn (01XXXXXXXXX or +8801XXXXXXXXX)
if (preg_match('/(?:\+?880|0)(1[0-9]{9})\b/', $normMsg, $m)) {
  $msisdn = '01' . substr($m[1], -9); // normalize 01XXXXXXXXX
}

// Bangla-ish patterns already normalized digits; also catch "লেনদেন আইডি"/"ট্রাঞ্জাকশন আইডি"
if ($trxID === '' && preg_match('/(?:লেনদেন|ট্রাঞ্জাকশন)\s*আইডি[:\s]*([A-Za-z0-9]{6,20})/iu', $normMsg, $m)) {
  $trxID = strtoupper($m[1]);
}

// Reference/PPPoE/Client code patterns customers might type in SMS (we search within gateway-provided copy if included)
if (preg_match('/\bPPPoE[:#\- ]?([A-Za-z0-9._\-]{3,40})\b/i', $normMsg, $m)) { $refHint = $m[1]; }
elseif (preg_match('/\bCID[:#\- ]?([0-9]{1,10})\b/i', $normMsg, $m))       { $refHint = 'CID:' . $m[1]; }
elseif (preg_match('/\bINV[-# ]?([A-Za-z0-9\-]{3,30})\b/i', $normMsg, $m))  { $refHint = 'INV-' . $m[1]; }
elseif (preg_match('/\bCODE[:#\- ]?([A-Za-z0-9._\-]{3,40})\b/i', $normMsg, $m)) { $refHint = $m[1]; }

// Time normalize
$paid_at = date('Y-m-d H:i:s');
if ($timeStr !== '') {
  $t = strtotime($timeStr);
  if ($t !== false) $paid_at = date('Y-m-d H:i:s', $t);
}

// ---------- build a dedup key when trx missing ----------
$dedupKey = $trxID ? $trxID : substr(sha1($msisdn.'|'.$amount.'|'.$paid_at.'|'.$normMsg), 0, 20);

// ---------- DB ----------
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Idempotency: if trxID present use it; else check our hash in a shadow table/notes
if ($trxID) {
  $st = $pdo->prepare("SELECT id FROM payments WHERE method='bkash_sms' AND txn_id=? LIMIT 1");
  $st->execute([$trxID]);
  if ($st->fetchColumn()) {
    log_sms("DUP trx=$trxID");
    http_json(200, ['ok'=>true,'duplicate'=>true,'by'=>'trx']);
  }
} else {
  // If no trx, we try to see if same hash noted today in notes
  $st = $pdo->prepare("SELECT id FROM payments WHERE method='bkash_sms' AND notes LIKE ? AND DATE(paid_at)=CURDATE() LIMIT 1");
  $st->execute(['%#hash='.$dedupKey.'%']);
  if ($st->fetchColumn()) {
    log_sms("DUP hash=$dedupKey");
    http_json(200, ['ok'=>true,'duplicate'=>true,'by'=>'hash']);
  }
}

// ---------- client mapping (best-effort) ----------
$client_id = 0;
$bill_id   = 0;    // if you want, we can try to map to invoice

// 1) Map by refHint (PPPoE/client code/invoice)
try {
  if ($refHint !== '') {
    // PPPoE id
    $st = $pdo->prepare("SELECT id FROM clients WHERE pppoe_id = ? LIMIT 1");
    $st->execute([$refHint]);
    $cid = (int)($st->fetchColumn() ?: 0);
    if ($cid>0) $client_id = $cid;

    // client_code
    if ($client_id===0) {
      $st = $pdo->prepare("SELECT id FROM clients WHERE client_code = ? LIMIT 1");
      $st->execute([$refHint]);
      $cid = (int)($st->fetchColumn() ?: 0);
      if ($cid>0) $client_id = $cid;
    }

    // invoice_no → invoice → client
    if ($client_id===0 && str_starts_with($refHint,'INV-')) {
      $st = $pdo->prepare("SELECT id, client_id FROM invoices WHERE invoice_no = ? LIMIT 1");
      $st->execute([$refHint]);
      if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $bill_id = (int)$row['id'];
        $client_id = (int)$row['client_id'];
      }
    }
  }
} catch(Throwable $e){ /* ignore */ }

// 2) Map by msisdn → clients.phone/contact columns (if exist)
if ($client_id===0 && $msisdn!=='') {
  try {
    // Try common phone columns dynamically
    $cols = $pdo->query("SHOW COLUMNS FROM clients")->fetchAll(PDO::FETCH_COLUMN);
    $phoneCols = array_values(array_intersect($cols, ['phone','mobile','contact','contact_no','phone1','phone2','owner_phone','owner_mobile']));
    foreach ($phoneCols as $pc) {
      $st = $pdo->prepare("SELECT id FROM clients WHERE `$pc` = ? LIMIT 1");
      $st->execute([$msisdn]);
      $cid = (int)($st->fetchColumn() ?: 0);
      if ($cid>0){ $client_id=$cid; break; }
    }
  } catch(Throwable $e){}
}

// ---------- insert payment ----------
$noteParts = [];
if ($msisdn)     $noteParts[] = "payer:$msisdn";
if ($refHint)    $noteParts[] = "ref:$refHint";
$noteParts[] = "#src=sms";
$noteParts[] = "#hash=".$dedupKey;
$notes = implode('; ', $noteParts);

// prefer trxID; if absent, store hash in txn_id to keep uniqueness
$txn_for_db = $trxID ?: ('HASH-'.$dedupKey);

try {
  if ($bill_id > 0) {
    $st = $pdo->prepare("INSERT INTO payments (bill_id, amount, discount, method, txn_id, paid_at, notes, client_id)
                         VALUES (?, ?, 0, 'bkash_sms', ?, ?, ?, ?)");
    $st->execute([$bill_id, $amount, $txn_for_db, $paid_at, $notes, $client_id ?: null]);
  } else {
    $st = $pdo->prepare("INSERT INTO payments (bill_id, amount, discount, method, txn_id, paid_at, notes, client_id)
                         VALUES (NULL, ?, 0, 'bkash_sms', ?, ?, ?, ?)");
    $st->execute([$amount, $txn_for_db, $paid_at, $notes, $client_id ?: null]);
  }
  $pid = (int)$pdo->lastInsertId();
} catch(Throwable $e){
  log_sms('DB_INSERT_FAIL '.$e->getMessage());
  http_json(500, ['ok'=>false,'error'=>'DB insert failed']);
}

// ---------- optional: mark invoice paid if rules satisfied (left as project-specific) ----------
// TODO: call your existing invoice recalculation helper here if available.

// ---------- notify (optional Telegram hook) ----------
try {
  $TG_HOOK = $ROOT . '/tg/hook_payment.php';
  $TG_CORE = $ROOT . '/tg/telegram.php';
  if (is_readable($TG_HOOK)) {
    require_once $TG_HOOK;
    if (function_exists('tg_payment_notify')) tg_payment_notify('bkash_sms', $txn_for_db, $amount, $paid_at);
  } elseif (is_readable($TG_CORE)) {
    require_once $TG_CORE;
    if (function_exists('tg_send_payment')) tg_send_payment('bkash_sms', $txn_for_db, $amount, $paid_at);
  }
} catch(Throwable $e) {
  log_sms('TELEGRAM_WARN '.$e->getMessage());
}

log_sms("OK pid=$pid amount=$amount trx=".($trxID?:'N/A')." cid=$client_id bill=$bill_id");
http_json(200, ['ok'=>true,'stored'=>true,'payment_id'=>$pid,'client_id'=>$client_id,'bill_id'=>$bill_id,'amount'=>$amount,'trxID'=>$trxID]);
