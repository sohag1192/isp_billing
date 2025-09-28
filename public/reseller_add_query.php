<?php
// /public/reseller_add_query.php
// UI English; Comments Bangla — schema-aware insert (only insert existing columns)

declare(strict_types=1);

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

// (optional) ACL
$acl_file = __DIR__ . '/../app/acl.php';
if (is_file($acl_file)) require_once $acl_file;
if (function_exists('require_perm')) require_perm('reseller.manage');

// CSRF
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
  http_response_code(403); exit('Bad CSRF token');
}

// Safe HTML
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

try{
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // --- detect available columns in resellers ---
  $cols = [];
  try{
    $cols = $pdo->query("SHOW COLUMNS FROM resellers")->fetchAll(PDO::FETCH_COLUMN);
  }catch(Throwable $e){ $cols = []; }
  $has = fn(string $c) => in_array($c, $cols, true);

  // --- inputs (collect everything, later filter by existing columns) ---
  $in = [
    'code'      => trim($_POST['code'] ?? ''),
    'name'      => trim($_POST['name'] ?? ''),
    'phone'     => trim($_POST['phone'] ?? ''),
    'email'     => trim($_POST['email'] ?? ''),
    'address'   => trim($_POST['address'] ?? ''),
    'is_active' => isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1,
  ];

  if($in['name'] === ''){
    $_SESSION['flash_err'] = 'Name is required';
    header('Location: reseller_add.php'); exit;
  }

  // বাংলা: code অটো-জেনারেট কেবল তখনই যখন code কলাম আছে
  if($has('code') && $in['code'] === ''){
    $ym = date('Ym');
    $st = $pdo->prepare("SELECT COUNT(*) FROM resellers WHERE DATE_FORMAT(COALESCE(updated_at,created_at),'%Y%m') = ?");
    $st->execute([date('Ym')]);
    $cnt = (int)$st->fetchColumn() + 1;
    $in['code'] = 'RS'.$ym.str_pad((string)$cnt, 3, '0', STR_PAD_LEFT);
  }

  // --- build dynamic insert only with existing columns ---
  $insertData = [];
  foreach ($in as $k=>$v) {
    if ($has($k)) $insertData[$k] = $v;
  }
  // timestamps if present
  if ($has('created_at')) $insertData['created_at'] = ['RAW' => 'NOW()'];
  if ($has('updated_at')) $insertData['updated_at'] = ['RAW' => 'NOW()'];

  if (empty($insertData)) {
    throw new RuntimeException("No matching columns found in `resellers` table.");
  }

  // prepare SQL
  $fields = array_keys($insertData);
  $placeholders = [];
  $values = [];
  foreach ($insertData as $k=>$v) {
    if (is_array($v) && isset($v['RAW'])) {
      $placeholders[] = $v['RAW']; // NOW()
    } else {
      $placeholders[] = '?';
      $values[] = $v;
    }
  }
  $sql = "INSERT INTO resellers (".implode(',', array_map(fn($f)=>"`$f`",$fields)).") VALUES (".implode(',', $placeholders).")";
  $st = $pdo->prepare($sql);
  $st->execute($values);

  $id = (int)$pdo->lastInsertId();

  // audit (best-effort)
  try {
    if (function_exists('audit_log')) {
      $user_id = (int)($_SESSION['user_id'] ?? 0);
      @audit_log($user_id, $id, 'reseller_add', json_encode(['name'=>$in['name']], JSON_UNESCAPED_UNICODE));
    } else {
      $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        entity_id VARCHAR(64) NULL,
        action VARCHAR(64) NOT NULL,
        meta JSON NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      $stA = $pdo->prepare("INSERT INTO audit_logs (user_id, entity_id, action, meta) VALUES (?,?,?,?)");
      $stA->execute([(int)($_SESSION['user_id'] ?? 0), (string)$id, 'reseller_add',
        json_encode(['name'=>$in['name']], JSON_UNESCAPED_UNICODE)]);
    }
  } catch(Throwable $e) { /* silent */ }

  header('Location: reseller_view.php?id='.$id);
  exit;

} catch(Throwable $e){
  if (session_status() === PHP_SESSION_NONE) { session_start(); }
  $_SESSION['flash_err'] = 'Insert failed: '.$e->getMessage();
  header('Location: reseller_add.php'); exit;
}
