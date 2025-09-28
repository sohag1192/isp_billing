<?php
require_once __DIR__ . '/../app/require_login.php';
require_admin();
require_once __DIR__ . '/../app/db.php';

$id = $_GET['id'] ?? null;
$success = $error = '';

if (!$id) {
    die("Invalid request");
}

$stmt = db()->prepare("SELECT * FROM payments WHERE id = ?");
$stmt->execute([$id]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    die("Payment not found");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = $_POST['amount'];
    $payment_date = $_POST['payment_date'];
    $method = $_POST['payment_method'];
    $txn_id = $_POST['transaction_id'] ?? null;
    $notes = $_POST['notes'] ?? null;

    try {
        db()->beginTransaction();

        // পেমেন্ট আপডেট
        db()->prepare("UPDATE payments SET amount = ?, payment_date = ?, payment_method = ?, transaction_id = ?, notes = ? WHERE id = ?")
           ->execute([$amount, $payment_date, $method, $txn_id, $notes, $id]);

        // Paid Amount আবার হিসাব করো
        $stmt2 = db()->prepare("SELECT SUM(amount) as paid_sum FROM payments WHERE invoice_id = ?");
        $stmt2->execute([$payment['invoice_id']]);
        $paid_sum = $stmt2->fetchColumn() ?? 0;

        // ইনভয়েস টোটাল আনো
        $stmt3 = db()->prepare("SELECT total_amount FROM invoices WHERE id = ?");
        $stmt3->execute([$payment['invoice_id']]);
        $total_amount = $stmt3->fetchColumn();

        // নতুন স্ট্যাটাস ঠিক করো
        $new_status = 'unpaid';
        if ($paid_sum >= $total_amount) {
            $new_status = 'paid';
        } elseif ($paid_sum > 0 && $paid_sum < $total_amount) {
            $new_status = 'partial';
        }

        // ইনভয়েস আপডেট করো
        db()->prepare("UPDATE invoices SET paid_amount = ?, status = ? WHERE id = ?")
           ->execute([$paid_sum, $new_status, $payment['invoice_id']]);

        db()->commit();

        $success = "Payment updated successfully.";
    } catch (Exception $e) {
        db()->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

?>
<?php include __DIR__ . '/../app/header.php'; ?>
<div class="container mt-4">
    <h4>Edit Payment</h4>
    <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

    <form method="post">
        <div class="mb-3">
            <label>Payment Date</label>
            <input type="date" name="payment_date" class="form-control" value="<?= htmlspecialchars($payment['payment_date']) ?>" required>
        </div>
        <div class="mb-3">
            <label>Amount</label>
            <input type="number" step="0.01" name="amount" class="form-control" value="<?= htmlspecialchars($payment['amount']) ?>" required>
        </div>
        <div class="mb-3">
            <label>Payment Method</label>
            <select name="payment_method" class="form-control" required>
                <option value="cash" <?= $payment['payment_method'] == 'cash' ? 'selected' : '' ?>>Cash</option>
                <option value="bank" <?= $payment['payment_method'] == 'bank' ? 'selected' : '' ?>>Bank</option>
                <option value="bkash" <?= $payment['payment_method'] == 'bkash' ? 'selected' : '' ?>>bKash</option>
                <option value="nagad" <?= $payment['payment_method'] == 'nagad' ? 'selected' : '' ?>>Nagad</option>
                <option value="rocket" <?= $payment['payment_method'] == 'rocket' ? 'selected' : '' ?>>Rocket</option>
                <option value="sslcommerz" <?= $payment['payment_method'] == 'sslcommerz' ? 'selected' : '' ?>>SSLCommerz</option>
            </select>
        </div>
        <div class="mb-3">
            <label>Transaction ID</label>
            <input type="text" name="transaction_id" class="form-control" value="<?= htmlspecialchars($payment['transaction_id']) ?>">
        </div>
        <div class="mb-3">
            <label>Notes</label>
            <textarea name="notes" class="form-control"><?= htmlspecialchars($payment['notes']) ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Update Payment</button>
    </form>
</div>
<?php include __DIR__ . '/../app/footer.php'; ?>
