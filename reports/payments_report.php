<?php
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

// Filters
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$client_search = trim($_GET['client'] ?? '');
$method = $_GET['method'] ?? '';

$sql = "SELECT p.id, c.name AS client_name, i.invoice_number, p.amount, p.payment_date, p.payment_method
        FROM payments p
        LEFT JOIN invoices i ON p.bill_id = i.id
        LEFT JOIN clients c ON i.client_id = c.id
        WHERE 1=1";
$params = [];

if ($from_date != '') {
    $sql .= " AND p.payment_date >= ?";
    $params[] = $from_date . " 00:00:00";
}
if ($to_date != '') {
    $sql .= " AND p.payment_date <= ?";
    $params[] = $to_date . " 23:59:59";
}
if ($client_search != '') {
    $sql .= " AND c.name LIKE ?";
    $params[] = "%$client_search%";
}
if ($method != '') {
    $sql .= " AND p.payment_method = ?";
    $params[] = $method;
}

$sql .= " ORDER BY p.payment_date DESC";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Payments Report</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container mt-4">
    <h3 class="mb-3">Payments Report</h3>

    <form class="row g-2 mb-3">
        <div class="col-md-2">
            <input type="date" name="from_date" class="form-control" value="<?=htmlspecialchars($from_date)?>">
        </div>
        <div class="col-md-2">
            <input type="date" name="to_date" class="form-control" value="<?=htmlspecialchars($to_date)?>">
        </div>
        <div class="col-md-3">
            <input type="text" name="client" placeholder="Client Name" class="form-control" value="<?=htmlspecialchars($client_search)?>">
        </div>
        <div class="col-md-2">
            <select name="method" class="form-control">
                <option value="">All Methods</option>
                <option value="cash" <?=($method=='cash'?'selected':'')?>>Cash</option>
                <option value="bank" <?=($method=='bank'?'selected':'')?>>Bank</option>
                <option value="bkash" <?=($method=='bkash'?'selected':'')?>>Bkash</option>
                <option value="nagad" <?=($method=='nagad'?'selected':'')?>>Nagad</option>
                <option value="online" <?=($method=='online'?'selected':'')?>>Online</option>
            </select>
        </div>
        <div class="col-md-3">
            <button class="btn btn-primary">Filter</button>
            <a href="payments_report.php" class="btn btn-secondary">Reset</a>
        </div>
    </form>

    <div class="mb-2">
        <button class="btn btn-success btn-sm" onclick="window.print()">Print</button>
        <button class="btn btn-info btn-sm" onclick="exportTableToCSV('payments_report.csv')">Export CSV</button>
    </div>

    <table class="table table-bordered table-striped bg-white">
        <thead>
            <tr>
                <th>#</th>
                <th>Client</th>
                <th>Invoice No</th>
                <th>Amount</th>
                <th>Payment Date</th>
                <th>Method</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($rows): $i=1; foreach($rows as $r): ?>
                <tr>
                    <td><?=$i++?></td>
                    <td><?=htmlspecialchars($r['client_name'])?></td>
                    <td><?=htmlspecialchars($r['invoice_number'])?></td>
                    <td><?=number_format($r['amount'],2)?></td>
                    <td><?=htmlspecialchars($r['payment_date'])?></td>
                    <td><?=ucfirst($r['payment_method'])?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="6" class="text-center">No records found</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
function exportTableToCSV(filename) {
    var csv = [];
    var rows = document.querySelectorAll("table tr");
    for (var i = 0; i < rows.length; i++) {
        var row = [], cols = rows[i].querySelectorAll("td, th");
        for (var j = 0; j < cols.length; j++)
            row.push('"' + cols[j].innerText + '"');
        csv.push(row.join(","));
    }
    var csvFile = new Blob([csv.join("\n")], { type: "text/csv" });
    var downloadLink = document.createElement("a");
    downloadLink.download = filename;
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.click();
}
</script>
</body>
</html>
