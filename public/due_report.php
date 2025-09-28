<?php
// /public/due_report.php
// Purpose: Due Report (clients.ledger_balance > 0) + sorting, filters, pagination, export CSV/XLS
// Stack: PHP + PDO + Bootstrap 5; UI English; বাংলা কমেন্ট

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* -------------------- Schema helpers -------------------- */
// (বাংলা) টেবিল/কলাম একবার চেক করে cache
function db_has_column(string $table, string $column): bool {
  static $cache = [];
  if (!isset($cache[$table])) {
    $rows = db()->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
    $cache[$table] = array_flip($rows ?: []);
  }
  return isset($cache[$table][$column]);
}
$has_last_pay = db_has_column('clients','last_payment_date');

/* -------------------- Inputs -------------------- */
$q        = trim($_GET['q'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$limit    = max(10, min(100, (int)($_GET['limit'] ?? 20)));
$offset   = ($page - 1) * $limit;

// Advanced filters
$package   = $_GET['package'] ?? '';
$router    = $_GET['router']  ?? '';
$area      = trim($_GET['area'] ?? '');

// Date range (বাংলা) last_payment_date থাকলে সেটায়, নাহলে join_date
$date_from = trim($_GET['df'] ?? '');
$date_to   = trim($_GET['dt'] ?? '');
$date_col  = $has_last_pay ? 'c.last_payment_date' : 'c.join_date';
$re_date   = '/^\d{4}-\d{2}-\d{2}$/';
if ($date_from && !preg_match($re_date, $date_from)) $date_from = '';
if ($date_to   && !preg_match($re_date, $date_to))   $date_to   = '';

// Export
$export = strtolower(trim($_GET['export'] ?? '')); // '' | 'csv' | 'xls'

/* -------------------- Sorting (whitelist) -------------------- */
// বাংলা: sort column whitelist — ledger default desc
$allowed_sort = [
  'id'          => 'c.id',
  'client_code' => 'c.client_code',
  'name'        => 'c.name',
  'pppoe'       => 'c.pppoe_id',
  'package'     => 'p.name',
  'router'      => 'r.name',
  'area'        => 'c.area',
  'mobile'      => 'c.mobile',
  'ledger'      => 'c.ledger_balance',
  'join_date'   => 'c.join_date',
  'last_pay'    => $has_last_pay ? 'c.last_payment_date' : 'c.join_date',
  'updated'     => 'c.updated_at',
];
$sort_key = $_GET['sort'] ?? 'ledger';
$sort_col = $allowed_sort[$sort_key] ?? $allowed_sort['ledger'];

$dir_raw = strtolower($_GET['dir'] ?? 'desc');     // UI
$dir     = ($dir_raw === 'asc') ? 'ASC' : 'DESC';  // SQL

/* -------------------- Base Query + Filters -------------------- */
$sql_base = "FROM clients c
             LEFT JOIN packages p ON c.package_id = p.id
             LEFT JOIN routers  r ON c.router_id  = r.id
             WHERE c.is_left = 0 AND c.ledger_balance > 0 ";
$params = [];

// search
if ($q !== '') {
  $like = "%$q%";
  $sql_base .= " AND (c.name LIKE ? OR c.pppoe_id LIKE ? OR c.client_code LIKE ? OR c.mobile LIKE ?) ";
  array_push($params, $like, $like, $like, $like);
}

// advanced filters
if ($package !== '' && ctype_digit((string)$package)) {
  $sql_base .= " AND c.package_id = ? ";
  $params[] = (int)$package;
}
if ($router !== '' && ctype_digit((string)$router)) {
  $sql_base .= " AND c.router_id = ? ";
  $params[] = (int)$router;
}
if ($area !== '') {
  $sql_base .= " AND c.area LIKE ? ";
  $params[] = "%$area%";
}
if ($date_from !== '') {
  $sql_base .= " AND DATE($date_col) >= ? ";
  $params[] = $date_from;
}
if ($date_to !== '') {
  $sql_base .= " AND DATE($date_col) <= ? ";
  $params[] = $date_to;
}

/* ==================== EXPORT HANDLER ==================== */
// বাংলা: export=csv/xls হলে LIMIT ছাড়া full result এক্সপোর্ট হবে (current filters+sort)
if ($export === 'csv' || $export === 'xls') {
    $filename_base = 'due_report_'.date('Ymd_His');

    $sql_exp = "SELECT c.id, c.client_code, c.name, c.pppoe_id,
                       p.name AS package_name, r.name AS router_name,
                       c.area, c.mobile, c.join_date, ".
                       ($has_last_pay ? "c.last_payment_date," : "NULL AS last_payment_date,").
                       "c.ledger_balance
                $sql_base
                ORDER BY {$sort_col} {$dir}, c.id DESC";
    $stx = db()->prepare($sql_exp);
    $stx->execute($params);
    $rows_all = $stx->fetchAll(PDO::FETCH_ASSOC);

    if ($export === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$filename_base.'.csv"');
        echo "\xEF\xBB\xBF"; // BOM for Excel+Bangla
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID','Client Code','Name','PPPoE','Package','Router','Area','Mobile','Join Date','Last Payment','Ledger Balance']);
        foreach($rows_all as $r){
            fputcsv($out, [
              $r['id'],
              $r['client_code'],
              $r['name'],
              $r['pppoe_id'],
              $r['package_name'],
              $r['router_name'],
              $r['area'],
              $r['mobile'],
              $r['join_date'],
              $r['last_payment_date'],
              $r['ledger_balance'],
            ]);
        }
        fclose($out);
        exit;
    } else {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$filename_base.'.xls"');
        echo '<meta charset="utf-8">';
        echo '<table border="1" cellspacing="0" cellpadding="4">';
        echo '<tr>';
        $heads = ['ID','Client Code','Name','PPPoE','Package','Router','Area','Mobile','Join Date','Last Payment','Ledger Balance'];
        foreach($heads as $h){ echo '<th>'.h($h).'</th>'; }
        echo '</tr>';
        foreach($rows_all as $r){
            echo '<tr>';
            echo '<td>'.h($r['id']).'</td>';
            echo '<td>'.h($r['client_code']).'</td>';
            echo '<td>'.h($r['name']).'</td>';
            echo '<td>'.h($r['pppoe_id']).'</td>';
            echo '<td>'.h($r['package_name']).'</td>';
            echo '<td>'.h($r['router_name']).'</td>';
            echo '<td>'.h($r['area']).'</td>';
            echo '<td>'.h($r['mobile']).'</td>';
            echo '<td>'.h($r['join_date']).'</td>';
            echo '<td>'.h($r['last_payment_date']).'</td>';
            echo '<td>'.h($r['ledger_balance']).'</td>';
            echo '</tr>';
        }
        echo '</table>';
        exit;
    }
}
/* ================== END EXPORT HANDLER =================== */

/* -------------------- Count + Fetch (paged) -------------------- */
$stc = db()->prepare("SELECT COUNT(*) ".$sql_base);
$stc->execute($params);
$total = (int)$stc->fetchColumn();
$total_pages = max(1, (int)ceil($total / $limit));
if ($page > $total_pages){ $page = $total_pages; $offset = ($page-1)*$limit; }

$sql = "SELECT c.*, p.name AS package_name, r.name AS router_name
        ".$sql_base."
        ORDER BY {$sort_col} {$dir}, c.id DESC
        LIMIT $limit OFFSET $offset";
$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

/* -------------------- Sum (page + overall) -------------------- */
$page_due_sum = 0.0;
foreach ($rows as $r){ $page_due_sum += (float)$r['ledger_balance']; }

// overall sum (respect filters)
$sts = db()->prepare("SELECT COALESCE(SUM(c.ledger_balance),0) ".$sql_base);
$sts->execute($params);
$overall_due_sum = (float)$sts->fetchColumn();

/* -------------------- Dropdown data -------------------- */
$pkgs = db()->query("SELECT id, name FROM packages ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$rtrs = db()->query("SELECT id, name FROM routers  ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$areas_stmt = db()->query("SELECT DISTINCT area FROM clients WHERE area IS NOT NULL AND area<>'' ORDER BY area ASC");
$areas = $areas_stmt->fetchAll(PDO::FETCH_COLUMN);

/* -------------------- Helper: build_sort_link -------------------- */
function build_sort_link($label, $key, $currentSort, $currentDir){
    $params = $_GET;
    unset($params['sort'], $params['dir'], $params['page']);
    $nextDir = 'asc';
    if ($currentSort === $key && strtolower($currentDir) === 'asc') { $nextDir = 'desc'; }
    $params['sort'] = $key; $params['dir'] = $nextDir; $params['page'] = 1;
    $qs = http_build_query($params);

    $icon = ' <i class="bi bi-arrow-down-up"></i>';
    if ($currentSort === $key) {
        $icon = (strtolower($currentDir) === 'asc') ? ' <i class="bi bi-caret-up-fill"></i>' : ' <i class="bi bi-caret-down-fill"></i>';
    }
    return '<a class="text-decoration-none text-nowrap" href="?'.$qs.'" title="Sort by '.$label.'">'.$label.$icon.'</a>';
}

include __DIR__ . '/../partials/partials_header.php';
?>
<style>
  .table-compact>:not(caption)>*>*{ padding:.45rem .6rem; }
  .table-compact th,.table-compact td{ vertical-align:middle; }
  .col-actions{ width:210px; white-space:nowrap; }
  .badge-adv  { background:#e8f5e9; color:#0f5132; }
  .badge-due  { background:#fdecea; color:#842029; }
  .table thead { background:#0d6efd; color:#fff; }
  .summary .num { font-weight:700; }
</style>

<div class="container-fluid p-4">
  <div class="d-flex align-items-center gap-2 flex-wrap mb-3">
    <h4 class="mb-0"><i class="bi bi-coin"></i> Due Report</h4>
    <span class="badge bg-secondary"><?= (int)$total ?></span>
    <div class="ms-auto">
      <span class="me-3 text-muted small">Overall Due: <b>৳ <?= number_format($overall_due_sum,2) ?></b></span>
      <span class="text-muted small">Page Due: <b>৳ <?= number_format($page_due_sum,2) ?></b></span>
    </div>
  </div>

  <!-- Filters -->
  <form class="card shadow-sm mb-3" method="get" action="">
    <div class="card-body">
      <div class="row g-2 align-items-end">
        <!-- keep sort/dir when filtering -->
        <input type="hidden" name="sort" value="<?= h($sort_key) ?>">
        <input type="hidden" name="dir"  value="<?= h($dir_raw) ?>">
        <input type="hidden" name="page" value="1">

        <div class="col-12 col-md-3">
          <label class="form-label">Search</label>
          <input name="q" value="<?= h($q) ?>" class="form-control form-control-sm" placeholder="Name / PPPoE / Client Code / Mobile">
        </div>

        <div class="col-6 col-md-2">
          <label class="form-label">Package</label>
          <select name="package" class="form-select form-select-sm">
            <option value="">All</option>
            <?php foreach($pkgs as $p): ?>
              <option value="<?= (int)$p['id'] ?>" <?= ($package!=='' && (int)$package===(int)$p['id'])?'selected':'' ?>>
                <?= h($p['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-6 col-md-2">
          <label class="form-label">Router</label>
          <select name="router" class="form-select form-select-sm">
            <option value="">All</option>
            <?php foreach($rtrs as $r): ?>
              <option value="<?= (int)$r['id'] ?>" <?= ($router!=='' && (int)$router===(int)$r['id'])?'selected':'' ?>>
                <?= h($r['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-6 col-md-2">
          <label class="form-label">Area</label>
          <input name="area" value="<?= h($area) ?>" list="areas" class="form-control form-control-sm" placeholder="Area">
          <datalist id="areas">
            <?php foreach($areas as $a): ?>
              <option value="<?= h($a) ?>"></option>
            <?php endforeach; ?>
          </datalist>
        </div>

        <div class="col-6 col-md-2">
          <label class="form-label"><?= $has_last_pay ? 'Last Pay From' : 'Join From' ?></label>
          <input type="date" name="df" value="<?= h($date_from) ?>" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label"><?= $has_last_pay ? 'Last Pay To' : 'Join To' ?></label>
          <input type="date" name="dt" value="<?= h($date_to) ?>" class="form-control form-control-sm">
        </div>

        <div class="col-6 col-md-2">
          <label class="form-label">Per Page</label>
          <select name="limit" class="form-select form-select-sm">
            <?php foreach([10,20,30,50,100] as $L): ?>
              <option value="<?= $L ?>" <?= $limit==$L?'selected':'' ?>><?= $L ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-6 col-md-2 d-grid">
          <button class="btn btn-dark btn-sm"><i class="bi bi-funnel"></i> Filter</button>
        </div>

        <!-- Export buttons -->
        <div class="col-6 col-md-2 d-grid">
          <button class="btn btn-success btn-sm" name="export" value="csv" formtarget="_blank">
            <i class="bi bi-filetype-csv"></i> Export CSV
          </button>
        </div>
        <div class="col-6 col-md-2 d-grid">
          <button class="btn btn-primary btn-sm" name="export" value="xls" formtarget="_blank">
            <i class="bi bi-file-earmark-excel"></i> Export Excel
          </button>
        </div>
      </div>
    </div>
  </form>

  <!-- Table -->
  <div class="card shadow-sm">
    <div class="table-responsive">
      <table class="table table-hover table-compact align-middle mb-0">
        <thead>
          <tr>
            <th><?= build_sort_link('#', 'id', $sort_key, $dir_raw); ?></th>
            <th><?= build_sort_link('Client Code', 'client_code', $sort_key, $dir_raw); ?></th>
            <th><?= build_sort_link('Name', 'name', $sort_key, $dir_raw); ?></th>
            <th class="d-none d-md-table-cell"><?= build_sort_link('PPPoE', 'pppoe', $sort_key, $dir_raw); ?></th>
            <th class="d-none d-lg-table-cell"><?= build_sort_link('Package', 'package', $sort_key, $dir_raw); ?></th>
            <th class="d-none d-lg-table-cell"><?= build_sort_link('Router', 'router', $sort_key, $dir_raw); ?></th>
            <th><?= build_sort_link('Area', 'area', $sort_key, $dir_raw); ?></th>
            <th><?= build_sort_link('Mobile', 'mobile', $sort_key, $dir_raw); ?></th>
            <th class="text-end"><?= build_sort_link('Ledger (Due)', 'ledger', $sort_key, $dir_raw); ?></th>
            <th class="d-none d-xl-table-cell"><?= build_sort_link($has_last_pay?'Last Payment':'Join Date', $has_last_pay?'last_pay':'join_date', $sort_key, $dir_raw); ?></th>
            <th class="text-end col-actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($rows): foreach($rows as $r): ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td><code><?= h($r['client_code']) ?></code></td>
            <td>
              <div class="fw-semibold text-truncate" style="max-width:220px;"><?= h($r['name']) ?></div>
              <div class="text-muted small d-md-none">PPPoE: <?= h($r['pppoe_id']) ?></div>
            </td>
            <td class="d-none d-md-table-cell"><?= h($r['pppoe_id']) ?></td>
            <td class="d-none d-lg-table-cell"><?= h($r['package_name'] ?? '—') ?></td>
            <td class="d-none d-lg-table-cell"><?= h($r['router_name'] ?? '—') ?></td>
            <td><?= h($r['area'] ?? '—') ?></td>
            <td><a class="text-decoration-none" href="tel:<?= h($r['mobile']) ?>"><?= h($r['mobile']) ?></a></td>
            <td class="text-end">
              <?php
                $due = (float)$r['ledger_balance'];
                $cls = 'badge-due';
                echo '<span class="badge '.$cls.'">৳ '.number_format($due,2).'</span>';
              ?>
            </td>
            <td class="d-none d-xl-table-cell"><?= h($has_last_pay ? ($r['last_payment_date'] ?? '—') : ($r['join_date'] ?? '—')) ?></td>
            <td class="text-end">
              <div class="btn-group btn-group-sm" role="group">
                <a href="client_view.php?id=<?= (int)$r['id'] ?>" class="btn btn-outline-primary" title="View"><i class="bi bi-eye"></i></a>
                <a href="client_edit.php?id=<?= (int)$r['id'] ?>" class="btn btn-outline-secondary" title="Edit"><i class="bi bi-pencil-square"></i></a>
                <a href="invoices.php?search=<?= urlencode($r['pppoe_id']) ?>" class="btn btn-outline-dark" title="Invoices"><i class="bi bi-receipt"></i></a>
              </div>
            </td>
          </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="11" class="text-center text-muted py-4">No Due Found</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Pagination -->
  <?php if($total_pages>1): ?>
    <nav class="mt-3">
      <ul class="pagination pagination-sm justify-content-center">
        <?php
          $qsPrev = $_GET; $qsPrev['page'] = max(1,$page-1);
          $qsNext = $_GET; $qsNext['page'] = min($total_pages,$page+1);
        ?>
        <li class="page-item <?= $page<=1?'disabled':'' ?>">
          <a class="page-link" href="?<?= h(http_build_query($qsPrev)) ?>">Previous</a>
        </li>
        <?php
          $start = max(1,$page-2); $end=min($total_pages,$page+2);
          if(($end-$start)<4){ $end=min($total_pages,$start+4); $start=max(1,$end-4); }
          for($i=$start;$i<=$end;$i++):
            $qsi = $_GET; $qsi['page']=$i; ?>
            <li class="page-item <?= $i==$page?'active':'' ?>">
              <a class="page-link" href="?<?= h(http_build_query($qsi)) ?>"><?= $i ?></a>
            </li>
        <?php endfor; ?>
        <li class="page-item <?= $page>=$total_pages?'disabled':'' ?>">
          <a class="page-link" href="?<?= h(http_build_query($qsNext)) ?>">Next</a>
        </li>
      </ul>
    </nav>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../partials/partials_footer.php'; ?>
