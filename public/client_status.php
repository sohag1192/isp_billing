<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/routeros_api.class.php';

header('Content-Type: application/json');

$client_id = intval($_GET['id'] ?? 0);
if (!$client_id) {
    echo json_encode(['error' => 'Invalid client ID']);
    exit;
}

// ক্লায়েন্ট তথ্য
$stmt = db()->prepare("SELECT c.pppoe_id, c.router_id 
                       FROM clients c 
                       WHERE c.id = ?");
$stmt->execute([$client_id]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$client) {
    echo json_encode(['error' => 'Client not found']);
    exit;
}

// রাউটার তথ্য
$stmt = db()->prepare("SELECT * FROM routers 
                       WHERE id = ? AND type = 'mikrotik' AND status = 1 LIMIT 1");
$stmt->execute([$client['router_id']]);
$router = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$router) {
    echo json_encode(['error' => 'Router not found']);
    exit;
}

$pppoe_name = trim($client['pppoe_id']);

$API = new RouterosAPI();
$API->debug = false;

$ip = '';
$interface_name = '';
$last_seen = 'N/A';
$is_online = false;
$rx = 0;
$tx = 0;

if ($API->connect($router['ip'], $router['username'], $router['password'], $router['api_port'])) {

    // PPP Active থেকে IP ও Interface
    $active = $API->comm("/ppp/active/print", ["?name" => $pppoe_name]);
    if (!empty($active)) {
        $ip = $active[0]['address'] ?? '';
        $interface_name = $active[0]['interface'] ?? '';
        $is_online = true;
    } else {
        // অফলাইন হলে Last Seen
        $secret = $API->comm("/ppp/secret/print", ["?name" => $pppoe_name]);
        if (!empty($secret[0]['last-logged-out'])) {
            $last_seen = $secret[0]['last-logged-out'];
        }
    }

    // অনলাইনে থাকলে ট্রাফিক নাও
    if ($is_online && $interface_name) {
        $traf = $API->comm("/interface/monitor-traffic", [
            "=interface" => $interface_name,
            "=once" => ""
        ]);
        if (!empty($traf[0]['rx-bits-per-second'])) $rx = intval($traf[0]['rx-bits-per-second']);
        if (!empty($traf[0]['tx-bits-per-second'])) $tx = intval($traf[0]['tx-bits-per-second']);
    }

    $API->disconnect();
}

// পিং চেক
$ping_ok = false;
if ($ip) {
    $ping_result = exec("ping -c 1 -W 1 " . escapeshellarg($ip) . " 2>/dev/null", $out, $status);
    $ping_ok = ($status === 0);
}

// আউটপুট JSON
echo json_encode([
    'ip' => $ip ?: 'Not Connected',
    'online' => $is_online,
    'ping' => $ping_ok ? 'online' : 'offline',
    'last_seen' => $last_seen,
    'rx' => $rx,
    'tx' => $tx
]);
