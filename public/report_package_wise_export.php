<?php
// /public/report_package_wise_export.php
// (বাংলা) CSV export for the same dataset of report_package_wise.php (no pagination).
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function hcol(PDO $pdo, string $tbl, string $col): bool {
  $st=$pdo->prepare("SHOW COLUMNS FROM `$tbl` LIKE ?"); $st->execute([$col]);
  return (bool)$st->fetchColumn();
}
function ym_ok(?string $m): bool { return (bool)($m && preg_match('/^\d{4}-\d{2}$/',$m)); }
function ym_range(string $ym): array { $s=$ym.'-01'; return [$s,date('Y-m-t', strtotime($s))]; }

$search      = trim($_GET['search'] ?? '');
$router_id   = (int)($_GET['router_id'] ?? 0);
$area        = trim($_GET['area'] ?? '');
$status      = strtolower($_GET['status'] ?? '');
$include_left= (int)($_GET['include_left'] ?? 0);
$month_ym    = trim($_GET['month'] ?? '');

$has_left   = hcol($pdo,'clients','is_left');
$has_online = hcol($pdo,'clients','is_online');
$has_ledger = hcol($pdo,'clients','ledger_balance');
$has_status = hcol($pdo,'clients','status');

$inv_has_status        = hcol($pdo,'invoices','status');
$inv_has_is_void       = hcol($pdo,'invoices','is_void');
$inv_has_billing_month = hcol($pdo,'invoices','billing_month');
$inv_has_invoice_date  = hcol($pdo,'invoices','invoice_date');
$inv_has_created       = hcol($pdo,'invoices','created_at');

$inv_amount_col = null;
foreach (['total','payable','amount'] as $c) { if (hcol($pdo,'invoices',$c)) { $inv_amount_col = $c; break; } }

$amtExpr = "COALESCE(NULLIF(c.monthly_bill,0), p.price, 0)";

$where = "1=1";
$params = [];
if (!$include_left && $has_left) { $where .= " AND COALESCE(c.is_left,0)=0"; }
if ($status && $has_status) {
  if (in_array($status,['active','inactive'],true)) {
    $where .= " AND c.status = ?"; $params[] = $status;
  }
}
if ($router_id > 0) { $where .= " AND c.router_id = ?"; $params[] = $router_id; }
if ($area !== '')   { $where .= " AND c.area = ?";      $params[] = $area; }
if ($search !== '') { $where .= " AND p.name LIKE ?";   $params[] = "%$search%"; }

$sql = "
  SELECT
    p.id,
    p.name,
    p.price,
    COUNT(c.id)                                   AS total_clients,
    SUM(CASE WHEN ".($has_left?"COALESCE(c.is_left,0)=0":"1=1")." THEN 1 ELSE 0 END) AS current_clients,
    SUM(CASE WHEN ".($has_status?"c.status='active'":"0")." AND ".($has_left?"COALESCE(c.is_left,0)=0":"1=1")." THEN 1 ELSE 0 END) AS active_clients,
    SUM(CASE WHEN ".($has_online?"c.is_online=1":"0")."  AND ".($has_left?"COALESCE(c.is_left,0)=0":"1=1")." THEN 1 ELSE 0 END) AS online_clients,
    SUM(CASE WHEN ".($has_left?"COALESCE(c.is_left,0)=0":"1=1")." THEN $amtExpr ELSE 0 END) AS expected_monthly,
    ".($has_ledger ? "SUM(GREATEST(COALESCE(c.ledger_balance,0),0))" : "0")." AS due_total
  FROM packages p
  LEFT JOIN clients c ON c.package_id = p.id
  WHERE $where
  GROUP BY p.id, p.name, p.price
";
$st = $pdo->prepare($sql); $st->execute($params);
$data = $st->fetchAll(PDO::FETCH_ASSOC);

$invSummary = [];
if ($inv_amount_col && ym_ok($month_ym)) {
  [$mStart,$mEnd] = ym_range($month_ym);
  $invDateExpr = $inv_has_billing_month ? "DATE(i.billing_month)" : ($inv_has_invoice_date ? "DATE(i.invoice_date)" : ($inv_has_created ? "DATE(i.created_at)" : null));
  if ($invDateExpr) {
    $whereInv = " $invDateExpr BETWEEN ? AND ? ";
    if ($inv_has_is_void)  { $whereInv .= " AND COALESCE(i.is_void,0)=0"; }
    $sqlInv = "
      SELECT c.package_id,
             COUNT(i.id) AS inv_count,
             SUM(i.$inv_amount_col) AS inv_total,
             ".($inv_has_status ? "SUM(CASE WHEN i.status='paid' THEN i.$inv_amount_col ELSE 0 END)" : "0")." AS inv_paid,
             ".($inv_has_status ? "SUM(CASE WHEN i.status IN ('unpaid','partial') THEN i.$inv_amount_col ELSE 0 END)" : "0")." AS inv_unpaid
      FROM invoices i
      JOIN clients c ON c.id = i.client_id
      WHERE $whereInv
      GROUP BY c.package_id
    ";
    $sti = $pdo->prepare($sqlInv);
    $sti->execute([$mStart,$mEnd]);
    foreach ($sti->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $invSummary[(int)$r['package_id']] = [
        'inv_count'  => (int)$r['inv_count'],
        'inv_total'  => (float)$r['inv_total'],
        'inv_paid'   => (float)$r['inv_paid'],
        'inv_unpaid' => (float)$r['inv_unpaid'],
      ];
    }
  }
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="package_wise_report.csv"');

$out = fopen('php://output','w');
$baseCols = ['ID','Package','Price','Clients','Active','Online','Expected Monthly','Due (Ledger > 0)'];
$invCols = (ym_ok($month_ym) && $inv_amount_col) ? ['Inv Total','Inv Paid','Inv Unpaid'] : [];
fputcsv($out, array_merge($baseCols, $invCols));

foreach ($data as $r) {
  $pid = (int)$r['id'];
  $row = [
    $pid,
    $r['name'],
    number_format((float)$r['price'],2,'.',''),
    (int)$r['current_clients'],
    (int)$r['active_clients'],
    (int)$r['online_clients'],
    number_format((float)$r['expected_monthly'],2,'.',''),
    number_format((float)$r['due_total'],2,'.','')
  ];
  if ($invCols) {
    $row[] = number_format((float)($invSummary[$pid]['inv_total']  ?? 0),2,'.','');
    $row[] = number_format((float)($invSummary[$pid]['inv_paid']   ?? 0),2,'.','');
    $row[] = number_format((float)($invSummary[$pid]['inv_unpaid'] ?? 0),2,'.','');
  }
  fputcsv($out, $row);
}
fclose($out);
