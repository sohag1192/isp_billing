<?php
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

$year = $_GET['year'] ?? date('Y');

// Total stats query
$sql = "SELECT 
            COUNT(i.id) AS total_invoices,
            SUM(i.total_amount) AS total_amount,
            SUM(COALESCE(p.paid_amount,0)) AS total_paid,
            SUM(i.total_amount - COALESCE(p.paid_amount,0)) AS total_due
        FROM invoices i
        LEFT JOIN (
            SELECT bill_id, SUM(amount) AS paid_amount
            FROM payments
            GROUP BY bill_id
        ) p ON i.id = p.bill_id
        WHERE YEAR(i.issue_date) = ?";
$stmt = db()->prepare($sql);
$stmt->execute([$year]);
$summary = $stmt->fetch(PDO::FETCH_ASSOC);

// Monthly breakdown
$sql_monthly = "SELECT 
                   MONTH(i.issue_date) AS month,
                   SUM(i.total_amount) AS total_amount,
                   SUM(COALESCE(p.paid_amount,0)) AS total_paid,
                   SUM(i.total_amount - COALESCE(p.paid_amount,0)) AS total_due
                FROM invoices i
                LEFT JOIN (
                    SELECT bill_id, SUM(amount) AS paid_amount
                    FROM payments
                    GROUP BY bill_id
                ) p ON i.id = p.bill_id
                WHERE YEAR(i.issue_date) = ?
                GROUP BY MONTH(i.issue_date)
                ORDER BY MONTH(i.issue_date)";
$stmt2 = db()->prepare($sql_monthly);
$stmt2->execute([$year]);
$monthly_rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Yearly Summary Report</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container mt-4">
    <h3 class="mb-3">Yearly Summary Report - <?=$year?></h3>

    <form method="get" class="row g-2 mb-3">
        <div class="col-md-3">
            <select name="year" class="form-control" onchange="this.form.submit()">
                <?php for($y = date('Y'); $y >= 2020; $y--): ?>
                    <option value="<?=$y?>" <?=$y==$year?'selected':''?>><?=$y?></option>
                <?php endfor; ?>
            </select>
        </div>
    </form>

    <div class="card mb-3">
        <div class="card-body">
            <h5>Overall Summary</h5>
            <p><strong>Total Invoices:</strong> <?=$summary['total_invoices'] ?? 0?></p>
            <p><strong>Total Amount:</strong> <?=number_format($summary['total_amount'] ?? 0, 2)?></p>
            <p class="text-success"><strong>Total Paid:</strong> <?=number_format($summary['total_paid'] ?? 0, 2)?></p>
            <p class="text-danger"><strong>Total Due:</strong> <?=number_format($summary['total_due'] ?? 0, 2)?></p>
        </div>
    </div>

    <h5>Monthly Breakdown</h5>
    <table class="table table-bordered table-striped bg-white">
        <thead>
            <tr>
                <th>Month</th>
                <th>Total Amount</th>
                <th>Paid</th>
                <th>Due</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($monthly_rows): foreach($monthly_rows as $r): ?>
                <tr>
                    <td><?=date('F', mktime(0,0,0,$r['month'], 1))?></td>
                    <td><?=number_format($r['total_amount'], 2)?></td>
                    <td class="text-success"><?=number_format($r['total_paid'], 2)?></td>
                    <td class="text-danger"><?=number_format($r['total_due'], 2)?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="4" class="text-center">No data found</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="mt-2">
        <button class="btn btn-success btn-sm" onclick="window.print()">Print</button>
        <button class="btn btn-info btn-sm" onclick="exportTableToCSV('yearly_summary_<?=$year?>.csv')">Export CSV</button>
    </div>
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
