<?php
// /api/client_left_bulk.php (সংশ্লিষ্ট অংশ)
declare(strict_types=1);

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/audit.php';

header('Content-Type: application/json; charset=utf-8');
function jexit($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

$ids = $_POST['ids'] ?? [];
$target = $_POST['target'] ?? ''; // 'left' or 'undo'
if (!is_array($ids) || !$ids) jexit(['ok'=>false,'msg'=>'No IDs']);
if (!in_array($target, ['left','undo'], true)) jexit(['ok'=>false,'msg'=>'Invalid target']);

$want_left = $target === 'left' ? 1 : 0;
$left_at_sql = $want_left ? "NOW()" : "NULL";

$ok = 0; $fail = 0;

$stmt = db()->prepare("SELECT id, name, pppoe_id, is_left FROM clients WHERE id=?");
$up   = db()->prepare("UPDATE clients SET is_left=?, left_at={$left_at_sql} WHERE id=?");

foreach ($ids as $rawId) {
    $id = (int)$rawId;
    if (!$id) { $fail++; continue; }

    $stmt->execute([$id]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$c) { $fail++; continue; }

    try {
        $up->execute([$want_left, $id]);
        $ok++;

        // ✅ AUDIT LOG
        $action = $want_left ? 'client_left' : 'client_undo_left';
        audit($action, 'client', $id, [
            'pppoe_id' => $c['pppoe_id'],
            'name'     => $c['name'],
            'from'     => (int)$c['is_left'],
            'to'       => (int)$want_left,
        ]);
    } catch (Throwable $e) {
        $fail++;
    }
}

jexit(['ok'=>true,'updated'=>$ok,'failed'=>$fail]);
php
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(['status'=>'error','message'=>'Invalid method']); exit;
}
$payload = json_decode(file_get_contents('php://input'), true);
$ids    = $payload['ids'] ?? [];
$action = trim((string)($payload['action'] ?? ''));
if (!is_array($ids) || !count($ids) || !in_array($action, ['left','undo'], true)) {
  echo json_encode(['status'=>'error','message'=>'Bad params']); exit;
}
$ids = array_values(array_filter(array_map('intval',$ids), fn($v)=>$v>0));

try{
  $pdo = db();
  $in  = implode(',', array_fill(0, count($ids), '?'));
  if ($action === 'left') {
    $sql = "UPDATE clients SET is_left=1, left_at=NOW() WHERE id IN ($in)";
  } else {
    $sql = "UPDATE clients SET is_left=0, left_at=NULL WHERE id IN ($in)";
  }
  $st = $pdo->prepare($sql);
  $ok = $st->execute($ids);
  echo json_encode([
    'status'   => $ok ? 'success' : 'error',
    'processed'=> count($ids),
    'message'  => $action==='left' ? 'Bulk Left সম্পন্ন' : 'Bulk Undo Left সম্পন্ন'
  ]);
}catch(Throwable $e){
  echo json_encode(['status'=>'error','message'=>'Database error']);
}
