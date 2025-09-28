<?php
// /public/portal/invoice_view.php
// Client Portal — Single Invoice View (first fetch by id, then ownership-check)

declare(strict_types=1);
require_once __DIR__ . '/../../app/portal_require_login.php';
require_once __DIR__ . '/../../app/db.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------------- Debug switch (optional) ---------------- */
// define('PORTAL_DEBUG', true);
// if (defined('PORTAL_DEBUG')) { ini_set('display_errors','1'); error_reporting(E_ALL); }

/* ---------------- Helpers ---------------- */
function col_exists(PDO $pdo, string $tbl, string $col): bool {
  try{ $st=$pdo->prepare("SHOW COLUMNS FROM `$tbl` LIKE ?"); $st->execute([$col]); return (bool)$st->fetchColumn(); }
  catch(Throwable $e){ return false; }
}
function fmt_inv($raw){ $raw=(string)$raw; return ctype_digit($raw) ? ('INV-'.str_pad($raw,6,'0',STR_PAD_LEFT)) : $raw; }

/* ---------------- Resolve portal client ---------------- */
$client_id = (function(): int {
  if (function_exists('portal_client_id')) {
    $cid = (int) portal_client_id();
    if ($cid > 0) return $cid;
  }
  if (session_status() === PHP_SESSION_NONE) { session_start(); }
  foreach (['client_id','SESS_CLIENT_ID'] as $k) {
    if (!empty($_SESSION[$k]) && ctype_digit((string)$_SESSION[$k])) return (int)$_SESSION[$k];
  }
  return 0;
})();
if ($client_id <= 0){ http_response_code(403); echo 'Access denied'; exit; }

/* ---------------- Input ---------------- */
$iid = (int)($_GET['id'] ?? 0);
if ($iid <= 0){ http_response_code(404); echo 'Invoice not found'; exit; }

/* ---------------- Current client row (for mapping) ---------------- */
$st = $pdo->prepare("SELECT id, client_code, pppoe_id, name, email, mobile FROM clients WHERE id=?");
$st->execute([$client_id]);
$CLIENT = $st->fetch(PDO::FETCH_ASSOC) ?: ['id'=>$client_id,'client_code'=>null,'pppoe_id'=>null,'name'=>null,'email'=>null,'mobile'=>null];

/* ---------------- Schema detect ---------------- */
$has_is_void    = col_exists($pdo,'invoices','is_void');
$has_status     = col_exists($pdo,'invoices','status');
$has_idate      = col_exists($pdo,'invoices','invoice_date');
$has_bmon       = col_exists($pdo,'invoices','billing_month');
$has_month      = col_exists($pdo,'invoices','month');
$has_year       = col_exists($pdo,'invoices','year');
$has_inv_number = col_exists($pdo,'invoices','invoice_number');
$has_inv_no     = col_exists($pdo,'invoices','invoice_no');

/* Amount columns */
$amount_exprs = [];
if (col_exists($pdo,'invoices','total'))   $amount_exprs[]='i.total';
if (col_exists($pdo,'invoices','payable')) $amount_exprs[]='i.payable';
if (col_exists($pdo,'invoices','amount'))  $amount_exprs[]='i.amount';
$amount_expr = $amount_exprs ? ('COALESCE('.implode(',', $amount_exprs).')') : '0';

/* payments FK */
$pay_tbl     = 'payments';
$pay_has_iid = col_exists($pdo,$pay_tbl,'invoice_id');
$pay_has_bid = col_exists($pdo,$pay_tbl,'bill_id');
$pay_col_inv = $pay_has_iid ? 'invoice_id' : ($pay_has_bid ? 'bill_id' : null);

/* ---------------- 1) Fetch by id only (plus is_void filter) ---------------- */
$where = ["i.id = ?"];
$args  = [$iid];
if ($has_is_void) { $where[] = "COALESCE(i.is_void,0)=0"; }

$selects = ["i.*", "$amount_expr AS total_amount"];
if     ($has_inv_number)      $selects[] = "i.invoice_number AS invoice_no";
elseif ($has_inv_no)          $selects[] = "i.invoice_no AS invoice_no";
else                          $selects[] = "i.id AS invoice_no";

if     ($has_bmon)                     $selects[] = "i.billing_month AS ym";
elseif ($has_month && $has_year)       $selects[] = "CONCAT(i.year,'-',LPAD(i.month,2,'0')) AS ym";
elseif ($has_idate)                    $selects[] = "DATE_FORMAT(i.invoice_date,'%Y-%m') AS ym";
else                                   $selects[] = "'' AS ym";

$sql = "SELECT ".implode(", ", $selects)." FROM invoices i WHERE ".implode(' AND ', $where)." LIMIT 1";
$st = $pdo->prepare($sql); $st->execute($args);
$inv = $st->fetch(PDO::FETCH_ASSOC);
if (!$inv){ http_response_code(404); echo 'Invoice not found'; exit; }

/* ---------------- 2) Ownership check (after fetch) ---------------- */
$owned = false;

/* 2.a Try FK columns first */
foreach (['client_id','clients_id','customer_id','subscriber_id','user_id','client','cid'] as $fk) {
  if (array_key_exists($fk, $inv)) {
    $owned = ((int)$inv[$fk] === $client_id);
    break;
  }
}

/* 2.b If no FK match, try identifier mapping */
if (!$owned) {
  $pairs = [
    ['key'=>'client_code','client'=>$CLIENT['client_code'] ?? null],
    ['key'=>'pppoe_id',   'client'=>$CLIENT['pppoe_id'] ?? null],
    ['key'=>'email',      'client'=>$CLIENT['email'] ?? null],
    ['key'=>'mobile',     'client'=>$CLIENT['mobile'] ?? null],
    ['key'=>'name',       'client'=>$CLIENT['name'] ?? null],
  ];
  foreach ($pairs as $p){
    $k = $p['key']; $val = $p['client'];
    if ($val !== null && array_key_exists($k,$inv) && (string)$inv[$k] !== '') {
      // loose compare: trim + case-insensitive for strings, digits-only for mobile
      $a = is_string($inv[$k]) ? trim($inv[$k]) : $inv[$k];
      $b = is_string($val)     ? trim($val)     : $val;
      if ($k==='mobile'){ $a=preg_replace('/\D+/','',$a); $b=preg_replace('/\D+/','',$b); }
      if (strcasecmp((string)$a,(string)$b)===0) { $owned=true; break; }
    }
  }
}

/* 2.c If still not owned -> 403 */
if (!$owned){
  http_response_code(403);
  echo 'Access denied for this invoice';
  exit;
}

/* ---------------- 3) Compute amounts ---------------- */
$paid = 0.0;
if ($pay_col_inv){
  $stp = $pdo->prepare("SELECT SUM(COALESCE(amount,0)) - SUM(COALESCE(discount,0)) AS paid_sum
                        FROM $pay_tbl WHERE $pay_col_inv = ?");
  $stp->execute([$iid]);
  $paid = (float)($stp->fetchColumn() ?: 0);
}
$total = (float)($inv['total_amount'] ?? 0);
$due   = round($total - $paid, 2);

$inv_no = fmt_inv($inv['invoice_no'] ?? $inv['id']);
$ym     = (string)($inv['ym'] ?? '');
$status = trim((string)($inv['status'] ?? ''));
$badge  = 'secondary';
if ($status !== ''){
  if (strcasecmp($status,'paid')===0) $badge='success';
  elseif (strcasecmp($status,'partial')===0) $badge='warning';
  elseif (strcasecmp($status,'unpaid')===0 || strcasecmp($status,'due')===0) $badge='danger';
} else {
  $badge = ($due <= 0.00001) ? 'success' : (($paid > 0) ? 'warning' : 'danger');
  $status = ($due <= 0.00001) ? 'Paid' : (($paid > 0) ? 'Partial' : 'Unpaid');
}

/* Pay link (instruction page) */
$bkashUrl = '/public/portal/bkash.php?ref='.urlencode($inv_no).'&amount='.urlencode(number_format(max($due,0),2,'.',''));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Invoice <?= h($inv_no) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body{ background:#f7f8fb; }
    .portal-topbar { background:#fff; border-bottom:1px solid #e9edf3; }
    .sidebar-wrap { min-width:260px; background:linear-gradient(180deg,#eef5ff,#ffffff); border-right:1px solid #e9edf3; }
    .sidebar-inner { padding:16px; position:sticky; top:0; height:100vh; overflow:auto; }
    .content-wrap { flex:1; min-width:0; }
  </style>
</head>
<body>
  <nav class="portal-topbar navbar navbar-light">
    <div class="container-fluid">
      <span class="navbar-brand"><i class="bi bi-receipt"></i> Invoice <?= h($inv_no) ?></span>
      <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="/public/portal/invoices.php"><i class="bi bi-arrow-left"></i> Back</a>
        <?php if (file_exists(__DIR__.'/../invoice_print.php')): ?>
          <a class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener" href="/public/invoice_print.php?id=<?= $iid ?>"><i class="bi bi-printer"></i> Print</a>
        <?php endif; ?>
        <a class="btn btn-outline-danger btn-sm <?= ($due<=0?'disabled':'') ?>" href="<?= h($bkashUrl) ?>"><i class="bi bi-wallet2"></i> Pay</a>
      </div>
    </div>
  </nav>

  <div class="d-flex">
    <?php
      $sb1 = __DIR__.'/portal_sidebar.php'; $sb2 = __DIR__.'/sidebar.php';
      echo '<div class="sidebar-wrap d-none d-md-block"><div class="sidebar-inner">';
      if (is_file($sb1)) include $sb1; elseif (is_file($sb2)) include $sb2;
      echo '</div></div>';
    ?>
    <div class="content-wrap p-3">
      <div class="container-fluid px-0">
        <div class="card shadow-sm">
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-6">
                <h5 class="mb-1">Invoice <?= h($inv_no) ?></h5>
                <div class="text-muted">Month: <?= h($ym ?: '-') ?></div>
                <?php if (!empty($inv['invoice_date'])): ?>
                  <div class="text-muted">Date: <?= h($inv['invoice_date']) ?></div>
                <?php endif; ?>
                <div class="mt-2">
                  <span class="badge text-bg-<?= h($badge) ?>"><?= h(ucfirst($status)) ?></span>
                </div>
              </div>
              <div class="col-md-6 text-md-end">
                <div>Total: <strong><?= number_format($total,2) ?></strong></div>
                <div>Paid: <strong><?= number_format($paid,2) ?></strong></div>
                <div>Due: <strong class="<?= $due>0.01?'text-danger':($due<-0.01?'text-success':'text-muted') ?>"><?= number_format($due,2) ?></strong></div>
              </div>
            </div>

            <hr>
            <div class="text-muted small">
              <!-- বাংলা: আইটেমাইজড লাইন দেখাতে হলে এখানে লাইন-আইটেম রেন্ডার যোগ করুন -->
              This is a simplified portal view. Use Print for printable layout.
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
