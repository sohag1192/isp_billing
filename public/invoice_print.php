<?php
// /public/invoice_print.php
// Print-friendly invoice view + optional PDF (Dompdf) generation
// (বাংলা) কোড ইংরেজি, কমেন্ট বাংলা

declare(strict_types=1);

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

// Optional company profile (if exists)
$cfg_path = __DIR__ . '/../app/config.php';
if (file_exists($cfg_path)) { require_once $cfg_path; }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// (বাংলা) টেবিল/কলাম আছে কি না - হেল্পার
function col_exists(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
  $st->execute([$col]);
  return (bool)$st->fetchColumn();
}
function table_exists(PDO $pdo, string $table): bool {
  $st = $pdo->prepare("SHOW TABLES LIKE ?");
  $st->execute([$table]);
  return (bool)$st->fetchColumn();
}

// ---------- Inputs ----------
$id  = (int)($_GET['id'] ?? 0);                // invoice id
$vat = (float)($_GET['vat'] ?? 0);             // optional VAT percent (e.g., 5 / 7.5 / 15)
$pdf = (int)($_GET['pdf'] ?? 0);               // pdf=1 → try Dompdf

if ($id <= 0) {
  http_response_code(400);
  echo "Invalid invoice id.";
  exit;
}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ---------- Detect invoice amount/status schema ----------
$amt_col = null;
foreach (['total','payable','amount'] as $c) {
  if (col_exists($pdo,'invoices',$c)) { $amt_col = $c; break; }
}
if (!$amt_col) {
  http_response_code(500);
  echo "No amount column in invoices (need total/payable/amount).";
  exit;
}

$has_bm        = col_exists($pdo,'invoices','billing_month');
$has_number    = col_exists($pdo,'invoices','invoice_number');
$has_status    = col_exists($pdo,'invoices','status');
$has_remarks   = col_exists($pdo,'invoices','remarks');
$has_inv_date  = col_exists($pdo,'invoices','invoice_date');
$has_due_date  = col_exists($pdo,'invoices','due_date');
$has_pstart    = col_exists($pdo,'invoices','period_start');
$has_pend      = col_exists($pdo,'invoices','period_end');

$has_ledger    = col_exists($pdo,'clients','ledger_balance');

// ---------- Pull invoice + client ----------
$sel = "SELECT i.id, i.$amt_col AS amount, ".
       ($has_number ? "i.invoice_number, " : "NULL AS invoice_number, ").
       ($has_bm ? "i.billing_month, " : "NULL AS billing_month, ").
       ($has_inv_date ? "i.invoice_date, " : "NULL AS invoice_date, ").
       ($has_due_date ? "i.due_date, " : "NULL AS due_date, ").
       ($has_pstart ? "i.period_start, " : "NULL AS period_start, ").
       ($has_pend ? "i.period_end, " : "NULL AS period_end, ").
       ($has_status ? "i.status, " : "NULL AS status, ").
       ($has_remarks ? "i.remarks, " : "NULL AS remarks, ").
       "c.id AS client_id, c.client_code, c.name AS client_name, c.pppoe_id, c.mobile, c.email, c.address, ".
       "COALESCE(c.ledger_balance,0) AS ledger_balance ".
       "FROM invoices i ".
       "LEFT JOIN clients c ON c.id = i.client_id ".
       "WHERE i.id = ?";
$sti = $pdo->prepare($sel);
$sti->execute([$id]);
$inv = $sti->fetch(PDO::FETCH_ASSOC);

if (!$inv) {
  http_response_code(404);
  echo "Invoice not found.";
  exit;
}

// ---------- Compute payment info (schema-flex) ----------
$has_payments = table_exists($pdo,'payments');
$paid_amount = 0.0;
if ($has_payments) {
  // (বাংলা) payments টেবিলে bill_id বা invoice_id—যেটা আছে সেটাই ইউজ
  $has_bill_id    = col_exists($pdo,'payments','bill_id');
  $has_invoice_id = col_exists($pdo,'payments','invoice_id');

  if ($has_bill_id || $has_invoice_id) {
    $where = $has_bill_id ? "bill_id=?" : "invoice_id=?";
    $stp = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE $where");
    $stp->execute([$id]);
    $paid_amount = (float)$stp->fetchColumn();
  }
}

// ---------- Status derive (fallback if no invoices.status) ----------
$amount = (float)$inv['amount'];
$status = $inv['status'] ?? '';
if (!$status || !in_array(strtolower($status), ['paid','unpaid','partial','void'], true)) {
  if ($amount <= 0)           $status = 'paid';
  elseif ($paid_amount <= 0)  $status = 'unpaid';
  elseif ($paid_amount >= $amount) $status = 'paid';
  else                        $status = 'partial';
}

// ---------- VAT / Totals ----------
$sub_total = $amount;
$vat_amount = $vat > 0 ? round($sub_total * ($vat/100), 2) : 0.0;
$grand_total = round($sub_total + $vat_amount, 2);

// ---------- Company info (optional from config.php) ----------
$company = [
  'name'    => $GLOBALS['config']['company_name']    ?? 'Your Company Name',
  'address' => $GLOBALS['config']['company_address'] ?? 'Company Address',
  'phone'   => $GLOBALS['config']['company_phone']   ?? 'Phone',
  'email'   => $GLOBALS['config']['company_email']   ?? 'info@example.com',
  'logo'    => $GLOBALS['config']['company_logo']    ?? '/assets/img/logo.png',
];

// ---------- PDF generation (Dompdf auto-detect) ----------
if ($pdf === 1) {
  // (বাংলা) Dompdf থাকলে PDF বানাব; না থাকলে fallback বার্তা
  $dompdf_ok = false;
  // try typical vendor autoload locations
  $try = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
  ];
  foreach ($try as $path) {
    if (file_exists($path)) { require_once $path; $dompdf_ok = true; break; }
  }

  if ($dompdf_ok && class_exists('Dompdf\Dompdf')) {
    ob_start();
    include __FILE__ . '.tpl.php'; // (বাংলা) নিচে টেমপ্লেট ইনলাইন নেই—include করলে recursion হবে, তাই নিচে HTML echo করবো
    // কিন্তু আমরা এই ফাইলে সরাসরি HTML রেন্ডার করবো; ob_get_clean() দিয়ে PDF-এ দেব
    // নোট: যেহেতু একই ফাইল include করলে রিকার্সন, তাই সরাসরি HTML বাফার করবো (নীচে HTML রেন্ডার অংশের আগে break out করবো)
  } else {
    // সুন্দর fallback HTML
    header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><html><head><meta charset='utf-8'><title>PDF not available</title>
          <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css' rel='stylesheet'>
          </head><body class='p-4'>
          <div class='alert alert-warning'><strong>PDF generator (Dompdf) not found.</strong><br>
          Please install via <code>composer require dompdf/dompdf</code> and try again.
          </div>
          <p><a class='btn btn-primary' href='".h($_SERVER['PHP_SELF'])."?id={$id}'>Back to Print View</a></p>
          </body></html>";
    exit;
  }
}

// ---------- Render HTML (Print View) ----------
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Invoice #<?php echo h($inv['invoice_number'] ?: (string)$inv['id']); ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootstrap -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    /* ===== A4 Print Layout ===== */
    @page { size: A4; margin: 14mm; }
    @media print {
      .no-print { display: none !important; }
      body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      .stamp { opacity: 0.2 !important; }
      a[href]:after { content: ""; }
    }

    body { background: #fafafa; }
    .invoice-page { max-width: 900px; margin: 20px auto; background: #fff; box-shadow: 0 2px 10px rgba(0,0,0,.08); }
    .header { border-bottom: 2px solid #f1f1f1; padding: 24px; }
    .brand { display: flex; align-items: center; gap: 16px; }
    .brand img { height: 48px; width: 48px; object-fit: contain; }
    .brand .title { font-weight: 700; font-size: 1.25rem; }

    .meta { padding: 16px 24px; }
    .meta .badge-status { font-size: .95rem; }

    .table { margin-bottom: 0; }
    .totals { padding: 16px 24px 24px; }
    .footer { border-top: 2px solid #f1f1f1; padding: 16px 24px; font-size: .9rem; color:#666; }

    /* Status stamp (big watermark-style) */
    .stamp {
      position: absolute;
      right: 12%;
      top: 38%;
      transform: rotate(-18deg);
      font-weight: 900;
      font-size: 84px;
      color: <?php
        $lower = strtolower($status);
        echo ($lower==='paid' ? '#28a745' : ($lower==='partial' ? '#ffc107' : '#dc3545'));
      ?>;
      opacity: 0.12;
      letter-spacing: 2px;
      pointer-events: none;
      user-select: none;
    }

    .kv { display: grid; grid-template-columns: 140px 1fr; gap: 6px 12px; }
    .kv .label { color:#666; }
  </style>
</head>
<body>

<div class="invoice-page position-relative">
  <div class="no-print p-3 text-end bg-light border-bottom">
    <a href="?id=<?php echo $id; ?>" class="btn btn-outline-secondary me-2"><i class="bi bi-arrow-clockwise"></i> Refresh</a>
    <button class="btn btn-primary me-2" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
    <a class="btn btn-dark" href="?id=<?php echo $id; ?>&pdf=1<?php echo $vat>0 ? '&vat='.urlencode((string)$vat):''; ?>">
      <i class="bi bi-download"></i> Download PDF
    </a>
  </div>

  <div class="header d-flex justify-content-between align-items-center">
    <div class="brand">
      <img src="<?php echo h($company['logo']); ?>" alt="Logo" onerror="this.style.display='none'">
      <div>
        <div class="title"><?php echo h($company['name']); ?></div>
        <div class="text-muted small">
          <?php echo h($company['address']); ?> • <?php echo h($company['phone']); ?> • <?php echo h($company['email']); ?>
        </div>
      </div>
    </div>
    <div class="text-end">
      <div class="fs-5 fw-bold">INVOICE</div>
      <div class="text-muted small">#<?php echo h($inv['invoice_number'] ?: (string)$inv['id']); ?></div>
    </div>
  </div>

  <div class="stamp"><?php echo strtoupper(h($status)); ?></div>

  <div class="meta row g-4">
    <div class="col-md-6">
      <div class="fw-semibold mb-2">Bill To</div>
      <div class="kv">
        <div class="label">Client</div><div><?php echo h($inv['client_name'] ?: $inv['pppoe_id']); ?></div>
        <div class="label">PPPoE ID</div><div><?php echo h($inv['pppoe_id']); ?></div>
        <div class="label">Address</div><div><?php echo h($inv['address'] ?: '-'); ?></div>
        <div class="label">Mobile</div><div><?php echo h($inv['mobile'] ?: '-'); ?></div>
        <div class="label">Email</div><div><?php echo h($inv['email'] ?: '-'); ?></div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="fw-semibold mb-2">Invoice Info</div>
      <div class="kv">
        <div class="label">Invoice No</div><div><?php echo h($inv['invoice_number'] ?: (string)$inv['id']); ?></div>
        <div class="label">Date</div><div><?php echo h($inv['invoice_date'] ?: date('Y-m-d')); ?></div>
        <div class="label">Period</div>
        <div>
          <?php
            $period = '-';
            if ($inv['period_start'] && $inv['period_end']) $period = h($inv['period_start']).' to '.h($inv['period_end']);
            elseif ($inv['billing_month']) $period = date('F Y', strtotime($inv['billing_month']));
            echo $period;
          ?>
        </div>
        <div class="label">Due Date</div><div><?php echo h($inv['due_date'] ?: '-'); ?></div>
        <div class="label">Status</div>
        <div>
          <?php
            $cls = ($lower==='paid'?'success':($lower==='partial'?'warning':'danger'));
            echo '<span class="badge bg-'.$cls.' badge-status">'.strtoupper(h($status)).'</span>';
          ?>
        </div>
      </div>
    </div>
  </div>

  <div class="px-4">
    <table class="table table-bordered align-middle">
      <thead class="table-light">
        <tr>
          <th style="width:65%">Description</th>
          <th class="text-end" style="width:35%">Amount</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>
            Monthly Internet Service
            <?php
              $note = [];
              if (!empty($inv['period_start']) && !empty($inv['period_end'])) $note[] = "Period: ".h($inv['period_start'])." to ".h($inv['period_end']);
              elseif (!empty($inv['billing_month'])) $note[] = "Billing Month: ".date('F Y', strtotime($inv['billing_month']));
              if (!empty($inv['remarks'])) $note[] = h($inv['remarks']);
              if ($note) echo '<div class="text-muted small">'.implode(' • ', $note).'</div>';
            ?>
          </td>
          <td class="text-end"><?php echo number_format($sub_total, 2); ?></td>
        </tr>
      </tbody>
    </table>
  </div>

  <div class="totals">
    <div class="row justify-content-end">
      <div class="col-md-6 col-lg-5">
        <table class="table table-sm">
          <tr>
            <td class="text-muted">Subtotal</td>
            <td class="text-end"><?php echo number_format($sub_total, 2); ?></td>
          </tr>
          <?php if ($vat > 0): ?>
          <tr>
            <td class="text-muted">VAT (<?php echo number_format($vat, 2); ?>%)</td>
            <td class="text-end"><?php echo number_format($vat_amount, 2); ?></td>
          </tr>
          <?php endif; ?>
          <tr class="table-light">
            <th>Grand Total</th>
            <th class="text-end"><?php echo number_format($grand_total, 2); ?></th>
          </tr>
          <tr>
            <td class="text-muted">Paid</td>
            <td class="text-end"><?php echo number_format($paid_amount, 2); ?></td>
          </tr>
          <tr>
            <td class="text-muted">Due</td>
            <td class="text-end">
              <?php echo number_format(max(0, $grand_total - $paid_amount), 2); ?>
            </td>
          </tr>
        </table>
      </div>
    </div>
  </div>

  <div class="footer">
    <div>Thank you for your business.</div>
    <div class="small">If you have any question about this invoice, please contact us:
      <?php echo h($company['email']); ?> / <?php echo h($company['phone']); ?>
    </div>
  </div>
</div>

<!-- Bootstrap Icons (for buttons) -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
</body>
</html>
