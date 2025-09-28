<?php
// /public/client_view.php
// Client profile + live PPPoE status + actions (enable/disable/kick) + Auto-control trigger + Pay Bill (advance allowed)
// নোট: UI ইংরেজি; শুধু কমেন্ট বাংলায়

declare(strict_types=1);

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/routeros_api.class.php';

/* ---------------- Security: CSRF token ---------------- */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

/* ---------------- Helpers ---------------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function validateClientId($id){ return (is_numeric($id) && (int)$id > 0) ? (int)$id : 0; }

/* ---------------- Input: client id ---------------- */
$client_id = $_GET['id'] ?? '';
if (!$client_id) { header("Location: /public/clients.php"); exit; }

/* ---------------- DB + schema-awareness ---------------- */
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* (বাংলা) প্যাকেজ/রাউটার টেবিলের নাম কলাম ডাইনামিকলি ঠিক করা */
$pkgCols = $rtCols = [];
try { $pkgCols = $pdo->query("SHOW COLUMNS FROM packages")->fetchAll(PDO::FETCH_COLUMN) ?: []; } catch (Throwable $e) {}
try { $rtCols  = $pdo->query("SHOW COLUMNS FROM routers")->fetchAll(PDO::FETCH_COLUMN)  ?: []; } catch (Throwable $e) {}

$pkgNameParts = [];
foreach (['name','title','package_name'] as $c) { if (in_array($c,$pkgCols,true)) $pkgNameParts[] = "p.`$c`"; }
$PKG_NAME_EXPR = $pkgNameParts ? ('COALESCE('.implode(',', $pkgNameParts).')') : 'NULL';

$rtNameParts = [];
foreach (['name','identity','host','ip'] as $c) { if (in_array($c,$rtCols,true)) $rtNameParts[] = "r.`$c`"; }
$ROUTER_NAME_EXPR = $rtNameParts ? ('COALESCE('.implode(',', $rtNameParts).')') : 'r.id';

/* ---------------- Load client (+package +router) ---------------- */
$sql = "SELECT c.*,
               {$PKG_NAME_EXPR}   AS package_name,
               {$ROUTER_NAME_EXPR} AS router_name,
               r.ip AS router_ip, r.username, r.password, r.api_port
        FROM clients c
        LEFT JOIN packages p ON c.package_id = p.id
        LEFT JOIN routers  r ON c.router_id  = r.id
        WHERE c.pppoe_id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$client_id]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$client) { header("Location: /public/clients.php"); exit; }

/* ---------------- Photo helpers ---------------- */
$photo_url      = trim($client['photo_url'] ?? '');
$client_initial = mb_strtoupper(mb_substr($client['name'] ?? '?', 0, 1, 'UTF-8'));
$m              = trim($client['mobile'] ?? '');

/* ---------------- Live placeholders (AJAX will fill) ---------------- */
$live_ip   = '—';
$last_seen = '—';
$is_online = false;

/* ---------------- Status badges (with Left) ---------------- */
$stVal  = strtolower(trim($client['status'] ?? 'active'));
$isLeft = (int)($client['is_left'] ?? 0) === 1;

if ($isLeft) { $badge='bg-dark'; $stLabel='Left'; }
elseif (in_array($stVal, ['inactive','deactive','disabled','expired','blocked'], true)) { $badge='bg-danger';  $stLabel='Inactive'; }
elseif (in_array($stVal, ['pending','hold'], true)) { $badge='bg-warning text-dark'; $stLabel='Pending';  }
else { $badge='bg-success'; $stLabel='Active'; }

/* ---------------- Ledger badge color ---------------- */
$ledger = (float)($client['ledger_balance'] ?? 0);
if ($ledger > 0) { $ledgerClass='bg-danger';  $ledgerText='Due'; }
elseif ($ledger < 0){ $ledgerClass='bg-success'; $ledgerText='Advance'; }
else { $ledgerClass='bg-secondary'; $ledgerText='Clear'; }

/* ---------------- Payment link (schema-aware invoice lookup + advance fallback) ---------------- */
/* বাংলা: return URL সবসময় relative path রাখব—Host header এর উপর ভরসা নয় */
$current_path = $_SERVER['REQUEST_URI'] ?? ('/public/client_view.php?id='.$pppoe_id);

$payInvoiceId = 0;
try{
  $invCols = $pdo->query("SHOW COLUMNS FROM invoices")->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch(Throwable $e){ $invCols = []; }

/* বাংলা: ১) current month unpaid/partial/due খুঁজি ২) না পেলে latest unpaid/partial  */
if (in_array('billing_month',$invCols,true)) {
  $ms = date('Y-m-01'); $me = date('Y-m-t');
  $q1 = $pdo->prepare("SELECT id FROM invoices 
                       WHERE client_id=? AND billing_month BETWEEN ? AND ?
                         AND LOWER(status) IN ('unpaid','partial','due','partially_paid')
                       ORDER BY id DESC LIMIT 1");
  $q1->execute([$client_id, $ms, $me]);
  $payInvoiceId = (int)($q1->fetchColumn() ?: 0);
} elseif (in_array('month',$invCols,true) && in_array('year',$invCols,true)) {
  $q1 = $pdo->prepare("SELECT id FROM invoices
                       WHERE client_id=? AND month=? AND year=? 
                         AND LOWER(status) IN ('unpaid','partial','due','partially_paid')
                       ORDER BY id DESC LIMIT 1");
  $q1->execute([$client_id, (int)date('n'), (int)date('Y')]);
  $payInvoiceId = (int)($q1->fetchColumn() ?: 0);
}
if ($payInvoiceId <= 0){
  $q2 = $pdo->prepare("SELECT id FROM invoices
                       WHERE client_id=? AND LOWER(status) IN ('unpaid','partial','due','partially_paid')
                       ORDER BY id DESC LIMIT 1");
  $q2->execute([$client_id]);
  $payInvoiceId = (int)($q2->fetchColumn() ?: 0);
}

/* নিরাপদ চেক: ইনভয়েস সত্যিই client_id এর সাথে মেলে কিনা */
$validInvoiceId = 0;
if ($payInvoiceId > 0){
  $chk = $pdo->prepare("SELECT id FROM invoices WHERE id=? AND client_id=? LIMIT 1");
  $chk->execute([$payInvoiceId, $client_id]);
  $validInvoiceId = (int)($chk->fetchColumn() ?: 0);
}

/* link: সব সময় client_id; invoice থাকলে invoice_id যোগ, না থাকলে advance ফ্লো */
$pay_url = '/public/payment_add.php?client_id='.(int)$client_id
         . ($validInvoiceId>0 ? '&invoice_id='.$validInvoiceId : '&purpose=advance')
         . '&return='.urlencode($current_path);

/* ---------------- Header ---------------- */
$page_title = 'Client View';
include __DIR__ . '/../partials/partials_header.php';
?>
<!-- CSRF for JS -->
<meta name="csrf-token" content="<?= h($csrf) ?>">

<style>
/* ==== Light theme ==== */
.card-block{ border:1px solid #e5e7eb; border-radius:.75rem; background:#ffffff; }
.card-block .card-title{ font-weight:700; padding:.65rem .9rem; border-bottom:1px solid #eef1f4; background:#f8f9fa; }

/* Avatar */
.header-avatar{ width:70px; height:70px; border-radius:15%; overflow:hidden; border:1px solid #e5e7eb; background:#f2f4f7; }
.header-avatar img{ width:100%; height:100%; object-fit:cover; display:block; }
.header-avatar .avatar-fallback{ width:100%; height:100%; display:flex; align-items:center; justify-content:center; font-weight:700; color:#5c6b7a; }

.mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }

/* Key/Value table */
.table-kv{ --pad-y:.45rem; --pad-x:.6rem; width:100%; table-layout:fixed; }
.table-kv td{ padding: var(--pad-y) var(--pad-x); vertical-align: middle; }
.table-kv td.k, .table-kv td.v{ white-space: normal; word-break: break-word; overflow-wrap: anywhere; }
.table-kv i.bi{ color:#0d6efd; margin-right:.4rem; vertical-align:-.05rem; }
.table-kv colgroup col:first-child{ width: 42%; }
.table-kv colgroup col:last-child { width: 58%; }

.table-responsive{ border-radius:.5rem; }

/* Small buttons */
.kv-actions .btn, .table-kv .btn{ padding:.2rem .5rem; font-size:.8rem; }

/* Actions / modal */
.card-actions{ background:#f5f6f8; border-top:1px solid #e5e7eb; padding:.65rem .9rem; }
#renewModal .modal-content{ background:#f5f6f8; border:1px solid #e5e7eb; }
#renewModal .modal-header{ background:#f8f9fa; border-bottom-color:#e5e7eb; }
#renewModal .modal-footer{ background:#eef1f4; border-top-color:#e5e7eb; }

/* Toast + Confirm (ARIA friendly) */
.app-confirm-backdrop{ position:fixed; inset:0; background:rgba(0,0,0,.35); display:flex; align-items:center; justify-content:center; z-index:2000; }
.app-confirm-box{ background:#fff; border-radius:12px; width:360px; max-width:92vw; padding:18px; box-shadow:0 10px 30px rgba(0,0,0,.25); }
.app-confirm-title{ font-size:16px; font-weight:600; margin:0 0 6px; }
.app-confirm-text{ font-size:14px; color:#333; margin:0 0 14px; }
.app-confirm-actions{ display:flex; gap:8px; justify-content:center; }
.app-btn{ border:0; border-radius:8px; padding:8px 12px; font-size:14px; cursor:pointer; min-width:110px; }
.app-btn.secondary{ background:#e9ecef; } .app-btn.primary{ background:#0d6efd; color:#fff; }
@media (max-width:480px){ .app-confirm-actions{ flex-direction: column; } .app-btn{ width:100%; } }

.app-toast{ position:fixed; left:50%; top:50%; transform:translate(-50%,-50%); z-index:2100; min-width:280px; max-width:90vw; text-align:center; padding:14px 18px; border-radius:12px; color:#fff; background:#0d6efd; box-shadow:0 16px 40px rgba(0,0,0,.25); }
.app-toast[role="status"]{ aria-live:polite; }
.app-toast.success{ background:#198754 !important; }
.app-toast.error{ background:#dc3545 !important; }
.app-toast.hide{ opacity:0; transform:translate(-50%,-60%); transition:opacity .25s, transform .25s; }
</style>

<div class="container-fluid py-3 text-start">

  <!-- Header -->
  <div class="mb-3 d-flex flex-wrap align-items-center gap-2">
    <div class="d-flex align-items-center gap-2">
      <div class="header-avatar">
        <?php if ($photo_url): ?>
          <img src="<?= h($photo_url) ?>" referrerpolicy="no-referrer" alt="<?= h($client['name'] ?? 'Photo') ?>">
        <?php else: ?>
          <div class="avatar-fallback"><?= h($client_initial) ?></div>
        <?php endif; ?>
      </div>
      <div class="d-flex flex-column">
        <div class="d-flex align-items-center gap-2">
          <i class="bi bi-person-vcard"></i>
          <span class="fw-bold"><?= h($client['name']) ?></span>
          <span class="badge <?= $badge ?>"><?= $stLabel ?></span>
          <!-- name-side online pill; JS will update -->
          <span id="name-online" class="badge bg-secondary" style="background-color:#6c757d"><i class="bi bi-wifi"></i> Offline</span>
        </div>
        <div class="mt-1">
          <span class="badge <?= $ledgerClass ?>">Ledger: <?= number_format($ledger,2) ?> (<?= $ledgerText ?>)</span>
          <?php if (!empty($client['client_code'])): ?>
            <span class="badge bg-light text-dark border">Code: <?= h($client['client_code']) ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="ms-auto d-flex flex-wrap gap-2">
      <a href="/public/clients.php" class="btn btn-light btn-sm"><i class="bi bi-arrow-left"></i> Back</a>
      <a href="/public/client_edit.php?id=<?= (int)$client['id'] ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil-square"></i> Edit Info</a>
      <a href="/public/audit_logs.php?client_id=<?= (int)$client['id'] ?>" class="btn btn-outline-dark btn-sm" target="_blank" rel="noopener"><i class="bi bi-clock-history"></i> Logs</a>
      <?php if (!$isLeft): ?>
        <?php if ($stVal === 'active'): ?>
          <button class="btn btn-danger btn-sm" onclick="changeStatus(this, <?= (int)$client['id'] ?>, 'disable')"><i class="bi bi-x-square"></i> Disable</button>
        <?php else: ?>
          <button class="btn btn-success btn-sm" onclick="changeStatus(this, <?= (int)$client['id'] ?>, 'enable')"><i class="bi bi-file-check"></i> Enable</button>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($isLeft): ?>
    <div class="alert alert-dark d-flex align-items-center" role="alert">
      <i class="bi bi-person-dash me-2"></i>
      This client is marked as <strong class="ms-1">Left</strong>. Router actions are disabled.
    </div>
  <?php endif; ?>

  <div class="row g-3">

    <!-- Account Information -->
    <div class="col-12 col-lg-4">
      <div class="card-block h-100">
        <div class="card-title">Account Information</div>
        <div class="table-responsive p-2">
          <table class="table table-sm align-middle mb-0 table-kv table-borderless">
            <colgroup><col><col></colgroup>
            <tbody>
              <tr><td class="k"><i class="bi bi-upc-scan"></i> Client ID</td><td class="v mono"><?= h($client['id'] ?: '-') ?></td></tr>
              <tr><td class="k"><i class="bi bi-person"></i> Name</td><td class="v"><?= h($client['name'] ?: '-') ?></td></tr>
              <tr><td class="k"><i class="bi bi-geo-alt"></i> Address</td><td class="v"><?= nl2br(h($client['address'] ?: '-')) ?></td></tr>
              <tr>
                <td class="k"><i class="bi bi-telephone"></i> Mobile No.</td>
                <td class="v">
                  <?php if ($m): ?>
                    <span class="me-1"><?= h($m) ?></span><br>
                    <a class="btn btn-outline-secondary btn-sm me-1" href="tel:+88<?= h($m) ?>" title="Call"><i class="bi bi-telephone"></i></a>
                    <a class="btn btn-outline-secondary btn-sm me-1" href="sms:+88<?= h($m) ?>" title="SMS"><i class="bi bi-chat-dots"></i></a>
                    <a class="btn btn-outline-success btn-sm" target="_blank" rel="noopener" href="https://wa.me/+88<?= preg_replace('/\D/','',$m) ?>" title="WhatsApp"><i class="bi bi-whatsapp"></i></a>
                  <?php else: ?>-<?php endif; ?>
                </td>
              </tr>
              <tr><td class="k"><i class="bi bi-envelope"></i> Email</td><td class="v"><?= h($client['email'] ?: '-') ?></td></tr>
              <tr><td class="k"><i class="bi bi-card-list"></i> NID No.</td><td class="v"><?= h($client['nid'] ?? '-') ?></td></tr>
              <tr><td class="k"><i class="bi bi-card-list"></i> DOB</td><td class="v"><?= h($client['dob'] ?? '-') ?></td></tr>
              <tr><td class="k"><i class="bi bi-map"></i> Area</td><td class="v"><?= h($client['area'] ?: '-') ?></td></tr>
              <tr><td class="k"><i class="bi bi-map"></i> Sub Zone</td><td class="v"><?= h($client['sub_zone'] ?? '-') ?></td></tr>
              <tr><td class="k"><i class="bi bi-map"></i> Join Date</td><td class="v"><?= h($client['join_date'] ?? '-') ?></td></tr>
              <tr><td class="k"><i class="bi bi-calendar2-week"></i> Update</td><td class="v"><?= h($client['updated_at'] ?? '-') ?></td></tr>
            </tbody>
          </table>
        </div>
        <div class="card-actions d-flex flex-wrap gap-2">
          <a href="/public/client_edit.php?id=<?= (int)$client['id'] ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil-square"></i> Edit Info</a>
          <?php if ($m): ?><a href="sms:<?= h($m) ?>" class="btn btn-success btn-sm"><i class="bi bi-envelope"></i> Send SMS</a><?php endif; ?>
          <a href="/public/audit_logs.php?client_id=<?= (int)$client['id'] ?>" target="_blank" rel="noopener" class="btn btn-outline-dark btn-sm"><i class="bi bi-clock-history"></i> Logs</a>
        </div>
      </div>
    </div>

    <!-- Billing Information -->
    <div class="col-12 col-lg-4">
      <div class="card-block h-100">
        <div class="card-title">Billing Information</div>
        <div class="table-responsive p-2">
          <table class="table table-sm align-middle mb-0 table-kv table-borderless">
            <colgroup><col><col></colgroup>
            <tbody>
              <tr><td class="k"><i class="bi bi-diagram-3"></i>Conn. Type</td><td class="v mono"><?= strtoupper($client['connection_type'] ?? 'PPPOE') ?></td></tr>
              <tr><td class="k"><i class="bi bi-box2-fill"></i>Package</td><td class="v fw-bold"><?= h($client['package_name'] ?: 'N/A') ?></td></tr>
              <tr><td class="k"><i class="bi bi-cash-coin"></i>Packg Price</td><td class="v"><?= h($client['monthly_bill'] ?: '-') ?></td></tr>
              <tr><td class="k"><i class="bi bi-arrow-repeat"></i>Bill Cycle</td><td class="v">Monthly</td></tr>
              <tr><td class="k"><i class="bi bi-ui-checks"></i>Bill Type</td><td class="v">Prepaid</td></tr>
              <tr>
                <td class="k"><i class="bi bi-flag"></i> Bill Status</td>
                <td class="v">
                  <?php $expired = !empty($client['expiry_date']) && (strtotime($client['expiry_date']) < time()); ?>
                  <?php if ($expired): ?><span class="text-danger fw-bold">Expired</span>
                  <?php else: ?><span class="text-success">Running</span><?php endif; ?>
                  <span class="ms-3"><i class="bi bi-circle-fill <?= ($stVal==='active')?'text-success':'text-danger' ?>"></i> <span class="ms-1"><?= ($stVal==='active')? 'Enabled':'Disabled' ?></span></span>
                </td>
              </tr>
              <tr>
                <td class="k"><i class="bi bi-calendar-date"></i> Expiry Date</td>
                <td class="v">
                  <span><?= h($client['expiry_date'] ?? '-') ?></span>
                  <button class="btn btn-outline-secondary btn-sm ms-1" title="Calendar"><i class="bi bi-calendar3"></i></button>
                </td>
              </tr>
              <tr><td class="k"><i class="bi bi-wallet2"></i> Balance</td><td class="v"><span class="badge <?= $ledgerClass ?>"><?= number_format($ledger,2) ?> (<?= $ledgerText ?>)</span></td></tr>
              <tr><td class="k"><i class="bi bi-person-check"></i>Connect By</td><td class="v"><?= h($client['created_by'] ?? '-') ?></td></tr>
              <tr><td class="k"><i class="bi bi-geo"></i> Location</td><td class="v"><?= h($client['area'] ?: '-') ?></td></tr>
            </tbody>
          </table>
        </div>
        <div class="card-actions d-flex flex-wrap gap-2">
          <!-- Pay Bill: always enabled -->
          <a href="<?= h($pay_url) ?>" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-cash-coin"></i> Pay Bill
          </a>

          <!-- Renew (invoice create) -->
          <button id="btnRenew" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#renewModal" title="Create Invoice">
            <i class="bi bi-receipt"></i> Create Invoice
          </button>

          <a href="/public/client_invoices.php?id=<?= (int)$client['id'] ?>" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-receipt"></i> Invoices</a>
          <a href="/public/client_payments.php?id=<?= (int)$client['id'] ?>" class="btn btn-sm btn-outline-success">
            <i class="bi bi-cash-coin"></i> Payments</a>

          <?php if (!$isLeft): ?>
            <?php if ($stVal==='active'): ?>
              <button class="btn btn-outline-danger btn-sm" onclick="changeStatus(this, <?= (int)$client['id'] ?>,'disable')"><i class="bi bi-x-octagon"></i> Disable</button>
              <button class="btn btn-outline-warning btn-sm" onclick="changeStatus(this, <?= (int)$client['id'] ?>,'kick')"><i class="bi bi-plug"></i> Disconnect</button>
            <?php else: ?>
              <button class="btn btn-outline-success btn-sm" onclick="changeStatus(this, <?= (int)$client['id'] ?>,'enable')"><i class="bi bi-check2-circle"></i> Enable</button>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Server Information -->
    <div class="col-12 col-lg-4">
      <div class="card-block h-100">
        <div class="card-title">Server Information</div>
        <div class="table-responsive p-2">
          <table class="table table-sm align-middle mb-0 table-kv table-borderless">
            <colgroup><col><col></colgroup>
            <tbody>
              <tr>
                <td class="k"><i class="bi bi-hdd-network"></i> Server</td>
                <td class="v"><?= h($client['router_name'] ?: '-') ?></td>
              </tr>
              <tr>
                <td class="k"><i class="bi bi-person-badge"></i> Username</td>
                <td class="v mono">
                  <span id="pppoe-username"><?= h($client['pppoe_id'] ?: '-') ?></span>
                  <button type="button"
                          class="btn btn-outline-secondary btn-sm ms-1 btn-copy"
                          data-copy-el="#pppoe-username"
                          title="Copy Username">
                    <i class="bi bi-clipboard"></i>
                  </button>
                </td>
              </tr>
              <tr>
                <td class="k"><i class="bi bi-key"></i> Password</td>
                <td class="v mono">
                  <?php $pp = $client['pppoe_pass'] ?? ($client['pppoe_password'] ?? ''); // (বাংলা) স্কিমা ভিন্নতা গার্ড ?>
                  <span id="ppp-mask" data-revealed="0"><?= $pp ? str_repeat('•', max(6, strlen($pp))) : '-' ?></span>
                  <?php if ($pp): ?>
                    <button class="btn btn-outline-secondary btn-sm ms-1 btn-copy"
                            data-copy="<?= h($pp) ?>" title="Copy">
                      <i class="bi bi-clipboard"></i>
                    </button>
                    <button class="btn btn-outline-secondary btn-sm ms-1" id="ppp-eye" title="Show/Hide">
                      <i class="bi bi-eye"></i>
                    </button>
                  <?php endif; ?>
                </td>
              </tr>
              <tr>
                <td class="k"><i class="bi bi-ethernet"></i>Router Mac</td>
                <td class="v mono">
                  <span id="router-mac">—</span>
                  <button type="button" id="btn-copy-router" class="btn btn-outline-secondary btn-sm ms-1 btn-copy"
                          data-copy-el="#router-mac" title="Copy Router Mac">
                    <i class="bi bi-clipboard"></i>
                  </button>
                </td>
              </tr>
              <tr>
                <td class="k"><i class="bi bi-ethernet"></i> Active Mac</td>
                <td class="v mono">
                  <span id="active-mac">—</span>
                  <button id="btn-copy-active" class="btn btn-outline-secondary btn-sm ms-1 btn-copy"
                          data-copy-el="#active-mac" title="Copy" style="display:none;">
                    <i class="bi bi-clipboard"></i>
                  </button>
                </td>
              </tr>
              <tr><td class="k"><i class="bi bi-cpu"></i> Vendor</td><td class="v" id="device-vendor">—</td></tr>
              <tr><td class="k"><i class="bi bi-pc-display"></i> IP Address</td><td class="v mono" id="live-ip"><?= h($live_ip) ?></td></tr>
              <tr><td class="k"><i class="bi bi-stopwatch"></i> Uptime</td><td class="v" id="uptime">—</td></tr>
              <tr>
                <td class="k"><i class="bi bi-activity"></i> Status</td>
                <td class="v"><span id="live-status" class="badge <?= $is_online?'bg-success':'bg-danger' ?>"><?= $is_online?'Online':'Offline' ?></span></td>
              </tr>
              <tr><td class="k"><i class="bi bi-alarm"></i>Last Logout</td><td class="v" id="last-seen"><?= h($last_seen) ?></td></tr>
              <tr>
                <td class="k"><i class="bi bi-bar-chart-line"></i> Data Used</td>
                <td class="v"><span id="total-dl">—</span> Download <br> <span id="total-ul">—</span> Upload</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="card-actions d-flex flex-wrap gap-2">
          <a href="/public/client_live_graph.php?id=<?= (int)$client['id'] ?>" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm"><i class="bi bi-graph-up"></i> Live Graph</a>
          <a href="#" class="btn btn-outline-secondary btn-sm"><i class="bi bi-link-45deg"></i> Bind Mac</a>
          <?php if (!$isLeft): ?>
            <?php if ($stVal==='active'): ?>
              <button class="btn btn-outline-warning btn-sm" onclick="changeStatus(this, <?= (int)$client['id'] ?>,'kick')"><i class="bi bi-plug"></i> Disconnect</button>
            <?php else: ?>
              <button class="btn btn-outline-success btn-sm" onclick="changeStatus(this, <?= (int)$client['id'] ?>,'enable')"><i class="bi bi-plug"></i> Connect</button>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div>
</div>

<!-- ===================== RENEW MODAL ===================== -->
<div class="modal fade" id="renewModal" tabindex="-1" aria-labelledby="renewModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" id="renewForm">
      <div class="modal-header">
        <h6 class="modal-title" id="renewModalLabel"><i class="bi bi-arrow-repeat"></i> Renew — <?= h($client['name']) ?> (<?= h($client['client_code']) ?>)</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <div class="row g-2">
          <div class="col-6">
            <label class="form-label">Months</label>
            <select name="months" id="rn_months" class="form-select form-select-sm">
              <?php for($i=1;$i<=12;$i++): ?>
                <option value="<?= $i ?>" <?= $i===1?'selected':'' ?>><?= $i ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label">Amount</label>
            <input type="number" step="0.01" name="amount" id="rn_amount" class="form-control form-control-sm"
                   value="<?= is_numeric($client['monthly_bill']??null)? (0+$client['monthly_bill']) : 0 ?>">
          </div>

          <div class="col-6">
            <label class="form-label">Method</label>
            <select name="method" id="rn_method" class="form-select form-select-sm">
              <option value="Cash">Cash</option>
              <option value="bKash">bKash</option>
              <option value="Nagad">Nagad</option>
              <option value="Bank">Bank</option>
              <option value="Online">Online</option>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label">Invoice Date</label>
            <input type="date" name="invoice_date" id="rn_invoice_date" class="form-control form-control-sm"
                   value="<?= date('Y-m-d') ?>">
          </div>

          <div class="col-12">
            <label class="form-label">Note (optional)</label>
            <input type="text" name="note" id="rn_note" class="form-control form-control-sm" placeholder="e.g. Monthly renewal">
          </div>

          <div class="col-12 mt-2">
            <div class="alert alert-light border d-flex align-items-center gap-2 py-2 mb-0">
              <i class="bi bi-calendar-check text-primary"></i>
              <div>
                <div class="small text-muted">Current Expiry:</div>
                <div class="fw-semibold" id="rn_exp_current"><?= h($client['expiry_date'] ?: '—') ?></div>
              </div>
              <div class="ms-3">
                <div class="small text-muted">New Expiry (est.):</div>
                <div class="fw-semibold text-success" id="rn_exp_new">—</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="modal-footer justify-content-between">
        <span class="small text-muted">Package: <?= h($client['package_name'] ?: 'N/A') ?> • Bill: <?= h($client['monthly_bill'] ?? '0') ?></span>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary btn-sm">Create Invoice & Renew</button>
        </div>
      </div>
    </form>
  </div>
</div>
<!-- =================== /RENEW MODAL =================== -->

<script>
const API_SINGLE = '/api/control.php'; // বাংলা: action endpoint
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';

/* ===== Toast ===== */
function showToast(msg, type='success', timeout=2800){
  const box = document.createElement('div');
  box.className = 'app-toast ' + (type==='success' ? 'success' : 'error');
  box.setAttribute('role','status');
  box.textContent = msg || 'Done';
  document.body.appendChild(box);
  setTimeout(()=> box.classList.add('hide'), timeout-200);
  setTimeout(()=> box.remove(), timeout);
}
/* Restore toast after reload */
document.addEventListener('DOMContentLoaded', ()=>{
  const t = sessionStorage.getItem('toast');
  if (t){ try{ const o=JSON.parse(t); showToast(o.message, o.type||'success', 2800); }catch{} sessionStorage.removeItem('toast'); }
});

/* ===== Confirm dialog ===== */
function customConfirm({title='Confirm', message='Are you sure?', okText='OK', cancelText='Cancel'}){
  return new Promise((resolve)=>{
    const bd = document.createElement('div');
    bd.className = 'app-confirm-backdrop';
    bd.innerHTML = `
      <div class="app-confirm-box" role="dialog" aria-modal="true" aria-label="${title}">
        <div class="app-confirm-title">${title}</div>
        <div class="app-confirm-text">${message}</div>
        <div class="app-confirm-actions">
          <button class="app-btn secondary" data-act="cancel">${cancelText}</button>
          <button class="app-btn primary" data-act="ok">${okText}</button>
        </div>
      </div>`;
    document.body.appendChild(bd);
    const close=(v)=>{ document.removeEventListener('keydown', onKey); bd.remove(); resolve(v); };
    const onKey=(e)=>{ if(e.key==='Escape') close(false); if(e.key==='Enter') close(true); };
    bd.addEventListener('click', e=>{ if(e.target.dataset.act==='ok') close(true); if(e.target.dataset.act==='cancel'||e.target===bd) close(false); });
    document.addEventListener('keydown', onKey);
    setTimeout(()=> bd.querySelector('[data-act="ok"]')?.focus(), 10);
  });
}

/* ===== Enable/Disable/Kick — POST + CSRF ===== */
async function changeStatus(btn, id, action){
  const ok = await customConfirm({
    title: (action==='disable')?'Disable client?':(action==='kick'?'Disconnect client?':'Enable client?'),
    message: `Are you sure you want to ${action} this client?`,
    okText: (action==='disable')?'Disable':'Yes', cancelText: 'Cancel'
  });
  if(!ok) return;

  const oldHTML = btn.innerHTML; btn.disabled = true; btn.innerHTML = '...';

  fetch(API_SINGLE, {
    method: 'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body: new URLSearchParams({ action, id: String(id), csrf_token: CSRF })
  })
    .then(r=>r.json())
    .then(data=>{
      if (data.status === 'success'){
        const msg = data.message || 'Done';
        sessionStorage.setItem('toast', JSON.stringify({message: msg, type:'success'}));
        location.reload();
      } else {
        showToast(data.message || 'Operation failed', 'error', 3000);
        btn.disabled=false; btn.innerHTML=oldHTML;
      }
    })
    .catch(()=>{
      showToast('Request failed', 'error', 3000);
      btn.disabled=false; btn.innerHTML=oldHTML;
    });
}

/* ===== Auto-control trigger — POST + CSRF ===== */
async function autoRecheck(btn, id){
  const ok = await customConfirm({
    title: 'Auto re-evaluate?',
    message: 'Run auto control now based on current ledger balance.',
    okText: 'Run now', cancelText: 'Cancel'
  });
  if(!ok) return;

  const old = btn.innerHTML; btn.disabled = true; btn.innerHTML = '...';

  try{
    const res = await fetch('/api/auto_control_client.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({client_id: String(id), csrf_token: CSRF})
    });
    const j = await res.json();
    if (j.ok){
      sessionStorage.setItem('toast', JSON.stringify({message: j.msg || ('Action: '+(j.action||'done')), type:'success'}));
      location.reload();
    } else {
      showToast(j.msg || 'Auto control failed', 'error', 3000);
      btn.disabled=false; btn.innerHTML=old;
    }
  } catch(e){
    showToast('Request failed', 'error', 3000);
    btn.disabled=false; btn.innerHTML=old;
  }
}

/* ===== Copy ===== */
async function __copyTextRobust(t){
  t = (t || '').trim();
  if (!t || t === '-' || t === '—') throw new Error('empty');
  if (navigator.clipboard && window.isSecureContext !== false) {
    await navigator.clipboard.writeText(t);
    return;
  }
  const ta = document.createElement('textarea');
  ta.value = t; ta.setAttribute('readonly',''); ta.style.position='fixed'; ta.style.opacity='0';
  document.body.appendChild(ta); ta.select(); ta.setSelectionRange(0, t.length);
  const ok = document.execCommand('copy');
  document.body.removeChild(ta);
  if (!ok) throw new Error('fallback-failed');
}
document.addEventListener('click', async function(e){
  const btn = e.target.closest('.btn-copy');
  if(!btn) return;
  let text = (btn.getAttribute('data-copy') || '').trim();
  if (!text) {
    const sel = btn.getAttribute('data-copy-el');
    if (sel) {
      const el = document.querySelector(sel);
      if (el) text = (el.textContent || '').trim();
    }
  }
  try { await __copyTextRobust(text); showToast('copied','success',1600); }
  catch(err){ showToast('copy failed','error',1800); }
});

/* ===== Password eye toggle ===== */
document.getElementById('ppp-eye')?.addEventListener('click', ()=>{
  const m = document.getElementById('ppp-mask');
  if (!m) return;
  if (m.dataset.revealed === '1') {
    m.textContent = '<?= $pp ? str_repeat('•', max(6, strlen($pp))) : '-' ?>';
    m.dataset.revealed = '0';
  } else {
    m.textContent = '<?= h($pp) ?>';
    m.dataset.revealed = '1';
  }
});

/* ===== Live status via API (10s; backoff) ===== */
let liveTimer = null, inflight = false, backoff = 10000;
function loadLiveStatus(){
  if(inflight) return;
  inflight = true;
  const ctl = new AbortController();
  const t = setTimeout(()=>ctl.abort(), 7000);

  fetch('/api/client_live_status.php?id=<?= (int)$client['id']; ?>', {cache:'no-store', signal: ctl.signal})
    .then(res=>res.json()).then(d=>{
      const dv = document.getElementById('device-vendor');
      if (dv) dv.textContent = (d.device_vendor && d.device_vendor.trim()!=='') ? d.device_vendor : '—';

      const rmacEl = document.getElementById('router-mac');
      const amacEl = document.getElementById('active-mac');
      const rBtn   = document.getElementById('btn-copy-router');
      const aBtn   = document.getElementById('btn-copy-active');

      const rmac = (d.router_mac && d.router_mac.trim()!=='') ? d.router_mac : (d.caller_id || '—');
      const amac = (d.active_mac && d.active_mac.trim()!=='') ? d.active_mac : (d.caller_id || '—');

      if (rmacEl) rmacEl.textContent = rmac || '—';
      if (amacEl) amacEl.textContent = amac || '—';

      if (rBtn){
        if (rmac && rmac!=='—'){ rBtn.style.display=''; rBtn.setAttribute('data-copy', rmac); rBtn.removeAttribute('data-copy-el'); }
        else { rBtn.style.display='none'; rBtn.setAttribute('data-copy',''); }
      }
      if (aBtn){
        if (amac && amac!=='—'){ aBtn.style.display=''; aBtn.setAttribute('data-copy', amac); aBtn.removeAttribute('data-copy-el'); }
        else { aBtn.style.display='none'; aBtn.setAttribute('data-copy',''); }
      }

      if (dv && (dv.textContent==='—' || dv.textContent==='') && rmac && rmac!=='—'){
        fetch('/api/mac_vendor.php?mac='+encodeURIComponent(rmac), {cache:'no-store'})
          .then(r=>r.json()).then(j=>{ if (j && j.vendor) dv.textContent = j.vendor; }).catch(()=>{});
      }

      const ip = document.getElementById('live-ip');
      const up = document.getElementById('uptime');
      const st = document.getElementById('live-status');
      const ls = document.getElementById('last-seen');
      const dl = document.getElementById('total-dl');
      const ul = document.getElementById('total-ul');
      const rx = document.getElementById('rx-rate');
      const tx = document.getElementById('tx-rate');
      const namePill = document.getElementById('name-online');

      if(ip) ip.textContent = (d.ip ?? '—');
      if(up) up.textContent = (d.uptime ?? '—');
      if(ls) ls.textContent = (d.last_seen ?? '—');
      if(dl) dl.textContent = (d.total_download_gb!=null ? d.total_download_gb+' GB' : '—');
      if(ul) ul.textContent = (d.total_upload_gb!=null   ? d.total_upload_gb  +' GB' : '—');
      if(rx) rx.textContent = d.rx_rate || '0 Kbps';
      if(tx) tx.textContent = d.tx_rate || '0 Kbps';

      if(st){
        st.textContent = d.online ? 'Online':'Offline';
        st.className   = 'badge ' + (d.online ? 'bg-success' : 'bg-danger');
      }
      if(namePill){
        namePill.innerHTML = `<i class="bi bi-wifi"></i> ${d.online ? 'Online' : 'Offline'}`;
        namePill.className = 'badge ' + (d.online ? 'bg-success' : 'bg-secondary');
        namePill.style.backgroundColor = d.online ? '#198754' : '#6c757d';
      }

      backoff = 10000;
    })
    .catch(()=>{ backoff = Math.min(backoff * 1.5, 30000); })
    .finally(()=>{ clearTimeout(t); inflight=false; });
}
function startLive(){ if (!liveTimer) liveTimer = setInterval(loadLiveStatus, backoff); }
function stopLive(){ if (liveTimer) { clearInterval(liveTimer); liveTimer = null; } }
document.addEventListener('visibilitychange', ()=> {
  if (document.hidden) stopLive(); else { loadLiveStatus(); startLive(); }
});
setInterval(()=>{ if (liveTimer){ clearInterval(liveTimer); liveTimer = setInterval(loadLiveStatus, backoff); } }, 3000);

loadLiveStatus(); startLive();

/* ===== Renew submit (invoice+renew) ===== */
(function(){
  const monthsEl = document.getElementById('rn_months');
  const amountEl = document.getElementById('rn_amount');
  const invDateEl= document.getElementById('rn_invoice_date');
  const formEl   = document.getElementById('renewForm');

  const monthlyBill = Number(<?= json_encode((float)($client['monthly_bill'] ?? 0)) ?>);
  const expCur = <?= json_encode($client['expiry_date'] ?? '') ?>;

  function addMonths(dateStr, m){
    if(!dateStr) return '';
    const d = new Date(dateStr+'T00:00:00');
    if(isNaN(d)) return '';
    const dd = new Date(d.getTime()); dd.setMonth(dd.getMonth() + m);
    return `${dd.getFullYear()}-${String(dd.getMonth()+1).padStart(2,'0')}-${String(dd.getDate()).padStart(2,'0')}`;
  }
  function todayYMD(){
    const d=new Date();
    return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
  }
  function maxDate(a,b){ if(!a) return b; if(!b) return a; return (a>b)?a:b; }

  document.getElementById('renewModal')?.addEventListener('shown.bs.modal', ()=>{
    const m = parseInt(monthsEl.value||'1',10);
    if (!amountEl.dataset.touched) amountEl.value = (monthlyBill * (isNaN(m)?1:m)).toFixed(2);
    const base = maxDate(todayYMD(), (expCur||'')); // base = today বা current expiry এর বড় যেটা
    document.getElementById('rn_exp_new').textContent = base ? addMonths(base, isNaN(m)?1:m) : '—';
    document.getElementById('rn_exp_current').textContent = (expCur||'—');
  });

  monthsEl?.addEventListener('change', ()=>{
    const m = parseInt(monthsEl.value||'1',10);
    if (!amountEl.dataset.touched) amountEl.value = (monthlyBill * (isNaN(m)?1:m)).toFixed(2);
    const base = maxDate(todayYMD(), (expCur||'')); 
    document.getElementById('rn_exp_new').textContent = base ? addMonths(base, isNaN(m)?1:m) : '—';
  });
  amountEl?.addEventListener('input', ()=>{ amountEl.dataset.touched = '1'; });

  formEl?.addEventListener('submit', async (e)=>{
    e.preventDefault();
    const months = parseInt(monthsEl.value||'1',10);
    const amount = Number(amountEl.value||'0');
    const method = document.getElementById('rn_method').value || 'Cash';
    const note   = document.getElementById('rn_note').value || '';
    const invdt  = invDateEl.value || todayYMD();
    if(isNaN(months) || months<=0){ showToast('Invalid months','error'); return; }
    if(isNaN(amount) || amount<=0){ showToast('Invalid amount','error'); return; }

    try{
      const res = await fetch('/api/renew.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ client_id: <?= (int)$client['id'] ?>, months, amount, method, note, invoice_date: invdt, csrf_token: CSRF })
      });
      const data = await res.json();
      if (data.status === 'success'){
        showToast(data.message || 'Renewed & Invoiced','success',2200);
        const url = data.invoice_id
          ? `/public/invoice_view.php?id=${encodeURIComponent(data.invoice_id)}`
          : `/public/invoices.php?client_id=<?= (int)$client['id'] ?>`;
        setTimeout(()=> window.location.href = url, 700);
      } else {
        showToast(data.message || 'Renew failed','error',3000);
      }
    }catch(err){ showToast('Request failed','error',3000); }
  });
})();
</script>

<?php include __DIR__ . '/../partials/client_recent.php'; ?>
<?php include __DIR__ . '/../partials/client_ledger_widget.php'; ?>
<?php include __DIR__ . '/../partials/client_activity_widget.php'; ?>
<?php include __DIR__ . '/../partials/partials_footer.php'; ?>
