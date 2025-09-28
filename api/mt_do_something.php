<?php
// Example: /api/mt_do_something.php  (এই ফাইল শুধু MT কাজের জন্য)
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/routeros_api.class.php';

function respond($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

$router_id = intval($_GET['router_id'] ?? $_POST['router_id'] ?? 0);
if (!$router_id) respond(['ok'=>false,'error'=>'router_id missing']);

try {
    // রাউটার ফেচ
    $st = db()->prepare("SELECT * FROM routers WHERE id=?");
    $st->execute([$router_id]);
    $router = $st->fetch(PDO::FETCH_ASSOC);
    if (!$router) respond(['ok'=>false,'error'=>'Router not found']);

    // MT কানেক্ট
    $API = new RouterosAPI();
    $API->debug = false;
    if (!$API->connect($router['ip'], $router['username'], $router['password'], $router['api_port'])) {
        respond(['ok'=>false,'error'=>'MikroTik connect failed']);
    }

    // >>> এখানে আপনার আসল MT কমান্ডগুলো <<<
    // $API->comm('/ppp/secret/print', ['?name'=>$pppoe_id]);

    $API->disconnect();
    respond(['ok'=>true,'msg'=>'done']);
} catch (Throwable $e) {
    respond(['ok'=>false,'error'=>'Exception: '.$e->getMessage()]);
}
