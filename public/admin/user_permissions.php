<?php
// /public/admin/user_permissions.php
// UI English; Comments Bengali — Assign direct permissions (allow/deny) to users.

declare(strict_types=1);
$ROOT = dirname(__DIR__, 2);
require_once $ROOT.'/app/require_login.php';
require_once $ROOT.'/app/db.php';
require_once $ROOT.'/app/acl.php';

if (!(acl_is_admin_role() || acl_is_username_admin())) { acl_forbid_403('Admin only.'); }
function dbh():PDO{ $p=db(); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); return $p; }
function h($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
$pdo=dbh();

// data
$users = $pdo->query("SELECT id, username, COALESCE(name,full_name,'') AS full_name FROM users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$perms = $pdo->query("SELECT id, code FROM permissions ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);

// select user
$uid = (int)($_GET['user_id'] ?? 0);
$selUser = null;
$allowIds = $denyIds = [];
if ($uid>0){
  $st=$pdo->prepare("SELECT id,username,COALESCE(name,full_name,'') full_name FROM users WHERE id=?"); $st->execute([$uid]); $selUser=$st->fetch(PDO::FETCH_ASSOC);
  // load current selections
  $allowIds = $pdo->prepare("SELECT permission_id FROM user_permissions WHERE user_id=?");
  $allowIds->execute([$uid]); $allowIds = array_map('intval', $allowIds->fetchAll(PDO::FETCH_COLUMN));
  $denyIds = $pdo->prepare("SELECT permission_id FROM user_permission_denies WHERE user_id=?");
  $denyIds->execute([$uid]); $denyIds = array_map('intval', $denyIds->fetchAll(PDO::FETCH_COLUMN));
}

// csrf
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

$page_title="Admin · User Permissions";
include $ROOT.'/partials/partials_header.php';
?>
<div class="container py-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">User Permissions</h4>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary" href="/tools/seed_user_perms.php" target="_blank">Ensure tables</a>
      <a class="btn btn-outline-secondary" href="/tools/seed_hr_perms.php" target="_blank">Seed HR perms</a>
    </div>
  </div>

  <!-- Pick user -->
  <form class="card shadow-sm mb-3" method="get">
    <div class="card-body row g-2 align-items-end">
      <div class="col-md-6">
        <label class="form-label">Select user</label>
        <select class="form-select" name="user_id" required>
          <option value="">-- choose --</option>
          <?php foreach($users as $u): ?>
            <option value="<?php echo (int)$u['id']; ?>" <?php echo $uid===(int)$u['id']?'selected':''; ?>>
              <?php echo h($u['id'].': '.$u['username'].' — '.$u['full_name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <button class="btn btn-dark w-100" type="submit">Load</button>
      </div>
    </div>
  </form>

  <?php if ($selUser): ?>
    <div class="card shadow-sm">
      <div class="card-body">
        <h6 class="mb-3">Edit permissions — <code><?php echo h($selUser['username']); ?></code></h6>
        <form method="post" action="/public/admin/user_permissions_query.php">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="user_id" value="<?php echo (int)$uid; ?>">

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Allow (add)</label>
              <select class="form-select" name="allow_ids[]" multiple size="12">
                <?php foreach($perms as $p): ?>
                  <option value="<?php echo (int)$p['id']; ?>"
                          <?php echo in_array((int)$p['id'],$allowIds,true)?'selected':''; ?>>
                    <?php echo h($p['code']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Hold Ctrl/⌘ to select multiple. Examples: <code>hr.view</code>, <code>hr.*</code>, <code>*</code></div>
            </div>

            <div class="col-md-6">
              <label class="form-label">Deny (override)</label>
              <select class="form-select" name="deny_ids[]" multiple size="12">
                <?php foreach($perms as $p): ?>
                  <option value="<?php echo (int)$p['id']; ?>"
                          <?php echo in_array((int)$p['id'],$denyIds,true)?'selected':''; ?>>
                    <?php echo h($p['code']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Deny wins over any role/allow (use sparingly).</div>
            </div>
          </div>

          <div class="d-flex justify-content-end mt-3 gap-2">
            <a class="btn btn-outline-secondary" href="?">Cancel</a>
            <button class="btn btn-primary" type="submit">Save</button>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php include $ROOT.'/partials/partials_footer.php'; ?>
