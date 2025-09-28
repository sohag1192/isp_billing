<?php
// /public/tools/rebuild_ledgers.php
// Recalculate ALL invoices' statuses and ALL clients' ledgers
// UI English; Bangla comments only

declare(strict_types=1);
require_once __DIR__ . '/../../app/require_login.php';
require_once __DIR__ . '/../../app/db.php';

function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function col_exists(PDO $pdo,string $t,string $c):bool{
  try{
    $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
    $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
    $q->execute([$db,$t,$c]); return (bool)$q->fetchColumn();
  }catch(Throwable){ return false; }
}

$payFk = col_exists($pdo,'payments','bill_id') ? 'bill_id'
       : (col_exists($pdo,'payments','invoice_id') ? 'invoice_id' : null);
$invAmountCol = col_exists($pdo,'invoices','payable') ? 'payable'
             : (col_exists($pdo,'invoices','amount') ? 'amount'
             : (col_exists($pdo,'invoices','total')  ? 'total'  : 'total'));
$isNetInvAmount = in_array($invAmountCol, ['payable','net_amount','net_total'], true);
$hasPayDiscount = col_exists($pdo,'payments','discount');

$ledgerCols = ['ledger_balance','balance','wallet_balance','ledger'];
$clientLedgerCol = null;
foreach ($ledgerCols as $lc) if (col_exists($pdo,'clients',$lc)) { $clientLedgerCol = $lc; break; }

function payments_active_where(PDO $pdo, string $alias='pm'): string {
  $conds=[];
  if (col_exists($pdo,'payments','is_deleted')) $conds[]="$alias.is_deleted=0";
  if (col_exists($pdo,'payments','deleted_at')) $conds[]="$alias.deleted_at IS NULL";
  if (col_exists($pdo,'payments','is_active'))  $conds[]="$alias.is_active=1";
  if (col_exists($pdo,'payments','void'))       $conds[]="$alias.void=0";
  if (col_exists($pdo,'payments','status'))     $conds[]="COALESCE($alias.status,'') NOT IN ('deleted','void','cancelled')";
  return $conds ? (' AND '.implode(' AND ',$conds)) : '';
}

function recalc_invoice(PDO $pdo, int $invoice_id, string $invAmountCol, bool $isNetInvAmount, bool $hasPayDiscount, ?string $payFk): void {
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
  $u=$pdo->prepare("UPDATE invoices SET status=?, updated_at=NOW() WHERE id=?");
  $u->execute([$status,$invoice_id]);
}

function recalc_client(PDO $pdo, int $client_id, string $invAmountCol, bool $isNetInvAmount, bool $hasPayDiscount, ?string $payFk, ?string $clientLedgerCol): void {
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
  $discUsed=$isNetInvAmount?0.0:$sumDisc;
  $ledger = $sumInv - $discUsed - $sumPaid;
  $u=$pdo->prepare("UPDATE clients SET `$clientLedgerCol`=?, updated_at=NOW() WHERE id=?");
  $u->execute([$ledger,$client_id]);
}

/* ---------- Run ---------- */
$msg = '';
if (($_GET['do'] ?? '') === 'run') {
  $pdo->beginTransaction();
  try{
    foreach ($pdo->query("SELECT id FROM invoices") as $r) {
      recalc_invoice($pdo,(int)$r['id'],$invAmountCol,$isNetInvAmount,$hasPayDiscount,$payFk);
    }
    foreach ($pdo->query("SELECT id FROM clients") as $r) {
      recalc_client($pdo,(int)$r['id'],$invAmountCol,$isNetInvAmount,$hasPayDiscount,$payFk,$clientLedgerCol);
    }
    $pdo->commit();
    $msg = "Rebuild complete.";
  }catch(Throwable $e){
    $pdo->rollBack();
    $msg = "Failed: ".$e->getMessage();
  }
} else {
  $msg = "Click the button to rebuild all invoices & ledgers.";
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Rebuild Ledgers</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
</head>
<body class="p-3">
  <div class="container">
    <div class="card shadow-sm">
      <div class="card-body">
        <h5 class="card-title">Rebuild Invoices & Client Ledgers</h5>
        <p class="text-muted"><?= h($msg) ?></p>
        <a class="btn btn-primary" href="?do=run">Run Rebuild</a>
      </div>
    </div>
  </div>
</body>
</html>
