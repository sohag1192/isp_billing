<?php
// /public/receipt_payment.php
// POS-size Payment Receipt (58mm/80mm) + Due Amount + Manual Print/Close
// UI English; Bangla comments

declare(strict_types=1);

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function tbl_exists(PDO $pdo,string $t):bool{
  static $m=[]; if(isset($m[$t])) return $m[$t];
  try{
    $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
    $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
    $q->execute([$db,$t]);
    return $m[$t]=(bool)$q->fetchColumn();
  }catch(Throwable){ return $m[$t]=false; }
}
function col_exists(PDO $pdo,string $t,string $c):bool{
  static $m=[]; $k="$t::$c"; if(isset($m[$k])) return $m[$k];
  try{
    $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
    $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
    $q->execute([$db,$t,$c]);
    return $m[$k]=(bool)$q->fetchColumn();
  }catch(Throwable){ return $m[$k]=false; }
}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$payment_id = (int)($_GET['payment_id'] ?? 0);
$paper_w    = (int)($_GET['w'] ?? 58);              // 58 / 80 (mm)
$auto_print = (int)($_GET['autoprint'] ?? 0)===1;   // optional legacy
$return_url = trim($_GET['return'] ?? '');

if ($payment_id<=0) { http_response_code(400); echo "Invalid payment_id"; exit; }

/* ---------- Dynamic schema expressions ---------- */
// users → receiver field
$receiverExpr = null;
if (tbl_exists($pdo,'users')) {
  $cand = [];
  foreach (['name','full_name','username','email'] as $c) if (col_exists($pdo,'users',$c)) $cand[] = "u.`$c`";
  if ($cand) $receiverExpr = "TRIM(COALESCE(".implode(',', $cand).")) AS receiver_name";
}
// accounts.name
$accNameExpr = (tbl_exists($pdo,'accounts') && col_exists($pdo,'accounts','name')) ? "a.name AS account_name" : "NULL AS account_name";
// invoices.discount
$discCol = col_exists($pdo,'invoices','discount');

// payments optional cols
$pmCols = [
  'method'      => col_exists($pdo,'payments','method'),
  'txn_id'      => col_exists($pdo,'payments','txn_id'),
  'paid_at'     => col_exists($pdo,'payments','paid_at'),
  'remarks'     => col_exists($pdo,'payments','remarks'),
  'received_by' => col_exists($pdo,'payments','received_by'),
  'account_id'  => col_exists($pdo,'payments','account_id'),
];

/* ---------- Query (schema-aware) ---------- */
$sel = [
  "p.id AS payment_id",
  "p.invoice_id",
  "p.client_id",
  "ROUND(p.amount,2) AS amount",
  ($pmCols['method']     ? "p.method"  : "NULL AS method"),
  ($pmCols['txn_id']     ? "p.txn_id"  : "NULL AS txn_id"),
  ($pmCols['paid_at']    ? "p.paid_at" : "NULL AS paid_at"),
  ($pmCols['remarks']    ? "p.remarks" : "NULL AS remarks"),
  "i.total",
  ($discCol ? "COALESCE(i.discount,0) AS discount" : "0 AS discount"),
  "(SELECT COALESCE(SUM(pp.amount),0) FROM payments pp WHERE pp.invoice_id = p.invoice_id) AS paid_total",
  "c.name AS client_name",
];
$sel[] = $receiverExpr ? $receiverExpr : "NULL AS receiver_name";
$sel[] = $accNameExpr;

$sql = "SELECT ".implode(",\n       ", $sel)."
        FROM payments p
        JOIN invoices i ON i.id=p.invoice_id
        JOIN clients  c ON c.id=p.client_id";
$joins = [];
if ($pmCols['received_by'] && tbl_exists($pdo,'users'))    $joins[] = "LEFT JOIN users u ON u.id = p.received_by";
if ($pmCols['account_id']  && tbl_exists($pdo,'accounts')) $joins[] = "LEFT JOIN accounts a ON a.id = p.account_id";
if ($joins) $sql .= "\n".implode("\n", $joins);
$sql .= "\nWHERE p.id=? LIMIT 1";

$st=$pdo->prepare($sql);
$st->execute([$payment_id]);
$row=$st->fetch(PDO::FETCH_ASSOC);
if(!$row){ http_response_code(404); echo "Payment not found."; exit; }

/* ---------- Compute ---------- */
$client     = $row['client_name'] ?? '-';
$total      = (float)($row['total'] ?? 0);
$disc       = (float)($row['discount'] ?? 0);
$net        = max(0.0, $total - $disc);                 // Net payable
$amount     = (float)($row['amount'] ?? 0);             // Paid now
$paid_total = (float)($row['paid_total'] ?? $amount);   // Paid overall (this invoice)
$due_amount = max(0.0, $net - $paid_total);             // Due now

$method   = ($row['method'] ?? '') ?: 'Cash';
$paid_at  = $row['paid_at'] ?? date('Y-m-d H:i:s');
$txn_id   = $row['txn_id'] ?? '';
$remarks  = $row['remarks'] ?? '';
$receiver = trim((string)($row['receiver_name'] ?? '')); if ($receiver==='') $receiver = '—';
$acc_name = $row['account_name'] ?? '—';

$width_css = ($paper_w>=70 ? '80mm' : '58mm');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Payment Receipt</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  /* POS width */
  @page { size: <?= $width_css ?> auto; margin: 0; }
  body { margin:0; font: 12px/1.35 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", "Liberation Sans", sans-serif; background:#f6f7fb; }
  .wrap { min-height:100vh; display:flex; align-items:center; justify-content:center; padding:12px; }
  .sheet { background:#fff; border:1px solid #e9eef5; border-radius:10px; box-shadow:0 6px 30px rgba(0,0,0,.08); overflow:hidden; }
  .receipt { width: <?= $width_css ?>; padding: 10px 12px; }
  .center { text-align:center; }
  .right  { text-align:right; }
  .mono   { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
  .kv { display:flex; justify-content:space-between; gap:8px; }
  .kv .k{ color:#555; }
  .hr { border-top:1px dashed #999; margin:6px 0; }
  .small{ font-size:11px; color:#555; }
  .total-row { font-weight:700; }
  .logo { font-weight:800; letter-spacing:.5px; }
  .actions { display:flex; gap:8px; justify-content:center; padding:10px; border-top:1px solid #eef2f7; background:#fbfcff; }
  .btn { border:1px solid #d5dbe5; background:#fff; border-radius:8px; padding:8px 12px; cursor:pointer; font-size:13px; }
  .btn.primary { background:#0d6efd; color:#fff; border-color:#0d6efd; }
  .btn:hover { filter:brightness(.98); }
  @media print {
    body { background:#fff; }
    .wrap, .sheet { box-shadow:none; border:none; border-radius:0; }
    .actions { display:none !important; } /* প্রিন্টে বোতাম দেখাবো না */
    .receipt { padding: 8px 10px; }
  }
</style>
</head>
<body>
<div class="wrap">
  <div class="sheet">
    <div class="receipt">

      <!-- Header -->
      <div class="center">
        <div class="logo">ISP BILLING</div>
        <div class="small">Payment Receipt</div>
      </div>

      <div class="hr"></div>

      <!-- Basic -->
      <div class="kv"><div class="k">Receipt #</div><div class="v mono"><?= h($payment_id) ?></div></div>
      <div class="kv"><div class="k">Invoice ID</div><div class="v mono"><?= h($row['invoice_id']) ?></div></div>
      <div class="kv"><div class="k">Client</div><div class="v"><?= h($client) ?></div></div>
      <div class="kv"><div class="k">Paid At</div><div class="v mono"><?= h($paid_at) ?></div></div>

      <div class="hr"></div>

      <!-- Amounts -->
      <div class="kv"><div class="k">Invoice Total</div><div class="v mono right"><?= number_format($total,2) ?></div></div>
      <div class="kv"><div class="k">Discount</div><div class="v mono right">- <?= number_format($disc,2) ?></div></div>
      <div class="kv total-row"><div class="k">Net Payable</div><div class="v mono right"><?= number_format($net,2) ?></div></div>
      <div class="kv"><div class="k">Paid (this receipt)</div><div class="v mono right"><?= number_format($amount,2) ?></div></div>
      <div class="kv"><div class="k">Total Paid</div><div class="v mono right"><?= number_format($paid_total,2) ?></div></div>

      <div class="hr"></div>

      <!-- Meta -->
      <div class="kv"><div class="k">Method</div><div class="v"><?= h($method) ?></div></div>
      <?php if($txn_id): ?>
        <div class="kv"><div class="k">Txn ID</div><div class="v mono"><?= h($txn_id) ?></div></div>
      <?php endif; ?>
      <div class="kv"><div class="k">Account</div><div class="v"><?= h($acc_name) ?></div></div>
      <div class="kv"><div class="k">Received By</div><div class="v"><?= h($receiver) ?></div></div>
      <?php if($remarks): ?>
        <div class="kv"><div class="k">Remarks</div><div class="v"><?= h($remarks) ?></div></div>
      <?php endif; ?>

      <div class="hr"></div>

      <!-- DUE AMOUNT -->
      <div class="kv total-row">
        <div class="k">Due Amount</div>
        <div class="v mono right"><?= number_format($due_amount,2) ?></div>
      </div>

      <div class="center small" style="margin-top:8px">Thank you.</div>

    </div>

    <!-- Actions (not printed) -->
    <div class="actions">
      <button class="btn primary" id="btnPrint"><i>🖨</i> Print</button>
      <button class="btn" id="btnClose"><i>✖</i> Close Receipt</button>
    </div>
  </div>
</div>

<script>
// (বাংলা) Print/Close কন্ট্রোলস
document.getElementById('btnPrint')?.addEventListener('click', ()=> window.print());

document.getElementById('btnClose')?.addEventListener('click', ()=>{
  const ret = <?= json_encode($return_url) ?>;
  // ওপেনার থাকলে উইন্ডো ক্লোজ; না থাকলে return URL/ billing এ রিডাইরেক্ট
  try {
    if (window.opener && !window.opener.closed) { window.close(); return; }
  } catch(e){}
  if (ret && typeof ret === 'string' && ret.length>0) { window.location.href = ret; }
  else { window.location.href = '/public/billing.php'; }
});

// লিগ্যাসি সাপোর্ট: autoprint=1 দিলে আগের মত অটো প্রিন্ট
<?php if($auto_print): ?>
window.addEventListener('load', ()=>{ setTimeout(()=>window.print(), 60); });
<?php endif; ?>
</script>
</body>
</html>
