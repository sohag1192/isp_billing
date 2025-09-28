<?php
// /public/accounts_manage.php
// UI: English; Comments: বাংলা
// Feature: List accounts and link/unlink them to software users, with modal picker.
// Perm: Prefer ACL 'accounts.manage' if available; else fallback to admin/manager/accounts roles.

declare(strict_types=1);

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

// (optional) ACL থাকলে নাও; না থাকলে নীরব
$ACL_FILE = __DIR__ . '/../app/acl.php';
if (is_file($ACL_FILE)) require_once $ACL_FILE;

/* ---------------- helpers ---------------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function dbh(): PDO { $pdo=db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); return $pdo; }
function hasColumn(string $table, string $col): bool {
  static $cache=[];
  if(!isset($cache[$table])){
    $cols = dbh()->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $cache[$table] = array_flip($cols);
  }
  return isset($cache[$table][$col]);
}
function can_manage_accounts(): bool {
  // বাংলা: যদি ACL থাকে, 'accounts.manage' চাই; না থাকলে fallback
  if (function_exists('require_perm')) {
    try { require_perm('accounts.manage'); return true; }
    catch (Throwable $e) { return false; }
  }
  $u = $_SESSION['user'] ?? [];
  $is_admin = (int)($u['is_admin'] ?? 0);
  $role = strtolower((string)($u['role'] ?? ''));
  return $is_admin===1 || in_array($role, ['admin','superadmin','manager','accounts','accountant','billing'], true);
}

/* -------------- guard -------------- */
if (!can_manage_accounts()) {
  require_once __DIR__ . '/../partials/partials_header.php';
  echo '<div class="container my-4"><div class="alert alert-warning shadow-sm">
    <i class="bi bi-shield-lock me-2"></i> You are not permitted to manage accounts. (need <code>accounts.manage</code> or manager/admin role)
  </div></div>';
  require_once __DIR__ . '/../partials/partials_footer.php';
  exit;
}

/* -------------- CSRF -------------- */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF = $_SESSION['csrf_token'];

/* -------------- flash -------------- */
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

/* -------------- filters -------------- */
$only_unlinked = isset($_GET['only_unlinked']) ? (int)$_GET['only_unlinked'] : 0;
$q = trim((string)($_GET['q'] ?? ''));

/* -------------- user map -------------- */
// বাংলা: ইউজারের ডিসপ্লে-নেম কলাম auto-pick
$users = []; // id => display
try {
  $ucols = dbh()->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
  $pick = null;
  foreach (['name','full_name','username','email'] as $c) { if (in_array($c,$ucols,true)) { $pick=$c; break; } }
  if ($pick) {
    $st = dbh()->query("SELECT id, $pick AS u FROM users ORDER BY id");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $uid=(int)$r['id'];
      $users[$uid] = ($r['u']!==null && $r['u']!=='') ? (string)$r['u'] : ("User#$uid");
    }
  } else {
    $st = dbh()->query("SELECT id FROM users ORDER BY id");
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $uid) { $uid=(int)$uid; $users[$uid]="User#$uid"; }
  }
} catch(Throwable $e){ /* ignore gracefully */ }

/* -------------- account name column pick -------------- */
$accCols = dbh()->query("SHOW COLUMNS FROM accounts")->fetchAll(PDO::FETCH_COLUMN);
$nameCol = null;
foreach (['name','account_name','title','label','account_title'] as $c) {
  if (in_array($c, $accCols, true)) { $nameCol = $c; break; }
}

// বাংলা: সিলেক্ট লিস্ট—id, user_id, এবং নাম থাকলে নামও
$selectCols = ['id'];
if ($nameCol) $selectCols[] = $nameCol . ' AS acc_name';
if (in_array('user_id', $accCols, true)) $selectCols[] = 'user_id';

// search condition
$where = []; $params=[];
if ($only_unlinked && in_array('user_id', $accCols, true)) { $where[] = '(user_id IS NULL OR user_id=0)'; }
if ($q !== '') {
  if ($nameCol) { $where[]="$nameCol LIKE ?"; $params[]='%'.$q.'%'; }
  else { $where[]="CAST(id AS CHAR) LIKE ?"; $params[]='%'.$q.'%'; }
}
$wsql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

$sql = "SELECT ".implode(',', $selectCols)." FROM accounts $wsql ORDER BY id";
$st = dbh()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../partials/partials_header.php';
?>
<div class="container my-3">
  <div class="d-flex align-items-center justify-content-between">
    <h3 class="mb-0">Manage Wallet Accounts</h3>
    <div class="d-flex gap-2">
      <a href="/public/wallets.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-wallet2"></i> Wallets</a>
      <a href="?<?= h(http_build_query($_GET)) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-clockwise"></i></a>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-success mt-3 shadow-sm"><?= h($flash) ?></div>
  <?php endif; ?>

  <form class="row g-2 mt-3 mb-3" method="get">
    <div class="col-md-4">
      <label class="form-label">Search</label>
      <input type="text" name="q" value="<?= h($q) ?>" class="form-control" placeholder="Account name or ID">
    </div>
    <div class="col-md-3">
      <label class="form-label">Filter</label>
      <select name="only_unlinked" class="form-select">
        <option value="0" <?= $only_unlinked? '' : 'selected' ?>>All accounts</option>
        <option value="1" <?= $only_unlinked? 'selected' : '' ?>>Unlinked only</option>
      </select>
    </div>
    <div class="col-md-3 d-flex align-items-end gap-2">
      <button class="btn btn-outline-secondary"><i class="bi bi-funnel"></i> Apply</button>
      <a class="btn btn-outline-dark" href="/public/accounts_manage.php"><i class="bi bi-x-circle"></i> Reset</a>
    </div>
  </form>

  <div class="card shadow-sm">
    <div class="card-header">Accounts</div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-striped align-middle">
          <thead class="table-light">
            <tr>
              <th style="width:100px;">ID</th>
              <th>Account</th>
              <th>Linked User</th>
              <th class="text-end" style="width:220px;">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="4" class="text-center text-muted">No accounts found.</td></tr>
          <?php else: foreach ($rows as $r):
            $aid = (int)$r['id'];
            $acc_name = $r['acc_name'] ?? ("Account#$aid");
            $uid = (int)($r['user_id'] ?? 0);
            $uname = $uid>0 && isset($users[$uid]) ? $users[$uid] : '';
          ?>
            <tr>
              <td>#<?= $aid ?></td>
              <td><?= h($acc_name) ?></td>
              <td>
                <?=
                  $uid>0
                  ? ('<span class="badge text-bg-primary">'.h($uname).'</span>')
                  : '<span class="text-muted">— Unlinked</span>';
                ?>
              </td>
              <td class="text-end">
                <button
                  type="button"
                  class="btn btn-sm btn-outline-primary link-btn"
                  data-bs-toggle="modal"
                  data-bs-target="#linkModal"
                  data-account-id="<?= $aid ?>"
                  data-account-name="<?= h($acc_name) ?>"
                  data-user-id="<?= $uid ?>"
                >
                  <i class="bi bi-link-45deg"></i> <?= $uid>0 ? 'Change' : 'Link' ?>
                </button>

                <?php if ($uid>0): ?>
                  <form class="d-inline" method="post" action="/public/accounts_link_action.php" onsubmit="return confirm('Unlink this account?');">
                    <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
                    <input type="hidden" name="action" value="unlink">
                    <input type="hidden" name="account_id" value="<?= $aid ?>">
                    <button class="btn btn-sm btn-outline-danger">
                      <i class="bi bi-x-lg"></i> Unlink
                    </button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <div class="small text-muted">Linking sets <code>accounts.user_id</code>. Unlink keeps the account but clears the association.</div>
    </div>
  </div>
</div>

<!-- Modal: Link/Change -->
<div class="modal fade" id="linkModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="/public/accounts_link_action.php">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-link-45deg me-2"></i> Link Account to User</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
        <input type="hidden" name="action" value="link">
        <input type="hidden" name="account_id" id="m_account_id" value="">
        <div class="mb-3">
          <label class="form-label">Account</label>
          <input type="text" class="form-control" id="m_account_name" value="" readonly>
        </div>
        <div class="mb-3">
          <label class="form-label">User</label>
          <select class="form-select" name="user_id" id="m_user_id" required>
            <option value="">— Select user —</option>
            <?php foreach ($users as $uid=>$uname): ?>
              <option value="<?= (int)$uid ?>"><?= h($uname) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-primary"><i class="bi bi-check2"></i> Save</button>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  const modal = document.getElementById('linkModal');
  modal.addEventListener('show.bs.modal', function (event) {
    const btn = event.relatedTarget;
    if (!btn) return;
    const aid  = btn.getAttribute('data-account-id');
    const anm  = btn.getAttribute('data-account-name') || ('Account#'+aid);
    const uid  = btn.getAttribute('data-user-id') || '';

    modal.querySelector('#m_account_id').value = aid;
    modal.querySelector('#m_account_name').value = anm;
    const sel = modal.querySelector('#m_user_id');
    if (sel) sel.value = uid || '';
  });
});
</script>

<?php require_once __DIR__ . '/../partials/partials_footer.php';
