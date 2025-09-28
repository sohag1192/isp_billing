<?php
// /api/invoice_quick_renew.php
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

header('Content-Type: application/json');

try{
  $data = json_decode(file_get_contents('php://input'), true) ?: [];
  $client_id = (int)($data['client_id'] ?? 0);
  $months    = max(1, (int)($data['months'] ?? 1));
  $discount  = max(0, (float)($data['discount'] ?? 0));
  $mark_paid = !empty($data['mark_paid']);
  $method    = trim($data['method'] ?? '');
  $note      = trim($data['note'] ?? '');

  if($client_id<=0){ throw new Exception('Invalid client_id'); }

  // Load client
  $st = db()->prepare("SELECT id, name, monthly_bill, expiry_date, status, is_left FROM clients WHERE id=? LIMIT 1");
  $st->execute([$client_id]);
  $c = $st->fetch(PDO::FETCH_ASSOC);
  if(!$c) throw new Exception('Client not found');
  if((int)$c['is_left'] === 1) throw new Exception('Client is marked LEFT');
  $bill = (float)($c['monthly_bill'] ?? 0);
  if($bill<=0) throw new Exception('Client monthly_bill missing');

  // Period calc
  $today = new DateTimeImmutable('today');
  $from  = $today;
  if(!empty($c['expiry_date'])){
    $prevEnd = new DateTimeImmutable($c['expiry_date']);
    $candidate = $prevEnd->modify('+1 day');
    if($candidate > $from) $from = $candidate; // পরেরদিন থেকে শুরু
  }
  $to = $from->modify("+{$months} month")->modify('-1 day');

  $amount  = round($bill * $months, 2);
  $payable = max(0, round($amount - $discount, 2));

  $pdo = db();
  $pdo->beginTransaction();

  // Insert invoice
  $ins = $pdo->prepare("INSERT INTO invoices
    (client_id, period_start, period_end, months, amount, discount, payable, status, note)
    VALUES (:cid,:ps,:pe,:m,:amt,:disc,:pay,'unpaid',:note)");
  $ins->execute([
    ':cid'=>$client_id, ':ps'=>$from->format('Y-m-d'), ':pe'=>$to->format('Y-m-d'),
    ':m'=>$months, ':amt'=>$amount, ':disc'=>$discount, ':pay'=>$payable, ':note'=>$note ?: null
  ]);
  $invoice_id = (int)$pdo->lastInsertId();

  // If mark_paid => record payment & mark paid
  if($mark_paid){
    // payment
    $p = $pdo->prepare("INSERT INTO payments (invoice_id, client_id, amount, method, ref, note)
                        VALUES (?,?,?,?,?,?)");
    $p->execute([$invoice_id, $client_id, $payable, ($method?:null), null, $note?:null]);

    // invoice -> paid
    $u = $pdo->prepare("UPDATE invoices SET status='paid', paid_at=NOW(), method=? WHERE id=?");
    $u->execute([$method?:null, $invoice_id]);

    // client -> extend expiry_date
    $cu = $pdo->prepare("UPDATE clients SET expiry_date=?, last_payment_date=NOW() WHERE id=?");
    $cu->execute([$to->format('Y-m-d'), $client_id]);
  }

  $pdo->commit();

  echo json_encode([
    'status'=>'success',
    'message'=>'Invoice created'.($mark_paid?' & paid':''),
    'invoice_id'=>$invoice_id,
    'period'=>[$from->format('Y-m-d'), $to->format('Y-m-d')],
    'amount'=>$amount, 'discount'=>$discount, 'payable'=>$payable
  ]);
}catch(Exception $e){
  if(db()->inTransaction()) { db()->rollBack(); }
  http_response_code(400);
  echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
