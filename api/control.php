<?php
// /api/control.php
// Purpose: Enable/Disable/Kick PPPoE via MikroTik + JSON responses
// Style: Procedural PHP + PDO; Code English, comments Bangla

declare(strict_types=1);

/* ---------- API hardening: JSON-only output ---------- */
// (বাংলা) কোনো notice/warning যেন আউটপুটে মিশে না যায়
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

// (বাংলা) যেকোনো accidental output কেটে দিতে আউটপুট বাফার চালু
if (!ob_get_level()) { ob_start(); }

// (বাংলা) JSON header আগে থেকেই পাঠাই (header already sent হলেও buffer ক্লিনে সামলে নিব)
header('Content-Type: application/json; charset=utf-8');

// (বাংলা) API মোড ফ্ল্যাগ — অন্য ইনক্লুড ফাইল চাইলে ব্যবহার করতে পারে
if (!defined('API_MODE')) define('API_MODE', true);

/* ---------- Includes ---------- */
$ROOT = dirname(__DIR__);
require_once $ROOT . '/app/db.php';
require_once $ROOT . '/app/routeros_api.class.php';
$__audit = $ROOT . '/app/audit.php';
if (is_file($__audit)) require_once $__audit; // optional
require_once $ROOT . '/app/require_login.php'; // may echo notices; we will clean
$__acl = $ROOT . '/app/acl.php';
if (is_file($__acl)) { require_once $__acl; if (function_exists('require_perm')) { require_perm('ppp.enable_disable'); } }

/* ---------- Helpers ---------- */
function respond(array $arr, int $code = 200): void {
    // (বাংলা) যেটুকু accidental আউটপুট এসেছে সব ফেলে দিয়ে শুধু JSON পাঠাই
    http_response_code($code);
    if (ob_get_length() !== false) { @ob_clean(); }
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

function col_exists(PDO $pdo, string $tbl, string $col): bool {
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `$tbl` LIKE ?");
        $st->execute([$col]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

function get_router(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM routers WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function rt_connect(array $router): RouterosAPI|false {
    $ip   = $router['ip'] ?? ($router['address'] ?? '');
    $user = $router['username'] ?? ($router['user'] ?? '');
    $pass = $router['password'] ?? ($router['pass'] ?? '');
    $port = (int)($router['api_port'] ?? $router['port'] ?? 8728);

    if (!$ip || !$user || !$pass) return false;

    $API = new RouterosAPI();
    $API->debug = false;
    // (বাংলা) এই ক্লাসে port property সেট করে তারপর connect(ip,user,pass) করা লাগে
    if (property_exists($API, 'port')) $API->port = $port;
    return $API->connect($ip, $user, $pass) ? $API : false;
}

function safe_audit(string $action, string $etype, int $eid, array $meta = []): void {
    if (function_exists('audit')) {
        try { audit($action, $etype, $eid, $meta); } catch (Throwable $e) { /* ignore */ }
    }
}

/* ---------- DB ---------- */
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------- Inputs ---------- */
// (বাংলা) GET/POST দুইভাবেই allow করলাম
$action    = $_REQUEST['action'] ?? '';
$id        = (int)($_REQUEST['id'] ?? 0);
$client_id = (int)($_REQUEST['client_id'] ?? 0);
$pppoe_in  = trim((string)($_REQUEST['pppoe_id'] ?? ''));

// Normalize id
if ($client_id && !$id) $id = $client_id;

// Validate action
$action = strtolower($action);
if (!in_array($action, ['enable','disable','kick'], true)) {
    respond(['success'=>false,'type'=>'danger','message'=>'Invalid action'], 400);
}

/* ---------- Load client ---------- */
$has_is_left   = col_exists($pdo, 'clients', 'is_left');
$has_is_del    = col_exists($pdo, 'clients', 'is_deleted');
$has_status    = col_exists($pdo, 'clients', 'status');

$client = null;

if ($id > 0) {
    // (বাংলা) id দিয়ে
    $cond = "id=?";
    $args = [$id];

    if ($has_is_left) $cond .= " AND COALESCE(is_left,0)=0";
    if ($has_is_del)  $cond .= " AND COALESCE(is_deleted,0)=0";

    $st = $pdo->prepare("SELECT id, name, pppoe_id, router_id".($has_status?", status":"")." FROM clients WHERE $cond LIMIT 1");
    $st->execute($args);
    $client = $st->fetch(PDO::FETCH_ASSOC);
} elseif ($pppoe_in !== '') {
    // (বাংলা) pppoe_id দিয়ে
    $cond = "pppoe_id=?";
    $args = [$pppoe_in];

    if ($has_is_left) $cond .= " AND COALESCE(is_left,0)=0";
    if ($has_is_del)  $cond .= " AND COALESCE(is_deleted,0)=0";

    $st = $pdo->prepare("SELECT id, name, pppoe_id, router_id".($has_status?", status":"")." FROM clients WHERE $cond LIMIT 1");
    $st->execute($args);
    $client = $st->fetch(PDO::FETCH_ASSOC);
}

if (!$client) {
    respond(['success'=>false,'type'=>'danger','message'=>'Client not found or left/deleted'], 404);
}

// (বাংলা) essential fields
$client_id = (int)$client['id'];
$pppoe_id  = (string)$client['pppoe_id'];
$router_id = (int)$client['router_id'];
$oldStatus = $client['status'] ?? null;

// Router
$router = get_router($pdo, $router_id);
if (!$router) {
    respond(['success'=>false,'type'=>'danger','message'=>'Router not found'], 404);
}
$ip   = $router['ip'] ?? ($router['address'] ?? '');
$user = $router['username'] ?? ($router['user'] ?? '');
$pass = $router['password'] ?? ($router['pass'] ?? '');
$port = (int)($router['api_port'] ?? $router['port'] ?? 8728);
if (!$ip || !$user || !$pass) {
    respond(['success'=>false,'type'=>'danger','message'=>'Router credentials missing'], 500);
}

/* ---------- MikroTik connect ---------- */
$API = rt_connect($router);
if (!$API) {
    respond(['success'=>false,'type'=>'danger','message'=>'Router connection failed'], 502);
}

/* ---------- Actions ---------- */
$kicked = false;
$msg    = '';
$type   = 'info';

try {
    if ($action === 'kick') {
        // (বাংলা) শুধু active সেশন রিমুভ করো
        $act = $API->comm("/ppp/active/print", ["?name"=>$pppoe_id]);
        if (!empty($act[0]['.id'])) {
            $API->comm("/ppp/active/remove", [".id"=>$act[0]['.id']]);
            $kicked = true;
            $msg    = 'Client disconnected successfully';
            $type   = 'info';
        } else {
            $msg  = 'No active session found';
            $type = 'warning';
        }

    } else {
        // (বাংলা) enable/disable এর আগে secret id লাগবে
        $sec = $API->comm("/ppp/secret/print", [
            ".proplist" => ".id,disabled",
            "?name"     => $pppoe_id
        ]);
        if (empty($sec[0]['.id'])) {
            respond(['success'=>false,'type'=>'danger','message'=>'PPPoE user not found on router'], 404);
        }
        $secret_id   = $sec[0]['.id'];
        $is_disabled = isset($sec[0]['disabled']) && ($sec[0]['disabled']==='true' || $sec[0]['disabled']==='yes');

        if ($action === 'disable') {
            if (!$is_disabled) {
                $API->comm("/ppp/secret/set", [".id"=>$secret_id, "disabled"=>"yes"]);
            }
            if ($has_status) {
                $pdo->prepare("UPDATE clients SET status='inactive', updated_at=NOW() WHERE id=?")->execute([$client_id]);
            }
            // Active থাকলে কিক
            $act = $API->comm("/ppp/active/print", ["?name"=>$pppoe_id]);
            if (!empty($act[0]['.id'])) {
                $API->comm("/ppp/active/remove", [".id"=>$act[0]['.id']]);
                $kicked = true;
            }
            $msg  = 'Client disabled & disconnected (if online)';
            $type = 'danger';

        } else { // enable
            if ($is_disabled) {
                $API->comm("/ppp/secret/set", [".id"=>$secret_id, "disabled"=>"no"]);
            }
            if ($has_status) {
                $pdo->prepare("UPDATE clients SET status='active', updated_at=NOW() WHERE id=?")->execute([$client_id]);
            }
            $msg  = 'Client enabled successfully';
            $type = 'success';
        }
    }
} catch (Throwable $e) {
    // (বাংলা) রাউটার কমান্ডে সমস্যা – error রিটার্ন
    $API->disconnect();
    respond(['success'=>false,'type'=>'danger','message'=>'Router command failed: '.$e->getMessage()], 500);
}

// (বাংলা) কানেকশন ক্লোজ
$API->disconnect();

/* ---------- Audit (best-effort) ---------- */
$meta_common = [
    'pppoe_id'  => $pppoe_id,
    'name'      => $client['name'] ?? '',
    'router_id' => $router_id,
    'router_ip' => $ip,
];
if ($action === 'enable') {
    safe_audit('client_enable', 'client', $client_id, $meta_common + ['status_from'=>$oldStatus, 'status_to'=>'active',   'kicked'=>$kicked]);
} elseif ($action === 'disable') {
    safe_audit('client_disable','client', $client_id, $meta_common + ['status_from'=>$oldStatus, 'status_to'=>'inactive', 'kicked'=>$kicked]);
} else {
    safe_audit('client_kick',   'client', $client_id, $meta_common + ['kicked'=>$kicked]);
}

/* ---------- JSON response ---------- */
respond([
    'success' => true,
    'type'    => $type,
    'message' => $msg,
    'kicked'  => $kicked,
    'data'    => [
        'action'    => $action,
        'client_id' => $client_id,
        'pppoe_id'  => $pppoe_id,
        'router_id' => $router_id
    ]
], 200);
