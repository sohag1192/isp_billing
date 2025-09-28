<?php
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$bill_id = (int)($_GET['bill_id'] ?? $_POST['bill_id'] ?? 0);
if ($bill_id <= 0) {
  header("Location: /public/invoices.php");
  exit;
}

/* ===== Load bill + client ===== */
$st = db()->prepare("
  SELECT b.*, c.name AS client_name, c.client_code
  FROM bills b
  LEFT JOIN clients c ON c.id = b.client_id
  WHERE b.id = ?
  LIMIT 1
");
$st->execute([$bill_id]);
$bill = $st->fetch(PDO::FETCH_ASSOC);
if (!$bill) {
  header("Location: /public/invoices.php");
  exit;
}

$success_msg = $error_msg = '';
$receipt_url = '';

/* ===== If redirected after success (PRG) ===== */
if (isset($_GET['paid']) && (int)$_GET['paid'] === 1) {
  $pid = (int)($_GET['pid'] ?? 0);
  if ($pid > 0) {
    $success_msg = "পেমেন্ট সেভ হয়েছে।";
    $receipt_url = "/public/payment_receipt.php?id={$pid}";
  }
}

/* ===== POST: Save payment ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $amount   = (float)($_POST['amount']   ?? 0);
  $discount = (float)($_POST['discount'] ?? 0);
  $method   = trim($_POST['method'] ?? 'Cash');
  $note     = trim($_POST['note']   ?? '');
  $paid_at  = trim($_POST['paid_at'] ?? date('Y-m-d'));

  if ($amount < 0 || $discount < 0) {
    $error_msg = "টাকার পরিমাণ/ডিসকাউন্ট ঋণাত্মক হতে পারে না।";
  } elseif (($amount + $discount) <= 0) {
    $error_msg = "কমপক্ষে Amount বা Discount এর যেকোনো একটিতে মান দিন।";
  } else {
    try {
      db()->beginTransaction();

      // Insert payment
      $ins = db()->prepare("
        INSERT INTO payments (bill_id, amount, discount, method, note, paid_at, created_at)
        VALUES (:bill_id, :amount, :discount, :method, :note, :paid_at, :created_at)
      ");
      $ok = $ins->execute([
        ':bill_id'   => $bill_id,
        ':amount'    => $amount,
        ':discount'  => $discount,
        ':method'    => $method,
        ':note'      => $note ?: null,
        ':paid_at'   => $paid_at ?: date('Y-m-d'),
        ':created_at'=> date('Y-m-d H:i:s'),
      ]);
      if (!$ok) throw new Exception('Payment insert failed');

      $payment_id  = (int) db()->lastInsertId();

      // Recalculate bill totals
      $new_paid     = (float)$bill['paid_total']     + $amount;
      $new_discount = (float)$bill['discount_total'] + $discount;
      $new_due      = (float)$bill['total'] - $new_paid - $new_discount;
      if ($new_due < 0) $new_due = 0;
      $new_status   = ($new_due <= 0.00001) ? 'paid' : 'due';

      $up = db()->prepare("
        UPDATE bills
        SET paid_total = :paid_total,
            discount_total = :discount_total,
            due_total = :due_total,
            status = :status,
            updated_at = :updated_at
        WHERE id = :id
      ");
      $up->execute([
        ':paid_total'     => $new_paid,
        ':discount_total' => $new_discount,
        ':due_total'      => $new_due,
        ':status'         => $new_status,
        ':updated_at'     => date('Y-m-d H:i:s'),
        ':id'             => $bill_id,
      ]);

      db()->commit();

      // ✅ PRG: redirect so the Print Receipt button shows every time
      header("Location: /public/payment_add.php?bill_id={$bill_id}&paid=1&pid={$payment_id}");
      exit;

    } catch (Exception $e) {
      if (db()->inTransaction()) db()->rollBack();
      $error_msg = "সেভ করা যায়নি: " . $e->getMessage();
    }
  }
}

include __DIR__ . '/../partials/partials_header.php';
?>

<div class="container-fluid py-3">
  <div class="d-flex align-items-center gap-2 mb-3">
    <h5 class="mb-0">
      <i class="bi bi-cash-coin"></i> পেমেন্ট অ্যাড
    </h5>
    <div class="ms-auto">
      <a href="/public/invoices.php?view=<?= (int)$bill['id'] ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left"></i> ইনভয়েসে ফেরত যান
      </a>
    </div>
  </div>

  <?php if ($success_msg): ?>
    <div class="alert alert-success d-flex justify-content-between align-items-center">
      <div><?= h($success_msg) ?></div>
      <?php if ($receipt_url): ?>
        <a class="btn btn-success btn-sm" target="_blank" href="<?= h($receipt_url) ?>">
          <i class="bi bi-printer"></i> Print Receipt
        </a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($error_msg): ?>
    <div class="alert alert-danger"><?= h($error_msg) ?></div>
  <?php endif; ?>

  <!-- Bill Snapshot -->
  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <div class="row g-2">
        <div class="col-12 col-md-4">
          <div class="small text-muted">ক্লায়েন্ট</div>
          <div class="fw-semibold"><?= h($bill['client_name'] ?? 'Unknown') ?> <span class="text-muted">(#<?= h($bill['client_code'] ?? '-') ?>)</span></div>
        </div>
        <div class="col-6 col-md-2">
          <div class="small text-muted">মাস</div>
          <div class="fw-semibold"><?= h(($bill['bill_month'] ?? '').'/'.($bill['bill_year'] ?? '')) ?></div>
        </div>
        <div class="col-6 col-md-2">
          <div class="small text-muted">টোটাল</div>
          <div class="fw-semibold"><?= number_format((float)$bill['total'], 2) ?></div>
        </div>
        <div class="col-6 col-md-2">
          <div class="small text-muted">পেইড</div>
          <div class="fw-semibold"><?= number_format((float)$bill['paid_total'], 2) ?></div>
        </div>
        <div class="col-6 col-md-2">
          <div class="small text-muted">ডিউ</div>
          <div class="fw-semibold <?= ((float)$bill['due_total']>0?'text-danger':'text-success') ?>">
            <?= number_format((float)$bill['due_total'], 2) ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Payment Form -->
  <div class="card shadow-sm">
    <div class="card-header bg-light">
      <strong>নতুন পেমেন্ট</strong>
    </div>
    <div class="card
