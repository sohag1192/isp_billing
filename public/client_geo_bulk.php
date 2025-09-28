<?php
// /public/client_geo_bulk.php
// Bulk geo-tagging UI: তালিকা থেকে ক্লিক করে ক্লায়েন্টের ম্যাপ লোকেশন সেট/এডিট
// UI: English; Comments: বাংলা
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

$page_title = 'Bulk Set Client Locations';
require_once __DIR__ . '/../partials/partials_header.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------------- helpers ---------------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function tbl_exists(PDO $pdo, string $t): bool {
  try {$db=$pdo->query('SELECT DATABASE()')->fetchColumn();
       $st=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
       $st->execute([$db,$t]); return (bool)$st->fetchColumn();
  } catch(Throwable $e){ return false; }
}
function col_exists(PDO $pdo, string $tbl, string $col): bool {
  try { $st=$pdo->prepare("SHOW COLUMNS FROM `$tbl` LIKE ?"); $st->execute([$col]); return (bool)$st->fetchColumn(); }
  catch(Throwable $e){ return false; }
}
function pick_col(PDO $pdo, string $tbl, array $cands, string $fallback=''): string {
  foreach ($cands as $c) if (col_exists($pdo,$tbl,$c)) return $c;
  return $fallback;
}
function pick_tbl(PDO $pdo, array $cands, string $fallback=''): string {
  foreach ($cands as $t) if (tbl_exists($pdo,$t)) return $t;
  return $fallback;
}

/* ---------------- detect clients schema ---------------- */
$CLIENT_TBL = pick_tbl($pdo, ['clients','client','client_info','customers','subscriber','subscribers'], 'clients');
$ID_COL     = pick_col($pdo, $CLIENT_TBL, ['id','client_id','uid','cid'], 'id');
$NAME_COL   = pick_col($pdo, $CLIENT_TBL, ['name','full_name','client_name'], $ID_COL);
$PPPOE_COL  = pick_col($pdo, $CLIENT_TBL, ['pppoe_id','pppoe','username','user'], '');
$PHONE_COL  = pick_col($pdo, $CLIENT_TBL, ['phone','mobile','contact','contact_no','mobile_no'], '');
$AREA_COL   = pick_col($pdo, $CLIENT_TBL, ['area','zone','location'], '');
$PKG_COL    = pick_col($pdo, $CLIENT_TBL, ['package','package_id','plan','plan_id'], '');
$LAT_COL    = pick_col($pdo, $CLIENT_TBL, ['lat','latitude','gps_lat','geo_lat'], '');
$LNG_COL    = pick_col($pdo, $CLIENT_TBL, ['lng','longitude','lon','gps_lng','geo_lon','geo_lng'], '');

if (!$LAT_COL || !$LNG_COL) {
  echo '<div class="container my-4"><div class="alert alert-warning">Latitude/Longitude columns not found in <code>'.h($CLIENT_TBL).'</code>. Please add <code>lat</code> and <code>lng</code> (FLOAT/DECIMAL).</div></div>';
  require_once __DIR__ . '/../partials/partials_footer.php'; exit;
}

/* ---------------- JSON list endpoint ---------------- */
if (isset($_GET['list'])) {
  header('Content-Type: application/json; charset=utf-8');

  $q        = trim($_GET['q'] ?? '');
  $missing  = isset($_GET['missing']) && $_GET['missing'] == '1';
  $page     = max(1, (int)($_GET['page'] ?? 1));
  $limit    = min(200, max(10, (int)($_GET['limit'] ?? 25)));
  $offset   = ($page - 1) * $limit;

  $where = [];
  $bind = [];
  if ($q !== '') {
    $sub = [];
    $subBind = [];
    foreach ([$NAME_COL, $PPPOE_COL, $PHONE_COL, $AREA_COL] as $c) {
      if ($c) { $sub[] = "`$c` LIKE ?"; $subBind[] = "%$q%"; }
    }
    if ($sub) { $where[] = '(' . implode(' OR ', $sub) . ')'; $bind = array_merge($bind, $subBind); }
  }
  if ($missing) {
    $where[] = " ( `$LAT_COL` IS NULL OR `$LNG_COL` IS NULL OR TRIM(`$LAT_COL`)= '' OR TRIM(`$LNG_COL`)= '' ) ";
  }

  $sqlCount = "SELECT COUNT(*) FROM `$CLIENT_TBL`";
  if ($where) $sqlCount .= " WHERE " . implode(' AND ', $where);
  $stc = $pdo->prepare($sqlCount);
  $stc->execute($bind);
  $total = (int)$stc->fetchColumn();

  $sql = "SELECT `$ID_COL` AS id, `$NAME_COL` AS name";
  if ($PPPOE_COL)  $sql .= ", `$PPPOE_COL` AS pppoe";
  if ($PHONE_COL)  $sql .= ", `$PHONE_COL` AS phone";
  if ($AREA_COL)   $sql .= ", `$AREA_COL` AS area";
  if ($PKG_COL)    $sql .= ", `$PKG_COL` AS package";
  $sql .= ", `$LAT_COL` AS lat, `$LNG_COL` AS lng FROM `$CLIENT_TBL`";
  if ($where) $sql .= " WHERE " . implode(' AND ', $where);
  $sql .= " ORDER BY `$NAME_COL` ASC LIMIT $limit OFFSET $offset";
  $st = $pdo->prepare($sql);
  $st->execute($bind);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode([
    'ok'=>true,
    'data'=>$rows,
    'page'=>$page,
    'limit'=>$limit,
    'total'=>$total,
    'pages'=> (int)ceil($total / $limit),
    'id_key'=>$ID_COL
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  require_once __DIR__ . '/../partials/partials_footer.php'; exit;
}
?>
<style>
  :root { --sidew: 420px; }
  #geomap { height: calc(100vh - 220px); border: 1px solid rgba(0,0,0,.08); border-radius: .75rem; }
  .side { position: relative; height: calc(100vh - 220px); border: 1px solid rgba(0,0,0,.08); border-radius: .75rem; }
  .side .head { padding: .5rem .75rem; border-bottom: 1px solid rgba(0,0,0,.08); display:flex; gap:.5rem; align-items:end; background: var(--bs-body-bg); border-radius: .75rem .75rem 0 0; }
  .side .body { height: calc(100% - 52px); overflow: auto; padding: .5rem; }
  .row-item { border: 1px solid rgba(0,0,0,.08); border-radius: .5rem; padding:.5rem; margin-bottom:.5rem; background:#fff; cursor: pointer; }
  .row-item.active { outline: 2px solid var(--bs-primary); }
  .row-item small code { color:#6c757d; }
  .badge-dot { display:inline-block; width:8px; height:8px; border-radius:50%; background:#6c757d; margin-right:6px;}
</style>

<div class="container-fluid py-3">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0"><i class="bi bi-geo-alt me-2"></i>Bulk Set Client Locations</h4>
    <div class="text-muted small">
      Table: <code><?=h($CLIENT_TBL)?></code>, ID: <code><?=h($ID_COL)?></code>, Lat/Lng: <code><?=h($LAT_COL)?> / <?=h($LNG_COL)?></code>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-8">
      <div class="d-flex gap-2 mb-2 flex-wrap">
        <button id="btn_myloc" class="btn btn-outline-primary btn-sm"><i class="bi bi-crosshair"></i> Use my location</button>
        <button id="btn_setcenter" class="btn btn-outline-secondary btn-sm"><i class="bi bi-bullseye"></i> Set from map center</button>
        <button id="btn_clear" class="btn btn-outline-secondary btn-sm"><i class="bi bi-eraser"></i> Clear marker</button>
        <div class="ms-auto d-flex align-items-center gap-2">
          <div class="input-group input-group-sm" style="width: 300px;">
            <span class="input-group-text">Lat</span>
            <input id="lat" class="form-control">
            <span class="input-group-text">Lng</span>
            <input id="lng" class="form-control">
          </div>
          <button id="btn_save" class="btn btn-primary btn-sm"><i class="bi bi-save"></i> Save</button>
        </div>
      </div>
      <div id="geomap" class="shadow-sm"></div>
      <div class="form-text mt-1">Tip: Click on the map to drop/move the marker. Drag marker to fine tune.</div>
    </div>

    <div class="col-lg-4">
      <div class="side shadow-sm">
        <div class="head">
          <div class="flex-grow-1">
            <label class="form-label mb-1">Search</label>
            <input id="q" class="form-control form-control-sm" placeholder="name / PPPoE / phone / area">
          </div>
          <div>
            <label class="form-label mb-1">Show</label>
            <select id="sel_missing" class="form-select form-select-sm">
              <option value="1">Only missing</option>
              <option value="0">All</option>
            </select>
          </div>
          <div>
            <label class="form-label mb-1">Per page</label>
            <select id="sel_limit" class="form-select form-select-sm">
              <option>25</option><option>50</option><option>100</option>
            </select>
          </div>
          <button id="btn_reload" class="btn btn-secondary btn-sm ms-2"><i class="bi bi-search"></i></button>
        </div>
        <div class="body">
          <div id="list"></div>
          <nav class="mt-2">
            <ul id="pager" class="pagination pagination-sm mb-0"></ul>
          </nav>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Leaflet -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>

<script>
const idKey = <?= json_encode($ID_COL) ?>;

// Map setup — Bangladesh center
const map = L.map('geomap', {zoomControl:true}).setView([23.8103, 90.4125], 8);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'&copy; OpenStreetMap contributors'}).addTo(map);

let marker = null;
let selected = null; // {id, name, pppoe, lat, lng, ...}
let page = 1;

function setMarker(lat, lng){
  if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
  if (marker) { marker.setLatLng([lat, lng]); }
  else {
    marker = L.marker([lat,lng], {draggable:true}).addTo(map);
    marker.on('dragend', e=>{
      const {lat, lng} = e.target.getLatLng();
      document.getElementById('lat').value = lat.toFixed(6);
      document.getElementById('lng').value = lng.toFixed(6);
    });
  }
  document.getElementById('lat').value = lat.toFixed(6);
  document.getElementById('lng').value = lng.toFixed(6);
}

function clearMarker(){
  if (marker) { map.removeLayer(marker); marker=null; }
  document.getElementById('lat').value = '';
  document.getElementById('lng').value = '';
}

map.on('click', e=>{
  setMarker(e.latlng.lat, e.latlng.lng);
});

// Controls
document.getElementById('btn_myloc').addEventListener('click', ()=>{
  if (navigator.geolocation){
    navigator.geolocation.getCurrentPosition(pos=>{
      const {latitude, longitude} = pos.coords;
      setMarker(latitude, longitude);
      map.setView([latitude, longitude], 15);
    });
  }
});
document.getElementById('btn_setcenter').addEventListener('click', ()=>{
  const c = map.getCenter();
  setMarker(c.lat, c.lng);
});
document.getElementById('btn_clear').addEventListener('click', clearMarker);

// Save
document.getElementById('btn_save').addEventListener('click', async ()=>{
  if (!selected) { alert('Select a client from the list first.'); return; }
  const lat = parseFloat(document.getElementById('lat').value);
  const lng = parseFloat(document.getElementById('lng').value);
  if (!Number.isFinite(lat) || !Number.isFinite(lng)) { alert('Set a valid lat/lng first.'); return; }

  const fd = new FormData();
  fd.set('client_id', selected.id);
  fd.set('id_key', idKey);
  fd.set('lat', lat);
  fd.set('lng', lng);

  const res = await fetch('/public/client_geo_save.php', { method:'POST', body: fd });
  if (!res.ok) { alert('Save failed: '+res.status); return; }
  const j = await res.json();
  if (j.ok) {
    alert('Saved!');
    // update selected row display
    selected.lat = lat; selected.lng = lng;
    renderListRowActive(selected.id);
  } else {
    alert('Save failed: '+(j.error||'Unknown'));
  }
});

/* ---------- list ---------- */
async function loadList(goPage=1){
  page = goPage;
  const q = document.getElementById('q').value.trim();
  const missing = document.getElementById('sel_missing').value;
  const limit = document.getElementById('sel_limit').value;
  const url = new URL(window.location.href);
  url.search = '';
  url.pathname = url.pathname; // keep
  url.searchParams.set('list','1');
  if (q) url.searchParams.set('q', q);
  url.searchParams.set('missing', missing);
  url.searchParams.set('page', goPage);
  url.searchParams.set('limit', limit);

  const res = await fetch(url.toString(), { cache:'no-store' });
  const j = await res.json();
  if (!j.ok) return;

  const el = document.getElementById('list');
  el.innerHTML = '';

  j.data.forEach(row=>{
    const div = document.createElement('div');
    div.className = 'row-item';
    div.dataset.id = row.id;

    const hasGeo = Number.isFinite(parseFloat(row.lat)) && Number.isFinite(parseFloat(row.lng));
    const dot = `<span class="badge-dot" style="background:${hasGeo ? '#28a745':'#dc3545'}"></span>`;

    div.innerHTML = `
      <div class="d-flex align-items-center">
        <div class="flex-grow-1">
          <div class="fw-semibold">${dot}${row.name ?? row.pppoe ?? row.id}</div>
          <small class="text-muted">
            ${row.pppoe ? `<code>${row.pppoe}</code>` : ''} ${row.area ? ' • '+row.area : ''} ${row.package ? ' • '+row.package : ''} ${row.phone ? ' • '+row.phone : ''}
          </small>
        </div>
        <div class="text-nowrap small">
          ${hasGeo ? `<span class="badge text-bg-success">Set</span>` : `<span class="badge text-bg-danger">Missing</span>`}
        </div>
      </div>
    `;
    div.addEventListener('click', ()=> {
      selected = row;
      // active UI
      document.querySelectorAll('.row-item').forEach(x=>x.classList.remove('active'));
      div.classList.add('active');

      const lat = parseFloat(row.lat);
      const lng = parseFloat(row.lng);
      if (Number.isFinite(lat) && Number.isFinite(lng)) {
        setMarker(lat, lng);
        map.setView([lat,lng], 15);
      } else {
        clearMarker();
        // focus roughly by area? fallback center
        map.setView([23.8103, 90.4125], 12);
      }
      // top inputs
      if (Number.isFinite(lat) && Number.isFinite(lng)) {
        document.getElementById('lat').value = lat.toFixed(6);
        document.getElementById('lng').value = lng.toFixed(6);
      } else {
        document.getElementById('lat').value = '';
        document.getElementById('lng').value = '';
      }
    });

    el.appendChild(div);
  });

  // pager
  buildPager(j.page, j.pages);
}

function buildPager(cur, total){
  const ul = document.getElementById('pager');
  ul.innerHTML = '';
  function add(label, p, disabled=false, active=false){
    const li = document.createElement('li');
    li.className = 'page-item'+(disabled?' disabled':'')+(active?' active':'');
    const a = document.createElement('a');
    a.className = 'page-link';
    a.href = 'javascript:void(0)';
    a.textContent = label;
    a.addEventListener('click', ()=>{ if (!disabled && !active) loadList(p); });
    li.appendChild(a); ul.appendChild(li);
  }
  add('«', Math.max(1, cur-1), cur===1);
  const windowSize = 5;
  const start = Math.max(1, cur - Math.floor(windowSize/2));
  const end = Math.min(total, start + windowSize - 1);
  for (let p=start; p<=end; p++) add(String(p), p, false, p===cur);
  add('»', Math.min(total, cur+1), cur===total || total===0);
}

function renderListRowActive(id){
  document.querySelectorAll('.row-item').forEach(el=>{
    if (String(el.dataset.id) === String(id)) {
      el.classList.add('active');
      const badge = el.querySelector('.badge');
      if (badge) { badge.className = 'badge text-bg-success'; badge.textContent = 'Set'; }
      const dot = el.querySelector('.badge-dot');
      if (dot) dot.style.background = '#28a745';
    } else {
      el.classList.remove('active');
    }
  });
}

// search actions
document.getElementById('btn_reload').addEventListener('click', ()=> loadList(1));
document.getElementById('q').addEventListener('keydown', (e)=> { if (e.key==='Enter') loadList(1); });
document.getElementById('sel_missing').addEventListener('change', ()=> loadList(1));
document.getElementById('sel_limit').addEventListener('change', ()=> loadList(1));

// init
loadList(1);
</script>

<?php require_once __DIR__ . '/../partials/partials_footer.php'; ?>
