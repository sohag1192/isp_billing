<?php
// /public/clients.php (schema-aware list + filters + live online overlay)
// UI: English; Comments: বাংলা

declare(strict_types=1);

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

/* ================== Bootstrap ================== */
$pdo = db(); // বাংলা নোট: একবারই PDO নিন
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------- Runtime column detection ---------- */
$clientCols = $pdo->query("SHOW COLUMNS FROM clients")->fetchAll(PDO::FETCH_COLUMN);
$hasOnline  = in_array('is_online',   $clientCols, true);
$hasLeft    = in_array('is_left',     $clientCols, true);
$hasArea    = in_array('area',        $clientCols, true);
$hasJoin    = in_array('join_date',   $clientCols, true);
$hasExpire  = in_array('expiry_date', $clientCols, true);

/* (বাংলা) প্যাকেজ/রাউটার টেবিলের নাম কলাম ডাইনামিকলি ঠিক করা */
$pkgCols = [];
try { $pkgCols = $pdo->query("SHOW COLUMNS FROM packages")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
$pkgNameParts = [];
foreach (['name','title','package_name'] as $c) { if (in_array($c,$pkgCols,true)) $pkgNameParts[] = "p.`$c`"; }
$PKG_NAME_EXPR = $pkgNameParts ? ('COALESCE('.implode(',', $pkgNameParts).')') : 'NULL';

$rtCols = [];
try { $rtCols = $pdo->query("SHOW COLUMNS FROM routers")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
$rtNameParts = [];
foreach (['name','identity','ip','host'] as $c) { if (in_array($c,$rtCols,true)) $rtNameParts[] = "`$c`"; }
$ROUTER_NAME_EXPR = $rtNameParts ? ('COALESCE('.implode(',', $rtNameParts).')') : 'id';

/* ================== Inputs ================== */
$status = $_GET['status'] ?? '';
$allowedStatus = ['','active','inactive','online','offline']; // বাংলা: স্ট্যাটাস স্যানিটাইজ
if (!in_array($status, $allowedStatus, true)) { $status = ''; }

$search = trim($_GET['search'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;

/* Live flag: live=1 => fetch from MikroTik PPP Active; live=0 => use DB only */
$live = (int)($_GET['live'] ?? 1); // বাংলা নোট: ডিফল্ট লাইভ অন

/* ---- Advanced filters ---- */
$package_id = (int)($_GET['package_id'] ?? 0);
$router_id  = (int)($_GET['router_id']  ?? 0);
$area       = trim($_GET['area'] ?? '');

$join_from  = trim($_GET['join_from'] ?? '');
$join_to    = trim($_GET['join_to']   ?? '');
$exp_from   = trim($_GET['exp_from']  ?? '');
$exp_to     = trim($_GET['exp_to']    ?? '');

$re_date = '/^\d{4}-\d{2}-\d{2}$/';
if (!preg_match($re_date, $join_from)) $join_from = '';
if (!preg_match($re_date, $join_to))   $join_to   = '';
if (!preg_match($re_date, $exp_from))  $exp_from  = '';
if (!preg_match($re_date, $exp_to))    $exp_to    = '';

/* ---- Sorting (?sort=name&dir=asc) ---- */
$sort   = strtolower($_GET['sort'] ?? 'id');
$dirRaw = strtolower($_GET['dir']  ?? 'desc');
$dirRaw = in_array($dirRaw, ['asc','desc'], true) ? $dirRaw : 'desc';

$map = [
  'id'      => 'c.id',
  'code'    => 'c.client_code',
  'name'    => 'c.name',
  'pppoe'   => 'c.pppoe_id',
  'package' => $PKG_NAME_EXPR,   // (ডাইনামিক এক্সপ্রেশন)
  'status'  => 'c.status',
];
if ($hasJoin)   { $map['join']   = 'c.join_date'; }
if ($hasOnline) { $map['online'] = 'c.is_online'; }
if (!isset($map[$sort])) $sort = 'id';

$dirSql = ($dirRaw === 'asc') ? 'ASC' : 'DESC';
$order  = $map[$sort] . ' ' . $dirSql;

/* ---------- Sortable header link helper ---------- */
// বাংলা নোট: সব GET প্যারাম রেখে নির্দিষ্ট sort/dir/page আপডেট করি
function sort_link(string $key, string $label): string {
    $qs = $_GET;
    $currentSort = strtolower($qs['sort'] ?? 'id');
    $currentDir  = strtolower($qs['dir'] ?? 'desc');

    $qs['sort'] = $key;
    $qs['dir']  = ($currentSort === $key && $currentDir === 'asc') ? 'desc' : 'asc';
    $qs['page'] = 1;

    $href = '?' . http_build_query($qs);

    if ($currentSort === $key) {
        $arrow = ($currentDir === 'asc')
               ? ' <i class="bi bi-caret-up-fill"></i>'
               : ' <i class="bi bi-caret-down-fill"></i>';
    } else {
        $arrow = ' <i class="bi bi-arrow-down-up"></i>';
    }
    return '<a class="text-decoration-none" href="'.$href.'">'.$label.$arrow.'</a>';
}

/* ================== Query Build ================== */
$sql_base = "FROM clients c
             LEFT JOIN packages p ON c.package_id = p.id
             WHERE 1";
$params = [];

if ($hasLeft) {
    $sql_base .= " AND c.is_left = 0"; // বাংলা নোট: delete-এর বদলে left ফ্লো
}

/* Status quick filters (DB-level only) */
if ($status === 'active') {
    $sql_base .= " AND c.status = 'active'";
} elseif ($status === 'inactive') {
    $sql_base .= " AND c.status = 'inactive'";
} elseif ($status === 'online' && $hasOnline && !$live) {
    // বাংলা নোট: live=0 হলে DB is_online দিয়ে ফিল্টার; live=1 হলে পরে PHP-তে ফিল্টার করা যায়
    $sql_base .= " AND c.is_online = 1";
} elseif ($status === 'offline' && $hasOnline && !$live) {
    $sql_base .= " AND c.is_online = 0";
}

/* Basic search */
if ($search !== '') {
    $sql_base .= " AND (c.name LIKE ? OR c.pppoe_id LIKE ? OR c.mobile LIKE ?)";
    $like = "%$search%";
    array_push($params, $like, $like, $like);
}

/* Advanced filters */
if ($package_id > 0) { $sql_base .= " AND c.package_id = ?";  $params[] = $package_id; }
if ($router_id  > 0) { $sql_base .= " AND c.router_id  = ?";  $params[] = $router_id; }
if ($hasArea && $area !== '') { $sql_base .= " AND c.area = ?"; $params[] = $area; }

/* বাংলা নোট: ইনডেক্স বাঁচাতে DATE() এড়াই; DATETIME ধরে বাউন্ড সেট */
if ($hasJoin) {
  if ($join_from !== '') { $sql_base .= " AND c.join_date >= ?";   $params[] = $join_from . ' 00:00:00'; }
  if ($join_to   !== '') { $sql_base .= " AND c.join_date <= ?";   $params[] = $join_to   . ' 23:59:59'; }
}
if ($hasExpire) {
  if ($exp_from  !== '') { $sql_base .= " AND c.expiry_date >= ?"; $params[] = $exp_from  . ' 00:00:00'; }
  if ($exp_to    !== '') { $sql_base .= " AND c.expiry_date <= ?"; $params[] = $exp_to    . ' 23:59:59'; }
}

/* Count */
$stmt_count = $pdo->prepare("SELECT COUNT(*) ".$sql_base);
$stmt_count->execute($params);
$total_records = (int)$stmt_count->fetchColumn();
$total_pages   = $limit > 0 ? (int)ceil($total_records / $limit) : 1;

/* Data: (বাংলা) প্যাকেজ নামকে ডাইনামিক এক্সপ্রেশনে সিলেক্ট করি */
$sql = "SELECT c.*,
               {$PKG_NAME_EXPR} AS package_name
        ".$sql_base."
        ORDER BY $order, c.id DESC
        LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ================== LIVE online overlay (MikroTik PPP Active) ================== */
$liveOnlineMap = []; // [pppoe_id] => 1
$liveRouters = [];

if ($live && count($clients) > 0) {
    $routerIds = array_unique(array_values(array_filter(array_map(
        fn($c) => isset($c['router_id']) ? (int)$c['router_id'] : 0, $clients
    ))));
    if ($routerIds) {
        $in = implode(',', array_fill(0, count($routerIds), '?'));
        $rst = $pdo->prepare("SELECT id, ip, username, password, api_port FROM routers WHERE id IN ($in)");
        $rst->execute($routerIds);
        $liveRouters = $rst->fetchAll(PDO::FETCH_ASSOC);

        @require_once __DIR__ . '/../app/routeros_api.class.php';
        if (class_exists('RouterosAPI')) {
            foreach ($liveRouters as $rt) {
                $ip   = $rt['ip'] ?? '';
                $user = $rt['username'] ?? '';
                $pass = $rt['password'] ?? '';
                $port = (int)($rt['api_port'] ?? 8728) ?: 8728;
                if (!$ip || !$user) continue;

                try {
                    $API = new RouterosAPI();
                    $API->debug = false;
                    if (property_exists($API, 'timeout'))  $API->timeout  = 3;
                    if (property_exists($API, 'attempts')) $API->attempts = 1;

                    if (method_exists($API, 'connect') && $API->connect($ip, $user, $pass, $port)) {
                        if (method_exists($API, 'comm')) {
                            $res = $API->comm('/ppp/active/print', ['.proplist' => 'name']);
                        } else {
                            $API->write('/ppp/active/print');
                            $res = $API->read();
                        }
                        if (is_array($res)) {
                            foreach ($res as $row) {
                                if (!empty($row['name'])) $liveOnlineMap[(string)$row['name']] = 1;
                            }
                        }
                        $API->disconnect();
                    }
                } catch (Throwable $e) {
                    // বাংলা নোট: কোনো রাউটার না ধরলে চুপচাপ স্কিপ
                }
            }
        }
    }

    foreach ($clients as &$c) {
        $pppoe = (string)($c['pppoe_id'] ?? '');
        $c['_live_online'] = ($pppoe !== '' && isset($liveOnlineMap[$pppoe])) ? 1 : 0;
    }
    unset($c);

    if (!$hasOnline && ($status === 'online' || $status === 'offline')) {
        $want = ($status === 'online') ? 1 : 0;
        $clients = array_values(array_filter($clients, fn($c) => (int)($c['_live_online'] ?? 0) === $want));
        $total_records = count($clients);
        $total_pages   = 1;
        $page          = 1;
    }
}

/* Dropdown data (schema-aware names — ডাইনামিক এক্সপ্রেশন ইউজ) */
$packages = $pdo->query("SELECT id, ".($pkgNameParts ? ('COALESCE('.implode(',', array_map(fn($c)=>"`$c`",$pkgCols?array_intersect(['name','title','package_name'],$pkgCols):[])).')') : 'NULL')." AS name FROM packages ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$routers  = $pdo->query("SELECT id, ".($rtNameParts ? $ROUTER_NAME_EXPR : 'id')." AS name FROM routers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$areas = [];
if ($hasArea) {
  $areas = array_column(
    $pdo->query("SELECT DISTINCT area FROM clients WHERE area IS NOT NULL AND area<>'' ORDER BY area ASC")->fetchAll(PDO::FETCH_ASSOC),
    'area'
  );
}

/* UI: adv filter active? */
$adv_active = ($package_id>0 || $router_id>0 || ($hasArea && $area!=='') || ($hasJoin && ($join_from!=='' || $join_to!=='')) || ($hasExpire && ($exp_from!=='' || $exp_to!=='')));

/* ====== Page header include ====== */
$_active    = 'clients';         // বাংলা নোট: সাইডবার Active highlight
$page_title = 'All Clients';
require __DIR__ . '/../partials/partials_header.php';
?>
<div class="container-fluid">

  <!-- Header -->
  <div class="mb-2 d-flex flex-wrap align-items-center gap-2">
    <h4 class="mb-0">
      <?php
        if ($status === 'active')      echo "Active Clients";
        elseif ($status === 'inactive') echo "Inactive Clients";
        elseif ($status === 'online')   echo "Online Clients";
        elseif ($status === 'offline')  echo "Offline Clients";
        else                            echo "All Clients";
      ?>
    </h4>
    <span class="text-muted small">Total: <?= number_format($total_records) ?></span>

    <div class="ms-auto d-flex gap-2">
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
    </div>
  </div>

  <!-- Filter toolbar -->
  <form method="GET" class="filter-card card border-0 shadow-sm mb-3">
    <!-- keep sort/dir + live -->
    <?php if (!empty($_GET['sort'])): ?>
      <input type="hidden" name="sort" value="<?= htmlspecialchars($_GET['sort']) ?>">
    <?php endif; ?>
    <?php if (!empty($_GET['dir'])): ?>
      <input type="hidden" name="dir" value="<?= htmlspecialchars($_GET['dir']) ?>">
    <?php endif; ?>
    <input type="hidden" name="live" value="<?= (int)$live ?>">

    <div class="card-body pb-2">
      <div class="row g-2 align-items-stretch">
        <div class="col-12 col-md">
          <label class="form-label mb-1">Search</label>
          <div class="position-relative" id="search-group">
            <input type="text" name="search" id="search-input"
                   class="form-control form-control-sm"
                   placeholder="Name / PPPoE / Mobile"
                   value="<?= htmlspecialchars($search) ?>" autocomplete="off">
          </div>
        </div>

        <div class="col-6 col-md-auto d-grid">
          <label class="form-label mb-1 invisible d-none d-md-block">_</label>
          <button class="btn btn-primary btn-sm" type="submit">
            <i class="bi bi-search"></i> Apply
          </button>
        </div>
        <div class="col-6 col-md-auto d-grid">
          <label class="form-label mb-1 invisible d-none d-md-block">_</label>
          <button class="btn btn-outline-secondary btn-sm" type="button"
                  data-bs-toggle="collapse" data-bs-target="#advFilters" aria-expanded="<?= $adv_active?'true':'false' ?>">
            <i class="bi bi-sliders"></i> Filters
            <?php if ($adv_active): ?><span class="badge bg-danger ms-1">ON</span><?php endif; ?>
          </button>
        </div>
      </div>

      <!-- Advanced filters -->
      <div class="collapse <?= $adv_active?'show':'' ?> mt-3" id="advFilters">
        <div class="row g-2">
          <div class="col-6 col-md-3">
            <label class="form-label mb-1">Status</label>
            <select name="status" class="form-select form-select-sm">
              <?php
                $opts = [''=>'All','active'=>'Active','inactive'=>'Inactive'];
                if ($hasOnline || $live) { $opts['online']='Online'; $opts['offline']='Offline'; }
                foreach($opts as $k=>$v){
                  $sel = ($status===$k)?'selected':''; echo '<option value="'.htmlspecialchars($k).'" '.$sel.'>'.$v.'</option>';
                }
              ?>
            </select>
          </div>

          <div class="col-6 col-md-3">
            <label class="form-label mb-1">Package</label>
            <select name="package_id" class="form-select form-select-sm">
              <option value="0">All Packages</option>
              <?php foreach($packages as $pkg): ?>
                <option value="<?= (int)$pkg['id'] ?>" <?= $package_id==(int)$pkg['id']?'selected':'' ?>>
                  <?= htmlspecialchars($pkg['name'] ?? 'N/A') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-6 col-md-3">
            <label class="form-label mb-1">Router</label>
            <select name="router_id" class="form-select form-select-sm">
              <option value="0">All Routers</option>
              <?php foreach($routers as $rt): ?>
                <option value="<?= (int)$rt['id'] ?>" <?= $router_id==(int)$rt['id']?'selected':'' ?>>
                  <?= htmlspecialchars($rt['name'] ?? 'Router #'.(int)$rt['id']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <?php if ($hasArea): ?>
          <div class="col-6 col-md-3">
            <label class="form-label mb-1">Area</label>
            <select name="area" class="form-select form-select-sm">
              <option value="">All Areas</option>
              <?php foreach($areas as $ar): ?>
                <option value="<?= htmlspecialchars($ar) ?>" <?= $area===$ar?'selected':'' ?>>
                  <?= htmlspecialchars($ar) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>

          <?php if ($hasJoin): ?>
          <div class="col-6 col-md-3">
            <label class="form-label mb-1">Join (From)</label>
            <input type="date" name="join_from" value="<?= htmlspecialchars($join_from) ?>" class="form-control form-control-sm">
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label mb-1">Join (To)</label>
            <input type="date" name="join_to" value="<?= htmlspecialchars($join_to) ?>" class="form-control form-control-sm">
          </div>
          <?php endif; ?>

          <?php if ($hasExpire): ?>
          <div class="col-6 col-md-3">
            <label class="form-label mb-1">Expire (From)</label>
            <input type="date" name="exp_from" value="<?= htmlspecialchars($exp_from) ?>" class="form-control form-control-sm">
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label mb-1">Expire (To)</label>
            <input type="date" name="exp_to" value="<?= htmlspecialchars($exp_to) ?>" class="form-control form-control-sm">
          </div>
          <?php endif; ?>

          <div class="col-12 col-md-3 d-grid">
            <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-filter"></i> Apply Filters</button>
          </div>
          <div class="col-12 col-md-3 d-grid">
            <?php
              $base = $_GET;
              unset($base['status'],$base['search'],$base['package_id'],$base['router_id'],$base['area'],
                    $base['join_from'],$base['join_to'],$base['exp_from'],$base['exp_to'],$base['page']);
              $reset_qs = http_build_query($base);
            ?>
            <a class="btn btn-outline-secondary btn-sm" href="?<?= $reset_qs ?>">
              <i class="bi bi-x-circle"></i> Reset
            </a>
          </div>
        </div>

        <?php
          $badges = [];
          if ($status!=='')     $badges[] = 'Status: '.htmlspecialchars(ucfirst($status));
          if ($package_id>0)    { foreach($packages as $pkg){ if((int)$pkg['id']===$package_id){ $badges[]='Package: '.htmlspecialchars($pkg['name']??''); break; } } }
          if ($router_id>0)     { foreach($routers as $rt){ if((int)$rt['id']===$router_id){ $badges[]='Router: '.htmlspecialchars($rt['name']??('Router #'.$router_id)); break; } } }
          if ($hasArea && $area!=='')       $badges[] = 'Area: '.htmlspecialchars($area);
          if ($hasJoin && $join_from!=='')  $badges[] = 'Join ≥ '.htmlspecialchars($join_from);
          if ($hasJoin && $join_to!=='')    $badges[] = 'Join ≤ '.htmlspecialchars($join_to);
          if ($hasExpire && $exp_from!=='') $badges[] = 'Expire ≥ '.htmlspecialchars($exp_from);
          if ($hasExpire && $exp_to!=='')   $badges[] = 'Expire ≤ '.htmlspecialchars($exp_to);
        ?>
        <?php if (!empty($badges)): ?>
          <div class="mt-2 d-flex flex-wrap gap-2">
            <?php foreach($badges as $b): ?>
              <span class="badge rounded-pill text-bg-light border"><?= $b ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </form>

  <!-- Bulk action bar (+ Export) -->
  <div class="d-flex align-items-center gap-2 mt-2 flex-wrap">
    <button id="bulk-enable" class="btn btn-success btn-sm" disabled>Enable Selected</button>
    <button id="bulk-disable" class="btn btn-danger btn-sm" disabled>Disable Selected</button>
    <span class="text-muted small" id="sel-counter">(0 selected)</span>

    <?php
      $export_qs = http_build_query([
        'search'     => $search,
        'status'     => $status,
        'package_id' => $package_id,
        'router_id'  => $router_id,
        'area'       => $hasArea ? $area : '',
        'join_from'  => $hasJoin ? $join_from : '',
        'join_to'    => $hasJoin ? $join_to   : '',
        'exp_from'   => $hasExpire ? $exp_from : '',
        'exp_to'     => $hasExpire ? $exp_to   : '',
        'sort'       => $sort,
        'dir'        => $dirRaw,
        'live'       => $live,
      ]);
    ?>
    <a class="btn btn-outline-secondary btn-sm" href="export_clients.php?<?= $export_qs ?>">
      <i class="bi bi-filetype-csv"></i> Export CSV
    </a>
  </div>

  <!-- Table -->
  <?php $showOnlineCol = ($live || $hasOnline); ?>
  <div class="table-container table-responsive mt-3">
    <table class="table table-hover table-striped table-sm align-middle">
      <thead>
        <tr>
          <th style="width:32px;"><input type="checkbox" id="select-all"></th>
          <!-- <th><?= sort_link('code', 'Client Code') ?></th> -->
          <th><?= sort_link('name',   'Name') ?></th>
          <th><?= sort_link('pppoe',  'PPPoE ID') ?></th>
          <th><?= sort_link('package','Package') ?></th>
          <th><?= sort_link('status', 'Status') ?></th>
          <?php if ($showOnlineCol): ?><th><?= $hasOnline ? sort_link('online','Online') : 'Online' ?></th><?php endif; ?>
          <?php if ($hasJoin): ?><th><?= sort_link('join',   'Join Date') ?></th><?php endif; ?>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($clients): foreach ($clients as $client): ?>
        <tr>
          <td>
            <input type="checkbox" class="row-check"
                   value="<?= (int)$client['id']; ?>"
                   data-router="<?= (int)($client['router_id'] ?? 0); ?>">
          </td>

          <td><?= htmlspecialchars($client['name']); ?></td>

          <td>
            <a href="client_view.php?id=<?= (int)$client['id']; ?>"
               class="text-decoration-none"
               title="View: <?= (int)$client['id'] ?>">
              <?= htmlspecialchars($client['pppoe_id'] ?? '') ?>
            </a>
          </td>

          <td><?= htmlspecialchars($client['package_name'] ?? 'N/A'); ?></td>

          <td>
            <?php if (($client['status'] ?? '') === 'active'): ?>
              <span class="badge bg-success">Active</span>
            <?php elseif (($client['status'] ?? '') === 'inactive'): ?>
              <span class="badge bg-danger">Inactive</span>
            <?php else: ?>
              <span class="badge bg-secondary"><?= htmlspecialchars($client['status'] ?? 'N/A'); ?></span>
            <?php endif; ?>
          </td>

          <?php if ($showOnlineCol): ?>
          <td>
            <?php
              $onlineLive = isset($client['_live_online']) ? (int)$client['_live_online'] : null;
              if ($live && $onlineLive !== null) {
                  echo $onlineLive ? '<span class="badge bg-success">Online</span>' : '<span class="badge bg-secondary">Offline</span>';
              } elseif ($hasOnline && (int)($client['is_online'] ?? 0) === 1) {
                  echo '<span class="badge bg-success">Online</span>';
              } else {
                  echo '<span class="badge bg-secondary">Offline</span>';
              }
            ?>
          </td>
          <?php endif; ?>

          <?php if ($hasJoin): ?>
          <td><?= htmlspecialchars($client['join_date'] ?? ''); ?></td>
          <?php endif; ?>

          <td>
            <div class="btn-group btn-group-sm" role="group">
              <a href="client_view.php?id=<?= $client['pppoe_id']; ?>" class="btn btn-outline-primary" title="View Client">
                <i class="bi bi-eye"></i>
              </a>
              <a href="client_edit.php?id=<?= (int)$client['id']; ?>" class="btn btn-outline-primary" title="Edit Client">
                <i class="bi bi-pencil-square"></i>
              </a>
              <button class="btn btn-outline-primary" title="Send SMS" onclick="showToast('SMS dialog coming soon');">
                <i class="bi bi-envelope"></i>
              </button>
              <button class="btn btn-outline-primary" title="Change Package" onclick="bp2HandleBulkProfile()">
                <i class="bi bi-shuffle"></i>
              </button>

              <?php if (($client['status'] ?? '') === 'active'): ?>
                <button class="btn btn-sm btn-danger"
                        onclick="changeStatus(this, <?= (int)$client['id']; ?>, 'disable')"
                        title="Disable Client">
                  <i class="bi bi-x-square"></i>
                </button>
              <?php else: ?>
                <button class="btn btn-sm btn-success"
                        onclick="changeStatus(this, <?= (int)$client['id']; ?>, 'enable')"
                        title="Enable Client">
                  <i class="bi bi-file-check"></i>
                </button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="<?= 7 + (int)$showOnlineCol + (int)$hasJoin ?>" class="text-center text-muted">No clients found.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($total_pages > 1): ?>
    <nav class="mt-3">
      <ul class="pagination justify-content-center">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
          <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => max(1,$page - 1)])); ?>">Previous</a>
        </li>
        <?php
        $start = max(1, $page - 2);
        $end   = min($total_pages, $page + 2);
        if (($end - $start + 1) < 5) {
            if ($start == 1) { $end = min($total_pages, $start + 4); }
            elseif ($end == $total_pages) { $start = max(1, $end - 4); }
        }
        for ($i = $start; $i <= $end; $i++): ?>
          <li class="page-item <?= $i == $page ? 'active' : '' ?>">
            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?= $i; ?></a>
          </li>
        <?php endfor; ?>
        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
          <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => min($total_pages,$page + 1)])); ?>">Next</a>
        </li>
      </ul>
    </nav>
  <?php endif; ?>

</div><!-- /.container-fluid -->

<style>
/* (বাংলা) টেবিল/ফিল্টার লোকাল স্টাইল */
.table-container { background:#fff; padding:15px; border-radius:10px; box-shadow:0 2px 8px rgba(0,0,0,0.1); }
.table thead { background:#0d6efd; color:#fff; }
.badge{ font-size:.80rem; padding:4px 8px; }
.table td,.table th{ padding:6px 10px; line-height:1.2; vertical-align:middle; font-size:.9rem; }
.table-sm td,.table-sm th{ padding:4px 6px; }
thead th a{ text-decoration:none; color:inherit; }
thead th a:hover{ text-decoration:underline; }

.filter-card .form-label { font-weight: 500; }
@media (max-width: 576px){
  .filter-card .row > [class*="col-"] { margin-bottom: 4px; }
}

/* Suggest dropdown */
#search-group { position: relative; }
.suggest-box{
  position:absolute; left:0; right:0; top:100%;
  z-index:1060; background:#fff; border:1px solid rgba(0,0,0,.12); border-top:0;
  box-shadow:0 10px 24px rgba(0,0,0,.08);
  border-bottom-left-radius:10px; border-bottom-right-radius:10px;
  max-height:50vh; overflow:auto;
}
.suggest-item{ display:flex; justify-content:space-between; align-items:center; padding:8px 10px; cursor:pointer; border-top:1px solid #f2f2f2; font-size:14px; }
.suggest-item:first-child{ border-top:0; }
.suggest-item:hover, .suggest-item.active{ background:#f5f9ff; }
.suggest-left{ display:flex; gap:8px; align-items:center; }
.suggest-name{ font-weight:600; }
.suggest-meta{ color:#6c757d; font-size:12px; }

/* Toast + Confirm (centered solid) */
.app-toast{ position:fixed; left:50%; top:50%; transform:translate(-50%,-50%); z-index:9999; border:0; border-radius:120px; box-shadow:0 16px 40px rgba(0,0,0,.25); padding:14px 18px; min-width:280px; text-align:center; color:#fff; background:#0d6efd; transition:opacity .25s, transform .25s; }
.app-toast.success{ background:#198754; } .app-toast.error{ background:#dc3545; } .app-toast.hide{ opacity:0; transform:translate(-50%,-60%); }
.app-confirm-backdrop{ position:fixed; inset:0; background:rgba(0,0,0,.35); display:flex; align-items:center; justify-content:center; z-index:2000; }
.app-confirm-box{ background:#fff; border-radius:12px; width:360px; max-width:92vw; padding:18px; box-shadow:0 10px 30px rgba(0,0,0,.25); }
.app-confirm-title{ font-size:16px; font-weight:600; margin:0 0 6px; }
.app-confirm-text{ font-size:14px; color:#333; margin:0 0 14px; }
.app-confirm-actions{ display:flex; gap:8px; justify-content:center; }
.app-btn{ border:0; border-radius:8px; padding:8px 12px; font-size:14px; cursor:pointer; }
.app-btn.secondary{ background:#e9ecef; } .app-btn.primary{ background:#0d6efd; color:#fff; }
@media (max-width: 480px){ .app-confirm-actions{ flex-direction: column; gap: 10px; } .app-confirm-box .app-btn{ width: 100%; } }
</style>

<script>
/* ====== API endpoints ====== */
const API_SINGLE       = '../api/control.php';
const API_BULK         = '../api/bulk_control.php';
const API_BULK_PROFILE = '../api/bulk_profile.php';
const API_BULK_NOTIFY  = '../api/bulk_notify.php';
const API_SUGGEST      = '../api/suggest_clients.php';

/* ====== CSRF (optional) ====== */
const CSRF = window.CSRF_TOKEN || '';

/* ====== Toast ====== */
function showToast(message, type='success', timeout=3000){
  const box = document.createElement('div');
  box.className = 'app-toast ' + (type === 'success' ? 'success' : 'error');
  box.textContent = message || '';
  document.body.appendChild(box);
  setTimeout(()=> box.classList.add('hide'), timeout - 250);
  setTimeout(()=> box.remove(), timeout);
}

/* ====== Confirm ====== */
function customConfirm({title='Confirm', message='Are you sure?', okText='OK', cancelText='Cancel'}){
  return new Promise((resolve)=>{
    const bd = document.createElement('div');
    bd.className = 'app-confirm-backdrop';
    bd.innerHTML = `
      <div class="app-confirm-box" role="dialog" aria-modal="true">
        <div class="app-confirm-title">${title}</div>
        <div class="app-confirm-text">${message}</div>
        <div class="app-confirm-actions">
          <button class="app-btn secondary" data-act="cancel">Cancel</button>
          <button class="app-btn primary" data-act="ok">OK</button>
        </div>
      </div>`;
    document.body.appendChild(bd);
    const close=(v)=>{ document.removeEventListener('keydown', onKey); bd.remove(); resolve(v); };
    const onKey=(e)=>{ if(e.key==='Escape') close(false); if(e.key==='Enter') close(true); };
    bd.addEventListener('click', e=>{ if(e.target.dataset.act==='ok') close(true); if(e.target.dataset.act==='cancel'||e.target===bd) close(false); });
    document.addEventListener('keydown', onKey);
    setTimeout(()=> bd.querySelector('[data-act="ok"]')?.focus(), 10);
  });
}

/* ====== Single enable/disable ====== */
async function changeStatus(btn, id, action){
  const ok = await customConfirm({
    title: (action==='disable')?'Disable client?':'Enable client?',
    message: `Are you sure you want to ${action} this client?`,
    okText: (action==='disable')?'Disable':'Enable',
    cancelText: 'Cancel'
  });
  if (!ok) return;

  const oldHTML = btn.innerHTML; btn.disabled = true; btn.innerHTML = '...';

  fetch(`${API_SINGLE}?action=${encodeURIComponent(action)}&id=${encodeURIComponent(id)}`, {
    headers: CSRF ? {'X-CSRF-Token': CSRF} : {}
  })
    .then(r=>r.json())
    .then(data=>{
      if (data.status === 'success'){
        showToast(data.message || 'Success', 'success', 2500);
        setTimeout(()=> location.reload(), 800);
      } else {
        showToast(data.message || 'Operation failed', 'error', 3000);
        btn.disabled=false; btn.innerHTML=oldHTML;
      }
    })
    .catch(()=>{
      showToast('Request failed', 'error', 3000);
      btn.disabled=false; btn.innerHTML=oldHTML;
    });
}

/* ====== Bulk selection & actions ====== */
const sel = {
  set:new Set(), boxAll:null, boxes:[], btnE:null, btnD:null, counter:null,
  init(){
    this.boxAll  = document.getElementById('select-all');
    this.boxes   = Array.from(document.querySelectorAll('.row-check'));
    this.btnE    = document.getElementById('bulk-enable');
    this.btnD    = document.getElementById('bulk-disable');
    this.counter = document.getElementById('sel-counter');

    this.boxAll?.addEventListener('change', ()=>{
      const checked=this.boxAll.checked;
      this.boxes.forEach(b=>{ b.checked=checked; if(checked) this.set.add(b.value); else this.set.delete(b.value); });
      this.sync();
    });
    this.boxes.forEach(b=>{
      b.addEventListener('change', ()=>{
        if (b.checked) this.set.add(b.value); else this.set.delete(b.value);
        this.sync();
      });
    });

    this.btnE?.addEventListener('click', ()=> this.bulkEnableDisable('enable'));
    this.btnD?.addEventListener('click', ()=> this.bulkEnableDisable('disable'));

    this.sync();
  },
  selectedIds(){ return Array.from(this.set).map(v=>parseInt(v,10)).filter(Boolean); },
  sync(){
    const n=this.set.size;
    if (this.counter) this.counter.textContent=`(${n} selected)`;
    const dis=(n===0);
    [this.btnE,this.btnD].forEach(b=>{ if(b) b.disabled=dis; });
    if (this.boxAll){
      if(n===0){ this.boxAll.indeterminate=false; this.boxAll.checked=false; }
      else if(n===this.boxes.length){ this.boxAll.indeterminate=false; this.boxAll.checked=true; }
      else { this.boxAll.indeterminate=true; }
    }
  },
  async bulkEnableDisable(action){
    const ids=this.selectedIds(); if(!ids.length) return;
    const ok = await customConfirm({
      title:(action==='disable')?'Disable selected?':'Enable selected?',
      message:`Are you sure you want to ${action} ${ids.length} client(s)?`,
      okText:(action==='disable')?'Disable':'Enable', cancelText:'Cancel'
    });
    if(!ok) return;
    fetch('../api/bulk_control.php', {
      method:'POST',
      headers:{'Content-Type':'application/json', ...(CSRF? {'X-CSRF-Token': CSRF}: {})},
      body:JSON.stringify({ action, ids })
    }).then(r=>r.json()).then(data=>{
      if(data.status==='success'){
        const msg=`Done: ${data.succeeded}/${data.processed} succeeded` + (data.failed?`, ${data.failed} failed`:``);
        showToast(msg, data.failed?'error':'success', 2800);
        setTimeout(()=> location.reload(), 800);
      } else { showToast(data.message||'Bulk operation failed', 'error', 3500); }
    }).catch(()=> showToast('Bulk request failed', 'error', 3500));
  },
};
document.addEventListener('DOMContentLoaded', ()=> sel.init());

/* ====== Bulk profile change (stub) ====== */
function bp2HandleBulkProfile(){
  showToast('Bulk profile change UI coming soon', 'success', 2000);
}

/* ====== Suggestion dropdown ====== */
(function(){
  const box   = document.getElementById('search-input');
  const group = document.getElementById('search-group');
  if (!box || !group) return;

  const drop = document.createElement('div');
  drop.className = 'suggest-box d-none';
  group.appendChild(drop);

  let active=-1, lastQ='';

  const hide = ()=> drop.classList.add('d-none');
  const show = ()=> drop.classList.remove('d-none');
  const clear= ()=>{ drop.innerHTML=''; active=-1; };

  const esc = s => (s||'').replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m]));

  function render(list){
    clear();
    if (!list || !list.length){ hide(); return; }
    list.forEach(r=>{
      const el = document.createElement('div');
      el.className = 'suggest-item';
      el.innerHTML = `
        <div class="suggest-left">
          <span class="suggest-name">${esc(r.name||'Unknown')}</span>
          <span class="suggest-meta">(${esc(r.pppoe_id||'-')})</span>
        </div>
        <div class="suggest-meta">${esc(r.client_code||'')}${r.mobile?' • '+esc(r.mobile):''}</div>`;
      el.addEventListener('click', ()=>{ window.location.href = 'client_view.php?id=' + encodeURIComponent(r.id); });
      drop.appendChild(el);
    });
    show();
  }

  let t=null;
  async function fetchSuggest(q){
    const res = await fetch(`${API_SUGGEST}?q=${encodeURIComponent(q)}`, { credentials:'same-origin', headers: CSRF ? {'X-CSRF-Token': CSRF} : {} });
    if (!res.ok) throw new Error('HTTP '+res.status);
    return res.json();
  }

  function onInput(){
    const q = box.value.trim();
    if (q.length < 2){ clear(); hide(); return; }
    if (q === lastQ) return;
    lastQ = q;
    clearTimeout(t);
    t = setTimeout(async ()=>{
      try{
        const data = await fetchSuggest(q);
        if (box.value.trim() !== q) return;
        render(data.results || data.items || []);
      }catch(e){ clear(); hide(); }
    }, 200);
  }

  function onKey(e){
    if (drop.classList.contains('d-none')) return;
    const nodes = Array.from(drop.querySelectorAll('.suggest-item'));
    if (!nodes.length) return;

    if (e.key==='ArrowDown'){ e.preventDefault(); active=(active+1)%nodes.length; update(nodes); }
    else if (e.key==='ArrowUp'){ e.preventDefault(); active=(active-1+nodes.length)%nodes.length; }
    else if (e.key==='Enter' && active>=0){ e.preventDefault(); nodes[active].click(); }
    else if (e.key==='Escape'){ hide(); }
    update(nodes);
  }
  function update(nodes){
    nodes.forEach(n=>n.classList.remove('active'));
    if (active>=0 && active<nodes.length){
      nodes[active].classList.add('active');
      const el = nodes[active], top=el.offsetTop, bottom=top+el.offsetHeight;
      if (top < drop.scrollTop) drop.scrollTop = top;
      else if (bottom > drop.scrollTop + drop.clientHeight) drop.scrollTop = bottom - drop.clientHeight;
    }
  }

  document.addEventListener('click', e=>{ if (!group.contains(e.target)) hide(); });
  box.addEventListener('input', onInput);
  box.addEventListener('focus', onInput);
  box.addEventListener('keydown', onKey);
})();
</script>

<?php
require __DIR__ . '/../partials/partials_footer.php';
