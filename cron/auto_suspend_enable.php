<?php
// cron/auto_suspend_enable.php
// Purpose: Due থাকলে MikroTik PPP secret disable + active kick, পরিশোধ হলে enable
// Style: Procedural PHP + PDO; Code English, comments in Bangla
// Run (CLI): php cron/auto_suspend_enable.php [--dry] [--due_limit=100] [--router_id=5] [--area=Town] [--batch=500]
// Cron (every 10 mins): */10 * * * * php /var/www/html/cron/auto_suspend_enable.php >> /var/www/html/storage/logs/cron_suspend.log 2>&1

declare(strict_types=1);
date_default_timezone_set('Asia/Dhaka');

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/routeros_api.class.php';

/* ================= Config ================= */
// (বাংলা) disable করলে active থেকেও কিক করবে—true রাখাই ভালো
$DISCONNECT_ACTIVE_SESSION = true;
// (বাংলা) RouterOS API port fallback (class অনুযায়ী port property বা 4th arg—দুটোই চেষ্টা করব)
$DEFAULT_API_PORT          = 8728;
/* ========================================== */

// ---------- Helpers ----------
function col_exists(PDO $pdo, string $tbl, string $col): bool {
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `$tbl` LIKE ?");
        $st->execute([$col]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}
function tbl_exists(PDO $pdo, string $tbl): bool {
    try { $pdo->query("SELECT 1 FROM `$tbl` LIMIT 1"); return true; }
    catch (Throwable $e) { return false; }
}
function audit_log(PDO $pdo, string $action, array $meta = []): void {
    // (বাংলা) audit_logs / audit — যেটা আছে স্কিমা-সেফ ভাবে লিখি; না থাকলে স্কিপ
    $table = tbl_exists($pdo, 'audit_logs') ? 'audit_logs' : (tbl_exists($pdo,'audit') ? 'audit' : '');
    if (!$table) return;
    $payload = json_encode($meta, JSON_UNESCAPED_UNICODE);
    if (col_exists($pdo,$table,'action') && col_exists($pdo,$table,'meta') && col_exists($pdo,$table,'created_at')) {
        $pdo->prepare("INSERT INTO `$table` (action, meta, created_at) VALUES (?, ?, NOW())")->execute([$action, $payload]);
    } elseif (col_exists($pdo,$table,'message')) {
        $pdo->prepare("INSERT INTO `$table` (message) VALUES (?)")->execute([$action.' '.$payload]);
    }
}
function get_router(PDO $pdo, int $id) {
    $st = $pdo->prepare("SELECT * FROM routers WHERE id=? LIMIT 1");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC);
}
function rt_connect(array $router) {
    // (বাংলা) MikroTik API কানেক্ট — class ভেদে দুইভাবে কাজ করে: port property / 4th arg
    global $DEFAULT_API_PORT;
    $ip   = $router['ip']       ?? ($router['address'] ?? '');
    $user = $router['username'] ?? ($router['user'] ?? '');
    $pass = $router['password'] ?? ($router['pass'] ?? '');
    $port = intval($router['api_port'] ?? $router['port'] ?? $DEFAULT_API_PORT);
    if (!$ip || !$user || !$pass) return false;

    $API = new RouterosAPI();
    $API->debug = false;
    // চেষ্টা-১: property দিয়ে
    try {
        if (property_exists($API,'port')) $API->port = $port;
        if ($API->connect($ip, $user, $pass)) return $API;
    } catch (Throwable $e) { /* fallback */ }
    // চেষ্টা-২: 4th arg দিয়ে
    try {
        if ($API->connect($ip, $user, $pass, $port)) return $API;
    } catch (Throwable $e) { /* no-op */ }
    return false;
}
function rt_secret_info($api, string $name): array {
    $r = $api->comm('/ppp/secret/print', ['.proplist'=>'.id,disabled', '?name'=>$name]);
    if (!isset($r[0]['.id'])) return ['id'=>null, 'disabled'=>null];
    $dis = $r[0]['disabled'] ?? '';
    $is_dis = ($dis === 'true' || $dis === 'yes' || $dis === '1');
    return ['id'=>$r[0]['.id'], 'disabled'=>$is_dis];
}
function rt_active_ids($api, string $name): array {
    $res = $api->comm('/ppp/active/print', ['.proplist'=>'.id,name', '?name'=>$name]);
    $ids = [];
    foreach ($res as $row) if (isset($row['.id'])) $ids[] = $row['.id'];
    return $ids;
}
function rt_disable_and_kick($api, string $name, bool $kick=true): array {
    $info = rt_secret_info($api, $name);
    $changed = false; $kicked = 0;
    if ($info['id'] && !$info['disabled']) {
        $api->comm('/ppp/secret/set', ['.id'=>$info['id'], 'disabled'=>'yes']);
        $changed = true;
    }
    if ($kick) {
        foreach (rt_active_ids($api, $name) as $aid) {
            $api->comm('/ppp/active/remove', ['.id'=>$aid]);
            $kicked++;
        }
    }
    return ['changed'=>$changed, 'kicked'=>$kicked];
}
function rt_enable($api, string $name): bool {
    $info = rt_secret_info($api, $name);
    if ($info['id'] && $info['disabled']) {
        $api->comm('/ppp/secret/set', ['.id'=>$info['id'], 'disabled'=>'no']);
        return true;
    }
    return false;
}

// ---------- Inputs / Flags ----------
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// (বাংলা) GET/CLI প্যারাম
function cli_get_flag(string $key) {
    global $argv;
    if (PHP_SAPI !== 'cli' || empty($argv)) return null;
    foreach ($argv as $arg) {
        if (preg_match('/^--'.preg_quote($key,'/').'=(.*)$/', $arg, $m)) return $m[1];
        if ($arg === '--'.$key) return '1';
    }
    return null;
}
$due_limit = isset($_GET['due_limit']) ? (float)$_GET['due_limit'] : (float)(cli_get_flag('due_limit') ?? 1.0);
$router_id = isset($_GET['router_id']) ? (int)$_GET['router_id'] : (int)(cli_get_flag('router_id') ?? 0);
$area      = isset($_GET['area'])      ? trim((string)$_GET['area']) : (string)(cli_get_flag('area') ?? '');
$batch     = isset($_GET['batch'])     ? max(1,(int)$_GET['batch']) : max(1,(int)(cli_get_flag('batch') ?? 500));
$dry       = isset($_GET['dry'])       ? (int)$_GET['dry'] : (int)(cli_get_flag('dry') ?? 0);

// (বাংলা) optional tracking columns—থাকলে আপডেট করব
$has_status        = col_exists($pdo, 'clients', 'status');
$has_is_left       = col_exists($pdo, 'clients', 'is_left');
$has_suspend_flag  = col_exists($pdo, 'clients', 'suspend_by_billing');
$has_suspended_at  = col_exists($pdo, 'clients', 'suspended_at');

// ---------- Build base filter ----------
$where = "COALESCE(c.pppoe_id,'')<>'' AND c.router_id IS NOT NULL";
if ($has_is_left) $where .= " AND COALESCE(c.is_left,0)=0";
$args  = [];
if ($router_id > 0) { $where .= " AND c.router_id = ?"; $args[] = $router_id; }
if ($area !== '')   { $where .= " AND c.area = ?";      $args[] = $area; }

// ---------- Candidate groups ----------
$due_sql = "
SELECT c.id, c.name, c.pppoe_id, c.router_id, COALESCE(c.ledger_balance,0) AS due
".($has_status ? ", c.status" : "")."
FROM clients c
WHERE $where AND COALESCE(c.ledger_balance,0) > ?
ORDER BY c.ledger_balance DESC
LIMIT $batch
";
$due_args = array_merge($args, [$due_limit]);

$enable_sql = "
SELECT c.id, c.name, c.pppoe_id, c.router_id, COALESCE(c.ledger_balance,0) AS due
".($has_status ? ", c.status" : "")."
FROM clients c
WHERE $where AND COALESCE(c.ledger_balance,0) <= 0
".($has_suspend_flag ? " AND COALESCE(c.suspend_by_billing,0)=1" : "")."
ORDER BY c.id ASC
LIMIT $batch
";

// ---------- Fetch ----------
$st1 = $pdo->prepare($due_sql);    $st1->execute($due_args); $to_suspend = $st1->fetchAll(PDO::FETCH_ASSOC);
$st2 = $pdo->prepare($enable_sql); $st2->execute($args);     $to_enable  = $st2->fetchAll(PDO::FETCH_ASSOC);

if (!$to_suspend && !$to_enable) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "[".date('Y-m-d H:i:s')."] Nothing to do (filters applied). due_limit={$due_limit}, router_id={$router_id}, area='{$area}', batch={$batch}, dry={$dry}\n";
    exit;
}

// ---------- Router grouping ----------
function group_by_router(array $rows): array {
    $g = [];
    foreach ($rows as $r) {
        $rid = (int)$r['router_id'];
        if ($rid <= 0) continue;
        $g[$rid][] = $r;
    }
    return $g;
}
$g_suspend = group_by_router($to_suspend);
$g_enable  = group_by_router($to_enable);

// ---------- Connect per-router ----------
$routers = []; $apis = [];
foreach (array_unique(array_merge(array_keys($g_suspend), array_keys($g_enable))) as $rid) {
    $routers[$rid] = get_router($pdo, $rid);
    $apis[$rid]    = ($routers[$rid] && !$dry) ? rt_connect($routers[$rid]) : false;
    if (!$apis[$rid] && !$dry) {
        echo "[".date('Y-m-d H:i:s')."] Router connect failed (id=$rid)\n";
    }
}

// ---------- Process ----------
$summary = ['suspended'=>0,'kicked'=>0,'enabled'=>0,'skipped'=>0,'routers'=>[]];

// Suspend due>limit
foreach ($g_suspend as $rid => $rows) {
    $api = $apis[$rid] ?? false;
    foreach ($rows as $c) {
        $pppoe = (string)$c['pppoe_id'];
        if ($pppoe === '' || (!$api && !$dry)) { $summary['skipped']++; continue; }

        if ($dry) { $summary['suspended']++; continue; }

        try {
            $res = rt_disable_and_kick($api, $pppoe, $DISCONNECT_ACTIVE_SESSION);
            if ($res['changed']) $summary['suspended']++;
            $summary['kicked'] += $res['kicked'];
            $summary['routers'][$rid]['suspend'] = ($summary['routers'][$rid]['suspend'] ?? 0) + (int)$res['changed'];
            $summary['routers'][$rid]['kick']    = ($summary['routers'][$rid]['kick']    ?? 0) + (int)$res['kicked'];

            // flags/status
            if ($has_suspend_flag) {
                $sql = "UPDATE clients SET suspend_by_billing=1".
                       ($has_suspended_at ? ", suspended_at=NOW()" : "").
                       " WHERE id=?";
                $pdo->prepare($sql)->execute([(int)$c['id']]);
            }
            if ($has_status && (($c['status'] ?? '') !== 'inactive')) {
                $pdo->prepare("UPDATE clients SET status='inactive', updated_at=NOW() WHERE id=?")->execute([(int)$c['id']]);
            }

            audit_log($pdo, 'pppoe_suspend_due', [
                'client_id'=>(int)$c['id'],
                'pppoe_id'=>$pppoe,
                'router_id'=>(int)$rid,
                'ledger'=>(float)$c['due']
            ]);
            echo "[".date('Y-m-d H:i:s')."] DISABLED {$pppoe} due={$c['due']} (router={$rid})\n";
            if ($DISCONNECT_ACTIVE_SESSION && $res['kicked']>0) {
                echo "[".date('Y-m-d H:i:s')."] KICKED {$res['kicked']} active session(s) {$pppoe}\n";
            }
        } catch (Throwable $e) {
            $summary['skipped']++;
        }
    }
}

// Enable when paid/advance
foreach ($g_enable as $rid => $rows) {
    $api = $apis[$rid] ?? false;
    foreach ($rows as $c) {
        $pppoe = (string)$c['pppoe_id'];
        if ($pppoe === '' || (!$api && !$dry)) { $summary['skipped']++; continue; }

        if ($dry) { $summary['enabled']++; continue; }

        try {
            $ok = rt_enable($api, $pppoe);
            if ($ok) {
                if ($has_suspend_flag) {
                    $pdo->prepare("UPDATE clients SET suspend_by_billing=0 WHERE id=?")->execute([(int)$c['id']]);
                }
                if ($has_status && (($c['status'] ?? '') !== 'active')) {
                    $pdo->prepare("UPDATE clients SET status='active', updated_at=NOW() WHERE id=?")->execute([(int)$c['id']]);
                }
                $summary['enabled']++;
                $summary['routers'][$rid]['enable'] = ($summary['routers'][$rid]['enable'] ?? 0) + 1;

                audit_log($pdo, 'pppoe_enable_after_payment', [
                    'client_id'=>(int)$c['id'],
                    'pppoe_id'=>$pppoe,
                    'router_id'=>(int)$rid,
                    'ledger'=>(float)$c['due']
                ]);
                echo "[".date('Y-m-d H:i:s')."] ENABLED {$pppoe} due={$c['due']} (router={$rid})\n";
            }
        } catch (Throwable $e) {
            $summary['skipped']++;
        }
    }
}

// ---------- Disconnect ----------
foreach ($apis as $rid => $api) {
    if ($api) { try { $api->disconnect(); } catch (Throwable $e) {} }
}

// ---------- Output ----------
header('Content-Type: text/plain; charset=utf-8');
echo "Auto Suspend/Enable Summary\n";
echo "due_limit={$due_limit}, router_id={$router_id}, area='{$area}', batch={$batch}, dry={$dry}\n";
echo "suspended={$summary['suspended']}, kicked={$summary['kicked']}, enabled={$summary['enabled']}, skipped={$summary['skipped']}\n";
foreach ($summary['routers'] as $rid => $x) {
    $s = $x['suspend'] ?? 0; $e = $x['enable'] ?? 0; $k = $x['kick'] ?? 0;
    echo " - router #{$rid}: suspended={$s}, enabled={$e}, kicked={$k}\n";
}
