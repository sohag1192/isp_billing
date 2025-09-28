<?php
// /public/portal/index.php
// UI English; বাংলা কমেন্ট

require_once __DIR__ . '/../../app/portal_require_login.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/routeros_api.class.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------- Helpers (বাংলা) ---------- */
// (বাংলা) navbar-এ ব্যবহার হচ্ছে, তাই আগে থেকেই সেফ ডিফাইন
if (!function_exists('portal_username')) {
  function portal_username(): string {
    return $_SESSION['username'] ?? $_SESSION['pppoe_id'] ?? $_SESSION['client_name'] ?? 'Customer';
  }
}

/* ===================== Load Client ===================== */
$st = db()->prepare("
  SELECT c.*,
         p.name  AS package_name,
         p.price AS package_price,
         r.name  AS router_name,
         r.id    AS router_id,
         r.ip    AS router_ip,
         r.api_port AS router_api_port,
         r.username AS router_user,
         r.password AS router_pass
  FROM clients c
  LEFT JOIN packages p ON p.id = c.package_id
  LEFT JOIN routers  r ON r.id = c.router_id
  WHERE c.id = ? LIMIT 1
");
$st->execute([portal_client_id()]);
$client = $st->fetch(PDO::FETCH_ASSOC) ?: [];

/* Guard values */
$client_id   = (int)($client['id'] ?? 0);
$pppoe_id    = (string)($client['pppoe_id'] ?? '');
$client_name = (string)($client['name'] ?? 'Client');

/* ===================== Ledger Balance ===================== */
// (বাংলা) +ve = Due, -ve = Advance, 0 = Paid
$ledger_balance = 0.00;
if ($client_id) {
  $st = db()->prepare("SELECT COALESCE(ledger_balance,0) FROM clients WHERE id=?");
  $st->execute([$client_id]);
  $ledger_balance = (float)$st->fetchColumn();
}
function ledgerBadgeClass(float $v): string {
  if ($v > 0.0001) return 'bg-danger';
  if ($v < -0.0001) return 'bg-success';
  return 'bg-secondary';
}
function ledgerLabel(float $v): string {
  if ($v > 0.0001) return 'Due';
  if ($v < -0.0001) return 'Advance';
  return 'Paid';
}

/* ===================== Router/PPP Status ===================== */
// (বাংলা) $ppp_active tri-state: true|false|null (null => Unknown)
$status_text = 'Unknown';
$ppp_active  = null;

try {
  if (!empty($client['router_id']) && $pppoe_id !== '') {
    // (বাংলা) রাউটার রেকর্ড থেকে ফিল্ড-ভ্যারিয়েন্ট সাপোর্ট
    $ipKeys   = ['ip','router_ip','ip_address','host','address'];
    $userKeys = ['username','user','api_user','login','router_user'];
    $passKeys = ['password','pass','api_pass','secret','router_pass'];
    $portKeys = ['api_port','port'];

    $ip=$user=$pass=null; $port = null;
    foreach ($ipKeys as $k)   if (!empty($client[$k])) { $ip   = $client[$k]; break; }
    foreach ($userKeys as $k) if (!empty($client[$k])) { $user = $client[$k]; break; }
    foreach ($passKeys as $k) if (!empty($client[$k])) { $pass = $client[$k]; break; }
    foreach ($portKeys as $k) if (!empty($client[$k])) { $port = (int)$client[$k]; break; }
    if (!$port) { $port = 8728; } // (বাংলা) ডিফল্ট API পোর্ট

    if ($ip && $user && $pass) {
      $API = new RouterosAPI();
      $API->debug = false;

      if ($API->connect($ip, $user, $pass, $port, 5)) {
        // (বাংলা) সিক্রেট স্ট্যাটাস
        $secret = $API->comm("/ppp/secret/print", ["?name"=>$pppoe_id]);
        if (!empty($secret)) {
          $disabled = strtolower((string)($secret[0]['disabled'] ?? 'false'));
          $status_text = ($disabled === 'true' || $disabled === 'yes') ? 'Disabled' : 'Enabled';
        } else {
          $status_text = 'Not Found';
        }
        // (বাংলা) অ্যাক্টিভ সেশন
        $active     = $API->comm("/ppp/active/print", ["?name"=>$pppoe_id]);
        $ppp_active = !empty($active) ? true : false;

        $API->disconnect();
      } else {
        // (বাংলা) কানেক্ট না হলে Unknown
        $status_text = 'Router unreachable';
        $ppp_active  = null;
      }
    }
  }
} catch (Throwable $e) {
  $status_text = 'Router check failed';
  $ppp_active  = null;
}

/* ===================== Avatar Helper ===================== */
/* (বাংলা) ইউজারের ছবি বের করার নিয়ম:
   1) clients.photo_url থাকলে সেটাই ব্যবহার
   2) নাহলে uploads/clients/<pppoe-id>.(webp|jpg|jpeg|png)
   3) নাহলে ডিফল্ট */
function portal_client_avatar_url(array $client): string {
  $placeholder = '/assets/images/default-avatar.png';
  $url = trim($client['photo_url'] ?? '');
  if ($url !== '') {
    $abs = realpath(__DIR__ . '/../../' . ltrim($url, '/'));
    if ($abs && is_file($abs)) return $url . '?v=' . @filemtime($abs);
    return $url;
  }
  $pppoe = (string)($client['pppoe_id'] ?? '');
  $slug  = strtolower(preg_replace('/[^a-z0-9-_]+/i', '-', $pppoe));
  $slug  = trim($slug, '-_');
  if ($slug === '') return $placeholder;

  $baseAbs = realpath(__DIR__ . '/../../uploads/clients') ?: (__DIR__ . '/../../uploads/clients');
  $baseWeb = '/uploads/clients';
  foreach (['webp','jpg','jpeg','png'] as $ext) {
    $abs = $baseAbs . DIRECTORY_SEPARATOR . $slug . '.' . $ext;
    if (is_file($abs)) return $baseWeb . '/' . $slug . '.' . $ext . '?v=' . @filemtime($abs);
  }
  return $placeholder;
}
$avatar_url = portal_client_avatar_url($client);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Customer Portal</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root{
  --brand: #635BFF;
  --brand-2: #22A6F0;
  --card-glass: rgba(255,255,255,.7);
  --shadow: 0 10px 30px rgba(0,0,0,.10);
}
body{ background: linear-gradient(180deg,#f7f9fc 0%, #eef3ff 100%); }
.navbar-dark { background: linear-gradient(90deg, #111 0%, #1b1f2a 100%); }

/* Hero */
.portal-hero{
  position: relative;
  border-radius: 18px;
  background: radial-gradient(1200px 400px at 10% -20%, rgba(99,91,255,.25), rgba(99,91,255,0)) ,
              linear-gradient(135deg, #ffffff 0%, #f2f6ff 100%);
  box-shadow: var(--shadow); overflow: hidden;
}
.portal-hero::after{
  content:""; position:absolute; inset:-1px;
  background: linear-gradient(135deg, rgba(34,166,240,.18), rgba(99,91,255,.18));
  mask: radial-gradient(400px 140px at 100% 0%, black, transparent);
  pointer-events:none;
}

/* Avatar ring */
.avatar-outer{ width:124px; height:124px; padding:3px; border-radius:50%;
  background: conic-gradient(from 90deg, var(--brand), var(--brand-2), var(--brand)); }
.avatar-inner{ width:118px; height:118px; border-radius:50%; overflow:hidden;
  background:#f3f5f8; border:2px solid #fff; }

/* Pills */
.stat-pill{ display:flex; align-items:center; gap:.5rem; background:#fff;
  border:1px solid #eef1f6; border-radius:999px; padding:.4rem .75rem; box-shadow: var(--shadow); white-space:nowrap; }
.stat-pill i{ font-size:1rem; color: var(--brand); }

/* Cards */
.card-glass{ background: var(--card-glass); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
  border: 1px solid rgba(255,255,255,.6); box-shadow: var(--shadow); border-radius: 14px; }
.card-header.soft{ background: linear-gradient(90deg, rgba(99,91,255,.12), rgba(34,166,240,.12)); border-bottom: 1px solid #e9edf8; }

/* Global kv */
.kv{ display:grid; grid-template-columns: 160px 1fr; gap:.35rem 1rem; }
.kv .k{ color:#6b7280; font-weight:600; }
.kv .v{ font-weight:600; }

/* status chips */
.kchips{ display:grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap:.5rem; }
@media (min-width:768px){ .kchips{ grid-template-columns: repeat(4,minmax(0,1fr)); } }

canvas{ max-width:100%; }

/* Online/Offline/Unknown pills (scoped to portal-page) */
.portal-page .stat-pill.online{
  background: #198754; border-color: #198754; color: #fff;
}
.portal-page .stat-pill.online i{ color:#fff; }
.portal-page .stat-pill.offline{
  background:#ffecec; border-color:#ffd6d6; color:#b42318;
}
.portal-page .stat-pill.offline i{ color:#b42318; }
</style>
</head>
<body class="portal-page">

<nav class="navbar navbar-expand-lg navbar-dark">
  <div class="container">
    <a class="navbar-brand fw-bold" href="#"><i class="bi bi-speedometer2 me-1"></i> Customer Portal</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nv">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div id="nv" class="collapse navbar-collapse justify-content-end">
      <ul class="navbar-nav">
        <li class="nav-item"><a class="nav-link" href="/public/portal/logout.php">Logout (<?= h(portal_username()) ?>)</a></li>
      </ul>
    </div>
  </div>
</nav>

<div class="d-flex">
  <?php
    // (বাংলা) সাইডবার ফাইল নাম fallback: portal_sidebar.php অথবা sidebar.php
    $sb1 = __DIR__ . '/portal_sidebar.php';
    $sb2 = __DIR__ . '/sidebar.php';
    if (is_file($sb1))      { include $sb1; }
    elseif (is_file($sb2))  { include $sb2; }
    else { echo '<!-- Sidebar file not found -->'; }
  ?>

  <div class="container py-4">
    <!-- Hero -->
    <div class="portal-hero p-3 p-md-4 mb-4">
      <div class="d-flex flex-column flex-md-row align-items-center">
        <div class="me-md-4 mb-3 mb-md-0 text-center">
          <div class="avatar-outer"><div class="avatar-inner">
            <img src="<?= h($avatar_url) ?>" alt="Profile Photo" style="width:100%; height:100%; object-fit:cover;">
          </div></div>
        </div>
        <div class="flex-fill text-center text-md-start">
          <h3 class="mb-1"><?= h($client_name) ?></h3>
          <div class="text-muted mb-2"><?= h($client['package_name'] ?? 'No Package') ?></div>
          <div class="d-flex flex-wrap gap-2 justify-content-center justify-content-md-start">
            <span class="stat-pill">
              <i class="bi bi-shield-check"></i>
              <?= ($client['status'] ?? '')==='active' ? 'Active' : (($client['status'] ?? '')==='inactive' ? 'Inactive' : h($client['status'] ?? 'Unknown')) ?>
            </span>
            <?php if ($ppp_active === true): ?>
              <span class="stat-pill online"><i class="bi bi-wifi"></i> Online</span>
            <?php elseif ($ppp_active === false): ?>
              <span class="stat-pill offline"><i class="bi bi-wifi-off"></i> Offline</span>
            <?php else: ?>
              <span class="stat-pill" style="background:#f3f4f6;border-color:#e5e7eb;color:#374151">
                <i class="bi bi-question-circle"></i> Unknown
              </span>
            <?php endif; ?>
            <span class="stat-pill"><i class="bi bi-person-badge"></i> <?= h($pppoe_id ?: '-') ?></span>
          </div>
        </div>
        <div class="ms-md-4 mt-3 mt-md-0 d-flex flex-column gap-2 w-100" style="max-width:220px">
          <a href="/public/portal/invoices.php" class="btn btn-outline-primary"><i class="bi bi-receipt me-1"></i> View Invoices</a>
          <a href="/public/portal/ticket_new.php" class="btn btn-outline-warning"><i class="bi bi-life-preserver me-1"></i> Support Ticket</a>
          <!-- (বাংলা) চাইলে Pay Now যুক্ত করুন -->
          <a href="/public/portal/payments.php" class="btn btn-primary"><i class="bi bi-wallet2 me-1"></i> Pay Now</a>
        </div>
      </div>
    </div>

    <!-- Stats row -->
    <div class="row g-3 mb-3">
      <div class="col-6 col-lg-3"><div class="card card-glass p-3 h-100">
        <div class="text-muted small">Monthly Bill</div>
        <div class="fs-5 fw-bold"><?= number_format((float)($client['monthly_bill'] ?? 0),2) ?></div>
      </div></div>
      <div class="col-6 col-lg-3"><div class="card card-glass p-3 h-100">
        <div class="text-muted small">Service</div>
        <div class="fw-semibold"><?= h($status_text) ?> <?= $ppp_active === true ? '(Active)' : '' ?></div>
      </div></div>
      <div class="col-6 col-lg-3"><div class="card card-glass p-3 h-100">
        <div class="text-muted small">Package</div>
        <div class="fw-semibold"><?= h($client['package_name'] ?? '-') ?></div>
      </div></div>
      <div class="col-6 col-lg-3"><div class="card card-glass p-3 h-100">
        <div class="text-muted small">Join Date</div>
        <div class="fw-semibold"><?= h($client['join_date'] ?? '-') ?></div>
      </div></div>
      <!-- Ledger Balance -->
      <div class="col-12 col-lg-3"><div class="card card-glass p-3 h-100">
        <div class="text-muted small">Ledger Balance</div>
        <div class="d-flex align-items-center gap-2">
          <div class="fs-5 fw-bold"><?= number_format($ledger_balance, 2) ?></div>
          <span class="badge <?= ledgerBadgeClass($ledger_balance) ?>"><?= ledgerLabel($ledger_balance) ?></span>
        </div>
      </div></div>
    </div>

    <!-- Two-column -->
    <div class="row g-4">
      <div class="col-lg-5">
        <!-- Service Overview -->
        <div class="card shadow-sm border-0 service-overview">
          <div class="card-header soft fw-bold"><i class="bi bi-hdd-network me-1"></i> Service Overview</div>
          <div class="card-body">
            <div class="kv" id="sv-kv">
              <div class="k">Client</div><div class="v"><?= h($client_name) ?></div>
              <div class="k">PPPoE ID</div><div class="v"><?= h($pppoe_id ?: '-') ?></div>
              <div class="k">Package</div><div class="v"><?= h($client['package_name'] ?? '-') ?></div>
              <div class="k">Monthly Bill</div><div class="v"><?= number_format((float)($client['monthly_bill'] ?? 0),2) ?></div>
              <div class="k">Status</div><div class="v"><?= h($status_text) ?> <?= $ppp_active === true ? '(Active)' : '' ?></div>
              <div class="k">Router</div><div class="v"><?= h($client['router_name'] ?? '-') ?></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Client Details -->
      <div class="col-lg-4">
        <div class="card shadow-sm border-0 client-details">
          <div class="card-header soft fw-bold"><i class="bi bi-person-vcard me-1"></i> Client Details</div>
          <div class="card-body">
            <div class="kv">
              <div class="k">Client Code</div><div class="v"><?= h($client['client_code'] ?? '-') ?></div>
              <div class="k">Mobile</div><div class="v"><?= h($client['mobile'] ?? '-') ?></div>
              <div class="k">Email</div><div class="v"><?= h($client['email'] ?? '-') ?></div>
              <div class="k">Address</div><div class="v"><?= h($client['address'] ?? '-') ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Live Status -->
    <div class="card shadow-sm border-0 mt-4">
      <div class="card-header soft fw-bold"><i class="bi bi-activity me-1"></i> Live Connection Status</div>
      <div class="card-body">
        <div class="kchips">
          <div class="stat-pill w-100 justify-content-center"><i class="bi bi-geo-alt"></i> <span>IP: <span id="live-ip">—</span></span></div>
          <div class="stat-pill w-100 justify-content-center">
            <i class="bi bi-broadcast-pin"></i>
            <span>Status: <span id="live-status" class="badge bg-secondary" aria-live="polite">Checking...</span></span>
          </div>
          <div class="stat-pill w-100 justify-content-center"><i class="bi bi-clock-history"></i> <span>Last Seen: <span id="last-seen">—</span></span></div>
          <div class="stat-pill w-100 justify-content-center"><i class="bi bi-stopwatch"></i> <span>Uptime: <span id="uptime">—</span></span></div>
          <div class="stat-pill w-100 justify-content-center"><i class="bi bi-download"></i> <span>DL Speed: <span id="rx_speed">0</span> KB/s</span></div>
          <div class="stat-pill w-100 justify-content-center"><i class="bi bi-upload"></i> <span>UL Speed: <span id="tx_speed">0</span> KB/s</span></div>
          <div class="stat-pill w-100 justify-content-center"><i class="bi bi-archive"></i> <span>Total DL: <span id="total-dl">0</span> GB</span></div>
          <div class="stat-pill w-100 justify-content-center"><i class="bi bi-archive-fill"></i> <span>Total UL: <span id="total-ul">0</span> GB</span></div>
        </div>
      </div>
    </div>

    <!-- Live Speed Graph (with Controls) -->
    <div class="card shadow-sm border-0 mt-4">
      <div class="card-header soft fw-bold d-flex align-items-center justify-content-between">
        <span><i class="bi bi-graph-up-arrow me-1"></i> Live Speed Graph</span>
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <!-- (বাংলা) গ্রাফ কন্ট্রোলস -->
          <div class="input-group input-group-sm" style="width:auto">
            <label class="input-group-text" for="unitSel">Unit</label>
            <select id="unitSel" class="form-select">
              <option value="bps" selected>bps</option>
              <option value="kbps">Kbps</option>
              <option value="mbps">Mbps</option>
            </select>
          </div>
          <div class="input-group input-group-sm" style="width:auto">
            <label class="input-group-text" for="winSel">Window</label>
            <select id="winSel" class="form-select">
              <option value="20" selected>~20 pts</option>
              <option value="60">~60 pts</option>
              <option value="300">~300 pts</option>
            </select>
          </div>
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" id="smoothChk" checked>
            <label class="form-check-label small" for="smoothChk">Smooth</label>
          </div>
          <button id="playBtn" class="btn btn-sm btn-primary"><i class="bi bi-play-fill me-1"></i>Play</button>
          <button id="pauseBtn" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pause-fill me-1"></i>Pause</button>
          <button id="exportBtn" class="btn btn-sm btn-outline-dark"><i class="bi bi-download me-1"></i>Export PNG</button>
        </div>
      </div>
      <div class="card-body">
        <canvas id="trafficChart" height="140"></canvas>
        <div class="small text-muted mt-2">
          Graph polls <code>/api/client_status.php?id=<?= (int)$client_id; ?></code> every 5s.
        </div>
      </div>
    </div>

    <!-- Billing Info -->
    <div class="card shadow-sm border-0 mt-4 mb-5">
      <div class="card-header soft fw-bold"><i class="bi bi-cash-coin me-1"></i> Billing Info</div>
      <div class="card-body row g-3">
        <div class="col-md-4"><div class="card card-glass p-3 h-100">
          <div class="text-muted small">Monthly Bill</div>
          <div class="fs-5 fw-bold"><?= number_format((float)($client['monthly_bill'] ?? 0),2) ?></div>
        </div></div>
        <div class="col-md-4"><div class="card card-glass p-3 h-100">
          <div class="text-muted small">Last Payment Date</div>
          <div class="fw-semibold"><?= h($client['last_payment_date'] ?? '-') ?></div>
        </div></div>
        <div class="col-md-4"><div class="card card-glass p-3 h-100">
          <div class="text-muted small">Payment Status</div>
          <div class="fw-semibold"><?= h($client['payment_status'] ?? '-') ?></div>
        </div></div>
      </div>
    </div>

  </div><!-- /container -->
</div><!-- /d-flex -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
// ===================== Live status polling (বাংলা) =====================
// API: /api/client_live_status.php -> { online:true|false|null, ip, last_seen, uptime, rx_speed, tx_speed, total_download_gb, total_upload_gb }

let liveInterval = null;
function loadLiveStatus() {
  fetch('/api/client_live_status.php?id=<?= (int)$client_id; ?>')
    .then(res => res.ok ? res.json() : Promise.resolve({}))
    .then(data => {
      document.getElementById('live-ip').innerText    = (data && data.ip) || '—';
      document.getElementById('last-seen').innerText  = (data && data.last_seen) || '—';
      document.getElementById('uptime').innerText     = (data && data.uptime) || '—';
      document.getElementById('rx_speed').innerText   = (data && (data.rx_speed ?? data.rx_kbps)) ?? 0;
      document.getElementById('tx_speed').innerText   = (data && (data.tx_speed ?? data.tx_kbps)) ?? 0;
      document.getElementById('total-dl').innerText   = (data && (data.total_download_gb ?? data.total_dl_gb)) ?? 0;
      document.getElementById('total-ul').innerText   = (data && (data.total_upload_gb   ?? data.total_ul_gb)) ?? 0;

      const st = document.getElementById('live-status');
      if (data && data.online === true) { st.innerText = 'Online';  st.className = 'badge bg-success'; }
      else if (data && data.online === false) { st.innerText = 'Offline'; st.className = 'badge bg-danger'; }
      else { st.innerText = 'Unknown'; st.className = 'badge bg-secondary'; }
    })
    .catch(() => {
      const st = document.getElementById('live-status');
      st.innerText = 'Unknown';
      st.className = 'badge bg-secondary';
    });
}
function startLivePolling(){
  if (liveInterval) return;
  liveInterval = setInterval(() => { if(!document.hidden) loadLiveStatus(); }, 2000);
}
function stopLivePolling(){
  if (!liveInterval) return;
  clearInterval(liveInterval); liveInterval = null;
}
document.addEventListener('visibilitychange', () => {
  if (document.hidden) stopLivePolling(); else startLivePolling();
});
loadLiveStatus(); startLivePolling();
</script>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// ===================== Live Speed Graph (বাংলা) =====================
// API: /api/client_status.php -> { rx|rx_bps, tx|tx_bps }
// Controls: Play/Pause, unit(bps/kbps/mbps), window size, smoothing, export

const canvas = document.getElementById('trafficChart');
let trafficChart = null;
let chartTimer   = null;

// (বাংলা) Helper: bps → unit কনভার্সন
function convertBps(v, unit){
  const x = Number(v || 0);
  if (unit === 'kbps') return x / 1_000;
  if (unit === 'mbps') return x / 1_000_000;
  return x; // bps
}
function shortUnit(u){ return (u || 'bps').toUpperCase(); }

// (বাংলা) DOM refs
const unitSel   = document.getElementById('unitSel');
const winSel    = document.getElementById('winSel');
const smoothChk = document.getElementById('smoothChk');
const playBtn   = document.getElementById('playBtn');
const pauseBtn  = document.getElementById('pauseBtn');
const exportBtn = document.getElementById('exportBtn');

// (বাংলা) চার্ট ইনিট
if (window.Chart && canvas) {
  const ctx = canvas.getContext('2d');
  const chartData = {
    labels: [],
    datasets: [
      { label: 'Download', data: [], borderColor: 'blue',  fill:false, tension:.25 },
      { label: 'Upload',   data: [], borderColor: 'green', fill:false, tension:.25 }
    ]
  };
  trafficChart = new Chart(ctx, {
    type: 'line',
    data: chartData,
    options: {
      responsive:true,
      animation:false,
      plugins:{
        legend:{ display:true },
        tooltip:{
          callbacks:{
            // (বাংলা) টুলটিপে সিলেক্টেড ইউনিট দেখানো
            label: function(c){
              const unit = unitSel.value || 'bps';
              return `${c.dataset.label}: ${c.formattedValue} ${shortUnit(unit)}`;
            }
          }
        }
      },
      scales:{
        y:{ beginAtZero:true, ticks:{ callback:(v)=>v.toLocaleString() } }
      }
    }
  });

  // (বাংলা) এক ফেচ → গ্রাফে পুশ
  function pushPoint(rx_bps, tx_bps){
    const unit = unitSel.value || 'bps';
    const maxPoints = parseInt(winSel.value || '20', 10);

    const ts = new Date().toLocaleTimeString();
    const rx = convertBps(rx_bps, unit);
    const tx = convertBps(tx_bps, unit);

    const d = trafficChart.data;
    d.labels.push(ts);
    d.datasets[0].data.push(rx);
    d.datasets[1].data.push(tx);

    // (বাংলা) উইন্ডো লিমিট
    while (d.labels.length > maxPoints) {
      d.labels.shift();
      d.datasets[0].data.shift();
      d.datasets[1].data.shift();
    }

    trafficChart.update();
  }

  // (বাংলা) API পোল
  function pollOnce(){
    if (document.hidden) return; // ট্যাব হিডেন হলে স্কিপ
    fetch('/api/client_status.php?id=<?= (int)$client_id; ?>')
      .then(r => r.ok ? r.json() : Promise.resolve({}))
      .then(j => {
        // (বাংলা) ফিল্ড ফোলব্যাক: rx_bps|rx, tx_bps|tx ধরা হলো
        const rx = j.rx_bps ?? j.rx ?? 0;
        const tx = j.tx_bps ?? j.tx ?? 0;
        pushPoint(rx, tx);
      })
      .catch(()=>{/* ignore */});
  }

  // (বাংলা) প্লে/পজ কন্ট্রোল
  function startPolling(){
    if (chartTimer) return;
    pollOnce(); // ইনস্ট্যান্ট ১টা পয়েন্ট
    chartTimer = setInterval(pollOnce, 5000);
    playBtn.disabled  = true;
    pauseBtn.disabled = false;
  }
  function stopPolling(){
    if (!chartTimer) return;
    clearInterval(chartTimer); chartTimer = null;
    playBtn.disabled  = false;
    pauseBtn.disabled = true;
  }

  // (বাংলা) কন্ট্রোল হুক
  playBtn.addEventListener('click', startPolling);
  pauseBtn.addEventListener('click', stopPolling);
  exportBtn.addEventListener('click', () => {
    const url = trafficChart.toBase64Image('image/png', 1.0);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'live_speed_graph.png';
    a.click();
  });

  unitSel.addEventListener('change', ()=> trafficChart.update());
  winSel.addEventListener('change', ()=>{
    // (বাংলা) points window বদলালে সাথে সাথে prune করে দিন
    const maxPoints = parseInt(winSel.value || '20', 10);
    const d = trafficChart.data;
    while (d.labels.length > maxPoints) {
      d.labels.shift();
      d.datasets[0].data.shift();
      d.datasets[1].data.shift();
    }
    trafficChart.update();
  });
  smoothChk.addEventListener('change', ()=>{
    const t = smoothChk.checked ? .25 : 0;
    trafficChart.data.datasets.forEach(ds => ds.tension = t);
    trafficChart.update();
  });

  // (বাংলা) পেজ ভিজিবিলিটি অনুযায়ী অটো পজ/রিজিউম
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) stopPolling(); else startPolling();
  });

  // (বাংলা) ডিফল্ট: অটো-প্লে
  startPolling();
}
</script>

<!-- =================== SCOPED SPACING RULES =================== -->
<style>
/* Client Details: tighter two-column spacing (scoped) */
.client-details .kv{
  grid-template-columns: 100px 1fr;
  column-gap: .40rem;
  row-gap: .35rem;
}
.client-details .kv .k,.client-details .kv .v{
  text-align:left; justify-self:start; margin:0; padding:0;
}
.client-details .kv .v{ word-break:break-word; overflow-wrap:anywhere; }

/* Service Overview: label = content-width, gap small (scoped) */
.service-overview .kv{
  grid-template-columns: 110px 1fr;
  column-gap: .30rem !important;
  row-gap: .35rem !important;
}
.service-overview .kv .k,.service-overview .kv .v{
  text-align:left !important; justify-self:start !important; margin:0 !important; padding:0 !important;
}
.service-overview .kv .k{ white-space:nowrap !important; }

@media (max-width:576px){
  .client-details .kv{ grid-template-columns: 100px 1fr; column-gap:.35rem; }
  .service-overview .kv{ column-gap:.25rem !important; }
}
</style>

</body>
</html>
