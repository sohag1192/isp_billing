<?php
// /api/invoice_create.php
// সেফ সিঙ্গেল-ইনভয়েস তৈরি (form POST → insert + redirect)

error_reporting(E_ALL);
ini_set('display_errors', '0'); // কোনো ওয়ার্নিং স্ক্রিনে না

ob_start(); // accidental আউটপুট ব্লক করতে

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

function redirect($url) {
  // output buffer পরিষ্কার করে তারপর রিডাইরেক্ট
  if (ob_get_length()) ob_end_clean();
  header('Location: ' . $url);
  exit;
}

// === Helpers ===

// next invoice number: INV-YYYYMM-0001 style (month-scoped)
function next_invoice_number(PDO $pdo, string $ym): string {
  $prefix = 'INV-' . str_replace('-', '', $ym) . '-';
  $start  = $ym . '-01';
  $end    = date('Y-m-t', strtotime($start));

  // কতগুলো আছে গুনে আনুমানিক সিকোয়েন্স, কনফ্লিক্ট হলে বাড়াবে
  $q = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE billing_month BETWEEN ? AND ?");
  $q->execute([$start, $end]);
  $n = (int)$q->fetchColumn();
  $seq = $n + 1;

  do {
    $code = $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
    $t = $pdo->prepare("SELECT 1 FROM invoices WHERE invoice_number=? LIMIT 1");
    $t->execute([$code]);
    if (!$t->fetch()) return $code;
    $seq++;
  } while (true);
}

// totals calc aligned to your schema
function compute_totals(float $amount, float $discount, float $vat_percent): array {
  $amount   = round(max(0, $amount), 2);
  $discount = round(max(0, $discount), 2);
  $base     = max(0, $amount - $discount);
  $vat_p    = round(max(0, $vat_percent), 2);
  $vat_amt  = round($base * ($vat_p/100), 2);
  $total    = round($base + $vat_amt, 2);
  return [
    'subtotal'     => $amount,
    'discount'     => $discount,
    'vat_percent'  => $vat_p,
    'vat_amount'   => $vat_amt,
    'total'        => $total,
    'payable'      => $total,
    'total_amount' => $total,
  ];
}

// === Guard ===
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  redirect('../public/invoice_new.php');
}

try {
  $pdo = db();

  // ---- Collect & sanitize ----
  $client_id    = (int)($_POST['client_id'] ?? 0);
  $period_start = trim($_POST['period_start'] ?? '');
  $period_end   = trim($_POST['period_end'] ?? '');
  $amount_in    = isset($_POST['amount']) && $_POST['amount'] !== '' ? (float)$_POST['amount'] : null;
  $discount     = (float)($_POST['discount'] ?? 0);
  $vat_percent  = (float)($_POST['vat_percent'] ?? 0);
  $due_date_in  = trim($_POST['due_date'] ?? '');
  $notes        = trim($_POST['notes'] ?? '');

  if ($client_id <= 0)               throw new RuntimeException('Select client.');
  if (!$period_start || !$period_end) throw new RuntimeException('Select period.');
  if (strtotime($period_end) < strtotime($period_start)) throw new RuntimeException('Invalid period.');

  // ---- Load client (also get monthly_bill & package_id) ----
  $cs = $pdo->prepare("SELECT id, monthly_bill, package_id FROM clients WHERE id=? AND is_deleted=0");
  $cs->execute([$client_id]);
  $client = $cs->fetch(PDO::FETCH_ASSOC);
  if (!$client) throw new RuntimeException('Client not found.');

  // amount fallback: use monthly_bill if POST empty
  $amount = ($amount_in !== null) ? $amount_in : (float)$client['monthly_bill'];
  if ($amount <= 0) throw new RuntimeException('Amount is required.');

  // ---- Duplicate check (client+period) ----
  $chk = $pdo->prepare("SELECT id FROM invoices WHERE client_id=? AND period_start=? AND period_end=? LIMIT 1");
  $chk->execute([$client_id, $period_start, $period_end]);
  if ($chk->fetch()) throw new RuntimeException('Invoice already exists for this period.');

  // ---- Derive misc fields ----
  $months        = 1; // single month
  $billing_month = $period_start; // date
  $invoice_date  = date('Y-m-d');
  $due_date      = $due_date_in !== '' ? $due_date_in : $period_end; // fallback → period_end
  $package_id    = isset($client['package_id']) ? (int)$client['package_id'] : null;
  $created_by    = $_SESSION['user_id'] ?? null;

  // ---- Totals ----
  $t = compute_totals($amount, $discount, $vat_percent);

  // ---- Invoice number ----
  $ym = substr($period_start, 0, 7); // YYYY-MM
  $inv_no = next_invoice_number($pdo, $ym);

  // ---- Insert ----
  $ins = $pdo->prepare(
    "INSERT INTO invoices
     (client_id, period_start, period_end, months, amount, billing_month,
      package_id, invoice_number, invoice_date, due_date,
      subtotal, discount, vat_percent, vat_amount, total, payable, total_amount,
      status, created_by, created_at, note, notes)
     VALUES
     (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'unpaid', ?, NOW(), NULL, ?)"
  );

  $ins->execute([
    $client_id, $period_start, $period_end, $months, $amount, $billing_month,
    $package_id, $inv_no, $invoice_date, $due_date,
    $t['subtotal'], $t['discount'], $t['vat_percent'], $t['vat_amount'],
    $t['total'], $t['payable'], $t['total_amount'],
    $created_by, ($notes ?: null)
  ]);

  $invoice_id = (int)$pdo->lastInsertId();

  redirect('../public/invoice_view.php?id=' . $invoice_id);

} catch (Throwable $e) {
  // error হলে form-এ ফিরে যাক; message পাস করতে চাইলে session flash ব্যবহার করুন
  // (বাংলা নির্দেশনা: চাইলে এখানে $_SESSION['flash_error'] সেট করে redirect করুন)
  redirect('../public/invoice_new.php');
}
