<?php
// /public/report_expense_categories.php
// Expense breakdown by category (or head/title when category table absent)

declare(strict_types=1);
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/acl.php';

require_perm('report.expense_categories');

$pdo = db();
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function tbl_exists(PDO $pdo, string $t): bool{
  try{ $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
       $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
       $q->execute([$db,$t]); return (bool)$q->fetchColumn();
  }catch(Throwable $e){ return false; }
}
function col_exists(PDO $pdo, string $t, string $c): bool{
  try{ $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
       $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
       $q->execute([$db,$t,$c]); return (bool)$q->fetchColumn();
  }catch(Throwable $e){ return false; }
}
function pick_col_or_null(PDO $pdo, string $t, array $cands): ?string{
  foreach($cands as $c) if(col_exists($pdo,$t,$c)) return $c;
  return null;
}

/* ---------- inputs ---------- */
$today = date('Y-m-d');
$df = trim($_GET['date_from'] ?? date('Y-m-01', strtotime('-11 months', strtotime($today))));
$dt = trim($_GET['date_to']   ?? $today);
$method = trim($_GET['method'] ?? '');
$account_id = (int)($_GET['account_id'] ?? 0);
$topN   = max(3, min(50, (int)($_GET['top'] ?? 10))); // 3..50

/* ---------- schema detect ---------- */
if (!tbl_exists($pdo,'expenses')) {
  include __DIR__.'/../partials/partials_header.php';
  echo "<div class='container py-5'><div class='alert alert-danger'>`expenses` table not found.</div></div>";
  include __DIR__.'/../partials/partials_footer.php'; exit;
}

$dateCol   = pick_col_or_null($pdo,'expenses',['paid_at','expense_date','date','created_at']) ?? 'paid_at';
$amtCol    = pick_col_or_null($pdo,'expenses',['amount','total','value']) ?? 'amount';
$methCol   = pick_col_or_null($pdo,'expenses',['method','payment_method']);
$accCol    = col_exists($pdo,'expenses','account_id')  ? 'account_id'  : null;
$catIdCol  = col_exists($pdo,'expenses','category_id') ? 'category_id' : null;
$headCol   = pick_col_or_null($pdo,'expenses',['head','title','purpose','category']); // fallback text head
$softDel   = col_exists($pdo,'expenses','is_deleted');

$hasCatTbl = tbl_exists($pdo,'expense_categories');
$catNameCol = $hasCatTbl && col_exists($pdo,'expense_categories','name') ? 'name' : null;

$hasAccTbl = tbl_exists($pdo,'expense_accounts');

/* ---------- dropdown data ---------- */
$accounts = $hasAccTbl ? $pdo->query("SELECT id,name FROM expense_accounts WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) : [];
$methods = ['Cash','bKash','Nagad','Bank','Online'];

/* ---------- where ---------- */
$where = ["DATE(e.`$dateCol`) BETWEEN ? AND ?"];
$params= [$df,$dt];
if ($softDel) $where[] = "COALESCE(e.is_deleted,0)=0";
if ($method!=='' && $methCol){ $where[] = "e.`$methCol` = ?"; $params[] = $method; }
if ($account_id>0 && $accCol){ $where[] = "e.`$accCol` = ?"; $params[] = $account_id; }

/* ---------- grouping expr ---------- */
if ($catIdCol && $hasCatTbl) {
  $join = " LEFT JOIN expense_categories ec ON ec.id = e.`$catIdCol`";
  $label = "COALESCE(NULLIF(ec.`$catNameCol`,''),'(Uncategorized)')";
  $groupExpr = "COALESCE(ec.id,0)";
} elseif ($catIdCol) {
  $join = "";
  $label = "CONCAT('Category#', e.`$catIdCol`)";
  $groupExpr = "COALESCE(e.`$catIdCol`,0)";
} elseif ($headCol) {
  $join = "";
  $label = "COALESCE(NULLIF(e.`$headCol`,''),'(Uncategorized)')";
  $groupExpr = "COALESCE(NULLIF(e.`$headCol`,''),'(Uncategorized)')";
} else {
  $join = "";
  $label = "'(Uncategorized)'";
  $groupExpr = "'(Uncategorized)'";
}

/* ---------- query ---------- */
$sql = "SELECT $label AS cat, COALESCE(SUM(e.`$amtCol`),0) AS total, COUNT(*) AS cnt
        FROM expenses e
        $join
        WHERE ".implode(' AND ', $where)."
        GROUP BY $groupExpr
        ORDER BY total DESC";
$st=$pdo->prepare($sql); $st->execute($params);
$all = $st->fetchAll(PDO::FETCH_ASSOC);

/* top-N + others */
$rows = [];
$sumTop=0.0; $sumAll=0.0; $cntTop=0; $cntAll=0;
foreach($all as $i=>$r){
  $sumAll += (float)$r['total']; $cntAll += (int)$r['cnt'];
  if ($i < $topN){ $rows[]=$r; $sumTop+=(float)$r['total']; $cntTop+=(int)$r['cnt']; }
}
$othersVal = max(0.0, $sumAll - $sumTop);
$othersCnt = max(0, $cntAll - $cntTop);
if ($othersVal > 0) $rows[] = ['cat'=>'Others','total'=>$othersVal,'cnt'=>$othersCnt];

/* ---------- CSV export ---------- */
if (isset($_GET['export']) && $_GET['export']==='csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="expense_categories_'.$df.'_to_'.$dt.'.csv"');
  $out=fopen('php://output','w');
  fputcsv($out,['Category','Count','Amount']);
  foreach($rows as $r) fputcsv($out, [$r['cat'], $r['cnt'], number_format((float)$r['total'],2,'.','')]);
  fputcsv($out,['TOTAL',$cntAll, number_format($sumAll,2,'.','')]);
  fclose($out); exit;
}

/* ---------- UI ---------- */
include __DIR__ . '/../partials/partials_header.php';
?>
<div class="container-fluid py-3 text-start">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h5 class="m-0"><i class="bi bi-diagram-3"></i> Top Expense Categories</h5>
    <div class="d-flex gap-2">
      <a href="?<?= http_build_query(array_merge($_GET,['export'=>'csv'])) ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-file-earmark-spreadsheet"></i> Export CSV
      </a>
      <button class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
    </div>
  </div>

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
        <label class="form-label">Top N</label>
        <input type="number" min="3" max="50" name="top" class="form-control form-control-sm" value="<?= (int)$topN ?>">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Method</label>
        <select name="method" class="form-select form-select-sm">
          <option value="">All</option>
          <?php foreach($methods as $m): ?>
            <option value="<?= h($m) ?>" <?= $method===$m?'selected':'' ?>><?= h($m) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if($accCol && $hasAccTbl): ?>
      <div class="col-6 col-md-2">
        <label class="form-label">Account</label>
        <select name="account_id" class="form-select form-select-sm">
          <option value="">All</option>
          <?php foreach($accounts as $a): ?>
            <option value="<?= (int)$a['id'] ?>" <?= $account_id==(int)$a['id']?'selected':'' ?>><?= h($a['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="col-12 col-md-2 text-end">
        <button class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-funnel"></i> Apply</button>
      </div>
    </div>
  </form>

  <div class="row g-3 mb-3">
    <div class="col-md-4">
      <div class="card"><div class="card-body">
        <div class="text-muted">Categories (top + others)</div>
        <div class="fs-4 fw-bold"><?= count($rows) ?></div>
      </div></div>
    </div>
    <div class="col-md-4">
      <div class="card"><div class="card-body">
        <div class="text-muted">Total Expense</div>
        <div class="fs-4 fw-bold text-danger"><?= number_format($sumAll,2) ?></div>
      </div></div>
    </div>
  </div>

  <div class="card p-3 mb-3">
    <div class="row g-3">
      <div class="col-md-6"><canvas id="catBar" height="110"></canvas></div>
      <div class="col-md-6"><canvas id="catPie" height="110"></canvas></div>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table table-sm align-middle table-hover">
      <thead class="table-light">
        <tr><th>Category</th><th class="text-end">Count</th><th class="text-end">Amount</th></tr>
      </thead>
      <tbody>
        <?php if(!$rows): ?>
          <tr><td colspan="3" class="text-center text-muted py-4">No data</td></tr>
        <?php else: foreach($rows as $r): ?>
          <tr>
            <td><?= h($r['cat']) ?></td>
            <td class="text-end"><?= number_format((int)$r['cnt']) ?></td>
            <td class="text-end"><?= number_format((float)$r['total'],2) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
      <tfoot class="table-light">
        <tr>
          <th>Total</th>
          <th class="text-end"><?= number_format($cntAll) ?></th>
          <th class="text-end"><?= number_format($sumAll,2) ?></th>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function(){
  if(!window.Chart) return;
  const labels = <?= json_encode(array_column($rows,'cat')) ?>;
  const values = <?= json_encode(array_map(fn($r)=>(float)$r['total'],$rows)) ?>;

  new Chart(document.getElementById('catBar').getContext('2d'), {
    type: 'bar',
    data: { labels, datasets: [{ label: 'Amount', data: values, borderWidth: 1 }] },
    options: { responsive:true, scales:{ y:{ beginAtZero:true } } }
  });

  new Chart(document.getElementById('catPie').getContext('2d'), {
    type: 'pie',
    data: { labels, datasets: [{ label: 'Amount', data: values }] },
    options: { responsive:true }
  });
})();
</script>

<?php include __DIR__ . '/../partials/partials_footer.php'; ?>
