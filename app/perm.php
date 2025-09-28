<?php
// /app/perm.php
// Simple Role-Based Access for Expense module


require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

/* -------- small introspection helpers (schema-aware) -------- */
function _tbl_exists(PDO $pdo, string $t): bool{
  try{
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $q  = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
    $q->execute([$db,$t]);
    return (bool)$q->fetchColumn();
  }catch(Throwable $e){ return false; }
}
function _col_exists(PDO $pdo, string $t, string $c): bool{
  try{
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $q  = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
    $q->execute([$db,$t,$c]);
    return (bool)$q->fetchColumn();
  }catch(Throwable $e){ return false; }
}

/* -------- ensure users.role column (best-effort) -------- */
// নোট: তোমার সিস্টেমে টেবিলের নাম users / admins — দুটোই চেষ্টা করি।
function perm_bootstrap_role_column(): array {
  $pdo = db(); $table = null;
  foreach (['users','admins'] as $t) {
    if (_tbl_exists($pdo,$t)) { $table = $t; break; }
  }
  if (!$table) return [null,null];

  // role না থাকলে ADD করি; DEFAULT viewer রাখি
  if (!_col_exists($pdo,$table,'role')) {
    try { $pdo->exec("ALTER TABLE `$table` ADD COLUMN `role` VARCHAR(20) NOT NULL DEFAULT 'viewer'"); }
    catch(Throwable $e){ /* ignore */ }
  }

  // id=1 থাকলে admin করে দেই (first user owner ধরে)
  try {
    $id1 = $pdo->query("SELECT id FROM `$table` ORDER BY id ASC LIMIT 1")->fetchColumn();
    if ($id1) {
      $st = $pdo->prepare("UPDATE `$table` SET `role`='admin' WHERE id=? AND (`role` IS NULL OR `role`='viewer')");
      $st->execute([$id1]);
    }
  } catch(Throwable $e){ /* ignore */ }

  return [$pdo,$table];
}

/* -------- role resolve -------- */
function perm_current_role(): string {
  // সেশন থেকে role পড়ি, না থাকলে DB
  if (!empty($_SESSION['user']['role'])) return (string)$_SESSION['user']['role'];
  if (!empty($_SESSION['role']))         return (string)$_SESSION['role'];

  [$pdo,$table] = perm_bootstrap_role_column();
  if (!$pdo || !$table) return 'viewer';

  // logged-in user id বের করি (require_login.php সাধারণত সেট করে)
  $uid = null;
  if (!empty($_SESSION['user']['id'])) $uid = (int)$_SESSION['user']['id'];
  elseif (!empty($_SESSION['user_id'])) $uid = (int)$_SESSION['user_id'];
  if (!$uid) return 'viewer';

  try{
    $st = $pdo->prepare("SELECT `role` FROM `$table` WHERE id=?");
    $st->execute([$uid]);
    $role = (string)($st->fetchColumn() ?: 'viewer');
  }catch(Throwable $e){ $role = 'viewer'; }

  // সেশনে ক্যাশ
  $_SESSION['user']['role'] = $role;
  $_SESSION['role'] = $role;
  return $role;
}

/* -------- permission map -------- */
// রোল: admin, manager, accountant, viewer
// পারমিশন কী:
//  - expense.view, expense.add, expense.edit, expense.delete
//  - expense.accounts (accounts CRUD)
//  - expense.categories (categories CRUD)
function perm_map(): array {
  return [
    'admin' => ['*'], // সবকিছু পারবে
    'manager' => [
      'expense.view','expense.add','expense.edit','expense.delete',
      'expense.accounts','expense.categories'
    ],
    'accountant' => [
      'expense.view','expense.add','expense.edit',
      'expense.accounts','expense.categories'
    ],
    'viewer' => ['expense.view']
  ];
}

/* -------- allow/deny helpers -------- */
function can(string $permission): bool {
  $role = perm_current_role();
  $map  = perm_map();
  $perms = $map[$role] ?? [];
  if (in_array('*',$perms,true)) return true;
  return in_array($permission,$perms,true);
}

function perm_require(string $permission){
  if (can($permission)) return;
  // 403 দেখাই
  if (!headers_sent()) {
    header($_SERVER['SERVER_PROTOCOL'].' 403 Forbidden');
  }
  // simple 403 view থাকলে সেখানে পাঠাই
  $p = __DIR__ . '/../public/403.php';
  if (is_file($p)) { require $p; }
  else { echo "<h3 style='font-family:sans-serif;color:#dc3545'>403 Forbidden</h3><p>Permission required: <code>".htmlspecialchars($permission)."</code></p>"; }
  exit;
}

/* ইউটিল: admin ইউজাররা কারো role সেট করতে চাইলে */
function perm_set_role(int $user_id, string $role): bool {
  [$pdo,$table] = perm_bootstrap_role_column();
  if (!$pdo || !$table) return false;
  $role = strtolower($role);
  if (!array_key_exists($role, perm_map())) return false;
  try{
    $st=$pdo->prepare("UPDATE `$table` SET `role`=? WHERE id=?");
    return $st->execute([$role,$user_id]);
  }catch(Throwable $e){ return false; }
}
