<?php
// /api/expense_delete.php — soft delete expense + audit (always JSON)

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/acl.php';
require_once __DIR__ . '/../app/audit.php';

/* polyfill: require_perm_json (থাকলে ব্যবহার হবে, না থাকলে ডিফাইন) */
if (!function_exists('require_perm_json')) {
  function require_perm_json(string $perm){
    if (function_exists('acl_can') && acl_can($perm)) return;
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'forbidden','need'=>$perm], JSON_UNESCAPED_UNICODE);
    exit;
  }
}

require_perm_json('expense.delete');

/* helpers */
function col_exists(PDO $pdo, string $t, string $c): bool{
  try{
    $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
    $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
    $q->execute([$db,$t,$c]); return (bool)$q->fetchColumn();
  }catch(Throwable $e){ return false; }
}

$raw = file_get_contents('php://input');
$in  = json_decode($raw ?: '[]', true);
$id  = (int)($in['id'] ?? 0);
if ($id<=0) { echo json_encode(['ok'=>false,'error'=>'invalid_id']); exit; }

$pdo = db();

/* ensure soft-delete columns (MariaDB compat: IF NOT EXISTS ছাড়াই) */
try{
  if (!col_exists($pdo,'expenses','is_deleted')) $pdo->exec("ALTER TABLE expenses ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0");
  if (!col_exists($pdo,'expenses','deleted_at')) $pdo->exec("ALTER TABLE expenses ADD COLUMN deleted_at DATETIME NULL");
  if (!col_exists($pdo,'expenses','deleted_by')) $pdo->exec("ALTER TABLE expenses ADD COLUMN deleted_by BIGINT NULL");
}catch(Throwable $e){ /* ignore */ }

/* fetch row (alive only) */
$st=$pdo->prepare("SELECT * FROM expenses WHERE id=? AND COALESCE(is_deleted,0)=0");
$st->execute([$id]);
$exp=$st->fetch(PDO::FETCH_ASSOC);
if(!$exp){ echo json_encode(['ok'=>false,'error'=>'not_found']); exit; }

/* snapshot (minimal) */
$dateCol = null;
foreach (['paid_at','expense_date','date','created_at'] as $c) { if (array_key_exists($c,$exp)) { $dateCol=$c; break; } }
$old = [
  'id'=>$exp['id'],
  'amount'=>$exp['amount'] ?? ($exp['total'] ?? ($exp['value'] ?? null)),
  'date'=>$dateCol ? $exp[$dateCol] : null,
  'account_id'=>$exp['account_id'] ?? null,
  'category_id'=>$exp['category_id'] ?? null,
];

try{
  $pdo->beginTransaction();
  $u=$pdo->prepare("UPDATE expenses SET is_deleted=1, deleted_at=NOW(), deleted_by=? WHERE id=?");
  $u->execute([$_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null, $id]);

  audit_log('expense', $id, 'delete', $old, ['is_deleted'=>1]);

  $pdo->commit();
  echo json_encode(['ok'=>true]);
}catch(Throwable $e){
  if($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'exception','message'=>$e->getMessage()]);
}
