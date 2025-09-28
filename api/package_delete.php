<?php
// /api/package_delete.php
// (বাংলা) Soft delete if is_deleted exists; otherwise hard delete. Block if clients are assigned.
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

header('Content-Type: application/json; charset=utf-8');
function out($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
function hascol(PDO $pdo, $tbl, $col){
  $st=$pdo->prepare("SHOW COLUMNS FROM `$tbl` LIKE ?"); $st->execute([$col]);
  return (bool)$st->fetchColumn();
}

try{
  $in = json_decode(file_get_contents('php://input'), true) ?? [];
  $id = (int)($in['id'] ?? 0);
  if ($id<=0) out(['ok'=>false,'error'=>'Invalid id']);

  $pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // (বাংলা) references: any active client using this package?
  $stc = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE package_id=? AND COALESCE(is_left,0)=0");
  $stc->execute([$id]);
  if ((int)$stc->fetchColumn() > 0) {
    out(['ok'=>false,'error'=>'Clients are assigned to this package—delete is blocked']);
  }

  if (hascol($pdo,'packages','is_deleted')) {
    $u = $pdo->prepare("UPDATE packages SET is_deleted=1, updated_at=NOW() WHERE id=?");
    $u->execute([$id]);
    out(['ok'=>true,'message'=>'Package archived']);
  } else {
    $d = $pdo->prepare("DELETE FROM packages WHERE id=?");
    $d->execute([$id]);
    out(['ok'=>true,'message'=>'Package deleted']);
  }
}catch(Throwable $e){
  out(['ok'=>false,'error'=>$e->getMessage()]);
}
