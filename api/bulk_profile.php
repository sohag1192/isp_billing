<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../app/require_login.php';
// চাইলে রোল গার্ড:
// require_once __DIR__ . '/../app/roles.php'; require_role(['billing','admin']);

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/routeros_api.class.php';

function respond($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

$profile = trim($payload['profile'] ?? '');
$ids     = $payload['ids'] ?? [];

if ($profile === '' || !is_array($ids) || !count($ids)) {
  respond(['status'=>'error','message'=>'Invalid payload']);
}

// ক্লায়েন্ট ডাটা (router wise)
$in = implode(',', array_fill(0, count($ids), '?'));
$sql = "SELECT id, pppoe_id, router_id FROM clients WHERE is_deleted=0 AND id IN ($in)";
$st  = db()->prepare($sql);
$st->execute($ids);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) respond(['status'=>'error','message'=>'No clients found']);

$byRouter = [];
foreach ($rows as $r) {
  $rid = (int)$r['router_id'];
  if (!$rid) continue;
  $byRouter[$rid][] = $r;
}

// router helpers
function get_router($id){
  $st = db()->prepare("SELECT * FROM routers WHERE id=?");
  $st->execute([$id]); return $st->fetch(PDO::FETCH_ASSOC);
}
function rt_connect($r){
  $ip = $r['ip'] ?? ($r['address'] ?? '');
  $user = $r['username'] ?? ($r['user'] ?? '');
  $pass = $r['password'] ?? ($r['pass'] ?? '');
  $port = intval($r['port'] ?? $r['api_port'] ?? 8728);
  if(!$ip || !$user || !$pass) return false;
  $API = new RouterosAPI(); $API->debug=false;
  return $API->connect($ip,$user,$pass,$port) ? $API : false;
}
function secret_id($API, $name){
  $res = $API->comm('/ppp/secret/print', ['?name'=>$name, '.proplist'=>'.id']);
  return isset($res[0]['.id']) ? $res[0]['.id'] : null;
}

$processed=0; $ok=0; $fail=0;

foreach ($byRouter as $rid=>$clients) {
  $router = get_router($rid);
  if (!$router){ $fail += count($clients); $processed += count($clients); continue; }

  $API = rt_connect($router);
  if (!$API){ $fail += count($clients); $processed += count($clients); continue; }

  foreach ($clients as $c) {
    $processed++;
    $id = secret_id($API, $c['pppoe_id']);
    if (!$id){ $fail++; continue; }
    $res = $API->comm('/ppp/secret/set', ['.id'=>$id, 'profile'=>$profile]);
    // RouterOS API set returns array; absence of !trap indicates ok
    if (is_array($res)) $ok++; else $fail++;
  }
  $API->disconnect();
}

respond([
  'status'     => 'success',
  'processed'  => $processed,
  'succeeded'  => $ok,
  'failed'     => $fail,
  'profile'    => $profile
]);
