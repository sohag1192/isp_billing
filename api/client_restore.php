<?php
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
header('Content-Type: application/json');
mysqli_report(MYSQLI_REPORT_OFF);
error_reporting(0); ini_set('display_errors', '0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['status'=>'error','message'=>'Invalid method']); exit; }
$payload = json_decode(file_get_contents('php://input'), true);
$archId = intval($payload['id'] ?? 0);
$override_status = trim((string)($payload['status'] ?? ''));
if (!$archId) { echo json_encode(['status'=>'error','message'=>'Invalid ID']); exit; }

try{
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // helpers
  $hasCol = function(PDO $pdo, string $table, string $col): bool {
    $q = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE ?");
    $q->execute([$col]);
    return (bool)$q->fetch(PDO::FETCH_ASSOC);
  };
  $ensureUnique = function(PDO $pdo, string $col, $val){
    if($val === null || $val==='') return $val;
    $base = (string)$val; $suffix = 0; $v = $base;
    while(true){
      $st = $pdo->prepare("SELECT 1 FROM clients WHERE {$col} = ? LIMIT 1");
      $st->execute([$v]);
      if(!$st->fetchColumn()) return $v;
      $suffix++; $v = $base . '-R' . ($suffix>1?$suffix:'');
    }
  };

  $pdo->beginTransaction();

  // Load archive row
  $st = $pdo->prepare("SELECT * FROM deleted_clients WHERE id = ?");
  $st->execute([$archId]);
  $arch = $st->fetch(PDO::FETCH_ASSOC);
  if(!$arch){ $pdo->rollBack(); echo json_encode(['status'=>'error','message'=>'Archive not found']); exit; }

  // Parse snapshot safely
  $snap = [];
  if (!empty($arch['data_json'])) {
    $tmp = json_decode($arch['data_json'], true);
    if (is_array($tmp)) $snap = $tmp;
  }

  // discover clients columns
  $cols = [];
  foreach($pdo->query("DESCRIBE clients") as $d){
    if($d['Field'] === 'id') continue;
    $cols[$d['Field']] = true;
  }

  // build row
  $row = [];
  if ($snap) { foreach($snap as $k=>$v){ if(isset($cols[$k])) $row[$k] = $v; } }
  else {
    foreach(['id','client_code','name','pppoe_id','mobile','status','package_id','router_id'] as $k){
      if(isset($cols[$k]) && isset($arch[$k])) $row[$k] = $arch[$k];
    }
  }

  // status override/default
  if($override_status !== '' && isset($cols['status'])) $row['status'] = $override_status;
  if(isset($cols['status']) && empty($row['status'])) $row['status'] = 'inactive';

  // uniqueness
  if(isset($cols['client_code']) && isset($row['client_code'])){
    $row['client_code'] = $ensureUnique($pdo, 'client_code', (string)$row['client_code']);
  }
  if(isset($cols['pppoe_id']) && isset($row['pppoe_id'])){
    $row['pppoe_id'] = $ensureUnique($pdo, 'pppoe_id', (string)$row['pppoe_id']);
  }

  // optional timestamps if exist and missing
  if(isset($cols['created_at']) && empty($row['created_at'])) $row['created_at'] = date('Y-m-d H:i:s');
  if(isset($cols['updated_at']) && empty($row['updated_at'])) $row['updated_at'] = date('Y-m-d H:i:s');

  if(empty($row)){ $pdo->rollBack(); echo json_encode(['status'=>'error','message'=>'Nothing to restore (no matching columns)']); exit; }

  // insert
  $fields = array_keys($row);
  $place  = array_fill(0, count($fields), '?');
  $vals   = array_values($row);
  $sql = "INSERT INTO clients (".implode(',',$fields).") VALUES (".implode(',',$place).")";
  $pdo->prepare($sql)->execute($vals);
  $newId = (int)$pdo->lastInsertId();

  // best-effort: mark restore (only if columns exist)
  if ($hasCol($pdo, 'deleted_clients','restored_to_id') && $hasCol($pdo, 'deleted_clients','restored_at')) {
    $up = $pdo->prepare("UPDATE deleted_clients SET restored_to_id=?, restored_at=NOW() WHERE id=?");
    $up->execute([$newId, $archId]);
  }

  $pdo->commit();
  echo json_encode(['status'=>'success','message'=>"Restored (new id: {$newId})"]);
}catch(Throwable $e){
  if(isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  echo json_encode(['status'=>'error','message'=>'Restore failed']);
}
