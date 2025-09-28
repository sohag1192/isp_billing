<?php
// /public/hr/employee_add_query.php
// Code: English only; Comments: Bangla.
// Features:
// - Insert employee (schema-aware)
// - Manual/Auto employee code into a string code column (never into numeric PK)
// - Optional user creation (schema-aware)
// - Safe uploads with fallback dirs and path persistence
// - CSRF (if present), transactions, and robust fallbacks

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');

require_once __DIR__ . '/../../app/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------------- helpers ---------------- */
// বাংলা: HTML entity escape
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
// বাংলা: বর্তমান ডাটাবেস নাম
function db_name(PDO $pdo): string { return (string)$pdo->query('SELECT DATABASE()')->fetchColumn(); }
// বাংলা: টেবিল/কলাম এক্সিস্টেন্স
function tbl_exists(PDO $pdo, string $t): bool {
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
  $q->execute([db_name($pdo),$t]); return (bool)$q->fetchColumn();
}
function col_exists(PDO $pdo, string $t, string $c): bool {
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->execute([db_name($pdo),$t,$c]); return (bool)$q->fetchColumn();
}
function col_type(PDO $pdo, string $t, string $c): ?string {
  $q=$pdo->prepare("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->execute([db_name($pdo),$t,$c]); $d=$q->fetchColumn();
  return $d ? strtolower((string)$d) : null;
}
function col_is_numeric(PDO $pdo, string $t, string $c): bool {
  $num = ['int','bigint','smallint','mediumint','tinyint','decimal','double','float','bit'];
  $tpe = col_type($pdo,$t,$c);
  return $tpe ? in_array($tpe,$num,true) : false;
}
function col_is_autoinc(PDO $pdo, string $t, string $c): bool {
  $q=$pdo->prepare("SELECT EXTRA FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->execute([db_name($pdo),$t,$c]); $e = strtolower((string)$q->fetchColumn());
  return strpos($e, 'auto_increment') !== false;
}
// বাংলা: এক্সটেনশন সেফ কিনা
function safe_ext(string $fn, array $allow): ?string {
  $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
  return in_array($ext, $allow, true) ? $ext : null;
}
// বাংলা: নাম থেকে username slug
function slugify_username(string $name): string {
  $s = strtolower(trim($name));
  $s = preg_replace('/[^a-z0-9\s._-]+/','',$s);
  $s = preg_replace('/\s+/','.',$s);
  $s = preg_replace('/\.{2,}/','.',$s);
  return trim($s,'.') ?: 'user';
}
// বাংলা: unique username নিশ্চিত করা
function ensure_unique_username(PDO $pdo, string $username, string $users_table, string $username_col='username'): string {
  $base = $username; $try = $username; $i=0;
  while(true){
    $q = $pdo->prepare("SELECT 1 FROM {$users_table} WHERE {$username_col} = ? LIMIT 1");
    $q->execute([$try]);
    if (!$q->fetchColumn()) return $try;
    $i++; $try = $base . '-' . $i;
  }
}
// বাংলা: টেম্প পাসওয়ার্ড
function gen_password(int $len=10): string {
  $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789@#%!';
  $out=''; for($i=0;$i<$len;$i++){ $out.=$alphabet[random_int(0,strlen($alphabet)-1)]; }
  return $out;
}

/* ---------------- detect tables ---------------- */
$T_EMP  = tbl_exists($pdo,'emp_info') ? 'emp_info' : (tbl_exists($pdo,'employees') ? 'employees' : null);
if (!$T_EMP) {
  $_SESSION['flash'] = 'Employee table not found.';
  header('Location: /public/hr/employee_add.php'); exit;
}
$T_USERS = tbl_exists($pdo,'users') ? 'users' : null;

/* ---------------- CSRF (optional) ---------------- */
if (!empty($_SESSION['csrf'])) {
  if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf_token'] ?? ''))) {
    $_SESSION['flash'] = 'Invalid CSRF token.';
    header('Location: /public/hr/employee_add.php'); exit;
  }
}

/* ---------------- inputs ---------------- */
$e_name   = trim((string)($_POST['e_name'] ?? ''));
$e_des    = trim((string)($_POST['e_des'] ?? ''));
$e_gender = trim((string)($_POST['e_gender'] ?? ''));
$married  = trim((string)($_POST['married_stu'] ?? ''));
$dob      = $_POST['e_b_date'] ?? null;
$joindate = $_POST['e_j_date'] ?? null;
$n_id     = trim((string)($_POST['n_id'] ?? ''));
$bgroup   = trim((string)($_POST['bgroup'] ?? ''));

$basic_salary   = (float)($_POST['basic_salary'] ?? 0);
$mobile_bill    = (float)($_POST['mobile_bill'] ?? 0);
$house_rent     = (float)($_POST['house_rent'] ?? 0);
$medical        = (float)($_POST['medical'] ?? 0);
$food           = (float)($_POST['food'] ?? 0);
$others         = (float)($_POST['others'] ?? 0);
$provident_fund = (float)($_POST['provident_fund'] ?? 0);
$professional_tax = (float)($_POST['professional_tax'] ?? 0);
$income_tax     = (float)($_POST['income_tax'] ?? 0);

$pre_address = trim((string)($_POST['pre_address'] ?? ''));
$per_address = trim((string)($_POST['per_address'] ?? ''));
$e_cont_per  = trim((string)($_POST['e_cont_per'] ?? ''));
$e_cont_off  = trim((string)($_POST['e_cont_office'] ?? ''));
$e_cont_fam  = trim((string)($_POST['e_cont_family'] ?? ''));
$email       = trim((string)($_POST['email'] ?? ''));
$skype       = trim((string)($_POST['skype'] ?? ''));

$dept_id     = isset($_POST['e_dept']) ? (int)$_POST['e_dept'] : null;
$dept_text   = trim((string)($_POST['e_dept_text'] ?? ''));

$create_user   = !empty($_POST['create_user']);
$user_username = trim((string)($_POST['user_username'] ?? ''));
$user_role     = trim((string)($_POST['user_role'] ?? 'employee'));
$user_password = (string)($_POST['user_password'] ?? '');

$use_manual_code = !empty($_POST['use_manual_code']);
$emp_code_manual = trim((string)($_POST['emp_code_manual'] ?? ''));

/* ---------------- minimal validation ---------------- */
if ($e_name === '' || $e_des === '' || $basic_salary <= 0) {
  $_SESSION['flash'] = 'Required fields missing: name, designation, basic salary.';
  header('Location: /public/hr/employee_add.php'); exit;
}

/* ---------------- detect id/code columns ---------------- */
// বাংলা: numeric PK কখনোই নিজে সেট করব না (DB auto-increment ব্যবহার)
$pkCol = null;
if (col_exists($pdo,$T_EMP,'id') && col_is_numeric($pdo,$T_EMP,'id')) $pkCol='id';
$pkIsAuto = $pkCol ? col_is_autoinc($pdo,$T_EMP,$pkCol) : false;

// বাংলা: string code column (priority order)
$empCodeCol = null;
foreach (['emp_code','code','employee_code','emp_id','employee_id','e_id'] as $c) {
  if (col_exists($pdo,$T_EMP,$c) && !col_is_numeric($pdo,$T_EMP,$c)) { $empCodeCol = $c; break; }
}

/* ---------------- resolve employee code ---------------- */
$EMPID = null;

if ($empCodeCol && $use_manual_code && $emp_code_manual !== '') {
  // বাংলা: manual code sanitize + duplicate check
  $manual = preg_replace('/[^A-Za-z0-9._-]/','', $emp_code_manual);
  if ($manual === '') {
    $_SESSION['flash'] = 'Invalid Employee Code (allowed: letters, digits, dot, underscore, dash).';
    header('Location: /public/hr/employee_add.php'); exit;
  }
  $q = $pdo->prepare("SELECT 1 FROM {$T_EMP} WHERE {$empCodeCol}=? LIMIT 1");
  $q->execute([$manual]);
  if ($q->fetchColumn()) {
    $_SESSION['flash'] = 'Duplicate Employee Code. Please choose another.';
    header('Location: /public/hr/employee_add.php'); exit;
  }
  $EMPID = $manual;
} else {
  // বাংলা: auto-generate only if string code column exists
  if ($empCodeCol) {
    $ym = date('Ym');
    $nextNum = 1;
    try {
      $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING_INDEX($empCodeCol, '.', -1) AS UNSIGNED))
                           FROM {$T_EMP} WHERE $empCodeCol LIKE '{$ym}.%'");
      $maxPart = (int)$stmt->fetchColumn();
      $nextNum = $maxPart + 1;
    } catch(Throwable $e) { $nextNum = 1; }
    $EMPID = $ym . '.' . $nextNum;
  }
}

/* ---------------- upload base dir ---------------- */
$baseDir1 = $_SERVER['DOCUMENT_ROOT'] . '/uploads/employee';
$baseDir2 = $_SERVER['DOCUMENT_ROOT'] . '/upload/employee'; // fallback
$saveDir  = is_dir($baseDir1) ? $baseDir1 : (is_dir($baseDir2) ? $baseDir2 : $baseDir2);
@mkdir($saveDir, 0775, true);

/* ---------------- insert ---------------- */
$pdo->beginTransaction();
try {
  $cols=[]; $qs=[]; $ps=[];

  // বাংলা: string code column থাকলে তবেই EMPID সেট করব
  if ($empCodeCol && $EMPID !== null) { $cols[]=$empCodeCol; $qs[]='?'; $ps[]=$EMPID; }

  // বাংলা: কমন কলাম ম্যাপ (যা টেবিলে থাকলে সেট করব)
  $map = [
    'e_name' => $e_name, 'name' => $e_name,
    'e_des'  => $e_des,  'designation' => $e_des,
    'gender' => $e_gender, 'e_gender' => $e_gender,
    'married_stu' => $married,
    'dob' => $dob ?: null, 'e_b_date' => $dob ?: null,
    'join_date' => $joindate ?: null, 'e_j_date' => $joindate ?: null,
    'n_id' => $n_id, 'nid' => $n_id,
    'bgroup' => $bgroup,

    'basic_salary' => $basic_salary,
    'mobile_bill'  => $mobile_bill,
    'house_rent'   => $house_rent,
    'medical'      => $medical,
    'food'         => $food,
    'others'       => $others,
    'provident_fund'   => $provident_fund,
    'professional_tax' => $professional_tax,
    'income_tax'       => $income_tax,

    'pre_address'  => $pre_address,  'present_address'   => $pre_address,
    'per_address'  => $per_address,  'permanent_address' => $per_address,

    'e_cont_per' => $e_cont_per,
    'e_cont_office' => $e_cont_off,
    'e_cont_family' => $e_cont_fam,

    'email' => $email, 'skype' => $skype,
  ];
  foreach ($map as $c=>$v){
    if (col_exists($pdo,$T_EMP,$c)) { $cols[]=$c; $qs[]='?'; $ps[]=$v; }
  }

  // বাংলা: department mapping
  if ($dept_id && col_exists($pdo,$T_EMP,'dept_id')) {
    $cols[]='dept_id'; $qs[]='?'; $ps[]=$dept_id;
  } elseif ($dept_text !== '' && col_exists($pdo,$T_EMP,'department')) {
    $cols[]='department'; $qs[]='?'; $ps[]=$dept_text;
  }

  // বাংলা: timestamps
  foreach (['created_at','updated_at'] as $tc) {
    if (col_exists($pdo,$T_EMP,$tc)) { $cols[]=$tc; $qs[]='?'; $ps[]=date('Y-m-d H:i:s'); }
  }

  $sql = "INSERT INTO {$T_EMP} (".implode(',',$cols).") VALUES (".implode(',',$qs).")";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($ps);

  // বাংলা: row identifier for subsequent updates
  $whereCol = null; $whereVal = null;
  $insertId = $pdo->lastInsertId();

  if ($pkCol && $insertId) { $whereCol = $pkCol; $whereVal = $insertId; }
  elseif ($empCodeCol && $EMPID !== null) { $whereCol = $empCodeCol; $whereVal = $EMPID; }

  /* ---------------- uploads ---------------- */
  if ($whereCol) {
    $fileStem = $EMPID ?: ('EMP'.date('YmdHis'));
    // photo
    if (!empty($_FILES['photo']['name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
      $ext = safe_ext($_FILES['photo']['name'], ['jpg','jpeg','png','webp']);
      if ($ext) {
        $to = $saveDir . '/' . $fileStem . '.' . $ext;
        @move_uploaded_file($_FILES['photo']['tmp_name'], $to);
        $p = str_replace($_SERVER['DOCUMENT_ROOT'],'',$to);
        foreach (['photo','photo_url','photo_path'] as $pc) {
          if (col_exists($pdo,$T_EMP,$pc)) {
            $pdo->prepare("UPDATE {$T_EMP} SET {$pc}=? WHERE {$whereCol}=?")->execute([$p,$whereVal]); break;
          }
        }
      }
    }
    // nid
    if (!empty($_FILES['nid_file']['name']) && is_uploaded_file($_FILES['nid_file']['tmp_name'])) {
      $ext = safe_ext($_FILES['nid_file']['name'], ['jpg','jpeg','png','webp','pdf']);
      if ($ext) {
        $to = $saveDir . '/' . $fileStem . '_NID.' . $ext;
        @move_uploaded_file($_FILES['nid_file']['tmp_name'], $to);
        $p = str_replace($_SERVER['DOCUMENT_ROOT'],'',$to);
        foreach (['nid_file','nid_url','nid_path'] as $nc) {
          if (col_exists($pdo,$T_EMP,$nc)) {
            $pdo->prepare("UPDATE {$T_EMP} SET {$nc}=? WHERE {$whereCol}=?")->execute([$p,$whereVal]); break;
          }
        }
      }
    }
  }

  /* ---------------- optional user creation ---------------- */
  $userCreatedMsg = '';
  if ($create_user && $T_USERS) {
    // বাংলা: user table column discovery
    $uCols = [
      'username'     => col_exists($pdo,$T_USERS,'username') ? 'username' : null,
      'password_hash'=> col_exists($pdo,$T_USERS,'password_hash') ? 'password_hash' : (col_exists($pdo,$T_USERS,'password') ? 'password' : null),
      'role'         => col_exists($pdo,$T_USERS,'role') ? 'role' : null,
      'full_name'    => col_exists($pdo,$T_USERS,'full_name') ? 'full_name' : (col_exists($pdo,$T_USERS,'name') ? 'name' : null),
      'email'        => col_exists($pdo,$T_USERS,'email') ? 'email' : null,
      'is_active'    => col_exists($pdo,$T_USERS,'is_active') ? 'is_active' : (col_exists($pdo,$T_USERS,'status') ? 'status' : null),
      'created_at'   => col_exists($pdo,$T_USERS,'created_at') ? 'created_at' : null,
      'employee_ref' => col_exists($pdo,$T_USERS,'employee_id') ? 'employee_id' : (col_exists($pdo,$T_USERS,'emp_id') ? 'emp_id' : null),
    ];

    if ($uCols['username'] && $uCols['password_hash']) {
      $uname = $user_username !== '' ? $user_username : slugify_username($e_name);
      $uname = ensure_unique_username($pdo,$uname,$T_USERS,$uCols['username']);

      // $plain = $user_password !== '' ? $user_password : gen_password(10);
      // $hash  = password_hash($plain, PASSWORD_DEFAULT);

      $colsU=[]; $qsU=[]; $psU=[];
      $colsU[]=$uCols['username'];      $qsU[]='?'; $psU[]=$uname;
      $colsU[]=$uCols['password_hash']; $qsU[]='?'; $psU[]= md5($user_password);
      if ($uCols['role'])       { $colsU[]=$uCols['role'];       $qsU[]='?'; $psU[]=$user_role ?: 'employee'; }
      if ($uCols['full_name'])  { $colsU[]=$uCols['full_name'];  $qsU[]='?'; $psU[]=$e_name; }
      if ($uCols['email'])      { $colsU[]=$uCols['email'];      $qsU[]='?'; $psU[]=$email; }
      if ($uCols['is_active'])  { $colsU[]=$uCols['is_active'];  $qsU[]='?'; $psU[]=1; }
      if ($uCols['created_at']) { $colsU[]=$uCols['created_at']; $qsU[]='?'; $psU[]=date('Y-m-d H:i:s'); }
      if ($uCols['employee_ref']) {
        $colsU[]=$uCols['employee_ref']; $qsU[]='?';
        $psU[] = $EMPID ?: ($insertId ?: null); // বাংলা: EMPID না থাকলে insertId
      }

      $sqlU="INSERT INTO {$T_USERS} (".implode(',',$colsU).") VALUES (".implode(',',$qsU).")";
      $pdo->prepare($sqlU)->execute($psU);

      $userCreatedMsg = " User created: {$uname} (temp password: {$plain}).";
    }
  }

  $pdo->commit();
  $_SESSION['flash'] = 'Employee added successfully.' . $userCreatedMsg;
  header('Location: /public/hr/employees.php'); exit;

} catch(Throwable $e) {
  $pdo->rollBack();
  $_SESSION['flash'] = 'Save failed: '.$e->getMessage();
  header('Location: /public/hr/employee_add.php'); exit;
}
