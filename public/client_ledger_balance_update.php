<?php
// /public/client_ledger_balance_update.php
// UI: English; Comments: বাংলা
// Feature: Update client's ledger_balance via POST (mode=set|delta), optional ledger row insert, audit-safe.

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');

$ROOT = dirname(__DIR__, 1);
require_once $ROOT . '/app/require_login.php';
require_once $ROOT . '/app/db.php';
$acl_file = $ROOT . '/app/acl.php';
if (is_file($acl_file)) require_once $acl_file;

if (session_status() === PHP_SESSION_NONE) { session_start(); }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ---------- CSRF ----------
$csrf = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
  $_SESSION['flash_error'] = 'Invalid request (CSRF).';
  header('Location: /public/client_ledger.php'); exit;
}

// ---------- ACL ----------
if (function_exists('require_perm')) {
  // বাংলা: ব্যালেন্স এডিট করার জন্য ledger.edit লাগবে
  require_perm('ledger.edit');
}

// ---------- Inputs ----------
$client_id = (int)($_POST['client_id'] ?? 0);
$mode      = strtolower(trim((string)($_POST['mode'] ?? 'delta')));
$amount    = (float)($_POST['amount'] ?? 0);
$note      = trim((string)($_POST['note'] ?? ''));

// বাংলা: ক্লায়েন্ট আইডি বা অ্যামাউন্ট ভ্যালিড না
if ($client_id <= 0 || !in_array($mode, ['set','delta'], true)) {
  $_SESSION['flash_error'] = 'Invalid input.';
  header('Location: /public/client_ledger.php'); exit;
}

// ---------- DB helpers ----------
function tbl_exists(PDO $pdo, string $t): bool {
  try{ $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
       $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
       $q->execute([$db,$t]); return (bool)$q->fetchColumn(); }catch(Throwable $e){ return false; }
}
function col_exists(PDO $pdo, string $t, string $c): bool {
  try{ $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
       $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
       $q->execute([$db,$t,$c]); return (bool)$q->fetchColumn(); }catch(Throwable $e){ return false; }
}
function pick_tbl(PDO $pdo, array $cands, ?string $fallback=null): ?string {
  foreach ($cands as $t) if (tbl_exists($pdo,$t)) return $t;
  return ($fallback && tbl_exists($pdo,$fallback)) ? $fallback : null;
}
function guess_id_col(PDO $pdo, string $t): string {
  $cands = ['id','client_id','c_id','cid','customer_id','subscriber_id','user_id','uid','acc_id','account_id','cl_id'];
  foreach ($cands as $c) if (col_exists($pdo,$t,$c)) return $c;
  try{
    $pk=$pdo->query("SHOW KEYS FROM `$t` WHERE Key_name='PRIMARY'")->fetch(PDO::FETCH_ASSOC);
    if ($pk && !empty($pk['Column_name']) && col_exists($pdo,$t,$pk['Column_name'])) return $pk['Column_name'];
  }catch(Throwable $e){}
  try{
    $cols=$pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN);
    if ($cols && isset($cols[0])) return (string)$cols[0];
  }catch(Throwable $e){}
  return 'id';
}

// ---------- Detect schema ----------
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$CLIENT_T = pick_tbl($pdo, ['clients','customers','subscribers','client_info','client']);
if (!$CLIENT_T || !col_exists($pdo, $CLIENT_T, 'ledger_balance')) {
  $_SESSION['flash_error'] = 'clients.ledger_balance not found.';
  header('Location: /public/client_ledger.php'); exit;
}
$C_ID   = guess_id_col($pdo, $CLIENT_T);
$C_NAME = col_exists($pdo,$CLIENT_T,'name') ? 'name' : (col_exists($pdo,$CLIENT_T,'client_name') ? 'client_name' : $C_ID);

// Optional ledger table (for history row)
$LEDGER_T = pick_tbl($pdo, ['client_ledger','client_ledgers','ledger','ledgers','client_ledger_entries']);
$L_ID   = $LEDGER_T ? guess_id_col($pdo,$LEDGER_T) : null;
$L_CLI  = $LEDGER_T ? guess_id_col($pdo,$LEDGER_T) : null;
if ($LEDGER_T) { foreach (['client_id','customer_id','subscriber_id','user_id','cid','c_id'] as $fk) { if (col_exists($pdo,$LEDGER_T,$fk)) { $L_CLI=$fk; break; } } }
$L_DATE = $LEDGER_T ? (col_exists($pdo,$LEDGER_T,'date') ? 'date' : (col_exists($pdo,$LEDGER_T,'entry_date') ? 'entry_date' : (col_exists($pdo,$LEDGER_T,'created_at') ? 'created_at' : (col_exists($pdo,$LEDGER_T,'paid_at') ? 'paid_at' : null)))) : null;
$L_AMT  = $LEDGER_T ? (col_exists($pdo,$LEDGER_T,'amount') ? 'amount' : (col_exists($pdo,$LEDGER_T,'amt') ? 'amt' : (col_exists($pdo,$LEDGER_T,'value') ? 'value' : (col_exists($pdo,$LEDGER_T,'money') ? 'money' : (col_exists($pdo,$LEDGER_T,'total') ? 'total' : null))))) : null;
$L_DEB  = $LEDGER_T ? (col_exists($pdo,$LEDGER_T,'debit') ? 'debit' : (col_exists($pdo,$LEDGER_T,'dr') ? 'dr' : null)) : null;
$L_CRE  = $LEDGER_T ? (col_exists($pdo,$LEDGER_T,'credit') ? 'credit' : (col_exists($pdo,$LEDGER_T,'cr') ? 'cr' : null)) : null;
$L_TYPE = $LEDGER_T ? (col_exists($pdo,$LEDGER_T,'type') ? 'type' : (col_exists($pdo,$LEDGER_T,'entry_type') ? 'entry_type' : (col_exists($pdo,$LEDGER_T,'drcr') ? 'drcr' : (col_exists($pdo,$LEDGER_T,'dr_cr') ? 'dr_cr' : null)))) : null;
$L_NOTE = $LEDGER_T ? (col_exists($pdo,$LEDGER_T,'note') ? 'note' : (col_exists($pdo,$LEDGER_T,'remarks') ? 'remarks' : (col_exists($pdo,$LEDGER_T,'remark') ? 'remark' : (col_exists($pdo,$LEDGER_T,'memo') ? 'memo' : (col_exists($pdo,$LEDGER_T,'description') ? 'description' : (col_exists($pdo,$LEDGER_T,'reference') ? 'reference' : null)))))) : null;

// ---------- Fetch current client ----------
$st = $pdo->prepare("SELECT `$C_ID` AS id, `$C_NAME` AS name, `ledger_balance` AS ledger_balance FROM `$CLIENT_T` WHERE `$C_ID`=? LIMIT 1");
$st->execute([$client_id]);
$cli = $st->fetch(PDO::FETCH_ASSOC);
if (!$cli) {
  $_SESSION['flash_error'] = 'Client not found.';
  header('Location: /public/client_ledger.php'); exit;
}
$old_balance = (float)$cli['ledger_balance'];

// ---------- Compute delta ----------
$delta = ($mode === 'set') ? ((float)$amount - $old_balance) : (float)$amount;
// বাংলা: ডেল্টা 0 হলে কিছুই করার দরকার নেই
if (abs($delta) < 0.0000001) {
  $_SESSION['flash_success'] = 'No change applied (amount equals current balance).';
  header('Location: /public/client_ledger.php?client_id='.(int)$client_id); exit;
}

$new_balance = $old_balance + $delta;

// ---------- Tx begin ----------
$pdo->beginTransaction();
try {
  // Update client balance
  $up = $pdo->prepare("UPDATE `$CLIENT_T` SET `ledger_balance`=? WHERE `$C_ID`=?");
  $up->execute([$new_balance, $client_id]);

  // Optional: insert a ledger row
  if ($LEDGER_T && $L_CLI) {
    // বাংলা: স্কিমা অনুযায়ী ডেবিট/ক্রেডিট বা amount+type ব্যবহার করা হবে
    if ($L_DEB && $L_CRE) {
      // delta >= 0 => client owes more (charge/debit), delta < 0 => payment/credit
      $debit  = max(0,  $delta);
      $credit = max(0, -$delta);
      $cols = ["`$L_CLI`","`$L_DEB`","`$L_CRE`"];
      $vals = ["?","?","?"];
      $args = [$client_id, $debit, $credit];
      if ($L_DATE){ $cols[]="`$L_DATE`"; $vals[]="NOW()"; }
      if ($L_TYPE){ $cols[]="`$L_TYPE`"; $vals[]="?"; $args[] = ($delta>=0 ? 'adjustment_debit' : 'adjustment_credit'); }
      if ($L_NOTE){ $cols[]="`$L_NOTE`"; $vals[]="?"; $args[] = ($note !== '' ? $note : 'balance adjustment'); }
      $sql = "INSERT INTO `$LEDGER_T` (".implode(',', $cols).") VALUES (".implode(',', $vals).")";
      $ins = $pdo->prepare($sql); $ins->execute($args);
    } elseif ($L_AMT) {
      $amt = abs($delta);
      $cols = ["`$L_CLI`","`$L_AMT`"];
      $vals = ["?","?"];
      $args = [$client_id, $amt];
      if ($L_DATE){ $cols[]="`$L_DATE`"; $vals[]="NOW()"; }
      if ($L_TYPE){ $cols[]="`$L_TYPE`"; $vals[]="?"; $args[] = ($delta>=0 ? 'adjustment_debit' : 'adjustment_credit'); }
      if ($L_NOTE){ $cols[]="`$L_NOTE`"; $vals[]="?"; $args[] = ($note !== '' ? $note : 'balance adjustment'); }
      $sql = "INSERT INTO `$LEDGER_T` (".implode(',', $cols).") VALUES (".implode(',', $vals).")";
      $ins = $pdo->prepare($sql); $ins->execute($args);
    }
  }

  // Audit (best-effort)
  try{
    if (function_exists('audit_log')) {
      // বাংলা: চেষ্টা ১ — নতুন সিগনেচার
      audit_log($_SESSION['user']['id'] ?? null, $client_id, 'ledger_balance_update', [
        'mode' => $mode, 'amount' => $amount, 'delta' => $delta,
        'old' => $old_balance, 'new' => $new_balance, 'note' => $note
      ]);
    } else {
      // বাংলা: চেষ্টা ২ — কোনো কাস্টম অডিট ফাংশন থাকলে
      if (function_exists('audit')) {
        audit('ledger_balance_update', [
          'user_id' => $_SESSION['user']['id'] ?? null,
          'client_id' => $client_id,
          'mode' => $mode, 'amount' => $amount, 'delta' => $delta,
          'old' => $old_balance, 'new' => $new_balance, 'note' => $note
        ]);
      }
    }
  }catch(Throwable $e){ /* audit is best-effort */ }

  $pdo->commit();

  $_SESSION['flash_success'] = 'Ledger balance updated: '
    . number_format($old_balance,2) . ' → ' . number_format($new_balance,2)
    . ' (Δ ' . number_format($delta,2) . ').';

} catch (Throwable $e) {
  $pdo->rollBack();
  $_SESSION['flash_error'] = 'Update failed: ' . $e->getMessage();
}

// Redirect back to detail page
header('Location: /public/client_ledger.php?client_id='.(int)$client_id);
exit;
