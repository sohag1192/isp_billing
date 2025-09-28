<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/mikrotik.php';

header('Content-Type: application/json');

$client_id = intval($_GET['id'] ?? 0);
if (!$client_id) {
    echo json_encode(['error' => 'Invalid client']);
    exit;
}

// Client info
$stmt = db()->prepare("SELECT c.pppoe_id, c.router_id 
                       FROM clients c WHERE c.id = ?");
$stmt->execute([$client_id]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$client) {
    echo json_encode(['error' => 'Client not found']);
    exit;
}

// Router info
$router = get_router($client['router_id']);
$API = mt_connect($router['ip'], $router['username'], $router['password']);

$ip = '';
$last_seen = 'N/A';
if ($API) {
    // Get IP from PPP active sessions
    $API->write('/ppp/active/print', false);
    $API->write('?name=' . $client['pppoe_id']);
    $res = $API->read();
    if (!empty($res[0]['address'])) {
        $ip = $res[0]['address'];
    }

    // If not active, get last-seen from secrets
    if (empty($ip)) {
        $API->write('/ppp/secret/print', false);
        $API->write('?name=' . $client['pppoe_id']);
        $sec = $API->read();
        if (!empty($sec[0]['last-logged-out'])) {
            $last_seen = $sec[0]['last-logged-out'];
        }
    }
    $API->disconnect();
}

// Ping test
$ping_ok = false;
if ($ip) {
    $ping_result = exec("ping -c 1 -W 1 " . escapeshellarg($ip) . " 2>/dev/null", $out, $status);
    $ping_ok = ($status === 0);
}

// Default traffic values
$rx = 0;
$tx = 0;

// If IP found, try to get interface traffic
if ($API = mt_connect($router['ip'], $router['username'], $router['password'])) {
    $API->write('/interface/monitor-traffic', false);
    $API->write('=interface=' . $client['pppoe_id'], false);
    $API->write('=once=');
    $traf = $API->read();
    if (!empty($traf[0]['rx-bits-per-second'])) $rx = intval($traf[0]['rx-bits-per-second']);
    if (!empty($traf[0]['tx-bits-per-second'])) $tx = intval($traf[0]['tx-bits-per-second']);
    $API->disconnect();
}

echo json_encode([
    'ip' => $ip ?: 'Not Connected',
    'ping' => $ping_ok ? 'online' : 'offline',
    'last_seen' => $last_seen,
    'rx' => $rx,
    'tx' => $tx
]);
