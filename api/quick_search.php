<?php
// api/quick_search.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/roles.php';



function respond($arr){ echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }

$q = trim($_GET['q'] ?? '');
$limit = 10;

if ($q === '') {
    respond(['status'=>'success','results'=>[]]);
}

// Build LIKE params
$like = '%' . $q . '%';

$sql = "SELECT id, name, client_code, pppoe_id, mobile, status, router_id
        FROM clients
        WHERE is_deleted = 0
          AND (name LIKE ? OR pppoe_id LIKE ? OR mobile LIKE ? OR client_code LIKE ?)
        ORDER BY name ASC
        LIMIT {$limit}";

$st = db()->prepare($sql);
$st->execute([$like, $like, $like, $like]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

respond(['status'=>'success','results'=>$rows]);
?>