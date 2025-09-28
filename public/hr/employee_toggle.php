<?php
// /public/hr/employee_toggle.php
// Code: English; Comments: Bangla.
// Toggle order: is_left → is_active → status. emp_id প্রাইওরিটি।
// GET: input/confirm; POST: do toggle; success → back URL (list page).
// CSRF/ACL safe, row-lock, updated_at/left_at safe, audit_log flexible + audit_logs fallback.

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');

if (session_status() === PHP_SESSION_NONE) { session_start(); }

/* ---------- Bootstrap auth/ACL ---------- */
$APP = __DIR__ . '/../../app/';
foreach (['require_login.php', 'auth.php'] as $f) {
  $p = $APP . $f;
  if (is_file($p)) { require_once $p; break; }
}
$acl = $APP . 'acl.php';
if (is_file($acl)) { require_once $acl; if (function_exists('acl_boot')) { @acl_boot(); } }

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
$CSRF = (string)$_SESSION['csrf'];

/* ---------- Helpers ---------- */
function hr_allow_toggle(): bool { if (function_exists('acl_can')) return acl_can('hr.toggle'); return true; }

/* বাংলা: emp code resolve — সব কী কভার */
function resolve_emp_code(): string {
  $keys = ['emp_id','e_id','employee_id','emp_code','id','code','e','eid'];
  foreach ($keys as $k) { $v = trim((string)($_POST[$k] ?? $_GET[$k] ?? '')); if ($v !== '') return $v; }
  $pathInfo = (string)($_SERVER['PATH_INFO'] ?? ''); if ($pathInfo) { $seg = trim($pathInfo,'/'); if ($seg!=='') return $seg; }
  $req = (string)($_SERVER['REQUEST_URI'] ?? ''); if ($req && preg_match('#/employee_toggle\.php/([^?]+)#i',$req,$m)) return trim($m[1]);
  $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
  if ($ref) { $q = @parse_url($ref, PHP_URL_QUERY); if ($q) { parse_str((string)$q, $arr);
    foreach ($keys as $k) { if (!empty($arr[$k])) return trim((string)$arr[$k]); } } }
  return '';
}

/* বাংলা: back URL detected — self পেজ হলে discard */
function safe_back_from_referer(string $fallback = '/public/hr/employees.php'): string {
  $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
  $host = $_SERVER['HTTP_HOST'] ?? '';
  if (!$ref) return $fallback;
  $pu = @parse_url($ref);
  if (!empty($pu['host']) && $pu['host'] !== $host) return $fallback;
  $path = $pu['path'] ?? '';
  if ($path && stripos($path, '/public/hr/employee_toggle.php') !== false) return $fallback;
  return $ref;
}
/* বাংলা: পোস্টের সময় পাওয়া back URL স্যানিটাইজ করুন */
function sanitize_back_url(string $url = '', string $fallback = '/public/hr/employees.php'): string {
  if ($url === '') return $fallback;
  $host = $_SERVER['HTTP_HOST'] ?? '';
  $pu = @parse_url($url);
  if (!empty($pu['host']) && $pu['host'] !== $host) return $fallback;
  $path = $pu['path'] ?? '';
  if ($path && stripos($path, '/public/hr/employee_toggle.php') !== false) return $fallback;
  return $url;
}
function current_user_id_guess(): ?int {
  if (function_exists('acl_current_user_id')) { $id = (int)acl_current_user_id(); return $id>0 ? $id : null; }
  foreach ([
    $_SESSION['user']['id'] ?? null,
    $_SESSION['user_id'] ?? null,
    $_SESSION['SESS_USER_ID'] ?? null,
    $_SESSION['id'] ?? null,
  ] as $v) { $id = (int)$v; if ($id>0) return $id; }
  return null;
}

/* ---------- GET: input/confirm ---------- */
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'POST') {
  if (!hr_allow_toggle()) { if (function_exists('acl_forbid_403')) { acl_forbid_403('Permission denied for: hr.toggle'); } http_response_code(403); exit('Forbidden'); }

  $e_id = resolve_emp_code();

  // বাংলা: back URL সেশন/hidden-এ রেখে দিচ্ছি, যাতে POST শেষে ঠিক জায়গায় যাই
  $back = safe_back_from_referer('/public/hr/employees.php');
  $_SESSION['_hr_toggle_back'] = $back;

  $csrf_h = htmlspecialchars($CSRF, ENT_QUOTES, 'UTF-8');

  echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
  echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
  echo '<title>Toggle Employee</title>';
  echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">';
  echo '</head><body class="bg-light"><div class="container py-4"><div class="card shadow-sm"><div class="card-body">';
  echo '<h5 class="card-title mb-3">Toggle Employee Status</h5>';

  if ($e_id === '') {
    echo '<form method="get" action="" class="mb-3"><div class="input-group">';
    echo '  <input type="text" name="emp_id" class="form-control" placeholder="Enter Employee ID (emp_id)" required>';
    echo '  <button type="submit" class="btn btn-primary">Next</button>';
    echo '</div></form>';
    echo '<a class="btn btn-secondary" href="'.htmlspecialchars($back, ENT_QUOTES, 'UTF-8').'">Back</a>';
  } else {
    $e_id_h = htmlspecialchars($e_id, ENT_QUOTES, 'UTF-8');
    echo '<p class="mb-3">Are you sure you want to toggle status for <code>'.$e_id_h.'</code>?</p>';
    echo '<form method="post" action="">';
    echo '  <input type="hidden" name="_csrf" value="'.$csrf_h.'">';
    echo '  <input type="hidden" name="emp_id" value="'.$e_id_h.'">';
    echo '  <input type="hidden" name="_back" value="'.htmlspecialchars($back, ENT_QUOTES, 'UTF-8').'">';
    echo '  <button type="submit" class="btn btn-primary me-2">Confirm</button>';
    echo '  <a class="btn btn-secondary" href="'.htmlspecialchars($back, ENT_QUOTES, 'UTF-8').'">Cancel</a>';
    echo '</form>';
  }

  echo '</div></div></div></body></html>';
  exit;
}

/* ---------- POST: do toggle ---------- */
if (!isset($_POST['_csrf'], $_SESSION['csrf']) || !hash_equals((string)$_SESSION['csrf'], (string)$_POST['_csrf'])) { http_response_code(419); exit('Invalid CSRF token.'); }
if (function_exists('require_perm')) { require_perm('hr.toggle'); } elseif (!hr_allow_toggle()) { if (function_exists('acl_forbid_403')) { acl_forbid_403('Permission denied for: hr.toggle'); } http_response_code(403); exit('Forbidden'); }

$e_id = resolve_emp_code();
if ($e_id === '') { http_response_code(400); exit('Missing e_id'); }

$back = sanitize_back_url((string)($_POST['_back'] ?? ($_SESSION['_hr_toggle_back'] ?? '')), '/public/hr/employees.php');
unset($_SESSION['_hr_toggle_back']);

/* ---------- DB ---------- */
require_once $APP . 'db.php';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------- Information schema helpers ---------- */
function tbl_exists(PDO $pdo, string $tbl): bool {
  try { $db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        $q  = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
        $q->execute([$db,$tbl]); return (bool)$q->fetchColumn();
  } catch (Throwable $e) { return false; }
}
function col_exists(PDO $pdo, string $tbl, string $col): bool {
  try { $db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        $q  = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
        $q->execute([$db,$tbl,$col]); return (bool)$q->fetchColumn();
  } catch (Throwable $e) { return false; }
}
function current_db(PDO $pdo): string { return (string)$pdo->query('SELECT DATABASE()')->fetchColumn(); }
function guess_emp_table(PDO $pdo): ?string {
  foreach (['emp_info','employees'] as $t) if (tbl_exists($pdo,$t)) return $t;
  try {
    $st = $pdo->prepare("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
                         WHERE TABLE_SCHEMA=? AND (TABLE_NAME LIKE '%emp%' OR TABLE_NAME LIKE '%employee%')
                         ORDER BY TABLE_NAME LIMIT 1");
    $st->execute([current_db($pdo)]); $t = $st->fetchColumn(); return $t ?: null;
  } catch (Throwable $e) { return null; }
}
function table_columns(PDO $pdo, string $tbl): array {
  try { $st = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
        $st->execute([current_db($pdo),$tbl]); return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
  } catch (Throwable $e) { return []; }
}
function table_pk(PDO $pdo, string $tbl): ?string {
  try { $st = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                             WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY'
                             ORDER BY ORDINAL_POSITION LIMIT 1");
        $st->execute([current_db($pdo),$tbl]); $c=$st->fetchColumn(); return $c?:null;
  } catch (Throwable $e) { return null; }
}
function column_has_match(PDO $pdo, string $tbl, string $col, $val): bool {
  try { $st = $pdo->prepare("SELECT 1 FROM `$tbl` WHERE `$col`=? LIMIT 1"); $st->execute([$val]); return (bool)$st->fetchColumn();
  } catch (Throwable $e) { return false; }
}
function col_data_type(PDO $pdo, string $tbl, string $col): ?string {
  try { $st = $pdo->prepare("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
        $st->execute([current_db($pdo),$tbl,$col]); $t=$st->fetchColumn(); return $t?strtolower((string)$t):null;
  } catch (Throwable $e) { return null; }
}
function col_nullable(PDO $pdo, string $tbl, string $col): bool {
  try { $st = $pdo->prepare("SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
        $st->execute([current_db($pdo),$tbl,$col]); return strtoupper((string)$st->fetchColumn())==='YES';
  } catch (Throwable $e) { return true; }
}

/* ---------- Resolve table/identifier (emp_id priority) ---------- */
$T_EMP = guess_emp_table($pdo);
if (!$T_EMP) { http_response_code(500); exit('Employees table not found.'); }

$INPUT_VAL = $e_id;
$allCols   = table_columns($pdo, $T_EMP);

$FORCED_COL = trim((string)($_GET['col'] ?? $_GET['id_col'] ?? $_POST['col'] ?? ''));
if ($FORCED_COL !== '' && in_array($FORCED_COL, $allCols, true)) {
  $CODE_COL = $FORCED_COL;
} elseif (in_array('emp_id',$allCols,true) && column_has_match($pdo,$T_EMP,'emp_id',$INPUT_VAL)) {
  $CODE_COL = 'emp_id';
} else {
  $candidates = ['emp_id','e_id','employee_id','emp_code','employee_code','code','eid','empno','emp_no','emp_uid','id'];
  $PK = table_pk($pdo,$T_EMP);
  $isNum = (string)(int)$INPUT_VAL === $INPUT_VAL || is_numeric($INPUT_VAL);
  if ($isNum && $PK && in_array($PK,$allCols,true) && !in_array($PK,$candidates,true)) array_unshift($candidates,$PK);
  $CODE_COL = null;
  foreach ($candidates as $c) { if (!in_array($c,$allCols,true)) continue; if (column_has_match($pdo,$T_EMP,$c,$INPUT_VAL)) { $CODE_COL = $c; break; } }
  if (!$CODE_COL) { foreach ($allCols as $c) { if (column_has_match($pdo,$T_EMP,$c,$INPUT_VAL)) { $CODE_COL = $c; break; } } }
}
if (!$CODE_COL) { http_response_code(500); echo 'Employee code column not found (value: '.htmlspecialchars($INPUT_VAL,ENT_QUOTES,'UTF-8').'). Tip: pass ?col=emp_id'; exit; }

/* ---------- Feature flags ---------- */
$HAS_IS_ACTIVE = col_exists($pdo,$T_EMP,'is_active');
$HAS_STATUS    = col_exists($pdo,$T_EMP,'status');
$HAS_IS_LEFT   = col_exists($pdo,$T_EMP,'is_left');
$HAS_LEFT_AT   = col_exists($pdo,$T_EMP,'left_at');
$HAS_UPDATED   = col_exists($pdo,$T_EMP,'updated_at');

$STATUS_TYPE   = $HAS_STATUS ? (col_data_type($pdo,$T_EMP,'status') ?? '') : '';
$LEFT_AT_NULL  = $HAS_LEFT_AT ? col_nullable($pdo,$T_EMP,'left_at') : true;

/* ---------- Toggle (row lock) ---------- */
$pdo->beginTransaction();
try {
  $sel = $pdo->prepare("SELECT * FROM `$T_EMP` WHERE `$CODE_COL`=? LIMIT 1 FOR UPDATE");
  $sel->execute([$INPUT_VAL]);
  $row = $sel->fetch(PDO::FETCH_ASSOC);
  if (!$row) throw new RuntimeException('Employee not found.');

  $toggledField = ''; $newValue = null;

  if ($HAS_IS_LEFT) {
    $current = (int)($row['is_left'] ?? 0); $new = $current ? 0 : 1;
    $sql = "UPDATE `$T_EMP` SET `is_left`=:v"; $params = [':v'=>$new];
    if ($HAS_LEFT_AT) { if ($new===1){ $sql.=", `left_at`=:la"; $params[':la']=date('Y-m-d H:i:s'); } elseif ($LEFT_AT_NULL){ $sql.=", `left_at`=:la"; $params[':la']=null; } }
    if ($HAS_UPDATED) { $sql.=", `updated_at`=:ua"; $params[':ua']=date('Y-m-d H:i:s'); }
    $sql.=" WHERE `$CODE_COL`=:id LIMIT 1"; $params[':id']=$INPUT_VAL;
    $pdo->prepare($sql)->execute($params);
    $toggledField='is_left'; $newValue=$new;

  } elseif ($HAS_IS_ACTIVE) {
    $current = (int)($row['is_active'] ?? 0); $new = $current ? 0 : 1;
    $sql = "UPDATE `$T_EMP` SET `is_active`=:v"; $params = [':v'=>$new];
    if ($HAS_UPDATED) { $sql.=", `updated_at`=:ua"; $params[':ua']=date('Y-m-d H:i:s'); }
    $sql.=" WHERE `$CODE_COL`=:id LIMIT 1"; $params[':id']=$INPUT_VAL;
    $pdo->prepare($sql)->execute($params);
    $toggledField='is_active'; $newValue=$new;

  } elseif ($HAS_STATUS) {
    $raw = $row['status']; $isNumeric = is_numeric($raw) || in_array($STATUS_TYPE,['tinyint','smallint','int','bigint','decimal','float','double'],true);
    if ($isNumeric) { $curr=(int)$raw; $new=$curr?0:1; } else { $s=strtolower((string)$raw); $isAct=in_array($s,['active','enabled','enable','on','true','yes','1'],true); $new=$isAct?'inactive':'active'; }
    $sql = "UPDATE `$T_EMP` SET `status`=:v"; $params=[':v'=>$new];
    if ($HAS_UPDATED) { $sql.=", `updated_at`=:ua"; $params[':ua']=date('Y-m-d H:i:s'); }
    $sql.=" WHERE `$CODE_COL`=:id LIMIT 1"; $params[':id']=$INPUT_VAL;
    $pdo->prepare($sql)->execute($params);
    $toggledField='status'; $newValue=$new;

  } else { throw new RuntimeException('No toggle-able status column found.'); }

  /* ---------- Audit ---------- */
  $userId  = current_user_id_guess() ?? 0;
  $entityId = null; $pk = table_pk($pdo,$T_EMP); if ($pk && isset($row[$pk]) && is_numeric($row[$pk])) $entityId = (int)$row[$pk];

  $meta = [
    'emp_id_input' => $INPUT_VAL,
    'table'        => $T_EMP,
    'code_col'     => $CODE_COL,
    'toggled'      => $toggledField,
    'new_value'    => $newValue,
    'by_user'      => ($_SESSION['user']['username'] ?? ($_SESSION['username'] ?? null)),
    'at'           => date('c'),
  ];

  $audited=false;
  $audit_fn=$APP.'audit.php'; if (is_file($audit_fn)) require_once $audit_fn;
  if (function_exists('audit_log')) {
    try { @audit_log($userId, $entityId, 'employee_toggle', $meta); $audited=true; }
    catch (Throwable $e1) { try { @audit_log('employee_toggle', $entityId, $meta); $audited=true; } catch (Throwable $e2) { $audited=false; } }
  }
  if (!$audited && tbl_exists($pdo,'audit_logs')) {
    try {
      $cols = table_columns($pdo,'audit_logs');
      $col_user = in_array('user_id',$cols,true)?'user_id':(in_array('uid',$cols,true)?'uid':null);
      $col_ent  = in_array('entity_id',$cols,true)?'entity_id':(in_array('target_id',$cols,true)?'target_id':null);
      $col_act  = in_array('action',$cols,true)?'action':(in_array('event',$cols,true)?'event':(in_array('activity',$cols,true)?'activity':'action'));
      $col_meta = in_array('meta',$cols,true)?'meta':(in_array('meta_json',$cols,true)?'meta_json':(in_array('details',$cols,true)?'details':(in_array('data',$cols,true)?'data':null)));
      $col_time = in_array('created_at',$cols,true)?'created_at':(in_array('logged_at',$cols,true)?'logged_at':null);

      $fields=[]; $vals=[]; $pr=[];
      if ($col_user){ $fields[]="`$col_user`"; $pr[':u']=$userId; $vals[]=':u'; }
      if ($col_ent){ $fields[]="`$col_ent`";  $pr[':e']=$entityId; $vals[]=':e'; }
      $fields[]="`$col_act`"; $pr[':a']='employee_toggle'; $vals[]=':a';
      if ($col_meta){ $fields[]="`$col_meta`"; $pr[':m']=json_encode($meta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); $vals[]=':m'; }
      if ($col_time){ $fields[]="`$col_time`"; $pr[':t']=date('Y-m-d H:i:s'); $vals[]=':t'; }
      $sql="INSERT INTO `audit_logs` (".implode(',',$fields).") VALUES (".implode(',',$vals).")";
      $pdo->prepare($sql)->execute($pr);
    } catch (Throwable $ef) { /* ignore */ }
  }

  $pdo->commit();

  // ✅ সবসময় list/back পেজে ফিরে যান (self পেজে আর নয়)
  header('Location: '.$back);
  exit;

} catch (Throwable $e) {
  $pdo->rollBack();
  http_response_code(500);
  echo 'Error: '.htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8');
}
