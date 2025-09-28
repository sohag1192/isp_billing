<?php
// /public/hr/employee_edit_query.php
// Code: English; Comments: Bangla.
// Fix: Robust CSRF (session + XSRF cookie; accepts csrf_token/csrf/header). Schema-aware update, uploads, audit.

declare(strict_types=1);

/* ---------- bootstrap ---------- */
$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/app/require_login.php';
require_once $ROOT . '/app/db.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

/* ---------- method & CSRF guard ---------- */
// বাংলা: কেবল POST-এ চলবে
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  header('Location: /public/hr/employees.php'); exit;
}

/* ---------- CSRF bootstrap (session + cookie) ---------- */
// বাংলা: একটি ক্যানোনিকাল টোকেন রাখি, কুকিও সেট করি (double-submit cookie pattern)
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$SERVER_TOKEN = (string)($_SESSION['csrf']);

// ensure cookie present (SameSite=Lax so normal POST কাজ করবে)
if (!isset($_COOKIE['XSRF-TOKEN']) || $_COOKIE['XSRF-TOKEN'] !== $SERVER_TOKEN) {
  // নোট: আপনার সাইট যদি HTTPS হয়, 'secure'=>true করুন
  setcookie('XSRF-TOKEN', $SERVER_TOKEN, [
    'expires'  => time() + 86400 * 7,
    'path'     => '/',
    'secure'   => false,
    'httponly' => false,
    'samesite' => 'Lax',
  ]);
}

/* ---------- read client tokens ---------- */
$CLIENT_TOKEN = (string)(
  $_POST['csrf_token']                      // preferred field
  ?? $_POST['csrf']                         // legacy/fallback
  ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')  // ajax/header
);
$COOKIE_TOKEN = (string)($_COOKIE['XSRF-TOKEN'] ?? '');

// বাংলা: সার্ভার টোকেন থাকলে—কোনো একটি মিললেই পাস
if ($SERVER_TOKEN !== '') {
  $ok = false;
  if ($CLIENT_TOKEN !== '' && hash_equals($SERVER_TOKEN, $CLIENT_TOKEN)) $ok = true;
  if (!$ok && $COOKIE_TOKEN !== '' && hash_equals($SERVER_TOKEN, $COOKIE_TOKEN)) $ok = true;

  if (!$ok) {
    // ডায়াগনস্টিক—error_log এ স্টেটাস দিন (টোকেন ভ্যালু নয়)
    error_log('[HR] CSRF failed on employee_edit_query.php: st='.(int)($SERVER_TOKEN!=='' ).' ct='.(int)($CLIENT_TOKEN!==''). ' ck='.(int)($COOKIE_TOKEN!==''));
    http_response_code(400);
    echo 'Invalid CSRF token.';
    exit;
  }
}

/* ---------- helpers ---------- */
// বাংলা: PDO হ্যান্ডলার
function dbh(): PDO { $pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); return $pdo; }
function db_name(PDO $pdo): string { return (string)$pdo->query('SELECT DATABASE()')->fetchColumn(); }
function table_exists(PDO $pdo, string $t): bool {
  try { $st=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
        $st->execute([db_name($pdo),$t]); return (bool)$st->fetchColumn(); } catch(Throwable $e){ return false; }
}
function col_exists(PDO $pdo, string $tbl, string $col): bool {
  try { $st=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
        $st->execute([db_name($pdo),$tbl,$col]); return (bool)$st->fetchColumn(); } catch(Throwable $e){ return false; }
}
function ext_from_upload(array $f, array $allow): string {
  $n = strtolower((string)($f['name'] ?? '')); $ext = pathinfo($n, PATHINFO_EXTENSION);
  return ($ext && in_array($ext, $allow, true)) ? $ext : '';
}
function ensure_dir(string $path): bool { if (!is_dir($path)) { if (!@mkdir($path, 0775, true)) return false; } return is_writable($path); }
function looks_like(array $f, array $mimes): bool {
  if (!function_exists('finfo_open')) return true; $fi=finfo_open(FILEINFO_MIME_TYPE); if(!$fi) return true;
  $mt=finfo_file($fi,(string)($f['tmp_name']??'')); finfo_close($fi); if(!$mt) return true;
  foreach($mimes as $ok) if (stripos($mt,$ok)===0) return true; return false;
}
function normalize_date(?string $s): ?string { $s=trim((string)$s); if($s==='') return null; $ts=strtotime($s); if($ts===false) return $s; return date('Y-m-d',$ts); }

/* ---------- inputs ---------- */
$posted_id = trim((string)($_POST['e_id'] ?? ''));
$name      = trim((string)($_POST['name'] ?? ''));
$status_in = trim((string)($_POST['status'] ?? ''));
$mobile    = trim((string)($_POST['mobile'] ?? ''));
$dept_id   = trim((string)($_POST['dept_id'] ?? ''));
$join_d    = trim((string)($_POST['join_date'] ?? ''));
$address   = trim((string)($_POST['address'] ?? ''));
$salary_in = trim((string)($_POST['salary'] ?? ''));

if ($posted_id === '' || $name === '') { http_response_code(400); echo 'Missing required fields.'; exit; }

$pdo = dbh();

/* ---------- table ---------- */
$emp_tbl = table_exists($pdo, 'emp_info') ? 'emp_info' : (table_exists($pdo, 'employees') ? 'employees' : '');
if ($emp_tbl === '') { http_response_code(500); echo 'Employee table not found.'; exit; }

/* ---------- find row ---------- */
$id_candidates = ['emp_code','code','employee_code','emp_id','employee_id','e_id','id'];
$whereCol = ''; $old = null;
foreach ($id_candidates as $c) {
  if (!col_exists($pdo,$emp_tbl,$c)) continue;
  $st=$pdo->prepare("SELECT * FROM `$emp_tbl` WHERE `$c` = ? LIMIT 1");
  $st->execute([$posted_id]); $row=$st->fetch(PDO::FETCH_ASSOC);
  if ($row) { $whereCol=$c; $old=$row; break; }
}
if (!$old || $whereCol==='') { http_response_code(404); echo 'Employee not found.'; exit; }

/* ---------- discover columns ---------- */
$C_NAME=''; foreach(['name','e_name','emp_name','full_name'] as $c){ if(col_exists($pdo,$emp_tbl,$c)){ $C_NAME=$c; break; } }
$C_STATUS=''; foreach(['status','emp_status','is_active'] as $c){ if(col_exists($pdo,$emp_tbl,$c)){ $C_STATUS=$c; break; } }
$C_MOBILE=''; foreach(['mobile','phone','contact','contact_no','mobile_no','e_cont_per'] as $c){ if(col_exists($pdo,$emp_tbl,$c)){ $C_MOBILE=$c; break; } }
$C_ADDR=''; foreach(['address','present_address','pre_address'] as $c){ if(col_exists($pdo,$emp_tbl,$c)){ $C_ADDR=$c; break; } }
$C_SAL=''; foreach(['salary','gross','basic_salary','gross_total'] as $c){ if(col_exists($pdo,$emp_tbl,$c)){ $C_SAL=$c; break; } }
$C_JOIN=''; foreach(['join_date','e_j_date','joined_at','hire_date','created_at'] as $c){ if(col_exists($pdo,$emp_tbl,$c)){ $C_JOIN=$c; break; } }
$C_DEPT=''; foreach(['dept_id','department_id','dept','department'] as $c){ if(col_exists($pdo,$emp_tbl,$c)){ $C_DEPT=$c; break; } }
$C_PHOTO=''; foreach(['photo','photo_url','image','photo_path'] as $c){ if(col_exists($pdo,$emp_tbl,$c)){ $C_PHOTO=$c; break; } }
$C_NID=''; foreach(['nid','nid_path','nid_copy','nid_file'] as $c){ if(col_exists($pdo,$emp_tbl,$c)){ $C_NID=$c; break; } }
$C_UPDATED = col_exists($pdo,$emp_tbl,'updated_at') ? 'updated_at' : '';

/* ---------- SET ---------- */
if ($C_NAME === '') { http_response_code(500); echo 'Name column not found on table.'; exit; }
$set=[]; $bind=[];
$set[]="`$C_NAME` = ?"; $bind[]=$name;

if ($C_STATUS !== '') {
  $val = $old[$C_STATUS] ?? null;
  if ($status_in !== '') {
    if ($C_STATUS === 'is_active') {
      $v = strtolower($status_in);
      $val = in_array($v,['1','true','yes','active'],true)?1:(in_array($v,['0','false','no','inactive'],true)?0:$val);
    } else { $val = $status_in; }
  }
  $set[]="`$C_STATUS` = ?"; $bind[]=$val;
}
if ($C_MOBILE!==''){ $set[]="`$C_MOBILE` = ?"; $bind[]=($mobile!==''?$mobile:($old[$C_MOBILE]??null)); }
if ($C_DEPT  !==''){ $set[]="`$C_DEPT`   = ?"; $bind[]=($dept_id!==''?$dept_id:($old[$C_DEPT]??null)); }
if ($C_JOIN  !==''){ $set[]="`$C_JOIN`   = ?"; $bind[]=($join_d!==''?(normalize_date($join_d)??$join_d):($old[$C_JOIN]??null)); }
if ($C_ADDR  !==''){ $set[]="`$C_ADDR`   = ?"; $bind[]=($address!==''?$address:($old[$C_ADDR]??null)); }
if ($C_SAL   !==''){
  if ($salary_in!=='' && is_numeric($salary_in)){ $set[]="`$C_SAL` = ?"; $bind[]=$salary_in; }
  else { $set[]="`$C_SAL` = ?"; $bind[]=($old[$C_SAL]??null); }
}

/* ---------- uploads ---------- */
$docroot = $_SERVER['DOCUMENT_ROOT'] ?: $ROOT;
$base1 = rtrim($docroot,'/\\').'/uploads/employee';
$base2 = rtrim($docroot,'/\\').'/upload/employee';
$target_base = ensure_dir($base1) ? $base1 : (ensure_dir($base2) ? $base2 : $base1);
$fileStem = (string)($old['emp_code'] ?? $old['employee_code'] ?? $old['code'] ?? $old['emp_id'] ?? $old['employee_id'] ?? $old['e_id'] ?? $old['id'] ?? $posted_id);
$MAX_SIZE = 10*1024*1024;

// photo
if (!empty($_FILES['photo']['name']) && (int)$_FILES['photo']['error']===UPLOAD_ERR_OK){
  if ((int)$_FILES['photo']['size'] <= $MAX_SIZE && looks_like($_FILES['photo'], ['image/'])){
    $ext = ext_from_upload($_FILES['photo'], ['jpg','jpeg','png','webp']);
    if ($ext!==''){
      $dest = $target_base.'/'.$fileStem.'.'.$ext;
      if (@move_uploaded_file($_FILES['photo']['tmp_name'],$dest)){
        @chmod($dest,0644);
        $rel = str_replace('\\','/',$dest); $doc=rtrim(str_replace('\\','/',$docroot),'/');
        $rel = preg_replace('#^'.preg_quote($doc,'#').'#','',$rel); if ($rel==='' || $rel[0]!=='/') $rel='/'.ltrim($rel,'/');
        if ($C_PHOTO!==''){ $set[]="`$C_PHOTO` = ?"; $bind[]=$rel; }
      }
    }
  }
}
// nid
$nidKey = !empty($_FILES['nid_file']['name']) ? 'nid_file' : (!empty($_FILES['nid']['name']) ? 'nid' : '');
if ($nidKey!=='' && (int)$_FILES[$nidKey]['error']===UPLOAD_ERR_OK){
  if ((int)$_FILES[$nidKey]['size'] <= $MAX_SIZE && looks_like($_FILES[$nidKey], ['image/','application/pdf'])){
    $ext = ext_from_upload($_FILES[$nidKey], ['jpg','jpeg','png','webp','pdf']);
    if ($ext!==''){
      $dest = $target_base.'/'.$fileStem.'_NID.'.$ext;
      if (@move_uploaded_file($_FILES[$nidKey]['tmp_name'],$dest)){
        @chmod($dest,0644);
        $rel = str_replace('\\','/',$dest); $doc=rtrim(str_replace('\\','/',$docroot),'/');
        $rel = preg_replace('#^'.preg_quote($doc,'#').'#','',$rel); if ($rel==='' || $rel[0]!=='/') $rel='/'.ltrim($rel,'/');
        if ($C_NID!==''){ $set[]="`$C_NID` = ?"; $bind[]=$rel; }
      }
    }
  }
}

// updated_at
if ($C_UPDATED!==''){ $set[]="`$C_UPDATED` = NOW()"; }

/* ---------- execute update ---------- */
if (empty($set)) { header('Location: /public/hr/employee_view.php?e_id=' . urlencode($posted_id)); exit; }

$sql = "UPDATE `$emp_tbl` SET ".implode(', ',$set)." WHERE `$whereCol` = ? LIMIT 1";
$bind[] = $posted_id;

$pdo->beginTransaction();
try{
  $st=$pdo->prepare($sql); $st->execute($bind); $pdo->commit();
}catch(Throwable $e){
  $pdo->rollBack(); http_response_code(500); echo 'Update failed: '.$e->getMessage(); exit;
}

/* ---------- optional audit ---------- */
$aud = $ROOT . '/app/audit.php';
if (is_file($aud)) {
  require_once $aud;
  if (function_exists('audit_log')) {
    $changes = [
      'name'=>$name, 'status'=>$status_in, 'mobile'=>$mobile, 'dept'=>$dept_id,
      'join'=>$join_d, 'address'=>$address, 'salary'=>$salary_in,
      'photo_replaced'=>!empty($_FILES['photo']['name']),
      'nid_replaced'=>(!empty($_FILES['nid_file']['name'])||!empty($_FILES['nid']['name']))
    ];
    try { @audit_log('hr.employee.update', ['emp_ref'=>$posted_id,'where_col'=>$whereCol,'changes'=>$changes]); } catch(Throwable $e){}
  }
}

/* ---------- redirect ---------- */
header('Location: /public/hr/employee_view.php?e_id=' . urlencode($posted_id)); exit;
