<?php
// /public/suspended_clients.php
// Purpose: Nicely designed list + summary of auto-suspended clients
// Style: Procedural PHP + PDO; Code English, comments in Bangla
// Notes:
// - Schema-aware: prefers clients.suspend_by_billing=1; falls back to (status='inactive' AND ledger_balance>0)
// - Optional columns used if exist: suspend_by_billing, suspended_at, status, is_left
// - Sorting via ?sort=&dir= (ASC|DESC), Pagination via ?page=&per_page=
// - Filters: router, area, search (name/pppoe/mobile), date range (if suspended_at exists)
// - CSV export via ?export=1 (uses current filters)

declare(strict_types=1);
date_default_timezone_set('Asia/Dhaka');

$ROOT = dirname(__DIR__);
require_once $ROOT . '/app/db.php';

// (বাংলা) auth থাকলে লোড/প্রটেক্ট করো
$require = $ROOT . '/app/require_login.php';
if (is_file($require)) require_once $require;

// ---------- DB ----------
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ---------- Helpers ----------
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function col_exists(PDO $pdo, string $tbl, string $col): bool {
  $st = $pdo->prepare("SHOW COLUMNS FROM `$tbl` LIKE ?");
  $st->execute([$col]);
  return (bool)$st->fetchColumn();
}
function try_include_partial(string $path): bool {
  if (is_file($path)) { include $path; return true; }
  return false;
}

// ---------- Schema flags ----------
$has_status       = col_exists($pdo, 'clients', 'status');
$has_is_left      = col_exists($pdo, 'clients', 'is_left');
$has_sbf          = col_exists($pdo, 'clients', 'suspend_by_billing');
$has_suspended_at = col_exists($pdo, 'clients', 'suspended_at');

// ---------- Inputs ----------
$router_id  = isset($_GET['router_id']) ? (int)$_GET['router_id'] : 0;
$area       = trim((string)($_GET['area'] ?? ''));
$q          = trim((string)($_GET['q'] ?? ''));
$from       = trim((string)($_GET['from'] ?? ''));
$to         = trim((string)($_GET['to'] ?? ''));
$export     = (int)($_GET['export'] ?? 0);
$sort       = trim((string)($_GET['sort'] ?? 'suspended_at'));
$dir        = strtoupper(trim((string)($_GET['dir'] ?? 'DESC')));
$page       = max(1, (int)($_GET['page'] ?? 1));
$per_page   = min(200, max(10, (int)($_GET['per_page'] ?? 50)));

// ---------- Sorting whitelist ----------
$sortmap = [
  'name'          => 'c.name',
  'pppoe_id'      => 'c.pppoe_id',
  'router'        => 'r.name',
  'due'           => 'c.ledger_balance',
  'suspended_at'  => $has_suspended_at ? 'c.suspended_at' : 'c.updated_at',
  'updated_at'    => 'c.updated_at'
];
$order_by = $sortmap[$sort] ?? ($has_suspended_at ? 'c.suspended_at' : 'c.updated_at');
$dir = ($dir === 'ASC') ? 'ASC' : 'DESC';

// ---------- Filters -> WHERE ----------
$where = [];
$args  = [];

if ($has_is_left) $where[] = "COALESCE(c.is_left,0)=0";
$where[] = "COALESCE(c.pppoe_id,'')<>''";
$where[] = "c.router_id IS NOT NULL";

// (বাংলা) মূল শর্ত: auto-suspended—flag থাকলে সেটি, নাহলে fallback logic
if ($has_sbf) {
  $where[] = "COALESCE(c.suspend_by_billing,0)=1";
} else {
  $clause = "COALESCE(c.ledger_balance,0)>0";
  if ($has_status) $clause .= " AND c.status='inactive'";
  $where[] = $clause;
}

// router filter
if ($router_id > 0) { $where[]="c.router_id=?"; $args[]=$router_id; }
// area filter
if ($area !== '')   { $where[]="c.area=?";      $args[]=$area; }
// search filter (name/pppoe/mobile)
if ($q !== '') {
  $where[] = "(c.name LIKE ? OR c.pppoe_id LIKE ? OR c.mobile LIKE ?)";
  $args[] = "%{$q}%"; $args[] = "%{$q}%"; $args[] = "%{$q}%";
}
// date range (if suspended_at exists)
if ($has_suspended_at) {
  if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)) { $where[]="DATE(c.suspended_at) >= ?"; $args[]=$from; }
  if ($to   !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$to))   { $where[]="DATE(c.suspended_at) <= ?"; $args[]=$to; }
}

$where_sql = $where ? ("WHERE ".implode(" AND ", $where)) : "";

// ---------- Summary counts ----------
$cnt_total = (function() use ($pdo,$where_sql,$args) {
  $st = $pdo->prepare("SELECT COUNT(*) FROM clients c LEFT JOIN routers r ON r.id=c.router_id $where_sql");
  $st->execute($args);
  return (int)$st->fetchColumn();
})();
$cnt_today = 0;
if ($has_suspended_at) {
  $st = $pdo->prepare("SELECT COUNT(*) FROM clients c LEFT JOIN routers r ON r.id=c.router_id $where_sql AND DATE(c.suspended_at)=CURDATE()");
  $st->execute($args);
  $cnt_today = (int)$st->fetchColumn();
}

// router breakdown
$router_rows = (function() use ($pdo,$where_sql,$args) {
  $st = $pdo->prepare("
    SELECT r.id AS router_id, COALESCE(r.name,'(unknown)') AS router_name, COUNT(*) AS total
    FROM clients c
    LEFT JOIN routers r ON r.id=c.router_id
    $where_sql
    GROUP BY r.id, r.name
    ORDER BY total DESC, router_name ASC
    LIMIT 10
  ");
  $st->execute($args);
  return $st->fetchAll(PDO::FETCH_ASSOC);
})();

// areas for dropdown
$areas = (function() use ($pdo) {
  try {
    $st = $pdo->query("SELECT DISTINCT area FROM clients WHERE COALESCE(area,'')<>'' ORDER BY area ASC");
    return $st->fetchAll(PDO::FETCH_COLUMN);
  } catch (Throwable $e) { return []; }
})();
// routers for dropdown
$routers = (function() use ($pdo) {
  try {
    $st = $pdo->query("SELECT id, name FROM routers ORDER BY name ASC");
    return $st->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { return []; }
})();

// ---------- List query (paged) ----------
$offset = ($page - 1) * $per_page;

$sql = "
SELECT c.id, c.name, c.pppoe_id, c.mobile, c.area, c.router_id,
       COALESCE(c.ledger_balance,0) AS due,
       ".($has_status ? "c.status," : "'-' AS status,")."
       ".($has_suspended_at ? "c.suspended_at," : "NULL AS suspended_at,")."
       r.name AS router_name
FROM clients c
LEFT JOIN routers r ON r.id+c.router_id
$where_sql
ORDER BY $order_by $dir
LIMIT $per_page OFFSET $offset
";
$sql = str_replace('r.id+c.router_id', 'r.id=c.router_id', $sql); // safety (copy guard)
$st = $pdo->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// ---------- CSV export ----------
if ($export === 1) {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="auto_suspended_clients.csv"');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['ID','Name','PPPoE','Mobile','Area','Router','Due','Status','Suspended At']);
  foreach ($rows as $r) {
    fputcsv($out, [
      $r['id'], $r['name'], $r['pppoe_id'], $r['mobile'], $r['area'],
      $r['router_name'], $r['due'], $r['status'],
      $r['suspended_at'] ? $r['suspended_at'] : ''
    ]);
  }
  fclose($out);
  exit;
}

// ---------- Pagination calc ----------
$total_pages = max(1, (int)ceil($cnt_total / $per_page));

// ---------- Render ----------
$title = 'Auto-Suspended Clients';

// header include (fallback to minimal HTML if partial not found)
if (!try_include_partial($ROOT.'/partials/partials_header.php')) {
  ?><!doctype html><html lang="en"><head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=h($title)?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  </head><body class="bg-light"><?php
}
?>
<div class="container-fluid py-4">
  <!-- Title + Actions -->
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
    <h3 class="mb-0"><?=h($title)?></h3>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary" href="?<?=h(http_build_query(array_merge($_GET, ['export'=>1])))?>">
        Export CSV
      </a>
      <a class="btn btn-outline-primary" target="_blank" href="../cron/auto_suspend_enable.php?dry=1">
        Dry-run Auto Suspend/Enable
      </a>
    </div>
  </div>

  <!-- Summary cards -->
  <div class="row g-3 mb-3">
    <div class="col-12 col-sm-6 col-lg-3">
      <div class="card shadow-sm border-0">
        <div class="card-body">
          <div class="small text-muted">Total Suspended</div>
          <div class="display-6 fw-bold"><?=number_format($cnt_total)?></div>
        </div>
      </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
      <div class="card shadow-sm border-0">
        <div class="card-body">
          <div class="small text-muted">Suspended Today</div>
          <div class="display-6 fw-bold"><?=number_format($cnt_today)?></div>
          <?php if(!$has_suspended_at): ?>
            <div class="text-muted small">Info based on <code>suspended_at</code> column (optional)</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="col-12 col-lg-6">
      <div class="card shadow-sm border-0 h-100">
        <div class="card-body">
          <div class="small text-muted mb-2">Top Routers (by suspended count)</div>
          <div class="d-flex flex-wrap gap-2">
            <?php if ($router_rows): foreach ($router_rows as $rr): ?>
              <a class="badge text-bg-light border rounded-pill text-decoration-none"
                 href="?<?=h(http_build_query(array_merge($_GET, ['router_id'=>$rr['router_id'], 'page'=>1])))?>">
                <?=h($rr['router_name'])?> <span class="badge text-bg-danger ms-1"><?= (int)$rr['total'] ?></span>
              </a>
            <?php endforeach; else: ?>
              <span class="text-muted">No data</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Filters -->
  <form method="get" class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <div class="row g-3 align-items-end">
        <div class="col-12 col-md-3">
          <label class="form-label">Router</label>
          <select name="router_id" class="form-select">
            <option value="0">All routers</option>
            <?php foreach ($routers as $r): ?>
              <option value="<?=$r['id']?>" <?=$router_id===$r['id']?'selected':''?>><?=h($r['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-3">
          <label class="form-label">Area</label>
          <select name="area" class="form-select">
            <option value="">All areas</option>
            <?php foreach ($areas as $a): ?>
              <option value="<?=h($a)?>" <?=$area===$a?'selected':''?>><?=h($a)?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-3">
          <label class="form-label">Search</label>
          <input type="text" name="q" class="form-control" placeholder="Name / PPPoE / Mobile" value="<?=h($q)?>">
        </div>
        <?php if ($has_suspended_at): ?>
        <div class="col-6 col-md-1">
          <label class="form-label">From</label>
          <input type="date" name="from" class="form-control" value="<?=h($from)?>">
        </div>
        <div class="col-6 col-md-1">
          <label class="form-label">To</label>
          <input type="date" name="to" class="form-control" value="<?=h($to)?>">
        </div>
        <?php endif; ?>
        <div class="col-6 col-md-1">
          <label class="form-label">Per page</label>
          <input type="number" name="per_page" min="10" max="200" class="form-control" value="<?=h($per_page)?>">
        </div>
        <div class="col-6 col-md-1">
          <button class="btn btn-primary w-100">Filter</button>
        </div>
      </div>
    </div>
  </form>

  <!-- Table -->
  <div class="card border-0 shadow-sm">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <?php
            // (বাংলা) হেল্পার: sort link
            function sort_link($key, $label){
              $cur = $_GET; 
              $cur['sort']=$key; 
              $cur['dir'] = (($_GET['sort']??'')===$key && ($_GET['dir']??'DESC')==='ASC')?'DESC':'ASC';
              $icon = '';
              if (($_GET['sort']??'')===$key) $icon = (($_GET['dir']??'DESC')==='ASC')?'↑':'↓';
              return '<a class="text-decoration-none" href="?'.h(http_build_query($cur)).'">'.h($label).' '.h($icon).'</a>';
            }
          ?>
          <tr>
            <th>#</th>
            <th><?=sort_link('name','Client')?></th>
            <th><?=sort_link('pppoe_id','PPPoE')?></th>
            <th><?=sort_link('router','Router')?></th>
            <th>Area</th>
            <th><?=sort_link('due','Due')?></th>
            <?php if ($has_status): ?><th>Status</th><?php endif; ?>
            <th><?=sort_link('suspended_at','Suspended At')?></th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">No suspended clients.</td></tr>
          <?php else: foreach ($rows as $i=>$r): ?>
            <tr>
              <td><?= ($offset+$i+1) ?></td>
              <td class="fw-semibold"><?=h($r['name'])?></td>
              <td><span class="badge text-bg-dark"><?=h($r['pppoe_id'])?></span></td>
              <td><?=h($r['router_name'])?></td>
              <td><?=h($r['area'])?></td>
              <td>
                <?php $due = (float)$r['due']; ?>
                <span class="badge rounded-pill <?= $due>0?'text-bg-danger':'text-bg-success' ?>">
                  <?= number_format($due, 2) ?>
                </span>
              </td>
              <?php if ($has_status): ?>
                <td>
                  <?php $st = strtolower((string)$r['status']); ?>
                  <span class="badge <?= $st==='inactive'?'text-bg-warning':'text-bg-success' ?>"><?=h(ucfirst($st))?></span>
                </td>
              <?php endif; ?>
              <td><?= $r['suspended_at'] ? h($r['suspended_at']) : '—' ?></td>
              <td class="text-nowrap">
                <a class="btn btn-sm btn-outline-secondary" href="client_view.php?id=<?=$r['id']?>">View</a>

                <?php
                  // (বাংলা) Enable Now বাটন—flag থাকলে সরাসরি, নচেৎ fallback logic
                  $can_enable = $has_sbf ? true : ($has_status && strtolower((string)$r['status'])==='inactive' && ((float)$r['due'])>0);
                  if ($can_enable):
                ?>
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-success ms-1 btn-enable-now"
                    data-client-id="<?= (int)$r['id'] ?>"
                    data-pppoe-id="<?= h($r['pppoe_id']) ?>"
                  >Enable Now</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div class="card-footer d-flex justify-content-between align-items-center">
      <div class="text-muted small">Page <?=$page?> of <?=$total_pages?> • Total <?=$cnt_total?></div>
      <nav>
        <ul class="pagination mb-0">
          <?php
            $qbase = $_GET;
            $qbase['page']=1;                        $first = '?'.h(http_build_query($qbase));
            $qbase['page']=max(1,$page-1);           $prev  = '?'.h(http_build_query($qbase));
            $qbase['page']=min($total_pages,$page+1);$next  = '?'.h(http_build_query($qbase));
            $qbase['page']=$total_pages;             $last  = '?'.h(http_build_query($qbase));
          ?>
          <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="<?=$first?>">First</a></li>
          <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="<?=$prev?>">Prev</a></li>
          <li class="page-item <?= $page>=$total_pages?'disabled':'' ?>"><a class="page-link" href="<?=$next?>">Next</a></li>
          <li class="page-item <?= $page>=$total_pages?'disabled':'' ?>"><a class="page-link" href="<?=$last?>">Last</a></li>
        </ul>
      </nav>
    </div>
  </div>
</div>

<?php
// footer include
if (!try_include_partial($ROOT.'/partials/partials_footer.php')) {
  ?>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  </body></html>
  <?php
}
?>

<!-- (বাংলা) Enable Now — calls /api/control.php?action=enable&id=... (GET), expects JSON -->
<script>
// (বাংলা) ছোট্ট টোস্ট হেল্পার
function solidToast(msg, type='success') {
  try { if (window.showSolidToast) return window.showSolidToast(msg, type); } catch(e){}
  alert((type==='success'?'✅ ':'❗ ')+msg);
}

async function enableClientById(id) {
  const qs = new URLSearchParams({ action:'enable', id:String(id) });
  const resp = await fetch('/api/control.php?' + qs.toString(), {
    method: 'GET',
    credentials: 'same-origin',
    headers: { 'Accept': 'application/json,text/plain,*/*' }
  });
  const text = await resp.text();
  let data;
  try { data = JSON.parse(text); } catch(_) {
    throw new Error('API did not return JSON. ' + text.slice(0, 160));
  }
  if (!resp.ok || !data || data.status !== 'success') {
    throw new Error((data && data.message) ? data.message : 'Enable failed');
  }
  return data;
}

document.addEventListener('click', async function(e){
  const btn = e.target.closest('.btn-enable-now');
  if (!btn) return;

  const clientId = btn.getAttribute('data-client-id');
  const pppoeId  = btn.getAttribute('data-pppoe-id');

  if (!confirm(`Confirm enable PPPoE "${pppoeId}" now?`)) return;

  const prevHTML = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = 'Enabling...';

  try {
    const res = await enableClientById(clientId);
    solidToast(res.message || 'Enabled successfully', res.type || 'success');
    setTimeout(()=>location.reload(), 500);
  } catch(err) {
    console.error(err);
    solidToast(err.message || 'Failed', 'error');
    btn.disabled = false;
    btn.innerHTML = prevHTML;
  }
});
</script>
