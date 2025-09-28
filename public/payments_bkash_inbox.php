<?php
// /public/payments_bkash_inbox.php
// UI: English; Comments: বাংলা
// Feature: Review sms_inbox → filter (processed/unprocessed/error) → match & apply manually.

declare(strict_types=1);
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
$csrf = $_SESSION['csrf'];

$page_title = 'bKash SMS Inbox';
$active_menu = 'payments_bkash_inbox';

require_once __DIR__ . '/../partials/partials_header.php';

$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------------- filters ---------------- */
// বাংলা: সহজ ফিল্টার—status & search
$status  = $_GET['status'] ?? 'unprocessed'; // all|processed|unprocessed|error
$search  = trim($_GET['q'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$limit   = 20; $offset = ($page-1)*$limit;

$where   = []; $params = [];
if ($status === 'processed') { $where[]='processed=1'; }
elseif ($status === 'error') { $where[]='processed=0 AND error_msg IS NOT NULL AND error_msg<>""'; }
elseif ($status === 'unprocessed') { $where[]='processed=0'; }

if ($search !== '') {
  $where[] = '(raw_body LIKE ? OR trx_id LIKE ? OR sender_number LIKE ? OR ref_code LIKE ? OR msisdn_to LIKE ?)';
  $params[] = "%$search%"; $params[]="%$search%"; $params[]="%$search%"; $params[]="%$search%"; $params[]="%$search%";
}
$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

/* count */
$cnt = $pdo->prepare("SELECT COUNT(*) FROM sms_inbox $whereSql");
$cnt->execute($params); $total = (int)$cnt->fetchColumn();

/* fetch */
$sql = "SELECT * FROM sms_inbox $whereSql ORDER BY id DESC LIMIT $limit OFFSET $offset";
$st = $pdo->prepare($sql); $st->execute($params); $rows = $st->fetchAll(PDO::FETCH_ASSOC);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<div class="container-fluid py-3">
  <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <h5 class="mb-0">bKash SMS Inbox</h5>
    <form class="d-flex gap-2" method="get">
      <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="all" <?= $status==='all'?'selected':'' ?>>All</option>
        <option value="unprocessed" <?= $status==='unprocessed'?'selected':'' ?>>Unprocessed</option>
        <option value="processed" <?= $status==='processed'?'selected':'' ?>>Processed</option>
        <option value="error" <?= $status==='error'?'selected':'' ?>>Error</option>
      </select>
      <input type="text" class="form-control form-control-sm" name="q" value="<?= h($search) ?>" placeholder="Search text / trx / phone / ref">
      <button class="btn btn-sm btn-primary">Search</button>
    </form>
  </div>

  <div class="table-responsive">
    <table class="table table-sm align-middle table-hover">
      <thead class="table-light">
        <tr>
          <th>ID</th>
          <th>Received</th>
          <th>From</th>
          <th>To</th>
          <th>Amount</th>
          <th>TrxID</th>
          <th>Ref</th>
          <th>Status</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <td><small><?= h($r['received_at']) ?></small></td>
          <td><small><?= h($r['sender_number'] ?: $r['msisdn_from']) ?></small></td>
          <td><small><?= h($r['msisdn_to']) ?></small></td>
          <td><?= $r['amount'] !== null ? number_format((float)$r['amount'],2) : '-' ?></td>
          <td><code><?= h($r['trx_id']) ?></code></td>
          <td><small><?= h($r['ref_code']) ?></small></td>
          <td>
            <?php if ($r['processed']): ?>
              <span class="badge text-bg-success">Processed</span>
            <?php elseif (!empty($r['error_msg'])): ?>
              <span class="badge text-bg-warning" title="<?= h($r['error_msg']) ?>">Error</span>
            <?php else: ?>
              <span class="badge text-bg-secondary">Pending</span>
            <?php endif; ?>
          </td>
          <td>
            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#m<?= (int)$r['id'] ?>">
              Match & Apply
            </button>
          </td>
        </tr>
        <tr class="collapse" id="m<?= (int)$r['id'] ?>">
          <td colspan="9">
            <form method="post" action="/public/payments_bkash_match.php" class="row g-2">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="inbox_id" value="<?= (int)$r['id'] ?>">
              <div class="col-12">
                <div class="form-text">Raw SMS:</div>
                <div class="p-2 border rounded bg-light"><?= nl2br(h($r['raw_body'])) ?></div>
              </div>
              <div class="col-md-3">
                <label class="form-label">Client Ref (invoice_no / client_code / PPPoE)</label>
                <input type="text" name="ref" class="form-control" value="<?= h($r['ref_code']) ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Or Client ID</label>
                <input type="number" name="client_id" class="form-control" placeholder="clients.id">
              </div>
              <div class="col-md-2">
                <label class="form-label">Amount</label>
                <input type="number" step="0.01" name="amount" class="form-control" value="<?= h($r['amount']) ?>">
              </div>
              <div class="col-md-2">
                <label class="form-label">TrxID</label>
                <input type="text" name="trx_id" class="form-control" value="<?= h($r['trx_id']) ?>">
              </div>
              <div class="col-md-2">
                <label class="form-label">Mark Processed</label><br>
                <div class="form-check mt-2">
                  <input class="form-check-input" type="checkbox" name="mark_processed" value="1" checked>
                  <label class="form-check-label">Yes</label>
                </div>
              </div>
              <div class="col-12 d-flex gap-2">
                <button class="btn btn-success">Apply Payment</button>
                <a class="btn btn-outline-secondary" href="?status=<?= h($status) ?>&q=<?= h($search) ?>">Cancel</a>
              </div>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php
  $pages = (int)ceil($total / $limit);
  if ($pages>1): ?>
    <nav>
      <ul class="pagination pagination-sm">
        <?php for($i=1;$i<=$pages;$i++): ?>
          <li class="page-item <?= $i===$page?'active':'' ?>">
            <a class="page-link" href="?status=<?= h($status) ?>&q=<?= h($search) ?>&page=<?= $i ?>"><?= $i ?></a>
          </li>
        <?php endfor; ?>
      </ul>
    </nav>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../partials/partials_footer.php'; ?>
