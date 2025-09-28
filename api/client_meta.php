<?php
// /api/client_meta.php
// ক্লায়েন্টের quick meta (monthly_bill, expiry_date) ফেরত দেয়

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['ok'=>false, 'error'=>'invalid']); exit;
}

$st = db()->prepare("SELECT id, name, pppoe_id, monthly_bill, expiry_date FROM clients WHERE id=? AND is_deleted=0");
$st->execute([$id]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) { echo json_encode(['ok'=>false, 'error'=>'not_found']); exit; }

echo json_encode(['ok'=>true, 'data'=>[
    'id'           => (int)$row['id'],
    'name'         => $row['name'],
    'pppoe_id'     => $row['pppoe_id'],
    'monthly_bill' => (float)$row['monthly_bill'],
    'expiry_date'  => $row['expiry_date'] ?: null
]]);
