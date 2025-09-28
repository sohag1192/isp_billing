<?php
// /public/portal/ticket_view.php
// Client Portal — Ticket details + replies (UI + sidebar + CSRF + 2s live polling)

declare(strict_types=1);
require_once __DIR__ . '/../../app/portal_require_login.php';
require_once __DIR__ . '/../../app/db.php';

function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* -------- schema helpers -------- */
function col_exists(PDO $pdo, string $tbl, string $col): bool {
  try{ $st=$pdo->prepare("SHOW COLUMNS FROM `$tbl` LIKE ?"); $st->execute([$col]); return (bool)$st->fetchColumn(); }
  catch(Throwable $e){ return false; }
}
$has_status_tk  = col_exists($pdo,'tickets','status');
$has_created_tk = col_exists($pdo,'tickets','created_at');
$has_updated_tk = col_exists($pdo,'tickets','updated_at');
$has_created_rp = col_exists($pdo,'ticket_replies','created_at');

/* -------- inputs -------- */
$client_id = (int) portal_client_id();
$ticket_id = max(1, (int)($_GET['id'] ?? 0));

/* -------- fetch ticket (ownership enforced) -------- */
$sql = "SELECT * FROM tickets WHERE id=? AND client_id=? LIMIT 1";
$st  = $pdo->prepare($sql);
$st->execute([$ticket_id, $client_id]);
$ticket = $st->fetch(PDO::FETCH_ASSOC);

if (!$ticket) {
  http_response_code(404);
  ?>
  <!doctype html><html lang="en"><head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Ticket not found</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  </head><body>
  <div class="container py-5">
    <div class="alert alert-danger"><strong>Ticket not found</strong> or you don't have access.</div>
    <a class="btn btn-outline-secondary" href="/public/portal/tickets.php">Back to Tickets</a>
  </div></body></html>
  <?php
  exit;
}

/* -------- quick JSON poll endpoint (2s AJAX) -------- */
if (isset($_GET['poll'])) {
  header('Content-Type: application/json; charset=utf-8');
  $sinceId = max(0, (int)($_GET['since'] ?? 0));

  $rpCols = "id, ticket_id, user_type, message".($has_created_rp?", created_at":"");
  $sqlRp  = "SELECT $rpCols FROM ticket_replies WHERE ticket_id=? AND id>? ORDER BY id ASC";
  $rp     = $pdo->prepare($sqlRp);
  $rp->execute([$ticket_id, $sinceId]);
  $rows   = $rp->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $out = [
    'status'     => (string)($ticket['status'] ?? ''),
    'updated_at' => $has_updated_tk ? (string)($ticket['updated_at'] ?? '') : null,
    'replies'    => array_map(function($r){
      return [
        'id'        => (int)$r['id'],
        'user_type' => (string)$r['user_type'],
        'message'   => (string)$r['message'],
        'created_at'=> $r['created_at'] ?? null,
      ];
    }, $rows),
  ];
  echo json_encode($out);
  exit;
}

/* -------- csrf -------- */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['csrf_ticket_view'])) { $_SESSION['csrf_ticket_view'] = bin2hex(random_bytes(32)); }
$CSRF = $_SESSION['csrf_ticket_view'];

/* -------- handle post (reply / status change) -------- */
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $tok = (string)($_POST['_token'] ?? '');
  if (!hash_equals($_SESSION['csrf_ticket_view'] ?? '', $tok)) {
    $errors[] = 'Invalid form token.';
  } else {
    $action = (string)($_POST['action'] ?? 'reply');
    if ($action === 'reply') {
      $msg = trim((string)($_POST['message'] ?? ''));
      if ($msg === '' || mb_strlen($msg) < 2) {
        $errors[] = 'Message is required (min 2 chars).';
      } else {
        $ins = $pdo->prepare("INSERT INTO ticket_replies (ticket_id, user_type, message) VALUES (?, 'client', ?)");
        $ins->execute([$ticket_id, $msg]);
        if ($has_updated_tk) {
          $pdo->prepare("UPDATE tickets SET updated_at=NOW() WHERE id=?")->execute([$ticket_id]);
        }
        // rotate token + PRG
        $_SESSION['csrf_ticket_view'] = bin2hex(random_bytes(32));
        header("Location: /public/portal/ticket_view.php?id={$ticket_id}#last");
        exit;
      }
    } elseif ($action === 'status' && $has_status_tk) {
      $new = strtolower(trim((string)($_POST['new_status'] ?? '')));
      if (in_array($new, ['open','pending','resolved','closed'], true)) {
        $pdo->prepare("UPDATE tickets SET status=?".($has_updated_tk?", updated_at=NOW()":"")." WHERE id=? AND client_id=?")
            ->execute([$new, $ticket_id, $client_id]);
        $_SESSION['csrf_ticket_view'] = bin2hex(random_bytes(32));
        header("Location: /public/portal/ticket_view.php?id={$ticket_id}");
        exit;
      }
    }
  }
}

/* -------- fetch replies (initial render) -------- */
$rpCols = "id, ticket_id, user_type, message".($has_created_rp?", created_at":"");
$rp = $pdo->prepare("SELECT $rpCols FROM ticket_replies WHERE ticket_id=? ORDER BY ".($has_created_rp?'created_at, id':'id')." ASC");
$rp->execute([$ticket_id]);
$replies = $rp->fetchAll(PDO::FETCH_ASSOC) ?: [];
$last_id = 0; foreach($replies as $r){ if((int)$r['id']>$last_id) $last_id=(int)$r['id']; }

/* -------- ui helpers -------- */
function status_badge(string $s): string {
  $k = strtolower(trim($s));
  return match($k){
    'open'     => 'badge text-bg-primary',
    'pending'  => 'badge text-bg-warning text-dark',
    'resolved' => 'badge text-bg-success',
    'closed'   => 'badge text-bg-secondary',
    default    => 'badge text-bg-light text-dark'
  };
}
$badge = status_badge((string)($ticket['status'] ?? ''));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ticket #<?= (int)$ticket['id'] ?> — <?= h($ticket['subject'] ?? '') ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  body{ background: linear-gradient(180deg,#f7f9fc 0%, #eef3ff 100%); }
  .navbar-dark { background: linear-gradient(90deg, #111 0%, #1b1f2a 100%); }
  .page-wrap{ display:flex; }
  .sidebar-wrap{ min-width:260px; background:linear-gradient(180deg,#eef5ff,#ffffff); border-right:1px solid #e9edf3; }
  .sidebar-inner{ padding:16px; position:sticky; top:0; height:100vh; overflow:auto; }
  .content{ flex:1; min-width:0; padding:1rem; }
  .card-soft{ border:1px solid #e9edf3; border-radius:14px; box-shadow:0 10px 30px rgba(0,0,0,.05); }
  .card-header.soft{ background: linear-gradient(90deg, rgba(99,91,255,.12), rgba(34,166,240,.12)); border-bottom: 1px solid #e9edf8; }
  .bubble{ border:1px solid #e9edf3; border-radius:12px; padding:.75rem .9rem; background:#fff; }
  .bubble.admin{ background:#f8fafc; }
  .meta{ color:#6b7280; font-size:.85rem; }
  .msg pre{ white-space:pre-wrap; word-wrap:break-word; margin:0; }
</style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark">
  <div class="container">
    <a class="navbar-brand fw-bold" href="/public/portal/index.php">
      <i class="bi bi-life-preserver me-1"></i> Support
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nv">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div id="nv" class="collapse navbar-collapse justify-content-end">
      <ul class="navbar-nav">
        <li class="nav-item"><a class="nav-link" href="/public/portal/tickets.php"><i class="bi bi-card-list"></i> My Tickets</a></li>
        <li class="nav-item"><a class="nav-link" href="/public/portal/ticket_new.php"><i class="bi bi-plus-circle"></i> New Ticket</a></li>
      </ul>
    </div>
  </div>
</nav>

<div class="page-wrap">
  <?php
    // Sidebar include (portal_sidebar.php → sidebar.php)
    $sb1 = __DIR__ . '/portal_sidebar.php';
    $sb2 = __DIR__ . '/sidebar.php';
    echo '<div class="sidebar-wrap d-none d-md-block"><div class="sidebar-inner">';
    if (is_file($sb1)) include $sb1; elseif (is_file($sb2)) include $sb2;
    echo '</div></div>';
  ?>

  <div class="content">
    <div class="container-fluid px-0">

      <?php if ($errors): ?>
        <div class="alert alert-danger">
          <div class="fw-semibold mb-1"><i class="bi bi-x-circle"></i> Please fix the following:</div>
          <ul class="mb-0"><?php foreach($errors as $e){ echo '<li>'.h($e).'</li>'; } ?></ul>
        </div>
      <?php endif; ?>

      <div class="card card-soft mb-3">
        <div class="card-header soft d-flex align-items-center justify-content-between">
          <div class="d-flex align-items-center gap-2">
            <span class="fw-bold"><i class="bi bi-ticket-detailed me-1"></i> Ticket #<?= (int)$ticket['id'] ?></span>
            <span id="statusBadge" class="<?= h($badge) ?>"><?= h($ticket['status']!==''?ucfirst($ticket['status']):'—') ?></span>
          </div>
          <div class="d-flex align-items-center gap-2">
            <?php if ($has_created_tk): ?>
              <span class="text-muted small"><i class="bi bi-clock-history me-1"></i><?= h($ticket['created_at'] ?? '') ?></span>
            <?php endif; ?>
            <?php if ($has_updated_tk): ?>
              <span id="updatedAt" class="text-muted small"><i class="bi bi-arrow-repeat me-1"></i><?= h($ticket['updated_at'] ?? '') ?></span>
            <?php endif; ?>
            <a href="/public/portal/tickets.php" class="btn btn-sm btn-outline-secondary">
              <i class="bi bi-arrow-left"></i> Back
            </a>
          </div>
        </div>
        <div class="card-body">
          <h5 class="mb-2"><?= h($ticket['subject'] ?? '') ?></h5>
          <div class="bubble msg mb-3"><pre><?= h($ticket['message'] ?? '') ?></pre></div>

          <?php if ($has_status_tk): ?>
            <form method="post" class="d-inline-flex align-items-center gap-2 mb-3" id="statusForm">
              <input type="hidden" name="_token" value="<?= h($CSRF) ?>">
              <input type="hidden" name="action" value="status">
              <label class="form-label mb-0 small text-muted">Change status:</label>
              <?php $cur = strtolower((string)($ticket['status'] ?? '')); ?>
              <select name="new_status" class="form-select form-select-sm" style="width:auto">
                <?php foreach(['open','pending','resolved','closed'] as $opt): ?>
                  <option value="<?= h($opt) ?>" <?= $cur===$opt?'selected':'' ?>><?= ucfirst($opt) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-repeat"></i> Update</button>
            </form>
          <?php endif; ?>

          <hr>

          <!-- Replies -->
          <div class="mb-3">
            <div class="fw-bold mb-2"><i class="bi bi-chat-dots me-1"></i> Conversation</div>
            <div id="convList" data-last-id="<?= (int)$last_id ?>">
              <?php if (!$replies): ?>
                <div class="text-muted">No replies yet.</div>
              <?php else: foreach ($replies as $r): ?>
                <?php $isAdmin = (strtolower((string)($r['user_type'] ?? '')) === 'admin'); ?>
                <div class="mb-2 <?= $isAdmin ? 'bubble admin' : 'bubble' ?>" data-rid="<?= (int)$r['id'] ?>">
                  <div class="d-flex justify-content-between">
                    <div class="fw-semibold"><?= $isAdmin ? 'Support' : 'You' ?></div>
                    <div class="meta"><?= h($r['created_at'] ?? '') ?></div>
                  </div>
                  <div class="msg mt-1"><pre><?= h($r['message'] ?? '') ?></pre></div>
                </div>
              <?php endforeach; endif; ?>
              <div id="last"></div>
            </div>
          </div>

          <!-- Reply form -->
          <div class="mt-3">
            <form method="post" id="replyForm">
              <input type="hidden" name="_token" value="<?= h($CSRF) ?>">
              <input type="hidden" name="action" value="reply">
              <label class="form-label">Your Reply</label>
              <textarea name="message" class="form-control" rows="4" maxlength="5000" required placeholder="Write your reply..."></textarea>
              <div class="d-flex align-items-center gap-2 mt-2">
                <button class="btn btn-primary" id="replyBtn"><i class="bi bi-send"></i> Send Reply</button>
                <div id="replySpin" class="spinner-border spinner-border-sm text-primary" role="status" style="display:none"></div>
              </div>
            </form>
          </div>

        </div>
      </div>

    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// prevent double-submit
const f = document.getElementById('replyForm');
const b = document.getElementById('replyBtn');
const s = document.getElementById('replySpin');
f?.addEventListener('submit', () => { b.disabled = true; s.style.display='inline-block'; });

// ===== 2s live polling (replies + status) =====
const convList    = document.getElementById('convList');
let   lastId      = Number(convList?.dataset.lastId || 0);
const statusBadge = document.getElementById('statusBadge');
const updatedAtEl = document.getElementById('updatedAt');

function esc(t){ const d=document.createElement('div'); d.textContent=String(t??''); return d.textContent; }
function statusBadgeClass(s){
  const k=(s||'').toLowerCase();
  if(k==='open') return 'badge text-bg-primary';
  if(k==='pending') return 'badge text-bg-warning text-dark';
  if(k==='resolved') return 'badge text-bg-success';
  if(k==='closed') return 'badge text-bg-secondary';
  return 'badge text-bg-light text-dark';
}
function atBottom(){
  const px = 50;
  return (window.innerHeight + window.scrollY) >= (document.body.scrollHeight - px);
}
function appendReply(r){
  const isAdmin = String(r.user_type||'').toLowerCase()==='admin';
  const wrap = document.createElement('div');
  wrap.className = 'mb-2 ' + (isAdmin ? 'bubble admin' : 'bubble');
  wrap.setAttribute('data-rid', String(r.id));
  wrap.innerHTML =
    '<div class="d-flex justify-content-between">' +
      '<div class="fw-semibold">'+ (isAdmin ? 'Support' : 'You') +'</div>' +
      '<div class="meta">'+ esc(r.created_at||'') +'</div>' +
    '</div>' +
    '<div class="msg mt-1"><pre>'+ esc(r.message||'') +'</pre></div>';
  convList.insertBefore(wrap, document.getElementById('last'));
}

function poll(){
  if (document.hidden) return;
  fetch('<?= '/public/portal/ticket_view.php?id='.$ticket_id.'&poll=1' ?>' + '&since=' + lastId, {cache:'no-store'})
    .then(r => r.ok ? r.json() : Promise.resolve({}))
    .then(j => {
      // status update
      if (j.status !== undefined && statusBadge){
        statusBadge.textContent = (j.status ? (j.status[0].toUpperCase()+j.status.slice(1)) : '—');
        statusBadge.className = statusBadgeClass(j.status);
      }
      if (j.updated_at && updatedAtEl){
        updatedAtEl.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>' + esc(j.updated_at);
      }

      // replies (append new)
      if (Array.isArray(j.replies) && j.replies.length){
        const keepBottom = atBottom();
        for (const r of j.replies){
          appendReply(r);
          if (Number(r.id) > lastId) lastId = Number(r.id);
        }
        convList.dataset.lastId = String(lastId);
        if (keepBottom) document.getElementById('last')?.scrollIntoView({behavior:'smooth', block:'end'});
      }
    })
    .catch(()=>{ /* ignore network errors silently */ });
}

setInterval(poll, 2000);
document.addEventListener('DOMContentLoaded', () => {
  const last = document.getElementById('last');
  // লে-আউট রেডি হলে জাম্প-টু-বটম
  requestAnimationFrame(() => {
    if (last) last.scrollIntoView({ behavior: 'auto', block: 'end' });
    else window.scrollTo({ top: document.body.scrollHeight, behavior: 'auto' });
  });
});
</script>
</body>
</html>
