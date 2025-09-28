<?php
// /public/due_report_pro.php
// Aging / Due Report Pro — buckets: 0–30 / 31–60 / 61–90 / 91+
// UI English; বাংলা কমেন্ট; PDO + procedural; Bootstrap 5

declare(strict_types=1);

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ---- Schema helpers ----
function col_exists(PDO $pdo, string $tbl, string $col): bool {
  $st = $pdo->prepare("SHOW COLUMNS FROM `$tbl` LIKE ?");
  $st->execute([$col]);
  return (bool)$st->fetchColumn();
}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// invoices schema
$has_total    = col_exists($pdo,'invoices','total');
$has_payable  = col_exists($pdo,'invoices','payable');
$has_amount   = col_exists($pdo,'invoices','amount');
$has_status   = col_exists($pdo,'invoices','status');
$has_bm       = col_exists($pdo,'invoices','billing_month'); // DATE
$has_invdate  = col_exists($pdo,'invoices','invoice_date');  // DATE
$has_duedate  = col_exists($pdo,'invoices','due_date');      // DATE
$has_invnum   = col_exists($pdo,'invoices','invoice_number');
$has_created  = col_exists($pdo,'invoices','created_at');

$derived_total_sql = "COALESCE(".
  ($has_total   ? "i.total,"   : "").
  ($has_payable ? "i.payable," : "").
  ($has_amount  ? "i.amount,"  : "").
  "0)";

// ---- Inputs / Filters ----
$search     = trim($_GET['search'] ?? '');           // invoice number / client / PPPoE
$router_id  = $_GET['router']  ?? '';
$package_id = $_GET['package'] ?? '';
$area       = trim($_GET['area'] ?? '');
$min_due    = (float)($_GET['min_due'] ?? 0);        // only show due >= this
$bucket     = trim($_GET['bucket'] ?? '');           // '', 0-30, 31-60, 61-90, 91+
$due_from   = trim($_GET['due_from'] ?? '');         // filter by due_date >=
$due_to     = trim($_GET['due_to']   ?? '');         // filter by due_date <=
$export     = strtolower($_GET['export'] ?? '');     // csv

$re_date = '/^\d{4}-\d{2}-\d{2}$/';
if ($due_from && !preg_match($re_date, $due_from)) $due_from = '';
if ($due_to   && !preg_match($re_date, $due_to))   $due_to   = '';

// pagination
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 50;
$offset = ($page - 1) * $limit;

// ---- Base SQL: join clients + payments aggregate ----
// payments: invoice_id OR bill_id (schema-flex)
// NOTE: c.id AS client_id বাদ; i.*-এর মধ্যেই client_id আছে
$select = "
  SELECT
    i.*,
    c.name AS client_name,
    c.pppoe_id,
    $derived_total_sql AS derived_total,
    COALESCE(pay.pamt,0) AS paid_calc
";
$from = "
  FROM invoices i
  JOIN clients c ON c.id = i.client_id
  LEFT JOIN (
    SELECT COALESCE(pp.invoice_id, pp.bill_id) AS iid,
           COALESCE(SUM(pp.amount - COALESCE(pp.discount,0)),0) AS pamt
    FROM payments pp
    GROUP BY COALESCE(pp.invoice_id, pp.bill_id)
  ) pay ON pay.iid = i.id
";

$where = ["1=1"];
$params = [];

// search
if ($search !== '') {
  if ($has_invnum) {
    $where[] = "(i.invoice_number LIKE ? OR c.name LIKE ? OR c.pppoe_id LIKE ?)";
  } else {
    $where[] = "(CAST(i.id AS CHAR) LIKE ? OR c.name LIKE ? OR c.pppoe_id LIKE ?)";
  }
  $like = '%'.$search.'%';
  array_push($params, $like, $like, $like);
}
// router/package/area
if ($router_id !== '' && ctype_digit((string)$router_id)) { $where[] = "c.router_id = ?"; $params[] = (int)$router_id; }
if ($package_id !== '' && ctype_digit((string)$package_id)) { $where[] = "c.package_id = ?"; $params[] = (int)$package_id; }
if ($area !== '') { $where[] = "c.area LIKE ?"; $params[] = '%'.$area.'%'; }

// due date range
if ($has_duedate) {
  if ($due_from) { $where[] = "i.due_date >= ?"; $params[] = $due_from; }
  if ($due_to)   { $where[] = "i.due_date <= ?"; $params[] = $due_to; }
}

// Only invoices with positive outstanding
$having = " HAVING outstanding > 0 ";
if ($min_due > 0) {
  $having .= " AND outstanding >= ".(float)$min_due." ";
}

// বয়স: prefer due_date; fallback invoice_date; fallback billing_month end; else created_at
$age_expr_sql = "DATEDIFF(CURDATE(),
               COALESCE(".
                 ($has_duedate ? "i.due_date," : "").
                 ($has_invdate ? "DATE(i.invoice_date)," : "").
                 ($has_bm      ? "LAST_DAY(i.billing_month)," : "").
                 ($has_created ? "DATE(i.created_at)," : "").
                 "CURDATE()".
               "))";

// final select with computed fields
$final_select = "
  SELECT * FROM (
    $select,
    ($derived_total_sql - COALESCE(pay.pamt,0)) AS outstanding,
    $age_expr_sql AS age_days
    $from
    WHERE ".implode(' AND ', $where)."
  ) Z
  $having
";

// ---- Count for pagination ----
$stmt_count = $pdo->prepare("SELECT COUNT(*) FROM ( $final_select ) X");
$stmt_count->execute($params);
$total_records = (int)$stmt_count->fetchColumn();
$total_pages   = max(1, (int)ceil($total_records / $limit));

// ---- Fetch page data with order (default: largest due first)
$order = " ORDER BY age_days DESC, outstanding DESC, id DESC ";
$stmt = $pdo->prepare($final_select . $order . " LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ---- Bucketing (0–30, 31–60, 61–90, 91+)
$buckets = [
  '0-30'  => ['min'=>0,'max'=>30,'total'=>0.0,'count'=>0],
  '31-60' => ['min'=>31,'max'=>60,'total'=>0.0,'count'=>0],
  '61-90' => ['min'=>61,'max'=>90,'total'=>0.0,'count'=>0],
  '91+'   => ['min'=>91,'max'=>PHP_INT_MAX,'total'=>0.0,'count'=>0],
];
$grand_total = 0.0;
foreach ($rows as &$r) {
  $r['age_days'] = max(0, (int)$r['age_days']);
  $out = (float)$r['outstanding'];
  $grand_total += $out;

  $age = (int)$r['age_days'];
  if     ($age <= 30) { $bucket_key = '0-30'; }
  elseif ($age <= 60) { $bucket_key = '31-60'; }
  elseif ($age <= 90) { $bucket_key = '61-90'; }
  else                { $bucket_key = '91+'; }
  $r['bucket'] = $bucket_key;
  $buckets[$bucket_key]['total'] += $out;
  $buckets[$bucket_key]['count'] += 1;
}

// ---- Export CSV (entire filtered dataset)
if ($export === 'csv') {
  $stmtAll = $pdo->prepare($final_select . $order);
  $stmtAll->execute($params);
  $all = $stmtAll->fetchAll(PDO::FETCH_ASSOC);

  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="due_report_'.date('Ymd_His').'.csv"');

  $out = fopen('php://output', 'w');
  fputcsv($out, ['InvoiceID','InvoiceNumber','Client','PPPoE','InvoiceDate','DueDate','BillingMonth','Amount','Paid','Outstanding','AgeDays','Bucket']);
  foreach ($all as $r) {
    fputcsv($out, [
      $r['id'],
      $has_invnum ? ($r['invoice_number'] ?? '') : '',
      $r['client_name'],
      $r['pppoe_id'],
      $has_invdate ? ($r['invoice_date'] ?? '') : '',
      $has_duedate ? ($r['due_date'] ?? '') : '',
      $has_bm ? ($r['billing_month'] ?? '') : '',
      number_format((float)$r['derived_total'], 2, '.', ''),
      number_format((float)$r['paid_calc'],     2, '.', ''),  // <- changed
      number_format((float)$r['outstanding'],   2, '.', ''),
      (int)$r['age_days'],
      $r['bucket'] ?? '',
    ]);
  }
  fclose($out);
  exit;
}

// ---- Dropdown data
$pkgs = $pdo->query("SELECT id,name FROM packages ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$rtrs = $pdo->query("SELECT id,name FROM routers  ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$areas_stmt = $pdo->query("SELECT DISTINCT area FROM clients WHERE area IS NOT NULL AND area<>'' ORDER BY area");
$areas = $areas_stmt->fetchAll(PDO::FETCH_COLUMN);

// ---- UI
include __DIR__ . '/../partials/partials_header.php';
?>
<style>
.table thead { background:#0d6efd; color:#fff; }
.table-sm td,.table-sm th{ padding:6px 10px; line-height:1.2; vertical-align:middle; font-size:.9rem; }
thead th a{ text-decoration:none; color:inherit; }
thead th a:hover{ text-decoration:underline; }
.badge { font-size:.8rem; }
.card-totals { background:#f8f9fa; border:1px solid #e9ecef; border-radius:10px; }
.bucket-card .metric { font-size:1.05rem; }
.bucket-card .metric .v { font-weight:700; }
</style>

<div class="main-content p-3 p-md-4">
  <div class="container-fluid">

    <div class="d-flex align-items-center justify-content-between mb-3">
      <div>
        <h4 class="mb-1">Aging / Due Report</h4>
        <div class="text-muted small">Only invoices with positive outstanding included.</div>
      </div>
      <div class="card card-totals p-2">
        <div class="small text-muted">Grand Outstanding (page)</div>
        <div class="fw-semibold">৳ <?= number_format($grand_total, 2) ?></div>
      </div>
    </div>

    <!-- Buckets summary -->
    <div class="row g-2 mb-3">
      <?php foreach (['0-30','31-60','61-90','91+'] as $bk): ?>
      <div class="col-6 col-md-3">
        <div class="card bucket-card h-100">
          <div class="card-body py-2">
            <div class="text-muted small"><?= $bk ?> days</div>
            <div class="metric">
              <span class="v">৳ <?= number_format($buckets[$bk]['total'], 2) ?></span>
              <span class="text-muted small"> (<?= (int)$buckets[$bk]['count'] ?>)</span>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Export -->
    <?php $export_qs = array_merge($_GET, ['export'=>'csv','page'=>1]); ?>
    <a class="btn btn-outline-secondary btn-sm mb-2" href="?<?= http_build_query($export_qs) ?>">
      <i class="bi bi-filetype-csv"></i> Export CSV
    </a>

    <!-- Filters -->
    <form class="card border-0 shadow-sm mb-3" method="GET">
      <div class="card-body">
        <div class="row g-2">
          <div class="col-12 col-md-3">
            <label class="form-label mb-1">Search</label>
            <input type="text" name="search" class="form-control form-control-sm"
                   placeholder="<?= $has_invnum ? 'Invoice # / Client / PPPoE' : 'Invoice ID / Client / PPPoE' ?>"
                   value="<?= h($search) ?>">
          </div>

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
            <label class="form-label mb-1">Min Due</label>
            <input type="number" step="0.01" name="min_due" value="<?= h($min_due) ?>" class="form-control form-control-sm" placeholder="0.00">
          </div>

          <div class="col-6 col-md-1">
            <label class="form-label mb-1">Bucket</label>
            <?php $bopts = ['', '0-30','31-60','61-90','91+']; ?>
            <select name="bucket" class="form-select form-select-sm">
              <?php foreach($bopts as $b): ?>
                <option value="<?= h($b) ?>" <?= $bucket===$b?'selected':''; ?>><?= $b===''?'All':$b ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <?php if ($has_duedate): ?>
          <div class="col-6 col-md-2">
            <label class="form-label mb-1">Due From</label>
            <input type="date" name="due_from" value="<?= h($due_from) ?>" class="form-control form-control-sm">
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label mb-1">Due To</label>
            <input type="date" name="due_to" value="<?= h($due_to) ?>" class="form-control form-control-sm">
          </div>
          <?php endif; ?>

          <div class="col-12 col-md-2 d-grid">
            <label class="form-label mb-1 invisible d-none d-md-block">_</label>
            <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-filter"></i> Apply</button>
          </div>
          <div class="col-12 col-md-2 d-grid">
            <label class="form-label mb-1 invisible d-none d-md-block">_</label>
            <a class="btn btn-outline-secondary btn-sm" href="?"><i class="bi bi-x-circle"></i> Reset</a>
          </div>
        </div>
      </div>
      <input type="hidden" name="page" value="1">
    </form>

    <!-- Table -->
    <div class="table-responsive">
      <table class="table table-hover table-striped table-sm align-middle">
        <thead>
          <tr>
            <th style="width:80px;">ID</th>
            <th><?= $has_invnum ? 'Invoice #' : 'Invoice ID' ?></th>
            <th>Client</th>
            <th>Invoice Date</th>
            <th>Due Date</th>
            <th>Age (days)</th>
            <th>Bucket</th>
            <th class="text-end">Amount</th>
            <th class="text-end">Paid</th>
            <th class="text-end">Outstanding</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $page_out_total = 0.0;
          foreach ($rows as $r):
            $amt  = (float)$r['derived_total'];
            $paid = (float)$r['paid_calc']; // <- changed
            $out  = (float)$r['outstanding'];
            $page_out_total += $out;

            if ($bucket !== '' && $r['bucket'] !== $bucket) continue;
          ?>
          <tr>
            <td>#<?= (int)$r['id'] ?></td>
            <td>
              <a href="invoice_view.php?id=<?= (int)$r['id'] ?>" class="fw-semibold text-decoration-none">
                <?= $has_invnum ? h($r['invoice_number'] ?: '—') : 'ID-'.(int)$r['id'] ?>
              </a>
              <div class="text-muted small"><?= h($r['client_name']) ?> • <?= h($r['pppoe_id'] ?: '') ?></div>
            </td>
            <td><?= $has_invdate ? h($r['invoice_date'] ?: '-') : '—' ?></td>
            <td><?= $has_duedate ? h($r['due_date'] ?: '-') : '—' ?></td>
            <td><?= (int)$r['age_days'] ?></td>
            <td><span class="badge bg-<?= $r['bucket']==='91+'?'danger':($r['bucket']==='61-90'?'warning text-dark':'secondary') ?>"><?= h($r['bucket']) ?></span></td>
            <td class="text-end">৳ <?= number_format($amt, 2) ?></td>
            <td class="text-end">৳ <?= number_format($paid, 2) ?></td>
            <td class="text-end fw-semibold">৳ <?= number_format($out, 2) ?></td>
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
                  title="Add Payment"
                  data-bs-toggle="modal"
                  data-bs-target="#payModal"
                  data-id="<?= (int)$r['id'] ?>"
                  data-client="<?= h($r['client_name']) ?>"
                  data-total="<?= $amt ?>"
                  data-paid="<?= $paid ?>"
                >
                  <i class="bi bi-cash-coin"></i>
                </button>
                <a class="btn btn-outline-dark" title="Print" href="invoice_print.php?id=<?= (int)$r['id'] ?>" target="_blank">
                  <i class="bi bi-printer"></i>
                </a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>

          <?php if (!$rows): ?>
            <tr><td colspan="11" class="text-center text-muted">No due found.</td></tr>
          <?php endif; ?>
        </tbody>
        <tfoot>
          <tr class="table-light">
            <th colspan="9" class="text-end">Page Outstanding</th>
            <th class="text-end">৳ <?= number_format($page_out_total, 2) ?></th>
            <th></th>
          </tr>
        </tfoot>
      </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
      <nav class="mt-3">
        <ul class="pagination justify-content-center">
          <li class="page-item <?= $page<=1?'disabled':''; ?>">
            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page'=>$page-1])) ?>">Previous</a>
          </li>
          <?php
            $start = max(1, $page - 2);
            $end   = min($total_pages, $page + 2);
            if (($end - $start + 1) < 5) {
              if ($start == 1) { $end = min($total_pages, $start + 4); }
              elseif ($end == $total_pages) { $start = max(1, $end - 4); }
            }
            for ($i=$start; $i<=$end; $i++):
          ?>
            <li class="page-item <?= $i==$page?'active':''; ?>">
              <a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>$i])) ?>"><?= $i ?></a>
            </li>
          <?php endfor; ?>
          <li class="page-item <?= $page>=$total_pages?'disabled':''; ?>">
            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page'=>$page+1])) ?>">Next</a>
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

<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
<script>
// Payment modal init + submit
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
      const res  = await fetch('../api/payment_add.php', { method:'POST', body:fd, credentials:'same-origin' });
      const text = await res.text();
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
