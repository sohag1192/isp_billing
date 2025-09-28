<?php
// htdocs/api/suggest_clients.php
header('Content-Type: application/json; charset=utf-8');

// NOTE: api এবং app একই লেভেলে (siblings) => এক লেভেল উপরে
require_once __DIR__ . '/../app/db.php';

function respond($arr){ echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }

$q = trim($_GET['q'] ?? '');
$limit = 10;

// মিনিমাম ৩ অক্ষর
if ($q === '' || mb_strlen($q) < 3) {
    respond(['status'=>'success','results'=>[]]);
}

// কনটিগুয়াস (সিরিয়ালি পাশাপাশি) ম্যাচ -> name LIKE '%q%'
$like = '%' . $q . '%';

$sql = "SELECT id, name, client_code, pppoe_id, mobile, status, router_id
        FROM clients
        WHERE is_deleted = 0
          AND name LIKE ?
        ORDER BY INSTR(name, ?) ASC, name ASC
        LIMIT {$limit}";

$st = db()->prepare($sql);
$st->execute([$like, $q]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

respond(['status'=>'success','results'=>$rows]);
