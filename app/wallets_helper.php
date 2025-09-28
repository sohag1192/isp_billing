<?php
// /app/wallets_helper.php
// বাংলা কমেন্ট: ইউনিফাইড ওয়ালেট হেল্পার — দুটি স্কিমা সাপোর্ট করে:
//   A) wallets + wallet_transactions (+ wallets.balance)
//   B) accounts + wallet_transfers + payments.account_id
// লক্ষ্য: পেমেন্ট ক্রেডিট, ব্যালান্স পড়া, সব ওয়ালেটের তালিকা, সিম্পল settlement

require_once __DIR__ . '/db.php';

function _wh_db(): PDO { return db(); }
function _wh_col_exists(PDO $pdo, string $t, string $c): bool {
  static $m = [];
  $k="$t::$c";
  if(isset($m[$k])) return $m[$k];
  try{
    $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
    $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
    $q->execute([$db,$t,$c]);
    return $m[$k]=(bool)$q->fetchColumn();
  }catch(Throwable $e){ return $m[$k]=false; }
}
function _wh_tbl_exists(PDO $pdo, string $t): bool {
  static $m=[];
  if(isset($m[$t])) return $m[$t];
  try{
    $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
    $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
    $q->execute([$db,$t]);
    return $m[$t]=(bool)$q->fetchColumn();
  }catch(Throwable $e){ return $m[$t]=false; }
}

/* ----------- detect schema ----------- */
function wh_schema(): string {
  $pdo=_wh_db();
  $hasWallets = _wh_tbl_exists($pdo,'wallets');
  $hasWT      = _wh_tbl_exists($pdo,'wallet_transactions');
  if ($hasWallets && $hasWT) return 'A'; // new
  $hasAcc     = _wh_tbl_exists($pdo,'accounts');
  $hasWTf     = _wh_tbl_exists($pdo,'wallet_transfers');
  $hasPay     = _wh_tbl_exists($pdo,'payments');
  $hasAccId   = $hasPay && _wh_col_exists($pdo,'payments','account_id');
  if ($hasAcc && $hasWTf && $hasPay && $hasAccId) return 'B'; // legacy
  return 'unknown';
}

/* ----------- list wallets with balances ----------- */
function wh_list_wallets_with_balances(): array {
  $pdo=_wh_db();
  $schema = wh_schema();
  if ($schema==='A') {
    // (A) wallets + wallet_transactions
    $rows = $pdo->query("SELECT id, user_id, name, COALESCE(balance,0) AS balance, COALESCE(is_company,0) AS is_company FROM wallets ORDER BY is_company DESC, name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    // pending/undeposited = un-settled 'in' − un-settled 'out' (if is_settled column exists)
    $hasSet = _wh_col_exists($pdo,'wallet_transactions','is_settled');
    foreach ($rows as &$r) {
      $wid=(int)$r['id'];
      if ($hasSet) {
        $st=$pdo->prepare("SELECT
          COALESCE(SUM(CASE WHEN direction='in' AND is_settled=0 THEN amount ELSE 0 END),0)
          - COALESCE(SUM(CASE WHEN direction='out' AND is_settled=0 THEN amount ELSE 0 END),0)
        FROM wallet_transactions WHERE wallet_id=?");
        $st->execute([$wid]);
        $r['pending_balance']=(float)$st->fetchColumn();
      } else {
        $r['pending_balance']=0.0;
      }
      $r['total_balance']=(float)($r['balance'] ?? 0);
      unset($r['balance']);
    }
    return $rows;
  } elseif ($schema==='B') {
    // (B) accounts + wallet_transfers + payments.account_id
    $accs = $pdo->query("SELECT id, user_id, name FROM accounts WHERE user_id IS NOT NULL ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    // payments credited
    $pay = $pdo->query("SELECT account_id, COALESCE(SUM(amount),0) amt FROM payments GROUP BY account_id")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    // settlements (approved)
    $out = $pdo->query("SELECT from_account_id, COALESCE(SUM(amount),0) amt FROM wallet_transfers WHERE status='approved' GROUP BY from_account_id")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    $in  = $pdo->query("SELECT to_account_id,   COALESCE(SUM(amount),0) amt FROM wallet_transfers WHERE status='approved' GROUP BY to_account_id")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    $rows=[];
    foreach($accs as $a){
      $aid=(int)$a['id'];
      $p=(float)($pay[$aid] ?? 0);
      $o=(float)($out[$aid] ?? 0);
      $i=(float)($in[$aid]  ?? 0);
      $rows[] = [
        'id' => $aid,
        'user_id' => (int)$a['user_id'],
        'name' => (string)$a['name'],
        'is_company' => 0,
        'pending_balance' => 0.0,            // legacy স্কিমায় explicit pending ধরে রাখেন না
        'total_balance'   => $p - $o + $i,   // Payments − SettledOut + SettledIn
      ];
    }
    return $rows;
  }
  return [];
}

/* ----------- credit payment into wallet ----------- */
function wh_credit_payment(int $wallet_id, int $payment_id, int $client_id, int $invoice_id, float $amount, ?string $note, ?int $by_user): void {
  $pdo=_wh_db();
  $schema=wh_schema();
  if ($schema==='A') {
    $hasSet=_wh_col_exists($pdo,'wallet_transactions','is_settled');
    if ($hasSet) {
      $q=$pdo->prepare("INSERT INTO wallet_transactions (wallet_id, payment_id, client_id, invoice_id, direction, amount, reason, note, is_settled, created_by, created_at)
        VALUES (?,?,?,?, 'in', ?, 'payment', ?, 0, ?, NOW())");
      $q->execute([$wallet_id,$payment_id,$client_id,$invoice_id, round($amount,2), $note, $by_user]);
    } else {
      $q=$pdo->prepare("INSERT INTO wallet_transactions (wallet_id, payment_id, client_id, invoice_id, direction, amount, reason, note, created_by, created_at)
        VALUES (?,?,?,?, 'in', ?, 'payment', ?, ?, NOW())");
      $q->execute([$wallet_id,$payment_id,$client_id,$invoice_id, round($amount,2), $note, $by_user]);
    }
    // materialized balance থাকলে আপডেট
    if (_wh_col_exists($pdo,'wallets','balance')) {
      $u=$pdo->prepare("UPDATE wallets SET balance = ROUND(COALESCE(balance,0) + ?, 2) WHERE id=?");
      $u->execute([ round($amount,2), $wallet_id ]);
    }
  } elseif ($schema==='B') {
    // legacy: payments.account_id ইতিমধ্যেই সেট হয়েছে ধরে নেই (payment_add এ)
    // এখানে আলাদা লেজার টেবিলে লেখার দরকার নেই; ড্যাশবোর্ড aggregation-ই ব্যালান্স দেখায়।
    // চাইলে এখানে অতিরিক্ত log টেবিল রাখতে পারেন।
    return;
  }
}

/* ----------- settle to company (simple) ----------- */
function wh_simple_settle_to_company(int $from_wallet_id, int $company_wallet_id, float $amount, string $note, int $by_user): void {
  $pdo=_wh_db();
  $schema=wh_schema();
  if ($schema==='A') {
    $pdo->beginTransaction();
    try{
      // out from operator
      $hasSet=_wh_col_exists($pdo,'wallet_transactions','is_settled');
      if ($hasSet) {
        $q=$pdo->prepare("INSERT INTO wallet_transactions (wallet_id, direction, amount, reason, note, is_settled, created_by, created_at)
          VALUES (?, 'out', ?, 'settlement', ?, 1, ?, NOW())");
      } else {
        $q=$pdo->prepare("INSERT INTO wallet_transactions (wallet_id, direction, amount, reason, note, created_by, created_at)
          VALUES (?, 'out', ?, 'settlement', ?, ?, NOW())");
      }
      $q->execute([$from_wallet_id, round($amount,2), $note, $by_user]);
      if (_wh_col_exists($pdo,'wallets','balance')) {
        $pdo->prepare("UPDATE wallets SET balance = ROUND(COALESCE(balance,0) - ?, 2) WHERE id=?")->execute([ round($amount,2), $from_wallet_id ]);
        $pdo->prepare("UPDATE wallets SET balance = ROUND(COALESCE(balance,0) + ?, 2) WHERE id=?")->execute([ round($amount,2), $company_wallet_id ]);
      }
      $pdo->commit();
    } catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
  } elseif ($schema==='B') {
    // legacy: wallet_transfers টেবিলে pending/approved ফ্লো থাকে—এটা আপনার approvals UI নিয়ন্ত্রণ করবে
    $stmt=$pdo->prepare("INSERT INTO wallet_transfers (from_account_id, to_account_id, amount, status, note, created_by, created_at)
                         VALUES (?,?,?,?,?, ?, NOW())");
    $stmt->execute([$from_wallet_id, $company_wallet_id, round($amount,2), 'pending', $note, $by_user]);
  }
}
