<?php
// /public/billing_discount_api.php
// Manage discounts for billing list (list / delete)
// UI: JSON API; Comments: বাংলা

declare(strict_types=1);

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

// (অপশনাল) ACL থাকলে
$acl_file = __DIR__ . '/../app/acl.php';
if (is_file($acl_file)) require_once $acl_file;

// ---------- helpers ----------
function jexit($ok, $data = [], $code = 200) {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => $ok] + $data, JSON_UNESCAPED_UNICODE);
  exit;
}
function col_exists(PDO $pdo,string $t,string $c):bool{
  try{ $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
    $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
    $q->execute([$db,$t,$c]); return (bool)$q->fetchColumn();
  }catch(Throwable){ return false; }
}
function payments_active_where(PDO $pdo, string $alias='pm'): string {
  $c=[];
  if (col_exists($pdo,'payments','is_deleted')) $c[]="$alias.is_deleted=0";
  if (col_exists($pdo,'payments','deleted_at')) $c[]="$alias.deleted_at IS NULL";
  if (col_exists($pdo,'payments','void'))       $c[]="$alias.void=0";
  if (col_exists($pdo,'payments','status'))     $c[]="COALESCE($alias.status,'') NOT IN ('deleted','void','cancelled')";
  return $c ? (' AND '.implode(' AND ',$c)) : '';
}
function find_invoice_discount_col(PDO $pdo): ?string {
  $cands=['discount','bill_discount','discount_amount','disc','inv_discount','pdiscount','p_discount','prev_discount','previous_discount','package_discount','plan_discount','promo_discount'];
  foreach($cands as $c) if (col_exists($pdo,'invoices',$c)) return $c;
  try{
    $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
    $q=$pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='invoices' AND COLUMN_NAME LIKE '%discount%' LIMIT 1");
    $q->execute([$db]); $col=$q->fetchColumn();
    return $col? (string)$col : null;
  }catch(Throwable){ return null; }
}

// ---------- CSRF ----------
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$csrf = $_POST['csrf'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
  jexit(false, ['error' => 'Invalid CSRF'], 400);
}

// ---------- ACL (optional but recommended) ----------
if (function_exists('require_perm')) {
  // বাংলা: ডিসকাউন্ট মুছতে পারমিশন লাগবে
  try { require_perm('billing.discount'); }
  catch(Throwable $e){ jexit(false, ['error'=>'Not permitted'], 403); }
}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ---------- Schema detect ----------
$payFk = col_exists($pdo,'payments','invoice_id') ? 'invoice_id'
       : (col_exists($pdo,'payments','bill_id') ? 'bill_id' : null);
$hasPayDiscount = col_exists($pdo,'payments','discount');
$payDateCol = col_exists($pdo,'payments','payment_date') ? 'payment_date'
            : (col_exists($pdo,'payments','created_at') ? 'created_at' : null);

$invoiceDiscCol = find_invoice_discount_col($pdo);

$action = $_POST['action'] ?? '';
try {
  if ($action === 'list') {
    // বাংলা: নির্দিষ্ট invoice_id-এর discount ব্রেকডাউন দেখাও
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);
    if ($invoice_id <= 0) jexit(false, ['error'=>'Missing invoice_id'], 400);

    // invoice-level discount
    $inv_discount = 0.0;
    if ($invoiceDiscCol) {
      $st = $pdo->prepare("SELECT COALESCE(`$invoiceDiscCol`,0) FROM invoices WHERE id=?");
      $st->execute([$invoice_id]); $inv_discount = (float)$st->fetchColumn();
    }

    // payment-based discounts
    $payRows = [];
    if ($payFk && $hasPayDiscount) {
      $payActive = payments_active_where($pdo,'pm');
      $sql = "SELECT pm.id, pm.amount, pm.discount, ".($payDateCol? "pm.`$payDateCol`":"NULL")." AS pdate, COALESCE(pm.method,'') AS method, COALESCE(pm.txn_id,'') AS txn_id
              FROM payments pm WHERE pm.`$payFk`=? AND COALESCE(pm.discount,0) > 0 $payActive
              ORDER BY pm.id DESC";
      $st = $pdo->prepare($sql); $st->execute([$invoice_id]);
      $payRows = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    jexit(true, ['invoice_discount'=>$inv_discount, 'payments'=>$payRows]);
  }

  if ($action === 'delete_payment') {
    // বাংলা: নির্দিষ্ট payment.id-এর discount শূন্য করে দাও (soft delete)
    $payment_id = (int)($_POST['payment_id'] ?? 0);
    if ($payment_id <= 0) jexit(false, ['error'=>'Missing payment_id'], 400);
    if (!$hasPayDiscount) jexit(false, ['error'=>'payments.discount column not found'], 400);

    $pdo->beginTransaction();
    $st = $pdo->prepare("UPDATE payments SET discount=0 WHERE id=?");
    $st->execute([$payment_id]);

    // (অপশনাল) audit trail
    if (col_exists($pdo,'payments','updated_at')) {
      $pdo->prepare("UPDATE payments SET updated_at=NOW() WHERE id=?")->execute([$payment_id]);
    }
    $pdo->commit();
    jexit(true, ['message'=>'Payment discount cleared']);
  }

  if ($action === 'clear_invoice') {
    // বাংলা: invoice-level discount (invoices.discount*) শূন্য করো
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);
    if ($invoice_id <= 0) jexit(false, ['error'=>'Missing invoice_id'], 400);
    if (!$invoiceDiscCol) jexit(false, ['error'=>'Invoice discount column not found'], 400);

    $pdo->beginTransaction();
    $st = $pdo->prepare("UPDATE invoices SET `$invoiceDiscCol`=0 WHERE id=?");
    $st->execute([$invoice_id]);
    if (col_exists($pdo,'invoices','updated_at')) {
      $pdo->prepare("UPDATE invoices SET updated_at=NOW() WHERE id=?")->execute([$invoice_id]);
    }
    $pdo->commit();
    jexit(true, ['message'=>'Invoice discount cleared']);
  }

  jexit(false, ['error'=>'Unknown action'], 400);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  jexit(false, ['error'=>$e->getMessage()], 500);
}
