<?php
// /public/index.php
// বাংলা: Dashboard — একরকম slim KPI card শেপ (আইকন বৃত্ত + বড় সংখ্যা + লেবেল + ছোট টেইল)
// নোট: Financial Summary-তে দশমিক সরানো হয়েছে (nf($x) => 0 decimal)

declare(strict_types=1);

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------- helpers ---------- */
// (বাংলা) টেবিল/কলাম সেফ চেক
function col_exists(PDO $pdo, string $table, string $column): bool {
  try { $st=$pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?"); $st->execute([$column]); return (bool)$st->fetch(PDO::FETCH_ASSOC); }
  catch (Throwable $e){ return false; }
}
function tbl_exists(PDO $pdo, string $t): bool {
  try{
    $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
    $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
    $q->execute([$db,$t]); return (bool)$q->fetchColumn();
  }catch(Throwable $e){ return false; }
}
function q_scalar(PDO $pdo, string $sql, array $p=[], $def=0){
  $st=$pdo->prepare($sql); $st->execute($p); $v=$st->fetchColumn();
  return $v===false?$def:$v;
}
// (বাংলা) ডিফল্ট 0 decimal
function nf($n, int $d=0): string { return number_format((float)$n, $d, '.', ','); }
function pct_change($now, $prev): ?float { $prev=(float)$prev; if (abs($prev)<1e-9) return null; return ((float)$now - $prev)/$prev*100.0; }

/* ---------- dates ---------- */
$today = date('Y-m-d');
$mStart = date('Y-m-01 00:00:00');  $mEnd = date('Y-m-t 23:59:59');
$pmStart= date('Y-m-01 00:00:00', strtotime('first day of last month'));
$pmEnd  = date('Y-m-t 23:59:59', strtotime('last day of last month'));

/* ---------- schema guards ---------- */
$has_clients         = tbl_exists($pdo,'clients');
$has_is_left         = $has_clients && col_exists($pdo,'clients','is_left');
$has_status          = $has_clients && col_exists($pdo,'clients','status');
$has_join_date       = $has_clients && col_exists($pdo,'clients','join_date');
$has_is_online       = $has_clients && col_exists($pdo,'clients','is_online');
$has_expiry_date     = $has_clients && col_exists($pdo,'clients','expiry_date');
$has_updated_at      = $has_clients && col_exists($pdo,'clients','updated_at');
$has_ledger_balance  = $has_clients && col_exists($pdo,'clients','ledger_balance');

$has_payments = tbl_exists($pdo,'payments') && col_exists($pdo,'payments','paid_at') && col_exists($pdo,'payments','amount');
$has_invoices = tbl_exists($pdo,'invoices') && (col_exists($pdo,'invoices','created_at') || (col_exists($pdo,'invoices','month') && col_exists($pdo,'invoices','year')));

/* ---------- counters ---------- */
// Auto suspended (by billing) fallback লজিকসহ
$auto_suspended = 0;
if (col_exists($pdo,'clients','suspend_by_billing')) {
  $auto_suspended = (int)q_scalar($pdo,"SELECT COUNT(*) FROM clients WHERE suspend_by_billing=1");
} else {
  $cond_due = $has_ledger_balance ? "COALESCE(ledger_balance,0)>0" : "1=1";
  $auto_suspended = (int)q_scalar($pdo,"SELECT COUNT(*) FROM clients WHERE $cond_due AND COALESCE(status,'')='inactive'");
}

$total_clients    = (int)q_scalar($pdo,"SELECT COUNT(*) FROM clients WHERE ".($has_is_left?'is_left=0':'1=1'));
$active_clients   = ($has_status)? (int)q_scalar($pdo,"SELECT COUNT(*) FROM clients WHERE ".($has_is_left?'is_left=0 AND ':'')."status='active'") : 0;
$inactive_clients = ($has_status)? (int)q_scalar($pdo,"SELECT COUNT(*) FROM clients WHERE ".($has_is_left?'is_left=0 AND ':'')."status='inactive'") : 0;
$pending_clients  = ($has_status)? (int)q_scalar($pdo,"SELECT COUNT(*) FROM clients WHERE ".($has_is_left?'is_left=0 AND ':'')."status='pending'") : 0;
$total_disabled   = ($has_status)? (int)q_scalar($pdo,"SELECT COUNT(*) FROM clients WHERE ".($has_is_left?'is_left=0 AND ':'')."status='disabled'") : 0;
$left_clients     = (int)q_scalar($pdo,"SELECT COUNT(*) FROM clients WHERE ".($has_is_left?'is_left=1':'1=0'));

$expired_clients  = ($has_expiry_date)? (int)q_scalar($pdo,"SELECT COUNT(*) FROM clients WHERE ".($has_is_left?'is_left=0 AND ':'')."expiry_date IS NOT NULL AND DATE(expiry_date) < CURDATE()") : 0;

if ($has_is_online) {
  $total_online  = (int)q_scalar($pdo,"SELECT COUNT(*) FROM clients WHERE ".($has_is_left?'is_left=0 AND ':'')."is_online=1");
  $total_offline = (int)q_scalar($pdo,"SELECT COUNT(*) FROM clients WHERE ".($has_is_left?'is_left=0 AND ':'')."is_online=0");
} else { $total_online=0; $total_offline=$total_clients; }

$today_joined   = ($has_join_date)? (int)q_scalar($pdo,"SELECT COUNT(*) FROM clients WHERE ".($has_is_left?'is_left=0 AND ':'')."DATE(join_date)=?",[$today]) : 0;
$today_expired  = ($has_expiry_date)? (int)q_scalar($pdo,"SELECT COUNT(*) FROM clients WHERE ".($has_is_left?'is_left=0 AND ':'')."DATE(expiry_date)=?",[$today]) : 0;
$today_inactive = ($has_updated_at&&$has_status)? (int)q_scalar($pdo,"SELECT COUNT(*) FROM clients WHERE ".($has_is_left?'is_left=0 AND ':'')."status='inactive' AND DATE(updated_at)=?",[$today]) : 0;

/* ---------- ledger & finance ---------- */
$total_due=0.0; $total_adv=0.0; $net_balance_now=0.0;
if ($has_ledger_balance){
  $st=$pdo->query("SELECT
    SUM(CASE WHEN ledger_balance>0 THEN ledger_balance ELSE 0 END),
    SUM(CASE WHEN ledger_balance<0 THEN -ledger_balance ELSE 0 END)
  FROM clients ".($has_is_left?"WHERE is_left=0":""));
  [$d,$a]=$st->fetch(PDO::FETCH_NUM) ?: [0,0];
  $total_due=(float)$d; $total_adv=(float)$a; $net_balance_now=$total_due-$total_adv;
}

// Top KPI calcs
$sales_now=0; $sales_prev=0; // payments count
if ($has_payments){
  $sales_now  = (int)q_scalar($pdo,"SELECT COUNT(*) FROM payments WHERE paid_at BETWEEN ? AND ?",[$mStart,$mEnd]);
  $sales_prev = (int)q_scalar($pdo,"SELECT COUNT(*) FROM payments WHERE paid_at BETWEEN ? AND ?",[$pmStart,$pmEnd]);
}
$sales_pct = pct_change($sales_now,$sales_prev);

$cust_now=$total_clients; $cust_prev=$cust_now;
if ($has_join_date && $has_is_left) { $cust_prev = (int)q_scalar($pdo,"SELECT COUNT(*) FROM clients WHERE is_left=0 AND join_date < ?",[$mStart]); }
$cust_pct = pct_change($cust_now,$cust_prev);

$avg_bill_now=0.0; $avg_bill_prev=0.0;
if ($has_invoices){
  $has_created = col_exists($pdo,'invoices','created_at');
  $has_payable = col_exists($pdo,'invoices','payable');
  $col = $has_payable ? 'payable' : 'amount';
  if ($has_created){
    $avg_bill_now  = (float)q_scalar($pdo,"SELECT AVG($col) FROM invoices WHERE created_at BETWEEN ? AND ?",[$mStart,$mEnd]);
    $avg_bill_prev = (float)q_scalar($pdo,"SELECT AVG($col) FROM invoices WHERE created_at BETWEEN ? AND ?",[$pmStart,$pmEnd]);
  } else {
    $avg_bill_now  = (float)q_scalar($pdo,"SELECT AVG($col) FROM invoices WHERE month=? AND year=?",[(int)date('n'),(int)date('Y')]);
    $lm=(int)date('n',strtotime('last month')); $ly=(int)date('Y',strtotime('last month'));
    $avg_bill_prev = (float)q_scalar($pdo,"SELECT AVG($col) FROM invoices WHERE month=? AND year=?",[$lm,$ly]);
  }
}
$avg_bill_pct = pct_change($avg_bill_now,$avg_bill_prev);

// Balance MoM approx
$balance_prev=null; $balance_pct=null;
if ($has_ledger_balance){
  $flow_invoices=0.0; $flow_payments=0.0;
  if ($has_invoices){
    $has_created = col_exists($pdo,'invoices','created_at'); $has_payable = col_exists($pdo,'invoices','payable'); $col=$has_payable?'payable':'amount';
    $flow_invoices = $has_created
      ? (float)q_scalar($pdo,"SELECT SUM($col) FROM invoices WHERE created_at BETWEEN ? AND ?",[$mStart,$mEnd])
      : (float)q_scalar($pdo,"SELECT SUM($col) FROM invoices WHERE month=? AND year=?",[(int)date('n'),(int)date('Y')]);
  }
  if ($has_payments){
    $flow_payments = (float)q_scalar($pdo,"SELECT SUM(amount) FROM payments WHERE paid_at BETWEEN ? AND ?",[$mStart,$mEnd]);
  }
  $balance_prev = $net_balance_now - ($flow_invoices - $flow_payments);
  $balance_pct  = pct_change($net_balance_now,$balance_prev);
}

// Quick metrics
$todays_collection=0.0; $this_month_collection=0.0; $unpaid_invoices=0;
if ($has_payments){
  $d1=date('Y-m-d 00:00:00'); $d2=date('Y-m-d 23:59:59');
  $todays_collection = (float)q_scalar($pdo,"SELECT SUM(amount) FROM payments WHERE paid_at BETWEEN ? AND ?",[$d1,$d2]);
  $this_month_collection = (float)q_scalar($pdo,"SELECT SUM(amount) FROM payments WHERE paid_at BETWEEN ? AND ?",[$mStart,$mEnd]);
}
if (tbl_exists($pdo,'invoices')){
  $unpaid_invoices = (int)q_scalar($pdo,"SELECT COUNT(*) FROM invoices WHERE status IN ('unpaid','partial')");
}

$page_title = "Dashboard";
?>
<?php require __DIR__ . '/../partials/partials_header.php'; ?>

<style>
/* ======== Slim KPI Card (একই শেপ সবার জন্য) ======== */
.slim-kpi{
  background:#fff; border:1px solid #e5e7eb; border-radius:8px;
  padding:12px 14px; display:flex; gap:10px; align-items:flex-start;
  box-shadow:0 4px 12px rgba(16,24,40,.06);
}
.slim-kpi .ico{
  width:38px; height:38px; border-radius:10px; display:grid; place-items:center;
  color:#fff; font-size:18px; flex:0 0 auto;
}
.i-blue{background:#60a5fa;} .i-green{background:#34d399;} .i-amber{background:#fbbf24;} .i-rose{background:#f87171;}
.i-slate{background:#94a3b8;} .i-cyan{background:#22d3ee;} .i-orange{background:#f59e0b;} .i-gray{background:#9ca3af;}
.slim-kpi .num{margin:0; font-weight:800; font-size:22px; line-height:1;}
.slim-kpi .lbl{margin:0; font-size:13px; color:#4b5563;}
.slim-kpi .tail{font-size:12px; margin-top:2px;}
.ind-up{color:#059669;} .ind-down{color:#e11d48;} .ind-na{color:#6b7280;}

.section-title{font-weight:600; color:#374151; font-size:14px; margin:10px 0 6px;}
/* grid */
.grid-4{display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:.6rem;}
@media (max-width: 992px){ .grid-4{grid-template-columns:repeat(2,minmax(0,1fr));} }
.grid-6{display:grid; grid-template-columns:repeat(6,minmax(0,1fr)); gap:.6rem;}
@media (max-width: 1200px){ .grid-6{grid-template-columns:repeat(3,minmax(0,1fr));} }
@media (max-width: 768px){ .grid-6{grid-template-columns:repeat(2,minmax(0,1fr));} }
</style>

<div class="container-fluid py-2">

 

  <!-- ===== Status/Counts — একই শেপে সব বোতাম ===== -->
  <div class="grid-6 mb-2">
    <div class="slim-kpi"><div class="ico i-blue"><i class="bi bi-people-fill"></i></div><div><p class="num"><?= nf($total_clients) ?></p><p class="lbl">Total Clinet</p><div class="tail"><span class="ind-na">&nbsp;</span></div></div></div>
    <div class="slim-kpi"><div class="ico i-green"><i class="bi bi-check-circle"></i></div><div><p class="num"><?= nf($active_clients) ?></p><p class="lbl">Active Client</p><div class="tail"><span class="ind-na">&nbsp;</span></div></div></div>
    <div class="slim-kpi"><div class="ico i-rose"><i class="bi bi-x-circle"></i></div><div><p class="num"><?= nf($inactive_clients) ?></p><p class="lbl">Inactive Cleint</p><div class="tail"><span class="ind-na">&nbsp;</span></div></div></div>
    <div class="slim-kpi"><div class="ico i-rose"><i class="bi bi-slash-circle"></i></div><div><p class="num"><?= nf($auto_suspended) ?></p><p class="lbl">Auto Inactive</p><div class="tail"><span class="ind-na">&nbsp;</span></div></div></div>
    <div class="slim-kpi"><div class="ico i-orange"><i class="bi bi-exclamation-circle"></i></div><div><p class="num"><?= nf($expired_clients) ?></p><p class="lbl">Expired Client</p><div class="tail"><span class="ind-na">&nbsp;</span></div></div></div>
    <div class="slim-kpi"><div class="ico i-amber"><i class="bi bi-hourglass-split"></i></div><div><p class="num"><?= nf($pending_clients) ?></p><p class="lbl">Pending Clinet</p><div class="tail"><span class="ind-na">&nbsp;</span></div></div></div>

    <div class="slim-kpi"><div class="ico i-slate"><i class="bi bi-box-arrow-left"></i></div><div><p class="num"><?= nf($left_clients) ?></p><p class="lbl">Left Cleint</p><div class="tail"><span class="ind-na">&nbsp;</span></div></div></div>
    <div class="slim-kpi"><div class="ico i-cyan"><i class="bi bi-wifi"></i></div><div><p class="num"><?= nf($total_online) ?></p><p class="lbl">Online</p><div class="tail"><span class="ind-na">&nbsp;</span></div></div></div>
    <div class="slim-kpi"><div class="ico i-gray"><i class="bi bi-wifi-off"></i></div><div><p class="num"><?= nf($total_offline) ?></p><p class="lbl">Offline</p><div class="tail"><span class="ind-na">&nbsp;</span></div></div></div>
    <div class="slim-kpi"><div class="ico i-slate"><i class="bi bi-ban"></i></div><div><p class="num"><?= nf($total_disabled) ?></p><p class="lbl">Disabled</p><div class="tail"><span class="ind-na">&nbsp;</span></div></div></div>

    <div class="slim-kpi"><div class="ico i-blue"><i class="bi bi-person-plus"></i></div><div><p class="num"><?= nf($today_joined) ?></p><p class="lbl">Today's Joined</p><div class="tail"><span class="ind-na">&nbsp;</span></div></div></div>
    <div class="slim-kpi"><div class="ico i-orange"><i class="bi bi-calendar-x"></i></div><div><p class="num"><?= nf($today_expired) ?></p><p class="lbl">Today's Expired</p><div class="tail"><span class="ind-na">&nbsp;</span></div></div></div>
    <div class="slim-kpi"><div class="ico i-rose"><i class="bi bi-slash-circle"></i></div><div><p class="num"><?= nf($today_inactive) ?></p><p class="lbl">Today's Inactive</p><div class="tail"><span class="ind-na">&nbsp;</span></div></div></div>
  </div>

  <!-- ===== Financial Summary — একই শেপ (NO DECIMALS) ===== -->
  <div class="section-title">Financial Summary</div>
  <div class="grid-6">
    <div class="slim-kpi">
      <div class="ico i-rose"><i class="bi bi-cash-coin"></i></div>
      <div><p class="num"><?= nf($total_due) ?></p><p class="lbl">Total Due (Tk)</p><div class="tail"><span class="ind-na">&nbsp;</span></div></div>
    </div>
    <div class="slim-kpi">
      <div class="ico i-green"><i class="bi bi-piggy-bank"></i></div>
      <div><p class="num"><?= nf($total_adv) ?></p><p class="lbl">Total Advance (Tk)</p><div class="tail"><span class="ind-na">&nbsp;</span></div></div>
    </div>
    <div class="slim-kpi">
      <div class="ico i-cyan"><i class="bi bi-cash-stack"></i></div>
      <div><p class="num"><?= nf($todays_collection) ?></p><p class="lbl">Today's Collection (Tk)</p><div class="tail"><span class="ind-na">&nbsp;</span></div></div>
    </div>
    <div class="slim-kpi">
      <div class="ico i-green"><i class="bi bi-collection"></i></div>
      <div><p class="num"><?= nf($this_month_collection) ?></p><p class="lbl">This Month Collection (Tk)</p><div class="tail"><span class="ind-na">&nbsp;</span></div></div>
    </div>
    <div class="slim-kpi">
      <div class="ico i-orange"><i class="bi bi-exclamation-triangle"></i></div>
      <div><p class="num"><?= nf($unpaid_invoices) ?></p><p class="lbl">Unpaid / Partial Invoices</p><div class="tail"><span class="ind-na">&nbsp;</span></div></div>
    </div>
  </div>

</div>


 </div>
  </div>

</div> </div>
  </div>

</div>

<a href="https://info.flagcounter.com/HCBe"><img src="https://s01.flagcounter.com/count2/HCBe/bg_FFFFFF/txt_000000/border_CCCCCC/columns_2/maxflags_10/viewers_0/labels_0/pageviews_0/flags_0/percent_0/" alt="Flag Counter" border="0"></a>

<?php require __DIR__ . '/../partials/partials_footer.php'; ?>
