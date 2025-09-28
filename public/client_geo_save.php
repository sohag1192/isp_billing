<?php
// /public/client_geo_save.php
// UI: (N/A) — JSON API; Comments: বাংলা
// Feature: Save lat/lng for a client

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function tbl_exists(PDO $pdo, string $t): bool {
  try { $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
        $st=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
        $st->execute([$db,$t]); return (bool)$st->fetchColumn();
  } catch(Throwable $e){ return false; }
}
function col_exists(PDO $pdo, string $tbl, string $col): bool {
  try { $st=$pdo->prepare("SHOW COLUMNS FROM `$tbl` LIKE ?"); $st->execute([$col]); return (bool)$st->fetchColumn(); }
  catch(Throwable $e){ return false; }
}
function pick_col(PDO $pdo, string $tbl, array $cands, string $fallback=''): string {
  foreach ($cands as $c) if (col_exists($pdo,$tbl,$c)) return $c;
  return $fallback;
}

/* --------------- detect schema --------------- */
$CLIENT_TBL = 'clients';
foreach (['clients','client','client_info','customers','subscriber','subscribers'] as $cand) {
  if (tbl_exists($pdo, $cand)) { $CLIENT_TBL = $cand; break; }
}
$ID_COL  = pick_col($pdo, $CLIENT_TBL, ['id','client_id','uid','cid'], 'id');
$LAT_COL = pick_col($pdo, $CLIENT_TBL, ['lat','latitude','gps_lat','geo_lat'], '');
$LNG_COL = pick_col($pdo, $CLIENT_TBL, ['lng','longitude','lon','gps_lng','geo_lon','geo_lng'], '');

if (!$LAT_COL || !$LNG_COL) { echo json_encode(['ok'=>false,'error'=>'lat/lng columns missing']); exit; }

/* --------------- inputs --------------- */
$client_id = $_POST['client_id'] ?? '';
$id_key    = $_POST['id_key'] ?? $ID_COL;
$lat       = $_POST['lat'] ?? null;
$lng       = $_POST['lng'] ?? null;

if ($id_key !== $ID_COL) { echo json_encode(['ok'=>false,'error'=>'Invalid id key']); exit; }
if (!is_numeric($lat) || !is_numeric($lng)) { echo json_encode(['ok'=>false,'error'=>'Invalid lat/lng']); exit; }

$lat = (float)$lat;
$lng = (float)$lng;

$sql = "UPDATE `$CLIENT_TBL` SET `$LAT_COL`=?, `$LNG_COL`=? WHERE `$ID_COL`=? LIMIT 1";
$st  = $pdo->prepare($sql);
$ok  = $st->execute([$lat, $lng, $client_id]);

echo json_encode(['ok'=>$ok ? true : false]);
