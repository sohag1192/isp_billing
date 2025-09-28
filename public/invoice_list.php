<?php
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

// ইনভয়েস লিস্ট
$stmt = db()->prepare("
    SELECT invoices.*, clients.name AS client_name 
    FROM invoices 
    LEFT JOIN clients ON invoices.client_id = clients.id 
    ORDER BY invoices.id DESC
");
$stmt->execute();
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../app/header.php';
?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4>Invoice List</h4>
        <a href="invoice_add.php" class="btn btn-primary">+ New Invoice</a>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <?php if (count($invoices) > 0): ?>
                <table class="table table-bordered m-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Client</th>
                            <th>Total</th>
                            <th>Paid</th>
                            <th>Status</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($invoices as $inv): ?>
                        <tr>
                            <td><?= $inv['id'] ?></td>
                            <td><?= htmlspecialchars($inv['client_name']) ?></td>
                            <td><?= number_format($inv['total_amount'], 2) ?></td>
                            <td><?= number_format($inv['paid_amount'], 2) ?></td>
                            <td><?= ucfirst($inv['status']) ?></td>
                            <td><?= $inv['created_at'] ?></td>
                            <td>
                                <a href="invoice_view.php?id=<?= $inv['id'] ?>" class="btn btn-sm btn-info">
                                    <i class="fa fa-eye"></i> View
                                </a>
                                <a href="payment_add.php?invoice_id=<?= $inv['id'] ?>" class="btn btn-sm btn-success">
                                    <i class="fa fa-plus"></i> Add Payment
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="p-3 mb-0 text-muted">No invoices found.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../app/footer.php'; ?>
