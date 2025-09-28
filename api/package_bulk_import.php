<?php
// /api/package_bulk_import.php
// (বাংলা) Create missing packages from router PPP profiles. dry=1 => preview only.
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

header('Content-Type: application/json; charset=utf-8');
function out($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
function hascol(PDO $pdo, $tbl, $col){
  $st=$pdo->prepare("SHOW COLUMNS FROM `$tbl` LIKE ?"); $st->execute([$col]);
  return (bool)$st->fetchColumn();
}

try{
  $in = json_decode(file_get_contents('php://input'), true) ?? [];
  $router_id     = (int)($in['router_id'] ?? 0);
  $profiles      = array_values(array_filter(array_map('trim', (array)($in['profiles'] ?? []))));
  $default_price = (float)($in['default_price'] ?? 0);
  $dry           = (int)($in['dry'] ?? 0);

  if (!$profiles) out(['ok'=>false,'error'=>'Empty profiles']);

  $pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // (বাংলা) current package names (lowercase map)
  $cur = [];
  $q = $pdo->query("SELECT name FROM packages" . (hascol($pdo,'packages','is_deleted') ? " WHERE COALESCE(is_deleted,0)=0" : ""));
  foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $nm) $cur[mb_strtolower($nm)] = true;

  $items = [];
  foreach ($profiles as $p) {
    $lname = mb_strtolower($p);
    $exists = isset($cur[$lname]);
    $items[] = ['name'=>$p, 'exists'=>$exists];
  }

  if ($dry) out(['ok'=>true,'items'=>$items]);

  $created=0; $skipped=0;
  $ins = $pdo->prepare("INSERT INTO packages (name, price, created_at, updated_at) VALUES (?,?,NOW(),NOW())");
  foreach ($items as $it){
    if ($it['exists']) { $skipped++; continue; }
    $ins->execute([$it['name'], $default_price]);
    $created++;
  }
  out(['ok'=>true,'created'=>$created,'skipped'=>$skipped]);
}catch(Throwable $e){
  out(['ok'=>false,'error'=>$e->getMessage()]);
}
