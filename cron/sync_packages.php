<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/routeros_api.class.php';

// MikroTik কানেকশন ডিটেইলস (ডাটাবেজ থেকেও নিতে পারেন)
$router_ip   = "103.175.242.4";
$router_user = "swapon";
$router_pass = "s9124";
$router_port = 7999; // API Port

$API = new RouterosAPI();
$API->debug = false;

if ($API->connect($router_ip, $router_user, $router_pass, $router_port)) {

    echo "✅ Connected to MikroTik\n";

    // প্রোফাইল লিস্ট আনা
    $profiles = $API->comm("/ppp/profile/print");

    foreach ($profiles as $profile) {
        $name = $profile['name'] ?? '';
        $rate = $profile['rate-limit'] ?? '';
        $price = 0.00; // MikroTik প্রোফাইলে দাম থাকে না, ম্যানুয়ালি সেট করতে হবে
        $validity = 30; // ডিফল্ট 30 দিন

        if ($name == '') continue;

        // ডাটাবেজে আগে আছে কিনা চেক
        $stmt = db()->prepare("SELECT id FROM packages WHERE name = ?");
        $stmt->execute([$name]);
        $exists = $stmt->fetch();

        if ($exists) {
            // আপডেট
            $update = db()->prepare("UPDATE packages SET speed=?, validity=? WHERE id=?");
            $update->execute([$rate, $validity, $exists['id']]);
            echo "🔄 Updated package: $name ($rate)\n";
        } else {
            // নতুন ইনসার্ট
            $insert = db()->prepare("INSERT INTO packages (name, speed, price, validity) VALUES (?, ?, ?, ?)");
            $insert->execute([$name, $rate, $price, $validity]);
            echo "➕ Added package: $name ($rate)\n";
        }
    }

    $API->disconnect();
    echo "✅ Sync complete!\n";

} else {
    echo "❌ Failed to connect to MikroTik API\n";
}
