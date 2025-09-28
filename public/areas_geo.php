<?php
// /public/areas_geo.php
// UI: N/A (API); Comments: বাংলা
// Feature: Area polygon CRUD + list (GeoJSON)

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------------- helpers ---------------- */
function tbl_exists(PDO $pdo, string $t): bool {
  try {
    $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
    $st=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
    $st->execute([$db,$t]); return (bool)$st->fetchColumn();
  } catch(Throwable $e){ return false; }
}

/* ---------------- ensure table ---------------- */
$T = 'network_areas';
if (!tbl_exists($pdo, $T)) {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS `network_areas` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `name` VARCHAR(120) NOT NULL,
      `color` VARCHAR(16) NOT NULL DEFAULT '#0d6efd',
      `geojson` MEDIUMTEXT NOT NULL,
      `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
}

/* ---------------- routing ---------------- */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
  if ($method === 'GET') {
    if (isset($_GET['list'])) {
      $rows = $pdo->query("SELECT id, name, color, geojson FROM `$T` ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
      $features = [];
      foreach ($rows as $r) {
        $g = json_decode($r['geojson'], true);
        if (!$g) continue;
        if (isset($g['type']) && $g['type']==='Feature') {
          $g['properties'] = array_merge(['id'=>$r['id'],'name'=>$r['name'],'color'=>$r['color']], $g['properties'] ?? []);
          $features[] = $g;
        } elseif (isset($g['type']) && $g['type']==='FeatureCollection') {
          foreach ($g['features'] as &$f) {
            $f['properties'] = array_merge(['id'=>$r['id'],'name'=>$r['name'],'color'=>$r['color']], $f['properties'] ?? []);
          }
          unset($f);
          $features = array_merge($features, $g['features']);
        } else {
          $features[] = [
            'type'=>'Feature',
            'geometry'=>$g,
            'properties'=>['id'=>$r['id'],'name'=>$r['name'],'color'=>$r['color']]
          ];
        }
      }
      echo json_encode(['ok'=>true,'type'=>'FeatureCollection','features'=>$features], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
      exit;
    }
    $id = (int)($_GET['id'] ?? 0);
    if ($id>0) {
      $st=$pdo->prepare("SELECT id,name,color,geojson FROM `$T` WHERE id=? LIMIT 1");
      $st->execute([$id]);
      $row=$st->fetch(PDO::FETCH_ASSOC);
      echo json_encode(['ok'=> (bool)$row, 'data'=>$row]);
      exit;
    }
    echo json_encode(['ok'=>true,'message'=>'areas_geo api']); exit;
  }

  if ($method === 'POST') {
    $id     = (int)($_POST['id'] ?? 0);
    $name   = trim((string)($_POST['name'] ?? ''));
    $color  = trim((string)($_POST['color'] ?? '#0d6efd'));
    $geojson= trim((string)($_POST['geojson'] ?? ''));

    if ($name==='' || $geojson==='') { echo json_encode(['ok'=>false,'error'=>'name/geojson required']); exit; }
    $g = json_decode($geojson, true);
    if (!$g) { echo json_encode(['ok'=>false,'error'=>'Invalid GeoJSON']); exit; }

    if ($id>0) {
      $st=$pdo->prepare("UPDATE `$T` SET name=?, color=?, geojson=? WHERE id=?");
      $ok=$st->execute([$name,$color,$geojson,$id]);
      echo json_encode(['ok'=>$ok?true:false, 'id'=>$id]); exit;
    } else {
      $st=$pdo->prepare("INSERT INTO `$T` (name,color,geojson) VALUES (?,?,?)");
      $ok=$st->execute([$name,$color,$geojson]);
      echo json_encode(['ok'=>$ok?true:false, 'id'=> (int)$pdo->lastInsertId()]); exit;
    }
  }

  if ($method === 'DELETE') {
    parse_str($_SERVER['QUERY_STRING'] ?? '', $q);
    $id=(int)($q['id'] ?? 0);
    if ($id<=0){ echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
    $st=$pdo->prepare("DELETE FROM `$T` WHERE id=? LIMIT 1");
    $ok=$st->execute([$id]);
    echo json_encode(['ok'=>$ok?true:false]); exit;
  }

  echo json_encode(['ok'=>false,'error'=>'Unsupported method']);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
