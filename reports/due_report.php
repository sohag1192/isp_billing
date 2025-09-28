<?php
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

// Filters
$client_search = trim($_GET['client'] ?? '');
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';

$sql = "SELECT i.id, i.invoice_number, c.name AS client_name, i.total_amount, 
               COALESCE(SUM(p.amount),0) AS paid_amount, 
               (i.total_amount - COALESCE(SUM(p.amount),0)) AS due_amount,
               i.issue_date
        FROM invoices i
        LEFT JOIN clients c ON i.client_id = c.id
        LEFT JOIN payments p ON i.id = p.bill_id
        WHERE (i.status = 'unpaid' OR i.status = 'partial')";

$params = [];

if ($client_search != '') {
    $sql .= " AND c.name LIKE ?";
    $params[] = "%$client_search%";
}
if ($from_date != '') {
    $sql .= " AND i.issue_date >= ?";
    $params[] = $from_date . " 00:00:00";
}
if ($to_date != '') {
    $sql .= " AND i.issue_date <= ?";
    $params[] = $to_date . " 23:59:59";
}

$sql .= " GROUP BY i.id ORDER BY i.issue_date DESC";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Due Report</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container mt-4">
    <h3 class="mb-3">Due Report</h3>

    <form class="row g-2 mb-3">
        <div class="col-md-3">
            <input type="text" name="client" placeholder="Client Name" class="form-control" value="<?=htmlspecialchars($client_search)?>">
        </div>
        <div class="col-md-2">
            <input type="date" name="from_date" class="form-control" value="<?=htmlspecialchars($from_date)?>">
        </div>
        <div class="col-md-2">
            <input type="date" name="to_date" class="form-control" value="<?=htmlspecialchars($to_date)?>">
        </div>
        <div class="col-md-3">
            <button class="btn btn-primary">Filter</button>
            <a href="due_report.php" class="btn btn-secondary">Reset</a>
        </div>
    </form>

    <div class="mb-2">
        <button class="btn btn-success btn-sm" onclick="window.print()">Print</button>
        <button class="btn btn-info btn-sm" onclick="exportTableToCSV('due_report.csv')">Export CSV</button>
    </div>

    <table class="table table-bordered table-striped bg-white">
        <thead>
            <tr>
                <th>#</th>
                <th>Client</th>
                <th>Invoice No</th>
                <th>Total</th>
                <th>Paid</th>
                <th>Due</th>
                <th>Issue Date</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($rows): $i=1; foreach($rows as $r): ?>
                <tr>
                    <td><?=$i++?></td>
                    <td><?=htmlspecialchars($r['client_name'])?></td>
                    <td><?=htmlspecialchars($r['invoice_number'])?></td>
                    <td><?=number_format($r['total_amount'],2)?></td>
                    <td><?=number_format($r['paid_amount'],2)?></td>
                    <td class="text-danger fw-bold"><?=number_format($r['due_amount'],2)?></td>
                    <td><?=htmlspecialchars($r['issue_date'])?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="7" class="text-center">No records found</td></tr>
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
