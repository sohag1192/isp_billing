<?php
// api/router_ids_by_clients.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

function respond($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

$ids = [];

// --- GET: ids=1,2,3  OR  id=1&id=2&id=3
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['ids'])) {
        foreach (explode(',', $_GET['ids']) as $v) {
            $v = intval(trim($v)); if ($v) $ids[] = $v;
        }
    }
    if (isset($_GET['id'])) {
        if (is_array($_GET['id'])) {
            foreach ($_GET['id'] as $v) { $v = intval($v); if ($v) $ids[] = $v; }
        } else {
            $v = intval($_GET['id']); if ($v) $ids[] = $v;
        }
    }
}

// --- POST JSON: { "ids":[...] }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    $raw = file_get_contents('php://input');
    if ($raw) {
        $body = json_decode($raw, true);
        if (isset($body['ids']) && is_array($body['ids'])) {
            foreach ($body['ids'] as $v) { $v = intval($v); if ($v) $ids[] = $v; }
        }
    }
}

$ids = array_values(array_unique($ids));
if (!$ids) respond(['status'=>'error','message'=>'ids missing']);

$in = implode(',', array_fill(0, count($ids), '?'));
$sql = "SELECT DISTINCT router_id
        FROM clients
        WHERE is_deleted=0 AND router_id IS NOT NULL AND router_id>0
          AND id IN ($in)";
$st  = db()->prepare($sql);
$st->execute($ids);
$rows = $st->fetchAll(PDO::FETCH_COLUMN);

respond(['status'=>'success','router_ids'=>array_map('intval', array_values(array_unique($rows)))]);
