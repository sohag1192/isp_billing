<?php
// /public/client_invoices.php
// বাংলা: Single Client → Invoices list (filters + sorting + export + totals)

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* -------- Inputs -------- */
$client_id = (int)($_GET['id'] ?? 0);
if ($client_id<=0) { http_response_code(400); exit('Invalid client id'); }

$q        = trim($_GET['q'] ?? '');              // search: invoice_no, month
$month    = trim($_GET['month'] ?? '');          // YYYY-MM
$status   = trim($_GET['status'] ?? '');         // paid/partial/unpaid/void
$df       = trim($_GET['df'] ?? '');             // date from (invoice_date)
$dt       = trim($_GET['dt'] ?? '');             // date to
$page     = max(1, (int)($_GET['page'] ?? 1));
$limit    = max(10, min(100, (int)($_GET['limit'] ?? 25)));
$offset   = ($page - 1) * $limit;
$export   = strtolower(trim($_GET['export'] ?? '')); // ""|"csv"|"xls"

/* -------- Client Summary -------- */
// বাংলা: client info + ledger badge
$client = $pdo->prepare("SELECT id, name, pppoe_id, client_code, mobile, area, ledger_balance FROM clients WHERE id=?");
$client->execute([$client_id]); $c = $client->fetch(PDO::FETCH_ASSOC);
if(!$c){ http_response_code(404); exit('Client not found'); }

/* -------- Column map (defensive) -------- */
function hascol($table,$col){
  static $cache=[]; if(!isset($cache[$table])){
    $st=db()->prepare("SHOW COLUMNS FROM `$table`"); $st->execute();
    $cache[$table]=array_flip(array_map(fn($r)=>$r['Field'],$st->fetchAll(PDO::FETCH_ASSOC)));
  }
  return isset($cache[$table][$col]);
}
$tbl='invoices';
$col_id          = hascol($tbl,'id')? 'i.id' : 'i.invoice_id';
$col_client_id   = 'i.client_id';
$col_month       = hascol($tbl,'billing_month')? 'i.billing_month' : (hascol($tbl,'month')? 'i.month' : "NULL");
$col_total       = hascol($tbl,'total')? 'i.total' : (hascol($tbl,'amount')? 'i.amount' : '0');
$col_status      = hascol($tbl,'status')? 'i.status' : (hascol($tbl,'payment_status')? 'i.payment_status' : 'NULL');
$col_is_void     = hascol($tbl,'is_void')? 'i.is_void' : '0';
$col_inv_date    = hascol($tbl,'invoice_date')? 'i.invoice_date' : (hascol($tbl,'created_at')? 'i.created_at' : 'NULL');
$col_paid        = hascol($tbl,'paid_amount')? 'i.paid_amount' : 'NULL';
$col_due         = hascol($tbl,'due_amount')? 'i.due_amount' : 'NULL';
$col_note        = hascol($tbl,'note')? 'i.note' : (hascol($tbl,'remarks')? 'i.remarks' : '');

/* -------- Sorting whitelist -------- */
$allowed_sort = [
  'id'     => $col_id,
  'month'  => $col_month,
  'date'   => $col_inv_date,
  'total'  => $col_total,
  'status' => $col_status,
];
$sort_key = $_GET['sort'] ?? 'date';
$sort_col = $allowed_sort[$sort_key] ?? $allowed_sort['date'];
$default_dir = ['id'=>'desc','date'=>'desc','total'=>'desc','month'=>'desc','status'=>'asc'];
$dir_raw  = strtolower($_GET['dir'] ?? ($default_dir[$sort_key] ?? 'desc'));
$dir      = ($dir_raw==='asc')?'ASC':'DESC';

/* -------- Base SQL -------- */
$sql_base = "FROM invoices i WHERE $col_client_id = ? ";
$params = [$client_id];

/* search */
if ($q!==''){
  $like="%$q%";
  $sql_base .= " AND (".implode(" OR ", array_filter([
    hascol($tbl,'invoice_no')? "i.invoice_no LIKE ?" : null,
    $col_month!=="NULL"? "$col_month LIKE ?" : null,
    $col_status!=="NULL"? "$col_status LIKE ?" : null,
  ])).") ";
  if (hascol($tbl,'invoice_no')) $params[]=$like;
  if ($col_month!=="NULL") $params[]=$like;
  if ($col_status!=="NULL") $params[]=$like;
}

/* filters */
if ($month && preg_match('/^\d{4}-\d{2}$/',$month)) {
  $sql_base .= " AND DATE_FORMAT($col_month,'%Y-%m') = ? "; $params[] = $month;
}
if ($status!==''){ $sql_base .= " AND $col_status = ? "; $params[] = $status; }
$re_date='/^\d{4}-\d{2}-\d{2}$/';
if ($df && preg_match($re_date,$df)) { $sql_base .= " AND $col_inv_date >= ? "; $params[]=$df.' 00:00:00'; }
if ($dt && preg_match($re_date,$dt)) { $sql_base .= " AND $col_inv_date <= ? "; $params[]=$dt.' 23:59:59'; }

/* -------- Export (stream) -------- */
$select = "$col_id AS id, $col_month AS billing_month, $col_inv_date AS invoice_date, $col_total AS total, $col_status AS status, $col_is_void AS is_void, $col_paid AS paid_amount, $col_due AS due_amount, $col_note AS note";
if ($export==='csv' || $export==='xls'){
  $fname='client_'.$client_id.'_invoices_'.date('Ymd_His');
  $sqlx="SELECT $select $sql_base ORDER BY $sort_col $dir, i.id DESC";
  $stx=$pdo->prepare($sqlx); $stx->execute($params);
  if (function_exists('apache_setenv')) @apache_setenv('no-gzip','1'); @ini_set('output_buffering','0'); @ini_set('zlib.output_compression','0'); while(ob_get_level()) @ob_end_flush(); ob_implicit_flush(1);

  if ($export==='csv'){
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$fname.'.csv"');
    echo "\xEF\xBB\xBF"; $out=fopen('php://output','w');
    fputcsv($out,['ID','Month','Invoice Date','Total','Status','Is Void','Paid','Due','Note']);
    while($r=$stx->fetch(PDO::FETCH_ASSOC)){
      fputcsv($out,[$r['id'],$r['billing_month'],$r['invoice_date'],$r['total'],$r['status'],$r['is_void'],$r['paid_amount'],$r['due_amount'],$r['note']]);
    }
    fclose($out); exit;
  } else {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$fname.'.xls"');
    echo '<meta charset="utf-8"><table border="1" cellspacing="0" cellpadding="4"><tr>';
    foreach(['ID','Month','Invoice Date','Total','Status','Is Void','Paid','Due','Note'] as $h) echo '<th>'.h($h).'</th>';
    echo '</tr>';
    while($r=$stx->fetch(PDO::FETCH_ASSOC)){
      echo '<tr><td>'.h($r['id']).'</td><td>'.h($r['billing_month']).'</td><td>'.h($r['invoice_date']).'</td><td>'.h($r['total']).'</td><td>'.h($r['status']).'</td><td>'.h($r['is_void']).'</td><td>'.h($r['paid_amount']).'</td><td>'.h($r['due_amount']).'</td><td>'.h($r['note']).'</td></tr>';
    }
    echo '</table>'; exit;
  }
}

/* -------- Count + Fetch -------- */
$stc=$pdo->prepare("SELECT COUNT(*) $sql_base"); $stc->execute($params);
$total=(int)$stc->fetchColumn(); $total_pages=max(1,(int)ceil($total/$limit));
$sql="SELECT $select $sql_base ORDER BY $sort_col $dir, i.id DESC LIMIT $limit OFFSET $offset";
$st=$pdo->prepare($sql); $st->execute($params); $rows=$st->fetchAll(PDO::FETCH_ASSOC);

/* -------- Totals (page & grand) -------- */
$stsum=$pdo->prepare("SELECT SUM($col_total) t_total, SUM(COALESCE($col_paid,0)) t_paid, SUM(COALESCE($col_due, CASE WHEN $col_total IS NOT NULL AND $col_paid IS NOT NULL THEN ($col_total-$col_paid) ELSE 0 END)) t_due $sql_base");
$stsum->execute($params); $sum=$stsum->fetch(PDO::FETCH_ASSOC);

/* -------- Sort link helper -------- */
function srt($key,$label,$cur,$dir_raw){ $q=$_GET; $q['sort']=$key; $q['dir']=($cur===$key && strtolower($dir_raw)==='asc')?'desc':'asc'; $q['page']=1; $icon=' <i class="bi bi-arrow-down-up"></i>'; if($cur===$key) $icon=(strtolower($dir_raw)==='asc')?' <i class="bi bi-caret-up-fill"></i>':' <i class="bi bi-caret-down-fill"></i>'; return '<a class="text-decoration-none" href="?'.http_build_query($q).'">'.$label.$icon.'</a>'; }

include __DIR__ . '/../partials/partials_header.php';
?>
<style>
.table-compact>:not(caption)>*>*{ padding:.45rem .6rem; }
.badge-ledger.pos{background:#ffecec;color:#b30000}
.badge-ledger.neg{background:#e9f7ef;color:#146c43}
</style>

<div class="container-fluid p-3">
  <!-- Client header -->
  <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
    <h5 class="mb-0"><i class="bi bi-receipt"></i> Invoices — <?= h($c['name']) ?> <span class="text-muted small">(#<?= (int)$c['id'] ?>)</span></h5>
    <?php $lb=(float)$c['ledger_balance']; $lcls=$lb>0?'pos':($lb<0?'neg':''); ?>
    <span class="badge badge-ledger <?= $lcls ?>">Ledger: <?= number_format($lb,2) ?></span>
    <div class="ms-auto d-flex gap-2">
      <a class="btn btn-sm btn-outline-secondary" href="client_view.php?id=<?= (int)$c['id'] ?>"><i class="bi bi-person"></i> Client</a>
      <a class="btn btn-sm btn-outline-primary" href="client_payments.php?id=<?= (int)$c['id'] ?>"><i class="bi bi-cash-coin"></i> Payments</a>
    </div>
  </div>

  <!-- Filters -->
  <form class="card shadow-sm mb-3" method="get">
    <div class="card-body">
      <input type="hidden" name="id" value="<?= (int)$client_id ?>">
      <input type="hidden" name="sort" value="<?= h($sort_key) ?>">
      <input type="hidden" name="dir"  value="<?= h($dir_raw) ?>">
      <div class="row g-2 align-items-end">
        <div class="col-12 col-md-3">
          <label class="form-label mb-1">Search</label>
          <input name="q" value="<?= h($q) ?>" class="form-control form-control-sm" placeholder="Invoice No / Month / Status">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label mb-1">Month</label>
          <input name="month" value="<?= h($month) ?>" type="month" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label mb-1">Status</label>
          <select name="status" class="form-select form-select-sm">
            <?php foreach([''=>'All','paid'=>'Paid','partial'=>'Partial','unpaid'=>'Unpaid','void'=>'Void'] as $k=>$v): ?>
              <option value="<?= h($k) ?>" <?= $status===$k?'selected':'' ?>><?= h($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label mb-1">Date From</label>
          <input type="date" name="df" value="<?= h($df) ?>" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label mb-1">Date To</label>
          <input type="date" name="dt" value="<?= h($dt) ?>" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-1">
          <label class="form-label mb-1">Per Page</label>
          <select name="limit" class="form-select form-select-sm">
            <?php foreach([10,25,50,100] as $L): ?><option value="<?= $L ?>" <?= $limit==$L?'selected':'' ?>><?= $L ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2 d-grid">
          <label class="invisible d-none d-md-block mb-1">_</label>
          <button class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i> Filter</button>
        </div>
        <div class="col-6 col-md-2 d-grid">
          <label class="invisible d-none d-md-block mb-1">_</label>
          <button class="btn btn-success btn-sm" name="export" value="csv" formtarget="_blank"><i class="bi bi-filetype-csv"></i> CSV</button>
        </div>
        <div class="col-6 col-md-2 d-grid">
          <label class="invisible d-none d-md-block mb-1">_</label>
          <button class="btn btn-primary btn-sm" name="export" value="xls" formtarget="_blank"><i class="bi bi-file-earmark-excel"></i> Excel</button>
        </div>
      </div>
    </div>
  </form>

  <!-- Totals -->
  <div class="alert alert-light border d-flex flex-wrap gap-3 align-items-center">
    <div>Total: <strong><?= number_format((float)($sum['t_total'] ?? 0),2) ?></strong></div>
    <div>Paid: <strong class="text-success"><?= number_format((float)($sum['t_paid'] ?? 0),2) ?></strong></div>
    <div>Due: <strong class="text-danger"><?= number_format((float)($sum['t_due'] ?? 0),2) ?></strong></div>
  </div>

  <!-- Table -->
  <div class="card shadow-sm">
    <div class="table-responsive">
      <table class="table table-compact table-striped align-middle mb-0">
        <thead class="table-dark">
          <tr>
            <th><?= srt('id','#',$sort_key,$dir_raw) ?></th>
            <th><?= srt('month','Month',$sort_key,$dir_raw) ?></th>
            <th><?= srt('date','Invoice Date',$sort_key,$dir_raw) ?></th>
            <th class="text-end"><?= srt('total','Total',$sort_key,$dir_raw) ?></th>
            <th><?= srt('status','Status',$sort_key,$dir_raw) ?></th>
            <th>Note</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if($rows): foreach($rows as $r): ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><?= h($r['billing_month'] ?? '') ?></td>
              <td><?= h($r['invoice_date'] ?? '') ?></td>
              <td class="text-end"><?= number_format((float)($r['total'] ?? 0),2) ?></td>
              <td>
                <?php $st=strtolower((string)$r['status']); $cls=($st==='paid'?'bg-success':($st==='partial'?'bg-warning text-dark':($st==='void'?'bg-secondary':'bg-danger'))); ?>
                <span class="badge <?= $cls ?>"><?= h($r['status'] ?? '-') ?></span>
              </td>
              <td class="text-truncate" style="max-width:220px;"><?= h($r['note'] ?? '') ?></td>
              <td class="text-end">
                <div class="btn-group btn-group-sm">
                  <a class="btn btn-outline-primary" href="invoices.php?q=<?= urlencode($r['id']) ?>" title="Open in Invoices"><i class="bi bi-box-arrow-up-right"></i></a>
                </div>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="7" class="text-center text-muted py-4">No invoices found</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Pagination -->
  <?php if($total_pages>1):
    $qsPrev=$_GET; $qsPrev['page']=max(1,$page-1);
    $qsNext=$_GET; $qsNext['page']=min($total_pages,$page+1);
    $start=max(1,$page-2); $end=min($total_pages,$page+2);
    if(($end-$start)<4){ $end=min($total_pages,$start+4); $start=max(1,$end-4); }
  ?>
  <nav class="mt-3">
    <ul class="pagination pagination-sm justify-content-center">
      <li class="page-item <?= $page<=1?'disabled':'' ?>">
        <a class="page-link" href="?<?= http_build_query($qsPrev) ?>">Previous</a>
      </li>
      <?php for($i=$start;$i<=$end;$i++): $qsi=$_GET; $qsi['page']=$i; ?>
        <li class="page-item <?= $i==$page?'active':'' ?>">
          <a class="page-link" href="?<?= http_build_query($qsi) ?>"><?= $i ?></a>
        </li>
      <?php endfor; ?>
      <li class="page-item <?= $page>=$total_pages?'disabled':'' ?>">
        <a class="page-link" href="?<?= http_build_query($qsNext) ?>">Next</a>
      </li>
    </ul>
  </nav>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../partials/partials_footer.php';
