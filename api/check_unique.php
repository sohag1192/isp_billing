<?php
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
header('Content-Type: application/json; charset=utf-8');

$pppoe_id = isset($_GET['pppoe_id']) ? trim($_GET['pppoe_id']) : '';
$out = ['pppoe_id_exists' => false];

try {
  if ($pppoe_id !== '') {
    $st = db()->prepare("SELECT 1 FROM clients WHERE pppoe_id=? LIMIT 1");
    $st->execute([$pppoe_id]);
    $out['pppoe_id_exists'] = (bool)$st->fetchColumn();
  }
  echo json_encode($out);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()]);
}
