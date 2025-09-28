<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/routeros_api.class.php';

function getMikrotikConnection($router_id) {
    $stmt = db()->prepare("SELECT * FROM routers WHERE id = ? AND status = 1 LIMIT 1");
    $stmt->execute([$router_id]);
    $router = $stmt->fetch();

    if (!$router) return false;

    $API = new RouterosAPI();
    $API->debug = false;
    if ($API->connect($router['ip_address'], $router['username'], $router['password'], $router['api_port'])) {
        return $API;
    }
    return false;
}

function getPPPoEStats($router_id) {
    $API = getMikrotikConnection($router_id);
    if (!$API) return ['online' => 0, 'offline' => 0, 'disabled' => 0];

    // অনলাইন ইউজার
    $activeUsers = $API->comm("/ppp/active/print");
    $online_count = count($activeUsers);

    // সব ইউজারের লিস্ট (প্রোফাইলসহ)
    $secrets = $API->comm("/ppp/secret/print");
    $disabled_count = 0;
    foreach ($secrets as $user) {
        if (isset($user['disabled']) && $user['disabled'] === 'true') {
            $disabled_count++;
        }
    }

    $offline_count = count($secrets) - $online_count;

    $API->disconnect();
    return [
        'online' => $online_count,
        'offline' => $offline_count,
        'disabled' => $disabled_count
    ];
}
