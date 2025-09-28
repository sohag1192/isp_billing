<?php
// ==== Auth/boot ====
// Bengali: CLI বা internal include (e.g., cron/auto_billing.php) হলে login/perm বাইপাস
if (PHP_SAPI !== 'cli' && !defined('APP_INTERNAL_CALL')) {
  require_once __DIR__ . '/../app/require_login.php';
  require_once __DIR__ . '/../app/acl.php';
  require_perm('invoice.generate'); // (বাংলা) পার্মিশন দরকার (ওয়েবে)
}
require_once __DIR__ . '/../app/db.php';

header('Content-Type: application/json; charset=utf-8');

// Bengali: বর্তমান ইউজার আইডি (ওয়েবে থাকলে), নাহলে null (cron)
$__UID = null;
if (isset($_SESSION['user']['id']) && ctype_digit((string)$_SESSION['user']['id'])) {
  $__UID = (int)$_SESSION['user']['id'];
}

/* ============ Helpers ============ */
// (বাংলা) JSON রেসপন্স
function respond($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

// (বাংলা) কলাম আছে কিনা
function hcol(PDO $pdo, $tbl, $col){
  $st = $pdo->prepare("SHOW COLUMNS FROM `$tbl` LIKE ?");
  $st->execute([$col]);
  return (bool)$st->fetchColumn();
}

// (বাংলা) YYYY-MM ভ্যালিডেট/নরমালাইজ
function norm_month(?string $m): string {
  return ($m && preg_match('/^\d{4}-\d{2}$/',$m)) ? $m : date('Y-m');
}

// (বাংলা) মাসের শুরুর/শেষের তারিখ
function month_range(string $ym): array {
  $start = $ym . '-01';
  $end   = date('Y-m-t', strtotime($start));
  return [$start, $end];
}

// (বাংলা) প্রোরেট এমাউন্ট (join_date/left_at অনুযায়ী)
function prorated_amount(float $base, string $ym, ?string $join_date, ?string $left_at): float {
  [$mStart, $mEnd] = month_range($ym);
  $from = $mStart;
  $to   = $mEnd;

  if (!empty($join_date) && $join_date > $from) $from = $join_date;
  if (!empty($left_at)   && substr($left_at,0,10) < $to) $to = substr($left_at,0,10);
  if ($to < $from) return 0.0;

  $days_in_month = (int)date('t', strtotime($mStart));
  $active_days   = (int)((strtotime($to) - strtotime($from)) / 86400) + 1;
  $ratio = max(0.0, min(1.0, $active_days / $days_in_month));
  return round($base * $ratio, 2);
}

/* ============ Inputs ============ */
$month            = norm_month(trim($_GET['month'] ?? date('Y-m'))); // YYYY-MM
$commit           = (int)($_GET['commit'] ?? 0);                     // 0=preview, 1=commit
$replace          = (int)($_GET['replace'] ?? 0);                    // 1=existing void+replace, 0=skip if exists
$prorate          = (int)($_GET['prorate'] ?? 0);                    // 1=প্রোরেট নেবে
$router           = isset($_GET['router_id']) ? (int)$_GET['router_id'] : 0;
$active_only      = (int)($_GET['active_only'] ?? 0);
$include_disabled = (int)($_GET['include_disabled'] ?? 0);
$include_left     = (int)($_GET['include_left'] ?? 0);
$due_days         = max(0, (int)($_GET['due_days'] ?? 7));
$force_zero       = (int)($_GET['force_zero'] ?? 0);
$debug            = (int)($_GET['debug'] ?? 0);

// (বাংলা) নতুন: monthly_bill অটোফিল সহায়তা
$autofill         = (int)($_GET['autofill'] ?? 0);
$default_amount   = (float)($_GET['default_amount'] ?? 0);
$autofill_scope   = strtolower($_GET['autofill_scope'] ?? 'filtered'); // filtered|all

$month_start = $month . '-01';
$month_end   = date('Y-m-t', strtotime($month_start));
$today       = date('Y-m-d');

try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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
  $has_total          = hcol($pdo,'invoices','total');
  $has_payable        = hcol($pdo,'invoices','payable');
  $has_amount         = hcol($pdo,'invoices','amount');
  $has_remarks        = hcol($pdo,'invoices','remarks');

  if     ($has_total)       $amount_target = 'total';
  elseif ($has_payable)     $amount_target = 'payable';
  elseif ($has_amount)      $amount_target = 'amount';
  else respond(['ok'=>false,'error'=>'No suitable amount column in invoices (need total/payable/amount).']);

  /* ---------- Clients schema ---------- */
  $has_ledger     = hcol($pdo,'clients','ledger_balance');
  $has_join_date  = hcol($pdo,'clients','join_date');
  $has_left_at    = hcol($pdo,'clients','left_at');
  $has_monthly    = hcol($pdo,'clients','monthly_bill');

  /* ---------- Audit helper (optional) ---------- */
  @include_once __DIR__ . '/../app/audit.php';
  $can_audit = function_exists('audit_log');

  /* ---------- Per-month lock (cron/duplicate safe) ---------- */
  $lock_name = "invoice:".$month;
  $stLock = $pdo->prepare("SELECT GET_LOCK(?, 0)");
  $stLock->execute([$lock_name]);
  $gotLock = (int)$stLock->fetchColumn();
  if ($gotLock !== 1) respond(['ok'=>false,'error'=>'Another invoice job is running for this month (lock).']);

  // ==== AUDIT: start ====
  if ($can_audit) {
    // Bengali: মাস-লেভেল অপারেশন; entity_id=null, entity_type=system
    audit_log('invoice_generate.start', null, 'system', $__UID, [
      'month'=>$month,'commit'=>$commit,'replace'=>$replace,'prorate'=>$prorate
    ]);
  }

  /* ---------- Build WHERE (shared) ---------- */
  $where = $include_left ? "1=1" : "COALESCE(c.is_left,0) = 0";
  if ($active_only && !$include_disabled) $where .= " AND c.status = 'active'";
  if ($router > 0) $where .= " AND c.router_id = :router_id";

  // (বাংলা) এমাউন্ট সোর্স
  $amountExpr = "COALESCE(NULLIF(c.monthly_bill,0), p.price, 0)";
  $amountCond = $force_zero ? "1=1" : "$amountExpr > 0";

  /* ---------- Optional AUTOFILL ---------- */
  $auto_info = [
    'preview_from_package'=>0,'preview_set_default'=>0,
    'applied_from_package'=>0,'applied_set_default'=>0
  ];

  if ($autofill) {
    $whereAuto = ($autofill_scope === 'all') ? "1=1" : $where;

    $preview_from_pkg_sql = "
      SELECT COUNT(*) FROM clients c
      LEFT JOIN packages p ON p.id=c.package_id
      WHERE $whereAuto AND COALESCE(c.monthly_bill,0) <= 0 AND COALESCE(p.price,0) > 0
    ";
    $stpf = $pdo->prepare($preview_from_pkg_sql);
    if (strpos($whereAuto, ':router_id') !== false) $stpf->bindValue(':router_id',$router,PDO::PARAM_INT);
    $stpf->execute(); $auto_info['preview_from_package'] = (int)$stpf->fetchColumn();

    if ($default_amount > 0){
      $preview_default_sql = "
        SELECT COUNT(*) FROM clients c
        LEFT JOIN packages p ON p.id=c.package_id
        WHERE $whereAuto AND COALESCE(c.monthly_bill,0) <= 0 AND COALESCE(p.price,0) <= 0
      ";
      $stpd = $pdo->prepare($preview_default_sql);
      if (strpos($whereAuto, ':router_id') !== false) $stpd->bindValue(':router_id',$router,PDO::PARAM_INT);
      $stpd->execute(); $auto_info['preview_set_default'] = (int)$stpd->fetchColumn();
    }

    if ($commit) {
      $pdo->beginTransaction();
      // 1) package.price -> monthly_bill
      $upd1 = $pdo->prepare("
        UPDATE clients c
        LEFT JOIN packages p ON p.id = c.package_id
        SET c.monthly_bill = p.price
        WHERE $whereAuto AND COALESCE(c.monthly_bill,0) <= 0 AND COALESCE(p.price,0) > 0
      ");
      if (strpos($whereAuto, ':router_id') !== false) $upd1->bindValue(':router_id',$router,PDO::PARAM_INT);
      $upd1->execute(); $auto_info['applied_from_package'] = (int)$upd1->rowCount();

      // 2) default_amount -> monthly_bill
      if ($default_amount > 0) {
        $upd2 = $pdo->prepare("
          UPDATE clients c
          SET c.monthly_bill = :def
          WHERE $whereAuto AND COALESCE(c.monthly_bill,0) <= 0
        ");
        if (strpos($whereAuto, ':router_id') !== false) $upd2->bindValue(':router_id',$router,PDO::PARAM_INT);
        $upd2->bindValue(':def', $default_amount);
        $upd2->execute(); $auto_info['applied_set_default'] = (int)$upd2->rowCount();
      }
      $pdo->commit();
    }
  }

  /* ---------- Eligible clients (with base & pro-rate fields) ---------- */
  $selectJoin  = $has_join_date ? "c.join_date" : "NULL AS join_date";
  $selectLeft  = $has_left_at   ? "c.left_at"   : "NULL AS left_at";
  $sqlClients = "
    SELECT c.id AS client_id, $amountExpr AS base_amount, $selectJoin, $selectLeft
    FROM clients c
    LEFT JOIN packages p ON p.id = c.package_id
    WHERE $where AND $amountCond
  ";
  $stc = $pdo->prepare($sqlClients);
  if ($router>0) $stc->bindValue(':router_id',$router,PDO::PARAM_INT);
  $stc->execute();
  $clients = $stc->fetchAll(PDO::FETCH_ASSOC);

  // (বাংলা) ডিবাগ সামারি
  if ($debug) {
    $tot = (int)$pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
    $not_left = (int)$pdo->query("SELECT COUNT(*) FROM clients WHERE COALESCE(is_left,0)=0")->fetchColumn();
    $with_amt = (int)$pdo->query("
      SELECT COUNT(*) FROM clients c LEFT JOIN packages p ON p.id=c.package_id
      WHERE $where AND $amountCond
    ")->fetchColumn();
    respond([
      'ok'=>true,'debug'=>true,'month'=>$month,
      'total_clients'=>$tot,'not_left'=>$not_left,
      'eligible_with_amount_gt0'=>$with_amt,
      'router_filter'=>$router,'active_only'=>$active_only,
      'include_left'=>$include_left,'force_zero'=>$force_zero,
      'autofill'=>$autofill,'default_amount'=>$default_amount,'autofill_scope'=>$autofill_scope,
      'autofill_preview'=>$auto_info,
      'query_where'=>$where,
      'eligible_clients_found'=>count($clients),
      'sample_client_ids'=>array_slice(array_map(fn($x)=> (int)$x['client_id'], $clients), 0, 10),
    ]);
  }

  // (বাংলা) প্রিভিউ টোটাল (প্রোরেট কনসিডার করে)
  $out = [
    'ok'=>true,
    'mode' => $commit? 'commit':'preview',
    'month'=> $month,
    'eligible_clients' => count($clients),
    'inserted'=>0, 'replaced'=>0, 'skipped'=>0,
    'total_amount'=>0.0, 'replaced_minus'=>0.0, 'added_plus'=>0.0,
    'notes'=>[],
    'autofill_preview'=>$auto_info
  ];

  if (!$commit) {
    $sum = 0.0;
    foreach($clients as $r){
      $base = (float)$r['base_amount'];
      $amt  = $prorate ? prorated_amount($base, $month, $r['join_date'] ?? null, $r['left_at'] ?? null) : $base;
      $sum += max(0.0, (float)$amt);
    }
    $out['total_amount'] = round($sum,2);
    $out['sample_client_ids'] = array_slice(array_map(fn($x)=> (int)$x['client_id'], $clients), 0, 10);

    // release lock (preview end)
    $rel = $pdo->prepare("SELECT RELEASE_LOCK(?)");
    $rel->execute([$lock_name]);

    respond($out);
  }

  /* ---------- Commit ---------- */
  if (!$clients) {
    $rel = $pdo->prepare("SELECT RELEASE_LOCK(?)");
    $rel->execute([$lock_name]);
    respond($out);
  }

  $pdo->beginTransaction();

  // (বাংলা) স্কিমা অনুযায়ী পুরনো খোঁজা (exists/replace)
  if     ($has_billing_month) $rngExpr = "i.billing_month BETWEEN ? AND ?";
  elseif ($has_invoice_date)  $rngExpr = "DATE(i.invoice_date) BETWEEN ? AND ?";
  elseif ($has_created)       $rngExpr = "DATE(i.created_at) BETWEEN ? AND ?";
  else                        $rngExpr = null;

  $findOldSql = "SELECT i.id, i.$amount_target AS amt
                 FROM invoices i
                 WHERE i.client_id = ? ".
                 ($rngExpr ? " AND $rngExpr " : "").
                 ($has_is_void ? " AND COALESCE(i.is_void,0)=0" : "").
                 ($has_status  ? " AND i.status <> 'void' " : "");
  $findOld = $pdo->prepare($findOldSql);

  // (বাংলা) পুরনো void/ডিলিট
  if ($has_is_void)      $voidOld = $pdo->prepare("UPDATE invoices SET is_void=1 ".($has_updated?", updated_at=NOW()":"")." WHERE id=?");
  elseif ($has_status)   $voidOld = $pdo->prepare("UPDATE invoices SET status='void' ".($has_updated?", updated_at=NOW()":"")." WHERE id=?");
  else                   $voidOld = $pdo->prepare("DELETE FROM invoices WHERE id=?");

  // (বাংলা) ডায়নামিক ইনসার্ট
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

  // (বাংলা) লেজার (null-safe)
  $updateLedger = $has_ledger
    ? $pdo->prepare("UPDATE clients SET ledger_balance = COALESCE(ledger_balance,0) + :delta WHERE id = :cid")
    : null;

  foreach ($clients as $r) {
    $cid  = (int)$r['client_id'];
    $base = (float)$r['base_amount'];
    $amt  = $prorate ? prorated_amount($base, $month, $r['join_date'] ?? null, $r['left_at'] ?? null) : $base;
    $amt  = round(max(0.0, (float)$amt), 2);

    // (বাংলা) বিদ্যমান non-void ইনভয়েস আছে? replace=0 হলে skip
    $exists = false;
    $oldRows = [];
    if ($rngExpr) {
      $findOld->execute([$cid, $month_start, $month_end]);
      $oldRows = $findOld->fetchAll(PDO::FETCH_ASSOC) ?: [];
      $exists = count($oldRows) > 0;
    }

    if ($exists && !$replace) {
      $out['skipped']++;
      continue;
    }

    // (বাংলা) পুরনো void + লেজার মাইনাস (replace mode)
    if ($exists && $replace) {
      foreach ($oldRows as $old) {
        if ($updateLedger) $updateLedger->execute([':delta'=>-1*(float)$old['amt'], ':cid'=>$cid]);
        $voidOld->execute([(int)$old['id']]);
        if ($can_audit) {
          // Bengali: void হচ্ছে invoice id; entity_id = invoice id
          audit_log('invoice_void', (int)$old['id'], 'invoice', $__UID, [
            'client_id'=>$cid, 'billing_month'=>$month
          ]);
        }
        $out['replaced']++;
        $out['replaced_minus'] += (float)$old['amt'];
      }
    }

    // (বাংলা) ইনভয়েস নম্বর + তারিখসমূহ
    $invNo = null;
    if ($has_invoice_number) {
      $rand = strtoupper(substr(bin2hex(random_bytes(2)),0,4));
      $invNo = 'INV-'.date('Ym', strtotime($month_start)).'-'.$cid.'-'.$rand;
    }
    $invDate = $has_invoice_date ? $today : null;
    $dueDate = $has_due_date ? date('Y-m-d', strtotime($today.' +'.$due_days.' days')) : null;
    $pStart  = $has_period_start ? $month_start : null;
    $pEnd    = $has_period_end   ? $month_end   : null;
    $remarks = $has_remarks ? ('Auto generated for '.$month.($prorate?' (prorated)':'')) : null;

    // (বাংলা) Insert invoice
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

    // (বাংলা) লেজার += amount
    if ($updateLedger) $updateLedger->execute([':delta'=>$amt, ':cid'=>$cid]);

    if ($can_audit) {
      // Bengali: নতুন ইনভয়েস তৈরি—entity_id = invoice id
      audit_log('invoice_create', (int)$newId, 'invoice', $__UID, [
        'client_id'=>$cid,'billing_month'=>$month,'total'=>$amt,'replace'=>$exists?1:0,'prorate'=>$prorate
      ]);
    }

    $out['inserted']++;
    $out['added_plus']  += $amt;
    $out['total_amount']+= $amt;
  }

  $pdo->commit();

  // ==== AUDIT: end ====
  if ($can_audit) {
    audit_log('invoice_generate.end', null, 'system', $__UID, [
      'month'=>$month, 'inserted'=>$out['inserted'], 'replaced'=>$out['replaced'],
      'skipped'=>$out['skipped'], 'tamount'=>$out['total_amount']
    ]);
  }

  // release lock
  $rel = $pdo->prepare("SELECT RELEASE_LOCK(?)");
  $rel->execute([$lock_name]);

  $out['autofill_applied'] = $auto_info;
  respond($out);

} catch(Throwable $e){
  // release lock on error
  if (isset($pdo)) {
    try { $rel = $pdo->prepare("SELECT RELEASE_LOCK(?)"); $rel->execute([$lock_name]); } catch(Throwable $__) {}
    if ($pdo->inTransaction()) $pdo->rollBack();
  }
  respond(['ok'=>false,'error'=>$e->getMessage()]);
}
