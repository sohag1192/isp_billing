<?php
// /public/expenses_export.php — schema-aware CSV
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="expenses_'.date('Ymd_His').'.csv"');

function col_exists(PDO $pdo, string $t, string $c): bool{
  try{
    $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
    $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
    $q->execute([$db,$t,$c]); return (bool)$q->fetchColumn();
  }catch(Throwable $e){ return false; }
}
function pick_col(PDO $pdo, string $t, array $candidates, string $fallback): string{
  foreach($candidates as $c){ if(col_exists($pdo,$t,$c)) return $c; }
  return $fallback;
}

$pdo = db();
$dateCol   = pick_col($pdo,'expenses',['paid_at','expense_date','date','created_at'],'paid_at');
$amountCol = pick_col($pdo,'expenses',['amount','total','value'],'amount');
$headCol   = pick_col($pdo,'expenses',['head','category','title','name'],'head');
$methodCol = pick_col($pdo,'expenses',['method','payment_method'],'method');
$refCol    = pick_col($pdo,'expenses',['ref_no','ref','reference','voucher_no'],'ref_no');
$noteCol   = pick_col($pdo,'expenses',['note','notes','remarks','description'],'note');

$from   = $_GET['from'] ?? '';
$to     = $_GET['to']   ?? '';
$head   = trim($_GET['head'] ?? '');
$method = trim($_GET['method'] ?? '');
$qtext  = trim($_GET['q'] ?? '');
$minAmt = isset($_GET['min']) ? (float)$_GET['min'] : null;
$maxAmt = isset($_GET['max']) ? (float)$_GET['max'] : null;

$w=[]; $p=[];
if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)) { $w[]="DATE(e.`$dateCol`) >= ?"; $p[]=$from; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$to))   { $w[]="DATE(e.`$dateCol`) <= ?"; $p[]=$to; }
if ($head!==''){   $w[]="e.`$headCol` = ?";   $p[]=$head; }
if ($method!==''){ $w[]="e.`$methodCol` = ?"; $p[]=$method; }
if ($minAmt!==null){ $w[]="e.`$amountCol` >= ?"; $p[]=$minAmt; }
if ($maxAmt!==null){ $w[]="e.`$amountCol` <= ?"; $p[]=$maxAmt; }
if ($qtext!==''){ $w[]="(e.`$refCol` LIKE ? OR e.`$noteCol` LIKE ?)"; $p[]="%$qtext%"; $p[]="%$qtext%"; }
$where = $w?('WHERE '.implode(' AND ',$w)):'';

$st = $pdo->prepare("
  SELECT
    e.id,
    e.`$dateCol`   AS paid_at,
    e.`$headCol`   AS head,
    e.`$methodCol` AS method,
    e.`$amountCol` AS amount,
    e.`$refCol`    AS ref_no,
    e.`$noteCol`   AS note
  FROM expenses e
  $where
  ORDER BY paid_at DESC, id DESC
");
$st->execute($p);

$out = fopen('php://output','w');
fputcsv($out, ['id','paid_at','head','method','amount','ref_no','note']);
while($r = $st->fetch(PDO::FETCH_ASSOC)){
  $r['note'] = preg_replace('/\r\n|\r|\n/', ' ', (string)$r['note']);
  fputcsv($out, [$r['id'],$r['paid_at'],$r['head'],$r['method'],$r['amount'],$r['ref_no'],$r['note']]);
}
fclose($out);
