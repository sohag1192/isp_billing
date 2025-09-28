<?php
// /public/admin/role_bind_query.php
// UI English; Comments Bengali
// Purpose: Update users.role_id safely (Admin-only)

declare(strict_types=1);

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/app/require_login.php';
require_once $ROOT . '/app/db.php';
require_once $ROOT . '/app/acl.php';

if (!(acl_is_admin_role() || acl_is_username_admin())) {
  acl_forbid_403('Admin only: role assignment action.');
}

function dbh(): PDO { $pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); return $pdo; }

// CSRF
$token = $_POST['csrf_token'] ?? '';
if (!$token || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$token)) {
  acl_forbid_403('Invalid CSRF token.');
}

$user_id = (int)($_POST['user_id'] ?? 0);
$role_id = (int)($_POST['role_id'] ?? 0);

if ($user_id <= 0 || $role_id <= 0) {
  http_response_code(422);
  echo "Invalid input.";
  exit;
}

$pdo = dbh();

/* ---------- schema sanity (বাংলা: স্কিমা যাচাই) ---------- */
function tbl_exists(PDO $pdo, string $t): bool {
  $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
  $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
  $st->execute([$db,$t]);
  return (bool)$st->fetchColumn();
}
function col_exists(PDO $pdo, string $tbl, string $col): bool {
  $st = $pdo->prepare("SHOW COLUMNS FROM `$tbl` LIKE ?");
  $st->execute([$col]);
  return (bool)$st->fetchColumn();
}

if (!tbl_exists($pdo,'users') || !col_exists($pdo,'users','role_id') || !tbl_exists($pdo,'roles')) {
  http_response_code(500);
  echo "Missing users/roles table or users.role_id column.";
  exit;
}

// Prevent demoting built-in admin username accidentally (optional safety)
$st = $pdo->prepare("SELECT username FROM users WHERE id=? LIMIT 1");
$st->execute([$user_id]);
$uname = strtolower((string)$st->fetchColumn());
if ($uname === 'admin' && $user_id !== (int)($_SESSION['SESS_USER_ID'] ?? $_SESSION['user_id'] ?? 0)) {
  // বাংলা: admin ইউজারের রোল চেঞ্জ করতে চাইলে, ইচ্ছা করলে ব্লক করুন
  // এখানে allow করছি—চাইলে নিচের লাইন আনকমেন্ট করে ব্লক করতে পারেন।
  // acl_forbid_403('Changing role for built-in admin is restricted.');
}

/* ---------- update ---------- */
$up = $pdo->prepare("UPDATE users SET role_id=? WHERE id=?");
$up->execute([$role_id, $user_id]);

// ছোট্ট টোস্ট দেখাতে চাইলে কুয়েরি স্ট্রিংয়ে flag দিন
header('Location: /public/admin/roles.php?saved=1');
exit;
