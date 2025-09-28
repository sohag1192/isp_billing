<?php
// /public/wallet_accounts.php
// UI: English; Comments: বাংলা
// Feature: Map bKash receiving MSISDN to wallet owner (user_id).

declare(strict_types=1);
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
$csrf = $_SESSION['csrf'];

$page_title = 'Wallet Accounts (bKash Numbers)';
$active_menu = 'wallet_accounts';

require_once __DIR__ . '/../partials/partials_header.php';

$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* create if not exists (soft safety) */
$pdo->exec("CREATE TABLE IF NOT EXISTS wallet_accounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  msisdn VARCHAR(32) NOT NULL,
  label VARCHAR(64) DEFAULT NULL,
  UNIQUE KEY uniq_msisdn (msisdn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* POST add/delete */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['csrf'] ?? '') === $csrf) {
  if (isset($_POST['add'])) {
    $user_id = (int)($_POST['user_id'] ?? 0);
    $msisdn  = trim($_POST['msisdn'] ?? '');
    $label   = trim($_POST['label'] ?? '');
    if ($user_id>0 && $msisdn!=='') {
      $pdo->prepare("INSERT INTO wallet_accounts (user_id,msisdn,label) VALUES (?,?,?)")
          ->execute([$user_id,$msisdn,$label ?: null]);
    }
  } elseif (isset($_POST['del'])) {
    $id = (int)($_POST['id'] ?? 0);
    if ($id>0) $pdo->prepare("DELETE FROM wallet_accounts WHERE id=?")->execute([$id]);
  }
  header("Location: /public/wallet_accounts.php"); exit;
}

/* fetch list */
$rows = $pdo->query("SELECT wa.*, u.username, u.full_name FROM wallet_accounts wa
                     LEFT JOIN users u ON u.id=wa.user_id
                     ORDER BY wa.id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="container py-3">
  <h5 class="mb-3">Wallet Accounts (bKash receiving numbers)</h5>

  <form method="post" class="row g-2 mb-4">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <div class="col-md-3">
      <label class="form-label">User ID</label>
      <input type="number" name="user_id" class="form-control" placeholder="users.id" required>
    </div>
    <div class="col-md-3">
      <label class="form-label">MSISDN (01XXXXXXXXX)</label>
      <input type="text" name="msisdn" class="form-control" required>
    </div>
    <div class="col-md-4">
      <label class="form-label">Label (optional)</label>
      <input type="text" name="label" class="form-control" placeholder="Accounts / Cash / Agent SIM">
    </div>
    <div class="col-md-2 d-flex align-items-end">
      <button class="btn btn-primary w-100" name="add" value="1">Add</button>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-sm align-middle table-hover">
      <thead class="table-light">
        <tr>
          <th>ID</th>
          <th>User</th>
          <th>MSISDN</th>
          <th>Label</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <td><small><?= h($r['full_name'] ?: $r['username'] ?: ('#'.$r['user_id'])) ?></small></td>
          <td><?= h($r['msisdn']) ?></td>
          <td><?= h($r['label']) ?></td>
          <td>
            <form method="post" class="d-inline">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" name="del" value="1" onclick="return confirm('Delete this mapping?')">
                Delete
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/../partials/partials_footer.php'; ?>
