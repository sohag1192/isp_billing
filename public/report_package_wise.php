<?php
// /public/report_package_wise.php
// (বাংলা) Package-wise report: counts, expected monthly, due, optional invoice-month summary.

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------- Helpers ---------- */
function hcol(PDO $pdo, string $tbl, string $col): bool {
  $st = $pdo->prepare("SHOW COLUMNS FROM `$tbl` LIKE ?");
  $st->execute([$col]);
  return (bool)$st->fetchColumn();
}
function ym_ok(?string $m): bool { return (bool)($m && preg_match('/^\d{4}-\d{2}$/',$m)); }
function ym_range(string $ym): array { $s=$ym.'-01'; return [$s,date('Y-m-t', strtotime($s))]; }

/* ---------- Inputs ---------- */
$search       = trim($_GET['search'] ?? '');
$router_id    = (int)($_GET['router_id'] ?? 0);
$area         = trim($_GET['area'] ?? '');
$status       = strtolower($_GET['status'] ?? '');
$include_left = (int)($_GET['include_left'] ?? 0);
$month_ym     = trim($_GET['month'] ?? '');

$sort = strtolower($_GET['sort'] ?? 'name'); // name|clients|active|online|expected|due|price|id|inv_total|inv_paid|inv_unpaid
$dir  = strtolower($_GET['dir']  ?? 'asc');  // asc|desc
$dir  = in_array($dir, ['asc','desc'], true) ? $dir : 'asc';

/* ---------- Lookups ---------- */
$routers = $pdo->query("SELECT id,name FROM routers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$areas   = $pdo->query("SELECT DISTINCT area FROM clients WHERE area IS NOT NULL AND area<>'' ORDER BY area ASC")->fetchAll(PDO::FETCH_COLUMN);

/* ---------- Schema flags ---------- */
$has_left   = hcol($pdo,'clients','is_left');
$has_online = hcol($pdo,'clients','is_online');
$has_ledger = hcol($pdo,'clients','ledger_balance');
$has_status = hcol($pdo,'clients','status');

$inv_has_status        = hcol($pdo,'invoices','status');
$inv_has_is_void       = hcol($pdo,'invoices','is_void');
$inv_has_billing_month = hcol($pdo,'invoices','billing_month');
$inv_has_invoice_date  = hcol($pdo,'invoices','invoice_date');
$inv_has_created       = hcol($pdo,'invoices','created_at');

$inv_amount_col = null;
foreach (['total','payable','amount'] as $c) { if (hcol($pdo,'invoices',$c)) { $inv_amount_col = $c; break; } }

/* ---------- Filters (WHERE) ---------- */
$amtExpr = "COALESCE(NULLIF(c.monthly_bill,0), p.price, 0)";
$where = "1=1";
$params = [];
if (!$include_left && $has_left) { $where .= " AND COALESCE(c.is_left,0)=0"; }
if ($status && $has_status && in_array($status,['active','inactive'],true)) { $where .= " AND c.status = ?"; $params[] = $status; }
if ($router_id > 0) { $where .= " AND c.router_id = ?"; $params[] = $router_id; }
if ($area !== '')   { $where .= " AND c.area = ?";      $params[] = $area; }
if ($search !== '') { $where .= " AND p.name LIKE ?";   $params[] = "%$search%"; }

/* ---------- Base aggregation (by package) ---------- */
$sql = "
  SELECT
    p.id,
    p.name,
    p.price,
    COUNT(c.id) AS total_clients,
    SUM(CASE WHEN ".($has_left?"COALESCE(c.is_left,0)=0":"1=1")." THEN 1 ELSE 0 END) AS current_clients,
    SUM(CASE WHEN ".($has_status?"c.status='active'":"0")." AND ".($has_left?"COALESCE(c.is_left,0)=0":"1=1")." THEN 1 ELSE 0 END) AS active_clients,
    SUM(CASE WHEN ".($has_online?"c.is_online=1":"0")."  AND ".($has_left?"COALESCE(c.is_left,0)=0":"1=1")." THEN 1 ELSE 0 END) AS online_clients,
    SUM(CASE WHEN ".($has_left?"COALESCE(c.is_left,0)=0":"1=1")." THEN $amtExpr ELSE 0 END) AS expected_monthly,
    ".($has_ledger ? "SUM(GREATEST(COALESCE(c.ledger_balance,0),0))" : "0")." AS due_total
  FROM packages p
  LEFT JOIN clients c ON c.package_id = p.id
  WHERE $where
  GROUP BY p.id, p.name, p.price
";
$st = $pdo->prepare($sql);
$st->execute($params);
$data = $st->fetchAll(PDO::FETCH_ASSOC);

/* ---------- Optional: invoice summary for a month ---------- */
$invSummary = [];
if ($inv_amount_col && ym_ok($month_ym)) {
  [$mStart,$mEnd] = ym_range($month_ym);
  $invDateExpr = $inv_has_billing_month ? "DATE(i.billing_month)" : ($inv_has_invoice_date ? "DATE(i.invoice_date)" : ($inv_has_created ? "DATE(i.created_at)" : null));
  if ($invDateExpr) {
    $whereInv = " $invDateExpr BETWEEN ? AND ? ";
    if ($inv_has_is_void)  $whereInv .= " AND COALESCE(i.is_void,0)=0";
    $sqlInv = "
      SELECT c.package_id,
             COUNT(i.id) AS inv_count,
             SUM(i.$inv_amount_col) AS inv_total,
             ".($inv_has_status ? "SUM(CASE WHEN i.status='paid' THEN i.$inv_amount_col ELSE 0 END)" : "0")." AS inv_paid,
             ".($inv_has_status ? "SUM(CASE WHEN i.status IN ('unpaid','partial') THEN i.$inv_amount_col ELSE 0 END)" : "0")." AS inv_unpaid
      FROM invoices i
      JOIN clients c ON c.id = i.client_id
      WHERE $whereInv
      GROUP BY c.package_id
    ";
    $sti = $pdo->prepare($sqlInv);
    $sti->execute([$mStart,$mEnd]);
    foreach ($sti->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $invSummary[(int)$r['package_id']] = [
        'inv_count'  => (int)$r['inv_count'],
        'inv_total'  => (float)$r['inv_total'],
        'inv_paid'   => (float)$r['inv_paid'],
        'inv_unpaid' => (float)$r['inv_unpaid'],
      ];
    }
  }
}

/* ---------- Sorting (PHP) ---------- */
function cmp_rows(array $a, array $b, string $key, string $dir): int {
  $av = $a[$key] ?? 0; $bv = $b[$key] ?? 0;
  if ($av == $bv) return 0;
  $res = ($av < $bv) ? -1 : 1;
  return $dir==='asc' ? $res : -$res;
}
foreach ($data as &$row) {
  $pid = (int)$row['id'];
  $row['inv_total']  = $invSummary[$pid]['inv_total']  ?? 0.0;
  $row['inv_paid']   = $invSummary[$pid]['inv_paid']   ?? 0.0;
  $row['inv_unpaid'] = $invSummary[$pid]['inv_unpaid'] ?? 0.0;
}
unset($row);

$sortKey = [
  'name'=>'name','price'=>'price','id'=>'id',
  'clients'=>'current_clients','active'=>'active_clients','online'=>'online_clients',
  'expected'=>'expected_monthly','due'=>'due_total',
  'inv_total'=>'inv_total','inv_paid'=>'inv_paid','inv_unpaid'=>'inv_unpaid',
][$sort] ?? 'name';
usort($data, fn($a,$b)=> cmp_rows($a,$b,$sortKey,$dir));

/* ---------- Sort link helper ---------- */
function sort_link($key, $label, $sort, $dir){
  $qs = $_GET; $next = ($sort===$key && $dir==='asc')?'desc':'asc';
  $qs['sort']=$key; $qs['dir']=$next;
  $href = '?' . http_build_query($qs);
  $icon = ' <i class="bi bi-arrow-down-up"></i>';
  if ($sort===$key) $icon = ($dir==='asc') ? ' <i class="bi bi-caret-up-fill"></i>' : ' <i class="bi bi-caret-down-fill"></i>';
  return '<a class="text-decoration-none" href="'.$href.'">'.$label.$icon.'</a>';
}

$export_qs = http_build_query([
  'search'=>$search,'router_id'=>$router_id,'area'=>$area,'status'=>$status,
  'include_left'=>$include_left,'month'=>$month_ym,'sort'=>$sort,'dir'=>$dir
]);

include __DIR__ . '/../partials/partials_header.php';
?>
<style>
.table-container{background:#fff;padding:15px;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.1);}
</style>

<div class="container-fluid py-3">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
    <div>
      <h5 class="mb-0">Package-wise Report</h5>
      <div class="text-muted small">
        Rows: <?= count($data) ?><?php if (ym_ok($month_ym)): ?> • Invoice month: <code><?= htmlspecialchars($month_ym) ?></code><?php endif; ?>
      </div>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-2">
      <a href="/public/report_package_wise_export.php?<?= $export_qs ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-filetype-csv"></i> Export CSV
      </a>
    </div>
  </div>

  <!-- Filters -->
  <form class="card border-0 shadow-sm mt-3">
    <div class="card-body">
      <div class="row g-2 align-items-end">
        <div class="col-sm-3">
          <label class="form-label mb-1">Search (Package)</label>
          <input type="text" name="search" class="form-control form-control-sm" value="<?= htmlspecialchars($search) ?>" placeholder="Package name...">
        </div>
        <div class="col-sm-2">
          <label class="form-label mb-1">Router</label>
          <select name="router_id" class="form-select form-select-sm">
            <option value="0">All routers</option>
            <?php foreach($routers as $rt): ?>
              <option value="<?= (int)$rt['id'] ?>" <?= $router_id==(int)$rt['id']?'selected':'' ?>><?= htmlspecialchars($rt['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-2">
          <label class="form-label mb-1">Area</label>
          <select name="area" class="form-select form-select-sm">
            <option value="">All areas</option>
            <?php foreach($areas as $ar): ?>
              <option value="<?= htmlspecialchars($ar) ?>" <?= $area===$ar?'selected':'' ?>><?= htmlspecialchars($ar) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-2">
          <label class="form-label mb-1">Status</label>
          <select name="status" class="form-select form-select-sm">
            <option value="" <?= $status===''?'selected':'' ?>>All</option>
            <option value="active" <?= $status==='active'?'selected':'' ?>>Active</option>
            <option value="inactive" <?= $status==='inactive'?'selected':'' ?>>Inactive</option>
          </select>
        </div>
        <div class="col-sm-2">
          <label class="form-label mb-1">Invoice Month (optional)</label>
          <input type="month" name="month" value="<?= htmlspecialchars($month_ym) ?>" class="form-control form-control-sm">
        </div>
        <div class="col-sm-1">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="inc-left" name="include_left" value="1" <?= $include_left?'checked':'' ?>>
            <label for="inc-left" class="form-check-label">Include left</label>
          </div>
        </div>
        <div class="col-sm-12 col-md-auto">
          <button class="btn btn-primary btn-sm"><i class="bi bi-sliders"></i> Apply</button>
          <a class="btn btn-outline-secondary btn-sm" href="?"><i class="bi bi-x-circle"></i> Reset</a>
        </div>
      </div>
    </div>
  </form>

  <!-- Table -->
  <div class="table-responsive mt-3 table-container">
    <table class="table table-sm table-hover align-middle">
      <thead class="table-primary">
        <tr>
          <th style="width:80px"><?= sort_link('id','ID',$sort,$dir) ?></th>
          <th><?= sort_link('name','Package',$sort,$dir) ?></th>
          <th class="text-end" style="width:120px"><?= sort_link('price','Price',$sort,$dir) ?></th>
          <th class="text-end" style="width:120px"><?= sort_link('clients','Clients',$sort,$dir) ?></th>
          <th class="text-end" style="width:120px"><?= sort_link('active','Active',$sort,$dir) ?></th>
          <th class="text-end" style="width:120px"><?= sort_link('online','Online',$sort,$dir) ?></th>
          <th class="text-end" style="width:160px"><?= sort_link('expected','Expected Monthly',$sort,$dir) ?></th>
          <th class="text-end" style="width:160px"><?= sort_link('due','Due (Ledger > 0)',$sort,$dir) ?></th>
          <?php if (ym_ok($month_ym) && $inv_amount_col): ?>
            <th class="text-end" style="width:160px"><?= sort_link('inv_total','Inv Total',$sort,$dir) ?></th>
            <th class="text-end" style="width:160px"><?= sort_link('inv_paid','Inv Paid',$sort,$dir) ?></th>
            <th class="text-end" style="width:160px"><?= sort_link('inv_unpaid','Inv Unpaid',$sort,$dir) ?></th>
          <?php endif; ?>
          <th class="text-end" style="width:120px">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$data): ?>
          <tr><td colspan="<?= ym_ok($month_ym)&&$inv_amount_col? 12 : 9 ?>" class="text-center text-muted">No data</td></tr>
        <?php else: foreach($data as $r): ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td><?= htmlspecialchars($r['name']) ?></td>
            <td class="text-end"><?= number_format((float)$r['price'],2) ?></td>
            <td class="text-end">
              <?php if ((int)$r['current_clients']>0): ?>
                <a href="/public/clients.php?package_id=<?= (int)$r['id'] ?>" class="btn btn-outline-secondary btn-sm">
                  <?= (int)$r['current_clients'] ?>
                </a>
              <?php else: ?>
                <span class="text-muted">0</span>
              <?php endif; ?>
            </td>
            <td class="text-end"><?= number_format((int)$r['active_clients']) ?></td>
            <td class="text-end"><?= number_format((int)$r['online_clients']) ?></td>
            <td class="text-end"><?= number_format((float)$r['expected_monthly'],2) ?></td>
            <td class="text-end"><?= number_format((float)$r['due_total'],2) ?></td>
            <?php if (ym_ok($month_ym) && $inv_amount_col): ?>
              <td class="text-end"><?= number_format((float)$r['inv_total'],2) ?></td>
              <td class="text-end"><?= number_format((float)$r['inv_paid'],2) ?></td>
              <td class="text-end"><?= number_format((float)$r['inv_unpaid'],2) ?></td>
            <?php endif; ?>
            <td class="text-end">
              <a class="btn btn-outline-primary btn-sm" href="/public/clients.php?package_id=<?= (int)$r['id'] ?>">View clients</a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
      <?php if ($data): ?>
      <tfoot>
        <?php
          $tot_expected = array_sum(array_map(fn($x)=> (float)$x['expected_monthly'], $data));
          $tot_due      = array_sum(array_map(fn($x)=> (float)$x['due_total'], $data));
          $tot_inv      = array_sum(array_map(fn($x)=> (float)$x['inv_total'], $data));
          $tot_paid     = array_sum(array_map(fn($x)=> (float)$x['inv_paid'], $data));
          $tot_unpaid   = array_sum(array_map(fn($x)=> (float)$x['inv_unpaid'], $data));
        ?>
        <tr class="fw-semibold">
          <td colspan="6" class="text-end">TOTAL</td>
          <td class="text-end"><?= number_format($tot_expected,2) ?></td>
          <td class="text-end"><?= number_format($tot_due,2) ?></td>
          <?php if (ym_ok($month_ym) && $inv_amount_col): ?>
            <td class="text-end"><?= number_format($tot_inv,2) ?></td>
            <td class="text-end"><?= number_format($tot_paid,2) ?></td>
            <td class="text-end"><?= number_format($tot_unpaid,2) ?></td>
          <?php endif; ?>
          <td></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../partials/partials_footer.php'; ?>
