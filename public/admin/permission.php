<?php
// /public/admin/permissions.php
// UI: English; Comments: বাংলা
// Feature: Create permissions and bulk assign to roles / users (allow & deny).

declare(strict_types=1);

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/app/require_login.php';
require_once $ROOT . '/app/db.php';
require_once $ROOT . '/app/acl.php';

if (!(acl_is_admin_role() || acl_is_username_admin())) {
  acl_forbid_403('Admin only: Permission management.');
}

function dbh(): PDO { $pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); return $pdo; }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pdo = dbh();

/* ---------- tiny helpers (বাংলা: টেবিল/কলাম এক্সিস্ট চেক) ---------- */
function tbl_exists(PDO $pdo, string $t): bool {
  $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
  $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
  $st->execute([$db, $t]);
  return (bool)$st->fetchColumn();
}
function col_exists(PDO $pdo, string $tbl, string $col): bool {
  $st = $pdo->prepare("SHOW COLUMNS FROM `$tbl` LIKE ?");
  $st->execute([$col]);
  return (bool)$st->fetchColumn();
}

/* ---------- load data ---------- */
$perms = $pdo->query("SELECT id, code FROM permissions ORDER BY code ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$roles = $pdo->query("SELECT id, name FROM roles ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$users = $pdo->query("SELECT id, username, COALESCE(name, full_name, '') AS full_name FROM users ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* ---------- csrf ---------- */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

$page_title = "Admin · Permissions";
include $ROOT . '/partials/partials_header.php';
?>
<div class="container py-3">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Permissions</h4>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary" href="/tools/seed_hr_perms.php" target="_blank">Seed HR perms</a>
      <a class="btn btn-outline-secondary" href="/tools/seed_user_perms.php" target="_blank">Ensure user-perm tables</a>
    </div>
  </div>

  <?php
  // বাংলা: প্রি-ফ্লাইট স্কিমা সতর্কতা
  $warn = [];
  foreach (['permissions','roles','role_permissions','users'] as $t) {
    if (!tbl_exists($pdo,$t)) $warn[] = $t;
  }
  if ($warn): ?>
    <div class="alert alert-warning">
      Missing tables: <?php echo h(implode(', ', $warn)); ?>.<br>
      Run seed scripts above first.
    </div>
  <?php endif; ?>

  <!-- Create Permission -->
  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <h6 class="mb-3">Create permission</h6>
      <form class="row g-2 align-items-end" method="post" action="/public/admin/permissions_query.php">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <div class="col-md-6">
          <label class="form-label">Permission code</label>
          <input type="text" name="code" class="form-control" placeholder="e.g., hr.delete or hr.* or *" required>
          <div class="form-text">Allowed: <code>a-z 0-9 . : * - _</code></div>
        </div>
        <div class="col-md-3">
          <button class="btn btn-primary w-100" type="submit">Create</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Bulk Assign -->
  <div class="card shadow-sm">
    <div class="card-body">
      <h6 class="mb-3">Bulk assign / revoke</h6>
      <form method="post" action="/public/admin/permissions_bulk_query.php">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <div class="row g-3">
          <div class="col-lg-4">
            <label class="form-label">Permissions</label>
            <select class="form-select" name="perm_ids[]" multiple size="14" required>
              <?php foreach ($perms as $p): ?>
                <option value="<?php echo (int)$p['id']; ?>"><?php echo h($p['code']); ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Hold Ctrl/⌘ to multi-select</div>
          </div>

          <div class="col-lg-8">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Grant to Roles</label>
                <select class="form-select" name="role_ids[]" multiple size="6">
                  <?php foreach ($roles as $r): ?>
                    <option value="<?php echo (int)$r['id']; ?>"><?php echo h($r['name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">Revoke from Roles</label>
                <select class="form-select" name="role_ids_remove[]" multiple size="6">
                  <?php foreach ($roles as $r): ?>
                    <option value="<?php echo (int)$r['id']; ?>"><?php echo h($r['name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-md-6">
                <label class="form-label">Allow to Users</label>
                <select class="form-select" name="user_allow_ids[]" multiple size="6">
                  <?php foreach ($users as $u): ?>
                    <option value="<?php echo (int)$u['id']; ?>">
                      <?php echo h($u['id'].': '.$u['username'].' — '.$u['full_name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">Revoke Allow from Users</label>
                <select class="form-select" name="user_allow_ids_remove[]" multiple size="6">
                  <?php foreach ($users as $u): ?>
                    <option value="<?php echo (int)$u['id']; ?>">
                      <?php echo h($u['id'].': '.$u['username'].' — '.$u['full_name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-md-6">
                <label class="form-label">Deny for Users</label>
                <select class="form-select" name="user_deny_ids[]" multiple size="6">
                  <?php foreach ($users as $u): ?>
                    <option value="<?php echo (int)$u['id']; ?>">
                      <?php echo h($u['id'].': '.$u['username'].' — '.$u['full_name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">Revoke Deny from Users</label>
                <select class="form-select" name="user_deny_ids_remove[]" multiple size="6">
                  <?php foreach ($users as $u): ?>
                    <option value="<?php echo (int)$u['id']; ?>">
                      <?php echo h($u['id'].': '.$u['username'].' — '.$u['full_name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="d-flex justify-content-end gap-2 mt-3">
              <button class="btn btn-primary" type="submit">Apply</button>
              <a class="btn btn-outline-secondary" href="/public/admin/user_permissions.php">User Permissions</a>
              <a class="btn btn-outline-secondary" href="/public/admin/roles.php">Role Assign</a>
            </div>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- Existing permissions table -->
  <div class="card shadow-sm mt-3">
    <div class="card-body">
      <h6 class="mb-3">Existing permissions</h6>
      <div class="table-responsive">
        <table class="table table-sm">
          <thead class="table-light">
            <tr><th style="width:80px;">ID</th><th>Code</th></tr>
          </thead>
          <tbody>
            <?php if (!$perms): ?>
              <tr><td colspan="2" class="text-center text-muted">No permissions yet.</td></tr>
            <?php else: foreach ($perms as $p): ?>
              <tr>
                <td><code><?php echo (int)$p['id']; ?></code></td>
                <td><?php echo h($p['code']); ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <div class="small text-muted">Note: Avoid deleting permissions in DB to keep history clean. Use revoke/deny instead.</div>
    </div>
  </div>

</div>
<?php include $ROOT . '/partials/partials_footer.php'; ?>
