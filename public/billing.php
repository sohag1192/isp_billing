<?php
// /public/billing.php
// Month-wise Billing Dashboard
// Views: Summary (zone-wise) + List (All/Paid/Due) + Search + Pagination + CSV
// Eye → client_ledger.php; Pay → payment_add.php (always enabled)
// UI English; Bangla comments only

declare(strict_types=1);

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

/* ---------- Small helpers ---------- */
// (বাংলা) টেবিল/কলাম/স্কেলার
function tbl_exists(PDO $pdo,string $t):bool{
  try{ $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
    $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
    $q->execute([$db,$t]); return (bool)$q->fetchColumn();
  }catch(Throwable){ return false; }
}
function col_exists(PDO $pdo,string $t,string $c):bool{
  try{ $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
    $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
    $q->execute([$db,$t,$c]); return (bool)$q->fetchColumn();
  }catch(Throwable){ return false; }
}
function pdo_scalar(PDO $pdo,string $sql,array $p=[]){
  $st=$pdo->prepare($sql); $st->execute($p); return $st->fetchColumn();
}
// (বাংলা) Payments-এ active ফিল্টার (soft-delete/void বাদ)
function payments_active_where(PDO $pdo, string $alias='pm'): string {
  $c=[];
  if (col_exists($pdo,'payments','is_deleted')) $c[]="$alias.is_deleted=0";
  if (col_exists($pdo,'payments','deleted_at')) $c[]="$alias.deleted_at IS NULL";
  if (col_exists($pdo,'payments','void'))       $c[]="$alias.void=0";
  if (col_exists($pdo,'payments','status'))     $c[]="COALESCE($alias.status,'') NOT IN ('deleted','void','cancelled')";
  return $c ? (' AND '.implode(' AND ',$c)) : '';
}
// (বাংলা) invoices টেবিলে ডিসকাউন্ট কলাম অটো-ডিটেক্ট
function find_invoice_discount_col(PDO $pdo): ?string {
  $candidates = [
    'discount','bill_discount','discount_amount','disc','inv_discount',
    'pdiscount','p_discount','prev_discount','previous_discount',
    'package_discount','plan_discount','promo_discount'
  ];
  foreach ($candidates as $c) if (col_exists($pdo,'invoices',$c)) return $c;
  try {
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $q  = $pdo->prepare("
      SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA=? AND TABLE_NAME='invoices' AND COLUMN_NAME LIKE '%discount%'
      LIMIT 1
    ");
    $q->execute([$db]);
    $col = $q->fetchColumn();
    return $col ? (string)$col : null;
  } catch (Throwable) { return null; }
}

/* ---------- DB ---------- */
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------- Schema detection ---------- */
$hasInvMonth     = col_exists($pdo,'invoices','month');
$hasInvYear      = col_exists($pdo,'invoices','year');
$hasInvBillMonth = col_exists($pdo,'invoices','billing_month');

$payFk = col_exists($pdo,'payments','invoice_id') ? 'invoice_id'
       : (col_exists($pdo,'payments','bill_id') ? 'bill_id' : null);
$hasPayClientId  = col_exists($pdo,'payments','client_id');
$hasPayDiscount  = col_exists($pdo,'payments','discount');

$invAmountCol = col_exists($pdo,'invoices','payable') ? 'payable'
             : (col_exists($pdo,'invoices','net_amount') ? 'net_amount'
             : (col_exists($pdo,'invoices','amount') ? 'amount'
             : (col_exists($pdo,'invoices','total')  ? 'total'  : 'total')));
// (বাংলা) যদি invAmountCol net হয়, তবে ডিসকাউন্ট already applied ধরা হবে
$isNetInvAmount = in_array($invAmountCol, ['payable','net_amount','net_total'], true);

$invoiceDiscCol   = find_invoice_discount_col($pdo);
$showDiscountCol  = $hasPayDiscount || (bool)$invoiceDiscCol;

$ledgerCols = ['ledger_balance','balance','wallet_balance','ledger'];
$clientLedgerExpr = '0';
foreach ($ledgerCols as $lc) if (col_exists($pdo,'clients',$lc)) { $clientLedgerExpr = "c.`$lc`"; break; }

$clientMobileCol = null; foreach (['mobile','phone','cell','contact'] as $mc) if (col_exists($pdo,'clients',$mc)) { $clientMobileCol = $mc; break; }
$hasPackages = tbl_exists($pdo,'packages') && col_exists($pdo,'clients','package_id') && col_exists($pdo,'packages','id');
$hasPkgName  = $hasPackages && col_exists($pdo,'packages','name');
$payDateCol  = col_exists($pdo,'payments','payment_date') ? 'payment_date'
            : (col_exists($pdo,'payments','created_at') ? 'created_at' : null);

/* ---------- Inputs ---------- */
$month    = trim($_GET['month'] ?? date('Y-m'));
$search   = trim($_GET['search'] ?? '');
$tab      = strtolower(trim($_GET['tab'] ?? 'all'));        // all|paid|due
$view     = strtolower(trim($_GET['view'] ?? 'summary'));   // summary|list
$page     = max(1,(int)($_GET['page']??1));
$limit    = max(1, (int)($_GET['limit'] ?? 20));
$export   = isset($_GET['export']) && $_GET['export']==='csv';

/* Normalize month (সবসময় শুরু/শেষ তারিখ) */
$monthParam = preg_match('/^\d{4}-\d{2}$/',$month)?$month:date('Y-m');
[$yr,$mo] = array_map('intval', explode('-', $monthParam));
$date_start = $monthParam.'-01';
$date_end   = date('Y-m-t', strtotime($date_start));

/* invoices-এর জন্য month WHERE */
if ($hasInvBillMonth)       { $params_base = [$date_start,$date_end]; $whereMonth = "billing_month BETWEEN ? AND ?"; }
elseif ($hasInvMonth && $hasInvYear) { $params_base = [$mo,$yr]; $whereMonth = "month=? AND year=?"; }
else                         { $params_base = []; $whereMonth = "1=0"; }

/* ===================== Helper: Max invoice up-to-month-end ===================== */
/* (বাংলা) carryover due ধরতে “<= month-end” পর্যন্ত সর্বশেষ ইনভয়েস নেব */
if ($hasInvBillMonth) {
  $rangeUpTo = "WHERE billing_month <= ?";
  $params_upto = [$date_end];
} elseif ($hasInvMonth && $hasInvYear) {
  // (year < yr) OR (year = yr AND month <= mo)
  $rangeUpTo = "WHERE (year < ?) OR (year = ? AND month <= ?)";
  $params_upto = [$yr, $yr, $mo];
} else {
  $rangeUpTo = "WHERE 1=0";
  $params_upto = [];
}

/* =========================================================
   SUMMARY (ZONE/AREA-WISE)
   ========================================================= */
$zonesData = [];
$summaryError = null;
$z_tot_gen=$z_tot_col=$z_tot_dis=$z_tot_due=0.0;

if ($view === 'summary') {
  $zoneMeta = ['type'=>'single','key_col'=>null,'name_col'=>null,'table'=>null];
  if (col_exists($pdo,'clients','zone_id') && tbl_exists($pdo,'zones') && col_exists($pdo,'zones','id') && col_exists($pdo,'zones','name')) {
    $zoneMeta = ['type'=>'fk','key_col'=>'zone_id','name_col'=>'name','table'=>'zones'];
  } elseif (col_exists($pdo,'clients','area_id') && tbl_exists($pdo,'areas') && col_exists($pdo,'areas','id') && col_exists($pdo,'areas','name')) {
    $zoneMeta = ['type'=>'fk','key_col'=>'area_id','name_col'=>'name','table'=>'areas'];
  } elseif (col_exists($pdo,'clients','zone')) { $zoneMeta = ['type'=>'text','key_col'=>'zone','name_col'=>'zone','table'=>null];
  } elseif (col_exists($pdo,'clients','area')) { $zoneMeta = ['type'=>'text','key_col'=>'area','name_col'=>'area','table'=>null]; }

  $activeParts=[];
  if (col_exists($pdo,'clients','is_active')) $activeParts[]="COALESCE(c.is_active,0)=1";
  if (col_exists($pdo,'clients','is_left'))   $activeParts[]="COALESCE(c.is_left,0)=0";
  if (col_exists($pdo,'clients','status'))    $activeParts[]="COALESCE(c.status,'') NOT IN ('inactive','left','terminated','suspended')";
  $activeExpr = $activeParts ? implode(' AND ', $activeParts) : '1=1';

  try {
    if ($zoneMeta['type']==='fk') {
      $sql = "
        SELECT c.`{$zoneMeta['key_col']}` AS zone_key, z.`{$zoneMeta['name_col']}` AS zone_name,
               COUNT(*) total_clients,
               SUM(CASE WHEN ($activeExpr) THEN 1 ELSE 0 END) active_clients,
               SUM(CASE WHEN NOT ($activeExpr) THEN 1 ELSE 0 END) inactive_clients
        FROM clients c LEFT JOIN `{$zoneMeta['table']}` z ON z.id=c.`{$zoneMeta['key_col']}`
        GROUP BY c.`{$zoneMeta['key_col']}`, z.`{$zoneMeta['name_col']}` ORDER BY z.`{$zoneMeta['name_col']}` ASC";
      $rowsZ = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($zoneMeta['type']==='text') {
      $col = $zoneMeta['key_col'];
      $sql = "
        SELECT COALESCE(c.`$col`,'Unassigned') zone_key, COALESCE(c.`$col`,'Unassigned') zone_name,
               COUNT(*) total_clients,
               SUM(CASE WHEN ($activeExpr) THEN 1 ELSE 0 END) active_clients,
               SUM(CASE WHEN NOT ($activeExpr) THEN 1 ELSE 0 END) inactive_clients
        FROM clients c GROUP BY COALESCE(c.`$col`,'Unassigned') ORDER BY zone_name ASC";
      $rowsZ = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } else {
      $sql = "SELECT NULL zone_key,'All Clients' zone_name, COUNT(*) total_clients,
              SUM(CASE WHEN ($activeExpr) THEN 1 ELSE 0 END) active_clients,
              SUM(CASE WHEN NOT ($activeExpr) THEN 1 ELSE 0 END) inactive_clients
              FROM clients c";
      $rowsZ = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    $payActive = payments_active_where($pdo,'pm');

    /* payments join plan */
    $payJoin = '';
    if ($payFk)              $payJoin = "JOIN invoices i ON pm.`$payFk`=i.id JOIN clients c ON c.id=i.client_id";
    elseif ($hasPayClientId) $payJoin = "JOIN clients c ON c.id=pm.client_id";
    else                     $payJoin = null;

    /* Generated (only this month) */
    $sqlGen = "SELECT COALESCE(SUM(i.`$invAmountCol`),0)
               FROM invoices i JOIN clients c ON c.id=i.client_id
               WHERE i.$whereMonth AND %ZONECOND%";

    /* Discount aggregate: payments + invoices (দুটোই) */
    $sqlDisPay = null; $sqlDisInv = null;
    if ($payJoin && $hasPayDiscount) {
      $sqlDisPay = "SELECT COALESCE(SUM(pm.discount),0)
                    FROM payments pm $payJoin
                    WHERE ".($payDateCol ? "pm.`$payDateCol` BETWEEN ? AND ?" : "1=1")." $payActive AND %ZONECOND%";
    }
    if ($invoiceDiscCol) {
      $sqlDisInv = "SELECT COALESCE(SUM(i.`$invoiceDiscCol`),0)
                    FROM invoices i JOIN clients c ON c.id=i.client_id
                    WHERE i.$whereMonth AND %ZONECOND%";
    }

    /* Collection (payments, this month) */
    $sqlCol = null;
    if ($payJoin) {
      $sqlCol = "SELECT COALESCE(SUM(pm.amount),0)
                 FROM payments pm $payJoin
                 WHERE ".($payDateCol ? "pm.`$payDateCol` BETWEEN ? AND ?" : "1=1")." $payActive AND %ZONECOND%";
    }

    /* Due of this month (all-time payments against invoice) */
    $discForDue = "0";
    if (!$isNetInvAmount) {
      if ($hasPayDiscount) {
        $discForDue = "(SELECT COALESCE(SUM(pm2.discount),0) FROM payments pm2 WHERE ".($payFk?"pm2.`$payFk`=i.id":"pm2.client_id=i.client_id")." ".payments_active_where($pdo,'pm2').")";
      } elseif ($invoiceDiscCol) {
        $discForDue = "COALESCE(i.`$invoiceDiscCol`,0)";
      }
    }
    $sqlDue = "SELECT COALESCE(SUM(GREATEST(0,
                 COALESCE(i.`$invAmountCol`,0)
                 - $discForDue
                 - (SELECT COALESCE(SUM(pm1.amount),0) FROM payments pm1 WHERE ".($payFk?"pm1.`$payFk`=i.id":"pm1.client_id=i.client_id")." ".payments_active_where($pdo,'pm1').")
               )),0)
               FROM invoices i JOIN clients c ON c.id=i.client_id
               WHERE i.$whereMonth AND %ZONECOND%";

    foreach ($rowsZ as $r) {
      if     ($zoneMeta['type']==='fk')   { $zCond="c.`{$zoneMeta['key_col']}`=?"; $zParam=[$r['zone_key']]; }
      elseif ($zoneMeta['type']==='text') { $col=$zoneMeta['key_col']; $zCond="COALESCE(c.`$col`,'Unassigned')=?"; $zParam=[$r['zone_key']]; }
      else                                { $zCond="1=1"; $zParam=[]; }

      // Generated
      $stG=$pdo->prepare(str_replace('%ZONECOND%',$zCond,$sqlGen));
      $stG->execute(array_merge($params_base,$zParam)); $gen=(float)$stG->fetchColumn();

      // Collection
      $col=0.0; if ($sqlCol){
        $stC=$pdo->prepare(str_replace('%ZONECOND%',$zCond,$sqlCol));
        $stC->execute($payDateCol? array_merge([$date_start,$date_end],$zParam):$zParam);
        $col=(float)$stC->fetchColumn();
      }

      // Discount = payments + invoices
      $disPay=0.0; $disInv=0.0;
      if ($sqlDisPay){
        $stDP=$pdo->prepare(str_replace('%ZONECOND%',$zCond,$sqlDisPay));
        $stDP->execute($payDateCol? array_merge([$date_start,$date_end],$zParam):$zParam);
        $disPay=(float)$stDP->fetchColumn();
      }
      if ($sqlDisInv){
        $stDI=$pdo->prepare(str_replace('%ZONECOND%',$zCond,$sqlDisInv));
        $stDI->execute(array_merge($params_base,$zParam));
        $disInv=(float)$stDI->fetchColumn();
      }
      $dis = $disPay + $disInv;

      // Due
      $stU=$pdo->prepare(str_replace('%ZONECOND%',$zCond,$sqlDue));
      $stU->execute(array_merge($params_base,$zParam)); $due=(float)$stU->fetchColumn();

      $ratio = ($gen>0.0001)? (($col/$gen)*100.0) : 0.0;

      $zonesData[] = [
        'zone_key'=>$r['zone_key'],'zone_name'=>(string)$r['zone_name'],
        'total_clients'=>(int)$r['total_clients'],
        'active_clients'=>(int)$r['active_clients'],
        'inactive_clients'=>(int)$r['inactive_clients'],
        'generated'=>$gen,'collection'=>$col,'discount'=>$dis,'due'=>$due,'ratio'=>$ratio,
      ];
      $z_tot_gen += $gen; $z_tot_col += $col; $z_tot_dis += $dis; $z_tot_due += $due;
    }
  } catch (Throwable $e) { $summaryError = $e->getMessage(); }
}

/* =========================================================
   LIST VIEW (All/Paid/Due) – carryover-aware
   ========================================================= */
$params=[]; $filter="";
if($search!==''){
  $searchCols = [];
  foreach (['name','pppoe_id','mobile','phone','cell','contact'] as $sc) if (col_exists($pdo,'clients',$sc)) $searchCols[] = "c.`$sc` LIKE ?";
  if ($searchCols) { $filter .= " AND (".implode(' OR ',$searchCols).") "; foreach ($searchCols as $_) $params[] = "%$search%"; }
}

/* Header counters */
// total invoices created this month (as-is)
$count_total=(int)pdo_scalar($pdo,"SELECT COUNT(*) FROM invoices WHERE $whereMonth",$params_base);

/* innerCnt (latest invoice up-to-month-end per client) */
$sumPaidForCnt = $payFk
  ? "(SELECT COALESCE(SUM(pm.amount),0) FROM payments pm WHERE pm.`$payFk`=i.id".payments_active_where($pdo,'pm').")"
  : ( $hasPayClientId && $payDateCol
      ? "(SELECT COALESCE(SUM(pm.amount),0) FROM payments pm WHERE pm.client_id=i.client_id AND pm.`$payDateCol` BETWEEN ? AND ?".payments_active_where($pdo,'pm').")"
      : "0"
    );

if ($payFk && $hasPayDiscount && $invoiceDiscCol) {
  $sumDiscForCnt = "(COALESCE(i.`$invoiceDiscCol`,0) + (SELECT COALESCE(SUM(pm.discount),0) FROM payments pm WHERE pm.`$payFk`=i.id".payments_active_where($pdo,'pm')."))";
} elseif ($payFk && $hasPayDiscount) {
  $sumDiscForCnt = "(SELECT COALESCE(SUM(pm.discount),0) FROM payments pm WHERE pm.`$payFk`=i.id".payments_active_where($pdo,'pm').")";
} elseif (!$payFk && $hasPayClientId && $hasPayDiscount && $payDateCol) {
  // fallback: client-month discount
  $sumDiscForCnt = "(SELECT COALESCE(SUM(pm.discount),0) FROM payments pm WHERE pm.client_id=i.client_id AND pm.`$payDateCol` BETWEEN ? AND ?".payments_active_where($pdo,'pm').")";
} elseif ($invoiceDiscCol) {
  $sumDiscForCnt = "COALESCE(i.`$invoiceDiscCol`,0)";
} else { $sumDiscForCnt = "0"; }

// latest invoice up to month-end
$innerCnt = "
  SELECT 
    i.client_id,
    GREATEST(0, COALESCE(i.`$invAmountCol`,0) - ".($isNetInvAmount?'0':"($sumDiscForCnt)")." - ($sumPaidForCnt)) AS remain
  FROM invoices i
  JOIN(
    SELECT client_id, MAX(id) AS max_id
    FROM invoices $rangeUpTo GROUP BY client_id
  ) t ON t.max_id=i.id
";

// bind params for innerCnt
$params_cnt = $params_upto;
if (!$payFk && $hasPayClientId && $payDateCol) {
  // order must match occurrences: first for paid, then for discount (if present)
  $params_cnt = array_merge($params_cnt, [$date_start,$date_end]);
  if ($hasPayDiscount) $params_cnt = array_merge($params_cnt, [$date_start,$date_end]);
}

$count_paid =(int)pdo_scalar($pdo,"SELECT COUNT(*) FROM ($innerCnt) x WHERE x.remain<=0.0001", $params_cnt);
$count_due  =(int)pdo_scalar($pdo,"SELECT COUNT(*) FROM ($innerCnt) x WHERE x.remain>0.0001",  $params_cnt);

/* Row builder (latest up-to-month-end) */
$selectMobile = $clientMobileCol ? ", c.`$clientMobileCol` AS mobile" : ", NULL AS mobile";
$selectPkg    = $hasPkgName ? ", p.name AS package_name" : ", NULL AS package_name";

/* sumPaid / sumDisc for rows */
if ($payFk) {
  $sumPaid = "(SELECT COALESCE(SUM(pm.amount),0) FROM payments pm WHERE pm.`$payFk`=i.id".payments_active_where($pdo,'pm').")";
  if ($hasPayDiscount && $invoiceDiscCol) {
    $sumDisc = "(COALESCE(i.`$invoiceDiscCol`,0) + (SELECT COALESCE(SUM(pm.discount),0) FROM payments pm WHERE pm.`$payFk`=i.id".payments_active_where($pdo,'pm')."))";
  } elseif ($hasPayDiscount) {
    $sumDisc = "(SELECT COALESCE(SUM(pm.discount),0) FROM payments pm WHERE pm.`$payFk`=i.id".payments_active_where($pdo,'pm').")";
  } elseif ($invoiceDiscCol) {
    $sumDisc = "COALESCE(i.`$invoiceDiscCol`,0)";
  } else { $sumDisc = "0"; }
} else {
  // Fallback: only client_id exists in payments
  $sumPaid = ($hasPayClientId && $payDateCol)
    ? "(SELECT COALESCE(SUM(pm.amount),0) FROM payments pm WHERE pm.client_id=i.client_id AND pm.`$payDateCol` BETWEEN ? AND ?".payments_active_where($pdo,'pm').")"
    : "0";
  if ($hasPayClientId && $hasPayDiscount && $payDateCol) {
    $sumDisc = "(SELECT COALESCE(SUM(pm.discount),0) FROM payments pm WHERE pm.client_id=i.client_id AND pm.`$payDateCol` BETWEEN ? AND ?".payments_active_where($pdo,'pm').")";
  } elseif ($invoiceDiscCol) {
    $sumDisc = "COALESCE(i.`$invoiceDiscCol`,0)";
  } else { $sumDisc = "0"; }
}

$innerRows = "
  SELECT 
    c.id AS client_id, c.name AS client_name, c.pppoe_id
    $selectMobile
    $selectPkg,
    i.id AS invoice_id,
    i.`$invAmountCol` AS inv_amount,
    i.status,
    $sumDisc AS discount,
    $sumPaid AS paid_amount,
    $clientLedgerExpr AS ledger_balance,
    GREATEST(0, COALESCE(i.`$invAmountCol`,0) - ".($isNetInvAmount?'0':"($sumDisc)")." - ($sumPaid)) AS remain
  FROM clients c
  JOIN(
    SELECT i1.* FROM invoices i1
    JOIN(SELECT client_id, MAX(id) AS max_id FROM invoices $rangeUpTo GROUP BY client_id) t
      ON t.max_id=i1.id
  ) i ON i.client_id=c.id
  ".($hasPackages ? "LEFT JOIN packages p ON p.id=c.package_id" : "")."
  WHERE 1=1 $filter
";

$sql_count_clients = "SELECT COUNT(*) FROM ( $innerRows ) x ".
  ($tab==='paid' ? "WHERE x.remain<=0.0001" : ($tab==='due' ? "WHERE x.remain>0.0001" : ""));
$params_rows_base = $params_upto;
if (!$payFk && $hasPayClientId && $payDateCol) {
  $params_rows_base = array_merge($params_rows_base, [$date_start,$date_end]); // paid
  if ($hasPayDiscount && (!$payFk)) $params_rows_base = array_merge($params_rows_base, [$date_start,$date_end]); // disc
}
$params_count = array_merge($params_rows_base, $params);
$stc=$pdo->prepare($sql_count_clients);
$stc->execute($params_count);
$total_clients=(int)$stc->fetchColumn();

$pages=max(1,(int)ceil($total_clients/$limit));
$page=min(max(1,$page),$pages);
$offset=($page-1)*$limit;

/* Final rows */
$sql_rows = "SELECT * FROM ( $innerRows ) r " .
  ($tab==='paid' ? "WHERE r.remain<=0.0001" : ($tab==='due' ? "WHERE r.remain>0.0001" : "")) .
  " ORDER BY r.client_name ASC, r.invoice_id DESC LIMIT $limit OFFSET $offset";
$std=$pdo->prepare($sql_rows);
$params_rows = array_merge($params_rows_base, $params);
$std->execute($params_rows);
$rows=$std->fetchAll(PDO::FETCH_ASSOC);

/* ========================== NEW TOTALS ========================== */
/* (বাংলা) 1) Total Due (Month): এই মাসের ইনভয়েসগুলোর remain যোগফল (search/tab উপেক্ষা) */
$discForMonthDue = "0";
if (!$isNetInvAmount) {
  if ($hasPayDiscount) {
    $discForMonthDue = "(SELECT COALESCE(SUM(pm2.discount),0) FROM payments pm2 WHERE " . ($payFk ? "pm2.`$payFk`=i.id" : "pm2.client_id=i.client_id") . payments_active_where($pdo,'pm2') . ")";
  } elseif ($invoiceDiscCol) {
    $discForMonthDue = "COALESCE(i.`$invoiceDiscCol`,0)";
  }
}
$sql_month_due = "
  SELECT COALESCE(SUM(GREATEST(0,
    COALESCE(i.`$invAmountCol`,0)
    - $discForMonthDue
    - (SELECT COALESCE(SUM(pm1.amount),0) FROM payments pm1 WHERE ".($payFk?"pm1.`$payFk`=i.id":"pm1.client_id=i.client_id"). payments_active_where($pdo,'pm1') .")
  )),0)
  FROM invoices i
  WHERE i.$whereMonth
";
$month_due_total = (float) pdo_scalar($pdo, $sql_month_due, $params_base);

/* (বাংলা) 2) Due (Filtered): বর্তমান search/tab ফিল্টার মিলিয়ে সকল ম্যাচিং ক্লায়েন্টের remain যোগফল (pagination ছাড়া) */
$sql_filtered_due = "SELECT COALESCE(SUM(r.remain),0) FROM ( $innerRows ) r ".
  ($tab==='paid' ? "WHERE r.remain<=0.0001" : ($tab==='due' ? "WHERE r.remain>0.0001" : ""));
$stfd = $pdo->prepare($sql_filtered_due);
$stfd->execute($params_rows);
$filtered_due_total = (float)$stfd->fetchColumn();

/* (বাংলা) Page Due */
$page_due_total=0.0;
foreach($rows as $r){
  $invAmount=(float)($r['inv_amount']??0);
  $paid     =(float)($r['paid_amount']??0);
  $disc     =(float)($r['discount']??0);
  $remain   = max(0.0, $invAmount - ($isNetInvAmount?0:$disc) - $paid);
  $page_due_total += $remain;
}

/* CSV (List) */
if ($export && $view!=='summary') {
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="billing-'.$monthParam.'-'.$tab.'.csv"');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['ClientID','Name','PPPoE','Mobile','Package','Status','InvoiceAmount','Discount','Paid','Payable','Ledger']);
  foreach ($rows as $r) {
    $invAmount=(float)($r['inv_amount']??0);
    $paid     =(float)($r['paid_amount']??0);
    $disc     =(float)($r['discount']??0);
    $remain   = max(0.0, $invAmount - ($isNetInvAmount?0:$disc) - $paid);
    $ledger   = (float)($r['ledger_balance']??0);
    fputcsv($out, [
      (int)$r['client_id'], (string)($r['client_name'] ?? ''), (string)($r['pppoe_id'] ?? ''),
      (string)($r['mobile'] ?? ''), (string)($r['package_name'] ?? ''), (string)($r['status'] ?? ''),
      number_format($invAmount,2,'.',''), number_format($disc,2,'.',''),
      number_format($paid,2,'.',''), number_format($remain,2,'.',''), number_format($ledger,2,'.',''),
    ]);
  }
  fclose($out); return;
}

/* current URL for return */
$cur_url = $_SERVER['REQUEST_URI'] ?? '/public/billing.php';

/* CSRF (বাংলা) modal actions এর জন্য নিশ্চিত করি */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$__csrf = $_SESSION['csrf_token'] ?? '';
if (!$__csrf) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); $__csrf = $_SESSION['csrf_token']; }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Billing</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
  :root{ --radius:14px; }
  .summary .num{font-weight:700}.summary .total{color:#0d6efd}.summary .paid{color:#198754}.summary .due{color:#dc3545}
  .seg a{text-decoration:none}.seg .btn{border-radius:999px;padding:.44rem .9rem}.seg .btn.active{pointer-events:none}
  .hero{background:linear-gradient(135deg,#eef4ff 0%,#f9fbff 100%);border:1px solid #e9eefc;border-radius:var(--radius)}
  .kpi{border:1px solid #eef2f7;border-radius:var(--radius);background:#fff;box-shadow:0 4px 20px rgba(0,0,0,.04)}
  .kpi .icon{width:42px;height:42px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;background:#f1f5ff;font-size:18px}
  .kpi .val{font-size:1.15rem;font-weight:800;letter-spacing:.3px}
  .kpi .hint{font-size:.775rem;color:#6c757d}
  .table-sm td,.table-sm th{padding:.6rem .7rem;line-height:1.2;vertical-align:middle;font-size:.92rem}
  .table thead{background:#f6f9ff}.table thead th{border-bottom:1px solid #e6eaf5}
  .table tbody tr:hover{background:#fafcff}
  .badge-pill{border-radius:999px;padding:.35rem .6rem;font-weight:600}
  .payable{font-weight:700}.payable.due{color:#dc3545}.payable.zero{color:#198754}
  .ratio-wrap{min-width:130px}.progress{height:8px;background:#eef2f7}.progress-bar{background:#20c997}
  @media(max-width:767.98px){.overflow-x{overflow-x:auto}}
</style>
</head>
<body>
<?php include __DIR__ . '/../partials/partials_header.php'; ?>

<div class="main-content p-3 p-md-4">
  <div class="container-fluid">

    <h3 class="mb-3">Billing</h3>

    <?php
      $qs=$_GET; $qs['page']=1;
      $qs_sum=$qs;  $qs_sum['view']='summary'; unset($qs_sum['tab']); unset($qs_sum['search']);
      $qs_all=$qs;  $qs_all['view']='list'; $qs_all['tab']='all';
      $qs_paid=$qs; $qs_paid['view']='list'; $qs_paid['tab']='paid';
      $qs_due=$qs;  $qs_due['view']='list'; $qs_due['tab']='due';
    ?>
    <div class="seg btn-group mb-3" role="group">
      <a class="btn btn-outline-primary <?= $view==='summary'?'active':'' ?>" href="?<?= h(http_build_query($qs_sum)) ?>"><i class="bi bi-graph-up"></i> Summary</a>
      <a class="btn btn-outline-secondary <?= ($view==='list' && $tab==='all')?'active':'' ?>" href="?<?= h(http_build_query($qs_all)) ?>"><i class="bi bi-list-ul"></i> All</a>
      <a class="btn btn-outline-success <?= ($view==='list' && $tab==='paid')?'active':'' ?>" href="?<?= h(http_build_query($qs_paid)) ?>"><i class="bi bi-check2-circle"></i> Paid</a>
      <a class="btn btn-outline-danger  <?= ($view==='list' && $tab==='due')?'active':'' ?>" href="?<?= h(http_build_query($qs_due )) ?>"><i class="bi bi-exclamation-octagon"></i> Due</a>
      <a href="/public/suspended_clients.php" class="btn btn-outline-danger btn-sm"> 📴 Auto Inactive </a>
      <?php if($view==='list'){ $qs_csv=$qs; $qs_csv['export']='csv'; ?>
        <a class="btn btn-outline-primary btn-sm" href="?<?= h(http_build_query($qs_csv)) ?>"><i class="bi bi-file-earmark-spreadsheet"></i> Export CSV</a>
      <?php } ?>
    </div>

    <!-- Filters -->
    <form class="card border-0 shadow-sm mb-3" method="GET">
      <div class="card-body">
        <div class="row g-2 align-items-end">
          <div class="col-12 col-sm-3">
            <label class="form-label mb-1">Month</label>
            <input type="month" class="form-control form-control-sm" name="month" value="<?= h($monthParam) ?>">
          </div>
          <?php if($view==='list'): ?>
          <div class="col-12 col-sm-5">
            <label class="form-label mb-1">Search</label>
            <input type="text" class="form-control form-control-sm" name="search" placeholder="Name / PPPoE / Mobile" value="<?= h($search) ?>">
          </div>
          <div class="col-6 col-sm-2 d-grid">
            <button class="btn btn-primary btn-sm"><i class="bi bi-search"></i> Apply</button>
          </div>
          <div class="col-6 col-sm-2 d-grid">
            <a class="btn btn-outline-secondary btn-sm" href="?month=<?= h(date('Y-m')) ?>&view=<?= h($view) ?>&tab=<?= h($tab) ?>"><i class="bi bi-x-circle"></i> Reset</a>
          </div>
          <?php else: ?>
          <div class="col-6 col-sm-2 d-grid">
            <button class="btn btn-primary btn-sm"><i class="bi bi-search"></i> Apply</button>
          </div>
          <div class="col-6 col-sm-2 d-grid">
            <a class="btn btn-outline-secondary btn-sm" href="?month=<?= h(date('Y-m')) ?>&view=summary"><i class="bi bi-x-circle"></i> Reset</a>
          </div>
          <?php endif; ?>
        </div>
        <input type="hidden" name="view" value="<?= h($view) ?>">
        <?php if($view==='list'): ?><input type="hidden" name="tab" value="<?= h($tab) ?>"><?php endif; ?>
        <input type="hidden" name="page" value="1">
        <input type="hidden" name="limit" value="<?= (int)$limit ?>">
      </div>
    </form>

    <?php if($view==='summary'): ?>

      <div class="hero p-3 p-md-4 mb-3">
        <div class="d-flex flex-wrap align-items-end justify-content-between gap-2">
          <div>
            <h4 class="mb-1">Bills vs Collections</h4>
            <div class="text-muted">Month: <?= h(date('F Y', strtotime($monthParam.'-01'))) ?></div>
          </div>
          <div class="summary d-inline-flex flex-wrap gap-4">
            <div><span class="text-muted">Invoices:</span> <span class="num total"><?= number_format($count_total) ?></span></div>
            <div><span class="text-muted">Paid:</span> <span class="num paid"><?= number_format($count_paid) ?></span></div>
            <div><span class="text-muted">Due:</span> <span class="num due"><?= number_format($count_due) ?></span></div>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-6 col-md-3"><div class="kpi p-3"><div class="d-flex align-items-center gap-2 mb-2"><div class="icon text-primary"><i class="bi bi-receipt"></i></div><div class="hint">Generated</div></div><div class="val text-primary">৳ <?= number_format($z_tot_gen,2) ?></div></div></div>
        <div class="col-6 col-md-3"><div class="kpi p-3"><div class="d-flex align-items-center gap-2 mb-2"><div class="icon text-success"><i class="bi bi-coin"></i></div><div class="hint">Collection</div></div><div class="val text-success">৳ <?= number_format($z_tot_col,2) ?></div></div></div>
        <div class="col-6 col-md-3"><div class="kpi p-3"><div class="d-flex align-items-center gap-2 mb-2"><div class="icon text-info"><i class="bi bi-percent"></i></div><div class="hint">Discount</div></div><div class="val text-info">৳ <?= number_format($z_tot_dis,2) ?></div></div></div>
        <div class="col-6 col-md-3"><div class="kpi p-3"><div class="d-flex align-items-center gap-2 mb-2"><div class="icon text-danger"><i class="bi bi-exclamation-octagon"></i></div><div class="hint">Total Due</div></div><div class="val text-danger">৳ <?= number_format($z_tot_due,2) ?></div></div></div>
      </div>

      <div class="card border-0 shadow-sm">
        <div class="card-header fw-semibold">Zones / Areas</div>
        <div class="card-body p-0">
          <?php if($summaryError): ?>
            <div class="alert alert-danger m-3">Failed to load summary: <?= h($summaryError) ?></div>
          <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
              <thead>
                <tr>
                  <th style="width:56px">SL</th>
                  <th>Zone / Area</th>
                  <th class="text-end">Generated</th>
                  <th class="text-end">Collection</th>
                  <th class="text-end">Discount</th>
                  <th class="text-end">Due</th>
                  <th class="text-end">Ratio</th>
                </tr>
              </thead>
              <tbody>
                <?php if($zonesData): $sl=1; foreach($zonesData as $z): ?>
                <tr>
                  <td><?= $sl++ ?></td>
                  <td>
                    <div class="fw-semibold"><?= h($z['zone_name']) ?></div>
                    <div class="small">
                      <span class="badge bg-success-subtle text-success border badge-pill">Active: <?= (int)$z['active_clients'] ?></span>
                      <span class="badge bg-danger-subtle text-danger border badge-pill ms-1">Inactive: <?= (int)$z['inactive_clients'] ?></span>
                      <span class="text-muted ms-1 small">Total: <?= (int)$z['total_clients'] ?></span>
                    </div>
                  </td>
                  <td class="text-end fw-semibold">৳ <?= number_format($z['generated'],2) ?></td>
                  <td class="text-end text-success fw-semibold">৳ <?= number_format($z['collection'],2) ?></td>
                  <td class="text-end text-primary">৳ <?= number_format($z['discount'],2) ?></td>
                  <td class="text-end text-danger fw-semibold">৳ <?= number_format($z['due'],2) ?></td>
                  <td class="text-end">
                    <div class="ratio-wrap">
                      <div class="d-flex justify-content-between small text-muted mb-1">
                        <span><?= number_format($z['ratio'],2) ?>%</span>
                        <span><?= $z['generated']>0? number_format(($z['collection']/$z['generated'])*100,0).'%' : '0%' ?></span>
                      </div>
                      <div class="progress"><div class="progress-bar" style="width: <?= max(0,min(100,$z['ratio'])) ?>%"></div></div>
                    </div>
                  </td>
                </tr>
                <?php endforeach; else: ?>
                  <tr><td colspan="7" class="text-center text-muted">No data.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
      </div>

    <?php else: ?><!-- LIST VIEW -->

      <div class="text-left mb-2">
        <div class="summary d-inline-flex flex-wrap gap-4 fs-5 fs-md-4">
          <div><span class="text-muted">Total Bills :</span> <span class="num total"><?= number_format($count_total) ?></span></div>
          <div><span class="text-muted">Paid :</span> <span class="num paid"><?= number_format($count_paid) ?></span></div>
          <div><span class="text-muted">Due Bills :</span> <span class="num due"><?= number_format($count_due) ?></span></div>
        </div>
      </div>

      <!-- Totals row -->
      <div class="alert alert-light border d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div class="fw-semibold">
          <i class="bi bi-exclamation-octagon text-danger"></i>
          Total Due (Month): <span class="text-danger">৳ <?= number_format($month_due_total,2) ?></span>
        </div>
        <div class="text-muted">
          <span class="me-3">Due (Filtered): <span class="fw-semibold">৳ <?= number_format($filtered_due_total,2) ?></span></span>
          <span>Due (This Page): <span class="fw-semibold">৳ <?= number_format($page_due_total,2) ?></span></span>
        </div>
      </div>

      <div class="overflow-x">
        <table class="table table-striped table-hover table-sm align-middle">
          <thead>
            <tr>
              <th>Client ID</th>
              <th>ID / Name / Cell</th>
              <th>Package</th>
              <th>Status</th>
              <th class="text-end">Total</th>
              <?php if($showDiscountCol): ?><th class="text-end">Discount</th><?php endif; ?>
              <th class="text-end">Paid</th>
              <th class="text-end">Payable</th>
              <th class="text-end">Ledger</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if($rows): foreach($rows as $r):
              $invAmount = (float)($r['inv_amount']??0);
              $paid      = (float)($r['paid_amount']??0);
              $disc      = (float)($r['discount']??0);
              $remain    = max(0.0, $invAmount - ($isNetInvAmount?0:$disc) - $paid);
              $pay_class = $remain>0.0001?'due':'zero';
              $status_badge = $r['status']==='paid'?'success':($r['status']==='partial'?'warning text-dark':($r['status']==='unpaid'?'danger':'secondary'));
              $ledger    = (float)($r['ledger_balance']??0);
              $lb_label  = '৳ '.number_format(abs($ledger),2);
              $lb_label  = $ledger>=0 ? $lb_label : ('-'.$lb_label);
              $lb_class  = $ledger>0?'danger':($ledger<0?'success':'secondary');

              $return = $cur_url;
              $pay_url = 'payment_add.php?invoice_id='.(int)$r['invoice_id'].'&return='.rawurlencode($return);
              $ledger_url = 'client_ledger.php?client_id='.(int)$r['client_id'].'&return='.rawurlencode($cur_url);
            ?>
            <tr class="<?= $remain<=0.0001 ? 'table-success' : '' ?>">
              <td>#<?= (int)$r['client_id'] ?></td>
              <td>
                <div class="fw-semibold"><a class="text-decoration-none" href="client_view.php?id=<?= (int)$r['client_id'] ?>"><?= h($r['client_name']) ?></a></div>
                <div class="text-muted small"><?= h($r['pppoe_id'] ?? '') ?><?= ($r['mobile'] ?? '') ? ' • '.h($r['mobile']) : '' ?></div>
              </td>
              <td><?= h($r['package_name'] ?? '-') ?></td>
              <td><span class="badge bg-<?= $status_badge ?>"><?= ucfirst((string)$r['status']) ?></span></td>
              <td class="text-end">৳ <?= number_format($invAmount, 2) ?></td>

              <?php if($showDiscountCol): ?>
              <td class="text-end">
                ৳ <?= number_format($disc, 2) ?>
                <?php if ($disc > 0): ?>
                  <button type="button"
                          class="btn btn-link btn-sm p-0 ms-1 manage-discount"
                          data-invoice="<?= (int)$r['invoice_id'] ?>"
                          title="Manage discounts for this invoice">
                    <i class="bi bi-gear"></i>
                  </button>
                <?php endif; ?>
              </td>
              <?php endif; ?>

              <td class="text-end">৳ <?= number_format($paid, 2) ?></td>
              <td class="text-end payable <?= $pay_class ?>">৳ <?= number_format($remain, 2) ?></td>
              <td class="text-end"><span class="badge bg-<?= $lb_class ?>" title="Positive = Due, Negative = Advance"><?= $lb_label ?></span></td>
              <td>
                <div class="btn-group btn-group-sm">
                  <a class="btn btn-outline-success" title="Pay (Full/Partial)" href="<?= h($pay_url) ?>"><i class="bi bi-cash-coin"></i> Pay</a>
                  <a class="btn btn-outline-secondary" title="Invoice" href="invoice_view.php?id=<?= (int)$r['invoice_id'] ?>"><i class="bi bi-receipt"></i></a>
                  <a class="btn btn-outline-info" title="View Ledger" href="<?= h($ledger_url) ?>"><i class="bi bi-eye"></i></a>
                </div>
              </td>
            </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="<?= $showDiscountCol? '10':'9' ?>" class="text-center text-muted">No data found for this month.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <?php if($pages>1): ?>
      <nav class="mt-3">
        <ul class="pagination pagination-sm justify-content-center">
          <?php $qs=$_GET; $prev=max(1,$page-1); $next=min($pages,$page+1); ?>
          <li class="page-item <?= $page<=1?'disabled':'' ?>"><?php $qs['page']=$prev; ?><a class="page-link" href="?<?= h(http_build_query($qs)) ?>">Previous</a></li>
          <?php $start=max(1,$page-2); $end=min($pages,$start+4); $start=max(1,min($start,$end-4));
          for($p=$start;$p<=$end;$p++): $qs['page']=$p; ?>
            <li class="page-item <?= $p==$page?'active':'' ?>"><a class="page-link" href="?<?= h(http_build_query($qs)) ?>"><?= $p ?></a></li>
          <?php endfor; ?>
          <li class="page-item <?= $page>=$pages?'disabled':'' ?>"><?php $qs['page']=$next; ?><a class="page-link" href="?<?= h(http_build_query($qs)) ?>">Next</a></li>
        </ul>
      </nav>
      <?php endif; ?>

    <?php endif; ?>

  </div>
</div>

<!-- Discount Manager Modal -->
<div class="modal fade" id="discountModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title">Manage Discounts</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="disc-info" class="mb-3 small text-muted">Loading…</div>
        <div class="mb-2">
          <div class="fw-semibold">Invoice-level Discount</div>
          <div id="inv-disc-row" class="d-flex align-items-center justify-content-between border rounded p-2">
            <span id="inv-disc-amt">৳ 0.00</span>
            <button id="btn-clear-inv" class="btn btn-outline-danger btn-sm" disabled>
              <i class="bi bi-x-circle"></i> Clear
            </button>
          </div>
        </div>
        <div class="mt-3">
          <div class="fw-semibold mb-1">Payment Discounts</div>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead><tr><th>#ID</th><th>Date</th><th>Method</th><th class="text-end">Discount</th><th class="text-end">Action</th></tr></thead>
              <tbody id="pay-disc-tbody">
                <tr><td colspan="5" class="text-center text-muted">No rows.</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <small class="text-muted me-auto">Tip: Clearing will just set discount = 0 (soft delete).</small>
        <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
<script>
/* (বাংলা) পেমেন্ট হয়ে ফিরে এলে রিসিট অটো-ওপেন; ছোট Toast */
(function(){
  const q = new URLSearchParams(location.search);
  if (q.get('ok')==='1' && q.get('pid')) {
    const pid = q.get('pid');
    const w   = q.get('w') || '58';
    const url = `/public/receipt_payment.php?payment_id=${encodeURIComponent(pid)}&w=${encodeURIComponent(w)}&autoprint=1`;
    window.open(url, '_blank', 'noopener');
    const t = document.createElement('div');
    t.style.position='fixed'; t.style.left='50%'; t.style.top='20px'; t.style.transform='translateX(-50%)';
    t.style.background='#198754'; t.style.color='#fff'; t.style.padding='10px 14px';
    t.style.borderRadius='10px'; t.style.boxShadow='0 10px 30px rgba(0,0,0,.2)'; t.style.zIndex='9999';
    t.textContent='Payment saved';
    document.body.appendChild(t);
    setTimeout(()=>{ t.style.opacity='0'; t.style.transform='translate(-50%,-10px)'; }, 1800);
    setTimeout(()=> t.remove(), 2300);
  }
})();

/* (বাংলা) Discount Manager Modal লজিক */
(function(){
  const CSRF = <?= json_encode($__csrf ?? '') ?>;
  const modalEl = document.getElementById('discountModal');
  const discInfo = document.getElementById('disc-info');
  const invAmtEl = document.getElementById('inv-disc-amt');
  const btnClearInv = document.getElementById('btn-clear-inv');
  const tbody = document.getElementById('pay-disc-tbody');
  let currentInvoiceId = 0;

  async function apiCall(payload){
    const res = await fetch('/public/billing_discount_api.php', {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body: new URLSearchParams(payload)
    });
    return res.json();
  }

  async function loadDiscounts(invId){
    discInfo.textContent = 'Loading…';
    invAmtEl.textContent = '৳ 0.00';
    btnClearInv.disabled = true;
    tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">Loading…</td></tr>';

    const data = await apiCall({ action:'list', invoice_id: invId, csrf: CSRF });
    if (!data.ok) {
      discInfo.textContent = 'Failed: ' + (data.error || 'Unknown error');
      return;
    }
    discInfo.textContent = 'Invoice ID: ' + invId;

    const inv = Number(data.invoice_discount || 0);
    invAmtEl.textContent = '৳ ' + inv.toFixed(2);
    btnClearInv.disabled = !(inv > 0.0001);

    const pays = Array.isArray(data.payments) ? data.payments : [];
    if (!pays.length) {
      tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No payment discounts.</td></tr>';
    } else {
      tbody.innerHTML = '';
      pays.forEach(row => {
        const tr = document.createElement('tr');
        const id = Number(row.id);
        const disc = Number(row.discount || 0);
        const dt = row.pdate || '';
        const method = row.method || '';
        tr.innerHTML = `
          <td>${id}</td>
          <td>${dt ? dt : '-'}</td>
          <td>${method ? method : '-'}</td>
          <td class="text-end">৳ ${disc.toFixed(2)}</td>
          <td class="text-end">
            <button class="btn btn-outline-danger btn-sm btn-del-pay" data-pid="${id}">
              <i class="bi bi-x-circle"></i> Clear
            </button>
          </td>
        `;
        tbody.appendChild(tr);
      });
    }
  }

  // Open modal
  document.addEventListener('click', function(ev){
    const btn = ev.target.closest('.manage-discount');
    if (!btn) return;
    ev.preventDefault();
    currentInvoiceId = Number(btn.dataset.invoice) || 0;
    if (!currentInvoiceId) return;

    const modal = new bootstrap.Modal(modalEl);
    modal.show();
    loadDiscounts(currentInvoiceId);
  });

  // Clear invoice-level discount
  btnClearInv?.addEventListener('click', async function(){
    if (this.disabled || !currentInvoiceId) return;
    if (!confirm('Clear invoice-level discount?')) return;
    const data = await apiCall({ action:'clear_invoice', invoice_id: currentInvoiceId, csrf: CSRF });
    if (!data.ok) { alert(data.error || 'Failed'); return; }
    await loadDiscounts(currentInvoiceId);
    location.reload();
  });

  // Clear payment-level discount (event delegation)
  tbody?.addEventListener('click', async function(ev){
    const b = ev.target.closest('.btn-del-pay');
    if (!b) return;
    const pid = Number(b.dataset.pid) || 0;
    if (!pid) return;
    if (!confirm('Clear this payment discount?')) return;
    const data = await apiCall({ action:'delete_payment', payment_id: pid, csrf: CSRF });
    if (!data.ok) { alert(data.error || 'Failed'); return; }
    await loadDiscounts(currentInvoiceId);
    location.reload();
  });
})();
</script>

<?php if (isset($_GET['debug'])): ?>
<pre style="background:#111;color:#9f9;padding:12px;border-radius:8px;margin:12px">
invAmountCol    = <?= h($invAmountCol) . ($isNetInvAmount?' (NET)':' (GROSS)') . "\n" ?>
invoiceDiscCol  = <?= h($invoiceDiscCol ?? 'NULL') . "\n" ?>
hasPayDiscount  = <?= h($hasPayDiscount ? '1':'0') . "\n" ?>
payFk           = <?= h($payFk ?? 'NULL') . "\n" ?>
month_due_total = <?= number_format($month_due_total,2) . "\n" ?>
filtered_due_total = <?= number_format($filtered_due_total,2) . "\n" ?>
pages = <?= (int)$pages ?>, page = <?= (int)$page ?>, limit = <?= (int)$limit ?>, tab = <?= h($tab) . "\n" ?>
</pre>
<?php endif; ?>

</body>
</html>
