<?php
// /public/expenses.php — List + Filters + Edit/Delete actions (schema-aware)
// কোড ইংরেজি; কমেন্ট বাংলা।

declare(strict_types=1);
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/acl.php';



function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$pdo = db();

/* -------- helpers -------- */
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

/* -------- resolve columns (schema-aware) -------- */
$dateCol   = pick_col_or_null($pdo,'expenses',['paid_at','expense_date','date','created_at']) ?? 'paid_at';
$amountCol = pick_col_or_null($pdo,'expenses',['amount','total','value']) ?? 'amount';
$methodCol = pick_col_or_null($pdo,'expenses',['method','payment_method']);
$headCol   = pick_col_or_null($pdo,'expenses',['head','title','purpose','category']);
$refCol    = pick_col_or_null($pdo,'expenses',['ref_no','reference','voucher_no','ref']);
$noteCol   = pick_col_or_null($pdo,'expenses',['note','notes','remarks','description']);
$accCol    = col_exists($pdo,'expenses','account_id')  ? 'account_id'  : null;
$catCol    = col_exists($pdo,'expenses','category_id') ? 'category_id' : null;

$hasSoftDel = col_exists($pdo,'expenses','is_deleted');

// supporting tables available?
$hasAcc = tbl_exists($pdo,'expense_accounts');
$hasCat = tbl_exists($pdo,'expense_categories');

/* -------- filters -------- */
$df = trim($_GET['date_from'] ?? '');
$dt = trim($_GET['date_to']   ?? '');
$acc = (int)($_GET['account_id'] ?? 0);
$cat = (int)($_GET['category_id'] ?? 0);
$method = trim($_GET['method'] ?? '');
$q = trim($_GET['q'] ?? '');

$sort = $_GET['sort'] ?? $dateCol;
$dir  = strtolower($_GET['dir'] ?? 'desc');
$whitelistSort = [$dateCol,'id',$amountCol];
$sort = in_array($sort,$whitelistSort,true) ? $sort : $dateCol;
$dir  = in_array($dir,['asc','desc'],true) ? $dir : 'desc';

/* -------- base SQL -------- */
$select = "e.id, e.`$dateCol` AS dt, e.`$amountCol` AS amount";
if($methodCol) $select .= ", e.`$methodCol` AS method";
if($headCol)   $select .= " , e.`$headCol`   AS head";
if($refCol)    $select .= " , e.`$refCol`    AS ref_no";
if($noteCol)   $select .= " , e.`$noteCol`   AS note";
if($accCol)    $select .= " , e.`$accCol`    AS account_id";
if($catCol)    $select .= " , e.`$catCol`    AS category_id";

$join = "";
if($hasAcc && $accCol) $join .= " LEFT JOIN expense_accounts ea ON ea.id = e.`$accCol`";
if($hasCat && $catCol) $join .= " LEFT JOIN expense_categories ec ON ec.id = e.`$catCol`";

$select .= $hasAcc && $accCol ? ", ea.name AS account_name" : "";
$select .= $hasCat && $catCol ? ", ec.name AS category_name" : "";

/* -------- where -------- */
$where = [];
$params = [];
if ($hasSoftDel) $where[] = "COALESCE(e.is_deleted,0)=0";
if ($df !== ''){ $where[] = "DATE(e.`$dateCol`) >= ?"; $params[] = $df; }
if ($dt !== ''){ $where[] = "DATE(e.`$dateCol`) <= ?"; $params[] = $dt; }
if ($accCol && $acc>0){ $where[] = "e.`$accCol` = ?"; $params[] = $acc; }
if ($catCol && $cat>0){ $where[] = "e.`$catCol` = ?"; $params[] = $cat; }
if ($methodCol && $method!==''){ $where[] = "e.`$methodCol` = ?"; $params[] = $method; }
if ($q!==''){
  $w = [];
  $w[] = "CAST(e.id AS CHAR) LIKE ?";
  $params[] = "%$q%";
  $w[] = "CAST(e.`$amountCol` AS CHAR) LIKE ?";
  $params[] = "%$q%";
  if($headCol){ $w[] = "e.`$headCol` LIKE ?"; $params[] = "%$q%"; }
  if($refCol){  $w[] = "e.`$refCol`  LIKE ?"; $params[] = "%$q%"; }
  if($noteCol){ $w[] = "e.`$noteCol` LIKE ?"; $params[] = "%$q%"; }
  if($hasAcc && $accCol){ $w[] = "ea.name LIKE ?"; $params[] = "%$q%"; }
  if($hasCat && $catCol){ $w[] = "ec.name LIKE ?"; $params[] = "%$q%"; }
  $where[] = "(".implode(" OR ", $w).")";
}
$whereSql = $where ? ("WHERE ".implode(" AND ", $where)) : "";

/* -------- fetch dropdown data -------- */
$accounts = $hasAcc ? $pdo->query("SELECT id,name FROM expense_accounts WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) : [];
$categories = $hasCat ? $pdo->query("SELECT id,name FROM expense_categories WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) : [];

/* -------- totals (filtered) -------- */
$sumSql = "SELECT COALESCE(SUM(e.`$amountCol`),0) FROM expenses e $join $whereSql";
$stSum = $pdo->prepare($sumSql); $stSum->execute($params);
$totalFiltered = (float)$stSum->fetchColumn();

/* -------- rows (limit) -------- */
$limit = (int)($_GET['limit'] ?? 100);
if($limit<=0 || $limit>1000) $limit=100;

$sql = "SELECT $select FROM expenses e $join $whereSql ORDER BY e.`$sort` $dir LIMIT $limit";
$st = $pdo->prepare($sql); $st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

/* -------- header/footer -------- */
include __DIR__ . '/../partials/partials_header.php';
?>
<div class="container-fluid py-3 text-start">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h5 class="m-0"><i class="bi bi-list-ul"></i> Expenses</h5>
    <div class="d-flex gap-2">
      <?php if (show_if_can('expense.add')): ?>
      <a href="/public/expense_add.php" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-circle"></i> Add Expense
      </a>
      <?php endif; ?>
      <?php if (acl_is_viewer()): ?>
        <span class="badge bg-secondary">Read-only</span>
      <?php endif; ?>
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
      <?php if($hasAcc && $accCol): ?>
      <div class="col-12 col-md-2">
        <label class="form-label">Account</label>
        <select name="account_id" class="form-select form-select-sm">
          <option value="">All</option>
          <?php foreach($accounts as $a): ?>
            <option value="<?= (int)$a['id'] ?>" <?= $acc==(int)$a['id']?'selected':'' ?>>
              <?= h($a['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <?php if($hasCat && $catCol): ?>
      <div class="col-12 col-md-2">
        <label class="form-label">Category</label>
        <select name="category_id" class="form-select form-select-sm">
          <option value="">All</option>
          <?php foreach($categories as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $cat==(int)$c['id']?'selected':'' ?>>
              <?= h($c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <?php if($methodCol): ?>
      <div class="col-12 col-md-2">
        <label class="form-label">Method</label>
        <select name="method" class="form-select form-select-sm">
          <?php $methods=[''=>'All','Cash'=>'Cash','bKash'=>'bKash','Nagad'=>'Nagad','Bank'=>'Bank','Online'=>'Online']; ?>
          <?php foreach($methods as $k=>$v): ?>
            <option value="<?= h($k) ?>" <?= $method===$k?'selected':'' ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="col-12 col-md-2">
        <label class="form-label">Search</label>
        <input type="text" name="q" class="form-control form-control-sm" placeholder="id, amount, note..." value="<?= h($q) ?>">
      </div>
      <div class="col-6 col-md-1">
        <label class="form-label">Sort</label>
        <select name="sort" class="form-select form-select-sm">
          <option value="<?= h($dateCol) ?>" <?= $sort===$dateCol?'selected':'' ?>>Date</option>
          <option value="id" <?= $sort==='id'?'selected':'' ?>>ID</option>
          <option value="<?= h($amountCol) ?>" <?= $sort===$amountCol?'selected':'' ?>>Amount</option>
        </select>
      </div>
      <div class="col-6 col-md-1">
        <label class="form-label">Dir</label>
        <select name="dir" class="form-select form-select-sm">
          <option value="desc" <?= $dir==='desc'?'selected':'' ?>>DESC</option>
          <option value="asc"  <?= $dir==='asc'?'selected':''  ?>>ASC</option>
        </select>
      </div>
      <div class="col-6 col-md-1">
        <label class="form-label">Limit</label>
        <input type="number" min="10" max="1000" name="limit" class="form-control form-control-sm" value="<?= (int)$limit ?>">
      </div>
      <div class="col-6 col-md-2 text-end">
        <button class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-funnel"></i> Apply</button>
      </div>
    </div>
  </form>

  <div class="d-flex justify-content-between align-items-center mb-2">
    <div class="text-muted small">
      Showing <strong><?= count($rows) ?></strong> rows,
      Total (filtered): <strong><?= number_format($totalFiltered,2) ?></strong>
    </div>
    <!-- future: export buttons -->
  </div>

  <div class="table-responsive">
    <table class="table table-sm align-middle table-hover">
      <thead class="table-light">
        <tr>
          <th>ID</th>
          <th>Date</th>
          <?php if($hasAcc && $accCol): ?><th>Account</th><?php endif; ?>
          <?php if($hasCat && $catCol): ?><th>Category</th><?php endif; ?>
          <?php if($methodCol): ?><th>Method</th><?php endif; ?>
          <th class="text-end">Amount</th>
          <?php if($headCol): ?><th>Head</th><?php endif; ?>
          <?php if($refCol): ?><th>Ref</th><?php endif; ?>
          <?php if($noteCol): ?><th>Note</th><?php endif; ?>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if(!$rows): ?>
          <tr><td colspan="10" class="text-center text-muted py-4">No records</td></tr>
        <?php else: foreach($rows as $r): ?>
          <tr>
            <td class="mono"><?= (int)$r['id'] ?></td>
            <td class="mono"><?= h(substr((string)$r['dt'],0,16)) ?></td>
            <?php if($hasAcc && $accCol): ?>
              <td><?= h($r['account_name'] ?? '') ?></td>
            <?php endif; ?>
            <?php if($hasCat && $catCol): ?>
              <td><?= h($r['category_name'] ?? '') ?></td>
            <?php endif; ?>
            <?php if($methodCol): ?>
              <td><?= h($r['method'] ?? '') ?></td>
            <?php endif; ?>
            <td class="text-end fw-semibold"><?= number_format((float)$r['amount'],2) ?></td>
            <?php if($headCol): ?><td><?= h($r['head'] ?? '') ?></td><?php endif; ?>
            <?php if($refCol):  ?><td class="mono"><?= h($r['ref_no'] ?? '') ?></td><?php endif; ?>
            <?php if($noteCol): ?><td><?= h($r['note'] ?? '') ?></td><?php endif; ?>

            <td class="text-end">
              <?php if (show_if_can('expense.edit')): ?>
                <a href="/public/expense_edit.php?id=<?= (int)$r['id'] ?>"
                   class="btn btn-outline-primary btn-sm" title="Edit">
                  <i class="bi bi-pencil-square"></i>
                </a>
              <?php endif; ?>
              <?php if (show_if_can('expense.delete')): ?>
                <button type="button" class="btn btn-outline-danger btn-sm btn-exp-del"
                        data-id="<?= (int)$r['id'] ?>" title="Delete">
                  <i class="bi bi-trash"></i>
                </button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
document.addEventListener('click', async (e)=>{
  const b = e.target.closest('.btn-exp-del');
  if(!b) return;

  if(!confirm('Delete this expense? (soft-delete)')) return;
  b.disabled = true;

  try{
    const res  = await fetch('/public/expense_delete.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ id: b.dataset.id })
    });

    const text = await res.text();
    let j = null;
    try { j = JSON.parse(text); } catch(_){}

    if (!res.ok) {
      // 403 হলে পারমিশন নেই; নয়তো 404/500 show করি
      const msg = (j && j.error) ? j.error
                : ('HTTP '+res.status+' '+res.statusText+'\n'+text.slice(0,180));
      alert(msg || 'Request failed');
      b.disabled = false;
      return;
    }

    if (j && j.ok) {
      location.reload();
    } else {
      alert((j && j.error) ? j.error : ('Failed:\n'+text.slice(0,180)));
      b.disabled = false;
    }
  } catch(err){
    alert(err?.message || 'Request failed');
    b.disabled = false;
  }
});
</script>


<?php include __DIR__ . '/../partials/partials_footer.php'; ?>
