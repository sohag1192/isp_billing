<?php
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

$q = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? ''); // Active / Inactive / Pending

/* ---------- Advanced filters (optional) ---------- */
$package_id = (int)($_GET['package_id'] ?? 0);
$router_id  = (int)($_GET['router_id']  ?? 0);
$area       = trim($_GET['area'] ?? '');

$join_from  = trim($_GET['join_from'] ?? '');
$join_to    = trim($_GET['join_to']   ?? '');
$exp_from   = trim($_GET['exp_from']  ?? '');
$exp_to     = trim($_GET['exp_to']    ?? '');

$re_date = '/^\d{4}-\d{2}-\d{2}$/';
if ($join_from && !preg_match($re_date, $join_from)) $join_from = '';
if ($join_to   && !preg_match($re_date, $join_to))   $join_to   = '';
if ($exp_from  && !preg_match($re_date, $exp_from))  $exp_from  = '';
if ($exp_to    && !preg_match($re_date, $exp_to))    $exp_to    = '';

$adv_active = ($package_id>0 || $router_id>0 || $area!=='' || $join_from!=='' || $join_to!=='' || $exp_from!=='' || $exp_to!=='');

/* ---------- Sorting (URL: ?sort=name&dir=asc) ---------- */
$sort    = strtolower($_GET['sort'] ?? 'id');   // id, name, pppoe, code, mobile, status
$dirRaw  = strtolower($_GET['dir']  ?? 'desc'); // asc | desc
$dirRaw  = in_array($dirRaw, ['asc','desc'], true) ? $dirRaw : 'desc';

$sortable = [
  'id'     => 'id',
  'name'   => 'name',
  'pppoe'  => 'pppoe_id',
  'code'   => 'client_code',
  'mobile' => 'mobile',
  'status' => 'status'
];
if (!isset($sortable[$sort])) $sort = 'id';
$dirSql = ($dirRaw === 'asc') ? 'ASC' : 'DESC';
$order  = $sortable[$sort] . ' ' . $dirSql;

/* ---------- Sortable header link (preserve all GET params) ---------- */
function sort_link($key, $label, $q, $status, $currentSort, $currentDirRaw){
    $qs = $_GET; // সব বর্তমান GET carry করো
    $nextDir = ($currentSort === $key && $currentDirRaw === 'asc') ? 'desc' : 'asc';
    $qs['sort'] = $key;
    $qs['dir']  = $nextDir;
    $href = '?' . http_build_query($qs);

    // দিক–আইকন: asc => caret-up, desc => caret-down, অন্যথায় neutral
    if ($currentSort === $key) {
        $arrow = ($currentDirRaw === 'asc')
               ? ' <i class="bi bi-caret-up-fill"></i>'
               : ' <i class="bi bi-caret-down-fill"></i>';
    } else {
        $arrow = ' <i class="bi bi-arrow-down-up"></i>';
    }
    return '<a class="text-decoration-none" href="'.$href.'">'.$label.$arrow.'</a>';
}

/* ---------- Query build ---------- */
$sql = "SELECT id, name, pppoe_id, client_code, mobile, status FROM clients WHERE 1";
$params = [];

/* Search */
if ($q !== '') {
  $like = "%$q%";
  $sql .= " AND (name LIKE ? OR pppoe_id LIKE ? OR client_code LIKE ? OR mobile LIKE ?)";
  array_push($params, $like, $like, $like, $like);
}

/* Status (case-sensitive values as in your DB: Active/Inactive/Pending) */
if (in_array($status, ['Active','Inactive','Pending'], true)) {
  $sql .= " AND status = ?";
  $params[] = $status;
}

/* Advanced filters */
if ($package_id > 0) { $sql .= " AND package_id = ?";  $params[] = $package_id; }
if ($router_id  > 0) { $sql .= " AND router_id  = ?";  $params[] = $router_id; }
if ($area !== '')    { $sql .= " AND area       = ?";  $params[] = $area; }

if ($join_from !== '') { $sql .= " AND join_date   >= ?"; $params[] = $join_from; }
if ($join_to   !== '') { $sql .= " AND join_date   <= ?"; $params[] = $join_to;   }
if ($exp_from  !== '') { $sql .= " AND expiry_date >= ?"; $params[] = $exp_from;  }
if ($exp_to    !== '') { $sql .= " AND expiry_date <= ?"; $params[] = $exp_to;    }

/* ORDER BY (টাই-ব্রেকার হিসেবে id DESC) */
$sql .= " ORDER BY $order, id DESC LIMIT 500";

$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

/* Dropdown data for filters */
$packages = db()->query("SELECT id, name FROM packages ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$routers  = db()->query("SELECT id, name FROM routers  ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$areasRes = db()->query("SELECT DISTINCT area FROM clients WHERE area IS NOT NULL AND area<>'' ORDER BY area ASC")->fetchAll(PDO::FETCH_ASSOC);
$areas    = array_map(fn($r)=>$r['area'], $areasRes);

?>
<?php require_once __DIR__ . '/../partials/partials_header.php'; ?>

<div class="container-fluid">

  <!-- Header: title + add button -->
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h5 class="mb-0">All Clients</h5>
      <div class="text-muted small">Total: <?= count($rows) ?></div>
    </div>
    <a href="/public/client_add.php" class="btn btn-primary btn-sm">
      <i class="bi bi-plus-circle"></i> Add Client
    </a>
  </div>

  <!-- Search / Filters (flexible, mobile-friendly) -->
  <form class="card shadow-sm mb-3 filter-card" method="get" action="">
    <div class="card-body">
      <div class="row g-2 align-items-stretch">
        <!-- Search -->
        <div class="col-12 col-md">
          <label class="form-label mb-1">Search</label>
          <input name="q"
                 value="<?=htmlspecialchars($q)?>"
                 class="form-control form-control-sm"
                 placeholder="Name / PPPoE / Client Code / Mobile">
        </div>

        <!-- Quick: Submit -->
        <div class="col-6 col-md-auto d-grid">
          <label class="form-label mb-1 invisible d-none d-md-block">_</label>
          <button class="btn btn-dark btn-sm">
            <i class="bi bi-search"></i> Apply
          </button>
        </div>

        <!-- Toggle: Advanced -->
        <div class="col-6 col-md-auto d-grid">
          <label class="form-label mb-1 invisible d-none d-md-block">_</label>
          <button class="btn btn-outline-secondary btn-sm" type="button"
                  data-bs-toggle="collapse" data-bs-target="#advFilters"
                  aria-expanded="<?= $adv_active ? 'true':'false' ?>">
            <i class="bi bi-sliders"></i> Filters
            <?php if ($adv_active): ?><span class="badge bg-danger ms-1">ON</span><?php endif; ?>
          </button>
        </div>
      </div>

      <!-- Advanced (collapsible) -->
      <div class="collapse <?= $adv_active?'show':'' ?> mt-3" id="advFilters">
        <div class="row g-2">
          <!-- Status button group -->
          <div class="col-12">
            <label class="form-label mb-1">Status</label>
            <input type="hidden" name="status" id="statusInput" value="<?=htmlspecialchars($status)?>">
            <div class="btn-group w-100 flex-wrap" role="group" aria-label="Status filter">
              <button type="button"
                      class="btn btn-outline-secondary btn-sm mb-1 <?= $status===''?'active':'' ?>"
                      data-status="">
                <i class="bi bi-sliders"></i> Any
              </button>
              <button type="button"
                      class="btn btn-success btn-sm mb-1 <?= strcasecmp($status,'Active')===0?'active':'' ?>"
                      data-status="Active">
                <i class="bi bi-check-circle"></i> Active
              </button>
              <button type="button"
                      class="btn btn-danger btn-sm mb-1 <?= strcasecmp($status,'Inactive')===0?'active':'' ?>"
                      data-status="Inactive">
                <i class="bi bi-slash-circle"></i> Inactive
              </button>
              <button type="button"
                      class="btn btn-warning btn-sm mb-1 <?= strcasecmp($status,'Pending')===0?'active':'' ?>"
                      data-status="Pending">
                <i class="bi bi-question-circle"></i> Pending
              </button>
            </div>
          </div>

          <!-- Package -->
          <div class="col-6 col-md-3">
            <label class="form-label mb-1">Package</label>
            <select name="package_id" class="form-select form-select-sm">
              <option value="0">All</option>
              <?php foreach($packages as $pkg): ?>
                <option value="<?=$pkg['id']?>" <?=$package_id==(int)$pkg['id']?'selected':''?>>
                  <?=htmlspecialchars($pkg['name'])?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Router -->
          <div class="col-6 col-md-3">
            <label class="form-label mb-1">Router</label>
            <select name="router_id" class="form-select form-select-sm">
              <option value="0">All</option>
              <?php foreach($routers as $rt): ?>
                <option value="<?=$rt['id']?>" <?=$router_id==(int)$rt['id']?'selected':''?>>
                  <?=htmlspecialchars($rt['name'])?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Area -->
          <div class="col-6 col-md-3">
            <label class="form-label mb-1">Area</label>
            <select name="area" class="form-select form-select-sm">
              <option value="">All</option>
              <?php foreach($areas as $ar): ?>
                <option value="<?=htmlspecialchars($ar)?>" <?=$area===$ar?'selected':''?>><?=htmlspecialchars($ar)?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Join range -->
          <div class="col-6 col-md-3">
            <label class="form-label mb-1">Join (From)</label>
            <input type="date" name="join_from" value="<?=htmlspecialchars($join_from)?>" class="form-control form-control-sm">
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label mb-1">Join (To)</label>
            <input type="date" name="join_to" value="<?=htmlspecialchars($join_to)?>" class="form-control form-control-sm">
          </div>

          <!-- Expire range -->
          <div class="col-6 col-md-3">
            <label class="form-label mb-1">Expire (From)</label>
            <input type="date" name="exp_from" value="<?=htmlspecialchars($exp_from)?>" class="form-control form-control-sm">
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label mb-1">Expire (To)</label>
            <input type="date" name="exp_to" value="<?=htmlspecialchars($exp_to)?>" class="form-control form-control-sm">
          </div>

          <!-- Actions -->
          <div class="col-12 col-md-3 d-grid">
            <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-filter"></i> Apply Filters</button>
          </div>
          <div class="col-12 col-md-3 d-grid">
            <?php
              $base = $_GET;
              unset($base['q'],$base['status'],$base['package_id'],$base['router_id'],$base['area'],
                    $base['join_from'],$base['join_to'],$base['exp_from'],$base['exp_to']);
              $reset_qs = http_build_query($base);
            ?>
            <a class="btn btn-outline-secondary btn-sm" href="?<?=$reset_qs?>">
              <i class="bi bi-x-circle"></i> Reset
            </a>
          </div>
        </div>

        <!-- Active filters summary -->
        <?php
          $badges = [];
          if ($status!=='')     $badges[] = 'Status: '.htmlspecialchars($status);
          if ($package_id>0){ foreach($packages as $pkg){ if($pkg['id']==$package_id){ $badges[]='Package: '.htmlspecialchars($pkg['name']); break; } } }
          if ($router_id>0) { foreach($routers as $rt){ if($rt['id']==$router_id){ $badges[]='Router: '.htmlspecialchars($rt['name']); break; } } }
          if ($area!=='')       $badges[] = 'Area: '.htmlspecialchars($area);
          if ($join_from!=='')  $badges[] = 'Join ≥ '.htmlspecialchars($join_from);
          if ($join_to!=='')    $badges[] = 'Join ≤ '.htmlspecialchars($join_to);
          if ($exp_from!=='')   $badges[] = 'Expire ≥ '.htmlspecialchars($exp_from);
          if ($exp_to!=='')     $badges[] = 'Expire ≤ '.htmlspecialchars($exp_to);
        ?>
        <?php if (!empty($badges)): ?>
          <div class="mt-2 d-flex flex-wrap gap-2">
            <?php foreach($badges as $b): ?>
              <span class="badge rounded-pill text-bg-light border"><?=$b?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

      </div>
    </div>
  </form>

  <!-- Results -->
  <style>
    /* compact table + widths */
    :root { --mobile-col-w: 120px; }
    .table-compact>:not(caption)>*>*{ padding:.35rem .5rem; }
    .table-compact th, .table-compact td{ vertical-align:middle; }
    .w-min{ width:1%; white-space:nowrap; }
    .col-actions{ width:10px; }
    .col-id{ width:5px; }
    .col-status{ width:80px; }
    .col-name{ max-width:85px; }
    .col-pppoe{ max-width:85px; }
    .col-mobile{ width: var(--mobile-col-w); max-width: var(--mobile-col-w); }
    .col-mobile .truncate{ display:inline-block; max-width:100%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    table .d-sm-none.small { display:block !important; }
    thead th a{text-decoration:none; color:inherit;}
    thead th a:hover{text-decoration:underline;}

    /* Filter card spacing for mobile */
    .filter-card .form-label { font-weight: 500; }
    @media (max-width: 576px){
      .filter-card .row > [class*="col-"] { margin-bottom: 4px; }
    }
  </style>

  <div class="card shadow-sm">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover table-compact align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th class="col-id w-min">
                <?= sort_link('id', '#', $q, $status, $sort, $dirRaw) ?>
              </th>
              <th>
                <?= sort_link('name', 'Name', $q, $status, $sort, $dirRaw) ?>
              </th>
              <th>
                <?= sort_link('pppoe', 'PPPoE', $q, $status, $sort, $dirRaw) ?>
              </th>
              <th class="d-none d-md-table-cell">
                <?= sort_link('code', 'Client Code', $q, $status, $sort, $dirRaw) ?>
              </th>
              <th class="d-none d-md-table-cell col-mobile">
                <?= sort_link('mobile', 'Mobile', $q, $status, $sort, $dirRaw) ?>
              </th>
              <th class="col-status w-min">
                <?= sort_link('status', 'Status', $q, $status, $sort, $dirRaw) ?>
              </th>
              <th class="text-end col-actions w-min">Action</th>
            </tr>
          </thead>

          <tbody>
            <?php foreach($rows as $r): ?>
              <tr>
                <td class="w-min"><?=intval($r['id'])?></td>
                <td>
                  <span class="d-inline-block text-truncate col-name">
                    <?=htmlspecialchars($r['name'])?>
                  </span>
                </td>
                <td>
                  <span class="d-inline-block text-truncate col-pppoe">
                    <?=htmlspecialchars($r['pppoe_id'])?>
                  </span>
                </td>
                <td class="d-none d-md-table-cell"><?=htmlspecialchars($r['client_code'])?></td>
                <td class="d-none d-md-table-cell col-mobile">
                  <span class="truncate"><?=htmlspecialchars($r['mobile'])?></span>
                </td>
                <td class="w-min">
                  <span class="badge <?=$r['status']==='Inactive'?'bg-danger':($r['status']==='Pending'?'bg-warning text-dark':'bg-success')?>">
                    <?=$r['status']?>
                  </span>
                </td>
                <td class="text-end w-min">
                  <div class="btn-group btn-group-sm" role="group">
                    <a class="btn btn-outline-primary" href="/public/client_view.php?id=<?=$r['id']?>" title="View">
                      <i class="bi bi-eye"></i><span class="d-none d-lg-inline"> View</span>
                    </a>
                    <a class="btn btn-outline-secondary" href="/public/client_edit.php?id=<?=$r['id']?>" title="Edit">
                      <i class="bi bi-pencil-square"></i><span class="d-none d-lg-inline"> Edit</span>
                    </a>
                  </div>
                </td>
              </tr>
            <?php endforeach; if(empty($rows)): ?>
              <tr><td colspan="7" class="text-center text-muted py-4">কিছু পাওয়া যায়নি</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- JS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
<script>
// status button group -> hidden input
document.querySelectorAll('button[data-status]').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const val = btn.getAttribute('data-status');
    document.getElementById('statusInput').value = val;
    btn.parentElement.querySelectorAll('button[data-status]').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
  });
});

// auto-expand collapse if filters are active (fallback + bootstrap)
document.addEventListener('DOMContentLoaded', function(){
  var advActive = <?= $adv_active ? 'true' : 'false' ?>;
  var el = document.getElementById('advFilters');
  if (!el) return;
  if (advActive && window.bootstrap && bootstrap.Collapse){
    new bootstrap.Collapse(el, {toggle: true});
  } else if (advActive) {
    el.classList.add('show');
  }
});
</script>

<?php require_once __DIR__ . '/../partials/partials_footer.php'; ?>
