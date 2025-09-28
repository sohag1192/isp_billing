<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice PDF</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
        .invoice-box { padding: 15px; }
        .table th, .table td { padding: 6px; }
        .table thead th { background-color: #f8f9fa; }
    </style>
</head>
<body>
<div class="invoice-box">
    <h4>Invoice #<?= htmlspecialchars($invoice['invoice_number']) ?></h4>
    <p><strong>Date:</strong> <?= htmlspecialchars($invoice['invoice_date']) ?><br>
    <strong>Due Date:</strong> <?= htmlspecialchars($invoice['due_date']) ?></p>

    <h5>Bill To:</h5>
    <p>
        <?= htmlspecialchars($invoice['client_name']) ?><br>
        <?= nl2br(htmlspecialchars($invoice['address'])) ?><br>
        <?= htmlspecialchars($invoice['mobile']) ?>
    </p>

    <h5>Items</h5>
    <table class="table table-bordered">
        <thead>
            <tr>
                <th>Description</th>
                <th>Qty</th>
                <th>Unit Price</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $it): ?>
            <tr>
                <td><?= htmlspecialchars($it['description']) ?></td>
                <td><?= htmlspecialchars($it['quantity']) ?></td>
                <td class="text-end"><?= number_format($it['unit_price'], 2) ?></td>
                <td class="text-end"><?= number_format($it['total'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr>
                <th colspan="3" class="text-end">Subtotal</th>
                <td class="text-end"><?= number_format($invoice['subtotal'], 2) ?></td>
            </tr>
            <tr>
                <th colspan="3" class="text-end">Discount</th>
                <td class="text-end"><?= number_format($invoice['discount'], 2) ?></td>
            </tr>
            <tr class="table-primary">
                <th colspan="3" class="text-end">Total</th>
                <td class="text-end"><strong><?= number_format($invoice['total_amount'], 2) ?></strong></td>
            </tr>
        </tbody>
    </table>

    <p><strong>Status:</strong> <?= ucfirst($invoice['status']) ?></p>
</div>
</body>
</html>
