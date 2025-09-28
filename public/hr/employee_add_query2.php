<?php
// /public/hr/employee_add_query.php
// Insert employee (schema-aware) + upload Photo/NID to /uploads/employee
// Code: English; Comments: Bangla.

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','0'); // প্রোডাকশন: স্ক্রিনে error নয়
ini_set('log_errors','1');

session_start();
require_once __DIR__ . '/../../app/db.php';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------- helpers ---------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function tbl_exists(PDO $pdo, string $t): bool {
  try{
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $q  = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
    $q->execute([$db,$t]);
    return (bool)$q->fetchColumn();
  }catch(Throwable $e){ return false; }
}
function mime_to_ext(string $m): ?string {
  $map = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'application/pdf' => 'pdf'
  ];
  $m = strtolower(trim($m));
  return $map[$m] ?? null;
}
if (!function_exists('audit_log_safe')) {
  function audit_log_safe(string $a, ?int $id=null, array $meta=[]): void {
    if (!function_exists('audit_log')) return;
    try { audit_log($a,$id,$meta); return; } catch(Throwable $e) {}
    try { audit_log($a,$id); return; } catch(Throwable $e) {}
    try { audit_log($a); } catch(Throwable $e) {}
  }
}

/* ---------- CSRF (optional) ---------- */
// বাংলা: যদি সাইটে CSRF টোকেন ব্যবহার করেন, এখানে চেক হবে
if (!empty($_SESSION['csrf'])) {
  $token = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['csrf'], $token)) {
    http_response_code(403);
    exit('CSRF validation failed.');
  }
}

/* ---------- target table ---------- */
$T_EMP = tbl_exists($pdo,'emp_info') ? 'emp_info' :
         (tbl_exists($pdo,'employees') ? 'employees' : null);
if (!$T_EMP) {
  http_response_code(500);
  exit('Employees table not found.');
}

/* ---------- prepare column map ---------- */
$cols = []; // key: lower(Field) => original Field
$rs = $pdo->query("SHOW COLUMNS FROM {$T_EMP}");
while ($r = $rs->fetch(PDO::FETCH_ASSOC)) {
  $cols[strtolower($r['Field'])] = $r['Field'];
}
$hascol = function(string $name) use ($cols): bool {
  return array_key_exists(strtolower($name), $cols);
};
$getcol = function(string $name) use ($cols): ?string {
  $lk = strtolower($name);
  return $cols[$lk] ?? null;
};

/* ---------- generate EMPID (YYYYMM.(last id + 1)) ---------- */
// বাংলা: next numeric id lock করে EMPID বানাই
$y   = date('Y'); 
$m   = date('m'); 
$ym  = $y.$m; 
$emp_id = '';

$pdo->beginTransaction();
try{
  // বাংলা: id DESC lock নিয়ে সর্বশেষ id নিলাম
  $stmt = $pdo->query("SELECT id FROM {$T_EMP} ORDER BY id DESC LIMIT 1 FOR UPDATE");
  $last_id = (int)($stmt->fetchColumn() ?: 0);
  $emp_id  = ($last_id === 0) ? ($ym.'1') : ($ym.($last_id + 1));

  /* ---------- build insert data (schema-aware) ---------- */
  $data = [];

  // 1) Direct POST → same-name columns
  foreach ($_POST as $k => $v) {
    if ($k === '_csrf' || $k === 'csrf_token') continue;
    $lk = strtolower($k);
    if (isset($cols[$lk])) {
      $data[$cols[$lk]] = is_string($v) ? trim($v) : $v;
    }
  }

  // 2) EMPID to likely columns
  foreach (['e_id','emp_id','employee_id','emp_code'] as $c) {
    if ($hascol($c) && !isset($data[$getcol($c)])) {
      $data[$getcol($c)] = $emp_id;
    }
  }

  // 3) Name mapping (e_name → name/e_name)
  if (isset($_POST['e_name'])) {
    foreach (['e_name','name'] as $c) {
      if ($hascol($c) && !isset($data[$getcol($c)])) {
        $data[$getcol($c)] = trim((string)$_POST['e_name']);
      }
    }
  }

  // 4) Designation mapping (e_des → e_des/designation/title)
  if (isset($_POST['e_des'])) {
    foreach (['e_des','designation','title'] as $c) {
      if ($hascol($c) && !isset($data[$getcol($c)])) {
        $data[$getcol($c)] = trim((string)$_POST['e_des']);
      }
    }
  }

  // 5) Department mapping (id select or free-text)
  if (isset($_POST['e_dept']) && $_POST['e_dept'] !== '') {
    foreach (['department_id','dept_id','e_dept'] as $c) {
      if ($hascol($c) && !isset($data[$getcol($c)])) {
        $data[$getcol($c)] = (string)$_POST['e_dept'];
      }
    }
  } elseif (!empty($_POST['e_dept_text'])) {
    // বাংলা: ডিপার্টমেন্ট টেবিল না থাকলে text ফিল্ড সেভ করার চেষ্টা
    $deptText = trim((string)$_POST['e_dept_text']);
    foreach (['department','department_name','dept_name'] as $c) {
      if ($hascol($c) && !isset($data[$getcol($c)])) {
        $data[$getcol($c)] = $deptText;
        break;
      }
    }
  }

  // 6) timestamps (created_at/updated_at) if present
  $now = date('Y-m-d H:i:s');
  if ($hascol('created_at') && !isset($data[$getcol('created_at')])) $data[$getcol('created_at')] = $now;
  if ($hascol('updated_at') && !isset($data[$getcol('updated_at')])) $data[$getcol('updated_at')] = $now;

  // 7) Minimal validation (name + designation)
  $nameCol = $getcol('e_name') ?: $getcol('name');
  if ($nameCol && empty($data[$nameCol])) throw new RuntimeException('Name is required.');
  $desCol  = $getcol('e_des') ?: ($getcol('designation') ?: $getcol('title'));
  if ($desCol && empty($data[$desCol])) throw new RuntimeException('Designation is required.');

  if (empty($data)) throw new RuntimeException('No matching columns to insert.');

  // 8) INSERT
  $fields = array_keys($data);
  $params = array_map(fn($c)=>":$c", $fields);
  $sql    = "INSERT INTO {$T_EMP} (`".implode('`,`',$fields)."`) VALUES (".implode(',',$params).")";
  $st     = $pdo->prepare($sql);
  foreach ($data as $c=>$v) { $st->bindValue(":$c", $v); }
  $st->execute();
  $new_id = (int)$pdo->lastInsertId();

  /* ---------- uploads ---------- */
  $ROOT   = dirname(__DIR__, 2);
  $dir    = $ROOT . '/uploads/employee';
  if (!is_dir($dir)) { @mkdir($dir, 0755, true); }

  $finfo   = new finfo(FILEINFO_MIME_TYPE);
  $MAX_IMG = 5 * 1024 * 1024;  // 5MB for images
  $MAX_DOC = 10 * 1024 * 1024; // 10MB for NID (PDF allowed)

  $saved_photo = null;
  $saved_nid   = null;

  // Photo
  if (!empty($_FILES['photo']['tmp_name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
    $size = (int)filesize($_FILES['photo']['tmp_name']);
    if ($size > $MAX_IMG) throw new RuntimeException('Photo too large (max 5MB).');
    $mime = (string)($finfo->file($_FILES['photo']['tmp_name']) ?: '');
    $ext  = mime_to_ext($mime);
    if (!$ext || !in_array($ext, ['jpg','png','webp'], true)) {
      throw new RuntimeException('Invalid photo type (jpg/png/webp).');
    }
    // বাংলা: পুরনো এক্সটেনশন থাকলে ক্লিন
    foreach (['jpg','png','webp'] as $e) @unlink($dir.'/'.$emp_id.'.'.$e);
    $dest = $dir . '/' . $emp_id . '.' . $ext;
    if (!move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
      throw new RuntimeException('Photo upload failed.');
    }
    $saved_photo = '/uploads/employee/' . $emp_id . '.' . $ext;
  }

  // NID (image or PDF)
  if (!empty($_FILES['nid_file']['tmp_name']) && is_uploaded_file($_FILES['nid_file']['tmp_name'])) {
    $size = (int)filesize($_FILES['nid_file']['tmp_name']);
    if ($size > $MAX_DOC) throw new RuntimeException('NID file too large (max 10MB).');
    $mime = (string)($finfo->file($_FILES['nid_file']['tmp_name']) ?: '');
    $ext  = mime_to_ext($mime);
    if (!$ext || !in_array($ext, ['jpg','png','webp','pdf'], true)) {
      throw new RuntimeException('Invalid NID type (jpg/png/webp/pdf).');
    }
    foreach (['jpg','png','webp','pdf'] as $e) @unlink($dir.'/'.$emp_id.'_NID.'.$e);
    $dest = $dir . '/' . $emp_id . '_NID.' . $ext;
    if (!move_uploaded_file($_FILES['nid_file']['tmp_name'], $dest)) {
      throw new RuntimeException('NID upload failed.');
    }
    $saved_nid = '/uploads/employee/' . $emp_id . '_NID.' . $ext;
  }

  // বাংলা: photo_path / nid_path কলাম থাকলে আপডেট করে দেই
  $updates = [];
  $paramsU = [];
  if ($saved_photo && $hascol('photo_path')) { $updates[] = $getcol('photo_path').' = ?'; $paramsU[] = $saved_photo; }
  if ($saved_nid   && $hascol('nid_path'))   { $updates[] = $getcol('nid_path')  .' = ?'; $paramsU[] = $saved_nid; }
  if (!empty($updates)) {
    $paramsU[] = $new_id;
    $pdo->prepare("UPDATE {$T_EMP} SET ".implode(', ', $updates)." WHERE id = ?")->execute($paramsU);
  }

  // audit (optional)
  $af = __DIR__ . '/../../app/audit.php';
  if (is_file($af)) require_once $af;
  audit_log_safe('employee_add', $new_id, [
    'emp_id' => $emp_id,
    'e_name' => (string)($_POST['e_name'] ?? ''),
  ]);

  $pdo->commit();

  // বাংলা: ভিউতে পাঠাচ্ছি EMPID দিয়ে (আপনার view ফাইল যেমন নেয়)
  header('Location: /public/hr/employee_view.php?e_id=' . urlencode($emp_id));
  exit;

} catch (Throwable $e) {
  $pdo->rollBack();
  http_response_code(500);
  echo "Error: " . h($e->getMessage());
}
