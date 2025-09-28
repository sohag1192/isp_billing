<?php
/**
 * /partials/client_last_logout_update.php
 * পাতাটি খোলার সময় PPP Secret থেকে last-logged-out তুলে clients.last_logout_at আপডেট করে।
 * Expect: $client (array), $client_id, db(), RouterosAPI class available.
 */
if (!isset($client) || !isset($client_id)) { return; }

require_once __DIR__ . '/../app/routeros_api.class.php';
require_once __DIR__ . '/../app/db.php';

function _parse_ros_time_to_mysql($s){
    $t = strtotime($s);
    return $t ? date('Y-m-d H:i:s', $t) : null;
}

try {
    $pppoe_name = trim($client['pppoe_id'] ?? '');
    // আপনার প্রোজেক্টে client join-এ router ফিল্ড যেভাবে আনা হয় সেভাবে নিচের ৪টা ঠিক করুন
    $router_ip  = trim($client['router_ip']   ?? $client['ip']       ?? '');
    $username   = trim($client['username']    ?? '');
    $password   = (string)($client['password'] ?? '');
    $api_port   = intval($client['api_port']   ?? 8728);

    if ($pppoe_name !== '' && $router_ip !== '' && $username !== '') {
        $API = new RouterosAPI();
        $API->debug = false;

        if (@$API->connect($router_ip, $username, $password, $api_port)) {
            // ছোট রেসপন্সের জন্য proplist ব্যবহার
            $API->write('/ppp/secret/print', false);
            $API->write('=name='.$pppoe_name, false);
            $API->write('=.proplist=name,last-logged-out', true);
            $resp = $API->read();
            $API->disconnect();

            if (is_array($resp) && !empty($resp[0]['name'])) {
                $raw = trim($resp[0]['last-logged-out'] ?? '');
                if ($raw !== '' && strtolower($raw) !== 'never') {
                    $new = _parse_ros_time_to_mysql($raw); // ex: aug/22/2025 12:34:56 -> 2025-08-22 12:34:56
                    if ($new) {
                        // পুরোনোর সাথে তুলনা করে বড় হলে আপডেট
                        $st = db()->prepare("SELECT last_logout_at FROM clients WHERE id=?");
                        $st->execute([$client_id]);
                        $old = $st->fetchColumn();

                        if (!$old || strtotime($new) > strtotime((string)$old)) {
                            $up = db()->prepare("UPDATE clients SET last_logout_at=?, updated_at=NOW() WHERE id=?");
                            $up->execute([$new, $client_id]);
                            $client['last_logout_at'] = $new; // লোকাল অবজেক্ট রিফ্রেশ
                        }
                    } else {
                        error_log("last-logged-out parse failed: '$raw' for $pppoe_name");
                    }
                }
            } else {
                error_log("ppp secret not found for $pppoe_name on $router_ip");
            }
        } else {
            error_log("RouterOS connect failed {$router_ip}:{$api_port}");
        }
    }
} catch (Throwable $e) {
    error_log("client_last_logout_update error: ".$e->getMessage());
}
