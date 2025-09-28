<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/routeros_api.class.php';

// রাউটার ডেটা আনার ফাংশন
function get_router($id) {
    $stmt = db()->prepare("SELECT * FROM routers WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// রাউটার কানেকশন ফাংশন
function router_connect($ip, $user, $pass, $port = 8728) {
    $API = new RouterosAPI();
    $API->debug = false;
    if ($API->connect($ip, $user, $pass, $port)) {
        return $API;
    }
    return false;
}

// ইনপুট চেক
$action = $_GET['action'] ?? '';
$id     = intval($_GET['id'] ?? 0);

if (!$id || !in_array($action, ['enable', 'disable'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
    exit;
}

// ক্লায়েন্ট তথ্য
$stmt = db()->prepare("SELECT c.id, c.pppoe_id, c.router_id 
                       FROM clients c 
                       WHERE c.id = ? AND c.is_deleted = 0");
$stmt->execute([$id]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$client) {
    echo json_encode(['status' => 'error', 'message' => 'Client not found']);
    exit;
}

// রাউটার ইনফো
$router = get_router($client['router_id']);
if (!$router) {
    echo json_encode(['status' => 'error', 'message' => 'Router not found']);
    exit;
}

// রাউটার কানেক্ট
$API = router_connect($router['ip'], $router['username'], $router['password'], $router['port']);
if (!$API) {
    echo json_encode(['status' => 'error', 'message' => 'Router connection failed']);
    exit;
}

// PPP Secret ID খুঁজে বের করা
$secret = $API->comm("/ppp/secret/print", [
    ".proplist" => ".id",
    "?name" => $client['pppoe_id']
]);
if (!isset($secret[0]['.id'])) {
    echo json_encode(['status' => 'error', 'message' => 'PPPoE user not found']);
    $API->disconnect();
    exit;
}

$pppoe_id = $secret[0]['.id'];

// Enable/Disable
if ($action == 'disable') {
    $API->comm("/ppp/secret/set", [
        ".id" => $pppoe_id,
        "disabled" => "yes"
    ]);
    db()->prepare("UPDATE clients SET status = 'inactive' WHERE id = ?")->execute([$client['id']]);
    $msg = 'Client disabled successfully';
} else {
    $API->comm("/ppp/secret/set", [
        ".id" => $pppoe_id,
        "disabled" => "no"
    ]);
    db()->prepare("UPDATE clients SET status = 'active' WHERE id = ?")->execute([$client['id']]);
    $msg = 'Client enabled successfully';
}

$API->disconnect();
echo json_encode(['status' => 'success', 'message' => $msg]);
