<?php
// /public/invoices.php
// Invoices list + sorting + tabs + advanced filters (router/package/area) + export link + Print/PDF actions
// UI English; বাংলা কমেন্ট; schema-aware (billing_month/invoice_date, total/payable/amount, status fallback)

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

// (বাংলা) স্কিমা ডিটেক্টর
function col_exists(PDO $pdo, string $tbl, string $col): bool {
  $st = $pdo->prepare("SHOW COLUMNS FROM `$tbl` LIKE ?");
  $st->execute([$col]);
  return (bool)$st->fetchColumn();
}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$has_inv_number = col_exists($pdo,'invoices','invoice_number');
$has_status     = col_exists($pdo,'invoices','status');
$has_bm         = col_exists($pdo,'invoices','billing_month');
$has_inv_date   = col_exists($pdo,'invoices','invoice_date');
$has_due_date   = col_exists($pdo,'invoices','due_date');
$has_pstart     = col_exists($pdo,'invoices','period_start');
$has_pend       = col_exists($pdo,'invoices','period_end');
$has_created    = col_exists($pdo,'invoices','created_at');

$has_total      = col_exists($pdo,'invoices','total');
$has_payable    = col_exists($pdo,'invoices','payable');
$has_amount_col = col_exists($pdo,'invoices','amount');

// (বাংলা) যে কলাম আছে সেটাই নিয়ে derived total বানাবো
$derived_sql = "COALESCE(".
  ($has_total   ? "i.total,"   : "").
  ($has_payable ? "i.payable," : "").
  ($has_amount_col ? "i.amount," : "").
  "0)";

// ============== Inputs ==============
$search   = trim($_GET['search'] ?? '');
$statusQ  = trim($_GET['status'] ?? ''); // '', unpaid, paid, partial
$month    = trim($_GET['month']  ?? ''); // YYYY-MM
$inv_from = trim($_GET['inv_from'] ?? '');
$inv_to   = trim($_GET['inv_to']   ?? '');
$due_from = trim($_GET['due_from'] ?? '');
$due_to   = trim($_GET['due_to']   ?? '');

$router_id  = $_GET['router']  ?? '';
$package_id = $_GET['package'] ?? '';
$area       = trim($_GET['area'] ?? '');

$re_date = '/^\d{4}-\d{2}-\d{2}$/';
if ($inv_from && !preg_match($re_date, $inv_from)) $inv_from = '';
if ($inv_to   && !preg_match($re_date, $inv_to))   $inv_to   = '';
if ($due_from && !preg_match($re_date, $due_from)) $due_from = '';
if ($due_to   && !preg_match($re_date, $due_to))   $due_to   = '';

$page   = max(1, intval($_GET['page'] ?? 1));
$limit  = max(10, min(100, intval($_GET['limit'] ?? 20))); // Per Page সাপোর্ট
$offset = ($page - 1) * $limit;

// ---- Sorting (?sort=&dir=) ----
$sort   = strtolower($_GET['sort'] ?? 'id');
$dirRaw = strtolower($_GET['dir']  ?? 'desc');
$dirRaw = in_array($dirRaw, ['asc','desc'], true) ? $dirRaw : 'desc';

// (বাংলা) derived_total/amount sort map
$map = [
  'id'      => 'i.id',
  'number'  => $has_inv_number ? 'i.invoice_number' : 'i.id',
  'client'  => 'c.name',
  'start'   => $has_pstart ? 'i.period_start' : 'i.id',
  'end'     => $has_pend   ? 'i.period_end'   : 'i.id',
  'amount'  => "$derived_sql",       // derived
  'total'   => "$derived_sql",       // derived
  'status'  => $has_status ? 'i.status' : 'computed_status',
  'invdate' => $has_inv_date ? 'i.invoice_date' : 'i.id',
  'duedate' => $has_due_date ? 'i.due_date'     : 'i.id',
  'created' => $has_created  ? 'i.created_at'   : 'i.id',
];
if (!isset($map[$sort])) $sort = 'id';
$dirSql = ($dirRaw === 'asc') ? 'ASC' : 'DESC';
$order  = $map[$sort] . ' ' . $dirSql . ', i.id DESC'; // tie-breaker for stable pagination

// ---- Sortable header link helper ----
function sort_link($key, $label, $currentSort, $currentDirRaw){
  $qs = $_GET;
  unset($qs['page']); // page reset on sort
  $nextDir = ($currentSort === $key && $currentDirRaw === 'asc') ? 'desc' : 'asc';
  $qs['sort'] = $key; $qs['dir']  = $nextDir; $qs['page'] = 1;
  $href = '?' . http_build_query($qs);
  if ($currentSort === $key) {
    $arrow = ($currentDirRaw === 'asc') ? ' <i class="bi bi-caret-up-fill"></i>' : ' <i class="bi bi-caret-down-fill"></i>';
  } else {
    $arrow = ' <i class="bi bi-arrow-down-up"></i>';
  }
  return '<a class="text-decoration-none" href="'.$href.'" title="Sort by '.$label.'">'.$label.$arrow.'</a>';
}

// ============== Query Base & Filters (reusable) ==============
// বাংলা: status কলাম না থাকলে computed_status/HAVING দিয়ে ফিল্টার; billing_month থাকলে exact match; না থাকলে invoice_date মাসে fallback
function build_base_sql(PDO $pdo, &$params, $search, $statusQ, $month, $inv_from, $inv_to, $due_from, $due_to, $router_id, $package_id, $area, $has_inv_number, $has_status, $has_bm, $has_inv_date, $has_due_date, $derived_sql){
  $sql =
    " FROM invoices i
      JOIN clients c ON c.id = i.client_id
      LEFT JOIN (
        SELECT 
          COALESCE(pp.invoice_id, pp.bill_id) AS iid,
          COALESCE(SUM(pp.amount - COALESCE(pp.discount,0)),0) AS paid_amount
        FROM payments pp
        GROUP BY COALESCE(pp.invoice_id, pp.bill_id)
      ) pay ON pay.iid = i.id";

  $where = ["1=1"];

  // Search (invoice_number থাকলে তাতে; নাহলে id/name/pppoe)
  if ($search !== '') {
    if ($has_inv_number) {
      $where[] = "(i.invoice_number LIKE ? OR c.name LIKE ? OR c.pppoe_id LIKE ?)";
    } else {
      $where[] = "(CAST(i.id AS CHAR) LIKE ? OR c.name LIKE ? OR c.pppoe_id LIKE ?)";
    }
    $like = "%$search%";
    array_push($params, $like, $like, $like);
  }

  // Billing month filter (FIXED: billing_month হলে exact match)
  if (preg_match('/^\d{4}-\d{2}$/', $month)) {
    $mStart = $month.'-01';
    $mEnd   = date('Y-m-t', strtotime($mStart));
    if ($has_bm) {
      $where[] = "i.billing_month = ?";
      $params[] = $month;
    } elseif ($has_inv_date) {
      $where[] = "DATE(i.invoice_date) BETWEEN ? AND ?";
      array_push($params, $mStart, $mEnd);
    }
  }

  // Invoice date range
  if ($inv_from !== '' && $has_inv_date) { $where[] = "DATE(i.invoice_date) >= ?"; $params[] = $inv_from; }
  if ($inv_to   !== '' && $has_inv_date) { $where[] = "DATE(i.invoice_date) <= ?"; $params[] = $inv_to;   }

  // Due date range
  if ($due_from !== '' && $has_due_date) { $where[] = "DATE(i.due_date) >= ?"; $params[] = $due_from; }
  if ($due_to   !== '' && $has_due_date) { $where[] = "DATE(i.due_date) <= ?"; $params[] = $due_to;   }

  // Router/Package/Area
  if ($router_id !== '' && ctype_digit((string)$router_id))  { $where[] = "c.router_id = ?";  $params[] = (int)$router_id; }
  if ($package_id !== '' && ctype_digit((string)$package_id)){ $where[] = "c.package_id = ?"; $params[] = (int)$package_id; }
  if ($area !== '') { $where[] = "c.area LIKE ?"; $params[] = '%'.$area.'%'; }

  // (বাংলা) Status ফিল্টার
  // যদি invoices.status থাকে → WHERE i.status = ?
  // না থাকলে → HAVING computed_status = ?
  $having = '';
  if ($statusQ !== '') {
    if ($has_status) {
      $where[] = "i.status = ?";
      $params[] = $statusQ;
    } else {
      // computed status:
      // paid_amount >= derived_total → paid
      // paid_amount = 0 → unpaid
      // else → partial
      $having = " HAVING computed_status = ? ";
      $params[] = $statusQ;
    }
  }

  $whereSql = " WHERE ".implode(' AND ', $where);

  // Select list (schema-aware)
  $select =
    "SELECT
      i.*,
      c.name AS client_name,
      c.pppoe_id,
      $derived_sql AS derived_total,
      COALESCE(pay.paid_amount,0) AS paid_amount,
      " .
      ($has_status
        ? "i.status AS computed_status"
        : "CASE
            WHEN COALESCE(pay.paid_amount,0) <= 0 THEN 'unpaid'
            WHEN COALESCE(pay.paid_amount,0) >= ($derived_sql) THEN 'paid'
            ELSE 'partial'
          END AS computed_status"
      );

  // Return full SQL pieces
  return [$select, $sql.$whereSql, $having];
}

// ============== Main Query ==============
$params = [];
list($selectCols, $sql_base, $having_for_status) =
  build_base_sql($pdo, $params, $search, $statusQ, $month, $inv_from, $inv_to, $due_from, $due_to, $router_id, $package_id, $area,
                 $has_inv_number, $has_status, $has_bm, $has_inv_date, $has_due_date, $derived_sql);

/* Count (বাংলা: status HAVING থাকলে wrapper) */
if ($having_for_status) {
  $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM ( $selectCols $sql_base $having_for_status ) X");
} else {
  $stmt_count = $pdo->prepare("SELECT COUNT(*) ".$sql_base);
}
$stmt_count->execute($params);
$total_records = (int)$stmt_count->fetchColumn();
$total_pages   = max(1, (int)ceil($total_records / $limit));
if ($page > $total_pages) { $page = $total_pages; $offset = ($page - 1) * $limit; }

/* Data */
$sql = "$selectCols $sql_base $having_for_status ORDER BY $order LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Page totals (derived_total) */
$page_total = 0.0;
foreach ($rows as $r) { $page_total += (float)$r['derived_total']; }

/* ============== Tab Counters (All/Paid/Unpaid/Partial) ============== */
function count_by_status_tab(PDO $pdo, $statusKey, $search, $month, $inv_from, $inv_to, $due_from, $due_to, $router_id, $package_id, $area,
  $has_inv_number, $has_status, $has_bm, $has_inv_date, $has_due_date, $derived_sql) {

  $ps = [];
  list($sel, $base, $having) =
    build_base_sql($pdo, $ps, $search, $statusKey, $month, $inv_from, $inv_to, $due_from, $due_to, $router_id, $package_id, $area,
                   $has_inv_number, $has_status, $has_bm, $has_inv_date, $has_due_date, $derived_sql);

  if ($having) $q = $pdo->prepare("SELECT COUNT(*) FROM ( $sel $base $having ) Y");
  else         $q = $pdo->prepare("SELECT COUNT(*) ".$base);

  $q->execute($ps);
  return (int)$q->fetchColumn();
}
$cnt_all     = count_by_status_tab($pdo, '',       $search, $month, $inv_from, $inv_to, $due_from, $due_to, $router_id, $package_id, $area,
                                   $has_inv_number,$has_status,$has_bm,$has_inv_date,$has_due_date,$derived_sql);
$cnt_paid    = count_by_status_tab($pdo, 'paid',   $search, $month, $inv_from, $inv_to, $due_from, $due_to, $router_id, $package_id, $area,
                                   $has_inv_number,$has_status,$has_bm,$has_inv_date,$has_due_date,$derived_sql);
$cnt_unpaid  = count_by_status_tab($pdo, 'unpaid', $search, $month, $inv_from, $inv_to, $due_from, $due_to, $router_id, $package_id, $area,
                                   $has_inv_number,$has_status,$has_bm,$has_inv_date,$has_due_date,$derived_sql);
$cnt_partial = count_by_status_tab($pdo, 'partial',$search, $month, $inv_from, $inv_to, $due_from, $due_to, $router_id, $package_id, $area,
                                   $has_inv_number,$has_status,$has_bm,$has_inv_date,$has_due_date,$derived_sql);

// ট্যাব জেনেরেটর
function tab_link($key, $label, $count){
  $qs = $_GET; $qs['status'] = $key; $qs['page']=1;
  $active = ((($_GET['status'] ?? '') === $key)) ? 'active' : '';
  $href = '?' . http_build_query($qs);
  return '<li class="nav-item">
    <a class="nav-link '.$active.'" href="'.$href.'">
      '.$label.' <span class="badge bg-secondary">'.$count.'</span>
    </a>
  </li>';
}

/* ---- Dropdown data for filters ---- */
$pkgs = $pdo->query("SELECT id,name FROM packages ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$rtrs = $pdo->query("SELECT id,name FROM routers  ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$areas_stmt = $pdo->query("SELECT DISTINCT area FROM clients WHERE area IS NOT NULL AND area<>'' ORDER BY area");
$areas = $areas_stmt->fetchAll(PDO::FETCH_COLUMN);

include __DIR__ . '/../partials/partials_header.php';
?>
<style>
.table thead { background:#0d6efd; color:#fff; }
.table-sm td,.table-sm th{ padding:6px 10px; line-height:1.2; vertical-align:middle; font-size:.9rem; }
thead th a{ text-decoration:none; color:inherit; }
thead th a:hover{ text-decoration:underline; }
.badge { font-size:.8rem; }
.card-totals { background:#f8f9fa; border:1px solid #e9ecef; border-radius:10px; }
</style>

<div class="main-content p-3 p-md-4">
  <div class="container-fluid">

    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between mb-3">
      <div>
        <h4 class="mb-1">Invoices</h4>
        <div class="text-muted small">Total: <?= number_format($total_records) ?></div>
      </div>
      <div class="card card-totals p-2">
        <div class="small text-muted">Page Total</div>
        <div class="fw-semibold">৳ <?= number_format($page_total, 2) ?></div>
      </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-3">
      <?= tab_link('',        'All',     $cnt_all) ?>
      <?= tab_link('paid',    'Paid',    $cnt_paid) ?>
      <?= tab_link('unpaid',  'Unpaid',  $cnt_unpaid) ?>
      <?= tab_link('partial', 'Partial', $cnt_partial) ?>
    </ul>

    <!-- Export -->
    <?php
      $export_params = [
        'search'=>$search,'month'=>$month,'status'=>$statusQ,
        'inv_from'=>$inv_from,'inv_to'=>$inv_to,'due_from'=>$due_from,'due_to'=>$due_to,
        'router'=>$router_id,'package'=>$package_id,'area'=>$area,
        'sort'=>$sort,'dir'=>$dirRaw
      ];
    ?>
    <a class="btn btn-outline-secondary btn-sm mb-2"
       href="/public/export_invoices.php?<?= http_build_query($export_params) ?>">
      <i class="bi bi-filetype-csv"></i> Export CSV
    </a>

    <!-- Filters -->
    <form class="card border-0 shadow-sm mb-3" method="GET">
      <div class="card-body">
        <div class="row g-2">
          <div class="col-12 col-md-4">
            <label class="form-label mb-1">Search</label>
            <input type="text" name="search" class="form-control form-control-sm"
                   placeholder="<?= $has_inv_number ? 'Invoice # / Client / PPPoE' : 'Invoice ID / Client / PPPoE' ?>"
                   value="<?= h($search) ?>">
          </div>

          <div class="col-6 col-md-2">
            <label class="form-label mb-1">Status</label>
            <select name="status" class="form-select form-select-sm">
              <option value="" <?= $statusQ===''?'selected':''; ?>>All</option>
              <option value="unpaid"  <?= $statusQ==='unpaid'?'selected':'';  ?>>Unpaid</option>
              <option value="paid"    <?= $statusQ==='paid'?'selected':'';    ?>>Paid</option>
              <option value="partial" <?= $statusQ==='partial'?'selected':''; ?>>Partial</option>
            </select>
          </div>

          <div class="col-6 col-md-2">
            <label class="form-label mb-1">Billing Month</label>
            <input type="month" name="month" class="form-control form-control-sm" value="<?= h($month) ?>">
            <div class="form-text small">
              Filter by <?= $has_bm ? 'billing_month (=YYYY-MM)' : ($has_inv_date?'invoice_date (range)':'(no month field)') ?>
            </div>
          </div>

          <?php if ($has_inv_date): ?>
          <div class="col-6 col-md-2">
            <label class="form-label mb-1">Invoice From</label>
            <input type="date" name="inv_from" value="<?= h($inv_from) ?>" class="form-control form-control-sm">
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label mb-1">Invoice To</label>
            <input type="date" name="inv_to" value="<?= h($inv_to) ?>" class="form-control form-control-sm">
          </div>
          <?php endif; ?>

          <?php if ($has_due_date): ?>
          <div class="col-6 col-md-2">
            <label class="form-label mb-1">Due From</label>
            <input type="date" name="due_from" value="<?= h($due_from) ?>" class="form-control form-control-sm">
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label mb-1">Due To</label>
            <input type="date" name="due_to" value="<?= h($due_to) ?>" class="form-control form-control-sm">
          </div>
          <?php endif; ?>

          <!-- Router/Package/Area -->
          <div class="col-6 col-md-2">
            <label class="form-label mb-1">Router</label>
            <select name="router" class="form-select form-select-sm">
              <option value="">All</option>
              <?php foreach($rtrs as $r): ?>
                <option value="<?= (int)$r['id'] ?>" <?= ($router_id!=='' && (int)$router_id===(int)$r['id'])?'selected':'' ?>>
                  <?= h($r['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-6 col-md-2">
            <label class="form-label mb-1">Package</label>
            <select name="package" class="form-select form-select-sm">
              <option value="">All</option>
              <?php foreach($pkgs as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= ($package_id!=='' && (int)$package_id===(int)$p['id'])?'selected':'' ?>>
                  <?= h($p['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-6 col-md-2">
            <label class="form-label mb-1">Area</label>
            <input name="area" value="<?= h($area) ?>" list="areas" class="form-control form-control-sm" placeholder="Area">
            <datalist id="areas">
              <?php foreach($areas as $a): ?>
                <option value="<?= h($a) ?>"></option>
              <?php endforeach; ?>
            </datalist>
          </div>

          <div class="col-6 col-md-2">
            <label class="form-label mb-1">Per Page</label>
            <select name="limit" class="form-select form-select-sm">
              <?php foreach([10,20,30,50,100] as $L): ?>
                <option value="<?= $L ?>" <?= $limit==$L?'selected':'' ?>><?= $L ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12 col-md-2 d-grid">
            <label class="form-label mb-1 invisible d-none d-md-block">_</label>
            <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-filter"></i> Apply</button>
          </div>
          <div class="col-12 col-md-2 d-grid">
            <label class="form-label mb-1 invisible d-none d-md-block">_</label>
            <?php $reset_qs = http_build_query(['sort'=>$sort,'dir'=>$dirRaw,'limit'=>$limit]); ?>
            <a class="btn btn-outline-secondary btn-sm" href="?<?= $reset_qs ?>">
              <i class="bi bi-x-circle"></i> Reset
            </a>
          </div>
        </div>
      </div>

      <!-- keep sort/dir on filter submit -->
      <input type="hidden" name="sort" value="<?= h($sort) ?>">
      <input type="hidden" name="dir"  value="<?= h($dirRaw) ?>">
      <input type="hidden" name="page" value="1">
    </form>

    <!-- Table -->
    <div class="table-responsive">
      <table class="table table-hover table-striped table-sm align-middle">
        <thead>
          <tr>
            <th style="width:80px;"><?= sort_link('id','ID', $sort, $dirRaw) ?></th>
            <th><?= sort_link('number', $has_inv_number?'Invoice #':'Invoice ID', $sort, $dirRaw) ?></th>
            <th><?= sort_link('client','Client', $sort, $dirRaw) ?></th>
            <th><?= sort_link('start','Start', $sort, $dirRaw) ?></th>
            <th><?= sort_link('end','End', $sort, $dirRaw) ?></th>
            <th class="text-end"><?= sort_link('amount','Amount', $sort, $dirRaw) ?></th>
            <th class="text-end"><?= sort_link('total','Total',  $sort, $dirRaw) ?></th>
            <th><?= sort_link('status','Status', $sort, $dirRaw) ?></th>
            <th><?= sort_link('invdate','Invoice Date', $sort, $dirRaw) ?></th>
            <th><?= sort_link('duedate','Due Date', $sort, $dirRaw) ?></th>
            <th><?= sort_link('created','Created', $sort, $dirRaw) ?></th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($rows): foreach($rows as $r):
              $paid_amt  = (float)($r['paid_amount'] ?? 0);
              $total_row = (float)$r['derived_total'];
              $remaining = max(0, ($total_row - $paid_amt));
              $status_show = $r['computed_status'] ?? ($r['status'] ?? 'unpaid');
        ?>
          <tr>
            <td>#<?= (int)$r['id'] ?></td>
            <td>
              <?php if ($has_inv_number && !empty($r['invoice_number'])): ?>
                <a href="invoice_view.php?id=<?= (int)$r['id'] ?>" class="fw-semibold text-decoration-none">
                  <?= h($r['invoice_number']) ?>
                </a>
              <?php else: ?>
                <a href="invoice_view.php?id=<?= (int)$r['id'] ?>" class="fw-semibold text-decoration-none">
                  ID-<?= (int)$r['id'] ?>
                </a>
              <?php endif; ?>
            </td>
            <td>
              <a href="client_view.php?id=<?= (int)$r['client_id'] ?>" class="text-decoration-none">
                <?= h($r['client_name']) ?>
              </a>
              <div class="text-muted small"><?= h($r['pppoe_id'] ?: '') ?></div>
            </td>
            <td><?= $has_pstart ? h($r['period_start']) : '—' ?></td>
            <td><?= $has_pend   ? h($r['period_end'])   : '—' ?></td>
            <td class="text-end">৳ <?= number_format((float)($r['amount'] ?? $total_row), 2) ?></td>
            <td class="text-end fw-semibold">৳ <?= number_format($total_row, 2) ?></td>
            <td>
              <?php
                $st = strtolower($status_show);
                if ($st==='paid')        echo '<span class="badge bg-success">Paid</span>';
                elseif ($st==='partial') echo '<span class="badge bg-warning text-dark">Partial</span>';
                else                     echo '<span class="badge bg-danger">Unpaid</span>';
              ?>
            </td>
            <td><?= $has_inv_date ? h($r['invoice_date'] ?: '-') : '—' ?></td>
            <td><?= $has_due_date ? h($r['due_date']    ?: '-') : '—' ?></td>
            <td class="text-muted small"><?= $has_created ? h($r['created_at']) : '—' ?></td>
            <td>
              <div class="btn-group btn-group-sm" role="group">
                <a class="btn btn-outline-primary" title="View" href="invoice_view.php?id=<?= (int)$r['id'] ?>">
                  <i class="bi bi-eye"></i>
                </a>
                <a class="btn btn-outline-secondary" title="Client" href="client_view.php?id=<?= (int)$r['client_id'] ?>">
                  <i class="bi bi-person"></i>
                </a>
                <button
                  class="btn btn-outline-success"
                  title="Mark Paid / Add Payment"
                  data-bs-toggle="modal"
                  data-bs-target="#payModal"
                  data-id="<?= (int)$r['id'] ?>"
                  data-client="<?= h($r['client_name']) ?>"
                  data-total="<?= $total_row ?>"
                  data-paid="<?= $paid_amt ?>"
                >
                  <i class="bi bi-cash-coin"></i>
                </button>
                <a class="btn btn-outline-dark" title="Print"
                   href="invoice_print.php?id=<?= (int)$r['id'] ?>" target="_blank">
                  <i class="bi bi-printer"></i>
                </a>
                <a class="btn btn-dark" title="PDF"
                   href="invoice_print.php?id=<?= (int)$r['id'] ?>&pdf=1" target="_blank">
                  <i class="bi bi-file-earmark-arrow-down"></i>
                </a>
              </div>
            </td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="12" class="text-center text-muted">No invoices found.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
      <nav class="mt-3">
        <ul class="pagination justify-content-center pagination-sm">
          <?php
            $qs = $_GET;
            $prev = max(1, $page-1);
            $next = min($total_pages, $page+1);
          ?>
          <li class="page-item <?= $page<=1?'disabled':''; ?>">
            <?php $qs['page']=$prev; ?>
            <a class="page-link" href="?<?= h(http_build_query($qs)) ?>">Previous</a>
          </li>
          <?php
            $start = max(1, $page - 2);
            $end   = min($total_pages, $page + 2);
            if (($end - $start + 1) < 5) {
              if ($start == 1) { $end = min($total_pages, $start + 4); }
              elseif ($end == $total_pages) { $start = max(1, $end - 4); }
            }
            for ($i=$start; $i<=$end; $i++):
              $qs['page']=$i;
          ?>
            <li class="page-item <?= $i==$page?'active':''; ?>">
              <a class="page-link" href="?<?= h(http_build_query($qs)) ?>"><?= $i ?></a>
            </li>
          <?php endfor; ?>
          <li class="page-item <?= $page>=$total_pages?'disabled':''; ?>">
            <?php $qs['page']=$next; ?>
            <a class="page-link" href="?<?= h(http_build_query($qs)) ?>">Next</a>
          </li>
        </ul>
      </nav>
    <?php endif; ?>

  </div>
</div>

<!-- Payment Modal -->
<div class="modal fade" id="payModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="payForm">
        <div class="modal-header">
          <h5 class="modal-title">Add Payment</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="invoice_id" id="pay_invoice_id">
          <div class="mb-2">
            <div class="small text-muted">Client</div>
            <div class="fw-semibold" id="pay_client_name">—</div>
          </div>
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label">Total</label>
              <input type="text" id="pay_total" class="form-control" readonly>
            </div>
            <div class="col-6">
              <label class="form-label">Paid</label>
              <input type="text" id="pay_paid" class="form-control" readonly>
            </div>
            <div class="col-6">
              <label class="form-label">Amount</label>
              <input type="number" step="0.01" name="amount" id="pay_amount" class="form-control" required>
              <div class="form-text">Default: remaining</div>
            </div>
            <div class="col-6">
              <label class="form-label">Method</label>
              <select name="method" class="form-select" id="pay_method">
                <option value="">Select…</option>
                <option>Cash</option>
                <option>BKash</option>
                <option>Nagad</option>
                <option>Bank</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Transaction ID (optional)</label>
              <input type="text" name="txn_id" class="form-control" id="pay_txn">
            </div>
            <div class="col-12">
              <label class="form-label">Paid At</label>
              <input type="datetime-local" name="paid_at" id="pay_paid_at" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label">Remarks</label>
              <input type="text" name="remarks" id="pay_remarks" class="form-control" placeholder="Optional">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Close</button>
          <button class="btn btn-success" type="submit"><i class="bi bi-check2-circle"></i> Save Payment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
<script>
// Payment modal init + submit (JSON-safe: text fallback)
(function(){
  const modal = document.getElementById('payModal');
  const form  = document.getElementById('payForm');

  modal.addEventListener('show.bs.modal', event => {
    const btn = event.relatedTarget;
    const id   = btn.getAttribute('data-id');
    const name = btn.getAttribute('data-client');
    const total= parseFloat(btn.getAttribute('data-total')||'0');
    const paid = parseFloat(btn.getAttribute('data-paid')||'0');
    const remaining = Math.max(0, (total - paid)).toFixed(2);

    document.getElementById('pay_invoice_id').value = id;
    document.getElementById('pay_client_name').textContent = name || '—';
    document.getElementById('pay_total').value = total.toFixed(2);
    document.getElementById('pay_paid').value  = paid.toFixed(2);
    document.getElementById('pay_amount').value = remaining;

    // default now (YYYY-MM-DDTHH:MM)
    const now = new Date();
    const pad = n => String(n).padStart(2,'0');
    const local = now.getFullYear()+'-'+pad(now.getMonth()+1)+'-'+pad(now.getDate())+'T'+pad(now.getHours())+':'+pad(now.getMinutes());
    document.getElementById('pay_paid_at').value = local;

    document.getElementById('pay_method').value = '';
    document.getElementById('pay_txn').value = '';
    document.getElementById('pay_remarks').value = '';
  });

  form.addEventListener('submit', async (e)=>{
    e.preventDefault();
    const fd = new FormData(form);

    const amt = parseFloat(fd.get('amount')||'0');
    if (!(amt > 0)) { alert('Enter a valid amount.'); return; }

    try {
      const res  = await fetch('../public/payment_add.php', { method:'POST', body:fd, credentials:'same-origin' });
      const text = await res.text();   // read as text first (debug-friendly)
      let j;
      try { j = JSON.parse(text); }
      catch(parseErr){
        alert('Failed: invalid_json\\n\\n' + text.slice(0, 500));
        return;
      }
      if (!j.ok) {
        alert('Failed: ' + (j.message || j.error || 'unknown'));
        return;
      }
      location.reload();
    } catch (err) {
      alert('Network error');
    }
  });
})();
</script>

<?php include __DIR__ . '/../partials/partials_footer.php'; ?>
