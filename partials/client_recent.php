<?php
// /partials/client_recent.php
// Bengali notes: Client View পেজে Recent Invoices/Payments + inline Add Payment modal
// Code English; comments Bangla. Bootstrap 5 ব্যবহার।

declare(strict_types=1);
require_once __DIR__ . '/../app/db.php';

if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('fm')){ function fm($n){ return number_format((float)$n, 2, '.', ','); } }

if (!function_exists('col_exists')) {
  // বাংলা: টেবিলের কলাম আছে কিনা
  function col_exists(PDO $pdo, string $tbl, string $col): bool {
    try{ $st=$pdo->prepare("SHOW COLUMNS FROM `$tbl` LIKE ?"); $st->execute([$col]); return (bool)$st->fetchColumn(); }
    catch(Throwable $e){ return false; }
  }
}
if (!function_exists('tbl_exists')) {
  function tbl_exists(PDO $pdo, string $tbl): bool {
    try{ $st=$pdo->prepare("SHOW TABLES LIKE ?"); $st->execute([$tbl]); return (bool)$st->fetchColumn(); }
    catch(Throwable $e){ return false; }
  }
}

$pdo = db();

/* ---------- Resolve $client context ----------
   (বাংলা) parent পেজে থাকলে সেগুলোই; না থাকলে GET id দিয়ে লোড করি */
$client     = $client     ?? [];
$client_id  = isset($client_id) ? (int)$client_id : (int)($client['id'] ?? 0);
$pppoe_id   = $pppoe_id   ?? (string)($client['pppoe_id'] ?? '');
$clientName = (string)($client['name'] ?? 'Client');
$ledger     = isset($client['ledger_balance']) ? (float)$client['ledger_balance'] : null;

if ($client_id <= 0) {
  $cid = (int)($_GET['id'] ?? 0);
  if ($cid > 0) {
    $st = $pdo->prepare("SELECT * FROM clients WHERE id=? LIMIT 1");
    $st->execute([$cid]);
    $client = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $client_id  = (int)($client['id'] ?? 0);
    $pppoe_id   = (string)($client['pppoe_id'] ?? '');
    $clientName = (string)($client['name'] ?? 'Client');
    $ledger     = isset($client['ledger_balance']) ? (float)$client['ledger_balance'] : null;
  }
}

/* ---------- Invoices schema detect ---------- */
$has_invoices      = tbl_exists($pdo, 'invoices');
$inv_has_client_id = $has_invoices && col_exists($pdo, 'invoices', 'client_id');
$inv_has_number    = $has_invoices && col_exists($pdo, 'invoices', 'invoice_number');
$inv_has_total     = $has_invoices && col_exists($pdo, 'invoices', 'total');
$inv_has_amount    = $has_invoices && col_exists($pdo, 'invoices', 'amount');
$inv_has_payable   = $has_invoices && col_exists($pdo, 'invoices', 'payable');
$inv_has_status    = $has_invoices && col_exists($pdo, 'invoices', 'status');
$inv_has_month     = $has_invoices && col_exists($pdo, 'invoices', 'month');
$inv_has_year      = $has_invoices && col_exists($pdo, 'invoices', 'year');
$inv_has_bm        = $has_invoices && col_exists($pdo, 'invoices', 'billing_month'); // YYYY-MM
$inv_has_idate     = $has_invoices && col_exists($pdo, 'invoices', 'invoice_date');
$inv_has_created   = $has_invoices && col_exists($pdo, 'invoices', 'created_at');

$inv_date_col   = $inv_has_idate ? 'invoice_date' : ($inv_has_created ? 'created_at' : 'id');
$inv_amt_expr   = $inv_has_total ? 'total' : ($inv_has_amount ? 'amount' : ($inv_has_payable ? 'payable' : '0'));
$inv_month_expr = $inv_has_bm ? 'billing_month'
                : (($inv_has_year && $inv_has_month) ? "CONCAT(LPAD(`year`,4,'0'),'-',LPAD(`month`,2,'0'))" : "NULL");

/* ---------- Payments schema detect ---------- */
$has_payments      = tbl_exists($pdo, 'payments');
$pay_has_bill_id   = $has_payments && col_exists($pdo, 'payments', 'bill_id');
$pay_has_client_id = $has_payments && col_exists($pdo, 'payments', 'client_id');
$pay_has_amount    = $has_payments && col_exists($pdo, 'payments', 'amount');
$pay_has_method    = $has_payments && col_exists($pdo, 'payments', 'method');
$pay_has_txn       = $has_payments && col_exists($pdo, 'payments', 'txn_id');
$pay_has_discount  = $has_payments && col_exists($pdo, 'payments', 'discount');
$pay_has_paid_at   = $has_payments && col_exists($pdo, 'payments', 'paid_at');
$pay_has_created   = $has_payments && col_exists($pdo, 'payments', 'created_at');
$pay_date_col      = $pay_has_paid_at ? 'p.paid_at' : ($pay_has_created ? 'p.created_at' : 'p.id');

/* ---------- Recent Invoices ---------- */
$recent_invoices = [];
if ($client_id && $has_invoices && $inv_has_client_id) {
  $sql = "
    SELECT i.id,
           ".($inv_has_number ? "i.invoice_number," : "NULL AS invoice_number,")."
           {$inv_amt_expr} AS total_amount,
           ".($inv_has_status ? "i.status," : "NULL AS status,")."
           {$inv_month_expr} AS bill_month,
           i.{$inv_date_col} AS inv_date
    FROM invoices i
    WHERE i.client_id = ?
    ORDER BY i.{$inv_date_col} DESC, i.id DESC
    LIMIT 5
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$client_id]);
  $recent_invoices = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* ---------- Recent Payments ---------- */
$recent_payments = [];
if ($client_id && $has_payments && $pay_has_amount) {
  if ($pay_has_client_id) {
    $sql = "
      SELECT p.id,
             p.".($pay_has_paid_at ? "paid_at" : ($pay_has_created ? "created_at" : "id"))." AS pay_date,
             p.amount,
             ".($pay_has_discount ? "p.discount," : "0 AS discount,")."
             ".($pay_has_method ? "p.method," : "NULL AS method,")."
             ".($pay_has_txn ? "p.txn_id," : "NULL AS txn_id,")."
             ".($pay_has_bill_id ? "p.bill_id," : "NULL AS bill_id,")."
             NULL AS bill_month,
             NULL AS invoice_number
      FROM payments p
      WHERE p.client_id = ?
      ORDER BY {$pay_date_col} DESC, p.id DESC
      LIMIT 5
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$client_id]);
    $recent_payments = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } elseif ($pay_has_bill_id && $has_invoices) {
    $sql = "
      SELECT p.id,
             p.".($pay_has_paid_at ? "paid_at" : ($pay_has_created ? "created_at" : "id"))." AS pay_date,
             p.amount,
             ".($pay_has_discount ? "p.discount," : "0 AS discount,")."
             ".($pay_has_method ? "p.method," : "NULL AS method,")."
             ".($pay_has_txn ? "p.txn_id," : "NULL AS txn_id,")."
             p.bill_id,
             {$inv_month_expr} AS bill_month,
             ".($inv_has_number ? "i.invoice_number" : "NULL")." AS invoice_number
      FROM payments p
      INNER JOIN invoices i ON i.id = p.bill_id
      WHERE i.client_id = ?
      ORDER BY {$pay_date_col} DESC, p.id DESC
      LIMIT 5
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$client_id]);
    $recent_payments = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

/* ---------- Helpers ---------- */
$st_badge = function($status){
  $s = strtolower((string)$status);
  if ($s === 'paid') return '<span class="badge text-bg-success">Paid</span>';
  if ($s === 'unpaid') return '<span class="badge text-bg-danger">Unpaid</span>';
  if ($s === 'partial' || $s === 'partially_paid') return '<span class="badge text-bg-warning">Partial</span>';
  return $status ? '<span class="badge text-bg-secondary">'.h($status).'</span>' : '';
};

$ledgerBadge = function($v){
  if (!is_numeric($v)) return '';
  $v = (float)$v; // +ve=Due, -ve=Advance
  $cls = $v > 0 ? 'text-bg-danger' : ($v < 0 ? 'text-bg-success' : 'text-bg-secondary');
  $label = $v > 0 ? 'Due' : ($v < 0 ? 'Advance' : 'Settled');
  return '<span class="badge '.$cls.'">Ledger: '.$label.' '.fm(abs($v)).'</span>';
};

?>
<div class="row g-3 mt-3">
  <!-- ===== Recent Invoices ===== -->
  <div class="col-12 col-lg-6">
    <div class="card shadow-sm h-100">
      <div class="card-header d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-2">
          <strong>Recent Invoices</strong>
          <?php if ($ledger !== null): ?>
            <span class="ms-2"><?php echo $ledgerBadge($ledger); ?></span>
          <?php endif; ?>
        </div>
        <div class="d-flex gap-2">
          <!-- বাংলা: client ফোকাসড লিস্ট -->
          <a class="btn btn-sm btn-outline-primary" href="/public/invoices.php?search=<?php echo urlencode($pppoe_id ?: $clientName); ?>">Open list</a>
          <a class="btn btn-sm btn-outline-secondary" href="/public/billing.php?search=<?php echo urlencode($pppoe_id ?: $clientName); ?>">Billing</a>
        </div>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-sm table-striped mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th style="width:32%">Month / Date</th>
                <th style="width:18%">Invoice#</th>
                <th class="text-end" style="width:20%">Amount</th>
                <th style="width:18%">Status</th>
                <th class="text-center" style="width:12%">Action</th>
              </tr>
            </thead>
            <tbody>
            <?php if ($recent_invoices): foreach ($recent_invoices as $row):
              $monthLbl = $row['bill_month'] ?: '';
              $dateLbl  = $row['inv_date'] ? date('Y-m-d', strtotime((string)$row['inv_date'])) : '';
              $left     = $monthLbl ? h($monthLbl) : ($dateLbl ?: '-');
              $invNo    = $row['invoice_number'] ?: ('#'.(int)$row['id']);
              $amount   = (float)($row['total_amount'] ?? 0);
            ?>
              <tr>
                <td><?php echo $left, ($monthLbl && $dateLbl) ? ' <small class="text-muted">('.h($dateLbl).')</small>' : ''; ?></td>
                <td><?php echo h($invNo); ?></td>
                <td class="text-end"><?php echo fm($amount); ?></td>
                <td><?php echo $st_badge($row['status'] ?? null); ?></td>
                <td class="text-center">
                  <a class="btn btn-sm btn-outline-secondary" href="/public/invoices.php?focus_id=<?php echo (int)$row['id']; ?>">View</a>
                </td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="5" class="text-center text-muted py-4">No invoices found.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- ===== Recent Payments ===== -->
  <div class="col-12 col-lg-6">
    <div class="card shadow-sm h-100">
      <div class="card-header d-flex align-items-center justify-content-between">
        <strong>Recent Payments</strong>
        <div class="d-flex gap-2">
          <a class="btn btn-sm btn-outline-primary" href="/public/billing.php?search=<?php echo urlencode($pppoe_id ?: $clientName); ?>">Open list</a>
          <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#addPayModal">Add payment</button>
        </div>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-sm table-striped mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th style="width:32%">Date</th>
                <th class="text-end" style="width:18%">Amount</th>
                <th style="width:18%">Method</th>
                <th style="width:20%">Invoice</th>
                <th class="text-center" style="width:12%">Action</th>
              </tr>
            </thead>
            <tbody>
            <?php if ($recent_payments): foreach ($recent_payments as $row):
              $dt      = $row['pay_date'] ? date('Y-m-d', strtotime((string)$row['pay_date'])) : '';
              $amount  = (float)($row['amount'] ?? 0);
              $disc    = isset($row['discount']) ? (float)$row['discount'] : 0;
              $method  = $row['method'] ?: '';
              $txn     = $row['txn_id'] ?: '';
              $invTxt  = $row['invoice_number'] ?: ($row['bill_id'] ? ('#'.(int)$row['bill_id']) : ($row['bill_month'] ?: ''));
              $invLink = $row['bill_id'] ? '/public/invoices.php?focus_id='.((int)$row['bill_id']) : '/public/invoices.php?search='.urlencode($pppoe_id ?: $clientName);
            ?>
              <tr>
                <td>
                  <?php echo h($dt ?: '-'); ?>
                  <?php if ($txn): ?><div class="small text-muted">TXN: <?php echo h($txn); ?></div><?php endif; ?>
                </td>
                <td class="text-end">
                  <?php echo fm($amount); ?>
                  <?php if ($disc>0): ?><div class="small text-muted">Disc <?php echo fm($disc); ?></div><?php endif; ?>
                </td>
                <td><?php echo h($method ?: '-'); ?></td>
                <td>
                  <?php if ($invTxt): ?>
                    <a href="<?php echo h($invLink); ?>"><?php echo h($invTxt); ?></a>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="text-center">
                  <a class="btn btn-sm btn-outline-secondary" href="/public/payment_receipt.php?id=<?php echo (int)$row['id']; ?>">Receipt</a>
                </td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="5" class="text-center text-muted py-4">No payments found.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ===== Inline Add Payment Modal ===== -->
<div class="modal fade" id="addPayModal" tabindex="-1" aria-labelledby="addPayLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" id="addPayForm" action="/public/payment_add.php" method="post">
      <div class="modal-header">
        <h5 class="modal-title" id="addPayLabel">Add Payment — <?php echo h($clientName); ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <!-- বাংলা: client_id বাধ্যতামূলক -->
        <input type="hidden" name="client_id" value="<?php echo (int)$client_id; ?>">
        <div class="mb-3">
          <label class="form-label">Amount <span class="text-danger">*</span></label>
          <input type="number" step="0.01" min="0" class="form-control" name="amount" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Method</label>
          <input type="text" class="form-control" name="method" placeholder="bKash/Nagad/Cash/Bank">
        </div>
        <div class="mb-3">
          <label class="form-label">Transaction ID</label>
          <input type="text" class="form-control" name="txn_id" placeholder="TXN/Ref">
        </div>
        <div class="mb-3">
          <label class="form-label">Notes</label>
          <textarea class="form-control" rows="2" name="notes" placeholder="Optional notes"></textarea>
        </div>
        <div class="small text-muted">
          On submit: payment → recompute invoice → ledger -= amount (already implemented in your system).
        </div>
      </div>
      <div class="modal-footer justify-content-between">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
        <div class="d-flex gap-2">
          <button type="submit" class="btn btn-primary">Save payment</button>
          <a class="btn btn-outline-dark d-none" target="_blank" rel="noopener" id="receiptLink">Open receipt</a>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
/* বাংলা: Modal form AJAX submit — success হলে receipt লিংক দেখাই (যদি id রিটার্ন করে) */
(function(){
  const form = document.getElementById('addPayForm');
  const receiptLink = document.getElementById('receiptLink');

  if(!form) return;

  form.addEventListener('submit', async function(ev){
    ev.preventDefault();
    const fd = new FormData(form);

    // Hint: আপনার payment_add.php-তে ajax=1 দিলে JSON রেসপন্স দিন {ok:true,id:123}
    fd.append('ajax', '1');

    try{
      const res = await fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await res.json().catch(()=> ({}));

      if (data && data.ok) {
        // রিসিট বাটন অ্যাক্টিভেট
        if (data.id) {
          receiptLink.href = '/public/payment_receipt.php?id=' + encodeURIComponent(data.id);
          receiptLink.classList.remove('d-none');
        }
        // রিলোড করে টেবিল রিফ্রেশ করা ভাল
        setTimeout(()=> location.reload(), 600);
      } else {
        alert((data && data.error) ? data.error : 'Failed to save payment.');
      }
    } catch(e){
      alert('Network error. Please try again.');
    }
  }, false);
})();
</script>
