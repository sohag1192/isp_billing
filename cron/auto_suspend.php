<?php
// /cron/auto_suspend.php
// Multi-router Auto Suspend/Enable (MikroTik PPPoE ← invoice due check)
// Features: whitelist, default-router fallback, grace-days, strict month window,
//           dry-run mode, best-effort audit, local client flags sync, SAFE client_is_due()
// CRON: */30 * * * * php /path/to/project/cron/auto_suspend.php >> /var/log/auto_suspend.log 2>&1
// DRY:  php /path/to/project/cron/auto_suspend.php --dry

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');

$ROOT = dirname(__DIR__, 1);
require_once $ROOT . '/app/db.php';
require_once $ROOT . '/app/routeros_api.class.php'; // RouterOS API class

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ===================== Config (tweak as needed) ===================== */
// বাংলা: clients.router_id ফাঁকা হলে কোন রাউটার ধরা হবে; null হলে স্কিপ
const DEFAULT_ROUTER_ID = null;

// বাংলা: due হলেও কয় দিন পরে suspend করবে
const GRACE_DAYS = 0;

// বাংলা: ভবিষ্যৎ মাসের ইনভয়েস ignore করবে (শুধু current/older মাস consider)
const STRICT_MONTH = true;

// বাংলা: dry-run হলে শুধু লগ হবে, MikroTik-এ কোনো পরিবর্তন যাবে না
$DRY_RUN = in_array('--dry', $argv ?? [], true);

/* ========================= Generic helpers ========================= */
function table_exists(PDO $pdo, string $t): bool {
  try{
    $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
    $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
    $q->execute([$db,$t]); return (bool)$q->fetchColumn();
  }catch(Throwable $e){ return false; }
}
function tbl_exists(PDO $pdo, string $t): bool { return table_exists($pdo,$t); }

function col_exists(PDO $pdo, string $t, string $c): bool {
  try{
    $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
    $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
    $q->execute([$db,$t,$c]); return (bool)$q->fetchColumn();
  }catch(Throwable $e){ return false; }
}
function pick_tbl(PDO $pdo, array $cands, ?string $fallback=null): ?string {
  foreach ($cands as $t) if (tbl_exists($pdo,$t)) return $t;
  return ($fallback && tbl_exists($pdo,$fallback)) ? $fallback : null;
}
function pick_col(PDO $pdo, string $t, array $cands, ?string $fallback=null): ?string {
  foreach ($cands as $c) if (col_exists($pdo,$t,$c)) return $c;
  return ($fallback && col_exists($pdo,$t,$fallback)) ? $fallback : null;
}
function now_date(): string { return date('Y-m-d'); }
function month_year_now(): array { return [(int)date('n'), (int)date('Y')]; }

/* ========================= Audit (best-effort) ========================= */
$AUDIT_FUNC = function_exists('audit_log') ? 'audit_log' : (function_exists('audit') ? 'audit' : null);
$AUDIT_TABLE = (!$AUDIT_FUNC && table_exists($pdo,'audit_logs')) ? 'audit_logs' : null;

function audit_best_effort(PDO $pdo, ?int $user_id, int $client_id, string $action, array $meta = []): void {
  global $AUDIT_FUNC, $AUDIT_TABLE;
  try{
    if ($AUDIT_FUNC) { $fn = $AUDIT_FUNC; @$fn($user_id, $client_id, $action, $meta); return; }
  }catch(Throwable $e){ /* ignore */ }
  if ($AUDIT_TABLE) {
    try{
      $st=$pdo->prepare("INSERT INTO `audit_logs` (user_id, entity_id, action, meta, created_at) VALUES (?,?,?,?,NOW())");
      $st->execute([$user_id, $client_id, $action, json_encode($meta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }catch(Throwable $e){}
  }
}

/* ========================= Data fetch ========================= */
function fetch_routers(PDO $pdo): array {
  $RT = pick_tbl($pdo, ['routers','mikrotik_routers','router']);
  if (!$RT) { echo "[!] No routers table found.\n"; return []; }

  $ID   = pick_col($pdo,$RT,['id','router_id'],'id');
  $IP   = pick_col($pdo,$RT,['ip','address','host','router_ip'],'ip');
  $USER = pick_col($pdo,$RT,['username','user','login_user'],'username');
  $PASS = pick_col($pdo,$RT,['password','pass','login_pass'],'password');
  $PORT = pick_col($pdo,$RT,['api_port','port'],'api_port');
  $ACT  = pick_col($pdo,$RT,['is_active','active','enabled'],'is_active');

  $cols = ["`$ID` AS id","`$IP` AS ip","`$USER` AS username","`$PASS` AS password"];
  if ($PORT) $cols[] = "`$PORT` AS api_port";
  if ($ACT)  $cols[] = "`$ACT` AS is_active";

  $rows = $pdo->query("SELECT ".implode(',', $cols)." FROM `$RT`")->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($rows as &$r){
    $r['api_port'] = (int)($r['api_port'] ?? 8728);
    $r['is_active'] = isset($r['is_active']) ? (int)$r['is_active'] : 1;
  }
  return $rows;
}

function fetch_clients(PDO $pdo): array {
  $CT = pick_tbl($pdo, ['clients','customers','subscribers','client']);
  if (!$CT) { echo "[!] No clients table found.\n"; return []; }

  $C_ID   = pick_col($pdo,$CT,['id','client_id','cid','customer_id','subscriber_id'],'id');
  $C_RID  = pick_col($pdo,$CT,['router_id','router','routerid'], null);
  $C_USER = pick_col($pdo,$CT,['pppoe_id','username','pppoe_user','pppoe'], null);
  $C_STAT = pick_col($pdo,$CT,['status'], null);
  $C_ACT  = pick_col($pdo,$CT,['is_active'], null);
  $C_WL   = pick_col($pdo,$CT,['is_whitelist','whitelist'], null);
  $C_FLAG = pick_col($pdo,$CT,['flags','tags'], null);

  $cols = ["`$C_ID` AS id"];
  if ($C_RID)  $cols[] = "`$C_RID` AS router_id";
  if ($C_USER) $cols[] = "`$C_USER` AS pppoe";
  if ($C_STAT) $cols[] = "`$C_STAT` AS status";
  if ($C_ACT)  $cols[] = "`$C_ACT`  AS is_active";
  if ($C_WL)   $cols[] = "`$C_WL`   AS is_whitelist";
  if ($C_FLAG) $cols[] = "`$C_FLAG` AS flags";

  $rows = $pdo->query("SELECT ".implode(',', $cols)." FROM `$CT`")->fetchAll(PDO::FETCH_ASSOC) ?: [];

  foreach ($rows as &$r){
    $r['router_id'] = $r['router_id'] ?? DEFAULT_ROUTER_ID;
    $r['pppoe'] = trim((string)($r['pppoe'] ?? ''));
    $r['is_whitelist'] = isset($r['is_whitelist']) ? (int)$r['is_whitelist'] : 0;
    $r['flags'] = strtolower(trim((string)($r['flags'] ?? '')));
  }
  return $rows;
}

/* ========================= Business rules ========================= */
// বাংলা: whitelist হলে কখনো suspend নয় (force enable)
function is_whitelisted(array $c): bool {
  if (!empty($c['is_whitelist'])) return true;
  if (!empty($c['flags']) && (str_contains($c['flags'], 'vip') || str_contains($c['flags'], 'whitelist'))) return true;
  return false;
}

// 🔧 SAFE — month/year optional; fallback to net due; grace/strict respected
function client_is_due(PDO $pdo, int $client_id): bool {
  if (!tbl_exists($pdo,'invoices')) return false;

  $hasMonth = col_exists($pdo,'invoices','month') || col_exists($pdo,'invoices','bill_month');
  $hasYear  = col_exists($pdo,'invoices','year')  || col_exists($pdo,'invoices','bill_year');

  $cols = ['id','status'];
  if ($hasMonth) $cols[] = col_exists($pdo,'invoices','month') ? 'month' : 'bill_month AS month';
  if ($hasYear)  $cols[] = col_exists($pdo,'invoices','year') ? 'year' : 'bill_year AS year';
  if (col_exists($pdo,'invoices','created_at')) $cols[] = 'created_at';

  $st  = $pdo->prepare("SELECT ".implode(',', $cols)." FROM invoices WHERE client_id=? ORDER BY id DESC LIMIT 1");
  $st->execute([$client_id]);
  $inv = $st->fetch(PDO::FETCH_ASSOC);

  $today = new DateTimeImmutable(now_date());

  if ($inv) {
    $status = strtolower((string)($inv['status'] ?? ''));

    if (STRICT_MONTH && $hasMonth && $hasYear) {
      [$cm, $cy] = month_year_now();
      $im = (int)($inv['month'] ?? $cm);
      $iy = (int)($inv['year'] ?? $cy);
      if ($iy > $cy || ($iy === $cy && $im > $cm)) return false; // future invoice ⇒ ignore
    }

    if ($status === 'due') {
      if (GRACE_DAYS > 0 && !empty($inv['created_at'])) {
        try {
          $base = new DateTimeImmutable($inv['created_at']);
          $diff = (int)$today->diff($base)->format('%a');
          if ($diff < GRACE_DAYS) return false;
        } catch (Throwable $e) {}
      }
      return true;
    }
    if ($status === 'paid') return false;
  }

  // Fallback — net due
  $st1 = $pdo->prepare("SELECT COALESCE(SUM(payable),0) FROM invoices WHERE client_id=?");
  $st1->execute([$client_id]);
  $totalPayable = (float)$st1->fetchColumn();

  if (!tbl_exists($pdo,'payments')) return ($totalPayable > 0.000001);

  $paidSql = "SELECT COALESCE(SUM(p.amount + COALESCE(p.discount,0)),0)
              FROM payments p JOIN invoices i ON p.bill_id=i.id WHERE i.client_id=?";
  $stp = $pdo->prepare($paidSql);
  $stp->execute([$client_id]);
  $totalPaid = (float)$stp->fetchColumn();

  $net = $totalPayable - $totalPaid;
  if ($net > 0.000001) {
    if (GRACE_DAYS > 0) {
      $stL = $pdo->prepare("SELECT created_at FROM invoices WHERE client_id=? ORDER BY id DESC LIMIT 1");
      $stL->execute([$client_id]);
      $base = $stL->fetchColumn();
      if ($base) {
        try {
          $baseDt = new DateTimeImmutable((string)$base);
          $diff = (int)$today->diff($baseDt)->format('%a');
          if ($diff < GRACE_DAYS) return false;
        } catch (Throwable $e) {}
      }
    }
    return true;
  }
  return false;
}

/* ========================= Local flags sync ========================= */
function update_client_local_flags(PDO $pdo, int $client_id, bool $suspended): void {
  $CT = pick_tbl($pdo, ['clients','customers','subscribers','client']);
  if (!$CT) return;
  $hasStatus = col_exists($pdo,$CT,'status');
  $hasActive = col_exists($pdo,$CT,'is_active');
  if (!$hasStatus && !$hasActive) return;

  if ($hasStatus && $hasActive) {
    $st=$pdo->prepare("UPDATE `$CT` SET `status`=?, `is_active`=? WHERE `id`=?");
    $st->execute([$suspended ? 'suspended' : 'active', $suspended ? 0 : 1, $client_id]);
  } elseif ($hasStatus) {
    $st=$pdo->prepare("UPDATE `$CT` SET `status`=? WHERE `id`=?");
    $st->execute([$suspended ? 'suspended' : 'active', $client_id]);
  } else {
    $st=$pdo->prepare("UPDATE `$CT` SET `is_active`=? WHERE `id`=?");
    $st->execute([$suspended ? 0 : 1, $client_id]);
  }
}

/* ========================= RouterOS helpers ========================= */
function mikrotik_set_pppoe(RouterosAPI $api, string $pppoe, bool $disable, bool $dryRun = false): void {
  // find secret
  $res = $dryRun ? [['.id'=>'*dry*','disabled'=>$disable?'no':'yes']]
                 : $api->comm("/ppp/secret/print", [".proplist" => ".id,disabled", "?name" => $pppoe]);

  if (!is_array($res) || !isset($res[0][".id"])) {
    echo "  - PPPoE secret not found: {$pppoe}\n"; return;
  }
  $sid = $res[0][".id"];
  $curDisabled = strtolower((string)($res[0]["disabled"] ?? 'no')) === 'yes';

  if ($disable && !$curDisabled) {
    echo "  - Secret disabled\n";
    if (!$dryRun) $api->comm("/ppp/secret/set", [".id"=>$sid,"disabled"=>"yes"]);
  } elseif (!$disable && $curDisabled) {
    echo "  - Secret enabled\n";
    if (!$dryRun) $api->comm("/ppp/secret/set", [".id"=>$sid,"disabled"=>"no"]);
  } else {
    echo "  - Secret already ".($disable?'disabled':'enabled')."\n";
  }

  // kick active if disabling
  if ($disable && !$dryRun) {
    $act = $api->comm("/ppp/active/print", [".proplist" => ".id","?name"=>$pppoe]);
    if (is_array($act) && isset($act[0][".id"])) {
      $api->comm("/ppp/active/remove", [".id"=>$act[0][".id"]]);
      echo "  - Active session removed\n";
    }
  }
}

/* ============================= Main Flow ============================= */
$routers = fetch_routers($pdo);
if (!$routers) { echo "[!] No routers configured.\n"; exit; }

$clients = fetch_clients($pdo);
if (!$clients) { echo "[!] No clients found.\n"; exit; }

// group clients by router_id (fallback default)
$clientsByRouter = [];
foreach ($clients as $c) {
  if (empty($c['pppoe'])) continue;
  $rid = $c['router_id'] ?? DEFAULT_ROUTER_ID;
  if ($rid === null) continue;
  $clientsByRouter[(int)$rid][] = $c;
}

// map routers
$routerMap = [];
foreach ($routers as $r) $routerMap[(int)$r['id']] = $r;

$processed = 0;
foreach ($clientsByRouter as $rid => $clist) {
  $rid = (int)$rid;
  if (!isset($routerMap[$rid])) { echo "[Router #$rid] Not found; skipped.\n"; continue; }
  $r = $routerMap[$rid];
  if (!($r['is_active'] ?? 1)) { echo "[Router #$rid] Inactive; skipped.\n"; continue; }

  $ip=$r['ip']; $user=$r['username']; $pass=$r['password']; $port=(int)$r['api_port'] ?: 8728;

  echo "=== Router #$rid @ {$ip}:{$port} ".($DRY_RUN?"[DRY-RUN]":"")." ===\n";
  $API = new RouterosAPI();
  $API->debug = false;
  $connected = $DRY_RUN ? true : $API->connect($ip,$user,$pass,$port);
  if (!$connected) { echo "  [!] Connect failed.\n"; continue; }

  foreach ($clist as $c) {
    $cid=(int)$c['id']; $ppp=(string)$c['pppoe'];
    echo "- Client #{$cid} ({$ppp}): ";

    if (is_whitelisted($c)) {
      echo "WHITELIST -> ENABLE\n";
      mikrotik_set_pppoe($API, $ppp, false, $DRY_RUN);
      update_client_local_flags($pdo, $cid, false);
      audit_best_effort($pdo, null, $cid, 'auto_enable_whitelist', ['router_id'=>$rid,'pppoe'=>$ppp]);
      $processed++; continue;
    }

    $due = client_is_due($pdo, $cid);

    if ($due) {
      echo "DUE -> SUSPEND\n";
      mikrotik_set_pppoe($API, $ppp, true, $DRY_RUN);
      update_client_local_flags($pdo, $cid, true);
      audit_best_effort($pdo, null, $cid, 'auto_suspend', ['router_id'=>$rid,'pppoe'=>$ppp,'grace_days'=>GRACE_DAYS,'strict_month'=>STRICT_MONTH]);
    } else {
      echo "CLEAR -> ENABLE\n";
      mikrotik_set_pppoe($API, $ppp, false, $DRY_RUN);
      update_client_local_flags($pdo, $cid, false);
      audit_best_effort($pdo, null, $cid, 'auto_enable', ['router_id'=>$rid,'pppoe'=>$ppp]);
    }
    $processed++;
  }

  if (!$DRY_RUN) $API->disconnect();
}

echo "Done. Processed clients: {$processed}".($DRY_RUN ? " [DRY-RUN]" : "")."\n";
