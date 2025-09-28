<?php
// /tg/dashboard.php
// UI: English; Comments: বাংলা — সাবস্ক্রাইবার লিস্ট + লিংক জেনারেট + টেস্ট সেন্ড + কিউ স্ট্যাটাস
declare(strict_types=1);
error_reporting(E_ALL); ini_set('display_errors','0'); ini_set('log_errors','1');

$ROOT = dirname(__DIR__, 1);
require_once $ROOT.'/app/require_login.php';
require_once $ROOT.'/app/db.php';
require_once __DIR__.'/telegram.php';
$acl_file = $ROOT.'/app/acl.php'; if (is_file($acl_file)) require_once $acl_file;
if (function_exists('require_perm')) { require_perm('notify.view'); }

if (session_status()===PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));
$CSRF=$_SESSION['csrf'];
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES,'UTF-8'); }

$pdo=db(); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);

$CT = tg_pick_tbl($pdo, ['clients','customers','subscribers']);
$C = $CT ? tg_pick_client_cols($pdo,$CT) : ['id'=>'id','name'=>'name','pppoe'=>null];

$cfg=tg_cfg(); $botUser=h($cfg['bot_user']);
$baseLink="https://t.me/{$botUser}?start=";

// POST actions
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!hash_equals($CSRF,(string)($_POST['csrf']??''))) { $_SESSION['flash_error']='Invalid CSRF.'; header('Location: /tg/dashboard.php'); exit; }

  $act = $_POST['act'] ?? '';
  if ($act==='gen_link') {
    $cid=(int)($_POST['client_id'] ?? 0);
    if ($cid>0) {
      $token = tg_link_create($pdo,$cid);
      $_SESSION['flash_success'] = $token ? "Link created" : "Failed";
      $_SESSION['gen_link'] = $token ? ($baseLink.$token) : '';
    }
    header('Location: /tg/dashboard.php'); exit;
  }
  if ($act==='test_send') {
    $cid=(int)($_POST['client_id'] ?? 0);
    if ($cid>0) {
      $ok = tg_queue($pdo,$cid,'generic',['message'=>'This is a test notification.'],'test-'.date('Y-m-d')."-c{$cid}");
      $_SESSION['flash_success'] = $ok ? "Queued test message for #$cid" : "Queue failed (gap or error)";
    }
    header('Location: /tg/dashboard.php'); exit;
  }
  if ($act==='requeue' && isset($_POST['id'])) {
    if (function_exists('require_perm')) { require_perm('notify.requeue'); }
    $id=(int)$_POST['id'];
    $st=$pdo->prepare("UPDATE telegram_queue SET status='queued', retries=0, send_after=NOW(), last_error=NULL WHERE id=?");
    $st->execute([$id]); $_SESSION['flash_success']="Requeued #$id";
    header('Location: /tg/dashboard.php'); exit;
  }
}

// Fetch subscribers
$q = trim((string)($_GET['q'] ?? ''));
$where=[]; $args=[];
if ($q!=='' && $CT) {
  $like='%'.$q.'%';
  $cond=["CAST(s.client_id AS CHAR) LIKE ?"];
  $cond[]="c.`{$C['name']}` LIKE ?";
  if ($C['pppoe']) $cond[]="c.`{$C['pppoe']}` LIKE ?";
  $where[]='('.implode(' OR ',$cond).')';
  $args=array_merge($args,array_fill(0,count($cond),$like));
}
$sql="SELECT s.*, c.`{$C['name']}` AS name".($C['pppoe']?", c.`{$C['pppoe']}` AS pppoe":"")."
      FROM telegram_subscribers s
      LEFT JOIN `$CT` c ON c.`{$C['id']}` = s.client_id ".
      ($where?(' WHERE '.implode(' AND ',$where)):'').
      " ORDER BY s.id DESC LIMIT 200";
$subs=$pdo->prepare($sql); $subs->execute($args);
$subs=$subs->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Queue summary & latest
$sum=$pdo->query("SELECT status, COUNT(1) cnt FROM telegram_queue GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
$latest=$pdo->query("SELECT * FROM telegram_queue ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC) ?: [];

require_once $ROOT.'/partials/partials_header.php'; ?>
<div class="container-fluid py-3">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0"><i class="bi bi-telegram"></i> Telegram Notifications</h4>
    <form class="d-flex" method="get">
      <input type="text" class="form-control form-control-sm me-2" name="q" value="<?php echo h($q); ?>" placeholder="Search client/name/pppoe">
      <button class="btn btn-sm btn-outline-primary"><i class="bi bi-search"></i> Search</button>
    </form>
  </div>

  <?php if(!empty($_SESSION['flash_error'])): ?><div class="alert alert-danger py-2"><?php echo h($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></div><?php endif; ?>
  <?php if(!empty($_SESSION['flash_success'])): ?><div class="alert alert-success py-2"><?php echo h($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?></div><?php endif; ?>
  <?php if(!empty($_SESSION['gen_link'])): ?>
    <div class="alert alert-info py-2"><strong>Client Link:</strong> <a target="_blank" href="<?php echo h($_SESSION['gen_link']); ?>"><?php echo h($_SESSION['gen_link']); ?></a><?php unset($_SESSION['gen_link']); ?></div>
  <?php endif; ?>

  <div class="row g-3 mb-3">
    <div class="col-md-4">
      <div class="card shadow-sm border-0">
        <div class="card-body">
          <div class="d-flex align-items-center">
            <div class="me-3"><i class="bi bi-robot fs-3"></i></div>
            <div>
              <div class="text-muted small">Bot</div>
              <div class="fw-semibold">@<?php echo $botUser; ?></div>
              <div class="small text-muted">Set Webhook: <code>/tg/webhook.php?secret=YOUR_SECRET</code></div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-md-8">
      <div class="card shadow-sm border-0">
        <div class="card-body">
          <div class="d-flex justify-content-between">
            <div>
              <div class="text-muted small">Queue Summary</div>
              <div class="fw-semibold">Queued: <?php echo (int)($sum['queued'] ?? 0); ?> • Sent: <?php echo (int)($sum['sent'] ?? 0); ?> • Failed: <?php echo (int)($sum['failed'] ?? 0); ?></div>
            </div>
            <form class="d-flex" method="post">
              <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
              <input type="hidden" name="act" value="gen_link">
              <input type="number" class="form-control form-control-sm me-2" name="client_id" placeholder="Client ID" required>
              <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-link-45deg"></i> Generate Link</button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header bg-light fw-semibold">Subscribers</div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th style="width:90px;">ID</th>
              <th style="width:90px;">Client</th>
              <th>Name</th>
              <th style="width:150px;">PPPoE</th>
              <th style="width:160px;">Telegram</th>
              <th style="width:160px;" class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if(!$subs): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">No subscribers yet.</td></tr>
          <?php else: foreach($subs as $s): ?>
            <tr>
              <td class="text-muted">#<?php echo (int)$s['id']; ?></td>
              <td>#<?php echo (int)$s['client_id']; ?></td>
              <td class="fw-semibold"><?php echo h((string)($s['name'] ?? '')); ?></td>
              <td><?php echo h((string)($s['pppoe'] ?? '')); ?></td>
              <td>
                <div><code><?php echo h((string)$s['username'] ?? ''); ?></code></div>
                <div class="small text-muted">chat_id: <?php echo (int)$s['chat_id']; ?></div>
              </td>
              <td class="text-end">
                <form method="post" class="d-inline">
                  <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                  <input type="hidden" name="act" value="test_send">
                  <input type="hidden" name="client_id" value="<?php echo (int)$s['client_id']; ?>">
                  <button class="btn btn-sm btn-outline-primary"><i class="bi bi-send"></i> Test Send</button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header bg-light fw-semibold">Latest Queue</div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead class="table-light"><tr>
            <th style="width:80px;">ID</th>
            <th style="width:80px;">Client</th>
            <th>Template</th>
            <th>Payload</th>
            <th style="width:120px;">Status</th>
            <th style="width:220px;">Times</th>
            <th style="width:160px;">Action</th>
          </tr></thead>
          <tbody>
          <?php
            $latest=$pdo->query("SELECT * FROM telegram_queue ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if(!$latest): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">Empty.</td></tr>
          <?php else: foreach($latest as $n): ?>
            <tr>
              <td class="text-muted">#<?php echo (int)$n['id']; ?></td>
              <td>#<?php echo (int)$n['client_id']; ?></td>
              <td class="fw-semibold"><?php echo h((string)$n['template_key']); ?></td>
              <td class="small"><code class="text-wrap d-inline-block" style="max-width:360px;"><?php echo h((string)$n['payload_json']); ?></code></td>
              <td>
                <?php $c=$n['status']==='sent'?'success':($n['status']==='failed'?'danger':'warning text-dark'); ?>
                <span class="badge bg-<?php echo $c; ?>"><?php echo h((string)$n['status']); ?></span>
                <div class="small text-muted">Retries: <?php echo (int)$n['retries']; ?></div>
              </td>
              <td class="small">
                <div>Queued: <?php echo h((string)$n['created_at']); ?></div>
                <div>Send after: <?php echo h((string)$n['send_after']); ?></div>
                <div>Sent: <?php echo h((string)($n['sent_at'] ?? '—')); ?></div>
              </td>
              <td>
                <form method="post" class="d-inline">
                  <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                  <input type="hidden" name="act" value="requeue">
                  <input type="hidden" name="id" value="<?php echo (int)$n['id']; ?>">
                  <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-repeat"></i> Requeue</button>
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
<?php require_once $ROOT.'/partials/partials_footer.php';
