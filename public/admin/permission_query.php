<?php
// /public/admin/permissions_query.php
// UI English; Comments বাংলা — Create a permission code safely.

declare(strict_types=1);

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/app/require_login.php';
require_once $ROOT . '/app/db.php';
require_once $ROOT . '/app/acl.php';

if (!(acl_is_admin_role() || acl_is_username_admin())) {
  acl_forbid_403('Admin only.');
}

$token = $_POST['csrf_token'] ?? '';
if (!$token || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$token)) {
  acl_forbid_403('Invalid CSRF.');
}

$code = trim((string)($_POST['code'] ?? ''));
$code = strtolower($code);

// বাংলা: ইনপুট ভ্যালিডেশন — a-z0-9 . : * - _
if ($code === '' || !preg_match('/^[a-z0-9\.\:\*\-\_]+$/', $code)) {
  http_response_code(422);
  echo "Invalid permission code.";
  exit;
}

$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ensure permissions table exists
function tbl_exists(PDO $pdo, string $t): bool {
  $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
  $st=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
  $st->execute([$db,$t]); return (bool)$st->fetchColumn();
}
if (!tbl_exists($pdo,'permissions')) {
  http_response_code(500);
  echo "Missing table: permissions. Run /tools/seed_hr_perms.php";
  exit;
}

// upsert-like (IGNORE on duplicate)
$st = $pdo->prepare("INSERT IGNORE INTO permissions(code) VALUES(?)");
$st->execute([$code]);

header('Location: /public/admin/permissions.php?created=1');
exit;
