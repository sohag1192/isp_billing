<?php
// (বাংলা) Safe helpers
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$active = function (string $needle) use ($uri) {
  // (বাংলা) শুধু path-এর basename মিলাই, কুয়েরি/ফোল্ডার ইগনোর
  $b1 = basename($uri);
  $b2 = basename($needle);
  return ($b1 === $b2) ? 'active' : '';
};

// (বাংলা) ইউজারনেম/অবতার fallback
if (!function_exists('portal_username')) {
  function portal_username() { return $_SESSION['username'] ?? $_SESSION['pppoe_id'] ?? 'Customer'; }
}
$avatar_url = ($avatar_url ?? '/assets/images/default-avatar.png');

// (বাংলা) অ্যাকাউন্ট সামারি ডেটা (নিজের সোর্স বসান)
$portal = [
  'package'     => $_SESSION['package_name']   ?? 'Home 20 Mbps',
  'next_due'    => $_SESSION['next_due_date']  ?? null,     // '2025-09-10'
  'ledger'      => $_SESSION['ledger_balance'] ?? 0.00,     // +ve=Due, -ve=Advance
  'status'      => $_SESSION['service_status'] ?? 'active', // active/suspended
  'pppoe'       => $_SESSION['pppoe_id']       ?? ($_SESSION['username'] ?? ''),
];

// (বাংলা) লেজার ব্যাজ রঙ/লেবেল
$ledger = (float)$portal['ledger'];
$ledgerBadge = ($ledger > 0.0001) ? 'danger' : (($ledger < -0.0001) ? 'success' : 'secondary');
$ledgerLabel = ($ledger > 0.0001) ? 'Due' : (($ledger < -0.0001) ? 'Advance' : 'Settled');

// (বাংলা) কানেকশন স্ট্যাটাস
$online = ($portal['status'] === 'active');

// (বাংলা) বর্তমান URL রেখে lang কুয়েরি আপডেট ইউটিল
$reqUri = $_SERVER['REQUEST_URI'] ?? '/';
$parts  = parse_url($reqUri);
parse_str($parts['query'] ?? '', $qs);
$mkUrl = function(string $lang) use ($parts, $qs){
  $tmp = $qs; $tmp['lang'] = $lang;
  $q = http_build_query($tmp);
  $path = $parts['path'] ?? '/';
  return $path . ($q ? ('?' . $q) : '');
};
?>
<style>
:root{ --portal-brand:#635BFF; --portal-brand2:#22A6F0; --nav-h:38px; }
@media (max-width: 600px){
  .custom-navbar{padding:4px 0;min-height:var(--nav-h);}
  .custom-sidebar{top:var(--nav-h);height:calc(100% - var(--nav-h));}
  body{padding-top:20px;}
}
.custom-sidebar{
  background: linear-gradient(180deg,#1f2433 0%,#10131b 100%);
  color:#fff; border-right:1px solid rgba(255,255,255,.08);
}
.custom-sidebar .offcanvas-header{
  background: linear-gradient(90deg, rgba(99,91,255,.15), rgba(34,166,240,.15));
  border-bottom:1px solid rgba(255,255,255,.08);
}
.offcanvas-lg{ width: 295px; }

/* User/summary cards */
.sidebar-user,.summary-card{
  background: rgba(255,255,255,.07);
  border: 1px solid rgba(255,255,255,.12);
  border-radius: 14px;
}
.sidebar-user{ padding:.75rem; display:flex; align-items:center; gap:.75rem; }
.sidebar-avatar-ring{ width:44px;height:44px;padding:2px;border-radius:50%;
  background: conic-gradient(from 90deg, var(--portal-brand), var(--portal-brand2), var(--portal-brand));
}
.sidebar-avatar{ width:40px;height:40px;border-radius:50%;overflow:hidden;border:2px solid #fff;position:relative;}
.sidebar-avatar.skeleton::after{
  content:""; position:absolute; inset:0;
  background: linear-gradient(90deg, rgba(255,255,255,.05), rgba(255,255,255,.12), rgba(255,255,255,.05));
  animation: shimmer 1.2s infinite;
}
@keyframes shimmer{ 0%{transform:translateX(-100%)} 100%{transform:translateX(100%)} }
.sidebar-username{ font-weight:700; line-height:1.15; }
.sidebar-pppoe{ font-size:.8rem; opacity:.85; }

/* Nav pills */
.sidebar-nav .sidebar-link{
  display:flex; align-items:center; gap:.55rem; width:100%; text-align:left;
  color:#e9ecf7; background: rgba(255,255,255,.05);
  border:1px solid rgba(255,255,255,.10); border-radius: 12px;
  padding:.55rem .75rem; transition:.18s ease; text-decoration:none;
}
.sidebar-nav .sidebar-link:hover{
  background: rgba(255,255,255,.10); border-color: rgba(255,255,255,.18); transform: translateY(-1px);
}
.sidebar-nav .sidebar-link.active{
  background: linear-gradient(90deg, rgba(99,91,255,.30), rgba(34,166,240,.30));
  border-color: rgba(255,255,255,.28); color:#fff; box-shadow: 0 8px 22px rgba(0,0,0,.20);
}
.sidebar-nav .sidebar-link.logout{ background: rgba(220,53,69,.12); border-color: rgba(220,53,69,.28); }
.sidebar-nav .sidebar-link.logout:hover{ background: rgba(220,53,69,.18); }
.sidebar-section-title{ font-size:.78rem; letter-spacing:.06em; text-transform:uppercase; opacity:.75; margin:.5rem .1rem; }

/* Summary card */
.summary-card{ padding:.75rem; }
.summary-kv{ display:grid; grid-template-columns: 1fr auto; row-gap:.25rem; column-gap:.5rem; font-size:.92rem; }
.theme-toggle{ display:flex; align-items:center; gap:.5rem; cursor:pointer; user-select:none; font-size:.9rem; opacity:.9; }
.lang-toggle{ font-size:.9rem; opacity:.9; }
</style>

<!-- Navbar (mobile only) -->
<nav class="navbar navbar-dark bg-primary fixed-top d-lg-none custom-navbar">
  <div class="container-fluid">
    <button class="navbar-toggler p-1" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarMenu" aria-controls="sidebarMenu" aria-label="Toggle sidebar">
      <span class="navbar-toggler-icon"></span>
    </button>
    <span class="navbar-brand ms-2 small"><?= htmlspecialchars(portal_username()) ?></span>
  </div>
</nav>

<!-- Sidebar -->
<div class="offcanvas-lg offcanvas-start text-white custom-sidebar" tabindex="-1" id="sidebarMenu" aria-labelledby="sidebarMenuLabel">
  <div class="offcanvas-header py-2">
    <h6 class="offcanvas-title d-flex align-items-center gap-2" id="sidebarMenuLabel">
      <i class="bi bi-router"></i> Customer Menu
    </h6>
    <div class="d-flex align-items-center gap-3">
      <!-- Theme toggle (বাংলা: লাইট/ডার্ক টগল) -->
      <label class="theme-toggle" title="Theme">
        <input id="themeToggle" type="checkbox" class="form-check-input m-0" style="cursor:pointer">
        <span id="themeLabel" class="d-none d-lg-inline">Light</span>
      </label>
      <button type="button" class="btn-close btn-close-white btn-sm d-lg-none" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
  </div>

  <div class="offcanvas-body p-3 d-flex flex-column gap-3">
    <!-- User card -->
    <div class="sidebar-user">
      <div class="sidebar-avatar-ring">
        <div class="sidebar-avatar skeleton">
          <img src="<?= htmlspecialchars($avatar_url) ?>" alt="Avatar" style="width:100%;height:100%;object-fit:cover;"
               onload="this.parentElement.classList.remove('skeleton')"
               onerror="this.src='/assets/images/default-avatar.png';this.parentElement.classList.remove('skeleton')">
        </div>
      </div>
      <div class="flex-grow-1">
        <div class="sidebar-username d-flex align-items-center gap-2">
          <?= htmlspecialchars(portal_username()) ?>
          <span class="badge rounded-pill <?= $online ? 'text-bg-success' : 'text-bg-danger' ?>" title="Service status">
            <?= $online ? 'Online' : 'Suspended' ?>
          </span>
        </div>
        <div class="sidebar-pppoe"><?= htmlspecialchars($portal['pppoe'] ?: 'Signed in') ?></div>
      </div>
    </div>

    <!-- Account summary -->
    <div class="summary-card">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <strong class="small text-uppercase opacity-75">Account Summary</strong>
        <a href="/public/portal/bkash.php" class="btn btn-sm btn-outline-light">Pay Now</a>
      </div>
      <div class="summary-kv">
        <div>Package</div>      <div class="fw-semibold"><?= htmlspecialchars($portal['package']) ?></div>
        <div>Next Due</div>     <div class="fw-semibold"><?= htmlspecialchars($portal['next_due'] ?: '—') ?></div>
        <div>Ledger</div>       <div><span class="badge text-bg-<?= $ledgerBadge ?>"><?= number_format($ledger,2) ?> <?= $ledgerLabel ?></span></div>
      </div>
    </div>

    <!-- Quick actions -->
    <div>
      <div class="sidebar-section-title">Quick Actions</div>
      <ul class="nav flex-column sidebar-nav gap-2">
        <li class="nav-item">
          <a class="sidebar-link <?= $active('/index.php') ?>" href="/public/portal/index.php">
            <i class="bi bi-house"></i> <span>Dashboard</span>
          </a>
        </li>
        <li class="nav-item">
          <!-- (বাংলা) FIX: My Invoices এখন সঠিক পেজে যায় -->
          <a class="sidebar-link <?= $active('/invoices.php') ?>" href="/public/portal/invoices.php">
            <i class="bi bi-file-text"></i> <span>My Invoices</span>
          </a>
        </li>
        <li class="nav-item">
          <a class="sidebar-link <?= $active('/payments.php') ?>" href="/public/portal/payments.php">
            <i class="bi bi-cash-coin"></i> <span>Payments</span>
          </a>
        </li>
        <li class="nav-item">
          <a class="sidebar-link" href="https://fast.com" target="_blank" rel="noopener">
            <i class="bi bi-speedometer2"></i> <span>Speed Test</span>
          </a>
        </li>
        <li class="nav-item">
          <a class="sidebar-link <?= $active('/ticket_new.php') ?>" href="/public/portal/ticket_new.php">
            <i class="bi bi-life-preserver"></i> <span>Support Tickets</span>
          </a>
        </li>
      </ul>
    </div>

    <!-- Footer actions -->
    <div class="mt-auto d-flex justify-content-between align-items-center">
      <a class="sidebar-link logout" href="/public/portal/logout.php" title="Logout">
        <i class="bi bi-box-arrow-right"></i> <span>Logout</span>
      </a>
      <div class="lang-toggle">
        <a href="<?= htmlspecialchars($mkUrl('en')) ?>" class="link-light text-decoration-none me-2">EN</a> |
        <a href="<?= htmlspecialchars($mkUrl('bn')) ?>" class="link-light text-decoration-none ms-2">BN</a>
      </div>
    </div>
  </div>
</div>

<script>
// (বাংলা) থিম টগল: localStorage persist + ডাইনামিক লেবেল
(function(){
  const key='portalThemeDark';
  const html=document.documentElement;
  const cb=document.getElementById('themeToggle');
  const lbl=document.getElementById('themeLabel');
  const setLbl=()=>{ if(!lbl||!cb) return; lbl.textContent = cb.checked ? 'Dark' : 'Light'; };

  const saved=localStorage.getItem(key);
  if(saved==='1'){ html.dataset.bsTheme='dark'; cb.checked=true; }
  else{ html.dataset.bsTheme='light'; cb.checked=false; }
  setLbl();

  cb?.addEventListener('change',()=> {
    if(cb.checked){ html.dataset.bsTheme='dark'; localStorage.setItem(key,'1'); }
    else{ html.dataset.bsTheme='light'; localStorage.setItem(key,'0'); }
    setLbl();
  });
})();
</script>
