<?php
// /public/client_list_by_status.php
// Client list + Search + Advanced Filters + Pagination + Column Sorting + Export + Left/Undo Left (bulk)
// UI English; বাংলা কমেন্ট

declare(strict_types=1);

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* -------------------- Inputs -------------------- */
// বাংলা: status ভ্যালিডেশন (left => is_left=1; নাহলে is_left=0 + c.status=given)
$status = $_GET['status'] ?? 'active';
$valid_status = ['active','inactive','pending','expired','left'];
if (!in_array($status, $valid_status, true)) { die('Invalid status'); }

$q        = trim($_GET['q'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$limit    = max(10, min(100, (int)($_GET['limit'] ?? 20)));
$offset   = ($page - 1) * $limit;

// Advanced filters
$package   = $_GET['package'] ?? '';
$router    = $_GET['router']  ?? '';
$area      = trim($_GET['area'] ?? '');
$date_from = trim($_GET['df'] ?? '');
$date_to   = trim($_GET['dt'] ?? '');
$date_col  = ($status === 'left') ? 'c.left_at' : 'c.join_date';

// Export (csv/xls)
$export = strtolower(trim($_GET['export'] ?? '')); // '' | 'csv' | 'xls'

/* -------------------- Sorting (whitelist) -------------------- */
// বাংলা: কোন কোন কলামে sort করা যাবে — SQL ইনজেকশন ঠেকাতে whitelist ম্যাপ
$allowed_sort = [
  'id'          => 'c.id',
  'client_code' => 'c.client_code',
  'name'        => 'c.name COLLATE utf8mb4_unicode_ci',
  'pppoe'       => 'c.pppoe_id',
  'package'     => 'p.name',
  'router'      => 'r.name',
  'area'        => 'c.area',
  'mobile'      => 'c.mobile',
  'status'      => 'c.status',
  'left_at'     => 'c.left_at',
  'created'     => 'c.created_at',
  'updated'     => 'c.updated_at',
  'join'        => 'c.join_date',
  'expiry'      => 'c.expiry_date',
  'ledger'      => 'c.ledger_balance',
];

$sort_key = $_GET['sort'] ?? 'id';
$sort_col = $allowed_sort[$sort_key] ?? $allowed_sort['id'];

// বাংলা: numeric/date ধাঁচের ফিল্ডগুলিতে ডিফল্ট dir DESC; টেক্সটে ASC
$default_dir_map = [
  'id'=>'desc','created'=>'desc','updated'=>'desc','left_at'=>'desc',
  'join'=>'desc','expiry'=>'desc','ledger'=>'desc'
];
$requested_dir = strtolower($_GET['dir'] ?? '');
$dir_raw = $requested_dir ?: ($default_dir_map[$sort_key] ?? 'asc');
$dir     = ($dir_raw === 'asc') ? 'ASC' : 'DESC';

// বাংলা: nullable join কলামে NULLS LAST—IS NULL ফ্ল্যাগ আগে
$nulls_last_sql = '';
if (in_array($sort_key, ['package','router','area','mobile','left_at','expiry','updated','created'], true)) {
  $nulls_last_sql = " ({$sort_col} IS NULL), ";
}

/* -------------------- Base Query + Filters -------------------- */
// বাংলা: routers join রাখছি যাতে export/table উভয় জায়গায় router/package name পাওয়া যায়
$sql_base = "FROM clients c
             LEFT JOIN packages p ON c.package_id = p.id
             LEFT JOIN routers  r ON c.router_id  = r.id
             WHERE 1 ";
$params = [];

// left / normal
if ($status === 'left') {
  $sql_base .= " AND c.is_left = 1 ";
} else {
  $sql_base .= " AND c.is_left = 0 AND c.status = ? ";
  $params[] = $status;
}

// search
if ($q !== '') {
  $like = "%$q%";
  $sql_base .= " AND (c.name LIKE ? OR c.pppoe_id LIKE ? OR c.client_code LIKE ? OR c.mobile LIKE ? OR c.address LIKE ? OR c.ip_address LIKE ?) ";
  array_push($params, $like, $like, $like, $like, $like, $like);
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

// বাংলা: ইনডেক্স-ফ্রেন্ডলি date range (DATE() ছাড়া)
if ($date_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
  $sql_base .= " AND {$date_col} >= ? ";
  $params[] = $date_from . ' 00:00:00';
}
if ($date_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
  $sql_base .= " AND {$date_col} <= ? ";
  $params[] = $date_to . ' 23:59:59';
}

// ORDER BY (reuse for export + table)
$order_by = "{$nulls_last_sql}{$sort_col} {$dir}, c.id DESC";

/* ==================== EXPORT HANDLER ==================== */
// বাংলা: export=csv/xls হলে LIMIT ছাড়া ফুল রেজাল্ট; স্ট্যাবল অর্ডারিং
if ($export === 'csv' || $export === 'xls') {
    $filename_base = 'clients_'.($status ?: 'all').'_'.date('Ymd_His');

    $sql_exp = "SELECT 
                  c.id, c.client_code, c.name, c.pppoe_id, 
                  p.name AS package_name, r.name AS router_name,
                  c.area, c.mobile, c.status, c.join_date, c.left_at, c.ledger_balance
                $sql_base
                ORDER BY $order_by";
    $stx = $pdo->prepare($sql_exp);
    $stx->execute($params);
    $rows_all = $stx->fetchAll(PDO::FETCH_ASSOC);

    if ($export === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$filename_base.'.csv"');
        // UTF-8 BOM so Excel shows Bengali correctly
        echo "\xEF\xBB\xBF";

        $out = fopen('php://output', 'w');
        // headers
        fputcsv($out, [
          'ID','Client Code','Name','PPPoE','Package','Router',
          'Area','Mobile','Status','Join Date','Left At','Ledger Balance'
        ]);
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
              $r['status'],
              $r['join_date'],
              $r['left_at'],
              $r['ledger_balance'],
            ]);
        }
        fclose($out);
        exit;
    } else { // xls (Excel-friendly HTML table)
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$filename_base.'.xls"');
        echo '<meta charset="utf-8">';
        echo '<table border="1" cellspacing="0" cellpadding="4">';
        echo '<tr>';
        $heads = ['ID','Client Code','Name','PPPoE','Package','Router','Area','Mobile','Status','Join Date','Left At','Ledger Balance'];
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
            echo '<td>'.h($r['status']).'</td>';
            echo '<td>'.h($r['join_date']).'</td>';
            echo '<td>'.h($r['left_at']).'</td>';
            echo '<td>'.h($r['ledger_balance']).'</td>';
            echo '</tr>';
        }
        echo '</table>';
        exit;
    }
}
/* ================== END EXPORT HANDLER =================== */

/* -------------------- Count + Fetch (paged) -------------------- */
$stc = $pdo->prepare("SELECT COUNT(*) ".$sql_base);
$stc->execute($params);
$total = (int)$stc->fetchColumn();
$total_pages = max(1, (int)ceil($total / $limit));
$page = min($page, $total_pages); // বাংলা: clamp
$offset = ($page - 1) * $limit;

$sql = "SELECT 
          c.*, 
          p.name AS package_name, 
          r.name AS router_name
        $sql_base
        ORDER BY $order_by
        LIMIT $limit OFFSET $offset";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* -------------------- Dropdown data -------------------- */
$pkgs = $pdo->query("SELECT id, name FROM packages ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$rtrs = $pdo->query("SELECT id, name FROM routers  ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$areas_stmt = $pdo->query("SELECT DISTINCT area FROM clients WHERE area IS NOT NULL AND area<>'' ORDER BY area ASC");
$areas = $areas_stmt->fetchAll(PDO::FETCH_COLUMN);

/* -------------------- Helper: build_sort_link -------------------- */
// বাংলা: হেডার ক্লিকে sort টগল; অন্যান্য query প্যারাম রেখে দেয়; aria-label যোগ
function build_sort_link($label, $key, $currentSort, $currentDir){
    $params = $_GET;
    unset($params['sort'], $params['dir'], $params['page']); // page reset
    $nextDir = 'asc';
    if ($currentSort === $key && strtolower($currentDir) === 'asc') { $nextDir = 'desc'; }
    $params['sort'] = $key; $params['dir'] = $nextDir; $params['page'] = 1;
    $qs = http_build_query($params);

    $icon = ' <i class="bi bi-arrow-down-up"></i>';
    if ($currentSort === $key) {
        $icon = (strtolower($currentDir) === 'asc') ? ' <i class="bi bi-caret-up-fill"></i>' : ' <i class="bi bi-caret-down-fill"></i>';
    }
    $aria = ($currentSort === $key)
      ? ' aria-label="Sorted '.$currentDir.'"'
      : ' aria-label="Sort by '.$label.'"';
    return '<a class="text-decoration-none text-nowrap"'.$aria.' href="?'.$qs.'" title="Sort by '.$label.'">'.$label.$icon.'</a>';
}

$page_title = ucfirst($status).' Clients';
include __DIR__ . '/../partials/partials_header.php';
?>
<style>
  /* বাংলা: কমপ্যাক্ট টেবিল + টোস্ট */
  .table-compact>:not(caption)>*>*{ padding:.45rem .6rem; }
  .table-compact th,.table-compact td{ vertical-align:middle; }
  .col-actions{ width:220px; white-space:nowrap; }
  .badge-pending{ background:#ffc107; color:#111; }
  .app-toast{
    position:fixed; left:50%; top:50%; transform:translate(-50%,-50%);
    z-index:9999; border:1px solid #cfd8e3; border-radius:12px;
    box-shadow:0 12px 30px rgba(0,0,0,.18); padding:12px 16px; min-width:260px; text-align:center;
    background:#fff; transition:opacity .25s, transform .25s;
  }
  .app-toast.success{ background:#e9f7ef; color:#155724; border-color:#c3e6cb; }
  .app-toast.error  { background:#fdecea; color:#721c24; border-color:#f5c6cb; }
  .app-toast.hide   { opacity:0; transform:translate(-50%,-60%); }
</style>

<div class="container-fluid p-4">
  <div class="d-flex align-items-center gap-2 flex-wrap mb-3">
    <h4 class="mb-0"><i class="bi bi-people"></i> <?= ucfirst($status) ?> Clients</h4>
    <span class="badge bg-secondary"><?= (int)$total ?></span>
    <div class="ms-auto"></div>
  </div>

  <!-- Filters -->
  <form class="card shadow-sm mb-3" method="get" action="">
    <div class="card-body">
      <div class="row g-2 align-items-end">
        <!-- keep sort/dir when filtering -->
        <input type="hidden" name="sort" value="<?= h($sort_key) ?>">
        <input type="hidden" name="dir"  value="<?= h(strtolower($dir_raw)) ?>">

        <div class="col-12 col-md-3">
          <label class="form-label">Search</label>
          <input name="q" value="<?= h($q) ?>" class="form-control form-control-sm" placeholder="Name / PPPoE / Client Code / Mobile / Address / IP">
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

        <div class="col-6 col-md-1">
          <label class="form-label">Per Page</label>
          <select name="limit" class="form-select form-select-sm">
            <?php foreach([10,20,30,50,100] as $L): ?>
              <option value="<?= $L ?>" <?= $limit==$L?'selected':'' ?>><?= $L ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-6 col-md-2">
          <label class="form-label"><?= ($status==='left'?'Left From':'Join From') ?></label>
          <input type="date" name="df" value="<?= h($date_from) ?>" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label"><?= ($status==='left'?'Left To':'Join To') ?></label>
          <input type="date" name="dt" value="<?= h($date_to) ?>" class="form-control form-control-sm">
        </div>

        <div class="col-6 col-md-2">
          <label class="form-label">Status</label>
          <select name="status" class="form-select form-select-sm">
            <?php foreach(['active','inactive','pending','expired','left'] as $s): ?>
              <option value="<?= $s ?>" <?= $status===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
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

        <!-- Reset -->
        <div class="col-6 col-md-2 d-grid">
          <a class="btn btn-outline-secondary btn-sm" href="?status=<?= h($status) ?>">
            <i class="bi bi-arrow-counterclockwise"></i> Reset
          </a>
        </div>
      </div>
    </div>
  </form>

  <!-- Bulk bar -->
  <div class="d-flex align-items-center gap-2 mb-2">
    <input type="checkbox" id="select-all">
    <label for="select-all" class="mb-0 me-2">Select All</label>
    <!-- বাংলা: দুটোই visible থাকবে; selection না থাকলে disabled -->
    <button id="bulk-left" class="btn btn-danger btn-sm" disabled><i class="bi bi-box-arrow-right"></i> Mark Left (Bulk)</button>
    <button id="bulk-undo" class="btn btn-success btn-sm" disabled><i class="bi bi-arrow-counterclockwise"></i> Undo Left (Bulk)</button>
    <span class="text-muted small" id="sel-counter">(0 selected)</span>
  </div>

  <!-- Table -->
  <div class="card shadow-sm">
    <div class="table-responsive">
      <table class="table table-hover table-compact align-middle mb-0">
        <thead class="table-dark">
          <tr>
            <th style="width:34px;"><input type="checkbox" id="select-all-2"></th>
            <th><?= build_sort_link('#', 'id', $sort_key, strtolower($dir_raw)); ?></th>
            <th><?= build_sort_link('Name', 'name', $sort_key, strtolower($dir_raw)); ?></th>
            <th class="d-none d-md-table-cell"><?= build_sort_link('PPPoE', 'pppoe', $sort_key, strtolower($dir_raw)); ?></th>
            <th class="d-none d-lg-table-cell"><?= build_sort_link('Package', 'package', $sort_key, strtolower($dir_raw)); ?></th>
            <th><?= build_sort_link('Mobile', 'mobile', $sort_key, strtolower($dir_raw)); ?></th>
            <th class="d-none d-lg-table-cell"><?= build_sort_link('Status', 'status', $sort_key, strtolower($dir_raw)); ?></th>
            <?php if ($status === 'left'): ?>
              <th class="d-none d-xl-table-cell"><?= build_sort_link('Left At', 'left_at', $sort_key, strtolower($dir_raw)); ?></th>
            <?php else: ?>
              <th class="d-none d-xl-table-cell"><?= build_sort_link('Ledger', 'ledger', $sort_key, strtolower($dir_raw)); ?></th>
            <?php endif; ?>
            <th class="text-end col-actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($rows): foreach($rows as $r): ?>
          <tr>
            <td><input type="checkbox" class="row-check" value="<?= (int)$r['id'] ?>"></td>
            <td><?= (int)$r['id'] ?></td>
            <td>
              <div class="fw-semibold text-truncate" style="max-width:220px;"><?= h($r['name']) ?></div>
              <div class="text-muted small d-md-none">PPPoE: <?= h($r['pppoe_id']) ?></div>
            </td>
            <td class="d-none d-md-table-cell"><?= h($r['pppoe_id']) ?></td>
            <td class="d-none d-lg-table-cell"><?= h($r['package_name'] ?? '—') ?></td>
            <td><a class="text-decoration-none" href="tel:<?= h($r['mobile']) ?>"><?= h($r['mobile']) ?></a></td>
            <td class="d-none d-lg-table-cell">
              <?php
                $st = strtolower((string)$r['status']);
                $cls = $st==='inactive'?'bg-danger':($st==='pending'?'badge-pending':'bg-success');
              ?>
              <span class="badge <?= $cls ?>"><?= ucfirst((string)$r['status']) ?></span>
              <?php if ((int)$r['is_left']===1): ?>
                <span class="badge bg-secondary">Left</span>
              <?php endif; ?>
            </td>
            <?php if ($status === 'left'): ?>
              <td class="d-none d-xl-table-cell"><?= h($r['left_at']) ?></td>
            <?php else: ?>
              <td class="d-none d-xl-table-cell">
                <?php
                  $lb = (float)($r['ledger_balance'] ?? 0);
                  $bcls = $lb > 0 ? 'bg-danger' : ($lb < 0 ? 'bg-success' : 'bg-secondary');
                ?>
                <span class="badge <?= $bcls ?>"><?= number_format($lb, 2) ?></span>
              </td>
            <?php endif; ?>
            <td class="text-end">
              <div class="btn-group btn-group-sm" role="group">
                <a href="client_view.php?id=<?= (int)$r['id'] ?>" class="btn btn-outline-primary" title="View"><i class="bi bi-eye"></i></a>
                <a href="client_edit.php?id=<?= (int)$r['id'] ?>" class="btn btn-outline-secondary" title="Edit"><i class="bi bi-pencil-square"></i></a>
                <?php if ((int)$r['is_left']===1): ?>
                  <button class="btn btn-success" title="Undo Left" onclick="toggleLeft(<?= (int)$r['id'] ?>,'undo', this)"><i class="bi bi-arrow-counterclockwise"></i></button>
                <?php else: ?>
                  <button class="btn btn-danger" title="Mark Left" onclick="toggleLeft(<?= (int)$r['id'] ?>,'left', this)"><i class="bi bi-box-arrow-right"></i></button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; else: ?>
            <!-- বাংলা: মোট ৯টি কলাম (checkbox, id, name, pppoe, package, mobile, status, left_at/ledger, actions) -->
            <tr><td colspan="9" class="text-center text-muted py-4">No data found</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Pagination (window max 5 pages) -->
  <?php if($total_pages>1): ?>
    <nav class="mt-3">
      <ul class="pagination pagination-sm justify-content-center">
        <?php
          $qsPrev = $_GET; $qsPrev['page'] = max(1,$page-1);
          $qsNext = $_GET; $qsNext['page'] = min($total_pages,$page+1);
        ?>
        <li class="page-item <?= $page<=1?'disabled':'' ?>">
          <a class="page-link" href="?<?= http_build_query($qsPrev) ?>">Previous</a>
        </li>
        <?php
          $start = max(1,$page-2); $end=min($total_pages,$page+2);
          if(($end-$start)<4){ $end=min($total_pages,$start+4); $start=max(1,$end-4); }
          for($i=$start;$i<=$end;$i++):
            $qsi = $_GET; $qsi['page']=$i; ?>
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

<script>
/* ========= Left / Undo Left (single + bulk) ========= */
const API_LEFT_SINGLE = '../api/client_left_toggle.php';
const API_LEFT_BULK   = '../api/client_left_bulk.php';

function showToast(msg,type='success',ms=2800){
  const t=document.createElement('div'); t.className='app-toast '+(type==='success'?'success':'error');
  t.textContent=msg||'Done'; document.body.appendChild(t);
  setTimeout(()=>t.classList.add('hide'), ms-250); setTimeout(()=>t.remove(), ms);
}

async function toggleLeft(id, action, btn){
  if(!id) return;
  const confirmMsg = action==='left' ? 'Mark as LEFT?' : 'Undo LEFT?';
  if(!confirm(confirmMsg)) return;
  if(btn) btn.disabled = true;

  try{
    const res = await fetch(API_LEFT_SINGLE, {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ id, action })
    });
    const data = await res.json();
    if(data.status==='success'){
      showToast(data.message,'success');
      location.reload();
    }else{
      showToast(data.message||'Failed','error');
      if(btn) btn.disabled=false;
    }
  }catch(e){
    showToast('Request failed','error');
    if(btn) btn.disabled=false;
  }
}

// bulk selection
const setSel = new Set();
const boxAll = document.getElementById('select-all');
const boxAll2= document.getElementById('select-all-2');
const boxes  = () => Array.from(document.querySelectorAll('.row-check'));
const btnLeft= document.getElementById('bulk-left');
const btnUndo= document.getElementById('bulk-undo');
const counter= document.getElementById('sel-counter');

function syncSel(){
  const n=setSel.size;
  counter.textContent=`(${n} selected)`;
  const dis=(n===0); [btnLeft,btnUndo].forEach(b=> b && (b.disabled=dis));
  const allLen = boxes().length;
  [boxAll, boxAll2].forEach(el=>{
    if(!el) return;
    if(n===0){ el.indeterminate=false; el.checked=false; }
    else if(n===allLen){ el.indeterminate=false; el.checked=true; }
    else { el.indeterminate=true; el.checked=false; }
  });
}
boxes().forEach(b=> b.addEventListener('change', ()=>{ if(b.checked) setSel.add(b.value); else setSel.delete(b.value); syncSel(); }));
[boxAll, boxAll2].forEach(el=>{
  el?.addEventListener('change', ()=>{
    const c=el.checked; boxes().forEach(b=>{ b.checked=c; if(c) setSel.add(b.value); else setSel.delete(b.value); });
    syncSel();
  });
});
syncSel();

async function bulkLeft(action){
  const ids = Array.from(setSel).map(v=>parseInt(v,10)).filter(Boolean);
  if(!ids.length) return;
  const msg = action==='left' ? `Mark LEFT — ${ids.length}?` : `Undo LEFT — ${ids.length}?`;
  if(!confirm(msg)) return;

  try{
    const res = await fetch(API_LEFT_BULK, {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ ids, action })
    });
    const data = await res.json();
    if(data.status==='success'){
      showToast(data.message||'Done','success');
      location.reload();
    }else{
      showToast(data.message||'Failed','error');
    }
  }catch(e){ showToast('Request failed','error'); }
}
btnLeft?.addEventListener('click', ()=> bulkLeft('left'));
btnUndo?.addEventListener('click', ()=> bulkLeft('undo'));

// বাংলা: আগের ভার্সনে ট্যাবভিত্তিক hide ছিল—এখন আর hide করছিনা।
</script>

<?php include __DIR__ . '/../partials/partials_footer.php'; ?>
