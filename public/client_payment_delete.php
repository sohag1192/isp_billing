<?php
// /public/client_payment_delete.php
// UI: English; Comments: Bangla
// Deletes ONLY the latest payment for the client, only if payment month == current month.
// Also updates client billing columns (ledger_balance/balance/due/etc) so billing section reflects the delete.

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');

$ROOT = dirname(__DIR__, 1);
require_once $ROOT . '/app/require_login.php';
require_once $ROOT . '/app/db.php';

// (optional) ACL
$acl_file = $ROOT . '/app/acl.php';
if (is_file($acl_file)) require_once $acl_file;
if (function_exists('require_perm')) { require_perm('payments.delete'); }

if (session_status() === PHP_SESSION_NONE) { session_start(); }

/* ---------- method & CSRF ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  header('Location: /public/client_ledger.php'); exit;
}
$serverToken = (string)($_SESSION['csrf'] ?? '');
$clientToken = (string)($_POST['csrf_token'] ?? $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if ($serverToken !== '' && ($clientToken === '' || !hash_equals($serverToken, $clientToken))) {
  $_SESSION['flash_error'] = 'Invalid CSRF token.';
  header('Location: /public/client_ledger.php?client_id='.(int)($_POST['client_id'] ?? 0)); exit;
}

/* ---------- inputs ---------- */
$payment_id = (int)($_POST['payment_id'] ?? 0);
$client_id  = (int)($_POST['client_id'] ?? 0);
if ($payment_id<=0 || $client_id<=0) {
  $_SESSION['flash_error'] = 'Invalid request.';
  header('Location: /public/client_ledger.php?client_id='.$client_id); exit;
}

/* ---------- helpers ---------- */
function tbl_exists(PDO $pdo, string $t): bool {
  try { $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
        $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
        $q->execute([$db,$t]); return (bool)$q->fetchColumn();
  } catch (Throwable $e) { return false; }
}
function col_exists(PDO $pdo, string $t, string $c): bool {
  try { $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
        $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
        $q->execute([$db,$t,$c]); return (bool)$q->fetchColumn();
  } catch (Throwable $e) { return false; }
}
function pick_tbl(PDO $pdo, array $cands, ?string $fallback=null): ?string {
  foreach($cands as $t) if (tbl_exists($pdo,$t)) return $t;
  return ($fallback && tbl_exists($pdo,$fallback)) ? $fallback : null;
}
function pick_col(PDO $pdo, string $t, array $cands, ?string $fallback=null): ?string {
  foreach($cands as $c) if (col_exists($pdo,$t,$c)) return $c;
  return ($fallback && col_exists($pdo,$t,$fallback)) ? $fallback : null;
}

/* ---------- DB ---------- */
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------- detect payments schema ---------- */
$PAY_T = pick_tbl($pdo, ['payments']);
if (!$PAY_T) {
  $_SESSION['flash_error']='payments table missing.';
  header('Location: /public/client_ledger.php?client_id='.$client_id); exit;
}
$P_ID   = pick_col($pdo,$PAY_T,['id'],'id') ?? 'id';
$P_AMT  = pick_col($pdo,$PAY_T,['amount','amt'],'amount') ?? 'amount';
$P_DATE = pick_col($pdo,$PAY_T,['paid_at','created_at','date','payment_date']);
$P_CLI  = pick_col($pdo,$PAY_T,['client_id','customer_id','subscriber_id','user_id']);
if (!$P_CLI) {
  $_SESSION['flash_error']='client fk column missing on payments.';
  header('Location: /public/client_ledger.php?client_id='.$client_id); exit;
}

/* ---------- compute latest payment for this client ---------- */
$dtExpr = $P_DATE ? "p.`$P_DATE`" : "NULL";
$lastSql = "SELECT p.`$P_ID` AS id, $dtExpr AS paid_at
            FROM `$PAY_T` p
            WHERE p.`$P_CLI` = ?
            ORDER BY COALESCE($dtExpr, NOW()) DESC, p.`$P_ID` DESC
            LIMIT 1";
$st = $pdo->prepare($lastSql);
$st->execute([$client_id]);
$last = $st->fetch(PDO::FETCH_ASSOC);

if (!$last || (int)$last['id'] !== $payment_id) {
  $_SESSION['flash_error'] = 'Only the latest payment can be deleted.';
  header('Location: /public/client_ledger.php?client_id='.$client_id); exit;
}

/* ---------- fetch amount (to adjust client balances) ---------- */
$amtRow = $pdo->prepare("SELECT p.`$P_AMT` AS amount, p.`$P_CLI` AS client_fk, $dtExpr AS paid_at
                         FROM `$PAY_T` p WHERE p.`$P_ID` = ? LIMIT 1");
$amtRow->execute([$payment_id]);
$payInfo = $amtRow->fetch(PDO::FETCH_ASSOC);
if (!$payInfo) {
  $_SESSION['flash_error'] = 'Payment row not found.';
  header('Location: /public/client_ledger.php?client_id='.$client_id); exit;
}
$payAmt   = (float)($payInfo['amount'] ?? 0.0);
$payDate  = (string)($payInfo['paid_at'] ?? '');
$payCliId = (int)($payInfo['client_fk'] ?? 0);

/* ---------- month guard ---------- */
if ($payDate === '') {
  $_SESSION['flash_error'] = 'This payment has no valid date; deletion is blocked.';
  header('Location: /public/client_ledger.php?client_id='.$client_id); exit;
}
$ym_target = date('Y-m', strtotime($payDate));
$ym_now    = date('Y-m');
if ($ym_target !== $ym_now) {
  $_SESSION['flash_error'] = 'Payment month already passed; cannot delete.';
  header('Location: /public/client_ledger.php?client_id='.$client_id); exit;
}

/* ---------- detect client table + id column ---------- */
$CLIENT_T   = pick_tbl($pdo, ['clients','customers','subscribers','client_info','client']);
$CLI_IDCOL  = $CLIENT_T ? (pick_col($pdo,$CLIENT_T,['id','client_id','customer_id','subscriber_id','user_id','uid'],'id') ?? 'id') : null;

/* ---------- helper: adjust multiple billing columns ---------- */
/*
  বাংলা:
  - credit/balance টাইপ কলামে পেমেন্ট ডিলিট => amount বিয়োগ (minus)
  - due/receivable টাইপ কলামে পেমেন্ট ডিলিট => amount যোগ (plus)
  যদি আপনার স্কিমায় 'balance' আসলে due বোঝায়, তাহলে নীচের $creditCols/$dueCols লিস্টে অদলবদল করুন।
*/
function adjust_client_billing_balances(PDO $pdo, string $tbl, string $idcol, int $cid, float $amt): void {
  // কোন কোন কলাম থাকলে মিনাস/প্লাস হবে
  $creditCols = ['ledger_balance','balance','current_balance','wallet_balance','credit','advance','paid_total','payment_total'];
  $dueCols    = ['due','outstanding','receivable','current_due','total_due'];

  $sets = []; $bind = [':cid'=>$cid];

  // minus from credit-like
  foreach ($creditCols as $c) {
    if (col_exists($pdo,$tbl,$c)) {
      $sets[] = "`$c` = COALESCE(`$c`,0) - :amt";
    }
  }
  // plus to due-like
  foreach ($dueCols as $c) {
    if (col_exists($pdo,$tbl,$c)) {
      $sets[] = "`$c` = COALESCE(`$c`,0) + :amt";
    }
  }

  if (!$sets) return; // nothing to update

  $sql = "UPDATE `$tbl` SET ".implode(', ', $sets)." WHERE `$idcol` = :cid LIMIT 1";
  $st  = $pdo->prepare($sql);
  $st->execute([':amt'=>$amt] + $bind);
}

/* ---------- delete + adjust (transaction) ---------- */
try {
  $pdo->beginTransaction();

  // 1) Delete payment row
  $del = $pdo->prepare("DELETE FROM `$PAY_T` WHERE `$P_ID` = ? LIMIT 1");
  $del->execute([$payment_id]);
  if ($del->rowCount() < 1) { throw new Exception('Nothing deleted.'); }

  // 2) Adjust client balances (if table discovered)
  if ($CLIENT_T && $CLI_IDCOL) {
    adjust_client_billing_balances($pdo, $CLIENT_T, $CLI_IDCOL, ($payCliId ?: $client_id), $payAmt);
  }

  // 3) optional audit
  if (function_exists('audit_log')) {
    try {
      audit_log($_SESSION['user']['id'] ?? null, $payment_id, 'payment_delete', [
        'client_id'     => $client_id,
        'amount'        => $payAmt,
        'payment_month' => $ym_target,
        'clients_table' => $CLIENT_T,
        'id_col'        => $CLI_IDCOL,
      ]);
    } catch (Throwable $e) { /* ignore */ }
  }

  $pdo->commit();
  $_SESSION['flash_success'] = 'Latest payment deleted and billing balances updated.';
} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  $_SESSION['flash_error'] = 'Delete failed: ' . $e->getMessage();
}

header('Location: /public/client_ledger.php?client_id='.$client_id); exit;
