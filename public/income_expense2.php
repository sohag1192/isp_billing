<?php
// /public/income_expense.php
// Income vs Expense — schema-aware (date/method column auto-detect)

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------- helpers: schema detection ---------- */
function table_exists(PDO $pdo, string $table): bool {
  try{
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $q  = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
    $q->execute([$db,$table]);
    return (bool)$q->fetchColumn();
  }catch(Throwable $e){ return false; }
}
function col_exists(PDO $pdo, string $table, string $col): bool {
  try{
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $q  = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
    $q->execute([$db,$table,$col]);
    return (bool)$q->fetchColumn();
  }catch(Throwable $e){ return false; }
}
function pick_col(PDO $pdo, string $table, array $candidates, string $fallback): string {
  foreach($candidates as $c){ if(col_exists($pdo,$table,$c)) return $c; }
  return $fallback;
}

/* ---------- inputs ---------- */
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');
$gran = $_GET['gran'] ?? 'day'; // day|month
$method_inc = trim($_GET['method_inc'] ?? '');
$method_exp = trim($_GET['method_exp'] ?? '');

/* ---------- schema pick ---------- */
$pdo = db();

$hasExpenses = table_exists($pdo,'expenses');

$payTable = 'payments';
$payDate  = pick_col($pdo, $payTable, ['paid_at','payment_date','date','created_at'], 'paid_at');
$payMeth  = pick_col($pdo, $payTable, ['method','payment_method'], 'method');

$expTable = 'expenses';
$expDate  = $hasExpenses ? pick_col($pdo, $expTable, ['paid_at','expense_date','date','created_at'], 'paid_at') : null;
$expMeth  = $hasExpenses ? pick_col($pdo, $expTable, ['method','payment_method'], 'method') : null;

/* নিরাপদ গ্রানুলারিটি */
$gran = ($gran==='month') ? 'month' : 'day';

/* ---------- WHERE builders ---------- */
$wi=[]; $pi=[];
if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)) { $wi[]="DATE(p.`$payDate`) >= ?"; $pi[]=$from; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$to))   { $wi[]="DATE(p.`$payDate`) <= ?"; $pi[]=$to; }
if ($method_inc!==''){ $wi[]="p.`$payMeth` = ?"; $pi[]=$method_inc; }
$whereI = $wi?('WHERE '.implode(' AND ',$wi)):'';

$we=[]; $pe=[];
if ($hasExpenses){
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)) { $we[]="DATE(e.`$expDate`) >= ?"; $pe[]=$from; }
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$to))   { $we[]="DATE(e.`$expDate`) <= ?"; $pe[]=$to; }
  if ($method_exp!==''){ $we[]="e.`$expMeth` = ?"; $pe[]=$method_exp; }
}
$whereE = ($hasExpenses && $we) ? ('WHERE '.implode(' AND ',$we)) : ($hasExpenses ? '' : null);

/* ---------- totals ---------- */
$sti = $pdo->prepare("SELECT COALESCE(SUM(p.amount),0) FROM `$payTable` p $whereI");
$sti->execute($pi);
$income = (float)$sti->fetchColumn();

if ($hasExpenses){
  $ste = $pdo->prepare("SELECT COALESCE(SUM(e.amount),0) FROM `$expTable` e $whereE");
  $ste->execute($pe);
  $expense = (float)$ste->fetchColumn();
} else {
  $expense = 0.0;
}
$net = $income - $expense;

/* ---------- grouping ---------- */
if ($gran==='month'){
  $gi = $pdo->prepare("SELECT DATE_FORMAT(p.`$payDate`,'%Y-%m') as k, SUM(p.amount) s FROM `$payTable` p $whereI GROUP BY DATE_FORMAT(p.`$payDate`,'%Y-%m') ORDER BY k ASC");
} else {
  $gi = $pdo->prepare("SELECT DATE(p.`$payDate`) as k, SUM(p.amount) s FROM `$payTable` p $whereI GROUP BY DATE(p.`$payDate`) ORDER BY k ASC");
}
$gi->execute($pi);
$ginc = $gi->fetchAll(PDO::FETCH_KEY_PAIR);

if ($hasExpenses){
  if ($gran==='month'){
    $ge = $pdo->prepare("SELECT DATE_FORMAT(e.`$expDate`,'%Y-%m') as k, SUM(e.amount) s FROM `$expTable` e $whereE GROUP BY DATE_FORMAT(e.`$expDate`,'%Y-%m') ORDER BY k ASC");
  } else {
    $ge = $pdo->prepare("SELECT DATE(e.`$expDate`) as k, SUM(e.amount) s FROM `$expTable` e $whereE GROUP BY DATE(e.`$expDate`) ORDER BY k ASC");
  }
  $ge->execute($pe);
  $gexp = $ge->fetchAll(PDO::FETCH_KEY_PAIR);
} else {
  $gexp = [];
}

/* merge keys */
$keys = array_values(array_unique(array_merge(array_keys($ginc), array_keys($gexp))));
sort($keys);

/* ---------- view ---------- */
include __DIR__ . '/../partials/partials_header.php';
?>
<div class="container-fluid py-3">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0">Income vs Expense</h4>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-dark btn-sm" target="_blank" rel="noopener"
         href="/public/income_expense_export.php?<?= h(http_build_query($_GET)) ?>">
        <i class="bi bi-download"></i> Export CSV
      </a>
    </div>
  </div>

  <?php if(!$hasExpenses): ?>
    <div class="alert alert-warning">
      <strong>Note:</strong> <code>expenses</code> টেবিল পাওয়া যায়নি—Expense ধরা হয়েছে <strong>0</strong>। চাইলে আগে
      <code>expenses</code> টেবিল তৈরি করে নিন।
    </div>
  <?php endif; ?>

  <!-- Filters -->
  <form class="row g-2 mb-3" method="get">
    <div class="col-6 col-md-2">
      <label class="form-label small text-muted">From</label>
      <input type="date" name="from" value="<?= h($from) ?>" class="form-control form-control-sm">
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small text-muted">To</label>
      <input type="date" name="to" value="<?= h($to) ?>" class="form-control form-control-sm">
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small text-muted">Granularity</label>
      <select name="gran" class="form-select form-select-sm">
        <option value="day"   <?= $gran==='day'?'selected':'' ?>>Daily</option>
        <option value="month" <?= $gran==='month'?'selected':'' ?>>Monthly</option>
      </select>
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label small text-muted">Income Method</label>
      <select name="method_inc" class="form-select form-select-sm">
        <?php foreach(['','Cash','bKash','Nagad','Bank','Online'] as $op): ?>
          <option value="<?= h($op) ?>" <?= $op===$method_inc?'selected':'' ?>><?= $op?:'All' ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label small text-muted">Expense Method</label>
      <select name="method_exp" class="form-select form-select-sm" <?= $hasExpenses?'':'disabled' ?>>
        <?php foreach(['','Cash','bKash','Nagad','Bank','Online'] as $op): ?>
          <option value="<?= h($op) ?>" <?= $op===$method_exp?'selected':'' ?>><?= $op?:'All' ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>

  <!-- Summary -->
  <div class="row g-2 mb-3">
    <div class="col-12 col-md-4">
      <div class="alert alert-success mb-0"><strong>Income:</strong> <?= number_format($income,2) ?></div>
    </div>
    <div class="col-12 col-md-4">
      <div class="alert alert-danger mb-0"><strong>Expense:</strong> <?= number_format($expense,2) ?></div>
    </div>
    <div class="col-12 col-md-4">
      <div class="alert alert-light border mb-0"><strong>Net:</strong> <?= number_format($net,2) ?> <?= $net>=0?'(Profit)':'(Loss)' ?></div>
    </div>
  </div>

  <!-- Detail table -->
  <div class="table-responsive">
    <table class="table table-sm table-striped align-middle">
      <thead class="table-light">
        <tr>
          <th><?= $gran==='month'?'Month':'Date' ?></th>
          <th class="text-end">Income</th>
          <th class="text-end">Expense</th>
          <th class="text-end">Net</th>
        </tr>
      </thead>
      <tbody>
        <?php if(!$keys): ?>
          <tr><td colspan="4" class="text-center text-muted py-4">No data in range</td></tr>
        <?php else: foreach($keys as $k): 
          $inc = (float)($ginc[$k] ?? 0);
          $exp = (float)($gexp[$k] ?? 0);
          $nt  = $inc - $exp;
        ?>
          <tr>
            <td class="mono"><?= h($k) ?></td>
            <td class="text-end"><?= number_format($inc,2) ?></td>
            <td class="text-end"><?= number_format($exp,2) ?></td>
            <td class="text-end <?= $nt>=0?'text-success':'text-danger' ?>"><?= number_format($nt,2) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/../partials/partials_footer.php'; ?>
