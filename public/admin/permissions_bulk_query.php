<?php
// /public/admin/permissions_bulk_query.php
// UI English; Comments বাংলা — Bulk grant/revoke permissions to roles/users.

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

$perm_ids   = array_values(array_unique(array_map('intval', $_POST['perm_ids'] ?? [])));
$role_add   = array_values(array_unique(array_map('intval', $_POST['role_ids'] ?? [])));
$role_del   = array_values(array_unique(array_map('intval', $_POST['role_ids_remove'] ?? [])));

$user_allow_add = array_values(array_unique(array_map('intval', $_POST['user_allow_ids'] ?? [])));
$user_allow_del = array_values(array_unique(array_map('intval', $_POST['user_allow_ids_remove'] ?? [])));

$user_deny_add = array_values(array_unique(array_map('intval', $_POST['user_deny_ids'] ?? [])));
$user_deny_del = array_values(array_unique(array_map('intval', $_POST['user_deny_ids_remove'] ?? [])));

if (!$perm_ids) {
  http_response_code(422);
  echo "No permissions selected.";
  exit;
}

$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// schema guards
function tbl_exists(PDO $pdo, string $t): bool {
  $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
  $st=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
  $st->execute([$db,$t]); return (bool)$st->fetchColumn();
}
foreach (['permissions','roles','users','role_permissions'] as $t) {
  if (!tbl_exists($pdo,$t)) { http_response_code(500); echo "Missing table: $t"; exit; }
}
foreach (['user_permissions','user_permission_denies'] as $t) {
  if (!tbl_exists($pdo,$t)) { http_response_code(500); echo "Missing table: $t (run /tools/seed_user_perms.php)"; exit; }
}

$pdo->beginTransaction();
try {
  /* ---------- roles: grant ---------- */
  if ($role_add) {
    $ins = $pdo->prepare("INSERT IGNORE INTO role_permissions(role_id, permission_id) VALUES(?,?)");
    foreach ($role_add as $rid) {
      if ($rid <= 0) continue;
      foreach ($perm_ids as $pid) { if ($pid>0) $ins->execute([$rid,$pid]); }
    }
  }

  /* ---------- roles: revoke ---------- */
  if ($role_del) {
    $del = $pdo->prepare("DELETE FROM role_permissions WHERE role_id=? AND permission_id=?");
    foreach ($role_del as $rid) {
      if ($rid <= 0) continue;
      foreach ($perm_ids as $pid) { if ($pid>0) $del->execute([$rid,$pid]); }
    }
  }

  /* ---------- users: allow add ---------- */
  if ($user_allow_add) {
    $ins = $pdo->prepare("INSERT IGNORE INTO user_permissions(user_id, permission_id) VALUES(?,?)");
    foreach ($user_allow_add as $uid) {
      if ($uid <= 0) continue;
      foreach ($perm_ids as $pid) { if ($pid>0) $ins->execute([$uid,$pid]); }
    }
  }

  /* ---------- users: allow revoke ---------- */
  if ($user_allow_del) {
    $del = $pdo->prepare("DELETE FROM user_permissions WHERE user_id=? AND permission_id=?");
    foreach ($user_allow_del as $uid) {
      if ($uid <= 0) continue;
      foreach ($perm_ids as $pid) { if ($pid>0) $del->execute([$uid,$pid]); }
    }
  }

  /* ---------- users: deny add ---------- */
  if ($user_deny_add) {
    $ins = $pdo->prepare("INSERT IGNORE INTO user_permission_denies(user_id, permission_id) VALUES(?,?)");
    foreach ($user_deny_add as $uid) {
      if ($uid <= 0) continue;
      foreach ($perm_ids as $pid) { if ($pid>0) $ins->execute([$uid,$pid]); }
    }
  }

  /* ---------- users: deny revoke ---------- */
  if ($user_deny_del) {
    $del = $pdo->prepare("DELETE FROM user_permission_denies WHERE user_id=? AND permission_id=?");
    foreach ($user_deny_del as $uid) {
      if ($uid <= 0) continue;
      foreach ($perm_ids as $pid) { if ($pid>0) $del->execute([$uid,$pid]); }
    }
  }

  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  http_response_code(500);
  echo "Bulk apply failed.";
  exit;
}

// বাংলা: ক্যাশ রিফ্রেশ (এই সেশন ইউজারের জন্য)
acl_reset_cache();
header('Location: /public/admin/permissions.php?saved=1');
exit;
