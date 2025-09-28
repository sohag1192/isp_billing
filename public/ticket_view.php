<?php
// /admin/ticket_view.php
// Admin Ticket View — live auto-refresh (2s), always scroll-to-bottom, timestamp on right

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------- schema helpers ---------- */
function col_exists(PDO $pdo, string $tbl, string $col): bool {
  try{
    $st=$pdo->prepare("SHOW COLUMNS FROM `$tbl` LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetchColumn();
  }catch(Throwable $e){ return false; }
}
$has_created_tk = col_exists($pdo,'tickets','created_at');
$has_updated_tk = col_exists($pdo,'tickets','updated_at');
$has_created_rp = col_exists($pdo,'ticket_replies','created_at');

/* ---------- load ticket ---------- */
$ticket_id = max(1, (int)($_GET['id'] ?? 0));
$st = $pdo->prepare("
  SELECT t.*, c.name AS client_name
  FROM tickets t
  LEFT JOIN clients c ON c.id = t.client_id
  WHERE t.id=? LIMIT 1
");
$st->execute([$ticket_id]);
$ticket = $st->fetch(PDO::FETCH_ASSOC);
if (!$ticket) { http_response_code(404); die("Ticket not found"); }

/* ---------- lightweight poll endpoint ---------- */
/* GET /admin/ticket_view.php?id=...&poll=1&since=<lastReplyId> */
if (isset($_GET['poll'])) {
  header('Content-Type: application/json; charset=utf-8');

  $sinceId = max(0, (int)($_GET['since'] ?? 0));

  $cols = "id, ticket_id, user_type, message".($has_created_rp?", created_at":"");
  $rp = $pdo->prepare("SELECT $cols FROM ticket_replies WHERE ticket_id=? AND id>? ORDER BY id ASC");
  $rp->execute([$ticket_id, $sinceId]);
  $rows = $rp->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // latest status/updated_at
  $st2 = $pdo->prepare("SELECT status".($has_updated_tk?", updated_at":"")." FROM tickets WHERE id=?");
  $st2->execute([$ticket_id]);
  $meta = $st2->fetch(PDO::FETCH_ASSOC) ?: [];

  echo json_encode([
    'status'     => (string)($meta['status'] ?? $ticket['status'] ?? ''),
    'updated_at' => $has_updated_tk ? (string)($meta['updated_at'] ?? '') : null,
    'replies'    => array_map(function($r){
      return [
        'id'        => (int)$r['id'],
        'user_type' => (string)$r['user_type'],
        'message'   => (string)$r['message'],
        'created_at'=> $r['created_at'] ?? null,
      ];
    }, $rows),
  ]);
  exit;
}

/* ---------- handle POST (status update / admin reply) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (isset($_POST['message']) && trim($_POST['message'])) {
    $msg = trim((string)$_POST['message']);
    $ins = $pdo->prepare("INSERT INTO ticket_replies (ticket_id, user_type, message) VALUES (?, 'admin', ?)");
    $ins->execute([$ticket_id, $msg]);
    if ($has_updated_tk) {
      $pdo->prepare("UPDATE tickets SET updated_at=NOW() WHERE id=?")->execute([$ticket_id]);
    }
  }
  if (isset($_POST['status'])) {
    $status = (string)$_POST['status'];
    $upd = $pdo->prepare("UPDATE tickets SET status=?".($has_updated_tk?", updated_at=NOW()":"")." WHERE id=?");
    $upd->execute([$status, $ticket_id]);
  }
  header("Location: ticket_view.php?id=".$ticket_id);
  exit;
}

/* ---------- load replies for initial render ---------- */
$cols = "id, ticket_id, user_type, message".($has_created_rp?", created_at":"");
$rps = $pdo->prepare("SELECT $cols FROM ticket_replies WHERE ticket_id=? ORDER BY ".($has_created_rp?'created_at, id':'id')." ASC");
$rps->execute([$ticket_id]);
$replies = $rps->fetchAll(PDO::FETCH_ASSOC) ?: [];
$last_id = 0; foreach($replies as $r){ if((int)$r['id']>$last_id) $last_id=(int)$r['id']; }

include __DIR__ . '/../partials/partials_header.php';
?>

<!-- Small scoped CSS for message wrapping -->
<style>
  .msg-body{ white-space:pre-wrap; word-break:break-word; overflow-wrap:anywhere; }
</style>

<div class="container py-4">
  <div class="d-flex align-items-center justify-content-between mb-2">
    <h3 class="mb-0">Ticket #<?= (int)$ticket['id'] ?> — <?= h($ticket['subject']) ?></h3>
    <?php if ($has_updated_tk): ?>
      <span id="updatedAt" class="text-muted small">Updated: <?= h($ticket['updated_at'] ?? '') ?></span>
    <?php endif; ?>
  </div>
  <p class="mb-3">Client: <strong><?= h($ticket['client_name'] ?? '-') ?></strong></p>

  <form method="post" class="mb-3 d-flex align-items-center gap-2">
    <label class="mb-0"><strong>Status:</strong></label>
    <select name="status" id="statusSel" class="form-select w-auto d-inline-block">
      <option value="open"        <?= ($ticket['status'] ?? '')==='open'?'selected':'' ?>>Open</option>
      <option value="in_progress" <?= ($ticket['status'] ?? '')==='in_progress'?'selected':'' ?>>In Progress</option>
      <option value="closed"      <?= ($ticket['status'] ?? '')==='closed'?'selected':'' ?>>Closed</option>
    </select>
    <button class="btn btn-primary btn-sm">Update</button>
  </form>

  <div class="card p-3 mb-3">
    <strong>Initial Message:</strong><br>
    <?= nl2br(h($ticket['message'])) ?>
  </div>

  <div id="replyList" data-last-id="<?= (int)$last_id ?>">
    <?php foreach ($replies as $r): ?>
      <?php $isAdmin = (strtolower((string)$r['user_type'])==='admin'); ?>
      <div class="card p-2 mb-2 <?= $isAdmin ? 'bg-light' : '' ?>" data-rid="<?= (int)$r['id'] ?>">
        <div class="d-flex justify-content-between align-items-start gap-2">
          <div>
            <div class="fw-semibold mb-1"><?= ucfirst(h($r['user_type'])) ?></div>
            <div class="msg-body"><?= nl2br(h($r['message'])) ?></div>
          </div>
          <div class="small text-muted text-nowrap ms-2"><?= h($r['created_at'] ?? '') ?></div>
        </div>
      </div>
    <?php endforeach; ?>
    <div id="last"></div>
  </div>

  <form method="post" class="mt-3">
    <textarea name="message" class="form-control" placeholder="Write your reply..." rows="3" required></textarea>
    <button class="btn btn-success mt-2">Send Reply</button>
  </form>
</div>

<?php include __DIR__ . '/../partials/partials_footer.php'; ?>

<script>
/* ===== helpers ===== */
function esc(t){ const d=document.createElement('div'); d.textContent=String(t??''); return d.textContent; }
function scrollToBottom(smooth = true){
  const last = document.getElementById('last');
  if (last) last.scrollIntoView({ behavior: smooth ? 'smooth' : 'auto', block: 'end' });
  else window.scrollTo({ top: document.body.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
}
function appendReply(r){
  const wrap = document.createElement('div');
  const isAdmin = String(r.user_type||'').toLowerCase()==='admin';
  wrap.className = 'card p-2 mb-2 ' + (isAdmin ? 'bg-light' : '');
  wrap.setAttribute('data-rid', String(r.id));
  wrap.innerHTML =
    '<div class="d-flex justify-content-between align-items-start gap-2">' +
      '<div>' +
        '<div class="fw-semibold mb-1">'+ (isAdmin?'Admin':'Client') +'</div>' +
        '<div class="msg-body">'+ esc(r.message).replace(/\n/g,'<br>') +'</div>' +
      '</div>' +
      '<div class="small text-muted text-nowrap ms-2">'+ esc(r.created_at||'') +'</div>' +
    '</div>';
  const last = document.getElementById('last');
  document.getElementById('replyList').insertBefore(wrap, last);
}

/* ===== init lastId from DOM ===== */
const replyList = document.getElementById('replyList');
let lastId = Number(replyList?.dataset.lastId || 0);
const statusSel = document.getElementById('statusSel');
const updatedAt = document.getElementById('updatedAt');

/* ===== poll (2s) — always scroll to bottom on new replies ===== */
function poll(){
  if (document.hidden) return;
  fetch('ticket_view.php?id=<?= (int)$ticket_id ?>' + '&poll=1&since=' + encodeURIComponent(lastId), {cache:'no-store'})
    .then(r => r.ok ? r.json() : Promise.resolve({}))
    .then(j => {
      // status auto-update if changed elsewhere
      if (j.status && statusSel && statusSel.value !== j.status) {
        const allowed = ['open','in_progress','closed'];
        if (allowed.includes(j.status)) statusSel.value = j.status;
      }
      if (j.updated_at && updatedAt) updatedAt.textContent = 'Updated: ' + j.updated_at;

      // new replies -> append and ALWAYS scroll to bottom
      if (Array.isArray(j.replies) && j.replies.length){
        for (const r of j.replies){
          appendReply(r);
          if (Number(r.id) > lastId) lastId = Number(r.id);
        }
        replyList.dataset.lastId = String(lastId);
        scrollToBottom(true); // ✅ always jump to bottom
      }
    })
    .catch(()=>{ /* ignore */ });
}
setInterval(poll, 2000);

// 🔽 on first load, start from the very bottom (no smooth)
document.addEventListener('DOMContentLoaded', () => {
  requestAnimationFrame(() => scrollToBottom(false));
});
</script>
