<?php
// /api/auto_control_client.php
// Purpose: Re-check ONE client by clients.ledger_balance and auto enable/suspend on MikroTik.
// Action:
//   - ledger_balance > 0  ➜ disable PPP secret (+kick active)
//   - ledger_balance <= 0 ➜ enable PPP secret
// Bengali comments; code & labels in English.

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/routeros_api.class.php';

/* ---------- Helpers ---------- */
// (বাংলা) JSON response
function jexit(array $a): void { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

// (বাংলা) টেবিলের কলাম আছে কি না
function col_exists(PDO $pdo, string $tbl, string $col): bool {
  try {
    $st = $pdo->prepare("SHOW COLUMNS FROM `$tbl` LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) { return false; }
}

// (বাংলা) Audit log (যদি টেবিল থাকে)
function audit_log(PDO $pdo, int $client_id, string $action, string $note=''): void {
  if (!col_exists($pdo, 'audit_logs', 'id')) return;
  $st = $pdo->prepare("INSERT INTO audit_logs (entity_type, entity_id, action, note, created_at) VALUES ('client', ?, ?, ?, NOW())");
  $st->execute([$client_id, $action, $note]);
}

/* ---------- Input ---------- */
$client_id = (int)($_POST['client_id'] ?? $_GET['client_id'] ?? 0);
if ($client_id <= 0) jexit(['ok'=>0,'action'=>'noop','msg'=>'Invalid client id.']);

/* ---------- Load client + router ---------- */
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// (বাংলা) columns guard
$has_is_left = col_exists($pdo, 'clients', 'is_left');
$has_status  = col_exists($pdo, 'clients', 'status');
$has_optout  = col_exists($pdo, 'clients', 'auto_control_optout');
$has_vip     = col_exists($pdo, 'clients', 'is_vip');

$sql = "SELECT c.*, r.ip, r.username, r.password, r.api_port
        FROM clients c
        LEFT JOIN routers r ON r.id=c.router_id
        WHERE c.id=? LIMIT 1";
$st  = $pdo->prepare($sql);
$st->execute([$client_id]);
$c = $st->fetch(PDO::FETCH_ASSOC);
if (!$c) jexit(['ok'=>0,'action'=>'noop','msg'=>'Client not found.']);

if ($has_is_left && (int)$c['is_left'] === 1) {
  jexit(['ok'=>1,'action'=>'noop','msg'=>'Skipped: client is left.']);
}
if (empty($c['router_id']) || empty($c['pppoe_id'])) {
  jexit(['ok'=>0,'action'=>'noop','msg'=>'No router/PPPoE mapping.']);
}
// (বাংলা) opt-out/VIP হলে স্কিপ
if (($has_optout && (int)$c['auto_control_optout']===1) || ($has_vip && (int)$c['is_vip']===1)) {
  jexit(['ok'=>1,'action'=>'noop','msg'=>'Skipped by opt-out/VIP.']);
}

/* ---------- RouterOS connect ---------- */
// (বাংলা) RouterOS class: port প্রোপার্টি আগে সেট করে connect(ip,user,pass) কল
$API = new RouterosAPI();
$API->debug = false;
$API->port  = (int)($c['api_port'] ?: 8728);

if (!$API->connect($c['ip'], $c['username'], $c['password'])) {
  jexit(['ok'=>0,'action'=>'noop','msg'=>'Router connect failed.']);
}

/* ---------- PPP secret lookup ---------- */
$pppoe = $c['pppoe_id'];
$sec = $API->comm('/ppp/secret/print', [
  '.proplist' => '.id,disabled',
  '?name'     => $pppoe
]);
if (!isset($sec[0]['.id'])) {
  $API->disconnect();
  jexit(['ok'=>0,'action'=>'noop','msg'=>'PPP secret not found on router.']);
}
$secret_id   = $sec[0]['.id'];
$is_disabled = (isset($sec[0]['disabled']) && ($sec[0]['disabled']==='true' || $sec[0]['disabled']==='yes'));

/* ---------- Decision by ledger_balance ---------- */
// (বাংলা) +ve = Due, -ve/0 = Advance/Clear
$due = (float)($c['ledger_balance'] ?? 0.0);

if ($due > 0) {
  // (বাংলা) Disable if needed
  if (!$is_disabled) {
    $API->comm('/ppp/secret/set', ['.id'=>$secret_id, 'disabled'=>'yes']);
  }
  // (বাংলা) Active session থাকলে kick
  $act = $API->comm('/ppp/active/print', ['.proplist'=>'.id,name', '?name'=>$pppoe]);
  if (isset($act[0]['.id'])) {
    $API->comm('/ppp/active/remove', ['.id'=>$act[0]['.id']]);
  }
  // (বাংলা) status থাকলে inactive সেট
  if ($has_status && ($c['status'] ?? '') !== 'inactive') {
    $u = $pdo->prepare("UPDATE clients SET status='inactive', updated_at=NOW() WHERE id=?");
    $u->execute([$client_id]);
  }
  audit_log($pdo, $client_id, 'suspend', "Auto-suspend (due={$due})");
  $API->disconnect();
  jexit(['ok'=>1,'action'=>'disabled','msg'=>"Disabled (due={$due})"]);

} else {
  // (বাংলা) Enable if disabled
  if ($is_disabled) {
    $API->comm('/ppp/secret/set', ['.id'=>$secret_id, 'disabled'=>'no']);
  }
  // (বাংলা) status থাকলে active সেট
  if ($has_status && ($c['status'] ?? '') !== 'active') {
    $u = $pdo->prepare("UPDATE clients SET status='active', updated_at=NOW() WHERE id=?");
    $u->execute([$client_id]);
  }
  audit_log($pdo, $client_id, 'enable', "Auto-enable (due={$due})");
  $API->disconnect();
  jexit(['ok'=>1,'action'=>'enabled','msg'=>"Enabled (due={$due})"]);
}
