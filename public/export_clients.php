<?php
/**
 * /public/export_clients.php
 * Clients CSV Export (filters + sorting)
 * UI text: English only; Comments: Bangla
 */
declare(strict_types=1);

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

/* (বাংলা) কলাম আছে কি না চেকার */
if (!function_exists('hcol')) {
  function hcol(PDO $pdo, string $tbl, string $col): bool {
    $st = $pdo->prepare("SHOW COLUMNS FROM `$tbl` LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetchColumn();
  }
}

/* ============== Inputs ============== */
$search     = trim($_GET['search'] ?? '');
$status     = trim($_GET['status'] ?? ''); // active/inactive/online/offline
$is_left    = isset($_GET['is_left']) ? trim($_GET['is_left']) : ''; // '0' or '1'
$package    = trim($_GET['package'] ?? '');
$router     = trim($_GET['router'] ?? '');
$area       = trim($_GET['area'] ?? '');
$join_from  = trim($_GET['join_from'] ?? '');
$join_to    = trim($_GET['join_to'] ?? '');
$exp_from   = trim($_GET['exp_from'] ?? '');
$exp_to     = trim($_GET['exp_to'] ?? '');

$sort = strtolower($_GET['sort'] ?? 'name');
$dir  = strtolower($_GET['dir'] ?? 'asc');

$allowedSort = [
  'name'         => 'c.name',
  'pppoe_id'     => 'c.pppoe_id',
  'area'         => 'c.area',
  'package'      => 'p.name',
  'router'       => 'r.name',
  'join_date'    => 'c.join_date',
  'monthly_bill' => 'c.monthly_bill',
  'status'       => 'c.status',
  'is_left'      => 'c.is_left',
  'online'       => 'c.is_online',
];
$sortSql = $allowedSort[$sort] ?? 'c.name';
$dirSql  = ($dir === 'desc') ? 'DESC' : 'ASC';

/* (বাংলা) তারিখ ভ্যালিডেশন (YYYY-MM-DD) */
$re_date = '/^\d{4}-\d{2}-\d{2}$/';
if (!preg_match($re_date, $join_from)) $join_from = '';
if (!preg_match($re_date, $join_to))   $join_to   = '';
if (!preg_match($re_date, $exp_from))  $exp_from  = '';
if (!preg_match($re_date, $exp_to))    $exp_to    = '';

$pdo = db();
$has_ledger = hcol($pdo, 'clients', 'ledger_balance');

/* ============== SQL Build ============== */
$ledgerExpr = $has_ledger ? "c.ledger_balance" : "NULL AS ledger_balance";

$sql = "SELECT
          c.id, c.client_code, c.pppoe_id, c.name, c.mobile, c.email, c.address, c.area,
          c.status, c.is_left, c.left_at, c.package_id, c.router_id, c.is_online,
          c.join_date, c.expiry_date, c.monthly_bill, c.last_payment_date,
          c.payment_status, c.payment_method, c.remarks, c.onu_mac, c.onu_model, c.ip_address,
          $ledgerExpr,
          p.name AS package_name,
          r.name AS router_name
        FROM clients c
        LEFT JOIN packages p ON p.id = c.package_id
        LEFT JOIN routers  r ON r.id = c.router_id
        WHERE COALESCE(c.is_deleted,0) = 0";

$params = [];

/* Basic search */
if ($search !== '') {
  $sql .= " AND (c.name LIKE ? OR c.pppoe_id LIKE ? OR c.mobile LIKE ? OR c.client_code LIKE ?)";
  $like = "%$search%";
  array_push($params, $like, $like, $like, $like);
}

/* Status quick filters */
if ($status === 'active' || $status === 'inactive') {
  $sql .= " AND c.status = ?";
  $params[] = $status;
} elseif ($status === 'online') {
  $sql .= " AND c.is_online = 1";
} elseif ($status === 'offline') {
  $sql .= " AND c.is_online = 0";
}

/* Left filter */
if ($is_left !== '' && ($is_left === '0' || $is_left === '1')) {
  $sql .= " AND c.is_left = ?";
  $params[] = (int)$is_left;
}

/* Advanced filters */
if ($package !== '') {
  $sql .= " AND c.package_id = ?";
  $params[] = (int)$package;
}
if ($router !== '') {
  $sql .= " AND c.router_id = ?";
  $params[] = (int)$router;
}
if ($area !== '') {
  $sql .= " AND c.area = ?";
  $params[] = $area;
}

/* Date ranges */
if ($join_from !== '') { $sql .= " AND c.join_date >= ?";   $params[] = $join_from; }
if ($join_to   !== '') { $sql .= " AND c.join_date <= ?";   $params[] = $join_to;   }
if ($exp_from  !== '') { $sql .= " AND c.expiry_date >= ?"; $params[] = $exp_from;  }
if ($exp_to    !== '') { $sql .= " AND c.expiry_date <= ?"; $params[] = $exp_to;    }

$sql .= " ORDER BY {$sortSql} {$dirSql}, c.id ASC";

/* ============== Output headers ============== */
$filename = 'clients_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/* (বাংলা) UTF-8 BOM → Excel-এ Unicode ঠিক দেখায় */
echo "\xEF\xBB\xBF";

/* ============== Stream CSV ============== */
$out = fopen('php://output', 'w');

/* Header row */
$headers = [
  'ID','Client Code','PPPoE ID','Name','Mobile','Email','Address','Area',
  'Status','Online','Is Left','Left At','Package','Router',
  'Join Date','Expiry Date','Monthly Bill','Ledger Balance','Last Payment Date',
  'Payment Status','Payment Method','ONU MAC','ONU Model','IP Address','Remarks'
];
fputcsv($out, $headers);

/* Data rows (streaming) */
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $row = [
    $r['id'] ?? '',
    $r['client_code'] ?? '',
    $r['pppoe_id'] ?? '',
    $r['name'] ?? '',
    $r['mobile'] ?? '',
    $r['email'] ?? '',
    $r['address'] ?? '',
    $r['area'] ?? '',
    $r['status'] ?? '',
    ((int)($r['is_online'] ?? 0) === 1) ? 'Online' : 'Offline',
    (string)($r['is_left'] ?? ''),
    $r['left_at'] ?? '',
    $r['package_name'] ?? '',
    $r['router_name'] ?? '',
    $r['join_date'] ?? '',
    $r['expiry_date'] ?? '',
    $r['monthly_bill'] ?? '',
    $r['ledger_balance'] ?? '',
    $r['last_payment_date'] ?? '',
    $r['payment_status'] ?? '',
    $r['payment_method'] ?? '',
    $r['onu_mac'] ?? '',
    $r['onu_model'] ?? '',
    $r['ip_address'] ?? '',
    $r['remarks'] ?? '',
  ];
  fputcsv($out, $row);
}
fclose($out);
exit;
