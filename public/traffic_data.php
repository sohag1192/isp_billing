<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/routeros_api.class.php';

$client_id = intval($_GET['id'] ?? 0);
$iface = $_GET['iface'] ?? '';

if (!$client_id || !$iface) {
    echo json_encode(['error' => 'Invalid']);
    exit;
}

$stmt = db()->prepare("SELECT r.ip AS router_ip, r.username, r.password, r.api_port
                       FROM clients c
                       LEFT JOIN routers r ON c.router_id = r.id
                       WHERE c.id = ?");
$stmt->execute([$client_id]);
$router = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$router) {
    echo json_encode(['error' => 'Router not found']);
    exit;
}

$API = new RouterosAPI();
$API->debug = false;
$rx = $tx = 0;
$total_rx = $total_tx = 0;
$uptime = 'N/A';

if ($API->connect($router['router_ip'], $router['username'], $router['password'], $router['api_port'])) {
    // Live speed
    $traf = $API->comm("/interface/monitor-traffic", [
        "=interface" => $iface,
        "=once" => ""
    ]);
    if (!empty($traf[0]['rx-bits-per-second'])) {
        $rx = round($traf[0]['rx-bits-per-second'] / 1024 / 1024, 2); // Mbps
    }
    if (!empty($traf[0]['tx-bits-per-second'])) {
        $tx = round($traf[0]['tx-bits-per-second'] / 1024 / 1024, 2); // Mbps
    }

    // Total data
    $stats = $API->comm("/interface/print", ["?name" => $iface]);
    if (!empty($stats[0]['rx-byte'])) {
        $total_rx = round($stats[0]['rx-byte'] / 1024 / 1024 / 1024, 2); // GB
    }
    if (!empty($stats[0]['tx-byte'])) {
        $total_tx = round($stats[0]['tx-byte'] / 1024 / 1024 / 1024, 2); // GB
    }

    // Uptime
    if (!empty($stats[0]['uptime'])) {
        $uptime = $stats[0]['uptime'];
    }

    $API->disconnect();
}

echo json_encode([
    'rx' => $rx,
    'tx' => $tx,
    'total_rx' => $total_rx,
    'total_tx' => $total_tx,
    'uptime' => $uptime
]);
