<?php
// /api/cron_generate_invoices.php
// Auto Billing (cron-safe): মাস শুরুতে ইনভয়েস জেনারেট + অডিট + লক-লজিক
// UI JSON; বাংলা কমেন্ট; PDO + procedural

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../app/db.php';          // DB only (no login)
@require_once __DIR__ . '/../app/config.php';     // optional: $CONFIG['cron_token'] / CRON_TOKEN
@include_once __DIR__ . '/../app/audit.php';      // optional: audit_log(...)

function respond($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

// (বাংলা) কলাম এক্সিস্টেন্স চেক
function hcol(PDO $pdo, string $tbl, string $col): bool {
  $st = $pdo->prepare("SHOW COLUMNS FROM `$tbl` LIKE ?");
  $st->execute([$col]);
  return (bool)$st->fetchColumn();
}

/* ---------- Token check ---------- */
$given = trim($_GET['token'] ?? '');
$conf  = null;

// Priority: env > $CONFIG['cron_token'] > defined('CRON_TOKEN') > file(storage/cron_token.txt)
if (getenv('CRON_TOKEN'))                  $conf = getenv('CRON_TOKEN');
elseif (isset($CONFIG) && !empty($CONFIG['cron_token'])) $conf = (string)$CONFIG['cron_token'];
elseif (defined('CRON_TOKEN'))             $conf = (string)CRON_TOKEN;
elseif (is_readable(__DIR__.'/../storage/cron_token.txt'))
  $conf = trim((string)@file_get_contents(__DIR__.'/../storage/cron_token.txt'));

if (!$conf || !$given || !hash_equals($conf, $given)) {
  http_response_code(403);
  respond(['ok'=>false,'error'=>'forbidden']);
}

/* ---------- Inputs ---------- */
// target month (YYYY-MM). ডিফল্ট: চলতি মাস
$month = trim($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');

$router           = isset($_GET['router_id']) ? (int)$_GET['router_id'] : 0;
$active_only      = (int)($_GET['active_only'] ?? 0);
$include_disabled = (int)($_GET['include_disabled'] ?? 0);
$include_left     = (int)($_GET['include_left'] ?? 0);
$force_zero       = (int)($_GET['force_zero'] ?? 0);
$due_days         = max(0, (int)($_GET['due_days'] ?? 7));

// মোড: already exists হলে কী হবে — skip|replace
$mode = strtolower($_GET['mode'] ?? 'skip');
if (!in_array($mode, ['skip','replace'], true)) $mode = 'skip';

// Optional AUTOFILL (package.price → monthly_bill; fallback default_amount)
$autofill       = (int)($_GET['autofill'] ?? 1); // cron-এ by default on
$default_amount = (float)($_GET['default_amount'] ?? 0);
$autofill_scope = strtolower($_GET['autofill_scope'] ?? 'filtered'); // filtered|all

$lock_wait = (int)($_GET['lock_wait'] ?? 0); // seconds to wait for lock (default 0=nowait)

/* ---------- Derived dates ---------- */
$month_start = $month . '-01';
$month_end   = date('Y-m-t', strtotime($month_start));
$today       = date('Y-m-d');

/* ---------- Start ---------- */
$started_at = microtime(true);

try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  /* ---------- MySQL named lock (cron-safe) ---------- */
  $lockName = "cron_gen_inv_" . $month;
  $stmtLock = $pdo->prepare("SELECT GET_LOCK(?, ?)");
  $stmtLock->execute([$lockName, $lock_wait]);
  $gotLock = (string)$stmtLock->fetchColumn() === '1';
  if (!$gotLock) {
    respond(['ok'=>false,'error'=>'locked','lock'=>['name'=>$lockName,'waiting'=>$lock_wait]]);
  }

  // Always release lock at end
  $releaseLock = function() use ($pdo, $lockName){
    try { $pdo->prepare("SELECT RELEASE_LOCK(?)")->execute([$lockName]); } catch(Throwable $e){}
  };

  /* ---------- Schema flags (invoices) ---------- */
  $has_invoice_number = hcol($pdo,'invoices','invoice_number');
  $has_status         = hcol($pdo,'invoices','status');
  $has_is_void        = hcol($pdo,'invoices','is_void');
  $has_created        = hcol($pdo,'invoices','created_at');
  $has_updated        = hcol($pdo,'invoices','updated_at');
  $has_billing_month  = hcol($pdo,'invoices','billing_month');   // DATE
  $has_invoice_date   = hcol($pdo,'invoices','invoice_date');    // DATE
  $has_due_date       = hcol($pdo,'invoices','due_date');        // DATE
  $has_period_start   = hcol($pdo,'invoices','period_start');    // DATE
  $has_period_end     = hcol($pdo,'invoices','period_end');      // DATE
  $has_total          = hcol($pdo,'invoices','total');           // DECIMAL
  $has_payable        = hcol($pdo,'invoices','payable');         // DECIMAL
  $has_amount         = hcol($pdo,'invoices','amount');          // DECIMAL
  $has_remarks        = hcol($pdo,'invoices','remarks');

  if     ($has_total)       $amount_target = 'total';
  elseif ($has_payable)     $amount_target = 'payable';
  elseif ($has_amount)      $amount_target = 'amount';
  else { $releaseLock(); respond(['ok'=>false,'error'=>'No suitable amount column in invoices (need total/payable/amount).']); }

  /* ---------- Clients schema ---------- */
  $has_ledger = hcol($pdo,'clients','ledger_balance');

  /* ---------- Build WHERE (shared) ---------- */
  $where = $include_left ? "1=1" : "COALESCE(c.is_left,0) = 0";
  if ($active_only && !$include_disabled) $where .= " AND c.status = 'active'";
  if ($router > 0) $where .= " AND c.router_id = :router_id";

  // এমাউন্ট সোর্স (eligible চেকের জন্য)
  $amountExpr = "COALESCE(NULLIF(c.monthly_bill,0), p.price, 0)";
  $amountCond = $force_zero ? "1=1" : "$amountExpr > 0";

  /* ---------- Optional AUTOFILL (before generation) ---------- */
  $auto_info = [
    'applied_from_package'=>0,'applied_set_default'=>0
  ];
  if ($autofill) {
    $whereAuto = ($autofill_scope === 'all') ? "1=1" : $where;

    $pdo->beginTransaction();
    // 1) package.price → monthly_bill
    $upd1 = $pdo->prepare("
      UPDATE clients c
      LEFT JOIN packages p ON p.id = c.package_id
      SET c.monthly_bill = p.price
      WHERE $whereAuto
        AND COALESCE(c.monthly_bill,0) <= 0
        AND COALESCE(p.price,0) > 0
    ");
    if (strpos($whereAuto, ':router_id') !== false) $upd1->bindValue(':router_id',$router,PDO::PARAM_INT);
    $upd1->execute(); $auto_info['applied_from_package'] = (int)$upd1->rowCount();

    // 2) default_amount → monthly_bill
    if ($default_amount > 0) {
      $upd2 = $pdo->prepare("
        UPDATE clients c
        SET c.monthly_bill = :def
        WHERE $whereAuto
          AND COALESCE(c.monthly_bill,0) <= 0
      ");
      if (strpos($whereAuto, ':router_id') !== false) $upd2->bindValue(':router_id',$router,PDO::PARAM_INT);
      $upd2->bindValue(':def', $default_amount);
      $upd2->execute(); $auto_info['applied_set_default'] = (int)$upd2->rowCount();
    }
    $pdo->commit();
  }

  /* ---------- Early-exist check (month already billed?) ---------- */
  // রেঞ্জ এক্সপ্রেশন বানাই
  if ($has_billing_month)       $rangeExpr = "i.billing_month BETWEEN ? AND ?";
  elseif ($has_invoice_date)    $rangeExpr = "DATE(i.invoice_date) BETWEEN ? AND ?";
  elseif ($has_created)         $rangeExpr = "DATE(i.created_at) BETWEEN ? AND ?";
  else                          $rangeExpr = null;

  $exist_sql = "SELECT COUNT(*) FROM invoices i WHERE 1=1 ".
               ($rangeExpr ? " AND $rangeExpr " : " AND DATE(i.created_at) BETWEEN ? AND ? ");
  if ($has_is_void) $exist_sql .= " AND COALESCE(i.is_void,0)=0";
  if ($has_status)  $exist_sql .= " AND i.status <> 'void'";

  $stExist = $pdo->prepare($exist_sql);
  $stExist->execute([$month_start, $month_end]);
  $exists_count = (int)$stExist->fetchColumn();

  if ($exists_count > 0 && $mode === 'skip') {
    if (function_exists('audit_log')) {
      audit_log('cron_invoice_skip','system',0,[
        'billing_month'=>$month,'reason'=>'already_exists','count'=>$exists_count
      ]);
    }
    $releaseLock();
    respond([
      'ok'=>true,'skipped'=>true,'reason'=>'already_exists',
      'month'=>$month,'existing_invoices'=>$exists_count,'autofill'=>$auto_info
    ]);
  }

  /* ---------- Eligible clients ---------- */
  $sqlClients = "
    SELECT c.id AS client_id, $amountExpr AS bill_amount
    FROM clients c
    LEFT JOIN packages p ON p.id = c.package_id
    WHERE $where
      AND $amountCond
  ";
  $stc = $pdo->prepare($sqlClients);
  if ($router>0) $stc->bindValue(':router_id',$router,PDO::PARAM_INT);
  $stc->execute();
  $clients = $stc->fetchAll(PDO::FETCH_ASSOC);

  $out = [
    'ok'=>true,
    'month'=>$month,
    'eligible_clients'=>count($clients),
    'inserted'=>0,'replaced'=>0,'skipped'=>0,
    'replaced_minus'=>0.0,'added_plus'=>0.0,'total_amount'=>0.0,
    'mode'=>$mode,'autofill'=>$auto_info
  ];
  if (!$clients) {
    if (function_exists('audit_log')) {
      audit_log('cron_invoice_done','system',0,[
        'billing_month'=>$month,'eligible'=>0,'note'=>'no_clients'
      ]);
    }
    $releaseLock();
    respond($out);
  }

  /* ---------- Prepare statements ---------- */
  $pdo->beginTransaction();

  // find old (per-client, same month) for replace/cleanup
  $findOldSql = "SELECT i.id, i.$amount_target AS amt
                 FROM invoices i
                 WHERE i.client_id = ? ".
                 ($rangeExpr ? " AND $rangeExpr " : "").
                 ($has_is_void ? " AND COALESCE(i.is_void,0)=0" : "").
                 ($has_status  ? " AND i.status <> 'void' " : "");
  $findOld = $pdo->prepare($findOldSql);

  // void/delete
  if ($has_is_void)      $voidOld = $pdo->prepare("UPDATE invoices SET is_void=1 ".($has_updated?", updated_at=NOW()":"")." WHERE id=?");
  elseif ($has_status)   $voidOld = $pdo->prepare("UPDATE invoices SET status='void' ".($has_updated?", updated_at=NOW()":"")." WHERE id=?");
  else                   $voidOld = $pdo->prepare("DELETE FROM invoices WHERE id=?");

  // dynamic insert
  $cols = ['client_id', $amount_target];
  $vals = [':client_id', ':amount'];
  if ($has_invoice_number) { $cols[]='invoice_number'; $vals[]=':invoice_number'; }
  if ($has_billing_month)  { $cols[]='billing_month';  $vals[]=':billing_month'; }
  if ($has_invoice_date)   { $cols[]='invoice_date';   $vals[]=':invoice_date'; }
  if ($has_due_date)       { $cols[]='due_date';       $vals[]=':due_date'; }
  if ($has_period_start)   { $cols[]='period_start';   $vals[]=':period_start'; }
  if ($has_period_end)     { $cols[]='period_end';     $vals[]=':period_end'; }
  if ($has_status)         { $cols[]='status';         $vals[]="'unpaid'"; }
  if ($has_remarks)        { $cols[]='remarks';        $vals[]=':remarks'; }
  if ($has_created)        { $cols[]='created_at';     $vals[]='NOW()'; }
  if ($has_updated)        { $cols[]='updated_at';     $vals[]='NOW()'; }
  $insertSql = "INSERT INTO invoices (".implode(',', $cols).") VALUES (".implode(',', $vals).")";
  $insertInv = $pdo->prepare($insertSql);

  // ledger updater
  $updateLedger = $has_ledger
    ? $pdo->prepare("UPDATE clients SET ledger_balance = ledger_balance + :delta WHERE id = :cid")
    : null;

  foreach ($clients as $r) {
    $cid = (int)$r['client_id'];
    $amt = (float)$r['bill_amount'];

    // replace/cleanup old for this client-month (skip মোডেও সেফটি)
    $olds = [];
    if ($rangeExpr) {
      $findOld->execute([$cid, $month_start, $month_end]);
      $olds = $findOld->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    if ($olds && $mode === 'replace') {
      foreach ($olds as $old) {
        if ($updateLedger) $updateLedger->execute([':delta'=>-1*(float)$old['amt'], ':cid'=>$cid]);
        $voidOld->execute([(int)$old['id']]);
        if (function_exists('audit_log')) {
          audit_log('invoice_void','client',$cid,[
            'invoice_id'=>(int)$old['id'],'billing_month'=>$month,'via'=>'cron'
          ]);
        }
        $out['replaced']++;
        $out['replaced_minus'] += (float)$old['amt'];
      }
    } elseif ($olds && $mode === 'skip') {
      // যদি কারও আগেই থাকে, এই ক্লায়েন্টকে স্কিপ
      $out['skipped']++;
      continue;
    }

    // new invoice payload
    $invNo = null;
    if ($has_invoice_number) {
      $rand = strtoupper(substr(bin2hex(random_bytes(2)),0,4));
      $invNo = 'INV-'.date('Ym', strtotime($month_start)).'-'.$cid.'-'.$rand;
    }
    $invDate = $has_invoice_date ? $today : null;
    $dueDate = $has_due_date ? date('Y-m-d', strtotime($today.' +'.$due_days.' days')) : null;
    $pStart  = $has_period_start ? $month_start : null;
    $pEnd    = $has_period_end   ? $month_end   : null;
    $remarks = $has_remarks ? ('Auto (cron) for '.$month) : null;

    // insert
    $insertInv->bindValue(':client_id', $cid, PDO::PARAM_INT);
    $insertInv->bindValue(':amount', $amt);
    if ($has_invoice_number) $insertInv->bindValue(':invoice_number', $invNo);
    if ($has_billing_month)  $insertInv->bindValue(':billing_month', $month_start);
    if ($has_invoice_date)   $insertInv->bindValue(':invoice_date', $invDate);
    if ($has_due_date)       $insertInv->bindValue(':due_date', $dueDate);
    if ($has_period_start)   $insertInv->bindValue(':period_start', $pStart);
    if ($has_period_end)     $insertInv->bindValue(':period_end', $pEnd);
    if ($has_remarks)        $insertInv->bindValue(':remarks', $remarks);
    $insertInv->execute();
    $newId = (int)$pdo->lastInsertId();

    if ($updateLedger) $updateLedger->execute([':delta'=>$amt, ':cid'=>$cid]);

    if (function_exists('audit_log')) {
      audit_log('invoice_create','client',$cid,[
        'invoice_id'=>$newId,'billing_month'=>$month,'total'=>$amt,'via'=>'cron'
      ]);
    }

    $out['inserted']++;
    $out['added_plus']  += $amt;
    $out['total_amount']+= $amt;
  }

  $pdo->commit();

  if (function_exists('audit_log')) {
    audit_log('cron_invoice_done','system',0,[
      'billing_month'=>$month,
      'eligible'=>$out['eligible_clients'],
      'inserted'=>$out['inserted'],
      'replaced'=>$out['replaced'],
      'skipped'=>$out['skipped'],
      'total_amount'=>$out['total_amount'],
      'mode'=>$mode
    ]);
  }

  $releaseLock();
  $out['duration_sec'] = round(microtime(true)-$started_at, 3);
  respond($out);

} catch(Throwable $e){
  if (isset($pdo)) {
    try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch(Throwable $e2){}
    try { $pdo->prepare("DO 1"); } catch(Throwable $e3){} // noop
  }
  respond(['ok'=>false,'error'=>$e->getMessage()]);
}
