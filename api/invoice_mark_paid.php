<?php
// /api/invoice_mark_paid.php
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
header('Content-Type: application/json');

try{
  $data = json_decode(file_get_contents('php://input'), true) ?: [];
  $invoice_id = (int)($data['invoice_id'] ?? 0);
  $amount     = (float)($data['amount'] ?? 0);
  $method     = trim($data['method'] ?? '');
  $note       = trim($data['note'] ?? '');
  $ref        = trim($data['ref'] ?? '');

  if($invoice_id<=0 || $amount<=0){ throw new Exception('Invalid invoice/amount'); }

  $pdo = db();
  // load invoice
  $st = $pdo->prepare("SELECT i.*, c.id AS cid, c.expiry_date FROM invoices i
                       JOIN clients c ON c.id=i.client_id
                       WHERE i.id=? LIMIT 1");
  $st->execute([$invoice_id]);
  $inv = $st->fetch(PDO::FETCH_ASSOC);
  if(!$inv) throw new Exception('Invoice not found');

  $pdo->beginTransaction();
  // add payment
  $p = $pdo->prepare("INSERT INTO payments (invoice_id, client_id, amount, method, ref, note)
                      VALUES (?,?,?,?,?,?)");
  $p->execute([$invoice_id, (int)$inv['client_id'], $amount, ($method?:null), ($ref?:null), ($note?:null)]);

  // how much paid so far?
  $sum = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id=?");
  $sum->execute([$invoice_id]);
  $paid_total = (float)$sum->fetchColumn();

  $status = ($paid_total + 0.001 >= (float)$inv['payable']) ? 'paid' : 'partial';

  // update invoice status (+paid_at if paid)
  if($status==='paid'){
    $u = $pdo->prepare("UPDATE invoices SET status='paid', paid_at=NOW(), method=COALESCE(?, method) WHERE id=?");
    $u->execute([$method?:null, $invoice_id]);

    // extend expiry_date to invoice period_end if greater
    $end = new DateTimeImmutable($inv['period_end']);
    $cu = $pdo->prepare("UPDATE clients SET expiry_date=GREATEST(COALESCE(expiry_date,'1970-01-01'), ?) , last_payment_date=NOW() WHERE id=?");
    $cu->execute([$end->format('Y-m-d'), (int)$inv['client_id']]);
  }else{
    $u = $pdo->prepare("UPDATE invoices SET status='partial' WHERE id=?");
    $u->execute([$invoice_id]);
  }

  $pdo->commit();
  echo json_encode(['status'=>'success','message'=>'Payment recorded','paid_total'=>$paid_total,'invoice_status'=>$status]);
}catch(Exception $e){
  if(db()->inTransaction()) db()->rollBack();
  http_response_code(400);
  echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
