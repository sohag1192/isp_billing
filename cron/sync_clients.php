<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/routeros_api.class.php';

$API = new RouterosAPI();
$API->debug = false;

// সক্রিয় MikroTik রাউটার আনা
$routers = db()->query("SELECT id, name, ip, username, password, api_port 
                        FROM routers 
                        WHERE type='mikrotik' AND status=1")->fetchAll();

if (!$routers) {
    die("❌ No active MikroTik routers found in database.\n");
}

// সর্বশেষ client_code নম্বর বের করা
function get_next_client_code() {
    $stmt_last = db()->query("SELECT client_code FROM clients ORDER BY id DESC LIMIT 1");
    $last_client = $stmt_last->fetch();
    if ($last_client && preg_match('/CL(\d+)/', $last_client['client_code'], $matches)) {
        $next_number = intval($matches[1]) + 1;
    } else {
        $next_number = 1;
    }
    return 'CL' . str_pad($next_number, 4, '0', STR_PAD_LEFT);
}

foreach ($routers as $router) {
    echo "=============================\n";
    echo "📡 Connecting to Router: {$router['name']} ({$router['ip']})\n";

    if ($API->connect($router['ip'], $router['username'], $router['password'], $router['api_port'])) {

        // Active list আনা (অনলাইন ইউজারদের জন্য)
        $active_users = $API->comm("/ppp/active/print");
        $online_ids = [];
        foreach ($active_users as $active) {
            if (!empty($active['name'])) {
                $online_ids[] = $active['name'];
            }
        }

        // PPP Secrets আনা
        $secrets = $API->comm("/ppp/secret/print");

        foreach ($secrets as $secret) {
            $pppoe_id = $secret['name'] ?? '';
            $password = $secret['password'] ?? '';
            $profile  = $secret['profile'] ?? '';
            $disabled = $secret['disabled'] ?? 'false';
            $status   = ($disabled === 'true') ? 'inactive' : 'active';

            if ($pppoe_id == '') continue;

            // প্যাকেজ ম্যাচ
            $stmt_pkg = db()->prepare("SELECT id FROM packages WHERE name = ? AND router_id = ?");
            $stmt_pkg->execute([$profile, $router['id']]);
            $pkg = $stmt_pkg->fetch();
            $package_id = $pkg['id'] ?? 1; // fallback id=1

            // অনলাইন স্ট্যাটাস চেক
            $is_online = in_array($pppoe_id, $online_ids) ? 1 : 0;

            // আগে আছে কিনা চেক
            $stmt_chk = db()->prepare("SELECT id FROM clients WHERE pppoe_id = ? AND router_id = ?");
            $stmt_chk->execute([$pppoe_id, $router['id']]);
            $exists = $stmt_chk->fetch();

            if ($exists) {
                // আপডেট
                $update = db()->prepare("UPDATE clients 
                                         SET password=?,pppoe_pass = ?, package_id=?, status=?, is_online=? 
                                         WHERE id=?");
                $update->execute([$password,$password, $package_id, $status, $is_online, $exists['id']]);
                echo "🔄 Updated client: $pppoe_id (Online: $is_online)\n<br>".PHP_EOL;
            } else {
                // নতুন client_code ইউনিক নিশ্চিত করা
                do {
                    $new_client_code = get_next_client_code();
                    $stmt_check_id = db()->prepare("SELECT id FROM clients WHERE client_code = ?");
                    $stmt_check_id->execute([$new_client_code]);
                    $id_exists = $stmt_check_id->fetch();
                } while ($id_exists);

                // Default name = PPPoE ID
                $client_name = $pppoe_id;

                // ইনসার্ট
                $insert = db()->prepare("INSERT INTO clients 
                    (client_code, router_id, name, mobile, package_id, pppoe_id, password, status, is_online, join_date) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $insert->execute([$new_client_code, $router['id'], $client_name, null, $package_id, $pppoe_id, $password, $status, $is_online]);

                echo "➕ Added new client: $pppoe_id (Online: $is_online)\n".PHP_EOL;
            }
        }

        $API->disconnect();
        echo "✅ Sync complete for {$router['name']}!\n<br>".PHP_EOL;
    } else {
        echo "❌ Failed to connect to {$router['name']} ({$router['ip']})\n";
    }
}
