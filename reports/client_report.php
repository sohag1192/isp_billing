<?php
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

// Client লিস্ট লোড
$clients = db()->query("SELECT id, name FROM clients WHERE is_deleted = 0 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$selected_client = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;

$invoices = [];
$payments = [];
$total_invoice = 0;
$total_paid = 0;

if ($selected_client > 0) {
    // Invoice ডাটা
    $stmt = db()->prepare("SELECT id, invoice_no, invoice_date, total_amount, status FROM invoices WHERE client_id = ? ORDER BY invoice_date ASC");
    $stmt->execute([$selected_client]);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Payment ডাটা
    $stmt2 = db()->prepare("SELECT id, payment_date, amount, method FROM payments WHERE client_id = ? ORDER BY payment_date ASC");
    $stmt2->execute([$selected_client]);
    $payments = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    foreach ($invoices as $inv) {
        $total_invoice += $inv['total_amount'];
    }
    foreach ($payments as $pay) {
        $total_paid += $pay['amount'];
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Client-wise Report</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<div class="container mt-4">
    <h3 class="mb-4">📑 Client-wise Report</h3>

    <form method="GET" class="row g-3 mb-4">
        <div class="col-md-6">
            <label class="form-label">Select Client</label>
            <select name="client_id" class="form-select" onchange="this.form.submit()">
                <option value="">-- Select --</option>
                <?php foreach ($clients as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $selected_client == $c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <?php if ($selected_client > 0): ?>
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">Invoice List</div>
            <div class="card-body p-0">
                <table class="table table-striped table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Invoice No</th>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($invoices) > 0): ?>
                            <?php foreach ($invoices as $i => $inv): ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <td><?= htmlspecialchars($inv['invoice_no']) ?></td>
                                    <td><?= htmlspecialchars($inv['invoice_date']) ?></td>
                                    <td><?= number_format($inv['total_amount'], 2) ?></td>
                                    <td><?= ucfirst($inv['status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="text-center text-muted">No invoices found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header bg-success text-white">Payment List</div>
            <div class="card-body p-0">
                <table class="table table-striped table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Method</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($payments) > 0): ?>
                            <?php foreach ($payments as $p => $pay): ?>
                                <tr>
                                    <td><?= $p + 1 ?></td>
                                    <td><?= htmlspecialchars($pay['payment_date']) ?></td>
                                    <td><?= number_format($pay['amount'], 2) ?></td>
                                    <td><?= ucfirst($pay['method']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4" class="text-center text-muted">No payments found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="alert alert-info">
            <strong>Total Invoice:</strong> <?= number_format($total_invoice, 2) ?> |
            <strong>Total Paid:</strong> <?= number_format($total_paid, 2) ?> |
            <strong>Due:</strong> <?= number_format($total_invoice - $total_paid, 2) ?>
        </div>

        <div class="mt-3">
            <button class="btn btn-outline-primary" onclick="window.print()">🖨 Print</button>
            <a href="export_client_report.php?client_id=<?= $selected_client ?>" class="btn btn-outline-success">⬇ Export CSV</a>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
