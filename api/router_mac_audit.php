<?php
/**
 * /api/router_mac_audit.php
 * PPPoE live caller-id (MAC) ↔ clients.router_mac cross-check (JSON)
 *
 * Params (GET/POST):
 *   router_id     optional  - specific router only
 *   show          optional  - all|mismatch|missing|unknown  (default all)
 *   include_left  optional  - 0|1  (default 0 = exclude left clients)
 *
 * Output: JSON { ok, generated_at, router_count, session_count, rows:[...], summary:{...} }
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../app/require_login.php';       // protect API
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/routeros_api.class.php';

function jexit(array $a): void { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

// Bengali: MAC কে AA:BB:CC:DD:EE:FF ফরম্যাটে আনি (না পারলে খালি স্ট্রিং)
function norm_mac(string $mac): string {
    $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac));
    if (strlen($hex) < 12) return '';
    $hex = substr($hex, 0, 12);
    return implode(':', str_split($hex, 2));
}

$routerId     = trim($_GET['router_id'] ?? $_POST['router_id'] ?? '');
$show         = strtolower(trim($_GET['show'] ?? $_POST['show'] ?? 'all'));      // all|mismatch|missing|unknown
$include_left = !empty($_GET['include_left'] ?? $_POST['include_left']) ? 1 : 0;

// 1) Routers
$params = [];
$sqlRouters = "SELECT id, name, ip, username, password, api_port FROM routers WHERE 1";
if ($routerId !== '') { $sqlRouters .= " AND id=?"; $params[] = $routerId; }
$st = db()->prepare($sqlRouters);
$st->execute($params);
$routers = $st->fetchAll(PDO::FETCH_ASSOC);

if (!$routers) {
    jexit(['ok'=>1, 'generated_at'=>date('c'), 'router_count'=>0, 'session_count'=>0, 'rows'=>[], 'summary'=>[]]);
}

// 2) Clients index per router
$clientIdxByRouter = [];       // router_id => [pppoe_id => clientRow]
$clientParams = [];
$sqlC = "SELECT id, pppoe_id, name, router_id, router_mac, is_left, status
         FROM clients WHERE router_id IN (" . implode(',', array_fill(0, count($routers), '?')) . ")";
foreach ($routers as $r) $clientParams[] = $r['id'];
if (!$include_left) { $sqlC .= " AND (is_left = 0 OR is_left IS NULL)"; }
$stC = db()->prepare($sqlC);
$stC->execute($clientParams);

while ($c = $stC->fetch(PDO::FETCH_ASSOC)) {
    $rid = (string)$c['router_id'];
    $pp  = trim((string)$c['pppoe_id']);
    if (!isset($clientIdxByRouter[$rid])) $clientIdxByRouter[$rid] = [];
    $clientIdxByRouter[$rid][$pp] = $c;
}

// 3) Read PPP active & cross-check
$rows = [];
$totalSessions = 0;

foreach ($routers as $router) {
    $rid = (string)$router['id'];
    $API = new RouterosAPI();
    $API->port = intval($router['api_port'] ?: 8728);
    $API->timeout = 5;

    $live = [];
    if ($API->connect($router['ip'], $router['username'], $router['password'])) {
        // Bengali: PPPoE active list, চাই শুধু প্রয়োজনীয় ফিল্ড
        $API->write('/ppp/active/print', false);
        $API->write('=proplist=name,caller-id,address,uptime,encoding,service');
        $resp = $API->read(false);
        $API->disconnect();

        if (is_array($resp)) {
            foreach ($resp as $row) {
                $n = trim((string)($row['name'] ?? ''));       // PPPoE username
                if ($n === '') continue;
                $cid = trim((string)($row['caller-id'] ?? ''));
                $addr= trim((string)($row['address'] ?? ''));
                $live[$n] = [
                    'pppoe_id'  => $n,
                    'live_mac'  => norm_mac($cid),              // caller-id থেকে MAC normalize
                    'caller_id' => $cid,
                    'address'   => $addr,
                    'uptime'    => trim((string)($row['uptime'] ?? '')),
                    'encoding'  => trim((string)($row['encoding'] ?? '')),
                    'service'   => trim((string)($row['service'] ?? '')),
                ];
            }
        }
    } else {
        // রাউটার অনুপলব্ধ
        $rows[] = [
            'router_id'   => $router['id'],
            'router_name' => $router['name'],
            'pppoe_id'    => null,
            'client_id'   => null,
            'client_name' => null,
            'expected_mac'=> null,
            'live_mac'    => null,
            'caller_id'   => null,
            'address'     => null,
            'status'      => 'ROUTER_UNREACHABLE',
            'note'        => 'Could not connect to RouterOS API',
        ];
        continue;
    }

    $totalSessions += count($live);
    $idx = $clientIdxByRouter[$rid] ?? [];

    // 3a) Live session → client match + compare MAC
    foreach ($live as $pppoe_id => $L) {
        $client = $idx[$pppoe_id] ?? null;
        if (!$client) {
            $rows[] = [
                'router_id'   => $router['id'],
                'router_name' => $router['name'],
                'pppoe_id'    => $pppoe_id,
                'client_id'   => null,
                'client_name' => null,
                'expected_mac'=> null,
                'live_mac'    => $L['live_mac'] ?: null,
                'caller_id'   => $L['caller_id'] ?: null,
                'address'     => $L['address'] ?: null,
                'status'      => 'UNKNOWN_SESSION',
                'note'        => 'Live PPPoE not found in clients table',
            ];
            continue;
        }

        $expected = norm_mac((string)($client['router_mac'] ?? ''));
        $liveMac  = $L['live_mac'];
        $stTxt    = 'OK';
        $note     = '';

        if ($expected === '' && $liveMac === '') {
            $stTxt = 'BOTH_MAC_MISSING';
            $note  = 'No expected router_mac and no live caller-id MAC';
        } elseif ($expected === '' && $liveMac !== '') {
            $stTxt = 'MISSING_EXPECTED';
            $note  = 'Client router_mac empty; live has MAC';
        } elseif ($expected !== '' && $liveMac === '') {
            $stTxt = 'MISSING_LIVE';
            $note  = 'Live caller-id not a MAC';
        } elseif ($expected !== $liveMac) {
            $stTxt = 'MISMATCH';
            $note  = 'Expected vs live differ';
        }

        $rows[] = [
            'router_id'   => $router['id'],
            'router_name' => $router['name'],
            'pppoe_id'    => $pppoe_id,
            'client_id'   => $client['id'],
            'client_name' => $client['name'],
            'expected_mac'=> $expected ?: null,
            'live_mac'    => $liveMac ?: null,
            'caller_id'   => $L['caller_id'] ?: null,
            'address'     => $L['address'] ?: null,
            'status'      => $stTxt,
            'note'        => $note,
        ];
    }
}

// 4) Filter by "show"
if ($show !== 'all') {
    $rows = array_values(array_filter($rows, function($r) use ($show) {
        $s = $r['status'] ?? 'OK';
        if ($show === 'mismatch') return $s === 'MISMATCH';
        if ($show === 'missing')  return in_array($s, ['MISSING_EXPECTED','MISSING_LIVE','BOTH_MAC_MISSING'], true);
        if ($show === 'unknown')  return $s === 'UNKNOWN_SESSION';
        return true;
    }));
}

// 5) Summary
$summary = [
    'OK'                 => 0,
    'MISMATCH'           => 0,
    'MISSING_EXPECTED'   => 0,
    'MISSING_LIVE'       => 0,
    'BOTH_MAC_MISSING'   => 0,
    'UNKNOWN_SESSION'    => 0,
    'ROUTER_UNREACHABLE' => 0,
];
foreach ($rows as $r) {
    $s = $r['status'] ?? 'OK';
    if (isset($summary[$s])) $summary[$s]++;
}

jexit([
    'ok'            => 1,
    'generated_at'  => date('c'),
    'router_count'  => count($routers),
    'session_count' => $totalSessions,
    'rows'          => $rows,
    'summary'       => $summary,
]);
