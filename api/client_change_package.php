<?php
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/mikrotik_client.php';
header('Content-Type: application/json; charset=utf-8');

$client_id  = intval($_POST['client_id'] ?? 0);
$package_id = intval($_POST['package_id'] ?? 0);
if(!$client_id || !$package_id){ echo json_encode(['ok'=>false,'error'=>'missing params']); exit; }

$st = db()->prepare("SELECT id, pppoe_id, router_id FROM clients WHERE id=?");
$st->execute([$client_id]); $c = $st->fetch(PDO::FETCH_ASSOC);
if(!$c){ echo json_encode(['ok'=>false,'error'=>'client not found']); exit; }

$pq = db()->prepare("SELECT id, name, monthly_fee, profile_name FROM packages WHERE id=?");
$pq->execute([$package_id]); $pkg = $pq->fetch(PDO::FETCH_ASSOC);
if(!$pkg){ echo json_encode(['ok'=>false,'error'=>'package not found']); exit; }
$profile = trim($pkg['profile_name'] ?? '');
if($profile===''){ echo json_encode(['ok'=>false,'error'=>'package missing profile_name']); exit; }

$r = mt_get_router($c['router_id']);
if(!$r){ echo json_encode(['ok'=>false,'error'=>'router not found']); exit; }
list($API,$err) = mt_connect($r);
if(!$API){ echo json_encode(['ok'=>false,'error'=>$err]); exit; }

$x = mt_pppoe_set_profile($API, $c['pppoe_id'], $profile);
$API->disconnect();
if(!$x['ok']){ echo json_encode($x); exit; }

db()->prepare("UPDATE clients SET package_id=?, monthly_bill=? WHERE id=?")
  ->execute([$package_id, $pkg['monthly_fee'], $client_id]);

echo json_encode(['ok'=>true,'message'=>'Package changed', 'profile'=>$profile, 'monthly_bill'=>$pkg['monthly_fee']]);
