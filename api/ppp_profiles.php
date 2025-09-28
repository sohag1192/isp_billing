<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/routeros_api.class.php';

function respond($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

function get_router($id){
  $st = db()->prepare("SELECT * FROM routers WHERE id=?");
  $st->execute([$id]); return $st->fetch(PDO::FETCH_ASSOC);
}
function rt_connect($r){
  // বিভিন্ন টেবিল স্কিমা কভার
  $ip   = $r['ip'] ?? $r['address'] ?? $r['host'] ?? $r['hostname'] ?? $r['router_ip'] ?? '';
  $user = $r['username'] ?? $r['user'] ?? $r['api_user'] ?? '';
  $pass = $r['password'] ?? $r['pass'] ?? $r['api_pass'] ?? $r['api_password'] ?? '';
  $port = intval($r['port'] ?? $r['api_port'] ?? $r['ros_port'] ?? 8728);
  if(!$ip || !$user || !$pass) return false;
  $API = new RouterosAPI(); $API->debug = false;
  return $API->connect($ip, $user, $pass, $port) ? $API : false;
}
function fetch_profiles_for_router($rid){
  $r = get_router($rid);
  if(!$r) return ['ok'=>false,'error'=>'router not found'];
  $API = rt_connect($r);
  if(!$API) return ['ok'=>false,'error'=>'router connect failed'];
  $rows = $API->comm('/ppp/profile/print', ['.proplist'=>'name']);
  $API->disconnect();
  $names = [];
  foreach($rows as $row){ if(!empty($row['name'])) $names[]=$row['name']; }
  $names = array_values(array_unique($names));
  sort($names, SORT_NATURAL|SORT_FLAG_CASE);
  return ['ok'=>true,'profiles'=>$names];
}

// GET router_id=ID
if($_SERVER['REQUEST_METHOD']==='GET'){
  $rid = intval($_GET['router_id'] ?? 0);
  if(!$rid) respond(['status'=>'error','message'=>'router_id missing']);
  $res = fetch_profiles_for_router($rid);
  if(!$res['ok']) respond(['status'=>'error','message'=>$res['error']]);
  respond(['status'=>'success','profiles'=>$res['profiles']]);
}

// POST {router_ids:[...]}
$body = json_decode(file_get_contents('php://input'), true);
$rids = $body['router_ids'] ?? [];
if(!is_array($rids) || !count($rids)) respond(['status'=>'error','message'=>'router_ids missing']);

$all = [];
foreach($rids as $rid){
  $rid = intval($rid); if(!$rid) continue;
  $res = fetch_profiles_for_router($rid);
  if($res['ok']) $all = array_merge($all, $res['profiles']);
}
$all = array_values(array_unique($all));
sort($all, SORT_NATURAL|SORT_FLAG_CASE);
respond(['status'=>'success','profiles'=>$all]);
