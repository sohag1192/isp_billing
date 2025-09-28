<?php
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

$month = $_GET['month'] ?? '';
$status = $_GET['status'] ?? '';
$chart = isset($_GET['chart']);

if($chart){
    $sql = "SELECT payment_method, SUM(amount) as total FROM invoices WHERE DATE_FORMAT(date, '%Y-%m') = ? GROUP BY payment_method";
    $stmt = db()->prepare($sql);
    $stmt->execute([$month]);
    $labels = [];
    $data = [];
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
        $labels[] = $row['payment_method'];
        $data[] = $row['total'];
    }
    echo json_encode(['labels'=>$labels,'data'=>$data]);
    exit;
}

$sql = "SELECT invoice_id, client_name as client, date, amount, status, payment_method as method FROM invoices WHERE DATE_FORMAT(date, '%Y-%m') = ?";
$params = [$month];
if($status){
    $sql .= " AND status = ?";
    $params[] = $status;
}
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['data'=>$rows]);
?>
