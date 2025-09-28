<?php
// /public/portal/invoices.php
// Client Portal — My Invoices (schema-aware, safe, light UI)
// UI text: English; Comments: বাংলা

declare(strict_types=1);
require_once __DIR__ . '/../../app/portal_require_login.php';
require_once __DIR__ . '/../../app/db.php';

function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------- Helpers: schema detect ---------- */
function col_exists(PDO $pdo, string $tbl, string $col): bool {
  try{
    $st = $pdo->prepare("SHOW COLUMNS FROM `$tbl` LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetchColumn();
  }catch(Throwable $e){ return false; }
}

/* ---------- Resolve client (portal) ---------- */
// বাংলা: পোর্টাল লগিন থেকে client_id নিন — না থাকলে 403
$client_id = (int) (function() use ($pdo){
  if (function_exists('portal_client_id')) {
    $cid = (int) portal_client_id();
    if ($cid > 0) return $cid;
  }
  if (session_status() === PHP_SESSION_NONE) { session_start(); }
  foreach (['client_id','SESS_CLIENT_ID'] as $k) {
    if (!empty($_SESSION[$k]) && ctype_digit((string)$_SESSION[$k])) return (int)$_SESSION[$k];
  }
  return 0;
})();
if ($client_id <= 0){
  http_response_code(403);
  echo '<div style="max-width:680px;margin:40px auto;font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif">';
  echo '<h4 style="margin-bottom:8px;color:#b02a37">Access denied</h4>';
  echo '<p>Please sign in to the client portal.</p>';
  echo '<a href="/public/portal/index.php" style="text-decoration:none;border:1px solid #ccc;padding:6px 10px;border-radius:6px">Back to Portal</a>';
  echo '</div>';
  exit;
}

/* ---------- Columns, fallbacks ---------- */
$has_inv_number = col_exists($pdo,'invoices','invoice_number');
$has_inv_no     = col_exists($pdo,'invoices','invoice_no');
$has_bmon       = col_exists($pdo,'invoices','billing_month'); // YYYY-MM
$has_month      = col_exists($pdo,'invoices','month');
$has_year       = col_exists($pdo,'invoices','year');
$has_idate      = col_exists($pdo,'invoices','invoice_date');
$has_status     = col_exists($pdo,'invoices','status');
$has_is_void    = col_exists($pdo,'invoices','is_void');

/* total/payable/amount — যে আছে তা নিন */
$amount_exprs = [];
if (col_exists($pdo,'invoices','total'))   $amount_exprs[] = 'i.total';
if (col_exists($pdo,'invoices','payable')) $amount_exprs[] = 'i.payable';
if (col_exists($pdo,'invoices','amount'))  $amount_exprs[] = 'i.amount';
$amount_expr = $amount_exprs ? ('COALESCE('.implode(',', $amount_exprs).')') : '0';

/* payments টেবিলে invoice FK */
$pay_tbl     = 'payments';
$pay_has_iid = col_exists($pdo,$pay_tbl,'invoice_id');
$pay_has_bid = col_exists($pdo,$pay_tbl,'bill_id');
$pay_col_inv = $pay_has_iid ? 'invoice_id' : ($pay_has_bid ? 'bill_id' : null);

/* ---------- Inputs ---------- */
$month   = trim((string)($_GET['month']  ?? ''));                 // YYYY-MM
$status  = strtolower(trim((string)($_GET['status'] ?? '')));     // '', paid/partial/unpaid/due
$search  = trim((string)($_GET['search'] ?? ''));                 // invoice no / notes
$sort    = trim((string)($_GET['sort']   ?? 'date'));             // invoice, month, total, paid, due, status, date
$dir     = strtolower((string)($_GET['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
$page    = max(1, (int)($_GET['page'] ?? 1));
$limit   = max(10, min(100, (int)($_GET['ps'] ?? 20)));
$offset  = ($page - 1) * $limit;

/* ---------- Client binding (strict) ---------- */
// বাংলা: প্রথমে invoices টেবিলে client সম্পর্কিত কলাম খুঁজি
$invClientCol = null;
foreach (['client_id','customer_id','subscriber_id','user_id','client','cid'] as $col) {
  if (col_exists($pdo,'invoices',$col)) { $invClientCol = $col; break; }
}

/* ---------- WHERE ---------- */
$where = [];
$args  = [];
if ($invClientCol){
  $where[] = "i.`$invClientCol` = ?";
  $args[]  = $client_id;
} else {
  // বাংলা: FK না থাকলে সেফ সাইড — কিছুই না দেখানো ভাল
  $where[] = "1=0";
}

/* void বাদ দিন */
if ($has_is_void) $where[] = "COALESCE(i.is_void,0)=0";

/* status */
if ($status !== '' && $has_status){
  $where[] = "LOWER(i.status) = ?";
  $args[]  = $status;
}

/* month */
if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
  if ($has_bmon) {
    $where[] = "i.billing_month = ?";
    $args[]  = $month;
  } elseif ($has_month && $has_year) {
    [$y,$m] = explode('-', $month);
    $where[] = "(i.year = ? AND LPAD(i.month,2,'0') = ?)";
    $args[]  = (int)$y;
    $args[]  = $m;
  } elseif ($has_idate) {
    $where[] = "DATE_FORMAT(i.invoice_date,'%Y-%m') = ?";
    $args[]  = $month;
  }
}

/* search: invoice number / notes */
if ($search !== '') {
  $like = '%'.$search.'%';
  $parts = [];
  if ($has_inv_number) $parts[] = "i.invoice_number LIKE ?";
  if ($has_inv_no)     $parts[] = "i.invoice_no LIKE ?";
  if (col_exists($pdo,'invoices','notes')) $parts[] = "i.notes LIKE ?";
  if ($parts){
    $where[] = '('.implode(' OR ', $parts).')';
    foreach ($parts as $_) $args[] = $like;
  }
}

$where_sql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

/* ---------- Count ---------- */
$stc = $pdo->prepare("SELECT COUNT(*) FROM invoices i $where_sql");
$stc->execute($args);
$total_rows  = (int)$stc->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $limit));

/* ---------- Select list ---------- */
$selects = [
  "i.id",
  "$amount_expr AS total_amount",
  $has_status ? "COALESCE(i.status,'') AS status" : "'' AS status",
];

if ($has_inv_number)      $selects[] = "i.invoice_number AS invoice_no";
elseif ($has_inv_no)      $selects[] = "i.invoice_no AS invoice_no";
else                      $selects[] = "i.id AS invoice_no";

if     ($has_bmon)        $selects[] = "i.billing_month AS ym";
elseif ($has_month && $has_year) $selects[] = "CONCAT(i.year,'-',LPAD(i.month,2,'0')) AS ym";
elseif ($has_idate)       $selects[] = "DATE_FORMAT(i.invoice_date,'%Y-%m') AS ym";
else                      $selects[] = "'' AS ym";

$date_expr = $has_idate ? "i.invoice_date" : "i.id";
$sort_map = [
  'invoice' => "invoice_no",
  'month'   => "ym",
  'total'   => "total_amount",
  'paid'    => "paid_dummy",   // পরে client-side calc; SQL-এ সাজাব না
  'due'     => "due_dummy",    // পরে client-side calc; SQL-এ সাজাব না
  'status'  => "status",
  'date'    => $date_expr,
];
$sortCol = $sort_map[$sort] ?? $date_expr;
// বাংলা: paid/due SQL-এ নেই — fallback: date desc/asc
if (in_array($sort, ['paid','due'], true)) $sortCol = $date_expr;

/* ---------- Fetch page ---------- */
$sql = "SELECT ".implode(", ", $selects)."
        FROM invoices i
        $where_sql
        ORDER BY $sortCol $dir
        LIMIT $limit OFFSET $offset";
$st = $pdo->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* ---------- Paid map for this page ---------- */
$paid_map = [];
if ($pay_col_inv && $rows) {
  $ids = array_column($rows, 'id');
  if ($ids){
    $in = implode(',', array_fill(0, count($ids), '?'));
    $sqlp = "SELECT $pay_col_inv AS iid, SUM(COALESCE(amount,0) - COALESCE(discount,0)) AS paid_sum
             FROM $pay_tbl
             WHERE $pay_col_inv IN ($in)
             GROUP BY $pay_col_inv";
    $stp = $pdo->prepare($sqlp);
    $stp->execute($ids);
    while($r = $stp->fetch(PDO::FETCH_ASSOC)){
      $paid_map[(int)$r['iid']] = (float)$r['paid_sum'];
    }
  }
}

/* ---------- UI (no partials_header; portal sidebar only) ---------- */
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>My Invoices</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    /* Light, clean portal look */
    body { background:#f7f8fb; }
    .portal-topbar { background:#ffffff; border-bottom:1px solid #e9edf3; }
    .portal-topbar .brand { font-weight:600; }
    .portal-page .card { border:1px solid #e9edf3; }
    .sidebar-wrap { min-width:260px; background:linear-gradient(180deg,#eef5ff,#ffffff); border-right:1px solid #e9edf3; }
    .sidebar-inner { padding:16px; position:sticky; top:0; height:100vh; overflow:auto; }
    .content-wrap { flex:1; min-width:0; }
    .kv { display:grid; grid-template-columns:max-content 1fr; column-gap:12px; row-gap:6px; }
    .table thead th a { text-decoration:none; color:inherit; }
    .badge-pill { border-radius: 999px; }
  </style>
</head>
<body>
  <nav class="portal-topbar navbar navbar-light">
    <div class="container-fluid">
      <span class="navbar-brand brand"><i class="bi bi-receipt"></i> My Invoices</span>
      <a class="btn btn-outline-secondary btn-sm" href="/public/portal/index.php"><i class="bi bi-house"></i> Portal Home</a>
    </div>
  </nav>

  <div class="d-flex">
    <?php
      // বাংলা: পোর্টাল সাইডবার (যদি থাকে)
      $sb1 = __DIR__.'/portal_sidebar.php';
      $sb2 = __DIR__.'/sidebar.php';
      echo '<div class="sidebar-wrap d-none d-md-block"><div class="sidebar-inner">';
      if (is_file($sb1)) include $sb1; elseif (is_file($sb2)) include $sb2;
      echo '</div></div>';
    ?>
    <div class="content-wrap p-3 portal-page">
      <div class="container-fluid px-0">
        <!-- Filters -->
        <div class="card shadow-sm mb-3">
          <div class="card-body">
            <form class="row g-2 align-items-end" method="get">
              <div class="col-sm-3">
                <label class="form-label mb-1">Month</label>
                <input type="month" class="form-control" name="month" value="<?php echo h($month); ?>">
              </div>
              <div class="col-sm-3">
                <label class="form-label mb-1">Status</label>
                <select class="form-select" name="status">
                  <option value="">All</option>
                  <?php foreach(['paid'=>'Paid','partial'=>'Partial','unpaid'=>'Unpaid','due'=>'Due'] as $k=>$v): ?>
                    <option value="<?php echo h($k); ?>" <?php echo ($status===$k?'selected':''); ?>><?php echo h($v); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-sm-4">
                <label class="form-label mb-1">Search</label>
                <input type="text" class="form-control" name="search" value="<?php echo h($search); ?>" placeholder="Invoice no / notes">
              </div>
              <div class="col-sm-2">
                <button class="btn btn-primary w-100" type="submit"><i class="bi bi-funnel"></i> Filter</button>
              </div>
            </form>
          </div>
        </div>

        <?php
          // বাংলা: পেজ-সামারি (বর্তমান টেবিল রো থেকে)
          $sum_total = 0.0; $sum_paid = 0.0;
          foreach ($rows as $r) {
            $iid = (int)$r['id'];
            $tot = (float)($r['total_amount'] ?? 0);
            $pad = (float)($paid_map[$iid] ?? 0);
            $sum_total += $tot; $sum_paid += $pad;
          }

          // sorting helper
          function sort_link(string $key, string $label) {
            $q = $_GET; $q['sort'] = $key;
            $cur = strtolower((string)($_GET['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
            $q['dir'] = ($key === ($_GET['sort'] ?? '')) ? ($cur==='asc'?'desc':'asc') : 'asc';
            $url = '?'.http_build_query($q);
            return '<a href="'.h($url).'">'.h($label).' <i class="bi bi-arrow-down-up"></i></a>';
          }
        ?>

        <div class="d-flex flex-wrap gap-2 mb-2">
          <span class="badge text-bg-secondary p-2">Invoices: <?php echo h($total_rows); ?></span>
          <span class="badge text-bg-primary p-2">Total (page): <?php echo number_format($sum_total,2); ?></span>
          <span class="badge text-bg-success p-2">Paid (page): <?php echo number_format($sum_paid,2); ?></span>
          <span class="badge text-bg-danger p-2">Due (page): <?php echo number_format(max(0,$sum_total-$sum_paid),2); ?></span>
        </div>

        <div class="card shadow-sm">
          <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th style="width:140px;"><?php echo sort_link('invoice','Invoice'); ?></th>
                  <th style="width:120px;"><?php echo sort_link('month','Month'); ?></th>
                  <th class="text-end" style="width:120px;"><?php echo sort_link('total','Total'); ?></th>
                  <th class="text-end" style="width:120px;"><?php echo sort_link('paid','Paid'); ?></th>
                  <th class="text-end" style="width:120px;"><?php echo sort_link('due','Due'); ?></th>
                  <th style="width:110px;"><?php echo sort_link('status','Status'); ?></th>
                  <th class="text-end" style="width:160px;"><?php echo sort_link('date','Actions'); ?></th>
                </tr>
              </thead>
              <tbody>
              <?php if (!$rows): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No invoices found.</td></tr>
              <?php else: foreach($rows as $r):
                  $iid   = (int)$r['id'];
                  $tot   = (float)($r['total_amount'] ?? 0);
                  $paid  = (float)($paid_map[$iid] ?? 0);
                  $due   = max(0.0, $tot - $paid);
                  $sttxt = trim((string)($r['status'] ?? ''));

                  // বাংলা: স্ট্যাটাস ব্যাজ (status না থাকলে paid/due ভিত্তিক)
                  $badge = 'secondary'; $stshow = $sttxt;
                  if ($sttxt !== '') {
                    if (strcasecmp($sttxt,'paid')===0) $badge='success';
                    elseif (strcasecmp($sttxt,'partial')===0) $badge='warning';
                    elseif (strcasecmp($sttxt,'unpaid')===0 || strcasecmp($sttxt,'due')===0) $badge='danger';
                  } else {
                    $badge = ($due <= 0.00001) ? 'success' : (($paid > 0) ? 'warning' : 'danger');
                    $stshow = ($due <= 0.00001) ? 'Paid' : (($paid > 0) ? 'Partial' : 'Unpaid');
                  }

                  $inv_no = (string)($r['invoice_no'] ?? ('#'.$iid));
                  $ym     = (string)($r['ym'] ?? '');
                  $viewUrl = '/public/invoices.php?view='.$iid; // আপনার পাবলিক ভিউ/প্রিন্ট রুট আগের মতোই
                  $printUrl = (file_exists(__DIR__.'/../invoice_print.php')) ? ('/public/invoice_print.php?id='.$iid) : '';
                  // bKash instruction page (static) — due থাকলে enable
                  $bkashUrl = '/public/portal/bkash.php?ref='.urlencode($inv_no).'&amount='.urlencode(number_format($due,2,'.',''));
                ?>
                <tr>
                  <td><?php echo h($inv_no); ?></td>
                  <td><?php echo h($ym); ?></td>
                  <td class="text-end"><?php echo number_format($tot,2); ?></td>
                  <td class="text-end"><?php echo number_format($paid,2); ?></td>
                  <td class="text-end">
                    <span class="badge <?php echo $due>0.01?'text-bg-danger':($due<-0.01?'text-bg-success':'text-bg-secondary'); ?> badge-pill">
                      <?php echo number_format($due,2); ?>
                    </span>
                  </td>
                  <td><span class="badge text-bg-<?php echo h($badge); ?>"><?php echo h(ucfirst($stshow)); ?></span></td>
                  <td class="text-end">
                    <div class="btn-group btn-group-sm">
                      <a class="btn btn-outline-primary" href="<?php echo h($viewUrl); ?>" target="_blank" rel="noopener">
                        <i class="bi bi-eye"></i> View
                      </a>
                      <?php if ($printUrl): ?>
                        <a class="btn btn-outline-secondary" href="<?php echo h($printUrl); ?>" target="_blank" rel="noopener">
                          <i class="bi bi-printer"></i> Print
                        </a>
                      <?php endif; ?>
                      <a class="btn btn-outline-danger <?php echo ($due<=0?'disabled':''); ?>" href="<?php echo h($bkashUrl); ?>">
                        <i class="bi bi-wallet2"></i> Pay
                      </a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>

          <?php if ($total_pages > 1): ?>
          <div class="card-body">
            <nav aria-label="Page">
              <ul class="pagination pagination-sm mb-0">
                <?php
                  $q = $_GET;
                  for ($p=1; $p <= $total_pages; $p++){
                    $q['page'] = $p;
                    $url = '?'.http_build_query($q);
                    $active = ($p === $page) ? 'active' : '';
                    echo '<li class="page-item '.$active.'"><a class="page-link" href="'.h($url).'">'.$p.'</a></li>';
                  }
                ?>
              </ul>
            </nav>
          </div>
          <?php endif; ?>
        </div>

      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
