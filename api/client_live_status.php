<?php
/**
 * /api/client_live_status.php
 * Live PPPoE status + instant TX/RX (auto unit: Kbps/Mbps) + total usage (GB)
 * Requires: app/require_login.php, app/db.php, app/routeros_api.class.php
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/routeros_api.class.php';

function jexit(array $a): void {
	echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; 
	}


function normalize_mac_from_string($s){
    // ইনপুট থেকে হেক্সগুলো নিয়ে 12 ক্যারেক্টার করলে নরমালাইজ হবে
    $hex = strtoupper(preg_replace('/[^0-9A-F]/', '', (string)$s));
    if (strlen($hex) < 12) return null;
    $hex = substr($hex, 0, 12);
    return implode(':', str_split($hex, 2));   // XX:XX:XX:XX:XX:XX
}
function mac_prefix6($mac){  // AABBCC
    $hex = strtoupper(preg_replace('/[^0-9A-F]/', '', (string)$mac));
    return (strlen($hex)>=6) ? substr($hex, 0, 6) : null;
}
function vendor_lookup_cached($prefix6){
    $stmt = db()->prepare("SELECT vendor FROM mac_vendors WHERE mac_prefix=? LIMIT 1");
    $stmt->execute([$prefix6]);
    $v = $stmt->fetchColumn();
    return $v ?: null;
}
function vendor_cache_save($prefix6, $vendor){
    $stmt = db()->prepare("INSERT INTO mac_vendors(mac_prefix, vendor, updated_at)
                           VALUES(?, ?, NOW())
                           ON DUPLICATE KEY UPDATE vendor=VALUES(vendor), updated_at=NOW()");
    $stmt->execute([$prefix6, $vendor]);
}
function vendor_lookup_online($mac){
    // api.macvendors.com খুব লাইটওয়েট; rate-limit খেয়াল রাখুন
    $url = 'https://api.macvendors.com/' . rawurlencode($mac);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 4,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'ISP-Billing/1.0'
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 200 && $res !== false) {
        // API প্লেইন টেক্সট রিটার্ন করে
        return trim($res);
    }
    return null;
}

/** Get client id from GET/POST/JSON */
$id = 0;
if (isset($_GET['id'])) $id = (int)$_GET['id'];
elseif (isset($_POST['id'])) $id = (int)$_POST['id'];
else {
    $raw = file_get_contents('php://input');
    if ($raw) { $decoded = json_decode($raw, true); if (is_array($decoded) && isset($decoded['id'])) $id = (int)$decoded['id']; }
}
if ($id <= 0) jexit(['status'=>'error','message'=>'Invalid client id','online'=>false]);

try {
    // 1) Load client + router info
    $st = db()->prepare("
        SELECT c.id, c.pppoe_id, c.status, c.router_id, c.expiry_date,
               r.ip AS router_ip, r.username, r.password, r.api_port
        FROM clients c
        LEFT JOIN routers r ON r.id = c.router_id
        WHERE c.id = ? LIMIT 1
    ");
    $st->execute([$id]);
    $c = $st->fetch(PDO::FETCH_ASSOC);

    if (!$c || empty($c['router_ip'])) {
        jexit([
            'status'=>'ok','online'=>false,'ip'=>null,'uptime'=>null,'last_seen'=>null,
            'total_download_gb'=>null,'total_upload_gb'=>null,
            'rx_kbps'=>0,'tx_kbps'=>0,'rx_rate'=>'0 Kbps','tx_rate'=>'0 Kbps',
            'iface'=>null,'note'=>'router missing',
            'caller_id'=>null
        ]);
    }

    $pppName = trim((string)($c['pppoe_id'] ?? ''));
    if ($pppName === '') {
        jexit([
            'status'=>'ok','online'=>false,'ip'=>null,'uptime'=>null,'last_seen'=>null,
            'total_download_gb'=>null,'total_upload_gb'=>null,
            'rx_kbps'=>0,'tx_kbps'=>0,'rx_rate'=>'0 Kbps','tx_rate'=>'0 Kbps',
            'iface'=>null,'note'=>'empty ppp name',
            'caller_id'=>null
        ]);
    }

    // 2) Connect to RouterOS
    $API = new RouterosAPI();
    $API->debug = false;
    $api_port = (int)($c['api_port'] ?? 8728);
    if (!$API->connect($c['router_ip'], $c['username'], $c['password'], $api_port)) {
        jexit([
            'status'=>'ok','online'=>false,'ip'=>null,'uptime'=>null,'last_seen'=>null,
            'total_download_gb'=>null,'total_upload_gb'=>null,
            'rx_kbps'=>0,'tx_kbps'=>0,'rx_rate'=>'0 Kbps','tx_rate'=>'0 Kbps',
            'iface'=>null,'note'=>'api connect failed',
            'caller_id'=>null
        ]);
    }

    $note = [];

    // 3) Active PPP -> online/ip/uptime (+ caller_id)
    $active    = $API->comm('/ppp/active/print', ["?name" => $pppName]);
    $isOnline  = !empty($active);
    $ip        = $isOnline ? ($active[0]['address'] ?? null) : null;
    $uptime    = $isOnline ? ($active[0]['uptime']  ?? null) : null;
    $caller_id = $isOnline ? ($active[0]['caller-id'] ?? null) : null;

    // 4) Resolve dynamic interface (multi-fallback)
    $ifaceName = null;
    if ($isOnline) {
        // (a) direct guess: pppoe-<username>
        $guess = 'pppoe-' . $pppName;
        $row = $API->comm('/interface/print', ['?name' => $guess]);
        if (!empty($row[0]['name'])) { $ifaceName = $row[0]['name']; $note[]='iface:direct'; }
    }
    if ($isOnline && !$ifaceName) {
        // (b) scan all pppoe-in, running + contains username
        $all = $API->comm('/interface/print', ['?type' => 'pppoe-in']);
        if (!empty($all) && is_array($all)) {
            foreach ($all as $r) {
                $n = $r['name'] ?? '';
                $running = ($r['running'] ?? 'false') === 'true';
                if ($running && $n !== '' && stripos($n, $pppName) !== false) { $ifaceName = $n; $note[]='iface:pppoe-in-like'; break; }
            }
        }
    }
    if ($isOnline && !$ifaceName && $ip) {
        // (c) firewall connection mapping by client IP
        $conn = $API->comm('/ip/firewall/connection/print', ['?src-address' => $ip]);
        if (empty($conn)) { $conn = $API->comm('/ip/firewall/connection/print', ['?dst-address' => $ip]); }
        if (!empty($conn[0])) {
            $ii = $conn[0]['in-interface']  ?? null;
            $oi = $conn[0]['out-interface'] ?? null;
            $ifaceName = $ii ?: $oi;
            if ($ifaceName) $note[]='iface:fw-conn-map';
        }
    }

    // 5) Read live rates + totals (with auto-unit formatting)
    $rx_kbps = 0.0; $tx_kbps = 0.0;
    $rx_rate = '0 Kbps'; $tx_rate = '0 Kbps';
    $total_dl_gb = null; $total_ul_gb = null;

    if ($isOnline && $ifaceName) {
        // Correct param: 'interface'
        $mon = $API->comm('/interface/monitor-traffic', [
            'interface' => $ifaceName,
            'once'      => ''
        ]);

        if (!empty($mon[0])) {
            // Strip non-digits; RouterOS may return values with commas
            $rx_bps = (int)preg_replace('/\D+/', '', (string)($mon[0]['rx-bits-per-second'] ?? '0'));
            $tx_bps = (int)preg_replace('/\D+/', '', (string)($mon[0]['tx-bits-per-second'] ?? '0'));

            // Base Kbps
            $rx_kbps = $rx_bps / 1000;
            $tx_kbps = $tx_bps / 1000;

            // Auto unit => Kbps (<1000) / Mbps (>=1000)
            $rx_rate = ($rx_kbps >= 1000)
                ? (round($rx_kbps/1000, 2) . ' Mbps')
                : (round($rx_kbps, 1) . ' Kbps');

            $tx_rate = ($tx_kbps >= 1000)
                ? (round($tx_kbps/1000, 2) . ' Mbps')
                : (round($tx_kbps, 1) . ' Kbps');
        } else {
            $note[]='monitor-empty';
        }

        // Totals: bytes → GB
        $ifaceStats = $API->comm('/interface/print', ['?name' => $ifaceName]);
        if (!empty($ifaceStats[0])) {
            $rx_byte = (float)($ifaceStats[0]['rx-byte'] ?? 0);
            $tx_byte = (float)($ifaceStats[0]['tx-byte'] ?? 0);
            $div = 1024*1024*1024; // GiB
            $total_dl_gb = round($rx_byte / $div, 3); // Download = RX from NAS to client
            $total_ul_gb = round($tx_byte / $div, 3); // Upload   = TX from client to NAS
        } else {
            $note[]='iface-stats-empty';
        }
    }

 
    $lastSeen = null;
 
        $secret = $API->comm('/ppp/secret/print', ["?name" => $pppName]);
        if (!empty($secret[0]['last-logged-out'])) $lastSeen = $secret[0]['last-logged-out'];


    $API->disconnect();

    jexit([
        'status' => 'ok',
        'online' => $isOnline,
        'ip'     => $ip,
        'uptime' => $uptime,
        'last_seen' => $lastSeen,
        'iface'  => $ifaceName,
        'total_download_gb' => $total_dl_gb,
        'total_upload_gb'   => $total_ul_gb,
        // Raw ints (rounded) for any charts
        'rx_kbps' => (int)round($rx_kbps),
        'tx_kbps' => (int)round($tx_kbps),
        // Human friendly strings with auto unit
        'rx_rate' => $rx_rate,
        'tx_rate' => $tx_rate,
        'caller_id' => $caller_id,   // <-- NEW: caller-id যোগ করা হলো
        'note' => implode(',', $note),
    ]);

} catch (Throwable $e) {
    jexit([
        'status'=>'error','message'=>$e->getMessage(),
        'online'=>false,'ip'=>null,'uptime'=>null,'last_seen'=>null,
        'total_download_gb'=>null,'total_upload_gb'=>null,
        'rx_kbps'=>0,'tx_kbps'=>0,'rx_rate'=>'0 Kbps','tx_rate'=>'0 Kbps',
        'iface'=>null,
        'caller_id'=>null
    ]);
}


