<?php
// /public/income_expense.php
// Income (payments) vs Expense (expenses) — Filters + Chart + CSV export
// কোড ইংরেজি; কমেন্ট বাংলা।

declare(strict_types=1);
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/acl.php';

require_perm('report.income_expense'); // পারমিশন লাগবে

$pdo = db();
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------- helpers: table/column detection ---------- */
function tbl_exists(PDO $pdo, string $t): bool{
  try{
    $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
    $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
    $q->execute([$db,$t]); return (bool)$q->fetchColumn();
  }catch(Throwable $e){ return false; }
}
function col_exists(PDO $pdo, string $t, string $c): bool{
  try{
    $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
    $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
    $q->execute([$db,$t,$c]); return (bool)$q->fetchColumn();
  }catch(Throwable $e){ return false; }
}
function pick_col_or_null(PDO $pdo, string $t, array $cands): ?string{
  foreach($cands as $c){ if(col_exists($pdo,$t,$c)) return $c; }
  return null;
}

/* ---------- inputs: date range & filters ---------- */
$today = date('Y-m-d');
$start_default = date('Y-m-01', strtotime('-11 months', strtotime($today))); // last 12 months
$end_default   = $today;

$g   = ($_GET['g'] ?? 'month');                // group by: month|day
$df  = (trim($_GET['date_from'] ?? '') ?: $start_default);
$dt  = (trim($_GET['date_to']   ?? '') ?: $end_default);
$em  = trim($_GET['method'] ?? '');            // payment/expense method
$acc = (int)($_GET['account_id'] ?? 0);        // expense account filter
$cat = (int)($_GET['category_id'] ?? 0);       // expense category filter

$g = in_array($g, ['month','day'], true) ? $g : 'month';
if ($df > $dt) { $t=$df; $df=$dt; $dt=$t; }    // swap if reversed

/* ---------- resolve schema ---------- */
// income: payments table
$hasPayments = tbl_exists($pdo,'payments');
$payDateCol  = $hasPayments ? (pick_col_or_null($pdo,'payments',['paid_at','created_at','created','date']) ?? 'paid_at') : null;
$payAmtCol   = $hasPayments ? (pick_col_or_null($pdo,'payments',['amount','total','value']) ?? 'amount') : null;
$paySoftDel  = $hasPayments && col_exists($pdo,'payments','is_deleted');

// expense: expenses table
$hasExpenses = tbl_exists($pdo,'expenses');
$expDateCol  = $hasExpenses ? (pick_col_or_null($pdo,'expenses',['paid_at','expense_date','date','created_at']) ?? 'paid_at') : null;
$expAmtCol   = $hasExpenses ? (pick_col_or_null($pdo,'expenses',['amount','total','value']) ?? 'amount') : null;
$expMethCol  = $hasExpenses ? pick_col_or_null($pdo,'expenses',['method','payment_method']) : null;
$expAccCol   = $hasExpenses && col_exists($pdo,'expenses','account_id')  ? 'account_id'  : null;
$expCatCol   = $hasExpenses && col_exists($pdo,'expenses','category_id') ? 'category_id' : null;
$expSoftDel  = $hasExpenses && col_exists($pdo,'expenses','is_deleted');

// support tables (for dropdowns)
$hasAccTbl   = tbl_exists($pdo,'expense_accounts');
$hasCatTbl   = tbl_exists($pdo,'expense_categories');

/* ---------- fetch dropdown data ---------- */
$accounts   = $hasAccTbl ? $pdo->query("SELECT id,name FROM expense_accounts WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) : [];
$categories = $hasCatTbl ? $pdo->query("SELECT id,name FROM expense_categories WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) : [];

/* ---------- generate time buckets ---------- */
function months_between(string $from, string $to): array {
  $out=[]; $d = DateTime::createFromFormat('Y-m-d',$from)->modify('first day of this month');
  $end= DateTime::createFromFormat('Y-m-d',$to)->modify('first day of next month');
  while($d < $end){ $out[]=$d->format('Y-m'); $d->modify('+1 month'); }
  return $out;
}
function days_between(string $from, string $to): array {
  $out=[]; $d = new DateTime($from); $end = new DateTime($to.' 23:59:59');
  while($d <= $end){ $out[]=$d->format('Y-m-d'); $d->modify('+1 day'); }
  return $out;
}
$buckets = ($g==='month') ? months_between($df,$dt) : days_between($df,$dt);

/* ---------- query: INCOME (payments) ---------- */
$income = array_fill_keys($buckets, 0.0);
if ($hasPayments) {
  $where = ["DATE(p.`$payDateCol`) BETWEEN ? AND ?"];
  $params = [$df,$dt];
  if ($paySoftDel) { $where[] = "COALESCE(p.is_deleted,0)=0"; }
  // method filter (optional, if payments.method column থাকে)
  $payMethCol = pick_col_or_null($pdo,'payments',['method','payment_method','channel','via']);
  if ($em !== '' && $payMethCol) { $where[] = "p.`$payMethCol` = ?"; $params[] = $em; }

  if ($g==='month'){
    $sql = "SELECT DATE_FORMAT(p.`$payDateCol`, '%Y-%m') AS k, COALESCE(SUM(p.`$payAmtCol`),0) AS s
            FROM payments p
            WHERE ".implode(' AND ',$where)."
            GROUP BY k";
  } else {
    $sql = "SELECT DATE(p.`$payDateCol`) AS k, COALESCE(SUM(p.`$payAmtCol`),0) AS s
            FROM payments p
            WHERE ".implode(' AND ',$where)."
            GROUP BY k";
  }
  $st=$pdo->prepare($sql); $st->execute($params);
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $k = $r['k']; if (isset($income[$k])) $income[$k] = (float)$r['s'];
  }
}

/* ---------- query: EXPENSES (expenses) ---------- */
$expense = array_fill_keys($buckets, 0.0);
if ($hasExpenses) {
  $where = ["DATE(e.`$expDateCol`) BETWEEN ? AND ?"];
  $params = [$df,$dt];
  if ($expSoftDel) $where[] = "COALESCE(e.is_deleted,0)=0";
  if ($em !== '' && $expMethCol) { $where[] = "e.`$expMethCol` = ?"; $params[] = $em; }
  if ($acc>0 && $expAccCol) { $where[] = "e.`$expAccCol` = ?"; $params[] = $acc; }
  if ($cat>0 && $expCatCol) { $where[] = "e.`$expCatCol` = ?"; $params[] = $cat; }

  if ($g==='month'){
    $sql = "SELECT DATE_FORMAT(e.`$expDateCol`, '%Y-%m') AS k, COALESCE(SUM(e.`$expAmtCol`),0) AS s
            FROM expenses e
            WHERE ".implode(' AND ',$where)."
            GROUP BY k";
  } else {
    $sql = "SELECT DATE(e.`$expDateCol`) AS k, COALESCE(SUM(e.`$expAmtCol`),0) AS s
            FROM expenses e
            WHERE ".implode(' AND ',$where)."
            GROUP BY k";
  }
  $st=$pdo->prepare($sql); $st->execute($params);
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $k = $r['k']; if (isset($expense[$k])) $expense[$k] = (float)$r['s'];
  }
}

/* ---------- build rows ---------- */
$rows = [];
$totalInc=0.0; $totalExp=0.0;
foreach ($buckets as $k) {
  $inc = round($income[$k] ?? 0, 2);
  $exp = round($expense[$k] ?? 0, 2);
  $rows[] = ['k'=>$k, 'income'=>$inc, 'expense'=>$exp, 'net'=>round($inc-$exp,2)];
  $totalInc += $inc; $totalExp += $exp;
}
$netTotal = round($totalInc - $totalExp, 2);

/* ---------- CSV export ---------- */
if (isset($_GET['export']) && $_GET['export']==='csv') {
  $fn = "income_expense_{$g}_{$df}_to_{$dt}.csv";
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="'.$fn.'"');
  $out = fopen('php://output','w');
  fputcsv($out, ['Period','Income','Expense','Net']);
  foreach ($rows as $r) fputcsv($out, [$r['k'], number_format($r['income'],2,'.',''), number_format($r['expense'],2,'.',''), number_format($r['net'],2,'.','')]);
  fputcsv($out, ['TOTAL', number_format($totalInc,2,'.',''), number_format($totalExp,2,'.',''), number_format($netTotal,2,'.','')]);
  fclose($out);
  exit;
}

/* ---------- UI ---------- */
include __DIR__ . '/../partials/partials_header.php';
?>
<style>
  .stat-card{ border:1px solid #e5e7eb; border-radius:.75rem; background:#fff; }
  .stat-card .hdr{ padding:.65rem .9rem; border-bottom:1px solid #eef1f4; background:#f8f9fa; font-weight:600; }
  .stat-card .bd{ padding:.9rem; }
  .mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
</style>

<div class="container-fluid py-3 text-start">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h5 class="m-0"><i class="bi bi-graph-up"></i> Income vs Expense</h5>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="?<?= http_build_query(array_merge($_GET,['export'=>'csv'])) ?>">
        <i class="bi bi-file-earmark-spreadsheet"></i> Export CSV
      </a>
      <button class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
    </div>
  </div>

  <!-- Filters -->
  <form method="get" class="card p-3 mb-3">
    <div class="row g-2 align-items-end">
      <div class="col-6 col-md-2">
        <label class="form-label">From</label>
        <input type="date" name="date_from" class="form-control form-control-sm" value="<?= h($df) ?>">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">To</label>
        <input type="date" name="date_to" class="form-control form-control-sm" value="<?= h($dt) ?>">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Group</label>
        <select name="g" class="form-select form-select-sm">
          <option value="month" <?= $g==='month'?'selected':'' ?>>Monthly</option>
          <option value="day"   <?= $g==='day'?'selected':'' ?>>Daily</option>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Method</label>
        <select name="method" class="form-select form-select-sm">
          <?php $methods=[''=>'All','Cash'=>'Cash','bKash'=>'bKash','Nagad'=>'Nagad','Bank'=>'Bank','Online'=>'Online']; ?>
          <?php foreach($methods as $k=>$v): ?>
            <option value="<?= h($k) ?>" <?= $em===$k?'selected':'' ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <?php if($hasAccTbl && $expAccCol): ?>
      <div class="col-6 col-md-2">
        <label class="form-label">Expense Account</label>
        <select name="account_id" class="form-select form-select-sm">
          <option value="">All</option>
          <?php foreach($accounts as $a): ?>
            <option value="<?= (int)$a['id'] ?>" <?= $acc==(int)$a['id']?'selected':'' ?>><?= h($a['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <?php if($hasCatTbl && $expCatCol): ?>
      <div class="col-6 col-md-2">
        <label class="form-label">Expense Category</label>
        <select name="category_id" class="form-select form-select-sm">
          <option value="">All</option>
          <?php foreach($categories as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $cat==(int)$c['id']?'selected':'' ?>><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <div class="col-12 col-md-2 text-end">
        <button class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-funnel"></i> Apply</button>
      </div>
    </div>
  </form>

  <!-- KPI cards -->
  <div class="row g-3 mb-3">
    <div class="col-12 col-md-4">
      <div class="stat-card">
        <div class="hdr">Total Income</div>
        <div class="bd fs-4 fw-bold text-success mono"><?= number_format($totalInc,2) ?></div>
      </div>
    </div>
    <div class="col-12 col-md-4">
      <div class="stat-card">
        <div class="hdr">Total Expense</div>
        <div class="bd fs-4 fw-bold text-danger mono"><?= number_format($totalExp,2) ?></div>
      </div>
    </div>
    <div class="col-12 col-md-4">
      <div class="stat-card">
        <div class="hdr">Net (Income - Expense)</div>
        <div class="bd fs-4 fw-bold mono" style="color:<?= $netTotal>=0?'#198754':'#dc3545' ?>">
          <?= number_format($netTotal,2) ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Chart -->
  <div class="card p-3 mb-3">
    <canvas id="ieChart" height="90"></canvas>
  </div>

  <!-- Table -->
  <div class="table-responsive">
    <table class="table table-sm align-middle table-hover">
      <thead class="table-light">
        <tr>
          <th><?= $g==='month'?'Month':'Date' ?></th>
          <th class="text-end">Income</th>
          <th class="text-end">Expense</th>
          <th class="text-end">Net</th>
        </tr>
      </thead>
      <tbody>
      <?php if(!$rows): ?>
        <tr><td colspan="4" class="text-center text-muted py-4">No data</td></tr>
      <?php else: foreach($rows as $r): ?>
        <tr>
          <td class="mono"><?= h($r['k']) ?></td>
          <td class="text-end mono"><?= number_format($r['income'],2) ?></td>
          <td class="text-end mono"><?= number_format($r['expense'],2) ?></td>
          <td class="text-end mono" style="color:<?= $r['net']>=0?'#198754':'#dc3545' ?>"><?= number_format($r['net'],2) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
      <tfoot class="table-light">
        <tr>
          <th>Total</th>
          <th class="text-end mono"><?= number_format($totalInc,2) ?></th>
          <th class="text-end mono"><?= number_format($totalExp,2) ?></th>
          <th class="text-end mono" style="color:<?= $netTotal>=0?'#198754':'#dc3545' ?>"><?= number_format($netTotal,2) ?></th>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<!-- Chart.js (CDN) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function(){
  const labels  = <?= json_encode(array_column($rows,'k')) ?>;
  const income  = <?= json_encode(array_map(fn($r)=>round($r['income'],2), $rows)) ?>;
  const expense = <?= json_encode(array_map(fn($r)=>round($r['expense'],2),$rows)) ?>;
  const ctx = document.getElementById('ieChart').getContext('2d');

  // graceful fallback if CDN blocked
  if (!window.Chart) return;

  new Chart(ctx, {
    type: 'bar',
    data: {
      labels,
      datasets: [
        { label: 'Income',  data: income,  borderWidth: 1 },
        { label: 'Expense', data: expense, borderWidth: 1 }
      ]
    },
    options: {
      responsive: true,
      scales: {
        y: { beginAtZero: true }
      },
      plugins: {
        legend: { position: 'top' },
        title:  { display: true, text: 'Income vs Expense (<?= $g==='month'?'Monthly':'Daily' ?>)' }
      }
    }
  });
})();
</script>

<?php include __DIR__ . '/../partials/partials_footer.php'; ?>
