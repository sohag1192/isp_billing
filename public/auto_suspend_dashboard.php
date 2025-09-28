<?php
// /public/auto_suspend_dashboard.php
// UI: English; Comments: বাংলা — সুন্দর HTML ড্যাশবোর্ড (dry-run/apply)
declare(strict_types=1);
error_reporting(E_ALL); ini_set('display_errors','0'); ini_set('log_errors','1');

$ROOT = dirname(__DIR__, 1);
require_once $ROOT . '/app/require_login.php';
require_once $ROOT . '/app/db.php';
require_once $ROOT . '/app/routeros_api.class.php';
$acl_file = $ROOT . '/app/acl.php'; if (is_file($acl_file)) require_once $acl_file;
if (function_exists('require_perm')) { require_perm('suspend.run'); }

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function tbl_exists(PDO $pdo, string $t): bool {
  try{ $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
       $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
       $q->execute([$db,$t]); return (bool)$q->fetchColumn(); }catch(Throwable $e){ return false; }
}
function col_exists(PDO $pdo, string $t, string $c): bool {
  try{ $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
       $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
       $q->execute([$db,$t,$c]); return (bool)$q->fetchColumn(); }catch(Throwable $e){ return false; }
}
function pick_tbl(PDO $pdo, array $cands): ?string { foreach ($cands as $t) if (tbl_exists($pdo,$t)) return $t; return null; }
function pick_col(PDO $pdo, string $t, array $cands): ?string { foreach ($cands as $c) if (col_exists($pdo,$t,$c)) return $c; return null; }

const STRICT_MONTH = true;
const GRACE_DAYS   = 0;
const DEFAULT_ROUTER_ID = null;

$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ===== Shared logic: routers & clients
function fetch_routers(PDO $pdo): array {
  $RT = pick_tbl($pdo, ['routers','mikrotik_routers','router']); if(!$RT) return [];
  $ID=pick_col($pdo,$RT,['id','router_id']); $IP=pick_col($pdo,$RT,['ip','address','host']);
  $USER=pick_col($pdo,$RT,['username','user']); $PASS=pick_col($pdo,$RT,['password','pass']);
  $PORT=pick_col($pdo,$RT,['api_port','port']); $ACT=pick_col($pdo,$RT,['is_active','active']);
  $cols=["`$ID` AS id","`$IP` AS ip","`$USER` AS username","`$PASS` AS password"]; if($PORT)$cols[]="`$PORT` AS api_port"; if($ACT)$cols[]="`$ACT` AS is_active";
  $rows=$pdo->query("SELECT ".implode(',',$cols)." FROM `$RT`")->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach($rows as &$r){ $r['api_port']=(int)($r['api_port']??8728); $r['is_active']=isset($r['is_active'])?(int)$r['is_active']:1; }
  return $rows;
}
function fetch_clients(PDO $pdo): array {
  $CT = pick_tbl($pdo, ['clients','customers','subscribers']); if(!$CT) return [];
  $ID=pick_col($pdo,$CT,['id','client_id','cid']); $RID=pick_col($pdo,$CT,['router_id','router']);
  $USER=pick_col($pdo,$CT,['pppoe_id','username','pppoe_user']); $NAME=pick_col($pdo,$CT,['name','client_name']);
  $WL=pick_col($pdo,$CT,['is_whitelist','whitelist']); $BAL = col_exists($pdo,$CT,'ledger_balance')?'ledger_balance':null;
  $cols=["`$ID` AS id","`$NAME` AS name"]; if($RID)$cols[]="`$RID` AS router_id"; if($USER)$cols[]="`$USER` AS pppoe"; if($WL)$cols[]="`$WL` AS is_whitelist"; if($BAL)$cols[]="`$BAL` AS balance";
  $rows=$pdo->query("SELECT ".implode(',', $cols)." FROM `$CT`")->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach($rows as &$r){ $r['router_id']=$r['router_id']??DEFAULT_ROUTER_ID; $r['pppoe']=trim((string)($r['pppoe']??'')); $r['is_whitelist']=isset($r['is_whitelist'])?(int)$r['is_whitelist']:0; $r['balance']=(float)($r['balance']??0); }
  return $rows;
}
function client_is_due(PDO $pdo, int $client_id): bool {
  if (!tbl_exists($pdo,'invoices')) return false;
  $hasMonth = col_exists($pdo,'invoices','month') || col_exists($pdo,'invoices','bill_month');
  $hasYear  = col_exists($pdo,'invoices','year')  || col_exists($pdo,'invoices','bill_year');
  $cols=['id','status']; if($hasMonth)$cols[]=col_exists($pdo,'invoices','month')?'month':'bill_month AS month'; if($hasYear)$cols[]=col_exists($pdo,'invoices','year')?'year':'bill_year AS year'; if(col_exists($pdo,'invoices','created_at'))$cols[]='created_at';
  $st=$pdo->prepare("SELECT ".implode(',',$cols)." FROM invoices WHERE client_id=? ORDER BY id DESC LIMIT 1"); $st->execute([$client_id]); $inv=$st->fetch(PDO::FETCH_ASSOC);
  $today=new DateTimeImmutable(date('Y-m-d'));
  if($inv){
    $status=strtolower((string)($inv['status']??''));
    if(STRICT_MONTH && $hasMonth && $hasYear){
      $cm=(int)date('n'); $cy=(int)date('Y'); $im=(int)($inv['month']??$cm); $iy=(int)($inv['year']??$cy);
      if($iy>$cy || ($iy===$cy && $im>$cm)) return false;
    }
    if($status==='due'){
      if(GRACE_DAYS>0 && !empty($inv['created_at'])){
        try{$base=new DateTimeImmutable($inv['created_at']); $diff=(int)$today->diff($base)->format('%a'); if($diff<GRACE_DAYS) return false;}catch(Throwable $e){}
      }
      return true;
    }
    if($status==='paid') return false;
  }
  // fallback net due
  $st1=$pdo->prepare("SELECT COALESCE(SUM(payable),0) FROM invoices WHERE client_id=?"); $st1->execute([$client_id]); $total=(float)$st1->fetchColumn();
  if(!tbl_exists($pdo,'payments')) return $total>0.000001;
  $paid=$pdo->prepare("SELECT COALESCE(SUM(p.amount + COALESCE(p.discount,0)),0) FROM payments p JOIN invoices i ON p.bill_id=i.id WHERE i.client_id=?");
  $paid->execute([$client_id]); $tp=(float)$paid->fetchColumn();
  return ($total-$tp) > 0.000001;
}
function mikrotik_apply(string $ip,string $user,string $pass,int $port,string $pppoe,bool $disable,bool $dry=false): array {
  $log=[]; $log[] = ($disable?'Suspend':'Enable')." → $pppoe"; if ($dry){ $log[]='[dry-run]'; return $log; }
  $api=new RouterosAPI(); if(!$api->connect($ip,$user,$pass,$port)){ $log[]='Connect failed'; return $log; }
  $r=$api->comm("/ppp/secret/print", [".proplist"=>".id,disabled","?name"=>$pppoe]); if(!isset($r[0][".id"])){ $log[]='Secret not found'; $api->disconnect(); return $log; }
  $sid=$r[0][".id"]; $cur=(strtolower((string)($r[0]["disabled"]??'no'))==='yes');
  if($disable && !$cur){ $api->comm("/ppp/secret/set",[".id"=>$sid,"disabled"=>"yes"]); $log[]='secret: disabled'; $act=$api->comm("/ppp/active/print",[ ".proplist"=>".id","?name"=>$pppoe]); if(isset($act[0][".id"])){$api->comm("/ppp/active/remove",[ ".id"=>$act[0][".id"]]); $log[]='active: removed';}}
  if(!$disable && $cur){ $api->comm("/ppp/secret/set",[".id"=>$sid,"disabled"=>"no"]); $log[]='secret: enabled'; }
  $api->disconnect(); return $log;
}

$dry   = isset($_GET['mode']) && $_GET['mode']==='dry';
$apply = isset($_GET['mode']) && $_GET['mode']==='apply';
if ($apply && ($_GET['csrf'] ?? '') !== $CSRF) { http_response_code(403); echo 'Invalid CSRF'; exit; }

$routers = fetch_routers($pdo);
$clients = fetch_clients($pdo);
$byRouter=[]; foreach($clients as $c){ if(empty($c['pppoe'])) continue; $rid=$c['router_id'] ?? DEFAULT_ROUTER_ID; if($rid===null) continue; $byRouter[(int)$rid][]=$c; }

require_once $ROOT . '/partials/partials_header.php'; ?>
<div class="container-fluid py-3">
  <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
    <h4 class="mb-0"><i class="bi bi-diagram-3"></i> Auto Suspend/Enable Dashboard</h4>
    <div class="btn-group">
      <a href="?mode=dry" class="btn btn-sm btn-outline-secondary<?php echo $dry?' active':''; ?>"><i class="bi bi-eye"></i> Dry-run</a>
      <a href="?mode=apply&csrf=<?php echo h($CSRF); ?>" class="btn btn-sm btn-primary" onclick="return confirm('Apply changes to MikroTik?')"><i class="bi bi-lightning-charge"></i> Apply</a>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-4">
      <div class="card shadow-sm border-0">
        <div class="card-body">
          <div class="d-flex align-items-center">
            <div class="me-3"><i class="bi bi-shield-lock fs-3"></i></div>
            <div>
              <div class="text-muted small">Mode</div>
              <div class="fw-semibold"><?php echo $dry?'Dry-run (no changes)':'Apply (changes on MikroTik)'; ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card shadow-sm border-0">
        <div class="card-body">
          <div class="d-flex align-items-center">
            <div class="me-3"><i class="bi bi-calendar2-range fs-3"></i></div>
            <div>
              <div class="text-muted small">Strict Month</div>
              <div class="fw-semibold"><?php echo STRICT_MONTH?'ON':'OFF'; ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card shadow-sm border-0">
        <div class="card-body">
          <div class="d-flex align-items-center">
            <div class="me-3"><i class="bi bi-hourglass-split fs-3"></i></div>
            <div>
              <div class="text-muted small">Grace Days</div>
              <div class="fw-semibold"><?php echo GRACE_DAYS; ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php foreach ($routers as $r):
    $rid=(int)$r['id']; if(!($r['is_active']??1)) continue;
    $clist = $byRouter[$rid] ?? [];
    if (!$clist) continue;
  ?>
  <div class="card mb-3 shadow-sm">
    <div class="card-header bg-light d-flex justify-content-between align-items-center">
      <div class="fw-semibold"><i class="bi bi-hdd-network"></i> Router #<?php echo $rid; ?> — <?php echo h($r['ip']); ?>:<?php echo (int)$r['api_port']; ?></div>
      <span class="badge bg-secondary"><?php echo count($clist); ?> client(s)</span>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th style="width:80px;">ID</th>
              <th>Name</th>
              <th style="width:220px;">PPPoE</th>
              <th style="width:120px;" class="text-end">Balance</th>
              <th style="width:130px;">Status</th>
              <th>Result</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($clist as $c):
            $cid=(int)$c['id']; $ppp=$c['pppoe']; $due = client_is_due($pdo,$cid);
            $isWL = !empty($c['is_whitelist']);
            $badge = $isWL ? '<span class="badge bg-success">WHITELIST</span>' : ($due ? '<span class="badge bg-danger">DUE</span>' : '<span class="badge bg-primary">CLEAR</span>');
            $result = [];
            if ($apply) {
              if ($isWL)        $result = mikrotik_apply($r['ip'],$r['username'],$r['password'],(int)$r['api_port'],$ppp,false,false);
              else if ($due)    $result = mikrotik_apply($r['ip'],$r['username'],$r['password'],(int)$r['api_port'],$ppp,true,false);
              else              $result = mikrotik_apply($r['ip'],$r['username'],$r['password'],(int)$r['api_port'],$ppp,false,false);
            } elseif ($dry) {
              $action = $isWL ? 'Enable (WL)' : ($due?'Suspend':'Enable');
              $result = [$action, '[dry-run]'];
            }
          ?>
            <tr>
              <td class="text-muted">#<?php echo $cid; ?></td>
              <td class="fw-semibold"><?php echo h($c['name']); ?></td>
              <td><code><?php echo h($ppp); ?></code></td>
              <td class="text-end"><?php echo number_format((float)($c['balance'] ?? 0),2); ?></td>
              <td><?php echo $badge; ?></td>
              <td class="small text-muted"><?php echo h(implode(' • ', $result)); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

  <?php if (empty($byRouter)): ?>
    <div class="alert alert-warning">No router/client mapping found. Set <code>clients.router_id</code> or define DEFAULT_ROUTER_ID.</div>
  <?php endif; ?>
</div>
<?php require_once $ROOT . '/partials/partials_footer.php';
