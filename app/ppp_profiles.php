<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/roles.php';
require_role(['billing']); // admin auto allowed

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/routeros_api.class.php';

function respond($arr){ echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }

function get_router($id){
    $st = db()->prepare("SELECT * FROM routers WHERE id=?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC);
}
function rt_connect($router){
    $ip   = $router['ip'] ?? ($router['address'] ?? '');
    $user = $router['username'] ?? ($router['user'] ?? '');
    $pass = $router['password'] ?? ($router['pass'] ?? '');
    $port = intval($router['port'] ?? $router['api_port'] ?? 8728);
    if (!$ip || !$user || !$pass) return false;
    $API = new RouterosAPI(); $API->debug = false;
    return $API->connect($ip, $user, $pass, $port) ? $API : false;
}
function fetch_profiles_for_router($rid){
    $router = get_router($rid);
    if (!$router) return ['ok'=>false,'error'=>'router not found'];
    $API = rt_connect($router);
    if (!$API) return ['ok'=>false,'error'=>'router connect failed'];
    $rows = $API->comm('/ppp/profile/print', ['.proplist'=>'name']);
    $API->disconnect();

    $names = [];
    foreach ($rows as $r){
        if (isset($r['name']) && $r['name'] !== '') $names[] = $r['name'];
    }
    $names = array_values(array_unique($names));
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    return ['ok'=>true,'profiles'=>$names];
}

// GET: single router
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rid = intval($_GET['router_id'] ?? 0);
    if (!$rid) respond(['status'=>'error','message'=>'router_id missing']);
    $res = fetch_profiles_for_router($rid);
    if (!$res['ok']) respond(['status'=>'error','message'=>$res['error']]);
    respond(['status'=>'success','profiles'=>$res['profiles']]);
}

// POST: multiple routers (union)
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
$rids = $body['router_ids'] ?? [];
if (!is_array($rids) || count($rids)===0) respond(['status'=>'error','message'=>'router_ids missing']);

$union = [];
foreach ($rids as $rid){
    $rid = intval($rid);
    if (!$rid) continue;
    $res = fetch_profiles_for_router($rid);
    if ($res['ok']) $union = array_merge($union, $res['profiles']);
}
$union = array_values(array_unique($union));
sort($union, SORT_NATURAL | SORT_FLAG_CASE);

respond(['status'=>'success','profiles'=>$union]);
