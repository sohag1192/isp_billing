<?php // /partials/partials_header.php
/* (বাংলা) সেশন + পেজ টাইটেল */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$page_title = trim($page_title ?? '') ?: '';
/* Active slug from page (optional) */
$__active = trim($GLOBALS['_active'] ?? ''); // e.g., 'dashboard', 'clients', 'billing' ...
?>

<?php
/* (বাংলা) সেশন/হেল্পার সেফগার্ড */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/* (বাংলা) লগিন ইউজারের ডিসপ্লে নেম/রোল */
$__u   = $_SESSION['user'] ?? [];
$__nm  = $__u['full_name'] ?? $__u['name'] ?? $__u['username'] ?? 'User';
$__rl  = $__u['role'] ?? 'user';
?>

<!doctype html>
<html lang="en">
<head>

  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></title>
  <!-- Bootstrap & Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <!-- Site CSS (keep your global overrides) -->
  <link rel="stylesheet" href="/assets/css/style.css">

  <!-- CSS STYLE   -->
  <?php include __DIR__ . '/../assets/css/style_partial.css'; ?>
  

  <!-- Sidebar UX fix: main vs submenu clearly differentiated -->
  
  <style>
    /* Container scroll */
    .sidebar-scroll{ padding: .5rem; }

    /* Main buttons (top-level) */
    .sidebar .btn-menu{
      display:flex; align-items:center; gap:.55rem; width:100%;
      padding:.55rem .75rem; border-radius:.6rem;
      font-weight:600; text-align:left;
      color: var(--bs-body-color); background: transparent;
    }
    .sidebar .btn-menu .menu-label{ flex:1; }
    .sidebar .btn-menu .menu-caret{ transition: transform .2s ease; }
    .sidebar .btn-menu[aria-expanded="true"] .menu-caret{ transform: rotate(180deg); }

    /* Active/hover for main */
    .sidebar .btn-menu:hover{ background: var(--bs-secondary-bg); }
    .sidebar .btn-menu.active{
      background: var(--bs-primary-bg-subtle);
      color: var(--bs-primary);
    }

    /* Submenu wrapper: subtle left rail + inset background */
    .sidebar .submenu{
      margin:.25rem 0 .5rem .25rem;
      padding:.25rem 0 .25rem .25rem;
      border-left: 2px solid var(--bs-border-color);
      border-radius:.25rem;
    }

    /* Submenu items */
    .sidebar .submenu .btn-menu{
      font-weight:500; font-size:.95rem;
      padding:.5rem .75rem .5rem 1.6rem;
      position:relative; border-radius:.5rem;
      color: var(--bs-body-color);
      background: transparent;
    }
    /* Bullet dot for submenu */
    .sidebar .submenu .btn-menu::before{
      content:""; position:absolute; left:.75rem; top:50%;
      width:6px; height:6px; border-radius:50%;
      background: currentColor; opacity:.45; transform: translateY(-50%);
    }

    /* Submenu hover/active */
    .sidebar .submenu .btn-menu:hover{
      background: var(--bs-secondary-bg);
    }
    .sidebar .submenu .btn-menu.active{
      background: var(--bs-primary-bg-subtle);
      color: var(--bs-primary);
      font-weight:600;
    }

    /* Badges inside items keep spacing to right */
    .sidebar .menu-badge{ margin-left:auto; }
  </style>
</head>
<body class="app-shell">

<!-- =============== Topbar =============== -->
<nav class="navbar topbar sticky-top navbar-dark">
  <div class="container-fluid">
    <!-- Mobile: open sidebar -->
    <button class="btn btn-outline-light d-md-none" type="button"
      data-bs-toggle="offcanvas" data-bs-target="#sidebarOffcanvas" aria-controls="sidebarOffcanvas">
      <i class="bi bi-list"></i>
    </button>

    <!-- Desktop: collapse/expand -->
    <button id="btnSidebarToggle" class="btn btn-outline-light d-none d-md-inline-flex me-1" type="button" title="Toggle sidebar">
      <i class="bi bi-layout-sidebar"></i>
    </button>
    <a class="navbar-brand ms-2 d-flex align-items-center gap-2" href="/public/index.php">
      <img src="/assets/img/logo.png" alt="ISP Billing">
    </a>
    <div class="d-flex align-items-center gap-2 ms-auto">

      <!-- Quick Search (desktop) -->
      <form class="d-none d-sm-flex align-items-center position-relative flex-shrink-0 me-2"
            id="nav-search-form" action="/public/clients.php" method="get" autocomplete="off" role="search">
        <div class="input-group input-group-sm" id="nav-search-group">
          <span class="input-group-text py-1"><i class="bi bi-search"></i></span>
          <input type="text" class="form-control form-control-sm" id="nav-search-input"
                 name="search" placeholder="Search user from database">
          <button class="btn btn-sm" type="submit"><i class="bi bi-arrow-right"></i></button>
        </div>
        <div id="nav-suggest" class="suggest-box d-none"></div>
      </form>

      <!-- Right buttons -->
      <a href="/public/tickets.php" class="btn btn-outline-light btn-sm d-none d-sm-inline">
        <i class="bi bi-life-preserver"></i> Tickets
      </a>

      <a href="/public/logout.php" class="btn btn-danger btn-sm">
        <i class="bi bi-box-arrow-right"></i> Logout
      </a>

      <!-- Profile Topbar (fixed invalid <a> nesting) -->
      <div class="nav-item d-flex align-items-center ms-2 text-white">
        <i class="bi bi-person-circle me-1"></i>
        <a class="fw-semibold link-light text-decoration-none" href="/public/profile.php"><?php echo h($__nm); ?></a>
        <span class="badge bg-secondary ms-2"><?php echo h($__rl); ?></span>
      </div>
    </div>
  </div>
</nav>

<!-- =============== Mobile Quick Search =============== -->
<div class="mobile-search p-2 d-flex d-sm-none">
  <form class="w-100 position-relative" id="nav-search-form-m" action="/public/clients.php" method="get" autocomplete="off" role="search">
    <div class="input-group input-group-sm" id="nav-search-group-m">
      <span class="input-group-text"><i class="bi bi-search"></i></span>
      <input type="text" class="form-control" id="nav-search-input-m" name="search" placeholder="Search...">
      <button class="btn" type="submit">Go</button>
    </div>
    <div id="nav-suggest-m" class="suggest-box d-none"></div>
  </form>
</div>

<?php
/* (বাংলা) ছোট হেল্পার: approval badge কাউন্ট */
@require_once __DIR__ . '/../app/db.php';
if (!function_exists('can_approve')) {
  function can_approve(): bool {
    $is_admin = (int)($_SESSION['user']['is_admin'] ?? 0);
    $role = strtolower((string)($_SESSION['user']['role'] ?? ''));
    return $is_admin === 1 || in_array($role, ['admin','superadmin','manager','accounts','accountant','billing'], true);
  }
}
$__pending_approvals = 0;
try {
  $pdo = db();
  $__pending_approvals = (int)$pdo->query("SELECT COUNT(*) FROM wallet_transfers WHERE status='pending'")->fetchColumn();
} catch (Throwable $e) { $__pending_approvals = 0; }

/* (বাংলা) active helper */
function is_active($slug){ global $__active; return ($__active === $slug) ? ' active' : ''; }

/* collapse states by section */
$openClients  = in_array($__active, ['clients','clients_online','clients_offline','clients_active','clients_inactive','clients_expired','clients_left','client_add'], true);
$openBilling  = in_array($__active, ['billing','due_report','due_report_pro','invoices','invoice_new','collections'], true);
$openRouters  = in_array($__active, ['routers','router_add'], true);
$openOLT      = in_array($__active, ['olts','olt_tools','onu_tools','olt_mac','olt_sfp','olt_pon','onu_monitor','admin_tools'], true);
$openAccounts = in_array($__active, ['wallets','wallet_approvals','wallet_settlement'], true);
$openSMS      = in_array($__active, ['sms'], true);
$openReports  = in_array($__active, ['reports','report_package_wise','payment_report','income_expense','expenses'], true);
/* FIX: HR open state was missing */
$openHR       = in_array($__active, ['hr_employees','hr_employee_toggle'], true);

function hasPermission($permissionKey) {
  global $pdo; // PDO database connection
  if (($_SESSION['role_id'] ?? null) == 1) { // super admin short-circuit
    return true;
  }
  if (!isset($_SESSION['role_id'])) {
    return false;
  }
  $roleId = (int)$_SESSION['role_id'];
  $sql = "
    SELECT p.perm_key
    FROM permissions p
    INNER JOIN role_permissions rp ON rp.permission_id = p.id
    WHERE rp.role_id = :role_id AND p.perm_key = :perm_key
    LIMIT 1
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([':role_id' => $roleId, ':perm_key' => $permissionKey]);
  return $stmt->rowCount() > 0;
}
?>

<!-- =============== Layout =============== -->
<div class="layout">

  <!-- =============== Sidebar (offcanvas-md) =============== -->
  <aside class="offcanvas offcanvas-start offcanvas-md sidebar" tabindex="-1" id="sidebarOffcanvas" aria-labelledby="sidebarLabel">
    <div class="offcanvas-header border-bottom border-secondary d-md-none">
      <h6 class="offcanvas-title m-0" id="sidebarLabel">
        <i class="bi bi-router-fill me-1"></i> ISP Billing
      </h6>
      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
    </div>

    <div class="offcanvas-body p-0">
      <nav class="sidebar-scroll" id="sidebarAccordion">
        <ul class="list-unstyled m-0">

          <!-- Dashboard -->
          <?php if (hasPermission('view.dashboard')){ ?>
          <li>
            <a href="/public/index.php" class="btn btn-menu<?php echo is_active('dashboard'); ?>">
              <i class="bi bi-speedometer2"></i> <span class="menu-label">Dashboard</span>
            </a>
          </li>
          <?php } ?>

          <!-- Clients -->
          <?php if (hasPermission('client.view')){ ?>
          <li>
            <button class="btn btn-menu w-100<?php echo $openClients?' active':''; ?>" data-bs-toggle="collapse"
                    data-bs-target="#clientsMenu" aria-expanded="<?php echo $openClients?'true':'false'; ?>">
              <i class="bi bi-people-fill"></i> <span class="menu-label">Clients</span>
              <i class="bi bi-caret-down-fill small ms-auto menu-caret"></i>
            </button>

            <ul class="collapse submenu list-unstyled <?php echo $openClients?'show':''; ?>" id="clientsMenu">
              <li><a href="/public/client_add.php" class="btn btn-menu<?php echo is_active('client_add'); ?>"><i class="bi bi-plus-circle"></i> Add Client</a></li>
              <li><a href="/public/clients.php" class="btn btn-menu<?php echo is_active('clients'); ?>"><i class="bi bi-list-ul"></i> All Clients</a></li>
			  <li><a href="/public/all_clientt_info.php" class="btn btn-menu<?php echo is_active('clients'); ?>"><i class="bi bi-list-ul"></i> All Client Info</a></li>
			  
			  
              <li><a href="/public/clients_online.php" class="btn btn-menu<?php echo is_active('clients_online'); ?>"><i class="bi bi-wifi"></i> Online Clients</a></li>
              <li><a href="/public/clients_offline.php" class="btn btn-menu<?php echo is_active('clients_offline'); ?>"><i class="bi bi-wifi-off"></i> Offline Clients</a></li>
              <li><a href="/public/client_list_by_status.php?status=active" class="btn btn-menu<?php echo is_active('clients_active'); ?>"><i class="bi bi-check-circle"></i> Active Clients</a></li>
              <li><a href="/public/client_list_by_status.php?status=inactive" class="btn btn-menu<?php echo is_active('clients_inactive'); ?>"><i class="bi bi-slash-circle"></i> Inactive Clients</a></li>
              <li><a href="/public/client_list_by_status.php?status=expired" class="btn btn-menu<?php echo is_active('clients_expired'); ?>"><i class="bi bi-hourglass"></i> Expired Clients</a></li>
              <li><a href="/public/suspended_clients.php" class="btn btn-menu"><i class="bi bi-hourglass"></i>Auto Inactive Client</a></li>
              <li><a href="/public/client_list_by_status.php?status=left" class="btn btn-menu<?php echo is_active('clients_left'); ?>"><i class="bi bi-box-arrow-left"></i> Left Clients</a></li>
              <li><a href="/public/client_ledger.php" class="btn btn-menu"><i class="bi bi-file-earmark-ruled"></i>Clients Ledger</a></li>
			</ul>
          </li>
          <?php } ?>

          <!-- Billing -->
          <?php if (hasPermission('view.billing')){ ?>
          <li>
            <button class="btn btn-menu w-100<?php echo $openBilling?' active':''; ?>" data-bs-toggle="collapse"
              data-bs-target="#billingMenu" aria-expanded="<?php echo $openBilling?'true':'false'; ?>">
              <i class="bi bi-receipt"></i> <span class="menu-label">Billing</span>
              <i class="bi bi-caret-down-fill small ms-auto menu-caret"></i>
            </button>
            <ul class="collapse submenu list-unstyled <?php echo $openBilling?'show':''; ?>" id="billingMenu">
              <li><a href="/public/billing.php" class="btn btn-menu<?php echo is_active('billing'); ?>"><i class="bi bi-list-check"></i> All Bills</a></li>
              <li><a href="/public/due_report_pro.php" class="btn btn-menu<?php echo is_active('due_report_pro'); ?>"><i class="bi bi-exclamation-triangle"></i> Due Bills</a></li>
			  <li><a href="/public/invoices.php?status=paid" class="btn btn-menu"><i class="bi bi-check2-circle"></i> Paid Bills</a></li>
              <li><a href="/public/invoices.php" class="btn btn-menu<?php echo is_active('invoices'); ?>"><i class="bi bi-file-text"></i> Invoices</a></li>
              <li><a href="/public/invoice_new.php" class="btn btn-menu<?php echo is_active('invoice_new'); ?>"><i class="bi bi-file-earmark-plus"></i> New Invoice</a></li>
              <li><a href="/public/collections.php?when=today" class="btn btn-menu<?php echo is_active('collections'); ?>"><i class="bi bi-calendar-day"></i> Today's Collection</a></li>
              <li><a href="/public/collections.php" class="btn btn-menu"><i class="bi bi-calendar2-week"></i> All Collection</a></li>
            </ul>
          </li>
          <?php } ?>

  <!-- Accounts -->
          <?php
            $accountMenu = hasPermission('view.wallets') || hasPermission('wallet.approval') || hasPermission('wallet.settlement');
            if ($accountMenu){ ?>
          <li class="nav-item">
            <a href="#" class="btn btn-menu w-100<?php echo $openAccounts?' active':''; ?>" data-bs-toggle="collapse" data-bs-target="#accountsMenu" aria-expanded="<?php echo $openAccounts?'true':'false'; ?>" role="button">
              <i class="bi bi-cash-stack"></i> <span class="menu-label">Accounts</span>
              <i class="bi bi-caret-down-fill small ms-auto menu-caret"></i>
            </a>
            <ul class="collapse submenu list-unstyled <?php echo $openAccounts?'show':''; ?>" id="accountsMenu">
              <?php if (hasPermission('view.wallets')){ ?>
                <li><a href="/public/wallets.php" class="btn btn-menu<?php echo is_active('wallets'); ?>"><i class="bi bi-wallet2"></i> Wallets</a></li>
				<li><a href="/public/wallets_dashboard.php" class="btn btn-menu"> <i class="bi bi-wallet-fill"></i> Wallet Dashboard</a></li>
              <?php } ?>
             
                <?php if (can_approve()): ?>
                  <li>
                    <a href="/public/wallet_approvals.php" class="btn btn-menu<?php echo is_active('wallet_approvals'); ?>">
                      <i class="bi bi-check2-square"></i> Approvals
                      <?php if ($__pending_approvals): ?>
                        <span class="badge bg-success ms-auto menu-badge"><?= $__pending_approvals ?></span>
                      <?php endif; ?>
                    </a>
                  </li>
                <?php endif; ?>
              
                <li><a href="/public/wallet_settlement.php" class="btn btn-menu<?php echo is_active('wallet_settlement'); ?>"><i class="bi bi-arrow-left-right"></i> Settlement</a></li>
				<li><a href="/public/report_payments.php" class="btn btn-menu<?php echo is_active('payment_report'); ?>"><i class="bi bi-receipt"></i> <span class="menu-label">Payment Report</span></a></li>
				<li><a href="/public/payment_report.php" class="btn btn-menu<?php echo is_active('payment_report'); ?>"><i class="bi bi-receipt"></i> <span class="menu-label">Payment Report Invoice</span></a></li>
				<li><a href="/public/income_expense.php" class="btn btn-menu<?php echo is_active('income_expense'); ?>"><i class="bi bi-graph-up-arrow"></i> <span class="menu-label">Income vs Expense</span></a></li>
				<li><a href="/public/expenses.php" class="btn btn-menu<?php echo is_active('expenses'); ?>"><i class="bi bi-currency-exchange"></i> <span class="menu-label">Expenses</span></a></li>
				<li><a href="/public/expense_add.php" class="btn btn-menu<?php echo is_active('expense_add'); ?>"><i class="bi bi-plus-square"></i> <span class="menu-label">Add Expense</span></a></li>
            </ul>
          </li>
          <?php } ?>


         
          <?php if (hasPermission('routers')){ ?>
          <li>
            <button class="btn btn-menu w-100<?php echo $openRouters?' active':''; ?>" data-bs-toggle="collapse"
                    data-bs-target="#routersMenu" aria-expanded="<?php echo $openRouters?'true':'false'; ?>">
              <i class="bi bi-hdd-network"></i> <span class="menu-label">Network</span>
              <i class="bi bi-caret-down-fill small ms-auto menu-caret"></i>
            </button>
            <ul class="collapse submenu list-unstyled <?php echo $openRouters?'show':''; ?>" id="routersMenu">
              <li><a href="/public/router_add.php" class="btn btn-menu<?php echo is_active('router_add'); ?>"><i class="bi bi-plus-circle"></i> Add Router</a></li>
              <li><a href="/public/routers.php" class="btn btn-menu<?php echo is_active('routers'); ?>"><i class="bi bi-list-ul"></i> Routers List</a></li>
			  <li><a href="/public/packages.php" class="btn btn-menu<?php echo is_active('packages'); ?>"><i class="bi bi-box2-fill"></i> <span class="menu-label">Packages</a></li>
			  <li><a href="/public/import_clients.php" class="btn btn-menu<?php echo is_active('packages'); ?>"><i class="bi bi-upload"></i> <span class="menu-label">import client Csv</a></li>
			  <li><a href="/public/import_mikrotik_client.php" class="btn btn-menu<?php echo is_active('packages'); ?>"><i class="bi bi-capslock-fill"></i> <span class="menu-label">import From Mikrotik</a></li>
            </ul>
          </li>
          <?php } ?> 

          <!-- OLT & 
          <?php if (hasPermission('olt.view')){ ?>
          <li>
            <button class="btn btn-menu w-100<?php echo $openOLT?' active':''; ?>" data-bs-toggle="collapse"
                    data-bs-target="#oltMenu" aria-expanded="<?php echo $openOLT?'true':'false'; ?>">
              <i class="bi bi-diagram-3"></i> <span class="menu-label">OLT & Tools</span>
              <i class="bi bi-caret-down-fill small ms-auto menu-caret"></i>
            </button>
            <ul class="collapse submenu list-unstyled <?php echo $openOLT?'show':''; ?>" id="oltMenu">
              <li><a href="/olt/index.php" class="btn btn-menu<?php echo is_active('olts'); ?>"><i class="bi bi-pc-display"></i> OLTs</a></li>
              <li><a href="/public/olt_mac_table.php" class="btn btn-menu<?php echo is_active('olt_mac'); ?>"><i class="bi bi-table"></i> OLT MAC Table</a></li>
              <li><a href="/public/onu_tools.php" class="btn btn-menu<?php echo is_active('onu_tools'); ?>"><i class="bi bi-magic"></i> ONU Tools</a></li>
              <li><a href="/public/admin_tools.php" class="btn btn-menu<?php echo is_active('admin_tools'); ?>"><i class="bi bi-hammer"></i> Tools</a></li>
              <li><a href="/public/olt_sfp.php" class="btn btn-menu<?php echo is_active('olt_sfp'); ?>"><i class="bi bi-lightning"></i> ALL SFP</a></li>
              <li><a href="/public/olt_pon.php" class="btn btn-menu<?php echo is_active('olt_pon'); ?>"><i class="bi bi-diagram-2"></i> ALL PON</a></li>
              <li><a href="/public/onu_monitor.php" class="btn btn-menu<?php echo is_active('onu_monitor'); ?>"><i class="bi bi-broadcast"></i> ALL ONU</a></li>
            </ul>
          </li>
          <?php } ?> & Tools -->

     



          <!-- HR -->
          <?php if (hasPermission('hrm.view')){ ?>
          <li>
            <button class="btn btn-menu w-100<?php echo $openHR?' active':''; ?>" data-bs-toggle="collapse"
                    data-bs-target="#hrMenu" aria-expanded="<?php echo $openHR?'true':'false'; ?>">
              <i class="bi bi-person-workspace"></i> <span class="menu-label">HRM</span>
              <i class="bi bi-caret-down-fill small ms-auto menu-caret"></i>
            </button>
            <ul class="collapse submenu list-unstyled <?php echo $openHR?'show':''; ?>" id="hrMenu">
				<li><a href="/public/users_permission.php" class="btn btn-menu<?php echo is_active('users_permission'); ?>"><i class="bi bi-box2-fill"></i> <span class="menu-label">User Permission</span></a></li>
				<li><a href="/public/users.php" class="btn btn-menu<?php echo is_active('users'); ?>"><i class="bi bi-people"></i> <span class="menu-label">Users</span></a></li>
				<li><a href="/public/hr/employees.php" class="btn btn-menu<?php echo is_active('hr_employees'); ?>"><i class="bi bi-people"></i> Employees (All)</a></li>
				<li><a href="/public/hr/employee_toggle.php" class="btn btn-menu<?php echo is_active('hr_employee_toggle'); ?>"><i class="bi bi-toggle-on"></i> Employee Toggle</a></li>
            </ul>
          </li>
          <?php } ?>

        

          <!-- SMS -->
          <?php
            $smsMenu = hasPermission('send.sms') || hasPermission('send.sms.bulk') || hasPermission('delivered.sms') || hasPermission('pending.sms');
            if ($smsMenu){ ?>
          <li>
            <button class="btn btn-menu w-100<?php echo $openSMS?' active':''; ?>" data-bs-toggle="collapse"
                    data-bs-target="#smsMenu" aria-expanded="<?php echo $openSMS?'true':'false'; ?>">
              <i class="bi bi-chat-left-dots"></i> <span class="menu-label">SMS</span>
              <i class="bi bi-caret-down-fill small ms-auto menu-caret"></i>
            </button>
            <ul class="collapse submenu list-unstyled <?php echo $openSMS?'show':''; ?>" id="smsMenu">
              <li><a href="#" class="btn btn-menu"><i class="bi bi-chat-dots"></i> Send SMS</a></li>
              <li><a href="#" class="btn btn-menu"><i class="bi bi-chat-dots"></i> Send SMS Bulk</a></li>
              <li><a href="#" class="btn btn-menu"><i class="bi bi-envelope-check"></i> Delivered SMS</a></li>
              <li><a href="#" class="btn btn-menu"><i class="bi bi-hourglass-split"></i> Pending SMS</a></li>
            </ul>
          </li>
          <?php } ?>


         
          <!-- Reports -->
          <?php if (hasPermission('report.view')){ ?>
          <li>
            <button class="btn btn-menu w-100<?php echo $openReports?' active':''; ?>" data-bs-toggle="collapse"
                    data-bs-target="#reportsMenu" aria-expanded="<?php echo $openReports?'true':'false'; ?>">
              <i class="bi bi-bar-chart-line-fill"></i> <span class="menu-label">Reports</span>
              <i class="bi bi-caret-down-fill small ms-auto menu-caret"></i>
            </button>
            <ul class="collapse submenu list-unstyled <?php echo $openReports?'show':''; ?>" id="reportsMenu">
             
                <li><a href="/public/tickets.php" class="btn btn-menu<?php echo is_active('tickets'); ?>"><i class="bi bi-life-preserver"></i> Tickets</a></li>
				<li><a href="/public/due_report.php" class="btn btn-menu<?php echo is_active('due_report'); ?>"><i class="bi bi-exclamation-triangle"></i> Due Report</a></li>
                <li><a href="/public/due_report_pro.php" class="btn btn-menu<?php echo is_active('due_report_pro'); ?>"><i class="bi bi-exclamation-triangle"></i> Due Report Pro</a></li>
				<li><a href="#" class="btn btn-menu<?php echo is_active('bill_report'); ?>"><i class="bi bi-graph-up"></i> Bill Report</a></li>
				<li><a href="#" class="btn btn-menu<?php echo is_active('payment_reports'); ?>"><i class="bi bi-cash-coin"></i> Payment Reports</a></li>
                <li><a href="#" class="btn btn-menu<?php echo is_active('export_clients'); ?>"><i class="bi bi-upload"></i> Export Clients</a></li>
                <li><a href="#" class="btn btn-menu<?php echo is_active('print_reports'); ?>"><i class="bi bi-printer"></i> Print Reports</a></li>
                <li><a href="/public/report_package_wise.php" class="btn btn-menu<?php echo is_active('report_package_wise'); ?>"><i class="bi bi-diagram-3"></i> Package-wise Report</a></li>
            </ul>
          </li>
          <?php } ?>
		  
		  
    <!-- SMS -->
          <li>
            <button class="btn btn-menu w-100" data-bs-toggle="collapse"
                    data-bs-target="#smsMenu" aria-expanded=" >
              <i class="bi bi-chat-left-dots"></i> <span class="menu-label">Settings</span>
              <i class="bi bi-caret-down-fill small ms-auto menu-caret"></i>
            </button>
            <ul class="collapse submenu list-unstyled " id="smsMenu">
              <li><a class="dropdown-item" href="/tg/settings.php"><i class="bi bi-sliders"></i>TG Settings</a></li>

            </ul>
          </li>
       

		  
          <!-- Logout -->
          <li>
            <a href="/public/logout.php" class="btn btn-menu text-danger">
              <i class="bi bi-box-arrow-right"></i> <span class="menu-label">Logout</span>
            </a>
          </li>

        </ul>
      </nav>
    </div>
  </aside>

  <!-- =============== Main Content Starts =============== -->
  <main class="content-area">
    <!-- page content goes here -->
