<?php
// /public/payment_receipt.php
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pid = (int)($_GET['payment_id'] ?? $_GET['pid'] ?? 0);
if ($pid <= 0){
  http_response_code(400);
  echo "Invalid payment id";
  exit;
}

/* Load payment + invoice + client */
$sql = "SELECT pay.*,
               inv.id            AS invoice_id,
               inv.invoice_no    AS invoice_no,
               inv.month         AS inv_month,
               inv.year          AS inv_year,
               inv.amount        AS inv_amount,
               inv.total         AS inv_total,
               inv.payable       AS inv_payable,
               inv.due           AS inv_due,
               c.id              AS client_id,
               c.name            AS client_name,
               c.client_code     AS client_code,
               c.pppoe_id        AS pppoe_id,
               c.mobile          AS mobile,
               c.address         AS address
        FROM payments pay
        LEFT JOIN invoices inv ON inv.id = pay.invoice_id
        LEFT JOIN clients  c   ON c.id  = pay.client_id
        WHERE pay.id = ?";
$st = db()->prepare($sql);
$st->execute([$pid]);
$P = $st->fetch(PDO::FETCH_ASSOC);
if (!$P){
  http_response_code(404);
  echo "Payment not found";
  exit;
}

/* Company/brand (optional) */
$cfg = [];
try{
  $kv = db()->query("SELECT `key`,`value` FROM settings WHERE `key` IN ('company_name','company_phone','company_address')")->fetchAll(PDO::FETCH_KEY_PAIR);
  if (is_array($kv)) $cfg = $kv;
}catch(Throwable $e){}
$company_name = $cfg['company_name']  ?? 'Your ISP';
$company_phone= $cfg['company_phone'] ?? '';
$company_addr = $cfg['company_address'] ?? '';

/* Safe helpers */
$inv_amount = $P['inv_amount'] ?? ($P['inv_total'] ?? ($P['inv_payable'] ?? 0));
$inv_amount = (float)$inv_amount;

/* Sum payments for this invoice (to show due after payment) */
$paid_sum = 0.0; $disc_sum = 0.0;
if (!empty($P['invoice_id'])){
  $ag = db()->prepare("SELECT COALESCE(SUM(amount),0) AS s_amount, COALESCE(SUM(discount),0) AS s_discount
                       FROM payments WHERE invoice_id = ?");
  $ag->execute([$P['invoice_id']]);
  $row = $ag->fetch(PDO::FETCH_ASSOC);
  if ($row){ $paid_sum = (float)$row['s_amount']; $disc_sum = (float)$row['s_discount']; }
}
$due_after = max(0, $inv_amount - ($paid_sum + $disc_sum));

/* Fields */
$pay_amount   = (float)($P['amount'] ?? 0);
$pay_discount = (float)($P['discount'] ?? 0);
$pay_method   = trim($P['method'] ?? ($P['payment_method'] ?? ''));
$pay_txn      = trim($P['txn_id'] ?? ($P['trx_id'] ?? ''));
$pay_note     = trim($P['notes'] ?? '');
$paid_at      = $P['paid_at'] ?? ($P['created_at'] ?? date('Y-m-d H:i:s'));

$inv_no       = $P['invoice_no'] ?? ('INV-'.$P['invoice_id']);
$period_str   = ($P['inv_month'] && $P['inv_year']) ? (sprintf('%02d', (int)$P['inv_month']).'-'.$P['inv_year']) : '';

?>
<!doctype html>
<html lang="bn">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Payment Receipt #<?= (int)$pid ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
  :root{ --receipt-max: 720px; }
  body{ background:#f6f7fb; }
  .receipt-wrap{ max-width:var(--receipt-max); margin:24px auto; }
  .rc-card{ background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,.06); }
  .rc-head{ padding:18px 18px; border-bottom:1px solid #edf0f3; display:flex; gap:12px; align-items:center; }
  .brand{ font-weight:800; font-size:20px; }
  .brand-sub{ color:#6b7280; font-size:12px; }
  .rc-actions{ margin-left:auto; display:flex; gap:8px; }
  .rc-body{ padding:18px; }
  .kv{ display:grid; grid-template-columns: 160px 1fr; gap:6px 12px; }
  .kv .k{ color:#6b7280; }
  .kv .v{ font-weight:600; }
  .hr{ border-top:1px dashed #d7dbe2; margin:14px 0; }
  .totals{ display:grid; grid-template-columns: 1fr auto; gap:6px 12px; max-width:420px; margin-left:auto; }
  .totals .k{ color:#6b7280; }
  .totals .v{ font-weight:700; }
  .badge-soft{ background:#eef2ff; color:#3730a3; }
  .water{ position:absolute; inset:0; display:none; }
  @media print{
    .rc-actions{ display:none !important; }
    body{ background:#fff; }
    .receipt-wrap{ margin:0; max-width:none; }
  }
</style>
</head>
<body>

<div class="receipt-wrap">
  <div class="rc-card position-relative">
    <div class="rc-head">
      <div>
        <div class="brand"><?= h($company_name) ?></div>
        <div class="brand-sub">
          <?= h($company_addr) ?><?= $company_phone ? ' • '.h($company_phone) : '' ?>
        </div>
      </div>
      <div class="rc-actions">
        <a href="javascript:window.print()" class="btn btn-primary btn-sm">
          <i class="bi bi-printer"></i> Print
        </a>
        <?php if (!empty($P['invoice_id'])): ?>
          <a href="/public/invoice_view.php?id=<?= (int)$P['invoice_id'] ?>" class="btn btn-outline-secondary btn-sm">Back to Invoice</a>
        <?php else: ?>
          <a href="/public/invoices.php" class="btn btn-outline-secondary btn-sm">Back</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="rc-body">
      <div class="d-flex align-items-start gap-3 mb-2">
        <div class="flex-grow-1">
          <h5 class="mb-0">Payment Receipt</h5>
          <div class="text-muted small">
            Receipt ID: #<?= (int)$pid ?><?= $inv_no ? ' • Invoice: '.h($inv_no) : '' ?><?= $period_str?' • Period: '.h($period_str):'' ?>
          </div>
        </div>
        <span class="badge bg-success align-self-start">PAID</span>
      </div>

      <div class="row g-3">
        <div class="col-12 col-md-6">
          <div class="kv">
            <div class="k">Client</div>
            <div class="v"><?= h($P['client_name'] ?? '-') ?>  <?= !empty($P['client_code'])? ' ('.h($P['client_code']).')':'' ?></div>

            <div class="k">PPPoE</div>
            <div class="v"><?= h($P['pppoe_id'] ?? '-') ?></div>

            <div class="k">Mobile</div>
            <div class="v"><?= h($P['mobile'] ?? '-') ?></div>

            <div class="k">Address</div>
            <div class="v"><?= h($P['address'] ?? '-') ?></div>
          </div>
        </div>

        <div class="col-12 col-md-6">
          <div class="kv">
            <div class="k">Paid At</div>
            <div class="v"><?= h($paid_at) ?></div>

            <div class="k">Method</div>
            <div class="v"><?= h($pay_method ?: '—') ?></div>

            <div class="k">Txn/Ref</div>
            <div class="v"><?= h($pay_txn ?: '—') ?></div>

            <div class="k">Notes</div>
            <div class="v"><?= h($pay_note ?: '—') ?></div>
          </div>
        </div>
      </div>

      <div class="hr"></div>

      <div class="row g-3">
        <div class="col-12 col-lg-7">
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead class="table-light">
                <tr>
                  <th>Description</th>
                  <th class="text-end">Amount</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>Invoice Amount</td>
                  <td class="text-end"><?= number_format($inv_amount, 2) ?></td>
                </tr>
                <tr>
                  <td>Payment (this)</td>
                  <td class="text-end">- <?= number_format($pay_amount, 2) ?></td>
                </tr>
                <tr>
                  <td>Discount (this)</td>
                  <td class="text-end">- <?= number_format($pay_discount, 2) ?></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="col-12 col-lg-5">
          <div class="totals">
            <div class="k">Total Paid (all)</div>
            <div class="v text-end"><?= number_format($paid_sum, 2) ?></div>

            <div class="k">Total Discount (all)</div>
            <div class="v text-end"><?= number_format($disc_sum, 2) ?></div>

            <div class="k">Due After Payment</div>
            <div class="v text-end <?= $due_after>0?'text-danger':'text-success' ?>">
              <?= number_format($due_after, 2) ?>
            </div>
          </div>
        </div>
      </div>

      <div class="mt-3 small text-muted">
        * এই রিসিটটি সিস্টেম জেনারেটেড। প্রয়োজনে ইনভয়েস পেজ থেকে বিস্তারিত দেখা যাবে।
      </div>
    </div>
  </div>

  <div class="text-center mt-2">
    <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
      <i class="bi bi-printer"></i> Print
    </button>
  </div>
</div>

<!-- Icons (optional) -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
</body>
</html>
