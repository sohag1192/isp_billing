<?php
// /public/hr/employee_delete.php
// UI: English; Comments: বাংলা
// Hard delete (schema-aware by id-like column). Uses ACL hr.delete if available.

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/app/require_login.php';
require_once $ROOT . '/app/db.php';

// (Optional) ACL
$acl_file = $ROOT . '/app/acl.php';
if (is_file($acl_file)) require_once $acl_file;
if (function_exists('require_perm')) { require_perm('hr.delete'); }

if (session_status() === PHP_SESSION_NONE) { session_start(); }

/* ---------- CSRF ---------- */
$serverToken = (string)($_SESSION['csrf'] ?? ($_SESSION['csrf_hr'] ?? ''));
$clientToken = (string)(
  $_POST['csrf_token'] ?? $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')
);
if ($serverToken !== '') {
  if ($clientToken === '' || !hash_equals($serverToken, $clientToken)) {
    $_SESSION['flash_error'] = 'Invalid CSRF token.';
    header('Location: /public/hr/employees.php'); exit;
  }
}

/* ---------- Input ---------- */
$emp_id = trim((string)($_POST['emp_id'] ?? ''));
if ($emp_id === '') {
  $_SESSION['flash_error'] = 'Employee identifier missing.';
  header('Location: /public/hr/employees.php'); exit;
}

/* ---------- DB helpers ---------- */
function tbl_exists(PDO $pdo, string $t): bool {
  try{ $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
       $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
       $q->execute([$db,$t]); return (bool)$q->fetchColumn(); }catch(Throwable $e){ return false; }
}
function col_exists(PDO $pdo, string $t, string $c): bool {
  try{ $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
       $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
       $q->execute([$db,$t,$c]); return (bool)$q->fetchColumn(); }catch(Throwable $e){ return false; }
}
function find_emp_table(PDO $pdo): string {
  foreach (['emp_info','employees','hr_employees','employee'] as $t) if (tbl_exists($pdo,$t)) return $t;
  return 'emp_info';
}
function pick_col(PDO $pdo, string $t, array $cands, ?string $fallback=null): ?string {
  foreach ($cands as $c) if (col_exists($pdo,$t,$c)) return $c;
  return $fallback && col_exists($pdo,$t,$fallback) ? $fallback : null;
}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
  $pdo->beginTransaction();

  $T = find_emp_table($pdo);
  $COL_ID  = pick_col($pdo,$T,['emp_id','e_id','employee_id','emp_code','id'],'id') ?? 'id';

  $del = $pdo->prepare("DELETE FROM `$T` WHERE `$COL_ID` = ? LIMIT 1");
  $del->execute([$emp_id]);

  if ($del->rowCount() < 1) {
    $alt = pick_col($pdo,$T,['emp_code','employee_code','code','e_code']);
    if ($alt && $alt !== $COL_ID) {
      $del2 = $pdo->prepare("DELETE FROM `$T` WHERE `$alt` = ? LIMIT 1");
      $del2->execute([$emp_id]);
      if ($del2->rowCount() < 1) {
        throw new Exception('Employee not found, nothing deleted.');
      }
    } else {
      throw new Exception('Employee not found, nothing deleted.');
    }
  }

  if (function_exists('audit_log')) {
    try { audit_log($_SESSION['user']['id'] ?? null, $emp_id, 'employee_delete', ['table'=>$T]); } catch(Throwable $e){}
  }

  $pdo->commit();
  $_SESSION['flash_success'] = 'Employee deleted successfully.';
} catch(Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  $_SESSION['flash_error'] = 'Delete failed: ' . $e->getMessage();
}

header('Location: /public/hr/employees.php'); exit;
