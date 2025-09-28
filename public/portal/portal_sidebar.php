<?php
// (বাংলা) Active link হাইলাইট করার ছোট হেল্পার
$uri = $_SERVER['REQUEST_URI'] ?? '';
$active = function(string $needle) use ($uri) {
  return (strpos($uri, $needle) !== false) ? 'active' : '';
};

// (বাংলা) অবতার URL (না থাকলে ডিফল্ট)
// যদি আপনার পেজে আগে $avatar_url সেট করা থাকে, সেটাই ব্যবহার হবে
$avatar_url = $avatar_url ?? '/assets/images/default-avatar.png';
?>
<style>
:root{
  --portal-brand: #635BFF;
  --portal-brand2:#22A6F0;
  --nav-h: 38px; /* (বাংলা) মোবাইল navbar height */
}

/* ---------- Mobile-only top navbar ---------- */
@media (max-width: 600px) {
  .custom-navbar{
    padding-top:4px; padding-bottom:4px; min-height: var(--nav-h);
  }
  /* (বাংলা) Offcanvas-কে navbar এর নিচ থেকে শুরু করাই */
  .custom-sidebar{
    top: var(--nav-h);
    height: calc(100% - var(--nav-h));
  }
  body{ padding-top:20px; }
}

/* ---------- Sidebar Theme (glass + gradient) ---------- */
.custom-sidebar{
  background:
    linear-gradient(180deg, rgba(31,36,51,1) 0%, rgba(16,19,27,1) 100%);
  color:#fff;
  border-right: 1px solid rgba(255,255,255,.08);
}
.custom-sidebar .offcanvas-header{
  background: linear-gradient(90deg, rgba(99,91,255,.15), rgba(34,166,240,.15));
  border-bottom: 1px solid rgba(255,255,255,.08);
}
.offcanvas-lg{ width: 285px; }

/* ---------- User card ---------- */
.sidebar-user{
  background: rgba(255,255,255,.08);
  border: 1px solid rgba(255,255,255,.12);
  border-radius: 14px;
  padding: .75rem;
  display:flex; align-items:center; gap:.75rem;
}
.sidebar-avatar-ring{
  width:44px; height:44px; padding:2px; border-radius:50%;
  background: conic-gradient(from 90deg, var(--portal-brand), var(--portal-brand2), var(--portal-brand));
}
.sidebar-avatar{
  width:40px; height:40px; border-radius:50%; overflow:hidden; border:2px solid #fff;
}
.sidebar-username{ font-weight:700; line-height:1.15; }
.sidebar-pppoe{ font-size:.8rem; opacity:.85; }

/* ---------- Links as “pills” ---------- */
.sidebar-nav .sidebar-link{
  display:flex; align-items:center; gap:.55rem;
  width:100%; text-align:left; color:#e9ecf7;
  background: rgba(255,255,255,.05);
  border:1px solid rgba(255,255,255,.10);
  border-radius: 12px;
  padding:.55rem .75rem;
  transition: all .18s ease;
  text-decoration:none;
}
.sidebar-nav .sidebar-link:hover{
  background: rgba(255,255,255,.10);
  border-color: rgba(255,255,255,.18);
  transform: translateY(-1px);
}
.sidebar-nav .sidebar-link i{ font-size:1rem; opacity:.95; }
.sidebar-nav .sidebar-link.active{
  background: linear-gradient(90deg, rgba(99,91,255,.30), rgba(34,166,240,.30));
  border-color: rgba(255,255,255,.28);
  color:#fff;
  box-shadow: 0 8px 22px rgba(0,0,0,.20);
}
.sidebar-nav .sidebar-link.logout{
  background: rgba(220,53,69,.12);
  border-color: rgba(220,53,69,.28);
}
.sidebar-nav .sidebar-link.logout:hover{
  background: rgba(220,53,69,.18);
}

/* ---------- Spacing ---------- */
.sidebar-section-title{
  font-size:.78rem; letter-spacing:.06em; text-transform:uppercase;
  opacity:.75; margin: .5rem .1rem;
}
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
      <i class="bi bi-ui-checks-grid"></i> Customer Menu
    </h6>
    <button type="button" class="btn-close btn-close-white btn-sm d-lg-none" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>

  <div class="offcanvas-body p-3 d-flex flex-column gap-3">
    <!-- User mini card -->
    <div class="sidebar-user">
      <div class="sidebar-avatar-ring">
        <div class="sidebar-avatar">
          <img src="<?= htmlspecialchars($avatar_url) ?>" alt="Avatar" style="width:100%;height:100%;object-fit:cover;">
        </div>
      </div>
      <div class="flex-grow-1">
        <div class="sidebar-username"><?= htmlspecialchars(portal_username()) ?></div>
        <div class="sidebar-pppoe">Signed in</div>
      </div>
    </div>

    <!-- Nav group -->
    <div>
      <div class="sidebar-section-title">Navigation</div>
      <ul class="nav flex-column sidebar-nav gap-2">
        <li class="nav-item">
          <a class="sidebar-link <?= $active('/public/portal/index.php') ?>" href="/public/portal/index.php">
            <i class="bi bi-house"></i> <span>Dashboard</span>
          </a>
        </li>
        <li class="nav-item">
          <a class="sidebar-link <?= $active('/public/portal/invoices.php') ?>" href="/public/portal/invoices.php">
            <i class="bi bi-file-text"></i> <span> Invoices</span>
          </a>
        </li>
        <li class="nav-item">
          <a class="sidebar-link <?= $active('/public/portal/ticket_new.php') ?>" href="/public/portal/ticket_new.php">
            <i class="bi bi-life-preserver"></i> <span>Support Tickets</span>
          </a>
        </li>
      </ul>
    </div>

    <!-- Danger zone / Logout -->
    <div class="mt-auto">
      <div class="sidebar-section-title">Account</div>
      <a class="sidebar-link logout" href="/public/portal/logout.php">
        <i class="bi bi-box-arrow-right"></i> <span>Logout</span>
      </a>
    </div>
  </div>
</div>
