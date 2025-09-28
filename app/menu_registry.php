<?php
// /app/menu_registry.php
// বাংলা: পুরো অ্যাপের মেনু রেজিস্ট্রি। এখানে লিস্ট/অ্যারে আকারে মেনু ডিফাইন করা হয়।
// প্রতিটি item: key, label, icon, url, perm (optional), children (optional)

if (!defined('MENU_REGISTRY_V1')) define('MENU_REGISTRY_V1', 1);

return [
  // Section: Main
  [
    'section' => 'Main',
    'items' => [
      ['key'=>'dashboard', 'label'=>'Dashboard', 'icon'=>'bi-speedometer2', 'url'=>'/public/index.php'],
    ],
  ],

  // Section: Clients
  [
    'section' => 'Clients',
    'items' => [
      ['key'=>'clients', 'label'=>'Clients', 'icon'=>'bi-people', 'url'=>'/public/clients.php', 'perm'=>'clients.view'],
      ['key'=>'client_add', 'label'=>'Add Client', 'icon'=>'bi-person-plus', 'url'=>'/public/client_add.php', 'perm'=>'clients.add'],
    ],
  ],

  // Section: Billing
  [
    'section' => 'Billing',
    'items' => [
      ['key'=>'billing', 'label'=>'Billing', 'icon'=>'bi-cash-coin', 'url'=>'/public/billing.php', 'perm'=>'billing.view'],
      ['key'=>'invoices', 'label'=>'Invoices', 'icon'=>'bi-receipt', 'url'=>'/public/invoices.php', 'perm'=>'invoices.view'],
      ['key'=>'payments', 'label'=>'Payments', 'icon'=>'bi-credit-card', 'url'=>'/public/payments.php', 'perm'=>'payments.view'],
    ],
  ],

  // Section: Network
  [
    'section' => 'Network',
    'items' => [
      ['key'=>'routers', 'label'=>'Routers', 'icon'=>'bi-hdd-network', 'url'=>'/public/routers.php', 'perm'=>'routers.view'],
      ['key'=>'packages', 'label'=>'Packages', 'icon'=>'bi-box', 'url'=>'/public/packages.php', 'perm'=>'packages.view'],
    ],
  ],

  // Section: HR
  [
    'section' => 'HR',
    'items' => [
      ['key'=>'employees', 'label'=>'Employees', 'icon'=>'bi-person-badge', 'url'=>'/public/hr/employees.php', 'perm'=>'hr.view'],
      ['key'=>'employee_add', 'label'=>'Add Employee', 'icon'=>'bi-person-plus', 'url'=>'/public/hr/employee_add.php', 'perm'=>'hr.add'],
      ['key'=>'hr_toggle', 'label'=>'Toggle (Active/Left)', 'icon'=>'bi-toggle2-on', 'url'=>'/public/hr/employees.php?focus=toggle', 'perm'=>'hr.toggle'],
    ],
  ],

  // Section: Admin
  [
    'section' => 'Admin',
    'items' => [
      ['key'=>'roles_perms', 'label'=>'Roles & Permissions', 'icon'=>'bi-shield-lock', 'url'=>'/public/users_permission.php', 'perm'=>'users.manage'],
      ['key'=>'users_menu_access', 'label'=>'User Menu Access', 'icon'=>'bi-list-check', 'url'=>'/public/users_menu_access.php', 'perm'=>'users.manage'],
      ['key'=>'settings', 'label'=>'Settings', 'icon'=>'bi-gear', 'url'=>'/public/settings.php', 'perm'=>'settings.view'],
    ],
  ],
];
