<?php
// /public/income_expense_export.php — schema-aware
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="income_expense_'.date('Ymd_His').'.csv"');

function table_exists(PDO $pdo, string $t): bool {
  try{ $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
       $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
       $q->execute([$db,$t]); return (bool)$q->fetchColumn();
  }catch(Throwable $e){ return false; }
}
function col_exists(PDO $pdo, string $t, string $c): bool {
  try{ $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
       $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
       $q->execute([$db,$t,$c]); return (bool)$q->fetchColumn();
  }catch(Throwable $e){ return false; }
}
function pick_col(PDO $pdo, string $t, array $candidates, string $fallback): string {
  foreach($candidates as $c){ if(col_exists($pdo,$t,$c)) return $c; }
  return $fallback;
}

$from = $_GET['from'] ?? '';
$to   = $_GET['to']   ?? '';
$gran = ($_GET['gran'] ?? 'day')==='month' ? 'month' : 'day';
$method_inc = trim($_GET['method_inc'] ?? '');
$method_exp = trim($_GET['method_exp'] ?? '');

$pdo = db();
$hasExp = table_exists($pdo,'expenses');

$payTable='payments';
$payDate = pick_col($pdo,$payTable,['paid_at','payment_date','date','created_at'],'paid_at');
$payMeth = pick_col($pdo,$payTable,['method','payment_method'],'method');

if($hasExp){
  $expTable='expenses';
  $expDate = pick_col($pdo,$expTable,['paid_at','expense_date','date','created_at'],'paid_at');
  $expMeth = pick_col($pdo,$expTable,['method','payment_method'],'method');
}

$wi=[]; $pi=[];
if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)) { $wi[]="DATE(p.`$payDate`) >= ?"; $pi[]=$from; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$to))   { $wi[]="DATE(p.`$payDate`) <= ?"; $pi[]=$to; }
if ($method_inc!==''){ $wi[]="p.`$payMeth`=?"; $pi[]=$method_inc; }
$whereI = $wi?('WHERE '.implode(' AND ',$wi)):'';

$we=[]; $pe=[];
if ($hasExp){
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)) { $we[]="DATE(e.`$expDate`) >= ?"; $pe[]=$from; }
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$to))   { $we[]="DATE(e.`$expDate`) <= ?"; $pe[]=$to; }
  if ($method_exp!==''){ $we[]="e.`$expMeth`=?"; $pe[]=$method_exp; }
}
$whereE = ($hasExp && $we)?('WHERE '.implode(' AND ',$we)) : ($hasExp?'':null);

if ($gran==='month'){
  $gi = $pdo->prepare("SELECT DATE_FORMAT(p.`$payDate`,'%Y-%m') as k, SUM(p.amount) s FROM `$payTable` p $whereI GROUP BY DATE_FORMAT(p.`$payDate`,'%Y-%m') ORDER BY k ASC");
} else {
  $gi = $pdo->prepare("SELECT DATE(p.`$payDate`) as k, SUM(p.amount) s FROM `$payTable` p $whereI GROUP BY DATE(p.`$payDate`) ORDER BY k ASC");
}
$gi->execute($pi);
$ginc = $gi->fetchAll(PDO::FETCH_KEY_PAIR);

if ($hasExp){
  if ($gran==='month'){
    $ge = $pdo->prepare("SELECT DATE_FORMAT(e.`$expDate`,'%Y-%m') as k, SUM(e.amount) s FROM `$expTable` e $whereE GROUP BY DATE_FORMAT(e.`$expDate`,'%Y-%m') ORDER BY k ASC");
  } else {
    $ge = $pdo->prepare("SELECT DATE(e.`$expDate`) as k, SUM(e.amount) s FROM `$expTable` e $whereE GROUP BY DATE(e.`$expDate`) ORDER BY k ASC");
  }
  $ge->execute($pe);
  $gexp = $ge->fetchAll(PDO::FETCH_KEY_PAIR);
} else {
  $gexp = [];
}

$keys = array_values(array_unique(array_merge(array_keys($ginc), array_keys($gexp))));
sort($keys);

$out = fopen('php://output','w');
fputcsv($out, [$gran==='month'?'month':'date','income','expense','net']);
foreach($keys as $k){
  $inc = (float)($ginc[$k] ?? 0);
  $exp = (float)($gexp[$k] ?? 0);
  fputcsv($out, [$k, number_format($inc,2,'.',''), number_format($exp,2,'.',''), number_format($inc-$exp,2,'.','')]);
}
fclose($out);
