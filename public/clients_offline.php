<?php
// /public/clients_offline.php
// Show ONLY offline clients.
// Live mode (default): compute via MikroTik /ppp/active/print
// Fallback (live=0): use DB is_online=0 (when column exists)

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/* ================== Bootstrap ================== */
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* runtime column detection */
$clientCols = $pdo->query("SHOW COLUMNS FROM clients")->fetchAll(PDO::FETCH_COLUMN);
$hasLeft    = in_array('is_left', $clientCols, true);
$hasOnline  = in_array('is_online', $clientCols, true);

/* ================== Inputs ================== */
$search = trim($_GET['search'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;

/* Live toggle: 1=PPP Active (default), 0=DB is_online */
$live = (int)($_GET['live'] ?? 1);

/* Sorting */
$sort   = strtolower($_GET['sort'] ?? 'name');
$dirRaw = strtolower($_GET['dir']  ?? 'asc');
$dirRaw = in_array($dirRaw, ['asc','desc'], true) ? $dirRaw : 'asc';

$map = [
  'name'    => 'c.name',
  'pppoe'   => 'c.pppoe_id',
  'package' => 'p.name',
  'area'    => 'c.area',
  'router'  => 'r.name',
  'join'    => 'c.join_date',
];
if (!isset($map[$sort])) $sort = 'name';
$dirSql = ($dirRaw==='asc') ? 'ASC' : 'DESC';
$order  = $map[$sort]." $dirSql, c.id ASC";

function sort_link($key,$label){
  $qs=$_GET;
  $currentSort = strtolower($qs['sort'] ?? '');
  $currentDir  = strtolower($qs['dir']  ?? 'asc');
  $qs['sort']=$key;
  $qs['dir'] = ($currentSort===$key && $currentDir==='asc')?'desc':'asc';
  $qs['page']=1;
  $href='?'.http_build_query($qs);
  $arrow = ($currentSort===$key)
           ? (($currentDir==='asc')?' <i class="bi bi-caret-up-fill"></i>':' <i class="bi bi-caret-down-fill"></i>')
           : ' <i class="bi bi-arrow-down-up"></i>';
  return '<a class="text-decoration-none" href="'.$href.'">'.$label.$arrow.'</a>';
}

/* ================== LIVE: fetch active PPP usernames ================== */
$activeUsers = []; // [pppoe_id => 1]
if ($live) {
    @require_once __DIR__ . '/../app/routeros_api.class.php';
    if (class_exists('RouterosAPI')) {
        $routers = $pdo->query("SELECT id, ip, username, password, api_port FROM routers")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($routers as $rt) {
            $ip   = $rt['ip'];
            $user = $rt['username'];
            $pass = $rt['password'];
            $port = (int)($rt['api_port'] ?: 8728);
            try{
                $API = new RouterosAPI();
                $API->debug = false;
                if (property_exists($API,'timeout'))  $API->timeout  = 3;
                if (property_exists($API,'attempts')) $API->attempts = 1;

                if ($API->connect($ip, $user, $pass, $port)) {
                    if (method_exists($API,'comm')) {
                        $res = $API->comm('/ppp/active/print', ['.proplist'=>'name']);
                    } else {
                        $API->write('/ppp/active/print');
                        $res = $API->read();
                    }
                    if (is_array($res)) {
                        foreach ($res as $row) {
                            if (!empty($row['name'])) {
                                $activeUsers[$row['name']] = 1;
                            }
                        }
                    }
                    $API->disconnect();
                }
            } catch (Throwable $e) {
                // skip
            }
        }
    }
}

/* ================== Query ================== */
$sql_base = "FROM clients c
             LEFT JOIN packages p ON p.id = c.package_id
             LEFT JOIN routers  r ON r.id = c.router_id
             WHERE 1";
$params = [];

if ($hasLeft) {
    $sql_base .= " AND c.is_left = 0";
}

if ($live) {
    if (!empty($activeUsers)) {
        $place = implode(',', array_fill(0, count($activeUsers), '?'));
        $sql_base .= " AND (c.pppoe_id IS NULL OR c.pppoe_id = '' OR c.pppoe_id NOT IN ($place))";
        $params = array_merge($params, array_keys($activeUsers));
    } else {
        // no active now => everyone (left excluded) is offline
    }
} else {
    if ($hasOnline) {
        $sql_base .= " AND c.is_online = 0";
    } else {
        $total_records = 0; $total_pages = 1; $rows = [];
        goto RENDER;
    }
}

/* Search */
if ($search !== '') {
    $sql_base .= " AND (c.name LIKE ? OR c.pppoe_id LIKE ? OR c.mobile LIKE ?)";
    $like = "%$search%";
    array_push($params, $like, $like, $like);
}

/* Count */
$stmt = $pdo->prepare("SELECT COUNT(*) ".$sql_base);
$stmt->execute($params);
$total_records = (int)$stmt->fetchColumn();
$total_pages   = $limit > 0 ? (int)ceil($total_records / $limit) : 1;

/* Data */
$sql = "SELECT c.id, c.name, c.pppoe_id, c.area, c.join_date,
               p.name AS package_name, r.name AS router_name
        ".$sql_base." ORDER BY $order LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

RENDER:
$_active    = 'clients';
$page_title = 'Offline Clients';
require __DIR__ . '/../partials/partials_header.php';
?>
<div class="container-fluid p-3 p-md-4">

  <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
    <div class="d-flex align-items-center gap-3">
      <h4 class="mb-0">Offline Clients</h4>
      <span class="text-muted small">Total: <?= number_format($total_records) ?></span>
    </div>

    <div class="d-flex align-items-center gap-2">
      <?php
        $qsLiveOn  = $_GET; $qsLiveOn['live']=1;  $qsLiveOn['page']=1;
        $qsLiveOff = $_GET; $qsLiveOff['live']=0; $qsLiveOff['page']=1;
      ?>
      <a href="?<?= http_build_query($qsLiveOn) ?>" class="btn btn-outline-success btn-sm <?= $live? 'active':'' ?>">
        <i class="bi bi-lightning-charge"></i> Live ON
      </a>
      <a href="?<?= http_build_query($qsLiveOff) ?>" class="btn btn-outline-secondary btn-sm <?= !$live? 'active':'' ?>">
        <i class="bi bi-lightning"></i> Live OFF
      </a>

      <!-- Auto-refresh toggle (60s) -->
      <div class="form-check form-switch ms-2">
        <input class="form-check-input" type="checkbox" id="autoRefreshToggle">
        <label class="form-check-label small" for="autoRefreshToggle">Auto refresh (60s)</label>
      </div>

      <a href="clients.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-people"></i> All Clients
      </a>
      <a href="clients_online.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-wifi"></i> Online Clients
      </a>
    </div>
  </div>

  <!-- Search bar -->
  <form method="GET" class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
      <div class="row g-2 align-items-stretch">
        <div class="col-12 col-md">
          <label class="form-label mb-1">Search</label>
          <input type="text" name="search" value="<?= h($search) ?>" class="form-control form-control-sm"
                 placeholder="Name / PPPoE / Mobile" autocomplete="off">
        </div>
        <div class="col-6 col-md-auto d-grid">
          <label class="form-label mb-1 invisible d-none d-md-block">_</label>
          <button class="btn btn-primary btn-sm" type="submit">
            <i class="bi bi-search"></i> Apply
          </button>
        </div>
        <div class="col-6 col-md-auto d-grid">
          <?php $reset = $_GET; unset($reset['search'],$reset['page']); ?>
          <label class="form-label mb-1 invisible d-none d-md-block">_</label>
          <a class="btn btn-outline-secondary btn-sm" href="?<?= http_build_query($reset) ?>">
            <i class="bi bi-x-circle"></i> Reset
          </a>
        </div>
      </div>
    </div>
    <!-- preserve sort/dir + live -->
    <?php if (!empty($_GET['sort'])): ?><input type="hidden" name="sort" value="<?= h($_GET['sort']) ?>"><?php endif; ?>
    <?php if (!empty($_GET['dir'])):  ?><input type="hidden" name="dir"  value="<?= h($_GET['dir'])  ?>"><?php endif; ?>
    <input type="hidden" name="live" value="<?= (int)$live ?>">
    <input type="hidden" name="page" value="1">
  </form>

  <div class="table-responsive" style="background:#fff;padding:12px;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,.08)">
    <table class="table table-hover table-striped table-sm align-middle">
      <thead>
        <tr>
          <th><?= sort_link('name','Name') ?></th>
          <th><?= sort_link('pppoe','PPPoE ID') ?></th>
          <th><?= sort_link('package','Package') ?></th>
          <th><?= sort_link('area','Area') ?></th>
          <th><?= sort_link('router','Router') ?></th>
          <th><?= sort_link('join','Join Date') ?></th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="7" class="text-center text-muted">No offline clients</td></tr>
        <?php else: foreach($rows as $c): ?>
          <tr>
            <td>
              <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#6c757d;margin-right:6px"></span>
              <a href="client_view.php?id=<?= (int)$c['id'] ?>" class="text-decoration-none"><?= h($c['name']) ?></a>
            </td>
            <td><?= h($c['pppoe_id']) ?></td>
            <td><?= h($c['package_name'] ?? 'N/A') ?></td>
            <td><?= h($c['area']) ?></td>
            <td><?= h($c['router_name']) ?></td>
            <td><?= h($c['join_date']) ?></td>
            <td>
              <div class="btn-group btn-group-sm">
                <a class="btn btn-outline-primary" href="client_view.php?id=<?= (int)$c['id'] ?>" title="View"><i class="bi bi-eye"></i></a>
                <a class="btn btn-outline-secondary" href="client_edit.php?id=<?= (int)$c['id'] ?>" title="Edit"><i class="bi bi-pencil-square"></i></a>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($total_pages > 1): ?>
    <nav class="mt-3">
      <ul class="pagination justify-content-center">
        <li class="page-item <?= $page<=1?'disabled':'' ?>">
          <a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>max(1,$page-1)])) ?>">Previous</a>
        </li>
        <?php
          $start=max(1,$page-2); $end=min($total_pages,$page+2);
          if(($end-$start+1)<5){
            if($start==1){ $end=min($total_pages,$start+4); }
            elseif($end==$total_pages){ $start=max(1,$end-4); }
          }
          for($i=$start;$i<=$end;$i++): ?>
            <li class="page-item <?= $i==$page?'active':'' ?>">
              <a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>$i])) ?>"><?= $i ?></a>
            </li>
        <?php endfor; ?>
        <li class="page-item <?= $page>=$total_pages?'disabled':'' ?>">
          <a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>min($total_pages,$page+1)])) ?>">Next</a>
        </li>
      </ul>
    </nav>
  <?php endif; ?>

</div>
<script>
/* Auto-refresh toggle (60s); state stored per-page in localStorage */
// বাংলা নোট: ট্যাব hidden হলে রিফ্রেশ স্কিপ
(function(){
  const KEY = 'auto_refresh:' + location.pathname;
  const REFRESH_MS = 60000;
  const toggle = document.getElementById('autoRefreshToggle');
  let timer = null;

  function apply(enabled){
    if (timer) { clearInterval(timer); timer = null; }
    if (enabled) {
      timer = setInterval(function(){
        if (document.hidden) return;
        location.reload();
      }, REFRESH_MS);
    }
  }

  const saved = localStorage.getItem(KEY);
  const enabled = saved === '1';
  if (toggle){
    toggle.checked = enabled;
    apply(enabled);
    toggle.addEventListener('change', function(){
      const on = toggle.checked;
      localStorage.setItem(KEY, on ? '1' : '0');
      apply(on);
    });
  }
})();
</script>
<?php require __DIR__ . '/../partials/partials_footer.php'; ?>
