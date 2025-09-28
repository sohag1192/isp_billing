<?php
// /public/client_whitelist.php
// UI: English; Comments: বাংলা — ক্লায়েন্ট হোয়াইটলিস্ট টগল UI
declare(strict_types=1);
error_reporting(E_ALL); ini_set('display_errors','0'); ini_set('log_errors','1');

$ROOT = dirname(__DIR__, 1);
require_once $ROOT . '/app/require_login.php';
require_once $ROOT . '/app/db.php';
$acl_file = $ROOT . '/app/acl.php'; if (is_file($acl_file)) require_once $acl_file;
if (function_exists('require_perm')) { require_perm('clients.edit'); }

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function tbl_exists(PDO $pdo, string $t): bool {
  try { $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
        $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
        $q->execute([$db,$t]); return (bool)$q->fetchColumn(); } catch(Throwable $e){ return false; }
}
function col_exists(PDO $pdo, string $t, string $c): bool {
  try { $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
        $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
        $q->execute([$db,$t,$c]); return (bool)$q->fetchColumn(); } catch(Throwable $e){ return false; }
}
function pick_tbl(PDO $pdo, array $cands): ?string { foreach ($cands as $t) if (tbl_exists($pdo,$t)) return $t; return null; }

$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$CT = pick_tbl($pdo, ['clients','customers','subscribers']);
if (!$CT || !col_exists($pdo,$CT,'is_whitelist')) { http_response_code(500); echo 'is_whitelist column missing. Run migration first.'; exit; }

$ID   = col_exists($pdo,$CT,'id')?'id':(col_exists($pdo,$CT,'client_id')?'client_id':'id');
$NAME = col_exists($pdo,$CT,'name')?'name':(col_exists($pdo,$CT,'client_name')?'client_name':$ID);
$USER = col_exists($pdo,$CT,'pppoe_id')?'pppoe_id':(col_exists($pdo,$CT,'username')?'username':null);

$q = trim((string)($_GET['q'] ?? ''));

// POST toggle
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!hash_equals($CSRF, (string)($_POST['csrf'] ?? ''))) { $_SESSION['flash_error']='Invalid CSRF.'; header('Location: /public/client_whitelist.php'); exit; }
  $cid = (int)($_POST['id'] ?? 0);
  $val = (int)($_POST['is_whitelist'] ?? 0) ? 1 : 0;
  if ($cid>0) {
    $st=$pdo->prepare("UPDATE `$CT` SET `is_whitelist`=? WHERE `$ID`=?"); $st->execute([$val,$cid]);
    $_SESSION['flash_success'] = ($val? 'Whitelisted' : 'Removed from whitelist') . " (#$cid)";
  }
  header('Location: /public/client_whitelist.php'); exit;
}

// fetch list
$where=[]; $args=[];
if ($q!=='') {
  $like='%'.$q.'%';
  $cond=["`$CT`.`$NAME` LIKE ?","CAST(`$CT`.`$ID` AS CHAR) LIKE ?"];
  foreach (['mobile','phone','email'] as $c) if (col_exists($pdo,$CT,$c)) $cond[]="`$CT`.`$c` LIKE ?";
  if ($USER) $cond[]="`$CT`.`$USER` LIKE ?";
  $where[]='('.implode(' OR ',$cond).')';
  $args=array_merge($args,array_fill(0,count($cond),$like));
}
$sql_where = $where ? (' WHERE '.implode(' AND ',$where)) : '';
$rows = $pdo->prepare("SELECT `$ID` AS id, `$NAME` AS name, ".($USER?"`$USER` AS user,":"")."`is_whitelist` FROM `$CT` $sql_where ORDER BY `$NAME` ASC LIMIT 200");
$rows->execute($args);
$list = $rows->fetchAll(PDO::FETCH_ASSOC) ?: [];

require_once $ROOT . '/partials/partials_header.php'; ?>
<div class="container-fluid py-3">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0"><i class="bi bi-star"></i> Client Whitelist</h4>
    <form class="d-flex" method="get" action="">
      <input type="text" class="form-control form-control-sm me-2" name="q" value="<?php echo h($q); ?>" placeholder="Search name/id/phone/<?php echo $USER?'username':''; ?>">
      <button class="btn btn-sm btn-outline-primary"><i class="bi bi-search"></i> Search</button>
    </form>
  </div>

  <?php if(!empty($_SESSION['flash_error'])): ?><div class="alert alert-danger py-2"><?php echo h($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></div><?php endif; ?>
  <?php if(!empty($_SESSION['flash_success'])): ?><div class="alert alert-success py-2"><?php echo h($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?></div><?php endif; ?>

  <div class="card">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th style="width:90px;">ID</th>
              <th>Name</th>
              <?php if ($USER): ?><th style="width:200px;">PPPoE</th><?php endif; ?>
              <th style="width:160px;" class="text-center">Whitelist</th>
            </tr>
          </thead>
          <tbody>
          <?php if(!$list): ?>
            <tr><td colspan="<?php echo $USER?4:3; ?>" class="text-center text-muted py-4">No clients found.</td></tr>
          <?php else: foreach($list as $r): ?>
            <tr>
              <td class="text-muted">#<?php echo (int)$r['id']; ?></td>
              <td class="fw-semibold"><?php echo h($r['name']); ?></td>
              <?php if ($USER): ?><td><code><?php echo h((string)($r['user'] ?? '')); ?></code></td><?php endif; ?>
              <td class="text-center">
                <form method="post" class="d-inline">
                  <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <input type="hidden" name="is_whitelist" value="<?php echo ($r['is_whitelist']?0:1); ?>">
                  <?php if ($r['is_whitelist']): ?>
                    <span class="badge bg-success me-2">Whitelisted</span>
                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle"></i> Remove</button>
                  <?php else: ?>
                    <span class="badge bg-secondary me-2">Normal</span>
                    <button class="btn btn-sm btn-outline-success"><i class="bi bi-check2-circle"></i> Add</button>
                  <?php endif; ?>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php require_once $ROOT . '/partials/partials_footer.php';
