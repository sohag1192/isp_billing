<?php
// /public/portal/ticket_new.php
// Client Portal — New Support Ticket (nice UI + sidebar include)

declare(strict_types=1);
require_once __DIR__ . '/../../app/portal_require_login.php';
require_once __DIR__ . '/../../app/db.php';

function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// --------- Load current client (for small header info) ----------
$st = $pdo->prepare("SELECT id, name, client_code, pppoe_id, mobile, email FROM clients WHERE id=? LIMIT 1");
$st->execute([portal_client_id()]);
$client = $st->fetch(PDO::FETCH_ASSOC) ?: ['id'=>0,'name'=>'Customer'];

// --------- CSRF token ----------
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['csrf_ticket'])) { $_SESSION['csrf_ticket'] = bin2hex(random_bytes(32)); }
$CSRF = $_SESSION['csrf_ticket'];

$errors = [];
$notice = '';

// --------- Handle POST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $token   = (string)($_POST['_token'] ?? '');
  if (!hash_equals($_SESSION['csrf_ticket'] ?? '', $token)) {
    http_response_code(400);
    $errors[] = 'Invalid form token. Please try again.';
  } else {
    // Basic sanitize
    $subject  = trim((string)($_POST['subject'] ?? ''));
    $message  = trim((string)($_POST['message'] ?? ''));
    $priority = trim((string)($_POST['priority'] ?? ''));
    $category = trim((string)($_POST['category'] ?? ''));

    if ($subject === '' || mb_strlen($subject) < 3) $errors[] = 'Subject is required (min 3 chars).';
    if ($message === '' || mb_strlen($message) < 5) $errors[] = 'Message is required (min 5 chars).';

    if (!$errors) {
      // Embed meta into message (no DB changes needed)
      $meta = [];
      if ($priority !== '') $meta[] = "Priority: ".$priority;
      if ($category !== '') $meta[] = "Category: ".$category;
      if ($client['pppoe_id'] ?? '') $meta[] = "PPPoE: ".$client['pppoe_id'];
      if ($client['client_code'] ?? '') $meta[] = "Client Code: ".$client['client_code'];
      $metaText = $meta ? (implode(" | ", $meta)."\n\n") : "";

      $fullMessage = $metaText . $message;

      $stmt = $pdo->prepare("INSERT INTO tickets (client_id, subject, message) VALUES (?, ?, ?)");
      $stmt->execute([(int)$client['id'], $subject, $fullMessage]);

      // rotate CSRF
      $_SESSION['csrf_ticket'] = bin2hex(random_bytes(32));

      header("Location: /public/portal/tickets.php?created=1");
      exit;
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>New Support Ticket</title>
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
  .chip{ display:inline-flex; align-items:center; gap:.4rem; border:1px solid #e5e7eb; border-radius:999px; padding:.35rem .65rem; background:#fff; }
  .chip i{ color:#6366f1; }
  .form-hint{ font-size:.85rem; color:#6b7280; }
  .counter{ font-variant-numeric: tabular-nums; }
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
      <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
          <h4 class="mb-1">New Ticket</h4>
          <div class="text-muted small">Create a support request. Our team will get back to you shortly.</div>
        </div>
        <div class="d-none d-sm-flex flex-wrap gap-2">
          <span class="chip"><i class="bi bi-person-badge"></i> <?= h($client['name'] ?? 'Customer') ?></span>
          <span class="chip"><i class="bi bi-hash"></i> <?= h($client['pppoe_id'] ?? '-') ?></span>
          <span class="chip"><i class="bi bi-upc-scan"></i> <?= h($client['client_code'] ?? '-') ?></span>
        </div>
      </div>

      <?php if ($errors): ?>
        <div class="alert alert-danger">
          <div class="fw-semibold mb-1"><i class="bi bi-x-circle"></i> Please fix the following:</div>
          <ul class="mb-0">
            <?php foreach($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php elseif ($notice): ?>
        <div class="alert alert-success"><?= h($notice) ?></div>
      <?php endif; ?>

      <div class="row g-4">
        <!-- Form -->
        <div class="col-lg-8">
          <div class="card card-soft">
            <div class="card-header soft fw-bold d-flex align-items-center justify-content-between">
              <span><i class="bi bi-pencil-square me-1"></i> Ticket Details</span>
              <a href="/public/portal/tickets.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back to Tickets</a>
            </div>
            <div class="card-body">
              <form method="post" id="ticketForm" novalidate>
                <input type="hidden" name="_token" value="<?= h($CSRF) ?>">

                <div class="mb-3">
                  <label class="form-label">Subject <span class="text-danger">*</span></label>
                  <input type="text" name="subject" id="subject" class="form-control" maxlength="120" required
                    placeholder="e.g., Internet is down since morning">
                  <div class="d-flex justify-content-between">
                    <div class="form-hint">Describe the main issue in one sentence.</div>
                    <div class="form-hint counter"><span id="subCount">0</span>/120</div>
                  </div>
                </div>

                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label">Category</label>
                    <select name="category" class="form-select">
                      <option value="">Select…</option>
                      <option>Connectivity</option>
                      <option>Billing</option>
                      <option>Package Change</option>
                      <option>Complaint</option>
                      <option>Other</option>
                    </select>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Priority</label>
                    <select name="priority" class="form-select">
                      <option value="">Normal</option>
                      <option>Low</option>
                      <option>High</option>
                      <option>Urgent</option>
                    </select>
                  </div>
                </div>

                <div class="mt-3 mb-2">
                  <label class="form-label">Message <span class="text-danger">*</span></label>
                  <textarea name="message" id="message" class="form-control" rows="7" maxlength="5000" required
                    placeholder="Write details: when it started, any error shown, router lights, reboot tried, etc."></textarea>
                  <div class="d-flex justify-content-between">
                    <div class="form-hint">Include relevant details (time, screenshots, error messages).</div>
                    <div class="form-hint counter"><span id="msgCount">0</span>/5000</div>
                  </div>
                </div>

                <div class="d-flex align-items-center gap-2 mt-3">
                  <button class="btn btn-success" type="submit" id="submitBtn">
                    <i class="bi bi-send"></i> Submit Ticket
                  </button>
                  <button class="btn btn-outline-secondary" type="reset">
                    <i class="bi bi-eraser"></i> Reset
                  </button>
                  <div id="submitSpin" class="spinner-border spinner-border-sm text-success ms-1" role="status" style="display:none"></div>
                </div>
              </form>
            </div>
          </div>
        </div>

        <!-- Tips / Recent -->
        <div class="col-lg-4">
          <div class="card card-soft mb-3">
            <div class="card-header soft fw-bold"><i class="bi bi-lightbulb me-1"></i> Tips</div>
            <div class="card-body small text-muted">
              <ul class="mb-0 ps-3">
                <li>Urgent issue? Mark priority as <strong>Urgent</strong>.</li>
                <li>Connectivity: mention <em>router lights</em>, reboot tried or not.</li>
                <li>Billing: include invoice no. (e.g. <code>INV-000123</code>).</li>
                <li>Attach screenshots later via ticket reply if needed.</li>
              </ul>
            </div>
          </div>

          <?php
            // last 5 tickets (subject only)
            try {
              $st2 = $pdo->prepare("SELECT id, subject FROM tickets WHERE client_id=? ORDER BY id DESC LIMIT 5");
              $st2->execute([(int)$client['id']]);
              $recent = $st2->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) { $recent = []; }
          ?>
          <div class="card card-soft">
            <div class="card-header soft fw-bold"><i class="bi bi-card-list me-1"></i> Recent Tickets</div>
            <div class="list-group list-group-flush">
              <?php if (!$recent): ?>
                <div class="list-group-item small text-muted">No previous tickets.</div>
              <?php else: foreach ($recent as $t): ?>
                <a class="list-group-item list-group-item-action d-flex align-items-center justify-content-between"
                   href="/public/portal/tickets.php#t<?= (int)$t['id'] ?>">
                  <span class="text-truncate" style="max-width: 230px;"><?= h($t['subject']) ?></span>
                  <i class="bi bi-chevron-right"></i>
                </a>
              <?php endforeach; endif; ?>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// live counters + double submit guard
const sub = document.getElementById('subject');
const msg = document.getElementById('message');
const subCount = document.getElementById('subCount');
const msgCount = document.getElementById('msgCount');
[sub, msg].forEach(el => el && el.addEventListener('input', () => {
  if (el === sub) subCount.textContent = String(el.value.length);
  if (el === msg) msgCount.textContent = String(el.value.length);
}));
const form = document.getElementById('ticketForm');
const btn  = document.getElementById('submitBtn');
const spin = document.getElementById('submitSpin');
form?.addEventListener('submit', () => {
  btn.disabled = true; spin.style.display = 'inline-block';
});
</script>
</body>
</html>
