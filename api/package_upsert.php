<?php
// /api/package_upsert.php
// (বাংলা) Create/Update package (name unique, price>=0)
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

header('Content-Type: application/json; charset=utf-8');
function out($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

try{
  $in = json_decode(file_get_contents('php://input'), true) ?? [];
  $id    = isset($in['id']) && $in['id'] !== '' ? (int)$in['id'] : null;
  $name  = trim((string)($in['name'] ?? ''));
  $price = (float)($in['price'] ?? 0);

  if ($name === '') out(['ok'=>false,'error'=>'Package name is required']);
  if ($price < 0)   out(['ok'=>false,'error'=>'Price must be >= 0']);

  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // (বাংলা) name unique (case-insensitive)
  $sqlDup = "SELECT id FROM packages WHERE LOWER(name)=LOWER(?)";
  $params = [$name];
  if ($id) { $sqlDup .= " AND id<>?"; $params[] = $id; }
  $st = $pdo->prepare($sqlDup); $st->execute($params);
  if ($st->fetch()) out(['ok'=>false,'error'=>'A package with the same name already exists']);

  if ($id) {
    $u = $pdo->prepare("UPDATE packages SET name=?, price=?, updated_at=NOW() WHERE id=?");
    $u->execute([$name,$price,$id]);
    out(['ok'=>true,'message'=>'Package updated','id'=>$id]);
  } else {
    $i = $pdo->prepare("INSERT INTO packages (name, price, created_at, updated_at) VALUES (?,?,NOW(),NOW())");
    $i->execute([$name,$price]);
    out(['ok'=>true,'message'=>'Package created','id'=>(int)$pdo->lastInsertId()]);
  }
}catch(Throwable $e){
  out(['ok'=>false,'error'=>$e->getMessage()]);
}
