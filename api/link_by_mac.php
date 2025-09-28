<?php
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

header('Content-Type: application/json; charset=utf-8');

$client_id = intval($_POST['client_id'] ?? $_GET['client_id'] ?? 0);
$mac       = strtolower(trim($_POST['mac'] ?? $_GET['mac'] ?? ''));

if(!$client_id || $mac===''){ echo json_encode(['ok'=>false,'error'=>'Missing client_id/mac']); exit; }

$st = db()->prepare("SELECT c.*, o.name AS olt_name 
                     FROM olt_mac_cache c 
                     JOIN olts o ON o.id=c.olt_id
                     WHERE c.mac=? ORDER BY c.learned_at DESC LIMIT 1");
$st->execute([$mac]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if(!$row){ echo json_encode(['ok'=>false,'error'=>'MAC not found in cache (Refresh Now first)']); exit; }

$u = db()->prepare("UPDATE clients 
                   SET caller_mac=?, olt_id=?, olt_vendor=(SELECT vendor FROM olts WHERE id=?),
                       olt_port=?, olt_onu=?, last_linked_at=NOW()
                   WHERE id=?");
$u->execute([$mac, $row['olt_id'], $row['olt_id'], $row['port'], $row['onu'], $client_id]);

echo json_encode(['ok'=>true,'data'=>[
    'olt_id'=>$row['olt_id'], 'olt_name'=>$row['olt_name'], 'port'=>$row['port'], 'onu'=>$row['onu']
]], JSON_UNESCAPED_UNICODE);
