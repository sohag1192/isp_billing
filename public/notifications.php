<?php
// /public/notifications.php
// UI: English; Comments: বাংলা — নোটিফিকেশন ড্যাশবোর্ড (ফিল্টার+রিসেন্ড)
declare(strict_types=1);
error_reporting(E_ALL); ini_set('display_errors','0'); ini_set('log_errors','1');

$ROOT = dirname(__DIR__, 1);
require_once $ROOT . '/app/require_login.php';
require_once $ROOT . '/app/db.php';
require_once $ROOT . '/app/notify.php';
$acl_file = $ROOT . '/app/acl.php'; if (is_file($acl_file)) require_once $acl_file;
if (function_exists('require_perm')) { require_perm('notify.view'); }

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$status = in_array(($_GET['status'] ?? ''), ['queued','sent','failed'], true) ? $_GET['status'] : '';
$channel= in_array(($_GET['channel'] ?? ''), ['sms','email'], true) ? $_GET['channel'] : '';
$q      = trim((string)($_GET['q'] ?? ''));
$page   = max(1,(int)($_GET['page'] ?? 1));
$per    = 50; $off = ($page-1)*$per;

// POST: requeue
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['act'] ?? '')==='requeue') {
  if (!hash_equals($CSRF, (string)($_POST['csrf'] ?? ''))) { $_SESSION['flash_error']='Invalid CSRF.'; header('Location: /public/notifications.php'); exit; }
  if (function_exists('require_perm')) { require_perm('notify.requeue'); }
  $id = (int)($_POST['id'] ?? 0);
  if ($id>0) {
    $st=$pdo->prepare("UPDATE notifications SET status='queued', retries=0, send_after=NOW(), last_error=NULL WHERE id=?");
    $st->execute([$id]); $_SESSION['flash_success']="Requeued #$id";
  }
  header('Location: /public/notifications.php'); exit;
}

$where=[]; $args=[];
if ($status!==''){ $where[]='status=?'; $args[]=$status; }
if ($channel!==''){ $where[]='channel=?'; $args[]=$channel; }
if ($q!==''){ $where[]='(template_key LIKE ? OR CAST(client_id AS CHAR) LIKE ? OR payload_json LIKE ?)'; $args=array_merge($args, array_fill(0,3,'%'.$q.'%')); }
$sqlw = $where?(' WHERE '.implode(' AND ',$where)) : '';

$tc=$pdo->prepare("SELECT COUNT(1) FROM notifications $sqlw"); $tc->execute($args);
$total = (int)$tc->fetchColumn();

$st=$pdo->prepare("SELECT * FROM notifications $sqlw ORDER BY id DESC LIMIT $per OFFSET $off"); $st->execute($args);
$rows=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];

require_once $ROOT . '/partials/partials_header.php'; ?>
<div class="container-fluid py-3">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0"><i class="bi bi-bell"></i> Notifications</h4>
    <form class="d-flex" method="get">
      <select name="status" class="form-select form-select-sm me-2" style="width:140px">
        <option value="">All Status</option>
        <option value="queued" <?php echo $status==='queued'?'selected':''; ?>>Queued</option>
        <option value="sent" <?php echo $status==='sent'?'selected':''; ?>>Sent</option>
        <option value="failed" <?php echo $status==='failed'?'selected':''; ?>>Failed</option>
      </select>
      <select name="channel" class="form-select form-select-sm me-2" style="width:120px">
        <option value="">All Channels</option>
        <option value="sms" <?php echo $channel==='sms'?'selected':''; ?>>SMS</option>
        <option value="email" <?php echo $channel==='email'?'selected':''; ?>>Email</option>
      </select>
      <input type="text" name="q" value="<?php echo h($q); ?>" class="form-control form-control-sm me-2" placeholder="Search template/client/payload">
      <button class="btn btn-sm btn-outline-primary"><i class="bi bi-search"></i> Filter</button>
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
            <th style="width:80px;">ID</th>
            <th style="width:90px;">Client</th>
            <th style="width:90px;">Channel</th>
            <th>Template</th>
            <th>Payload</th>
            <th style="width:130px;">Status</th>
            <th style="width:160px;">Times</th>
            <th style="width:160px;">Actions</th>
          </tr>
          </thead>
          <tbody>
          <?php if(!$rows): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">No notifications.</td></tr>
          <?php else: foreach($rows as $n): ?>
            <tr>
              <td class="text-muted">#<?php echo (int)$n['id']; ?></td>
              <td>#<?php echo (int)$n['client_id']; ?></td>
              <td><span class="badge bg-<?php echo $n['channel']==='sms'?'info':'secondary'; ?>"><?php echo h($n['channel']); ?></span></td>
              <td>
                <div class="fw-semibold"><?php echo h($n['template_key']); ?></div>
                <?php if(!empty($n['last_error'])): ?>
                  <div class="small text-danger">Error: <?php echo h((string)$n['last_error']); ?></div>
                <?php endif; ?>
              </td>
              <td class="small"><code class="d-inline-block text-wrap" style="max-width:360px;"><?php echo h((string)$n['payload_json']); ?></code></td>
              <td>
                <?php
                  $badge='secondary'; if($n['status']==='queued') $badge='warning text-dark'; elseif($n['status']==='sent') $badge='success'; elseif($n['status']==='failed') $badge='danger';
                ?>
                <span class="badge bg-<?php echo $badge; ?>"><?php echo h($n['status']); ?></span>
                <div class="small text-muted">Retry: <?php echo (int)$n['retries']; ?></div>
              </td>
              <td class="small">
                <div>Queued: <?php echo h((string)$n['created_at']); ?></div>
                <div>Send after: <?php echo h((string)$n['send_after']); ?></div>
                <div>Sent: <?php echo h((string)$n['sent_at'] ?? '—'); ?></div>
              </td>
              <td>
                <div class="btn-group btn-group-sm">
                  <form method="post">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="requeue">
                    <input type="hidden" name="id" value="<?php echo (int)$n['id']; ?>">
                    <button class="btn btn-outline-primary"><i class="bi bi-arrow-repeat"></i> Requeue</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
      <div class="small text-muted">Showing <?php echo count($rows); ?> of <?php echo (int)$total; ?> item(s)</div>
      <nav>
        <?php
          $pages = max(1,(int)ceil($total/$per)); $page=min($page,$pages);
          $qs=function($o){ $p=array_merge($_GET,$o); return '?'.http_build_query($p); };
        ?>
        <ul class="pagination pagination-sm mb-0">
          <li class="page-item <?php echo $page<=1?'disabled':''; ?>"><a class="page-link" href="<?php echo $page<=1?'#':h($qs(['page'=>$page-1])); ?>">‹</a></li>
          <li class="page-item disabled"><span class="page-link">Page <?php echo $page; ?> / <?php echo $pages; ?></span></li>
          <li class="page-item <?php echo $page>=$pages?'disabled':''; ?>"><a class="page-link" href="<?php echo $page>=$pages?'#':h($qs(['page'=>$page+1])); ?>">›</a></li>
        </ul>
      </nav>
    </div>
  </div>
</div>
<?php require_once $ROOT . '/partials/partials_footer.php';
