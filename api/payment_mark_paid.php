<?php
// /api/payment_mark_paid.php
// পেমেন্ট এন্ট্রি, ইনভয়েস স্ট্যাটাস আপডেট, ক্লায়েন্ট expiry_date বাড়ানো

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

function redirect($url){ header("Location: $url"); exit; }

if ($_SERVER['REQUEST_METHOD']!=='POST') redirect('../public/invoices.php');

$invoice_id = (int)($_POST['invoice_id'] ?? 0);
$client_id  = (int)($_POST['client_id'] ?? 0);
$amount     = (float)($_POST['amount'] ?? 0);
$method     = trim($_POST['method'] ?? '');
$txn_id     = trim($_POST['txn_id'] ?? '');
$remarks    = trim($_POST['remarks'] ?? '');
$paid_at_in = trim($_POST['paid_at'] ?? '');

if (!$invoice_id || !$client_id || $amount<=0 || !$paid_at_in){
  redirect('../public/invoices.php');
}

/* Load invoice */
$st = db()->prepare("SELECT * FROM invoices WHERE id=? AND client_id=?");
$st->execute([$invoice_id,$client_id]);
$inv = $st->fetch(PDO::FETCH_ASSOC);
if (!$inv) redirect('../public/invoices.php');

$pdo = db();
$pdo->beginTransaction();

try{
  /* Insert payment */
  $p = $pdo->prepare("INSERT INTO payments (invoice_id,client_id,amount,method,txn_id,paid_at,remarks)
                      VALUES (?,?,?,?,?,?,?)");
  $p->execute([$invoice_id,$client_id,$amount, ($method?:null), ($txn_id?:null), $paid_at_in, ($remarks?:null)]);

  /* Update invoice as paid (if fully paid; for now assume full) */
  $u = $pdo->prepare("UPDATE invoices SET status='paid', paid_at=?, payment_method=? WHERE id=?");
  $u->execute([$paid_at_in, ($method?:null), $invoice_id]);

  /* Extend client's expiry_date to invoice period_end if newer */
  $c = $pdo->prepare("UPDATE clients SET expiry_date = GREATEST(COALESCE(expiry_date,'1970-01-01'), ?) WHERE id=?");
  $c->execute([$inv['period_end'], $client_id]);

  /* audit */
  $a = $pdo->prepare("INSERT INTO audit_logs (user_id,action,entity,entity_id,meta) VALUES (?,?,?,?,?)");
  $a->execute([$_SESSION['user_id']??null,'invoice.mark_paid','invoice',$invoice_id,json_encode(['amount'=>$amount])]);

  $pdo->commit();
}catch(Throwable $e){
  $pdo->rollBack();
  // In production, log error
}

redirect('../public/invoice_view.php?id='.$invoice_id);
