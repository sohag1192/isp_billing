<?php
// /public/login_activity.php
// UI: English; Comments: বাংলা
// Feature: Login/Logout/Failed listing with filters, pagination, CSV export.

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');

$page_title = 'Login Activity';

require_once __DIR__ . '/../app/require_login.php'; // protect page
require_once __DIR__ . '/../app/db.php';

// (অপশনাল) ACL — থাকলে প্রটেক্ট করো
$acl_file = __DIR__ . '/../app/acl.php';
if (is_file($acl_file)) require_once $acl_file;
if (function_exists('require_perm')) {
  // বাংলা: আপনার ACL-এ 'audit.view' থাকলে গার্ড করবে; না থাকলে admin bypass (আপনার ACL মোতাবেক)
  require_perm('audit.view');
}

/* ---------------- helpers ---------------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------------- DB ---------------- */
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------------- Ensure table (first run safety) ---------------- */
$pdo->exec("
  CREATE TABLE IF NOT EXISTS `auth_logins` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT NULL,
    `username` VARCHAR(191) NULL,
    `event` ENUM('login','logout','failed') NOT NULL DEFAULT 'login',
    `success` TINYINT(1) NOT NULL DEFAULT 1,
    `ip` VARCHAR(45) NOT NULL,
    `user_agent` TEXT NULL,
    `session_id` VARCHAR(191) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_time` (`user_id`, `created_at`),
    KEY `idx_event_time` (`event`, `created_at`),
    KEY `idx_ip_time` (`ip`, `created_at`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* ---------------- inputs ---------------- */
$u     = trim($_GET['user'] ?? '');       // username or user_id
$ip    = trim($_GET['ip'] ?? '');
$evt   = trim($_GET['event'] ?? '');      // login/logout/failed
$ua    = trim($_GET['ua'] ?? '');
$from  = trim($_GET['from'] ?? '');       // YYYY-MM-DD
$to    = trim($_GET['to'] ?? '');         // YYYY-MM-DD
$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = max(10, min(100, (int)($_GET['limit'] ?? 25)));
$offset= ($page - 1) * $limit;

$export= strtolower(trim($_GET['export'] ?? '')); // '' | 'csv'

/* ---------------- where clause build ---------------- */
$where = [];
$bind  = [];

if ($u !== '') {
  $where[] = "(username LIKE ? OR user_id = ?)";
  $bind[]  = "%{$u}%";
  $bind[]  = ctype_digit($u) ? (int)$u : -1;
}
if ($ip !== '') {
  $where[] = "ip LIKE ?";
  $bind[]  = "%{$ip}%";
}
if (in_array($evt, ['login','logout','failed'], true)) {
  $where[] = "event = ?";
  $bind[]  = $evt;
}
if ($ua !== '') {
  $where[] = "user_agent LIKE ?";
  $bind[]  = "%{$ua}%";
}
$re_date = '/^\d{4}-\d{2}-\d{2}$/';
if ($from !== '' && preg_match($re_date, $from)) {
  $where[] = "created_at >= ?";
  $bind[]  = $from . " 00:00:00";
}
if ($to !== '' && preg_match($re_date, $to)) { // ✅ fixed
  $where[] = "created_at <= ?";
  $bind[]  = $to . " 23:59:59";
}
$sqlWhere = $where ? ("WHERE " . implode(" AND ", $where)) : "";

/* ---------------- export CSV (no LIMIT) ---------------- */
if ($export === 'csv') {
  $sql = "SELECT id, user_id, username, event, success, ip, user_agent, session_id, created_at
          FROM auth_logins $sqlWhere ORDER BY id DESC";
  $st = $pdo->prepare($sql);
  $st->execute($bind);

  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=login_activity_'.date('Ymd_His').'.csv');
  echo "\xEF\xBB\xBF";
  $out = fopen('php://output', 'w');
  fputcsv($out, ['ID','User ID','Username','Event','Success','IP','User Agent','Session','Created At']);
  while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, [
      $row['id'], $row['user_id'], $row['username'], $row['event'],
      $row['success'] ? 'Yes' : 'No',
      $row['ip'], $row['user_agent'], $row['session_id'], $row['created_at']
    ]);
  }
  fclose($out);
  exit;
}

/* ---------------- count & fetch ---------------- */
$stc = $pdo->prepare("SELECT COUNT(*) FROM auth_logins $sqlWhere");
$stc->execute($bind);
$total = (int)$stc->fetchColumn();

$total_pages = max(1, (int)ceil($total / $limit));
if ($page > $total_pages) { $page = $total_pages; $offset = ($page - 1) * $limit; }

$sql = "SELECT id, user_id, username, event, success, ip, user_agent, session_id, created_at
        FROM auth_logins $sqlWhere
        ORDER BY id DESC
        LIMIT $limit OFFSET $offset";
$st = $pdo->prepare($sql);
$st->execute($bind);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// pagination helper
$qparams = $_GET; unset($qparams['page']);
$base_qs = http_build_query($qparams);
function pager_link($p, $base_qs){ return '?'. $base_qs . ($base_qs ? '&' : '') . 'page=' . (int)$p; }

require_once __DIR__ . '/../partials/partials_header.php';
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
  .table td, .table th { vertical-align: middle; }
  .ua-trunc { max-width: 420px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  code.small { font-size: .85rem; }
</style>

<div class="container-fluid my-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Login Activity</h4>
    <div class="d-flex gap-2">
      <a class="btn btn-sm btn-outline-secondary" href="?<?= h($base_qs . ($base_qs ? '&' : '')) ?>export=csv" target="_blank" rel="noopener">
        <i class="bi bi-filetype-csv"></i> Export CSV
      </a>
    </div>
  </div>

  <!-- Filters -->
  <form class="row gy-2 gx-2 mb-3" method="get">
    <div class="col-12 col-md-2">
      <label class="form-label mb-1">User/ID</label>
      <input type="text" name="user" value="<?= h($u) ?>" class="form-control form-control-sm" placeholder="username or id">
    </div>
    <div class="col-12 col-md-2">
      <label class="form-label mb-1">IP</label>
      <input type="text" name="ip" value="<?= h($ip) ?>" class="form-control form-control-sm" placeholder="e.g. 192.168.0.1">
    </div>
    <div class="col-12 col-md-2">
      <label class="form-label mb-1">Event</label>
      <select name="event" class="form-select form-select-sm">
        <option value="">All</option>
        <option value="login"  <?= $evt==='login'?'selected':''; ?>>Login</option>
        <option value="logout" <?= $evt==='logout'?'selected':''; ?>>Logout</option>
        <option value="failed" <?= $evt==='failed'?'selected':''; ?>>Failed</option>
      </select>
    </div>
    <div class="col-12 col-md-2">
      <label class="form-label mb-1">From</label>
      <input type="date" name="from" value="<?= h($from) ?>" class="form-control form-control-sm">
    </div>
    <div class="col-12 col-md-2">
      <label class="form-label mb-1">To</label>
      <input type="date" name="to" value="<?= h($to) ?>" class="form-control form-control-sm">
    </div>
    <div class="col-12 col-md-2">
      <label class="form-label mb-1">Device (UA)</label>
      <input type="text" name="ua" value="<?= h($ua) ?>" class="form-control form-control-sm" placeholder="Chrome/Windows...">
    </div>
    <div class="col-12 d-flex gap-2 mt-2">
      <button class="btn btn-primary btn-sm"><i class="bi bi-search"></i> Filter</button>
      <a class="btn btn-outline-secondary btn-sm" href="login_activity.php"><i class="bi bi-x-circle"></i> Reset</a>
    </div>
  </form>

  <!-- Table -->
  <div class="card shadow-sm">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>When</th>
            <th>Event</th>
            <th>Success</th>
            <th>User</th>
            <th>IP</th>
            <th>Device (User-Agent)</th>
            <th>Session</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8" class="text-center text-muted">No records found</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <?php
            $badge = 'secondary';
            if ($r['event']==='login')  $badge = $r['success'] ? 'success' : 'warning';
            if ($r['event']==='failed') $badge = 'danger';
            if ($r['event']==='logout') $badge = 'info';
          ?>
          <tr>
            <td class="text-muted"><?= (int)$r['id'] ?></td>
            <td><code class="small"><?= h($r['created_at']) ?></code></td>
            <td><span class="badge bg-<?= h($badge) ?>"><?= h(ucfirst($r['event'])) ?></span></td>
            <td><?= $r['success'] ? 'Yes' : 'No' ?></td>
            <td>
              <?php if (!empty($r['user_id'])): ?>
                <span class="badge bg-secondary me-1">#<?= (int)$r['user_id'] ?></span>
              <?php endif; ?>
              <?= h($r['username'] ?? '') ?>
            </td>
            <td><code><?= h($r['ip']) ?></code></td>
            <td class="small">
              <div class="ua-trunc" title="<?= h($r['user_agent'] ?? '') ?>">
                <?= h($r['user_agent'] ?? '') ?>
              </div>
            </td>
            <td class="small"><code><?= h($r['session_id'] ?? '') ?></code></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Pagination -->
  <?php if ($total_pages > 1): ?>
  <nav class="mt-3">
    <ul class="pagination pagination-sm justify-content-center">
      <li class="page-item <?= $page<=1?'disabled':'' ?>">
        <a class="page-link" href="<?= h(pager_link($page-1, $base_qs)) ?>">Prev</a>
      </li>
      <?php
        $start = max(1, $page-2);
        $end   = min($total_pages, $page+2);
        for ($i=$start; $i<=$end; $i++):
      ?>
        <li class="page-item <?= $i===$page?'active':'' ?>">
          <a class="page-link" href="<?= h(pager_link($i, $base_qs)) ?>"><?= $i ?></a>
        </li>
      <?php endfor; ?>
      <li class="page-item <?= $page>=$total_pages?'disabled':'' ?>">
        <a class="page-link" href="<?= h(pager_link($page+1, $base_qs)) ?>">Next</a>
      </li>
    </ul>
  </nav>
  <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../partials/partials_footer.php'; ?>
