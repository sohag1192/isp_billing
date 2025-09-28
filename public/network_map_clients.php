<?php
// /public/network_map_clients.php
// Returns JSON of clients with geo positions for the network map
// live=1 হলে MikroTik PPP Active পড়বে এবং 'online' ফ্ল্যাগ সেট করবে
// UI: (N/A) — API only; Comments: বাংলা

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../app/db.php';

// MikroTik RouterOS API (প্রোজেক্টে আগেই আছে বলে ধরা হচ্ছে)
$api_path = __DIR__ . '/../app/routeros_api.class.php';
if (is_file($api_path)) {
  require_once $api_path;
}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------------- helpers ---------------- */
function col_exists(PDO $pdo, string $tbl, string $col): bool {
  try { $st=$pdo->prepare("SHOW COLUMNS FROM `$tbl` LIKE ?"); $st->execute([$col]); return (bool)$st->fetchColumn(); }
  catch(Throwable $e){ return false; }
}
function tbl_exists(PDO $pdo, string $t): bool {
  try {
    $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
    $st=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
    $st->execute([$db,$t]); return (bool)$st->fetchColumn();
  } catch(Throwable $e){ return false; }
}
function pick_col(PDO $pdo, string $tbl, array $cands, string $fallback=''): string {
  foreach ($cands as $c){ if (col_exists($pdo,$tbl,$c)) return $c; }
  return $fallback;
}
function pick_tbl(PDO $pdo, array $cands, string $fallback=''): string {
  foreach ($cands as $t){ if (tbl_exists($pdo,$t)) return $t; }
  return $fallback;
}

/* ---------------- detect client schema ---------------- */
$CLIENT_TBL = pick_tbl($pdo, ['clients','client','client_info','customers','subscriber','subscribers'], 'clients');

$ID_COL     = pick_col($pdo, $CLIENT_TBL, ['id','client_id','uid','cid'], 'id');
$NAME_COL   = pick_col($pdo, $CLIENT_TBL, ['name','full_name','client_name'], $ID_COL);
$PPPOE_COL  = pick_col($pdo, $CLIENT_TBL, ['pppoe_id','pppoe','username','user'], '');
$PHONE_COL  = pick_col($pdo, $CLIENT_TBL, ['phone','mobile','contact','contact_no','mobile_no'], '');
$AREA_COL   = pick_col($pdo, $CLIENT_TBL, ['area','zone','location'], '');
$PKG_COL    = pick_col($pdo, $CLIENT_TBL, ['package','package_id','plan','plan_id'], '');
$STATUS_COL = pick_col($pdo, $CLIENT_TBL, ['status','is_active','active'], '');
$LAT_COL    = pick_col($pdo, $CLIENT_TBL, ['lat','latitude','gps_lat','geo_lat'], '');
$LNG_COL    = pick_col($pdo, $CLIENT_TBL, ['lng','longitude','lon','gps_lng','geo_lon','geo_lng'], '');
$ROUTER_FK  = pick_col($pdo, $CLIENT_TBL, ['router_id','ap_id','nas_id','router','ap'], '');

// lat/lng না থাকলে কিছু দেখানোর নেই
if (!$LAT_COL || !$LNG_COL) { echo json_encode([]); exit; }

/* ---------------- optional routers schema (AP lines) ---------------- */
$ROUTERS_TBL = pick_tbl($pdo, ['routers','router','ap_list','access_points'], '');
$R_ID   = $R_NAME = $R_LAT = $R_LNG = '';
if ($ROUTERS_TBL) {
  $R_ID   = pick_col($pdo, $ROUTERS_TBL, ['id','router_id','rid'], '');
  $R_NAME = pick_col($pdo, $ROUTERS_TBL, ['name','router_name','identity'], '');
  $R_LAT  = pick_col($pdo, $ROUTERS_TBL, ['lat','latitude','gps_lat'], '');
  $R_LNG  = pick_col($pdo, $ROUTERS_TBL, ['lng','longitude','gps_lng','lon'], '');
}

/* ---------------- filters from query ---------------- */
$status  = trim($_GET['status']  ?? '');
$area    = trim($_GET['area']    ?? '');
$package = trim($_GET['package'] ?? '');
$q       = trim($_GET['q']       ?? '');
$needLive= isset($_GET['live']) && $_GET['live']=='1';

$where = [];
$bind  = [];

if ($status !== '' && $STATUS_COL) {
  $where[] = "`$STATUS_COL` LIKE ?";
  $bind[]  = "%$status%";
}
if ($area !== '' && $AREA_COL) {
  $where[] = "`$AREA_COL` = ?";
  $bind[]  = $area;
}
if ($package !== '' && $PKG_COL) {
  $where[] = "`$PKG_COL` = ?";
  $bind[]  = $package;
}
if ($q !== '') {
  $sub = [];
  $subBind = [];
  foreach ([$NAME_COL, $PPPOE_COL, $PHONE_COL] as $c) {
    if ($c) { $sub[] = "`$c` LIKE ?"; $subBind[] = "%$q%"; }
  }
  if ($sub) { $where[] = '(' . implode(' OR ', $sub) . ')'; $bind = array_merge($bind, $subBind); }
}

$sql = "SELECT `$ID_COL` AS id, `$NAME_COL` AS name, `$LAT_COL` AS lat, `$LNG_COL` AS lng";
if ($PPPOE_COL)  $sql .= ", `$PPPOE_COL` AS pppoe";
if ($PHONE_COL)  $sql .= ", `$PHONE_COL` AS phone";
if ($AREA_COL)   $sql .= ", `$AREA_COL` AS area";
if ($PKG_COL)    $sql .= ", `$PKG_COL` AS package";
if ($STATUS_COL) $sql .= ", `$STATUS_COL` AS status";
if ($ROUTER_FK)  $sql .= ", `$ROUTER_FK` AS router_id";
$sql .= " FROM `$CLIENT_TBL` WHERE `$LAT_COL` IS NOT NULL AND `$LNG_COL` IS NOT NULL";
$sql .= " AND TRIM(`$LAT_COL`)<>'' AND TRIM(`$LNG_COL`)<>''";
if ($where) $sql .= ' AND ' . implode(' AND ', $where);
$sql .= " LIMIT 15000";

$st = $pdo->prepare($sql);
$st->execute($bind);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

/* ---------------- build router map (for AP points) ---------------- */
$routerMap = [];
if ($ROUTERS_TBL && $R_ID && ($R_LAT && $R_LNG)) {
  $rsql = "SELECT `$R_ID` AS id";
  if ($R_NAME) $rsql .= ", `$R_NAME` AS name";
  $rsql .= ", `$R_LAT` AS lat, `$R_LNG` AS lng FROM `$ROUTERS_TBL`";
  $r = $pdo->query($rsql)->fetchAll(PDO::FETCH_ASSOC);
  foreach ($r as $it) {
    $routerMap[(string)$it['id']] = [
      'name' => $it['name'] ?? 'Router',
      'lat'  => is_numeric($it['lat']) ? (float)$it['lat'] : null,
      'lng'  => is_numeric($it['lng']) ? (float)$it['lng'] : null,
    ];
  }
}

/* ---------------- live PPPoE online usernames (optional) ---------------- */
// বাংলা: MikroTik গুলোর PPP Active নামের সেট। 20s ফাইল-ক্যাশ করে রাখি।
$onlineUsers = [];
if ($needLive && class_exists('RouterosAPI')) {
  $cacheFile = sys_get_temp_dir() . '/mik_online_cache.json';
  $cacheTTL  = 20;
  $now       = time();
  $useCache  = false;

  if (is_file($cacheFile)) {
    $stat = @stat($cacheFile);
    if ($stat && ($now - $stat['mtime'] <= $cacheTTL)) {
      $txt = @file_get_contents($cacheFile);
      if ($txt) {
        $arr = json_decode($txt, true);
        if (is_array($arr)) { $onlineUsers = $arr; $useCache = true; }
      }
    }
  }

  if (!$useCache) {
    $RT = pick_tbl($pdo, ['routers','mikrotik','nas'], '');
    if ($RT) {
      $RID   = pick_col($pdo, $RT, ['id','router_id'], 'id');
      $RHOST = pick_col($pdo, $RT, ['host','ip','address','router_ip'], '');
      $RPORT = pick_col($pdo, $RT, ['api_port','port'], '');
      $RUSER = pick_col($pdo, $RT, ['api_user','user','username','login'], '');
      $RPASS = pick_col($pdo, $RT, ['api_pass','password','pass','secret'], '');
      $RSTAT = pick_col($pdo, $RT, ['status','is_active','active'], '');

      $q = "SELECT `$RID` AS id, `$RHOST` AS host".
           ($RPORT? ", `$RPORT` AS port" : "").
           ($RUSER? ", `$RUSER` AS user" : "").
           ($RPASS? ", `$RPASS` AS pass" : "").
           " FROM `$RT`";
      if ($RSTAT) $q .= " WHERE (`$RSTAT` IN ('1','active','enabled') OR `$RSTAT` IS NULL)";

      $routers = $pdo->query($q)->fetchAll(PDO::FETCH_ASSOC);

      foreach ($routers as $r) {
        $host = trim((string)($r['host'] ?? ''));
        if ($host==='') continue;
        $port = (int)($r['port'] ?? 8728);
        $user = (string)($r['user'] ?? 'admin');
        $pass = (string)($r['pass'] ?? '');

        try {
          $api = new RouterosAPI();
          $api->port = $port ?: 8728;
          $api->attempts = 1;
          $api->timeout  = 3;
          if (@$api->connect($host, $user, $pass)) {
            $api->write('/ppp/active/print');
            $resp = $api->read();
            $api->disconnect();
            if (is_array($resp)) {
              foreach ($resp as $row) {
                if (!is_array($row)) continue;
                $name = $row['name'] ?? $row['user'] ?? null;
                if ($name) {
                  $onlineUsers[strtolower((string)$name)] = true;
                }
              }
            }
          }
        } catch (Throwable $e) {
          // নিঃশব্দে স্কিপ
        }
      }
    }
    @file_put_contents($cacheFile, json_encode(array_keys($onlineUsers), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  }
  // normalize
  if (array_values($onlineUsers) === $onlineUsers) {
    $u = [];
    foreach ($onlineUsers as $nm) { $u[strtolower((string)$nm)] = true; }
    $onlineUsers = $u;
  }
}

/* ---------------- build output ---------------- */
$out = [];
foreach ($rows as $c) {
  $lat = is_numeric($c['lat']) ? (float)$c['lat'] : null;
  $lng = is_numeric($c['lng']) ? (float)$c['lng'] : null;
  if ($lat === null || $lng === null) continue;

  $item = [
    'id'      => $c['id'],
    'name'    => $c['name'] ?? null,
    'pppoe'   => $c['pppoe'] ?? null,
    'phone'   => $c['phone'] ?? null,
    'area'    => $c['area'] ?? null,
    'package' => $c['package'] ?? null,
    'status'  => $c['status'] ?? null,
    'lat'     => $lat,
    'lng'     => $lng,
  ];

  if ($needLive && $PPPOE_COL && !empty($item['pppoe'])) {
    $pp = strtolower((string)$item['pppoe']);
    $item['online'] = isset($onlineUsers[$pp]) ? true : false;
  }

  if (!empty($c['router_id']) && $routerMap) {
    $rid = (string)$c['router_id'];
    if (isset($routerMap[$rid])) {
      $item['router_name'] = $routerMap[$rid]['name'];
      if ($routerMap[$rid]['lat'] !== null && $routerMap[$rid]['lng'] !== null) {
        $item['ap_lat'] = $routerMap[$rid]['lat'];
        $item['ap_lng'] = $routerMap[$rid]['lng'];
      }
    }
  }

  $out[] = $item;
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
