<?php
// /public/client_ledger.php
// Client-wise full ledger: invoices + payments (with GET+CSRF delete link)
// Shows both Ledger (DB) and Balance (Computed)
// UI English; Bangla comments only

declare(strict_types=1);

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

/* ---------- Session + CSRF ---------- */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

/* ---------- DB ---------- */
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------- Helpers ---------- */
function tbl_exists(PDO $pdo,string $t):bool{
  try{
    $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
    $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
    $q->execute([$db,$t]); return (bool)$q->fetchColumn();
  }catch(Throwable){ return false; }
}
function col_exists(PDO $pdo,string $t,string $c):bool{
  try{
    $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
    $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
    $q->execute([$db,$t,$c]); return (bool)$q->fetchColumn();
  }catch(Throwable){ return false; }
}

/* ---------- Inputs ---------- */
$client_id = (int)($_GET['client_id'] ?? 0);
if ($client_id <= 0) { http_response_code(400); echo "client_id required"; exit; }
$return = $_GET['return'] ?? '/public/billing.php';

/* ---------- Schema detect ---------- */
/* invoice amount column */
$invAmountCol = col_exists($pdo,'invoices','payable') ? 'payable'
             : (col_exists($pdo,'invoices','net_amount') ? 'net_amount'
             : (col_exists($pdo,'invoices','amount') ? 'amount'
             : (col_exists($pdo,'invoices','total')  ? 'total'  : 'total')));
$isNetInvAmount = in_array($invAmountCol, ['payable','net_amount','net_total'], true);

/* payments → prefer invoice_id then bill_id */
$payFk = col_exists($pdo,'payments','invoice_id') ? 'invoice_id'
       : (col_exists($pdo,'payments','bill_id') ? 'bill_id' : null);

$hasPayDiscount = col_exists($pdo,'payments','discount');

$hasPackages = tbl_exists($pdo,'packages') && col_exists($pdo,'clients','package_id') && col_exists($pdo,'packages','id');
$pkgNameCol  = $hasPackages && col_exists($pdo,'packages','name');
$pkgPriceCol = $hasPackages && (col_exists($pdo,'packages','price') || col_exists($pdo,'packages','rate') || col_exists($pdo,'packages','amount'));
$pkgPriceExpr = null;
if ($pkgPriceCol) { foreach (['price','rate','amount'] as $pc) if (col_exists($pdo,'packages',$pc)) { $pkgPriceExpr="p.`$pc`"; break; } }

$clientMobileCol = null;
foreach (['mobile','phone','cell','contact'] as $mc) if (col_exists($pdo,'clients',$mc)) { $clientMobileCol = $mc; break; }

/* client ledger column (DB) */
$ledgerCols = ['ledger_balance','balance','wallet_balance','ledger'];
$clientLedgerCol = null; foreach ($ledgerCols as $lc) if (col_exists($pdo,'clients',$lc)) { $clientLedgerCol = $lc; break; }

/* ---------- Active payment filter (soft-delete only) ----------
   (বাংলা) is_active=1 **দিচ্ছি না**—NULL/0 হলেও দেখাবে।
   শুধু ডিলিট/ভয়েড/ক্যান্সেল্ড বাদ দেই। */
function payments_active_where(PDO $pdo, string $alias='pm'): string {
  $c=[];
  if (col_exists($pdo,'payments','is_deleted')) $c[]="$alias.is_deleted=0";
  if (col_exists($pdo,'payments','deleted_at')) $c[]="$alias.deleted_at IS NULL";
  if (col_exists($pdo,'payments','void'))       $c[]="$alias.void=0";
  if (col_exists($pdo,'payments','status'))     $c[]="COALESCE($alias.status,'') NOT IN ('deleted','void','cancelled')";
  return $c ? (' AND '.implode(' AND ',$c)) : '';
}

/* ---------- Client header info ---------- */
$sqlClient = "SELECT c.*"
          .  ", ".($pkgNameCol?"p.name AS package_name":"NULL AS package_name")
          .  ", ".($pkgPriceExpr?("$pkgPriceExpr AS package_price"):"NULL AS package_price")
          .  ", ".($clientMobileCol?("c.`$clientMobileCol` AS mobile"):"NULL AS mobile")
          .  ", ".($clientLedgerCol?("c.`$clientLedgerCol` AS ledger_balance"):"0 AS ledger_balance")
          .  " FROM clients c "
          .  ($hasPackages ? "LEFT JOIN packages p ON p.id=c.package_id " : "")
          .  " WHERE c.id=? LIMIT 1";
$stc=$pdo->prepare($sqlClient); $stc->execute([$client_id]);
$client=$stc->fetch(PDO::FETCH_ASSOC);
if(!$client){ http_response_code(404); echo "Client not found"; exit; }

/* ---------- Invoices ---------- */
$sti=$pdo->prepare("SELECT i.* FROM invoices i WHERE i.client_id=? ORDER BY i.id DESC");
$sti->execute([$client_id]); $invoices=$sti->fetchAll(PDO::FETCH_ASSOC);

/* ---------- Payments (active only) ---------- */
$active = payments_active_where($pdo,'pm');
if ($payFk) {
  $stp=$pdo->prepare("
    SELECT pm.*, pm.id AS payment_id, i.id AS invoice_id
    FROM payments pm
    JOIN invoices i ON pm.`$payFk`=i.id
    WHERE i.client_id=? $active
    ORDER BY pm.id DESC
  ");
  $stp->execute([$client_id]); $payments=$stp->fetchAll(PDO::FETCH_ASSOC);
} else {
  $hasPayClientId = col_exists($pdo,'payments','client_id');
  if ($hasPayClientId) {
    $stp=$pdo->prepare("
      SELECT pm.*, pm.id AS payment_id, NULL AS invoice_id
      FROM payments pm
      WHERE pm.client_id=? $active
      ORDER BY pm.id DESC
    ");
    $stp->execute([$client_id]); $payments=$stp->fetchAll(PDO::FETCH_ASSOC);
  } else { $payments=[]; }
}

/* ---------- Totals & balances ---------- */
$sumInv = 0.0; foreach($invoices as $iv){ $sumInv += (float)($iv[$invAmountCol] ?? 0); }
$sumPaid = 0.0; $sumDisc = 0.0;
foreach($payments as $pm){ $sumPaid += (float)($pm['amount'] ?? 0); if ($hasPayDiscount) $sumDisc += (float)($pm['discount'] ?? 0); }
$discUsed = $isNetInvAmount ? 0.0 : $sumDisc;
$balanceComputed  = $sumInv - $discUsed - $sumPaid; // +ve = Due, -ve = Advance
$balanceDb = (float)($client['ledger_balance'] ?? 0);

/* ---------- Current URL ---------- */
$cur_url = $_SERVER['REQUEST_URI'] ?? ('/public/client_ledger.php?client_id='.$client_id);

/* ---------- Badges ---------- */
function money_badge(float $v): array {
  $cls = $v>0 ? 'text-danger' : ($v<0 ? 'text-success' : 'text-secondary');
  $label = ($v>=0?'Due':'Advance').' ৳ '.number_format(abs($v),2);
  return [$cls,$label];
}
[$clsDb,$labelDb]   = money_badge($balanceDb);
[$clsCmp,$labelCmp] = money_badge($balanceComputed);
$mismatch = (abs($balanceDb - $balanceComputed) > 0.01);

/* ---------- Header include (robust) ---------- */
ob_start();
$hdrIncluded = false;
$paths = [__DIR__ . '/../partials/partials_header.php', __DIR__ . '/../partials_header.php'];
foreach ($paths as $p) { if (is_file($p)) { include $p; $hdrIncluded=true; break; } }
$headerHtml = ob_get_clean();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Client Ledger</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
  .table-sm td, .table-sm th { padding: .5rem .6rem; vertical-align: middle; }
  .muted { color: #6c757d; }
  .mono { font-variant-numeric: tabular-nums; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; }
</style>
</head>
<body>
<?= $hdrIncluded ? $headerHtml : '' ?>

<div class="container-fluid p-3 p-md-4">

  <?php if(isset($_GET['msg']) && $_GET['msg']==='payment_deleted'): ?>
    <div class="alert alert-success py-2">Payment deleted & ledger updated.</div>
  <?php endif; ?>

  <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
    <div>
      <h4 class="mb-1">Client Ledger</h4>
      <div class="muted">
        <span class="fw-semibold"><?= h($client['name'] ?? 'Unknown') ?></span>
        <?php if(!empty($client['pppoe_id'])): ?> • PPPoE: <?= h($client['pppoe_id']) ?><?php endif; ?>
        <?php if(!empty($client['mobile'])): ?> • Cell: <?= h($client['mobile']) ?><?php endif; ?>
      </div>
      <div class="muted">
        Package: <?= h($client['package_name'] ?? '-') ?> 
        <?php if(isset($client['package_price'])): ?>
          • Price: <span class="mono">৳ <?= number_format((float)$client['package_price'],2) ?></span>
        <?php endif; ?>
      </div>
    </div>

    <div class="text-end">
      <div>Ledger (DB): <span class="mono <?= $clsDb ?>"><?= $labelDb ?></span></div>
      <div>Balance (Computed): <span class="mono <?= $clsCmp ?>"><?= $labelCmp ?></span></div>
      <?php if($mismatch): ?>
        <div class="small text-warning mt-1">
          Mismatch detected. Consider <a href="/public/rebuild_ledgers.php?do=run">rebuilding</a>.
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="mb-3 d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="<?= h($return) ?>"><i class="bi bi-arrow-left"></i> Back</a>
    <a class="btn btn-outline-success btn-sm" href="/public/payment_add.php?client_id=<?= (int)$client_id ?>&return=<?= rawurlencode($cur_url) ?>">
      <i class="bi bi-cash-coin"></i> Add Payment
    </a>
  </div>

  <?php if(!$invoices && !$payments): ?>
    <div class="alert alert-info">No invoices or payments found for this client yet.</div>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card shadow-sm">
        <div class="card-header fw-semibold">Invoices</div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm table-striped mb-0">
              <thead class="table-light">
                <tr>
                  <th>#ID</th>
                  <th>Month/Date</th>
                  <th>Status</th>
                  <th class="text-end">Amount</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
              <?php if($invoices): foreach($invoices as $iv): 
                $amt=(float)($iv[$invAmountCol]??0);
                $mon = $iv['billing_month'] ?? (($iv['year']??'').'-'.str_pad((string)($iv['month']??''),2,'0',STR_PAD_LEFT));
              ?>
                <tr class="<?= ($iv['status']??'')==='paid' ? 'table-success':'' ?>">
                  <td>#<?= (int)$iv['id'] ?></td>
                  <td>
                    <div class="fw-semibold"><?= h($mon ?: ($iv['invoice_date']??'')) ?></div>
                    <?php if(!empty($iv['invoice_date'])): ?>
                      <div class="small muted">Date: <?= h($iv['invoice_date']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td><span class="badge bg-<?= ($iv['status']==='paid'?'success':($iv['status']==='partial'?'warning text-dark':($iv['status']==='unpaid'?'danger':'secondary'))) ?>">
                    <?= ucfirst((string)$iv['status']) ?></span></td>
                  <td class="text-end mono">৳ <?= number_format($amt,2) ?></td>
                  <td><a class="btn btn-outline-secondary btn-sm" href="/public/invoice_view.php?id=<?= (int)$iv['id'] ?>"><i class="bi bi-receipt"></i></a></td>
                </tr>
              <?php endforeach; else: ?>
                <tr><td colspan="5" class="text-center text-muted">No invoices.</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div><!-- col -->

    <div class="col-lg-6">
      <div class="card shadow-sm">
        <div class="card-header fw-semibold">Payments</div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm table-striped mb-0">
              <thead class="table-light">
                <tr>
                  <th>#ID</th>
                  <th>Date</th>
                  <th>Invoice</th>
                  <th class="text-end">Amount</th>
                  <?php if($hasPayDiscount): ?><th class="text-end">Disc.</th><?php endif; ?>
                  <th>By</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php if($payments): foreach($payments as $pm):
                $pid=(int)$pm['payment_id'];
                $amount=(float)($pm['amount']??0);
                $disc=(float)($pm['discount']??0);
                $invId=(int)($pm['invoice_id']??0);
                $by = $pm['received_by'] ?? ($pm['user_name'] ?? ($pm['created_by'] ?? ''));
                if(!$by && isset($pm['user_id'])) $by = 'User#'.$pm['user_id'];

                // Absolute path + one-click GET delete (CSRF + confirm)
                $del_link = '/public/payment_delete.php?id='.$pid
                          .'&go=1&csrf='.urlencode($csrf)
                          .'&return='.rawurlencode($cur_url);
              ?>
                <tr>
                  <td>#<?= $pid ?></td>
                  <td>
                    <div class="fw-semibold"><?= h($pm['payment_date'] ?? ($pm['created_at'] ?? '')) ?></div>
                    <?php if(!empty($pm['method'])): ?><div class="small muted"><?= h($pm['method']) ?></div><?php endif; ?>
                  </td>
                  <td>
                    <?php if($invId): ?>
                      <a class="text-decoration-none" href="/public/invoice_view.php?id=<?= $invId ?>">#<?= $invId ?></a>
                    <?php else: ?>
                      <span class="text-muted">-</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-end mono">৳ <?= number_format($amount,2) ?></td>
                  <?php if($hasPayDiscount): ?><td class="text-end mono">৳ <?= number_format($disc,2) ?></td><?php endif; ?>
                  <td><?= h($by ?: '-') ?></td>
                  <td class="text-end">
                    <div class="btn-group btn-group-sm">
                      <a class="btn btn-outline-secondary" href="/public/receipt_payment.php?payment_id=<?= $pid ?>" title="Receipt"><i class="bi bi-printer"></i></a>
                      <a class="btn btn-outline-danger"
                         href="<?= h($del_link) ?>"
                         onclick="return confirm('Delete payment #<?= $pid ?>?');"
                         title="Delete"><i class="bi bi-trash"></i></a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; else: ?>
                <tr><td colspan="<?= $hasPayDiscount? '7':'6' ?>" class="text-center text-muted">No payments.</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div><!-- col -->
  </div><!-- row -->

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
</body>
</html>
