<?php
/**
 * Export Invoices CSV
 * Filters (GET):
 *   search (client name / pppoe_id / client_code),
 *   month (1-12), year (YYYY),
 *   status (Paid/Unpaid/Partial)  ← computed from payments
 *   router, package, area, is_left
 *
 * বাংলা কমেন্ট: ইনভয়েস + ক্লায়েন্ট জয়ন, পেমেন্ট সাম করে স্ট্যাটাস নির্ণয় করে CSV দেয়।
 */
declare(strict_types=1);
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

function csv_escape($v) {
    $v = (string)$v;
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    $v = str_replace('"', '""', $v);
    return '"' . $v . '"';
}

$search  = trim($_GET['search'] ?? '');
$month   = trim($_GET['month'] ?? '');
$year    = trim($_GET['year'] ?? '');
$status  = trim($_GET['status'] ?? ''); // Paid/Unpaid/Partial (computed)
$router  = trim($_GET['router'] ?? '');
$package = trim($_GET['package'] ?? '');
$area    = trim($_GET['area'] ?? '');
$is_left = isset($_GET['is_left']) ? trim($_GET['is_left']) : ''; // '0' or '1'

// Base: invoices + clients + packages + routers
$sql = "SELECT i.id, i.client_id, i.month, i.year, i.amount, i.payable, i.due, i.status AS inv_status,
               i.invoice_no, i.created_at,
               c.client_code, c.pppoe_id, c.name AS client_name, c.mobile, c.area, c.is_left,
               p.name AS package_name,
               r.name AS router_name
        FROM invoices i
        INNER JOIN clients c ON c.id = i.client_id
        LEFT JOIN packages p ON p.id = c.package_id
        LEFT JOIN routers  r ON r.id = c.router_id
        WHERE 1=1";
$params = [];

if ($search !== '') {
    $like = "%$search%";
    $sql .= " AND (c.name LIKE ? OR c.pppoe_id LIKE ? OR c.client_code LIKE ?)";
    array_push($params, $like, $like, $like);
}
if ($month !== '') { $sql .= " AND i.month = ?"; $params[] = (int)$month; }
if ($year  !== '') { $sql .= " AND i.year  = ?"; $params[] = (int)$year; }
if ($router  !== '') { $sql .= " AND c.router_id = ?";  $params[] = (int)$router; }
if ($package !== '') { $sql .= " AND c.package_id = ?"; $params[] = (int)$package; }
if ($area    !== '') { $sql .= " AND c.area = ?";       $params[] = $area; }
if ($is_left !== '' && ($is_left === '0' || $is_left === '1')) {
    $sql .= " AND c.is_left = ?"; $params[] = (int)$is_left;
}

// Fetch invoices
$stmt = db()->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Aggregate payments for these invoice IDs
$ids = array_column($invoices, 'id');
$paidMap = [];
if ($ids) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $ps = db()->prepare("SELECT bill_id, SUM(amount + IFNULL(discount,0)) AS paid_sum
                         FROM payments
                         WHERE bill_id IN ($ph)
                         GROUP BY bill_id");
    $ps->execute($ids);
    while ($row = $ps->fetch(PDO::FETCH_ASSOC)) {
        $paidMap[$row['bill_id']] = (float)$row['paid_sum'];
    }
}

// Filter by computed status if requested
if ($status !== '') {
    $status = ucfirst(strtolower($status)); // normalize
    $invoices = array_values(array_filter($invoices, function($inv) use ($paidMap, $status) {
        $payable = (float)($inv['payable'] ?? 0);
        $paid    = (float)($paidMap[$inv['id']] ?? 0);
        if ($paid >= $payable && $payable > 0) {
            $s = 'Paid';
        } elseif ($paid > 0 && $paid < $payable) {
            $s = 'Partial';
        } else {
            // payable==0 হলে Paid ধরা যায়, না হলে Unpaid
            $s = ($payable == 0) ? 'Paid' : 'Unpaid';
        }
        return $s === $status;
    }));
}

// Output headers
$filename = 'invoices_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
echo "\xEF\xBB\xBF"; // UTF‑8 BOM

$headers = [
    'Invoice ID','Invoice No','Month','Year','Client ID','Client Code','PPPoE ID','Client Name','Mobile','Area',
    'Router','Package','Amount','Payable','Paid','Due (DB)','Status (Computed)','Created At'
];
echo implode(',', array_map('csv_escape', $headers)) . "\n";

foreach ($invoices as $inv) {
    $payable = (float)($inv['payable'] ?? 0);
    $paid    = (float)($paidMap[$inv['id']] ?? 0);
    if ($paid >= $payable && $payable > 0) {
        $statusComputed = 'Paid';
    } elseif ($paid > 0 && $paid < $payable) {
        $statusComputed = 'Partial';
    } else {
        $statusComputed = ($payable == 0) ? 'Paid' : 'Unpaid';
    }

    $line = [
        $inv['id'] ?? '',
        $inv['invoice_no'] ?? '',
        $inv['month'] ?? '',
        $inv['year'] ?? '',
        $inv['client_id'] ?? '',
        $inv['client_code'] ?? '',
        $inv['pppoe_id'] ?? '',
        $inv['client_name'] ?? '',
        $inv['mobile'] ?? '',
        $inv['area'] ?? '',
        $inv['router_name'] ?? '',
        $inv['package_name'] ?? '',
        $inv['amount'] ?? '',
        $inv['payable'] ?? '',
        number_format((float)($paid), 2, '.', ''),
        $inv['due'] ?? '',
        $statusComputed,
        $inv['created_at'] ?? '',
    ];
    echo implode(',', array_map('csv_escape', $line)) . "\n";
}
exit;
