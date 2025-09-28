<?php
/**
 * /tools/sync_clients_router_mac.php
 * Update clients.router_mac from MikroTik PPPoE active caller-id (MAC).
 *
 * Run:
 *  CLI:
 *    php tools/sync_clients_router_mac.php
 *    php tools/sync_clients_router_mac.php router_id=2
 *    php tools/sync_clients_router_mac.php mode=overwrite
 *    php tools/sync_clients_router_mac.php mode=if_diff
 *    php tools/sync_clients_router_mac.php dry=1
 *
 *  Browser (login required):
 *    /tools/sync_clients_router_mac.php
 *    /tools/sync_clients_router_mac.php?router_id=2
 *    /tools/sync_clients_router_mac.php?mode=overwrite
 *    /tools/sync_clients_router_mac.php?dry=1
 *
 * Modes:
 *   empty_only (default): set only if clients.router_mac IS NULL/empty
 *   if_diff             : set if different from current value
 *   overwrite           : always set
 */

declare(strict_types=1);

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
  require_once __DIR__ . '/../app/require_login.php'; // protect for web
  header('Content-Type: text/plain; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
}

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/routeros_api.class.php';

// ---------- helpers ----------
function norm_mac(string $s): string {
  // Bengali: caller-id থেকে MAC থাকলে AA:BB:CC:DD:EE:FF বানাই; না হলে ''
  $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $s));
  if (strlen($hex) < 12) return '';
  $hex = substr($hex, 0, 12);
  return implode(':', str_split($hex, 2));
}
function println(string $s=''){ echo $s.PHP_EOL; }






// ---------- inputs ----------
$routerId = '';
$mode     = 'empty_only'; // empty_only | if_diff | overwrite
$dryRun   = false;
$debug    = false;

if ($isCli) {
  // Safe parse CLI args: router_id=, mode=, dry=1, --dry-run, debug=1
  foreach (($argv ?? []) as $arg) {
    if (preg_match('/^router_id=(\d+)/', $arg, $m)) { $routerId = $m[1]; continue; }
    if (preg_match('/^mode=(empty_only|if_diff|overwrite)$/', $arg, $m)) { $mode = $m[1]; continue; }
    if ($arg === 'dry=1' || $arg === '--dry-run') { $dryRun = true; continue; }
    if ($arg === 'debug=1' || $arg === '--debug') { $debug = true; continue; }
  }
} else {
  // Web: never access $_GET[...] directly without isset checks
  $routerId = isset($_GET['router_id']) ? (string)$_GET['router_id'] : (isset($_POST['router_id']) ? (string)$_POST['router_id'] : '');
  $modeIn   = isset($_GET['mode']) ? (string)$_GET['mode'] : (isset($_POST['mode']) ? (string)$_POST['mode'] : '');
  if (in_array($modeIn, ['empty_only','if_diff','overwrite'], true)) $mode = $modeIn;

  // dry/debug flags: present ⇒ true
  $dryRun = (isset($_GET['dry']) || isset($_POST['dry'])) ? true : false;
  $debug  = (isset($_GET['debug']) || isset($_POST['debug'])) ? true : false;
}

println("Sync clients.router_mac from PPPoE active (mode={$mode}, dry=".($dryRun?'yes':'no').")");


// ---------- db ----------
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// load routers
$params = [];
$sqlRouters = "SELECT id, name, ip, username, password, api_port FROM routers WHERE 1";
if ($routerId !== '') { $sqlRouters .= " AND id=?"; $params[] = $routerId; }
$st = $pdo->prepare($sqlRouters);
$st->execute($params);
$routers = $st->fetchAll(PDO::FETCH_ASSOC);

if (!$routers) { println("No routers found."); exit; }

// prefetch clients per router → pppoe_id => [id, router_mac]
$clientIdx = []; // [router_id][pppoe_id] => ['id'=>..,'router_mac'=>..]
$placeholders = implode(',', array_fill(0, count($routers), '?'));
$ridList = array_map(fn($r) => $r['id'], $routers);

$sqlC = "SELECT id, router_id, pppoe_id, router_mac FROM clients WHERE router_id IN ($placeholders)";
$stc = $pdo->prepare($sqlC);
$stc->execute($ridList);
while ($c = $stc->fetch(PDO::FETCH_ASSOC)) {
  $rid = (string)$c['router_id'];
  $pp  = trim((string)$c['pppoe_id']);
  if ($pp === '') continue;
  if (!isset($clientIdx[$rid])) $clientIdx[$rid] = [];
  $clientIdx[$rid][$pp] = ['id'=>$c['id'], 'router_mac'=>trim((string)$c['router_mac'] ?? '')];
}

// prepared updates
$updEmptyOnly = $pdo->prepare("
  UPDATE clients
     SET router_mac = :mac, updated_at = NOW()
   WHERE id = :id
     AND (router_mac IS NULL OR router_mac = '' )
");
$updIfDiff = $pdo->prepare("
  UPDATE clients
     SET router_mac = :mac, updated_at = NOW()
   WHERE id = :id
     AND (router_mac IS NULL OR router_mac = '' OR router_mac <> :mac )
");
$updOverwrite = $pdo->prepare("
  UPDATE clients
     SET router_mac = :mac, updated_at = NOW()
   WHERE id = :id
");

$totalRouters  = 0;
$totalSessions = 0;
$totalMatches  = 0;
$totalUpdates  = 0;
$totalSkipped  = 0;
$errors        = [];

foreach ($routers as $r) {
  $totalRouters++;
  $rid = (string)$r['id'];
  println("Router #{$r['id']} {$r['name']} ({$r['ip']}): connecting...");

  $API = new RouterosAPI();
  $API->port = intval($r['api_port'] ?: 8728);
  $API->timeout = 5;

  if (!$API->connect($r['ip'], $r['username'], $r['password'])) {
    $errors[] = "Router #{$r['id']} connect failed";
    println("  ERROR: connect failed");
    continue;
  }

  // fetch ppp active
  $API->write('/ppp/active/print', false);
  $API->write('=proplist=name,caller-id,address');
  $resp = $API->read(false);
  $API->disconnect();

  if (!is_array($resp)) { println("  WARN: no active list."); continue; }

  $idx = $clientIdx[$rid] ?? [];

  // optional: transaction per router
  if (!$dryRun) $pdo->beginTransaction();

  $updCountThisRouter = 0;
  $sessThisRouter     = 0;
  foreach ($resp as $row) {
    $pp = trim((string)($row['name'] ?? ''));
    if ($pp === '') continue;
    $sessThisRouter++;

    $cid = trim((string)($row['caller-id'] ?? ''));
    $mac = norm_mac($cid);
    if ($mac === '') { $totalSkipped++; continue; } // caller-id not a MAC

    $match = $idx[$pp] ?? null;
    if (!$match) { $totalSkipped++; continue; }     // PPPoE not in clients

    $totalMatches++;

    if ($dryRun) {
      println("  [DRY] #{$match['id']} {$pp} => {$mac}");
      continue;
    }

    // choose update strategy
    $ok = 0;
    if ($mode === 'overwrite') {
      $ok = $updOverwrite->execute([':mac'=>$mac, ':id'=>$match['id']]) ? 1 : 0;
    } elseif ($mode === 'if_diff') {
      $ok = $updIfDiff->execute([':mac'=>$mac, ':id'=>$match['id']]) ? 1 : 0;
    } else { // empty_only
      $ok = $updEmptyOnly->execute([':mac'=>$mac, ':id'=>$match['id']]) ? 1 : 0;
    }

    // rowCount for MySQL may be 0 if value is same or condition not met
    if (($mode === 'overwrite' && $updOverwrite->rowCount() > 0) ||
        ($mode === 'if_diff'   && $updIfDiff->rowCount() > 0) ||
        ($mode === 'empty_only'&& $updEmptyOnly->rowCount() > 0)) {
      $totalUpdates++;
      $updCountThisRouter++;
    }
  }

  if (!$dryRun) $pdo->commit();
  $totalSessions += $sessThisRouter;
  println("  Sessions: {$sessThisRouter} | Updated: ".($dryRun?'DRY':$updCountThisRouter));
}

println("----");
println("Routers processed : {$totalRouters}");
println("Live sessions read: {$totalSessions}");
println("Matched clients   : {$totalMatches}");
println("DB updates        : ".($dryRun?'DRY-RUN':$totalUpdates));
if ($totalSkipped) println("Skipped (no MAC/unknown PPPoE): {$totalSkipped}");
if ($errors) {
  println("Errors:");
  foreach ($errors as $e) println("  - ".$e);
}
