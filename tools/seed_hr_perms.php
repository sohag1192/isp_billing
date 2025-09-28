<?php
// /tools/seed_hr_perms.php
// Purpose: Create minimal RBAC tables if missing and seed HR permissions & role bindings.
// Code English; Comments Bengali.

declare(strict_types=1);

$ROOT = dirname(__DIR__);
require_once $ROOT . '/app/db.php';

header('Content-Type: text/plain; charset=utf-8');

function dbh(): PDO {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  return $pdo;
}

/* ---------- helpers: schema ---------- */
// বাংলা: টেবিল/কলাম আছে কিনা চেক
function tbl_exists(PDO $pdo, string $t): bool {
  $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
  $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
  $st->execute([$db, $t]);
  return (bool)$st->fetchColumn();
}
function col_exists(PDO $pdo, string $tbl, string $col): bool {
  $st = $pdo->prepare("SHOW COLUMNS FROM `$tbl` LIKE ?");
  $st->execute([$col]);
  return (bool)$st->fetchColumn();
}

/* ---------- ensure tables ---------- */
$pdo = dbh();
echo "== RBAC Seed Start ==\n";

$created = [];

if (!tbl_exists($pdo,'roles')) {
  $pdo->exec("
    CREATE TABLE roles (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(64) NOT NULL UNIQUE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
  $created[] = 'roles';
}
if (!tbl_exists($pdo,'permissions')) {
  $pdo->exec("
    CREATE TABLE permissions (
      id INT AUTO_INCREMENT PRIMARY KEY,
      code VARCHAR(128) NOT NULL UNIQUE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
  $created[] = 'permissions';
}
if (!tbl_exists($pdo,'role_permissions')) {
  $pdo->exec("
    CREATE TABLE role_permissions (
      role_id INT NOT NULL,
      permission_id INT NOT NULL,
      PRIMARY KEY (role_id, permission_id),
      CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
      CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
  $created[] = 'role_permissions';
}
echo $created ? ("Created tables: ".implode(', ',$created)."\n") : "Tables exist.\n";

/* ---------- upsert role helper ---------- */
function upsert_role(PDO $pdo, string $name): int {
  $st = $pdo->prepare("SELECT id FROM roles WHERE name=?");
  $st->execute([$name]);
  $id = (int)$st->fetchColumn();
  if ($id > 0) return $id;
  $ins = $pdo->prepare("INSERT INTO roles(name) VALUES(?)");
  $ins->execute([$name]);
  return (int)$pdo->lastInsertId();
}

/* ---------- upsert permission helper ---------- */
function upsert_perm(PDO $pdo, string $code): int {
  $st = $pdo->prepare("SELECT id FROM permissions WHERE code=?");
  $st->execute([$code]);
  $id = (int)$st->fetchColumn();
  if ($id > 0) return $id;
  $ins = $pdo->prepare("INSERT INTO permissions(code) VALUES(?)");
  $ins->execute([$code]);
  return (int)$pdo->lastInsertId();
}

/* ---------- bind helper ---------- */
function bind_role_perm(PDO $pdo, int $role_id, int $perm_id): void {
  $st = $pdo->prepare("INSERT IGNORE INTO role_permissions(role_id, permission_id) VALUES(?,?)");
  $st->execute([$role_id,$perm_id]);
}

/* ---------- seed roles ---------- */
// বাংলা: মিনিমাম ৩টি রোল — admin / hr_manager / viewer
$role_admin     = upsert_role($pdo, 'admin');
$role_manager   = upsert_role($pdo, 'hr_manager');
$role_viewer    = upsert_role($pdo, 'viewer');

echo "Roles: admin#$role_admin, hr_manager#$role_manager, viewer#$role_viewer\n";

/* ---------- seed permissions ---------- */
// বাংলা: HR পারমিশন + hr.* (ওয়াইল্ডকার্ড) + '*' (গ্লোবাল) — '*' আপনি চাইলে ব্যবহার করবেন
$perm_view   = upsert_perm($pdo, 'hr.view');
$perm_add    = upsert_perm($pdo, 'hr.add');
$perm_edit   = upsert_perm($pdo, 'hr.edit');
$perm_export = upsert_perm($pdo, 'hr.export');
$perm_hr_all = upsert_perm($pdo, 'hr.*');   // বাংলা: hr namespace wildcard
$perm_star   = upsert_perm($pdo, '*');      // বাংলা: গ্লোবাল wildcard (ঐচ্ছিক)

echo "Permissions seeded.\n";

/* ---------- grants ---------- */
// admin → '*' (অথবা hr.*); দুটোই বেঁধে দিচ্ছি যাতে আপনার ACL যে কোনোটাই কাজে লাগে
bind_role_perm($pdo, $role_admin,   $perm_star);
bind_role_perm($pdo, $role_admin,   $perm_hr_all);

// hr_manager → view/add/edit/export
foreach ([$perm_view,$perm_add,$perm_edit,$perm_export] as $pid) {
  bind_role_perm($pdo, $role_manager, $pid);
}

// viewer → শুধুই view/export (read-only)
foreach ([$perm_view,$perm_export] as $pid) {
  bind_role_perm($pdo, $role_viewer, $pid);
}

echo "Grants assigned.\n";

/* ---------- optional: assign users by username ---------- */
// বাংলা: যদি users টেবিলে role_id থাকে, এই ইউজারদের রোল অ্যাসাইন করে দিবে (থাকলে তবেই)
if (tbl_exists($pdo,'users') && col_exists($pdo,'users','role_id') && col_exists($pdo,'users','username')) {
  $map = [
    'admin'  => $role_admin,
    'swapon' => $role_manager,
    'bapa'   => $role_viewer,
    'demo'   => $role_viewer,
  ];
  $sel = $pdo->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
  $upd = $pdo->prepare("UPDATE users SET role_id=? WHERE id=?");
  $assigned = 0;
  foreach ($map as $uname=>$rid) {
    $sel->execute([$uname]);
    $uid = (int)$sel->fetchColumn();
    if ($uid > 0) {
      $upd->execute([$rid,$uid]);
      $assigned++;
    }
  }
  echo "User role assignments updated: $assigned\n";
} else {
  echo "Skipped user role assignments (users.role_id or users.username not found).\n";
}

/* ---------- done ---------- */
echo "== RBAC Seed Complete ==\n";

// বাংলা: সব ঠিক থাকলে CLI আউটপুট দেখাবে; ব্রাউজারে plain text রিটার্ন হবে।
