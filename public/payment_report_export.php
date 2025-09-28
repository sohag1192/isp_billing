<?php
// /public/payment_report_export.php
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="payments_'.date('Ymd_His').'.csv"');

$from   = $_GET['from'] ?? '';
$to     = $_GET['to']   ?? '';
$method = trim($_GET['method'] ?? '');
$client = (int)($_GET['client_id'] ?? 0);
$qtext  = trim($_GET['q'] ?? '');

$w=[]; $p=[];
if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)) { $w[]="DATE(p.paid_at) >= ?"; $p[]=$from; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$to))   { $w[]="DATE(p.paid_at) <= ?"; $p[]=$to; }
if ($method!==''){ $w[]="p.method = ?"; $p[]=$method; }
if ($client>0)   { $w[]="p.client_id = ?"; $p[]=$client; }
if ($qtext!==''){ $w[]="(p.txn_id LIKE ? OR p.remarks LIKE ? OR p.invoice_id = ?)"; $p[]="%$qtext%"; $p[]="%$qtext%"; $p[]=(int)$qtext; }
$where = $w?('WHERE '.implode(' AND ',$w)):'';

$pdo = db();
$st = $pdo->prepare("SELECT p.id, p.paid_at, c.name AS client, p.invoice_id, p.method, p.amount, p.txn_id, p.remarks
                     FROM payments p
                     LEFT JOIN clients c ON c.id=p.client_id
                     $where
                     ORDER BY p.paid_at DESC, p.id DESC");
$st->execute($p);

$out = fopen('php://output','w');
fputcsv($out, ['id','paid_at','client','invoice_id','method','amount','txn_id','remarks']);
while($r = $st->fetch(PDO::FETCH_ASSOC)){
  $r['remarks'] = preg_replace('/\r\n|\r|\n/',' ', (string)$r['remarks']);
  fputcsv($out, [$r['id'],$r['paid_at'],$r['client'],$r['invoice_id'],$r['method'],$r['amount'],$r['txn_id'],$r['remarks']]);
}
fclose($out);
