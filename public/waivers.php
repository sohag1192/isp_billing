<?php
// /public/waivers.php
// Waiver/Adjustment report — list, filters, sum, CSV export
// UI: English; Comments: বাংলা
declare(strict_types=1);

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function db_has_column(string $table, string $column): bool {
  static $cache = [];
  if (!isset($cache[$table])) {
    $rows = db()->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
    $cache[$table] = array_flip($rows ?: []);
  }
  return isset($cache[$table][$column]);
}

$payments_has_type     = db_has_column('payments','type');
$payments_has_method   = db_has_column('payments','method');
$payments_has_client   = db_has_column('payments','client_id');
$payments_has_notes    = db_has_column('payments','notes');
$payments_has_paid_at  = db_has_column('payments','paid_at');

// ---------- Inputs ----------
$search   = trim($_GET['search'] ?? '');     // name / pppoe / mobile
$date_f   = trim($_GET['date_from'] ?? '');
$date_t   = trim($_GET['date_to'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$limit    = 25;
$offset   = ($page - 1) * $limit;
$export   = isset($_GET['export']) && $_GET['export'] === 'csv';

// ---------- Base WHERE ----------
$where = [];
$params = [];

$waiver_clause = [];
if ($payments_has_type)   $waiver_clause[] = "p.type='waiver'";
if ($payments_has_method) $waiver_clause[] = "p.method='waiver'";
// fallback: negative amount গুলোও consider করব (যদি কেউ নেগেটিভ payment দিয়ে থাকে)
$waiver_clause[] = "p.amount < 0";

$where[] = "(".implode(' OR ', $waiver_clause).")";

// Join clients if have client_id or for search
$join_clients = true;

// Date filter (বাংলা) paid_at থাকলে সেটা, না থাকলে created_at ধরে নিব
$date_col = $payments_has_paid_at ? 'p.paid_at' : 'p.created_at';
if ($date_f !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$date_f)) { $where[] = "$date_col >= ?"; $params[] = $date_f; }
if ($date_t !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$date_t)) { $where[] = "$date_col <= ?"; $params[] = $date_t; }

if ($search !== '') {
  $where[] = "(c.name LIKE ? OR c.pppoe_id LIKE ? OR c.mobile LIKE ?)";
  $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}

$where_sql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

// ---------- Count & Sum ----------
$pdo = db();
$sql_count = "SELECT COUNT(*) AS cnt, COALESCE(SUM(CASE WHEN p.amount<0 THEN -p.amount ELSE p.amount END),0) AS total_waiver
              FROM payments p
              LEFT JOIN clients c ON c.id = p.client_id
              $where_sql";
$stc = $pdo->prepare($sql_count);
$stc->execute($params);
$agg = $stc->fetch(PDO::FETCH_ASSOC) ?: ['cnt'=>0,'total_waiver'=>0];
$total_rows = (int)$agg['cnt'];
$total_waiver = (float)$agg['total_waiver'];

// ---------- Fetch page ----------
$sql_list = "SELECT p.*, c.pppoe_id, c.name AS client_name, c.mobile
             FROM payments p
             LEFT JOIN clients c ON c.id = p.client_id
             $where_sql
             ORDER BY $date_col DESC, p.id DESC
             LIMIT $limit OFFSET $offset";
$st = $pdo->prepare($sql_list);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// ---------- CSV export ----------
if ($export) {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=waivers_'.date('Ymd_His').'.csv');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['Date','PPPoE ID','Client Name','Mobile','Amount(TK)','Reason','PaymentID']);
  foreach ($rows as $r) {
    $date = $payments_has_paid_at ? ($r['paid_at'] ?? '') : ($r['created_at'] ?? '');
    // amount normalize: negative হলে absolute দেখাই, নইলে as-is
    $amt = (float)$r['amount'];
    if ($amt < 0) $amt = -$amt;
    $reason = $payments_has_notes ? ($r['notes'] ?? '') : '';
    fputcsv($out, [$date, $r['pppoe_id'] ?? '', $r['client_name'] ?? '', $r['mobile'] ?? '', $amt, $reason, $r['id'] ?? '']);
  }
  fclose($out);
  exit;
}

// ---------- Pagination ----------
$total_pages = max(1, (int)ceil($total_rows / $limit));

?>
<?php require_once __DIR__ . '/../partials/partials_header.php'; ?>
<div class="container my-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="mb-0">Waiver / Adjustment Report</h3>
    <div>
      <a href="/public/waiver_add.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Add Waiver</a>
    </div>
  </div>

  <form class="row g-2 mb-3" method="get">
    <div class="col-md-3">
      <input type="text" name="search" value="<?=h($search)?>" class="form-control" placeholder="Search name/PPPoE/mobile">
    </div>
    <div class="col-md-2">
      <input type="date" name="date_from" value="<?=h($date_f)?>" class="form-control" placeholder="From">
    </div>
    <div class="col-md-2">
      <input type="date" name="date_to" value="<?=h($date_t)?>" class="form-control" placeholder="To">
    </div>
    <div class="col-md-5 d-flex gap-2">
      <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-funnel"></i> Filter</button>
      <a class="btn btn-outline-dark" href="?<?=h(http_build_query(array_merge($_GET,['export'=>'csv'])))?>">
        <i class="bi bi-filetype-csv"></i> Export CSV
      </a>
    </div>
  </form>

  <div class="alert alert-info py-2">
    <strong>Total waivers:</strong> TK <?=h(number_format($total_waiver,2))?> |
    <strong>Rows:</strong> <?=h((string)$total_rows)?>
  </div>

  <div class="table-responsive">
    <table class="table table-striped align-middle">
      <thead class="table-light">
        <tr>
          <th style="width:140px;">Date</th>
          <th>PPPoE ID</th>
          <th>Client</th>
          <th>Mobile</th>
          <th class="text-end">Waiver (TK)</th>
          <th>Reason</th>
          <th>ID</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="7" class="text-center text-muted">No waivers found.</td></tr>
        <?php else: foreach ($rows as $r): 
          $date = $payments_has_paid_at ? ($r['paid_at'] ?? '') : ($r['created_at'] ?? '');
          $amt = (float)$r['amount']; if ($amt < 0) $amt = -$amt; // normalize as positive display
          $reason = $payments_has_notes ? ($r['notes'] ?? '') : '';
        ?>
          <tr>
            <td><?=h($date)?></td>
            <td><?=h($r['pppoe_id'] ?? '')?></td>
            <td><?=h($r['client_name'] ?? '')?></td>
            <td><?=h($r['mobile'] ?? '')?></td>
            <td class="text-end"><span class="badge bg-success">-<?=h(number_format($amt,2))?></span></td>
            <td><?=h($reason)?></td>
            <td><?=h((string)($r['id'] ?? ''))?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination (show max 5 pages window) -->
  <?php
    $win = 5;
    $start = max(1, $page - intdiv($win-1,2));
    $end   = min($total_pages, $start + $win - 1);
    if ($end - $start + 1 < $win) $start = max(1, $end - $win + 1);
  ?>
  <nav aria-label="Page navigation">
    <ul class="pagination justify-content-center">
      <?php
        $q = $_GET; 
        $q['page']=1; 
        $first_url='?'.h(http_build_query($q));
        $q['page']=max(1,$page-1);
        $prev_url='?'.h(http_build_query($q));
      ?>
      <li class="page-item <?=($page<=1?'disabled':'')?>"><a class="page-link" href="<?=$first_url?>">&laquo;</a></li>
      <li class="page-item <?=($page<=1?'disabled':'')?>"><a class="page-link" href="<?=$prev_url?>">Prev</a></li>
      <?php for($i=$start;$i<=$end;$i++): 
        $q=$_GET; $q['page']=$i; $u='?'.h(http_build_query($q)); ?>
        <li class="page-item <?=($i==$page?'active':'')?>"><a class="page-link" href="<?=$u?>"><?=$i?></a></li>
      <?php endfor; 
        $q=$_GET; $q['page']=min($total_pages,$page+1); $next='?'.h(http_build_query($q));
        $q=$_GET; $q['page']=$total_pages; $last='?'.h(http_build_query($q));
      ?>
      <li class="page-item <?=($page>=$total_pages?'disabled':'')?>"><a class="page-link" href="<?=$next?>">Next</a></li>
      <li class="page-item <?=($page>=$total_pages?'disabled':'')?>"><a class="page-link" href="<?=$last?>">&raquo;</a></li>
    </ul>
  </nav>
</div>
<?php require_once __DIR__ . '/../partials/partials_footer.php'; ?>
