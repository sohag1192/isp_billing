<?php
// /public/network_map.php
// Network Map: Clients with GPS + LIVE PPPoE coloring + Only-Online + Right-panel list
// + Area polygon drawing (Leaflet.Draw) + Save/Load + Filter by area (even unsaved drawn polygon)
// + Floating labels, dropdown de-dup, API error guards
// UI: English; Comments: বাংলা

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');

$page_title = 'Network Map';
require_once __DIR__ . '/../partials/partials_header.php';
require_once __DIR__ . '/../app/db.php';

// (অপশনাল) ACL থাকলে ইনক্লুড করো; না থাকলে নীরবভাবে এগোবে
$acl_file = __DIR__ . '/../app/acl.php';
if (is_file($acl_file)) require_once $acl_file;

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
  try {$st=$pdo->prepare("SHOW COLUMNS FROM `$tbl` LIKE ?"); $st->execute([$col]); return (bool)$st->fetchColumn(); }
  catch(Throwable $e){ return false; }
}
function pick_col(PDO $pdo, string $tbl, array $cands, string $fallback='id'): string {
  foreach ($cands as $c) { if (col_exists($pdo, $tbl, $c)) return $c; }
  return $fallback;
}

/* ---------------- detect schema ---------------- */
$CLIENT_TBL = 'clients';
foreach (['clients','client','client_info','customers','subscriber','subscribers'] as $cand) {
  if (tbl_exists($pdo, $cand)) { $CLIENT_TBL = $cand; break; }
}
$ID_COL     = pick_col($pdo, $CLIENT_TBL, ['id','client_id','uid','cid'], 'id');
$NAME_COL   = pick_col($pdo, $CLIENT_TBL, ['name','full_name','client_name','customer_name','c_name','subscriber_name'], $ID_COL);
$PPPOE_COL  = pick_col($pdo, $CLIENT_TBL, ['pppoe_id','pppoe','username','user','login'], $ID_COL);
$LAT_COL    = pick_col($pdo, $CLIENT_TBL, ['lat','latitude','gps_lat','geo_lat'], '');
$LNG_COL    = pick_col($pdo, $CLIENT_TBL, ['lng','longitude','lon','gps_lng','geo_lon','geo_lng'], '');
$STATUS_COL = pick_col($pdo, $CLIENT_TBL, ['status','is_active','active'], 'status');

// ড্রপডাউন ফিল্টারের জন্য
$areas = [];
if (col_exists($pdo, $CLIENT_TBL, 'area')) {
  $areas = $pdo->query("SELECT DISTINCT area FROM `$CLIENT_TBL` WHERE area IS NOT NULL AND area<>'' ORDER BY area")->fetchAll(PDO::FETCH_COLUMN);
}
$packages = [];
if (col_exists($pdo, $CLIENT_TBL, 'package_id')) {
  $packages = $pdo->query("SELECT DISTINCT package_id FROM `$CLIENT_TBL` WHERE package_id IS NOT NULL AND package_id<>'' ORDER BY package_id")->fetchAll(PDO::FETCH_COLUMN);
}
?>
<style>
  :root { --sidew: 380px; }
  #netmap { height: calc(100vh - 210px); border-radius: .75rem; border: 1px solid rgba(0,0,0,.08); }
  .map-toolbar { background: var(--bs-body-bg); border: 1px solid rgba(0,0,0,.06); border-radius: .75rem; padding: .75rem; }
  .leaflet-popup-content { min-width: 260px; }
  .kv { display: grid; grid-template-columns: max-content 1fr; gap: .25rem .75rem; font-size: .9rem; }
  .kv div:nth-child(odd) { color: #6c757d; }

  .legend { display:flex; align-items:center; gap:.5rem; font-size:.9rem; }
  .dot { width:10px; height:10px; border-radius:50%; display:inline-block; }

  /* Right panel */
  .sidewrap { position: relative; }
  .side { position: absolute; right: 0; top: 0; width: var(--sidew); height: 100%; border-left: 1px solid rgba(0,0,0,.08); background: var(--bs-body-bg); border-radius: .75rem; overflow: hidden; }
  .side .head { padding: .5rem .75rem; border-bottom: 1px solid rgba(0,0,0,.08); display:flex; align-items:center; gap:.5rem; }
  .side .body { height: calc(100% - 46px); overflow: auto; padding: .5rem; }
  .side .item { padding: .5rem .5rem; border: 1px solid rgba(0,0,0,.08); border-radius: .5rem; margin-bottom:.5rem; background: #fff; }
  .side .item .nm { font-weight: 600; }
  .side .item small code { color: #6c757d; }

  .map-col { position: relative; }
  .map-col .pad-right { padding-right: calc(var(--sidew) + 12px); }
  @media (max-width: 992px){
    :root { --sidew: 320px; }
  }
  @media (max-width: 768px){
    .side { position: static; width: 100%; height: 300px; }
    .map-col .pad-right { padding-right: 0; }
    #netmap { height: 420px; }
  }

  /* Floating labels */
  .map-label {
    background: rgba(255,255,255,.85);
    border: 1px solid rgba(0,0,0,.15);
    border-radius: .375rem;
    padding: 2px 6px;
    font-size: 12px;
    line-height: 1.1;
    box-shadow: 0 1px 2px rgba(0,0,0,.06);
    color: #212529;
    white-space: nowrap;
  }
  .map-label.leaflet-tooltip-top:before { border-top-color: rgba(0,0,0,.15); opacity: .4; }
</style>

<div class="container-fluid py-3">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0"><i class="bi bi-diagram-3 me-2"></i>Network Map</h4>
    <div class="text-muted small">
      Table: <code><?=h($CLIENT_TBL)?></code>,
      ID: <code><?=h($ID_COL)?></code>, Name: <code><?=h($NAME_COL)?></code>,
      PPPoE: <code><?=h($PPPOE_COL)?></code>, Lat/Lng: <code><?=h($LAT_COL)?> / <?=h($LNG_COL)?></code>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-12">
      <div class="map-toolbar d-flex flex-wrap align-items-end gap-2">
        <div>
          <label class="form-label mb-1">Status</label>
          <select id="f_status" class="form-select form-select-sm">
            <option value="">All</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
            <option value="left">Left</option>
            <option value="suspended">Suspended</option>
            <option value="due">Due</option>
          </select>
        </div>
        <?php if (!empty($areas)): ?>
        <div>
          <label class="form-label mb-1">Area</label>
          <select id="f_area" class="form-select form-select-sm">
            <option value="">All</option>
            <?php foreach ($areas as $ar): ?>
              <option value="<?=h($ar)?>"><?=h($ar)?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <?php if (!empty($packages)): ?>
        <div>
          <label class="form-label mb-1">Package</label>
          <select id="f_package" class="form-select form-select-sm">
            <option value="">All</option>
            <?php foreach ($packages as $pk): ?>
              <option value="<?=h((string)$pk)?>"><?=h((string)$pk)?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <div class="flex-grow-1">
          <label class="form-label mb-1">Search (name/PPPoE/phone)</label>
          <input type="text" id="f_search" class="form-control form-control-sm" placeholder="Type and press Enter">
        </div>

        <div class="ms-auto d-flex flex-wrap align-items-center gap-3">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="only_online">
            <label class="form-check-label" for="only_online">Only online</label>
          </div>
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="show_labels" checked>
            <label class="form-check-label" for="show_labels">Show labels</label>
          </div>

          <!-- Area tools -->
          <div class="d-flex align-items-end gap-2">
            <div>
              <label class="form-label mb-1">Areas</label>
              <select id="sel_area" class="form-select form-select-sm" style="min-width:180px">
                <option value="">(None)</option>
              </select>
            </div>
            <div class="btn-group">
              <button id="btn_draw"   class="btn btn-outline-success btn-sm"><i class="bi bi-vector-pen"></i> Draw</button>
              <button id="btn_save"   class="btn btn-outline-primary btn-sm" disabled><i class="bi bi-save2"></i> Save</button>
              <button id="btn_delete" class="btn btn-outline-danger btn-sm" disabled><i class="bi bi-trash"></i> Delete</button>
              <button id="btn_filter" class="btn btn-outline-secondary btn-sm" disabled><i class="bi bi-funnel"></i> Filter by area</button>
            </div>
          </div>

          <div class="legend ms-2">
            <span class="dot" style="background:#28a745"></span> Online
            <span class="dot" style="background:#6c757d; margin-left:10px;"></span> Offline
            <span class="dot" style="background:#dc3545; margin-left:10px;"></span> Due/Left
          </div>
          <button id="btn_locate" class="btn btn-outline-primary btn-sm"><i class="bi bi-geo-alt"></i> Locate me</button>
          <button id="btn_fit" class="btn btn-outline-secondary btn-sm"><i class="bi bi-aspect-ratio"></i> Fit bounds</button>
          <button id="btn_refresh" class="btn btn-primary btn-sm"><i class="bi bi-arrow-repeat"></i> Refresh</button>
        </div>
      </div>
    </div>

    <div class="col-12 sidewrap">
      <div class="map-col pad-right">
        <div id="netmap" class="shadow-sm"></div>
        <div class="text-muted small mt-1" id="autoref_info"></div>
      </div>
      <aside class="side shadow-sm">
        <div class="head">
          <div class="fw-semibold">Visible Clients</div>
          <div class="ms-auto small text-muted">
            <span id="count_total" class="me-2">0</span>
            <span class="text-success">Online: <span id="count_online">0</span></span>
            <span class="text-secondary ms-2">Offline: <span id="count_offline">0</span></span>
          </div>
        </div>
        <div class="body">
          <div class="d-flex align-items-center gap-2 mb-2">
            <input id="list_search" class="form-control form-control-sm" placeholder="Filter in list...">
            <select id="list_sort" class="form-select form-select-sm" style="max-width:140px">
              <option value="name">Sort: Name</option>
              <option value="pppoe">Sort: PPPoE</option>
              <option value="status">Sort: Status</option>
              <option value="online">Sort: Online first</option>
            </select>
          </div>
          <div id="list" aria-live="polite"></div>
        </div>
      </aside>
    </div>
  </div>
</div>

<!-- Leaflet & MarkerCluster (CDN) -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>

<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css">
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>

<!-- Leaflet.Draw -->
<link rel="stylesheet" href="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.css">
<script src="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.js"></script>

<script>
// (বাংলা) বেসিক ম্যাপ
const map = L.map('netmap', { zoomControl: true }).setView([23.8103, 90.4125], 8);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap contributors' }).addTo(map);

const cluster = L.markerClusterGroup({ chunkedLoading: true });
map.addLayer(cluster);

// ড্রয়িং লেয়ার
const drawnItems = new L.FeatureGroup().addTo(map);
let drawControl = null;

// (বাংলা) স্টেট
let currentData = [];
let renderedData = [];
let currentBounds = null;
let timer = null;
const REFRESH_MS = 30000; // 30s
let selectedAreaId = null;    // dropdown থেকে নির্বাচিত এরিয়া
let activeDrawn = null;       // ইউজার যে polygon আঁকলেন (unsaved হলেও)
let loadedAreasLayerMap = {}; // id -> leaflet layer

/* ---------- helpers ---------- */
function statusBadge(st) {
  if (!st) return '<span class="badge text-bg-secondary">Unknown</span>';
  const s = String(st).toLowerCase();
  if (['1','active','enabled','true'].includes(s)) return '<span class="badge text-bg-success">Active</span>';
  if (['0','inactive','disabled','false'].includes(s)) return '<span class="badge text-bg-secondary">Inactive</span>';
  if (s.includes('suspend')) return '<span class="badge text-bg-warning">Suspended</span>';
  if (s.includes('left')) return '<span class="badge text-bg-dark">Left</span>';
  if (s.includes('due')) return '<span class="badge text-bg-danger">Due</span>';
  return '<span class="badge text-bg-secondary">'+(st??'Unknown')+'</span>';
}
function computeColor(c){
  if (c.online === true) return '#28a745';
  const st = (c.status||'').toString().toLowerCase();
  if (st.includes('left') || st.includes('due')) return '#dc3545';
  return '#6c757d';
}
function buildPopup(c){
  const kv = `
  <div class="kv">
    <div>Client:</div><div><strong>${c.name ?? c.pppoe ?? c.id}</strong></div>
    ${c.pppoe ? `<div>PPPoE:</div><div><code>${c.pppoe}</code></div>`:''}
    ${c.phone ? `<div>Phone:</div><div>${c.phone}</div>`:''}
    ${c.area ? `<div>Area:</div><div>${c.area}</div>`:''}
    ${c.package ? `<div>Package:</div><div>${c.package}</div>`:''}
    <div>Status:</div><div>${statusBadge(c.status)} ${c.online===true?'<span class="badge text-bg-success ms-1">Online</span>':'<span class="badge text-bg-secondary ms-1">Offline</span>'}</div>
  </div>
  <div class="mt-2 d-flex gap-2">
    <a class="btn btn-sm btn-primary" href="/public/client_view.php?<?=urlencode(''.$ID_COL)?>=${encodeURIComponent(c.id)}" target="_blank" rel="noopener">
      <i class="bi bi-box-arrow-up-right"></i> Open profile
    </a>
    ${c.router_name ? `<span class="badge text-bg-info align-self-center">AP: ${c.router_name}</span>`:''}
  </div>`;
  return kv;
}
function debounce(fn, ms){ let t=null; return (...args)=>{ clearTimeout(t); t=setTimeout(()=>fn(...args), ms); }; }

// point-in-polygon (ray casting) — WGS84 lon/lat
function pointInPolygon(point, vs){
  // point: [lng, lat]; vs: [[lng,lat], ...]
  const x = point[0], y = point[1];
  let inside = false;
  for (let i=0, j=vs.length-1; i<vs.length; j=i++) {
    const xi = vs[i][0], yi = vs[i][1];
    const xj = vs[j][0], yj = vs[j][1];
    const intersect = ((yi>y) !== (yj>y)) && (x < (xj - xi) * (y - yi) / ((yj - yi) || 1e-12) + xi);
    if (intersect) inside = !inside;
  }
  return inside;
}
function layerToGeoJSONFeature(layer){
  const gj = layer.toGeoJSON();
  if (gj.type === 'Feature') return gj;
  return { type:'Feature', geometry: gj, properties:{} };
}
function labelsEnabled(){
  const ui = document.getElementById('show_labels')?.checked ?? true;
  const zoomOk = map.getZoom() >= 12; // জুম <12 হলে label লুকাও (ক্লাটার কম)
  return ui && zoomOk;
}

/* ---------- API fetch ---------- */
async function fetchClients(live=true) {
  const params = new URLSearchParams();
  const st = document.getElementById('f_status').value.trim();
  const area = document.getElementById('f_area')?.value.trim() ?? '';
  const pkg = document.getElementById('f_package')?.value.trim() ?? '';
  const q = document.getElementById('f_search').value.trim();

  if (st) params.set('status', st);
  if (area) params.set('area', area);
  if (pkg) params.set('package', pkg);
  if (q) params.set('q', q);
  if (live) params.set('live','1');

  try{
    const res = await fetch('/public/network_map_clients.php?'+params.toString(), { cache:'no-store' });
    if (!res.ok) { console.error('Failed to load clients', res.status); return []; }
    return await res.json();
  } catch(e){
    console.error('Clients API unreachable', e);
    return [];
  }
}
async function fetchAreas(){
  try {
    const res = await fetch('/public/areas_geo.php?list=1', { cache:'no-store' });
    if (!res.ok) { alert('Failed to load areas ('+res.status+'). Are you logged in?'); return null; }
    const j = await res.json();
    if (!j || j.ok!==true) { alert('Areas API error'); return null; }
    return j;
  } catch(e){ alert('Areas API unreachable'); return null; }
}
async function saveArea(name, color, feature){
  const fd = new FormData();
  if (selectedAreaId) fd.set('id', selectedAreaId);
  fd.set('name', name);
  fd.set('color', color || '#0d6efd');
  fd.set('geojson', JSON.stringify(feature));
  const res = await fetch('/public/areas_geo.php', { method:'POST', body:fd });
  return await res.json();
}
async function deleteArea(id){
  const res = await fetch('/public/areas_geo.php?id='+encodeURIComponent(id), { method:'DELETE' });
  return await res.json();
}

/* ---------- render clients on map ---------- */
function renderMarkers(data){
  cluster.clearLayers();
  currentBounds = L.latLngBounds([]);
  renderedData = [];

  const only = document.getElementById('only_online').checked;

  // ✅ PATCH: selected area না থাকলে drawn polygon দিয়েও ফিল্টার চলবে
  let polyCoords = null; // [[lng,lat], ...]
  let useLayer = null;

  if (selectedAreaId && loadedAreasLayerMap[selectedAreaId]) {
    useLayer = loadedAreasLayerMap[selectedAreaId];
  } else if (activeDrawn) {
    useLayer = activeDrawn;
  }

  if (useLayer) {
    const gj = layerToGeoJSONFeature(useLayer);
    const g = gj.geometry;
    if (g && (g.type === 'Polygon' || g.type === 'MultiPolygon')) {
      polyCoords = (g.type === 'Polygon') ? g.coordinates[0] : g.coordinates[0][0]; // [ [lng,lat], ... ]
    }
  }

  data.forEach(c=>{
    if (typeof c.lat !== 'number' || typeof c.lng !== 'number') return;
    if (only && c.online !== true) return;

    if (polyCoords) {
      const inside = pointInPolygon([c.lng, c.lat], polyCoords);
      if (!inside) return;
    }

    const color = computeColor(c);
    const m = L.circleMarker([c.lat, c.lng], {
      radius: 6, weight: 2, color: color, fillColor: color, fillOpacity: 0.7,
      title: c.name ?? c.pppoe ?? String(c.id)
    });
    // Floating label
    m.bindTooltip((c.name ?? c.pppoe ?? String(c.id)), {
      permanent: labelsEnabled(),
      direction: 'top',
      className: 'map-label',
      offset: L.point(0, -8)
    });
    m.bindPopup(buildPopup(c));
    cluster.addLayer(m);
    currentBounds.extend([c.lat, c.lng]);
    renderedData.push(c);
  });

  if (renderedData.length && currentBounds.isValid()) map.fitBounds(currentBounds.pad(0.08));
  renderList();
}

/* ---------- right list ---------- */
function renderList(){
  const el = document.getElementById('list');
  const query = document.getElementById('list_search').value.trim().toLowerCase();
  const sort = document.getElementById('list_sort').value;

  let arr = renderedData.slice();
  if (query) {
    arr = arr.filter(c=>{
      const hay = (c.name||'')+' '+(c.pppoe||'')+' '+(c.phone||'')+' '+(c.area||'')+' '+(c.package||'');
      return hay.toLowerCase().includes(query);
    });
  }

  // sort
  arr.sort((a,b)=>{
    const A=(x)=> (x??'').toString().toLowerCase();
    if (sort==='pppoe') return A(a.pppoe).localeCompare(A(b.pppoe));
    if (sort==='status') return A(a.status).localeCompare(A(b.status));
    if (sort==='online') return (b.online===true)-(a.online===true) || A(a.name).localeCompare(A(b.name));
    return A(a.name).localeCompare(A(b.name)); // name
  });

  // counts
  document.getElementById('count_total').textContent = String(renderedData.length);
  document.getElementById('count_online').textContent = String(renderedData.filter(x=>x.online===true).length);
  document.getElementById('count_offline').textContent= String(renderedData.filter(x=>x.online!==true).length);

  // render
  el.innerHTML = '';
  const frag = document.createDocumentFragment();
  for (const c of arr) {
    const div = document.createElement('div');
    const col = computeColor(c);
    div.className = 'item';
    div.innerHTML = `
      <div class="d-flex align-items-center">
        <div class="me-2" style="width:10px;height:10px;border-radius:50%;background:${col}"></div>
        <div class="flex-grow-1">
          <div class="nm">${c.name ?? c.pppoe ?? c.id}</div>
          <small class="text-muted">
            ${c.pppoe ? `<code>${c.pppoe}</code>` : ''} ${c.area ? ' • '+c.area : ''} ${c.package ? ' • '+c.package : ''}
          </small>
        </div>
        <div>
          <a class="btn btn-sm btn-outline-primary" href="/public/client_view.php?<?=urlencode(''.$ID_COL)?>=${encodeURIComponent(c.id)}" target="_blank" rel="noopener">Open</a>
        </div>
      </div>`;
    frag.appendChild(div);
  }
  el.appendChild(frag);
}

/* ---------- areas: load & UI ---------- */
async function loadAreasToMap(){
  // পুরনো লেয়ার ক্লিয়ার
  for (const k in loadedAreasLayerMap) {
    const Lyr = loadedAreasLayerMap[k];
    if (map.hasLayer(Lyr)) map.removeLayer(Lyr);
  }
  loadedAreasLayerMap = {};
  drawnItems.clearLayers();

  // dropdown রিসেট + ✅ de-dup guard
  const sel = document.getElementById('sel_area');
  sel.innerHTML = '<option value="">(None)</option>';
  const seen = new Set();

  const j = await fetchAreas();
  if (!j) return;

  // features add
  j.features.forEach(f=>{
    const id = f.properties && f.properties.id;
    if (!id || seen.has(id)) return; // ✅ ডুপ্লিকেট স্কিপ
    seen.add(id);

    const color = (f.properties && f.properties.color) || '#0d6efd';
    const layer = L.geoJSON(f, {
      style: { color: color, weight: 2, fillColor: color, fillOpacity: .08 }
    }).addTo(map);
    loadedAreasLayerMap[id] = layer;

    const opt = document.createElement('option');
    opt.value = String(id);
    opt.textContent = (f.properties && f.properties.name) ? f.properties.name : ('Area #'+id);
    sel.appendChild(opt);
  });

  document.getElementById('btn_delete').disabled = !selectedAreaId;
  document.getElementById('btn_filter').disabled = !(selectedAreaId || activeDrawn);
}

/* ---------- orchestrate ---------- */
async function refresh(){
  currentData = await fetchClients(true);
  renderMarkers(currentData);
}
function setupAutoRefresh(){
  const info = document.getElementById('autoref_info');
  if (timer) clearInterval(timer);
  timer = setInterval(()=>{
    refresh().then(()=>{
      const ts = new Date();
      info.textContent = 'Auto-refreshed at ' + ts.toLocaleTimeString();
    });
  }, REFRESH_MS);
}

/* ---------- draw control ---------- */
function enableDrawMode(){
  if (drawControl) { map.removeControl(drawControl); drawControl=null; }
  drawControl = new L.Control.Draw({
    draw: {
      polygon: { allowIntersection: false, showArea: true, metric: true, shapeOptions: { color:'#0d6efd' } },
      polyline: false, rectangle: false, circle: false, marker: false, circlemarker:false
    },
    edit: { featureGroup: drawnItems, edit: true, remove: true }
  });
  map.addControl(drawControl);
  alert('Draw mode enabled: Click on the map to create a polygon. Double-click to finish.');
}

/* ---------- events ---------- */
document.getElementById('btn_refresh').addEventListener('click', refresh);
document.getElementById('btn_fit').addEventListener('click', ()=> {
  if (currentBounds && currentBounds.isValid()) map.fitBounds(currentBounds.pad(0.08));
});
document.getElementById('btn_locate').addEventListener('click', ()=>{
  if (navigator.geolocation){
    navigator.geolocation.getCurrentPosition(pos=>{
      const {latitude, longitude} = pos.coords;
      const me = L.circleMarker([latitude, longitude], { radius: 6 });
      me.bindTooltip('You are here');
      me.addTo(map);
      map.setView([latitude, longitude], 14);
    });
  }
});
document.getElementById('f_search').addEventListener('keydown', (e)=>{
  if (e.key === 'Enter') refresh();
});
document.getElementById('f_status').addEventListener('change', refresh);
document.getElementById('f_area')?.addEventListener('change', refresh);
document.getElementById('f_package')?.addEventListener('change', refresh);

document.getElementById('only_online').addEventListener('change', ()=> renderMarkers(currentData));
document.getElementById('show_labels').addEventListener('change', ()=> renderMarkers(currentData));
map.on('zoomend', ()=> renderMarkers(currentData)); // ✅ জুম-থ্রেশহোল্ডে লেবেল টগল

document.getElementById('list_search').addEventListener('input', debounce(renderList, 200));
document.getElementById('list_sort').addEventListener('change', renderList);

// Areas UI
document.getElementById('btn_draw').addEventListener('click', ()=>{
  enableDrawMode();
  document.getElementById('btn_save').disabled = false;
});
document.getElementById('btn_save').addEventListener('click', async ()=>{
  if (!activeDrawn && drawnItems.getLayers().length>0) {
    activeDrawn = drawnItems.getLayers()[0];
  }
  if (!activeDrawn) { alert('Draw an area first.'); return; }
  const name = prompt('Area name:', 'New Area');
  if (name===null || name.trim()==='') return;
  const color = prompt('Color (hex):', '#0d6efd') || '#0d6efd';
  const feature = layerToGeoJSONFeature(activeDrawn);
  feature.properties = feature.properties || {};
  feature.properties.name = name;
  feature.properties.color = color;

  const res = await saveArea(name, color, feature);
  if (!res.ok) { alert('Save failed'); return; }
  selectedAreaId = res.id;
  await loadAreasToMap();
  document.getElementById('sel_area').value = String(selectedAreaId);
  document.getElementById('btn_delete').disabled = !selectedAreaId;
  document.getElementById('btn_filter').disabled = !(selectedAreaId || activeDrawn);
  alert('Area saved.');
});
document.getElementById('btn_delete').addEventListener('click', async ()=>{
  if (!selectedAreaId) return;
  if (!confirm('Delete this area?')) return;
  const res = await deleteArea(selectedAreaId);
  if (!res.ok) { alert('Delete failed'); return; }
  selectedAreaId = null;
  await loadAreasToMap();
  document.getElementById('sel_area').value = '';
  document.getElementById('btn_delete').disabled = true;
  document.getElementById('btn_filter').disabled = !activeDrawn; // ✅ drawn থাকলে ফিল্টার চালু থাকবে
  renderMarkers(currentData);
});
// ✅ PATCH: Filter কাজ করবে saved area বা drawn polygon—দুটোতেই
document.getElementById('btn_filter').addEventListener('click', ()=>{
  if (!selectedAreaId && !activeDrawn) { alert('Select an area or draw a polygon first.'); return; }
  renderMarkers(currentData);
});
document.getElementById('sel_area').addEventListener('change', (e)=>{
  const v = e.target.value;
  selectedAreaId = v ? parseInt(v,10) : null;
  document.getElementById('btn_delete').disabled = !selectedAreaId;
  // drawn না থাকলে selectedAreaId আছে কি না দেখে Filter বাটন টগল
  document.getElementById('btn_filter').disabled = !(selectedAreaId || activeDrawn);

  if (selectedAreaId && loadedAreasLayerMap[selectedAreaId]) {
    const lyr = loadedAreasLayerMap[selectedAreaId];
    try { map.fitBounds(lyr.getBounds().pad(0.05)); } catch(_){}
  } else {
    if (currentBounds && currentBounds.isValid()) map.fitBounds(currentBounds.pad(0.08));
  }
});

// Leaflet.Draw hooks — ✅ drawn হলে সাথে সাথে Filter বাটন অন
map.on(L.Draw.Event.CREATED, function (e) {
  const layer = e.layer;
  drawnItems.clearLayers();
  drawnItems.addLayer(layer);
  activeDrawn = layer;
  document.getElementById('btn_save').disabled = false;
  document.getElementById('btn_filter').disabled = false;
});
map.on(L.Draw.Event.EDITED, function (e) {
  e.layers.eachLayer(function (layer) { activeDrawn = layer; });
  document.getElementById('btn_filter').disabled = false;
});
map.on(L.Draw.Event.DELETED, function () {
  activeDrawn = null;
  if (!selectedAreaId) document.getElementById('btn_filter').disabled = true;
});

/* ---------- init ---------- */
Promise.all([refresh(), loadAreasToMap()]).then(()=>{ setupAutoRefresh(); });
</script>

<?php require_once __DIR__ . '/../partials/partials_footer.php'; ?>
