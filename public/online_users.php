<?php
// /public/online_users.php
declare(strict_types=1);
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/session_track.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
st_bootstrap($pdo);

$within = (int)($_GET['within'] ?? 10);
if ($within < 1) $within = 10; if ($within > 240) $within = 240;

// username কলাম আছে কি?
$db = $pdo->query('SELECT DATABASE()')->fetchColumn();
$hasUname = (int)$pdo->query("
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA='{$db}' AND TABLE_NAME='user_sessions' AND COLUMN_NAME='username'
  ")->fetchColumn() > 0;

// users টেবিলে কোন নাম দেখাব তা বাছাই (schema-aware)
$uNameCol = 'username';
$chk = $pdo->query("
  SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA='{$db}' AND TABLE_NAME='users' AND COLUMN_NAME IN ('username','full_name','name','email')
  ORDER BY FIELD(COLUMN_NAME,'full_name','name','username','email') LIMIT 1
")->fetchColumn();
if ($chk) $uNameCol = $chk;

$selectName = $hasUname
  ? "COALESCE(s.username, u.`{$uNameCol}`)"
  : "u.`{$uNameCol}`";

$sql = "SELECT s.user_id, {$selectName} AS show_name, s.session_id, s.ip, s.user_agent, s.login_time, s.last_seen
        FROM user_sessions s
        LEFT JOIN users u ON u.id = s.user_id
        WHERE s.last_seen > NOW() - INTERVAL :min MINUTE
        ORDER BY s.last_seen DESC";
$st = $pdo->prepare($sql);
$st->bindValue(':min', $within, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Online Users';
include __DIR__ . '/../partials/partials_header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<div class="container-fluid my-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Currently Online Users</h4>
    <form class="d-flex gap-2" method="get">
      <input type="number" name="within" class="form-control form-control-sm" min="1" max="240" value="<?= (int)$within ?>">
      <button class="btn btn-sm btn-primary">Apply (minutes)</button>
    </form>
  </div>

  <div class="card shadow-sm">
    <div class="table-responsive">
      <table class="table table-sm table-striped align-middle mb-0">
        <thead class="table-dark">
          <tr>
            <th>#User</th>
            <th>Username</th>
            <th>IP</th>
            <th>Login Time</th>
            <th>Last Seen</th>
            <th>Device (UA)</th>
            <th>Session</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No active users in last <?= (int)$within ?> minutes</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td class="text-muted">#<?= (int)$r['user_id'] ?></td>
            <td><?= htmlspecialchars($r['show_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
            <td><code><?= htmlspecialchars($r['ip'] ?? '', ENT_QUOTES, 'UTF-8') ?></code></td>
            <td><code><?= htmlspecialchars($r['login_time'] ?? '', ENT_QUOTES, 'UTF-8') ?></code></td>
            <td><code><?= htmlspecialchars($r['last_seen'] ?? '', ENT_QUOTES, 'UTF-8') ?></code></td>
            <td class="small"><div style="max-width:420px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= htmlspecialchars($r['user_agent'] ?? '', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($r['user_agent'] ?? '', ENT_QUOTES, 'UTF-8') ?></div></td>
            <td class="small"><code><?= htmlspecialchars($r['session_id'] ?? '', ENT_QUOTES, 'UTF-8') ?></code></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../partials/partials_footer.php'; ?>
