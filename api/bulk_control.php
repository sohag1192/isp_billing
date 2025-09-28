<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/routeros_api.class.php';
require_once __DIR__ . '/../app/audit.php'; // ✅ audit helper

function respond($arr){ echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }

// Simple helpers
function get_router($id){
    $st = db()->prepare("SELECT * FROM routers WHERE id=?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC);
}
function rt_connect($router){
    $ip   = $router['ip'] ?? ($router['address'] ?? '');
    $user = $router['username'] ?? ($router['user'] ?? '');
    $pass = $router['password'] ?? ($router['pass'] ?? '');
    $port = intval($router['port'] ?? $router['api_port'] ?? 8728);
    if (!$ip || !$user || !$pass) return false;
    $API = new RouterosAPI(); $API->debug = false;
    return $API->connect($ip, $user, $pass, $port) ? $API : false;
}

// Read JSON
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
$action = $payload['action'] ?? '';
$ids    = $payload['ids'] ?? [];

if (!in_array($action, ['enable','disable'], true) || !is_array($ids) || count($ids)===0) {
    respond(['status'=>'error', 'message'=>'Invalid payload']);
}

// Load clients (audit এর জন্য name,status যোগ)
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$st = db()->prepare("SELECT id, pppoe_id, router_id, name, status
                     FROM clients
                     WHERE is_deleted=0 AND id IN ($placeholders)");
$st->execute($ids);
$clients = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$clients) respond(['status'=>'error','message'=>'No valid clients found']);

$processed = 0; $ok=0; $fail=0; $errors = [];
$kickedTotal = 0;

// Group by router_id
$map = [];
foreach ($clients as $c) {
    if (!$c['router_id'] || !$c['pppoe_id']) {
        $fail++; $errors[]=['id'=>$c['id'],'error'=>'router/pppoe missing'];
        continue;
    }
    $map[$c['router_id']][] = $c;
}

// Connect per router
$api_pool = [];    // router_id => API
$router_ip = [];   // router_id => ip (audit details)
foreach ($map as $rid => $list) {
    $router = get_router($rid);
    if (!$router) {
        foreach($list as $c){ $fail++; $errors[]=['id'=>$c['id'],'error'=>'router not found']; }
        continue;
    }
    $router_ip[$rid] = $router['ip'] ?? ($router['address'] ?? '');
    $api = rt_connect($router);
    $api_pool[$rid] = $api ?: false;
    if (!$api_pool[$rid]) {
        foreach($list as $c){ $fail++; $errors[]=['id'=>$c['id'],'error'=>'router connect failed']; }
    }
}

// Per router work
foreach ($map as $rid => $list) {
    $API = $api_pool[$rid];
    if (!$API) continue;

    foreach ($list as $c) {
        $processed++;
        $pppoe = $c['pppoe_id'];

        // PPP secret lookup
        $sec = $API->comm("/ppp/secret/print", [".proplist"=>".id", "?name"=>$pppoe]);
        if (empty($sec[0]['.id'])) {
            $fail++; $errors[]=['id'=>$c['id'],'error'=>'pppoe not found'];
            continue;
        }

        $ppp_id = $sec[0]['.id'];
        $disabled = ($action==='disable') ? 'yes' : 'no';

        // Secret set
        $API->comm("/ppp/secret/set", [".id"=>$ppp_id, "disabled"=>$disabled]);

        // DB status update
        $old_status = $c['status'] ?? null;
        $new_status = ($action==='disable') ? 'inactive' : 'active';
        db()->prepare("UPDATE clients SET status=? WHERE id=?")->execute([$new_status, $c['id']]);

        // disable হলে active থেকেও kick
        $kicked = false;
        if ($action === 'disable') {
            $act = $API->comm("/ppp/active/print", ["?name"=>$pppoe]);
            if (!empty($act[0]['.id'])) {
                $API->comm("/ppp/active/remove", [".id"=>$act[0]['.id']]);
                $kicked = true;
                $kickedTotal++;
            }
        }

        // ✅ Audit per item
        try {
            audit(
                $action==='enable' ? 'client_enable' : 'client_disable',
                'client',
                (int)$c['id'],
                [
                    'pppoe_id'    => $pppoe,
                    'name'        => $c['name'] ?? null,
                    'router_id'   => (int)$c['router_id'],
                    'router_ip'   => $router_ip[$rid] ?? null,
                    'status_from' => $old_status,
                    'status_to'   => $new_status,
                    'kicked'      => $kicked
                ]
            );
        } catch (Throwable $e) {
            // error_log($e->getMessage());
        }

        $ok++;
    }
}

// Disconnect
foreach ($api_pool as $rid=>$API) { if ($API) $API->disconnect(); }

// Summary
respond([
    'status'       => 'success',
    'processed'    => $processed,
    'succeeded'    => $ok,
    'failed'       => $fail,
    'kicked_total' => $kickedTotal,
    'errors'       => $errors
]);
