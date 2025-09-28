<?php
// /public/people.php
// Unified Customers + Subscribers directory (schema-aware, UNION across tables if both exist)
// UI: English; Comments: বাংলা

declare(strict_types=1);
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------------- helpers ---------------- */
// (বাংলা) XSS সেফ আউটপুট
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
// (বাংলা) টেবিল আছে কিনা
function tbl_exists(PDO $pdo, string $t): bool{
  try{
    $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
    $st=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
    $st->execute([$db,$t]); return (bool)$st->fetchColumn();
  }catch(Throwable $e){ return false; }
}
// (বাংলা) টেবিলের কলাম লিস্ট
function cols(PDO $pdo, string $tbl): array{
  try{ return $pdo->query("SHOW COLUMNS FROM `$tbl`")->fetchAll(PDO::FETCH_COLUMN); }
  catch(Throwable $e){ return []; }
}
function q_scalar(PDO $pdo, string $sql, array $p=[], $def=0){
  $st=$pdo->prepare($sql); $st->execute($p); $v=$st->fetchColumn();
  return $v===false?$def:$v;
}
function nf0($n){ return number_format((float)$n,0,'.',','); }

// (বাংলা) যে কোন সোর্স টেবিলকে unified কলামে ম্যাপ
function map_table(PDO $pdo, string $tbl): array{
  $C = cols($pdo,$tbl);
  $pick = function(array $wanted) use ($C){
    foreach($wanted as $w){ if (in_array($w,$C,true)) return $w; }
    return null;
  };
  $m = [
    // unified => source column
    'id'            => $pick(['id','client_id','customer_id','sid']),
    'code'          => $pick(['client_code','customer_code','code']),
    'name'          => $pick(['name','full_name','customer_name','client_name']),
    'username'      => $pick(['pppoe_id','pppoe','username','user','login']),
    'phone'         => $pick(['mobile','phone','contact','phone_no']),
    'area'          => $pick(['area','zone','location']),
    'status'        => $pick(['status','is_active','active']),
    'online'        => $pick(['is_online','online','active']),
    'join_date'     => $pick(['join_date','created_at','registered_at','added_at','created']),
    'expiry_date'   => $pick(['expiry_date','expire_at']),
    'ledger_balance'=> $pick(['ledger_balance','balance']),
    'profile'       => $pick(['profile','package','plan']),
    'router'        => $pick(['router_id','router','nas_id','nas']),
    'created_at'    => $pick(['created_at','added_at','created']),
    'updated_at'    => $pick(['updated_at','modified_at','updated']),
  ];

  // (বাংলা) যদি নাম না থাকে, fallback করুন username/code/id
  if (!$m['name']) $m['name'] = $m['username'] ?: ($m['code'] ?: ($m['id'] ?: null));

  // (বাংলা) SELECT অংশ বানানো
  $pieces = [];
  foreach($m as $k=>$src){
    if ($src){ $pieces[] = "`$tbl`.`$src` AS `$k`"; }
    else     { $pieces[] = "NULL AS `$k`"; }
  }
  // মূল id আলাদা রাখলে ভালো লাগে
  $srcId = $m['id'] ?: 'NULL';
  $select = "SELECT '$tbl' AS src, `$tbl`.`$srcId` AS src_id, ".implode(', ', $pieces)." FROM `$tbl`";

  return ['select'=>$select, 'cols'=>$m, 'tbl'=>$tbl];
}

/* ---------------- detect sources ---------------- */
// (বাংলা) সম্ভাব্য টেবিল নাম; যে যতগুলো আছে সেগুলো নিন
$candidatesA = ['customers','clients','client'];            // customer-like
$candidatesB = ['subscribers','pppoe_users','pppoe_secrets']; // subscriber-like

$sources = [];
foreach(array_merge($candidatesA,$candidatesB) as $t){
  if (tbl_exists($pdo,$t)){
    $sources[] = map_table($pdo,$t);
  }
}
if (!$sources){
  $page_title = "People";
  require __DIR__ . '/../partials/partials_header.php';
  echo '<div class="container py-4"><div class="alert alert-warning">No compatible table found. Tried: <code>customers, clients, client, subscribers, pppoe_users, pppoe_secrets</code>.</div></div>';
  require __DIR__ . '/../partials/partials_footer.php';
  exit;
}

/* ---------------- inputs ---------------- */
$page_title = "People (Customers + Subscribers)";
$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';
$online = $_GET['online'] ?? '';
$area   = $_GET['area'] ?? '';
$profile= $_GET['profile'] ?? '';
$router = $_GET['router'] ?? '';
$src    = $_GET['src'] ?? ''; // (বাংলা) নির্দিষ্ট সোর্স টেবিল ফিল্টার করতে চাইলে

$sort   = $_GET['sort'] ?? 'name';
$dir    = strtolower($_GET['dir'] ?? 'asc')==='desc' ? 'DESC' : 'ASC';
$page   = max(1,(int)($_GET['page'] ?? 1));
$limit  = min(100, max(10,(int)($_GET['limit'] ?? 20)));
$offset = ($page-1)*$limit;
$asCsv  = isset($_GET['export']) && $_GET['export']==='csv';

/* ---------------- UNION source ---------------- */
$selects = array_column($sources,'select');
$unionSql = implode(' UNION ALL ', $selects); // (বাংলা) দুই/একাধিক টেবিল একসাথে
// wrap
$base = "FROM ($unionSql) U";

/* ---------------- filters ---------------- */
$where=[]; $args=[];
if ($src!==''){
  $where[] = "U.src = ?"; $args[] = $src;
}
if ($search!==''){
  $like = "%$search%";
  $parts = [];
  foreach(['name','username','phone','code','area'] as $c){ $parts[] = "U.`$c` LIKE ?"; $args[]=$like; }
  $where[] = '('.implode(' OR ', $parts).')';
}
if ($status!==''){
  $where[]="U.status = ?"; $args[]=$status;
}
if ($area!==''){
  $where[]="U.area = ?"; $args[]=$area;
}
if ($profile!==''){
  $where[]="U.profile = ?"; $args[]=$profile;
}
if ($router!==''){
  $where[]="U.router = ?"; $args[]=$router;
}
if ($online!==''){
  if ($online==='1'){ $where[]="U.online IN (1,'1','yes','online','up','active')"; }
  elseif($online==='0'){ $where[]="(U.online IS NULL OR U.online IN (0,'0','no','offline','down','inactive'))"; }
}
$wsql = $where ? ('WHERE '.implode(' AND ',$where)) : '';

/* ---------------- sorting (safelist) ---------------- */
$sortable = ['src','name','username','phone','area','status','online','join_date','expiry_date','ledger_balance','profile','router','created_at','updated_at'];
if (!in_array($sort,$sortable,true)) $sort='name';

/* ---------------- lookups for dropdowns ---------------- */
// (বাংলা) area/profile/router এর ডিস্টিংক্ট লিস্ট গুলো UNION থেকেই নিন
$areas = $pdo->query("SELECT area, COUNT(*) c FROM ($unionSql) U WHERE area IS NOT NULL AND area<>'' GROUP BY area ORDER BY c DESC")->fetchAll(PDO::FETCH_ASSOC);
$profiles = $pdo->query("SELECT profile, COUNT(*) c FROM ($unionSql) U WHERE profile IS NOT NULL AND profile<>'' GROUP BY profile ORDER BY c DESC")->fetchAll(PDO::FETCH_ASSOC);
$routers  = $pdo->query("SELECT router, COUNT(*) c FROM ($unionSql) U WHERE router IS NOT NULL AND router<>'' GROUP BY router ORDER BY c DESC")->fetchAll(PDO::FETCH_ASSOC);

/* ---------------- KPI / counts ---------------- */
$totalAll   = (int)q_scalar($pdo, "SELECT COUNT(*) $base");
$totalMatch = (int)q_scalar($pdo, "SELECT COUNT(*) $base $wsql", $args);
$onlineCnt  = (int)q_scalar($pdo, "SELECT COUNT(*) $base WHERE U.online IN (1,'1','yes','online','up','active')");
$activeCnt  = (int)q_scalar($pdo, "SELECT COUNT(*) $base WHERE U.status IN ('active','enabled',1,'1','yes')");

/* ---------------- data ---------------- */
$sql = "SELECT * $base $wsql ORDER BY U.`$sort` $dir LIMIT $limit OFFSET $offset";
$st = $pdo->prepare($sql); $st->execute($args);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

/* ---------------- CSV export ---------------- */
if ($asCsv){
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=people.csv');
  $out=fopen('php://output','w');
  if ($rows){
    fputcsv($out, array_keys($rows[0]));
    foreach($rows as $r) fputcsv($out, $r);
  }
  fclose($out); exit;
}
?>
<?php require __DIR__ . '/../partials/partials_header.php'; ?>

<style>
.slim-kpi{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px 14px;display:flex;gap:10px;align-items:flex-start;box-shadow:0 4px 12px rgba(16,24,40,.06);}
.slim-kpi .ico{width:38px;height:38px;border-radius:10px;display:grid;place-items:center;color:#fff;font-size:18px;flex:0 0 auto;}
.i-blue{background:#60a5fa;} .i-green{background:#34d399;} .i-rose{background:#f87171;} .i-cyan{background:#22d3ee;} .i-gray{background:#9ca3af;}
.slim-kpi .num{margin:0;font-weight:800;font-size:22px;line-height:1;} .slim-kpi .lbl{margin:0;font-size:13px;color:#4b5563;}
.grid-4{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.6rem;} @media(max-width:992px){.grid-4{grid-template-columns:repeat(2,1fr);}}
.table-sm td,.table-sm th{padding:.4rem .5rem;}
</style>

<div class="container-fluid py-2">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <h4 class="m-0">People <small class="text-muted">(Customers + Subscribers)</small></h4>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-success btn-sm" href="people.php?<?= h(http_build_query(array_merge($_GET, ['export'=>'csv']))) ?>"><i class="bi bi-filetype-csv"></i> Export CSV</a>
    </div>
  </div>

  <div class="grid-4 mb-2">
    <div class="slim-kpi"><div class="ico i-blue"><i class="bi bi-people"></i></div><div><p class="num"><?= nf0($totalAll) ?></p><p class="lbl">Total</p></div></div>
    <div class="slim-kpi"><div class="ico i-green"><i class="bi bi-check-circle"></i></div><div><p class="num"><?= nf0($activeCnt) ?></p><p class="lbl">Active</p></div></div>
    <div class="slim-kpi"><div class="ico i-cyan"><i class="bi bi-wifi"></i></div><div><p class="num"><?= nf0($onlineCnt) ?></p><p class="lbl">Online</p></div></div>
    <div class="slim-kpi"><div class="ico i-gray"><i class="bi bi-funnel"></i></div><div><p class="num"><?= nf0($totalMatch) ?></p><p class="lbl">Matching</p></div></div>
  </div>

  <form class="row g-2 align-items-end mb-2" method="get">
    <div class="col-lg-3">
      <label class="form-label">Search</label>
      <input class="form-control" name="search" value="<?= h($search) ?>" placeholder="name, PPPoE/user, phone, code, area...">
    </div>
    <div class="col-lg-2">
      <label class="form-label">Status</label>
      <select class="form-select" name="status">
        <option value="">All</option>
        <option value="active"   <?= $status==='active'?'selected':'' ?>>Active</option>
        <option value="inactive" <?= $status==='inactive'?'selected':'' ?>>Inactive</option>
        <option value="disabled" <?= $status==='disabled'?'selected':'' ?>>Disabled</option>
        <option value="pending"  <?= $status==='pending'?'selected':'' ?>>Pending</option>
      </select>
    </div>
    <div class="col-lg-2">
      <label class="form-label">Online</label>
      <select class="form-select" name="online">
        <option value="">All</option>
        <option value="1" <?= $online==='1'?'selected':'' ?>>Online</option>
        <option value="0" <?= $online==='0'?'selected':'' ?>>Offline</option>
      </select>
    </div>
    <div class="col-lg-2">
      <label class="form-label">Area</label>
      <select class="form-select" name="area">
        <option value="">All</option>
        <?php foreach($areas as $a): $val=(string)$a['area']; if($val==='') continue; ?>
          <option value="<?= h($val) ?>" <?= $area===$val?'selected':'' ?>><?= h($val) ?> (<?= (int)$a['c'] ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-lg-2">
      <label class="form-label">Profile/Plan</label>
      <select class="form-select" name="profile">
        <option value="">All</option>
        <?php foreach($profiles as $p): $val=(string)$p['profile']; if($val==='') continue; ?>
          <option value="<?= h($val) ?>" <?= $profile===$val?'selected':'' ?>><?= h($val) ?> (<?= (int)$p['c'] ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-lg-2">
      <label class="form-label">Router</label>
      <select class="form-select" name="router">
        <option value="">All</option>
        <?php foreach($routers as $rr): $val=(string)$rr['router']; if($val==='') continue; ?>
          <option value="<?= h($val) ?>" <?= $router===$val?'selected':'' ?>><?= h($val) ?> (<?= (int)$rr['c'] ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if (count($sources)>1): ?>
    <div class="col-lg-2">
      <label class="form-label">Source</label>
      <select class="form-select" name="src">
        <option value="">All</option>
        <?php foreach($sources as $S): ?>
          <option value="<?= h($S['tbl']) ?>" <?= $src===$S['tbl']?'selected':'' ?>><?= h($S['tbl']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div class="col-lg-2">
      <label class="form-label">Sort</label>
      <select class="form-select" name="sort">
        <?php foreach($sortable as $s): ?>
          <option value="<?= h($s) ?>" <?= $sort===$s?'selected':'' ?>><?= h(ucwords(str_replace('_',' ',$s))) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-lg-1">
      <label class="form-label">Dir</label>
      <select class="form-select" name="dir">
        <option value="asc"  <?= $dir==='ASC'?'selected':'' ?>>ASC</option>
        <option value="desc" <?= $dir==='DESC'?'selected':'' ?>>DESC</option>
      </select>
    </div>
    <div class="col-lg-12 d-flex gap-2">
      <button class="btn btn-primary"><i class="bi bi-search"></i> Apply</button>
      <a class="btn btn-outline-secondary" href="people.php"><i class="bi bi-x"></i> Reset</a>
      <a class="btn btn-outline-success" href="people.php?<?= h(http_build_query(array_merge($_GET, ['export'=>'csv']))) ?>"><i class="bi bi-filetype-csv"></i> Export CSV</a>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle">
      <thead class="table-light">
        <tr>
          <th>Code</th>
          <th>Name</th>
          <th>PPPoE/User</th>
          <th>Phone</th>
          <th>Area</th>
          <th>Status</th>
          <th>Online</th>
          <th>Balance</th>
          <th>Profile</th>
          <th>Router</th>
          <th>Expiry</th>
		  <th>Created</th> <!-- নতুন -->
          <th>Source</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($rows as $r): ?>
        <?php
          $st = strtolower((string)($r['status'] ?? ''));
          $stCls = $st==='active'?'success':($st==='pending'?'warning':(($st==='disabled'||$st==='inactive')?'secondary':'light'));
          $on = (string)($r['online'] ?? '');
          $isOn = in_array(strtolower($on),['1','yes','online','up','active'],true) || $on===1;
          $bal = (float)($r['ledger_balance'] ?? 0);
          $balCls = $bal>0?'danger':($bal<0?'success':'secondary');
        ?>
        <tr>
          <td><?= h($r['src_id'] ?? '') ?></td>
          <td><?= h($r['name'] ?? '') ?></td>
          <td><code><?= h($r['username'] ?? '') ?></code></td>
          <td><?= h($r['phone'] ?? '') ?></td>
          <td><?= h($r['area'] ?? '') ?></td>
          <td><span class="badge bg-<?= $stCls ?>"><?= h($r['status'] ?? '') ?></span></td>
          <td><span class="badge bg-<?= $isOn?'success':'danger' ?>"><?= $isOn?'Online':'Offline' ?></span></td>
          <td><span class="badge bg-<?= $balCls ?>"><?= nf0($bal) ?></span></td>
          <td><?= h($r['profile'] ?? '') ?></td>
          <td><?= h($r['router'] ?? '') ?></td>
          <td><small><?= h($r['expiry_date'] ?? '') ?></small></td>
		  <td><small><?= h($r['created_at'] ?? '') ?></small></td> <!-- নতুন -->
          <td><span class="badge bg-light text-dark"><?= h($r['src'] ?? '') ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php $pages=max(1,(int)ceil($totalMatch/$limit)); if($pages>1): ?>
  <nav>
    <ul class="pagination pagination-sm">
      <?php $q=$_GET; for($i=1;$i<=$pages;$i++): $q['page']=$i; $url='people.php?'.h(http_build_query($q)); ?>
        <li class="page-item <?= $i===$page?'active':'' ?>"><a class="page-link" href="<?= $url ?>"><?= $i ?></a></li>
      <?php endfor; ?>
    </ul>
  </nav>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../partials/partials_footer.php'; ?>
