<?php
// /api/renew.php
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/audit.php';            // আগেই বানানো হেল্পার
// invoice_calc.php থাকলে include (না থাকলে ইগনোর)
$calc_path = __DIR__ . '/../app/invoice_calc.php';
if (file_exists($calc_path)) require_once $calc_path;

header('Content-Type: application/json');

function json_out($arr){ echo json_encode($arr); exit; }

$input = $_POST;
if (empty($input)) {
  // JSON body সাপোর্ট
  $input = json_decode(file_get_contents('php://input'), true) ?: [];
}

$client_id   = (int)($input['client_id'] ?? 0);
$months      = max(1, (int)($input['months'] ?? 1));
$start_on    = trim($input['start_on'] ?? '');       // YYYY-MM-DD | empty = auto
$mark_paid   = (int)($input['mark_paid'] ?? 0);      // 1 হলে সাথে সাথে paid
$pay_method  = trim($input['pay_method'] ?? '');     // Cash/bKash/...
$notes       = trim($input['notes'] ?? '');

if (!$client_id) json_out(['status'=>'error','message'=>'Invalid client_id']);

try{
  // Load client
  $st = db()->prepare("SELECT c.*, p.price AS pkg_price
                       FROM clients c
                       LEFT JOIN packages p ON p.id = c.package_id
                       WHERE c.id=? LIMIT 1");
  $st->execute([$client_id]);
  $c = $st->fetch(PDO::FETCH_ASSOC);
  if (!$c) json_out(['status'=>'error','message'=>'Client not found']);

  $monthly_bill = (float)($c['monthly_bill'] ?? 0);
  if (!$monthly_bill) $monthly_bill = (float)($c['pkg_price'] ?? 0);

  if ($monthly_bill <= 0) json_out(['status'=>'error','message'=>'Monthly bill not set for this client/package']);

  // Period calc
  $today = new DateTimeImmutable('today');
  if ($start_on && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_on)) {
    $period_start = new DateTimeImmutable($start_on);
  } else {
    // Auto: যদি আগের expiry_date আজ/ভবিষ্যৎ, তাহলে পরদিন থেকে; নাহলে আজ
    $exp = !empty($c['expiry_date']) ? new DateTimeImmutable($c['expiry_date']) : null;
    if ($exp && $exp >= $today) {
      $period_start = $exp->modify('+1 day');
    } else {
      $period_start = $today;
    }
  }
  // end = start + months - 1 day
  $period_end = $period_start->modify("+{$months} months")->modify('-1 day');

  // Amount (simple): months * monthly_bill (প্রোরেশন লাগলে invoice_calc.php ব্যবহার করুন)
  $amount = round($months * $monthly_bill, 2);

  // Invoice no generate: INV-YYYYMM-XXXX
  $ym = (new DateTime())->format('Ym');
  $st = db()->prepare("SELECT COUNT(*) FROM invoices WHERE invoice_no LIKE ?");
  $st->execute(["INV-$ym-%"]);
  $seq = (int)$st->fetchColumn() + 1;
  $invoice_no = sprintf('INV-%s-%04d', $ym, $seq);

  // Insert invoice
  $sql = "INSERT INTO invoices (client_id, invoice_no, period_from, period_to, months, amount, status, notes, created_at)
          VALUES (:cid, :inv, :pf, :pt, :m, :amt, 'unpaid', :notes, NOW())";
  $ins = db()->prepare($sql);
  $ok  = $ins->execute([
    ':cid'  => $client_id,
    ':inv'  => $invoice_no,
    ':pf'   => $period_start->format('Y-m-d'),
    ':pt'   => $period_end->format('Y-m-d'),
    ':m'    => $months,
    ':amt'  => $amount,
    ':notes'=> $notes ?: null
  ]);
  if (!$ok) json_out(['status'=>'error','message'=>'Invoice create failed']);

  $invoice_id = (int)db()->lastInsertId();

  // Mark paid (optional)
  if ($mark_paid) {
    // payments insert
    $p = db()->prepare("INSERT INTO payments (invoice_id, client_id, amount, method, ref_no, paid_at, notes)
                        VALUES (:iid, :cid, :amt, :m, :ref, NOW(), :n)");
    $p->execute([
      ':iid'=>$invoice_id, ':cid'=>$client_id, ':amt'=>$amount,
      ':m'=> ($pay_method ?: null), ':ref'=> null, ':n'=>$notes ?: null
    ]);
    // invoice status update
    db()->prepare("UPDATE invoices SET status='paid', paid_at=NOW() WHERE id=?")->execute([$invoice_id]);
  }

  // Update client expiry_date
  db()->prepare("UPDATE clients SET expiry_date=? WHERE id=?")
    ->execute([$period_end->format('Y-m-d'), $client_id]);

  // Audit log
  audit_log($client_id, 'renew', [
    'invoice_id'   => $invoice_id,
    'invoice_no'   => $invoice_no,
    'months'       => $months,
    'amount'       => $amount,
    'period_from'  => $period_start->format('Y-m-d'),
    'period_to'    => $period_end->format('Y-m-d'),
    'mark_paid'    => (bool)$mark_paid,
    'pay_method'   => $pay_method ?: null
  ]);

  json_out([
    'status' => 'success',
    'message'=> 'Renew successful',
    'invoice'=> [
      'id'          => $invoice_id,
      'invoice_no'  => $invoice_no,
      'amount'      => $amount,
      'status'      => $mark_paid ? 'paid' : 'unpaid',
      'period_from' => $period_start->format('Y-m-d'),
      'period_to'   => $period_end->format('Y-m-d')
    ],
    'new_expiry' => $period_end->format('Y-m-d')
  ]);

}catch(Exception $e){
  json_out(['status'=>'error','message'=>$e->getMessage()]);
}
