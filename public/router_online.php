<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/routeros_api.class.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo '<span class="text-danger">Invalid</span>';
    exit;
}

$id = intval($_GET['id']);

// রাউটার ডেটা লোড
$stmt = db()->prepare("SELECT * FROM routers WHERE id = ? AND type = 'mikrotik' AND status = 1 LIMIT 1");
$stmt->execute([$id]);
$router = $stmt->fetch();

if (!$router) {
    echo '<span class="text-danger">Not Found</span>';
    exit;
}

$API = new RouterosAPI();
$API->debug = false;

if ($API->connect($router['ip'], $router['username'], $router['password'], $router['api_port'])) {
    $activeUsers = $API->comm("/ppp/active/print");
    $online_count = count($activeUsers);
    echo '<span class="badge bg-success">'.$online_count.'</span>';
    $API->disconnect();
} else {
    echo '<span class="badge bg-danger">Offline</span>';
}
?>