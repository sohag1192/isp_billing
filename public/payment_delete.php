<?php
// /public/payment_delete.php
// Soft-delete (fallback hard-delete) a payment, then recalc invoice status + client ledger
// Supports GET one-click: ?id=..&go=1&csrf=..&return=..
// UI English; Bangla comments only

declare(strict_types=1);

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

/* -------- CSRF helpers -------- */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
function csrf_token(){ return $_SESSION['csrf']; }
function csrf_check($t){ return is_string($t) && hash_equals($_SESSION['csrf'] ?? '', $t); }

/* -------- DB + helpers -------- */
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function col_exists(PDO $pdo,string $t,string $c):bool{
  try{
    $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
    $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
    $q->execute([$db,$t,$c]); return (bool)$q->fetchColumn();
  }catch(Throwable){ return false; }
}
function tbl_exists(PDO $pdo,string $t):bool{
  try{
    $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
    $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
    $q->execute([$db,$t]); return (bool)$q->fetchColumn();
  }catch(Throwable){ return false; }
}
function audit_payment_delete(PDO $pdo, int $payment_id, ?int $user_id, array $meta=[]): void {
  $meta_json = json_encode($meta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  try {
    if (function_exists('audit_log')) {
      try { audit_log($user_id, $payment_id, 'payment_delete', $meta_json); return; } catch(Throwable $e){}
      try { audit_log('payment_delete', $payment_id, $meta_json); return; } catch(Throwable $e){}
    }
    if (tbl_exists($pdo,'audit_logs')) {
      $st = $pdo->prepare("INSERT INTO audit_logs (event, entity, entity_id, user_id, meta, created_at) VALUES (?,?,?,?,?,NOW())");
      $st->execute(['payment_delete','payments',$payment_id,$user_id,$meta_json]);
    }
  } catch(Throwable $e) { /* ignore */ }
}

/* -------- Schema detect -------- */
/* prefer invoice_id then bill_id */
$payFk = col_exists($pdo,'payments','invoice_id') ? 'invoice_id'
       : (col_exists($pdo,'payments','bill_id') ? 'bill_id' : null);
$payHasClientId = col_exists($pdo,'payments','client_id');

$hasPayDeleteFlags = [
  'is_deleted' => col_exists($pdo,'payments','is_deleted'),
  'deleted_at' => col_exists($pdo,'payments','deleted_at'),
  'deleted_by' => col_exists($pdo,'payments','deleted_by'),
  'void'       => col_exists($pdo,'payments','void'),
  'status'     => col_exists($pdo,'payments','status'),
];

$invAmountCol = col_exists($pdo,'invoices','payable') ? 'payable'
             : (col_exists($pdo,'invoices','net_amount') ? 'net_amount'
             : (col_exists($pdo,'invoices','amount') ? 'amount'
             : (col_exists($pdo,'invoices','total')  ? 'total'  : 'total')));
$isNetInvAmount = in_array($invAmountCol, ['payable','net_amount','net_total'], true);
$hasPayDiscount = col_exists($pdo,'payments','discount');

/* pick a client ledger column if present */
$ledgerCols = ['ledger_balance','balance','wallet_balance','ledger'];
$clientLedgerCol = null; foreach ($ledgerCols as $lc) if (col_exists($pdo,'clients',$lc)) { $clientLedgerCol = $lc; break; }

/* -------- Active filter + recalc helpers -------- */
function payments_active_where(PDO $pdo, string $alias = 'pm'): string {
  $conds=[];
  if (col_exists($pdo,'payments','is_deleted')) $conds[]="$alias.is_deleted=0";
  if (col_exists($pdo,'payments','deleted_at')) $conds[]="$alias.deleted_at IS NULL";
  if (col_exists($pdo,'payments','void'))       $conds[]="$alias.void=0"; // void=0 => active
  if (col_exists($pdo,'payments','status'))     $conds[]="COALESCE($alias.status,'') NOT IN ('deleted','void','cancelled')";
  return $conds ? (' AND '.implode(' AND ',$conds)) : '';
}
function recalc_invoice_status(PDO $pdo, int $invoice_id, string $invAmountCol, bool $isNetInvAmount, bool $hasPayDiscount, ?string $payFk): void {
  if (!$payFk) return;
  $active = payments_active_where($pdo,'pm');
  $sql = "
    SELECT i.id, COALESCE(i.`$invAmountCol`,0) AS inv_amount,
           (SELECT COALESCE(SUM(pm.amount),0) FROM payments pm WHERE pm.`$payFk`=i.id $active) AS paid_sum
           ".($hasPayDiscount? ", (SELECT COALESCE(SUM(pm.discount),0) FROM payments pm WHERE pm.`$payFk`=i.id $active) AS disc_sum" : ", 0 AS disc_sum")."
    FROM invoices i WHERE i.id=? LIMIT 1";
  $st=$pdo->prepare($sql); $st->execute([$invoice_id]); $r=$st->fetch(PDO::FETCH_ASSOC); if(!$r) return;
  $inv=(float)$r['inv_amount']; $paid=(float)$r['paid_sum']; $disc=(float)$r['disc_sum'];
  $discUsed = $isNetInvAmount ? 0.0 : $disc;
  $remain = max(0.0, $inv - $discUsed - $paid);
  $status = ($remain<=0.0001)?'paid':(($paid>0)?'partial':'unpaid');

  // (বাংলা) updated_at কলাম থাকলে তবেই সেট করবো
  $set = "status=?";
  if (col_exists($pdo,'invoices','updated_at')) $set .= ", updated_at=NOW()";
  $u=$pdo->prepare("UPDATE invoices SET $set WHERE id=?");
  $u->execute([$status,$invoice_id]);
}
function recalc_client_ledger(PDO $pdo, int $client_id, string $invAmountCol, bool $isNetInvAmount, bool $hasPayDiscount, ?string $payFk, ?string $clientLedgerCol): void {
  if (!$clientLedgerCol) return;
  $st1=$pdo->prepare("SELECT COALESCE(SUM(i.`$invAmountCol`),0) FROM invoices i WHERE i.client_id=?");
  $st1->execute([$client_id]); $sumInv=(float)$st1->fetchColumn();
  $active = payments_active_where($pdo,'pm');
  if ($payFk) {
    $st2=$pdo->prepare("SELECT COALESCE(SUM(pm.amount),0), ".($hasPayDiscount?"COALESCE(SUM(pm.discount),0)":"0")." 
                        FROM payments pm JOIN invoices i ON pm.`$payFk`=i.id
                        WHERE i.client_id=? $active");
    $st2->execute([$client_id]); [$sumPaid,$sumDisc]=array_map('floatval',$st2->fetch(PDO::FETCH_NUM) ?: [0,0]);
  } else {
    if (col_exists($pdo,'payments','client_id')) {
      $st2=$pdo->prepare("SELECT COALESCE(SUM(pm.amount),0), ".($hasPayDiscount?"COALESCE(SUM(pm.discount),0)":"0")." 
                          FROM payments pm WHERE pm.client_id=? $active");
      $st2->execute([$client_id]); [$sumPaid,$sumDisc]=array_map('floatval',$st2->fetch(PDO::FETCH_NUM) ?: [0,0]);
    } else { $sumPaid=0.0; $sumDisc=0.0; }
  }
  $discUsed = $isNetInvAmount ? 0.0 : $sumDisc;
  $ledger = $sumInv - $discUsed - $sumPaid;

  // (বাংলা) clients.updated_at থাকলে যোগ করবো
  $set = "`$clientLedgerCol`=?";
  if (col_exists($pdo,'clients','updated_at')) $set .= ", updated_at=NOW()";
  $u=$pdo->prepare("UPDATE clients SET $set WHERE id=?");
  $u->execute([$ledger,$client_id]);
}

/* -------- Inputs -------- */
$payment_id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$return = (string)($_GET['return'] ?? $_POST['return'] ?? '');
if (!$return) $return = '/public/billing.php';
if ($payment_id <= 0) { header('Location: '.$return); exit; }

/* -------- Load base payment -------- */
$st = $pdo->prepare("
  SELECT pm.*,
         ".($payFk ? "pm.`$payFk` AS invoice_id" : "NULL AS invoice_id").",
         ".($payHasClientId ? "pm.client_id" : "NULL AS client_id")."
  FROM payments pm WHERE pm.id=? LIMIT 1
");
$st->execute([$payment_id]);
$pm = $st->fetch(PDO::FETCH_ASSOC);
if (!$pm) { header('Location: '.$return); exit; }

$invoice_id = (int)($pm['invoice_id'] ?? 0);
$client_id  = (int)($pm['client_id'] ?? 0);
if (!$client_id && $invoice_id) {
  $stc=$pdo->prepare("SELECT client_id FROM invoices WHERE id=?");
  $stc->execute([$invoice_id]); $client_id=(int)($stc->fetchColumn() ?: 0);
}

/* -------- Execute delete (POST) or one-click GET(go=1) -------- */
$goNow = ($_SERVER['REQUEST_METHOD']==='POST') || (isset($_GET['go']) && $_GET['go']=='1');

if ($goNow) {
  $token = $_SERVER['REQUEST_METHOD']==='POST' ? ($_POST['csrf'] ?? '') : ($_GET['csrf'] ?? '');
  if (!csrf_check($token)) { http_response_code(400); echo "Invalid CSRF token."; exit; }

  $pdo->beginTransaction();
  try{
    $user_id = (int)($_SESSION['user']['id'] ?? 0);

    // 1) Soft-delete flags; না থাকলে hard-delete
    $didSoft=false; $sets=[];
    if ($hasPayDeleteFlags['is_deleted']) $sets[]="is_deleted=1";
    if ($hasPayDeleteFlags['deleted_at']) $sets[]="deleted_at=NOW()";
    if ($hasPayDeleteFlags['deleted_by']) $sets[]="deleted_by=".$pdo->quote((string)$user_id);
    if ($hasPayDeleteFlags['void'])       $sets[]="void=1";
    if ($hasPayDeleteFlags['status'])     $sets[]="status='deleted'";
    if ($sets) {
      $sql="UPDATE payments SET ".implode(',', $sets)." WHERE id=?";
      $u=$pdo->prepare($sql); $u->execute([$payment_id]); $didSoft=true;
    }
    if (!$didSoft) {
      $u=$pdo->prepare("DELETE FROM payments WHERE id=?");
      $u->execute([$payment_id]);
    }

    // 2) Audit
    audit_payment_delete($pdo, $payment_id, $user_id, ['action'=>'delete','soft'=>$didSoft]);

    // 3) Recalc invoice + client ledger (updated_at handled conditionally inside)
    if ($invoice_id) recalc_invoice_status($pdo,$invoice_id,$invAmountCol,$isNetInvAmount,$hasPayDiscount,$payFk);
    if ($client_id && $clientLedgerCol) recalc_client_ledger($pdo,$client_id,$invAmountCol,$isNetInvAmount,$hasPayDiscount,$payFk,$clientLedgerCol);

    $pdo->commit();
    $sep = (strpos($return,'?') !== false) ? '&' : '?';
    header('Location: '.$return.$sep.'msg=payment_deleted&pid='.$payment_id);
    exit;
  }catch(Throwable $e){
    $pdo->rollBack();
    http_response_code(500);
    echo "Failed to delete payment: ".$e->getMessage();
    exit;
  }
}

/* -------- Confirm page (GET without go=1) -------- */
$amount = (float)($pm['amount'] ?? 0);
$created = h($pm['created_at'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Delete Payment</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>
<body class="p-3">
<div class="container">
  <div class="card shadow-sm">
    <div class="card-body">
      <h5 class="card-title text-danger">Delete Payment</h5>
      <p class="mb-1">Payment ID: <code>#<?= (int)$payment_id ?></code></p>
      <p class="mb-1">Amount: <strong>৳ <?= number_format($amount,2) ?></strong></p>
      <?php if($created): ?><p class="text-muted small mb-2">Created: <?= $created ?></p><?php endif; ?>
      <div class="alert alert-warning">Are you sure you want to delete this payment? This will update invoice status and client ledger.</div>

      <form method="post" class="d-flex gap-2">
        <input type="hidden" name="id" value="<?= (int)$payment_id ?>">
        <input type="hidden" name="return" value="<?= h($return) ?>">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <button class="btn btn-danger"><i class="bi bi-trash"></i> Confirm Delete</button>
        <a class="btn btn-secondary" href="<?= h($return) ?>">Cancel</a>
      </form>

      <div class="mt-3">
        <a class="btn btn-outline-danger btn-sm" href="/public/payment_delete.php?id=<?= (int)$payment_id ?>&go=1&csrf=<?= urlencode(csrf_token()) ?>&return=<?= rawurlencode($return) ?>"
           onclick="return confirm('Delete payment #<?= (int)$payment_id ?> ?');">
          One-click Delete (GET)
        </a>
      </div>
    </div>
  </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
</body>
</html>
