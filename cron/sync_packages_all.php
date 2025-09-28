<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/routeros_api.class.php';

$API = new RouterosAPI();
$API->debug = false;

// সব রাউটার আনা
$routers = db()->query("SELECT id, name, ip, username, password, api_port FROM routers")->fetchAll();

if (!$routers) {
    die("❌ No routers found in database.\n");
}

foreach ($routers as $router) {
    echo "=============================\n";
    echo "📡 Connecting to Router: {$router['name']} ({$router['ip']})\n";

    if ($API->connect($router['ip'], $router['username'], $router['password'], $router['api_port'])) {

        $profiles = $API->comm("/ppp/profile/print");

        foreach ($profiles as $profile) {
            $name = $profile['name'] ?? '';
            $rate = $profile['rate-limit'] ?? '';
            $price = 0.00; // ম্যানুয়ালি পরে সেট করতে হবে
            $validity = 30;

            if ($name == '') continue;

            // ডাটাবেজে আগে আছে কিনা চেক
            $stmt = db()->prepare("SELECT id FROM packages WHERE name = ? AND router_id = ?");
            $stmt->execute([$name, $router['id']]);
            $exists = $stmt->fetch();

            if ($exists) {
                // আপডেট
                $update = db()->prepare("UPDATE packages SET speed=?, validity=? WHERE id=?");
                $update->execute([$rate, $validity, $exists['id']]);
                echo "🔄 Updated package: $name ($rate)\n";
            } else {
                // ইনসার্ট
                $insert = db()->prepare("INSERT INTO packages (router_id, name, speed, price, validity) VALUES (?, ?, ?, ?, ?)");
                $insert->execute([$router['id'], $name, $rate, $price, $validity]);
                echo "➕ Added package: $name ($rate)\n";
            }
        }

        $API->disconnect();
        echo "✅ Sync complete for {$router['name']}!\n";
    } else {
        echo "❌ Failed to connect to {$router['name']} ({$router['ip']})\n";
    }
}
