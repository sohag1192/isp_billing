<?php
// /public/accounts_link_action.php
// UI: English; Comments: বাংলা
// Feature: Process link/unlink of accounts.user_id with CSRF + permission guard.

declare(strict_types=1);

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

// (optional) ACL থাকলে নাও; না থাকলে নীরব
$ACL_FILE = __DIR__ . '/../app/acl.php';
if (is_file($ACL_FILE)) require_once $ACL_FILE;

function dbh(): PDO { $pdo=db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); return $pdo; }
function can_manage_accounts(): bool {
  if (function_exists('require_perm')) {
    try { require_perm('accounts.manage'); return true; }
    catch (Throwable $e) { return false; }
  }
  $u = $_SESSION['user'] ?? [];
  $is_admin = (int)($u['is_admin'] ?? 0);
  $role = strtolower((string)($u['role'] ?? ''));
  return $is_admin===1 || in_array($role, ['admin','superadmin','manager','accounts','accountant','billing'], true);
}

// ---------- CSRF ----------
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$csrf = (string)($_POST['csrf_token'] ?? '');
if (!$csrf || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
  http_response_code(400);
  $_SESSION['flash'] = 'Invalid CSRF token.';
  header('Location: /public/accounts_manage.php');
  exit;
}

// ---------- Perm ----------
if (!can_manage_accounts()) {
  http_response_code(403);
  $_SESSION['flash'] = 'Not permitted.';
  header('Location: /public/accounts_manage.php');
  exit;
}

// ---------- Inputs ----------
$action     = (string)($_POST['action'] ?? '');
$account_id = (int)($_POST['account_id'] ?? 0);
$user_id    = (int)($_POST['user_id'] ?? 0);

// ---------- Validate ----------
if ($account_id <= 0) {
  $_SESSION['flash'] = 'Invalid account.';
  header('Location: /public/accounts_manage.php');
  exit;
}

try {
  if ($action === 'link') {
    if ($user_id <= 0) {
      $_SESSION['flash'] = 'Please select a user.';
      header('Location: /public/accounts_manage.php');
      exit;
    }
    $st = dbh()->prepare("UPDATE accounts SET user_id=? WHERE id=?");
    $st->execute([$user_id, $account_id]);
    $_SESSION['flash'] = "Account #$account_id linked to User #$user_id.";
  } elseif ($action === 'unlink') {
    $st = dbh()->prepare("UPDATE accounts SET user_id=NULL WHERE id=?");
    $st->execute([$account_id]);
    $_SESSION['flash'] = "Account #$account_id unlinked.";
  } else {
    $_SESSION['flash'] = 'Unknown action.';
  }
} catch (Throwable $e) {
  $_SESSION['flash'] = 'Operation failed: ' . $e->getMessage();
}

// ---------- Redirect back ----------
header('Location: /public/accounts_manage.php');
exit;
