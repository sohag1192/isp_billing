<?php
// /public/reseller_view.php
// UI English; Comments Bangla — Profile + Link users + Clients + Pricing (schema-aware)

declare(strict_types=1);

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

// (optional) ACL
$acl_file = __DIR__ . '/../app/acl.php';
if (is_file($acl_file)) require_once $acl_file;
if (function_exists('require_perm')) require_perm('reseller.view');

// CSRF
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf_token'];

// Safe HTML
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// --- detect columns of resellers (schema-aware) ---
$cols = [];
try { $cols = $pdo->query("SHOW COLUMNS FROM resellers")->fetchAll(PDO::FETCH_COLUMN); }
catch(Throwable $e){ $cols = []; }
$has = fn(string $c) => in_array($c, $cols, true);

$reseller_id = (int)($_GET['id'] ?? 0);
if($reseller_id<=0){ http_response_code(400); exit('Invalid reseller'); }

/* ---------- Actions: toggle status / add user / remove user ---------- */
// বাংলা: Toggle Active/Inactive (only if is_active column exists)
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['act'] ?? '')==='toggle'){
  if(function_exists('require_perm')) require_perm('reseller.manage');
  if(($_POST['csrf'] ?? '') !== $csrf){ http_response_code(403); exit('Bad CSRF'); }
  if($has('is_active')){
    $st = $pdo->prepare("UPDATE resellers SET is_active = 1 - is_active"
      . ($has('updated_at') ? ", updated_at=NOW()" : "")
      . " WHERE id=?");
    $st->execute([$reseller_id]);
  } else {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    $_SESSION['flash_err'] = 'Status column (is_active) not found on resellers table.';
  }
  header('Location: reseller_view.php?id='.$reseller_id); exit;
}

// বাংলা: Map user → reseller_users
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['act'] ?? '')==='map_user'){
  if(function_exists('require_perm')) require_perm('reseller.manage');
  if(($_POST['csrf'] ?? '') !== $csrf){ http_response_code(403); exit('Bad CSRF'); }
  $user_id = (int)($_POST['user_id'] ?? 0);
  if($user_id>0){
    $pdo->exec("CREATE TABLE IF NOT EXISTS reseller_users (
      reseller_id INT NOT NULL, user_id INT NOT NULL,
      PRIMARY KEY(reseller_id,user_id),
      KEY idx_ru_reseller (reseller_id),
      KEY idx_ru_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $st = $pdo->prepare("INSERT IGNORE INTO reseller_users (reseller_id,user_id) VALUES (?,?)");
    $st->execute([$reseller_id,$user_id]);
  }
  header('Location: reseller_view.php?id='.$reseller_id); exit;
}

// বাংলা: Unmap user
if(isset($_GET['unmap_uid'])){
  if(function_exists('require_perm')) require_perm('reseller.manage');
  if(($_GET['csrf'] ?? '') !== $csrf){ http_response_code(403); exit('Bad CSRF'); }
  $uid = (int)$_GET['unmap_uid'];
  $pdo->prepare("DELETE FROM reseller_users WHERE reseller_id=? AND user_id=?")->execute([$reseller_id,$uid]);
  header('Location: reseller_view.php?id='.$reseller_id); exit;
}

/* ---------- Load data (use SELECT * to avoid missing column list) ---------- */
$rz = $pdo->prepare("SELECT * FROM resellers WHERE id=?");
$rz->execute([$reseller_id]);
$reseller = $rz->fetch(PDO::FETCH_ASSOC);
if(!$reseller){ http_response_code(404); exit('Reseller not found'); }

// বাংলা: Users list (basic)
$users = [];
try{
  $users = $pdo->query("SELECT id, username, name, email FROM users ORDER BY username ASC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e){ /* silent */ }

// বাংলা: mapped users
$mapped = [];
try{
  $mu = $pdo->prepare("SELECT ru.user_id, u.username, u.name, u.email
                         FROM reseller_users ru
                         LEFT JOIN users u ON u.id = ru.user_id
                        WHERE ru.reseller_id=?");
  $mu->execute([$reseller_id]);
  $mapped = $mu->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e){ /* silent */ }

// বাংলা: Clients quick list (assigned to this reseller)
$clients = [];
$clientCount = 0;
try{
  $stc = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE reseller_id=?");
  $stc->execute([$reseller_id]);
  $clientCount = (int)$stc->fetchColumn();

  $stl = $pdo->prepare("SELECT id, client_code, name, pppoe_id, monthly_bill, area, status
                          FROM clients WHERE reseller_id=?
                          ORDER BY id DESC LIMIT 10");
  $stl->execute([$reseller_id]);
  $clients = $stl->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e){ /* silent */ }

// বাংলা: Pricing rows (preview table)
$pricing = [];
try{
  $stp = $pdo->prepare("SELECT rp.package_id, rp.mode, rp.price_override, rp.share_percent, p.name as package_name, p.price as base_price
                          FROM reseller_packages rp
                          LEFT JOIN packages p ON p.id = rp.package_id
                         WHERE rp.reseller_id=?
                         ORDER BY p.name ASC");
  $stp->execute([$reseller_id]);
  $pricing = $stp->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e){ /* silent */ }

require_once __DIR__ . '/../partials/partials_header.php';
?>
<div class="container my-4 reseller-page">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Reseller — <?= h($reseller['name'] ?? ('#'.$reseller_id)) ?></h4>
    <div class="d-flex gap-2">
      <a class="btn btn-sm btn-outline-secondary" href="resellers.php">Back</a>
      <?php if(!function_exists('has_perm') || has_perm('reseller.pricing')): ?>
      <a class="btn btn-sm btn-dark" href="reseller_packages.php?id=<?= (int)$reseller_id ?>">Pricing</a>
      <?php endif; ?>
      <?php if(($has('is_active')) && (!function_exists('has_perm') || has_perm('reseller.manage'))): ?>
      <form method="post" class="d-inline">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="act" value="toggle">
        <button class="btn btn-sm btn-warning">Toggle Active</button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if(!empty($_SESSION['flash_err'])): ?>
    <div class="alert alert-danger py-2"><?= h($_SESSION['flash_err']); unset($_SESSION['flash_err']); ?></div>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-lg-5">
      <div class="card h-100">
        <div class="card-body">
          <?php if($has('is_active')): ?>
            <div class="mb-2">
              <?php if((int)($reseller['is_active'] ?? 0)===1): ?>
                <span class="badge bg-success">Active</span>
              <?php else: ?>
                <span class="badge bg-secondary">Inactive</span>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <?php if($has('code')): ?>
          <div class="row mb-1">
            <div class="col-4 fw-semibold">Code</div>
            <div class="col-8"><?= h($reseller['code'] ?? '') ?></div>
          </div>
          <?php endif; ?>

          <div class="row mb-1">
            <div class="col-4 fw-semibold">Name</div>
            <div class="col-8"><?= h($reseller['name'] ?? '') ?></div>
          </div>

          <?php if($has('phone')): ?>
          <div class="row mb-1">
            <div class="col-4 fw-semibold">Phone</div>
            <div class="col-8"><?= h($reseller['phone'] ?? '') ?></div>
          </div>
          <?php endif; ?>

          <?php if($has('email')): ?>
          <div class="row mb-1">
            <div class="col-4 fw-semibold">Email</div>
            <div class="col-8"><?= h($reseller['email'] ?? '') ?></div>
          </div>
          <?php endif; ?>

          <?php if($has('address')): ?>
          <div class="row mb-1">
            <div class="col-4 fw-semibold">Address</div>
            <div class="col-8"><?= h($reseller['address'] ?? '') ?></div>
          </div>
          <?php endif; ?>

          <?php if($has('created_at')): ?>
          <div class="row mb-1">
            <div class="col-4 fw-semibold">Created</div>
            <div class="col-8"><?= h($reseller['created_at'] ?? '') ?></div>
          </div>
          <?php endif; ?>

          <?php if($has('updated_at')): ?>
          <div class="row">
            <div class="col-4 fw-semibold">Updated</div>
            <div class="col-8"><?= h($reseller['updated_at'] ?? '') ?></div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-7">
      <div class="card mb-3">
        <div class="card-header py-2">Linked Users</div>
        <div class="card-body">
          <?php if(!function_exists('has_perm') || has_perm('reseller.manage')): ?>
          <form method="post" class="row g-2 align-items-end mb-3">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="act" value="map_user">
            <div class="col-md-8">
              <label class="form-label">Add user</label>
              <select name="user_id" class="form-select">
                <option value="">Select user…</option>
                <?php foreach($users as $u): ?>
                  <option value="<?= (int)$u['id'] ?>">
                    <?= h(($u['username'] ?? 'user#'.$u['id']).' — '.($u['name'] ?? '').' '.($u['email'] ?? '')) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <button class="btn btn-primary w-100">Link</button>
            </div>
          </form>
          <?php endif; ?>

          <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
              <thead class="table-light">
                <tr>
                  <th>User</th>
                  <th>Name</th>
                  <th>Email</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($mapped as $m): ?>
                <tr>
                  <td><?= h($m['username'] ?? ('user#'.$m['user_id'])) ?></td>
                  <td><?= h($m['name'] ?? '') ?></td>
                  <td><?= h($m['email'] ?? '') ?></td>
                  <td class="text-end">
                    <?php if(!function_exists('has_perm') || has_perm('reseller.manage')): ?>
                    <a class="btn btn-sm btn-outline-danger"
                       href="reseller_view.php?id=<?= (int)$reseller_id ?>&unmap_uid=<?= (int)$m['user_id'] ?>&csrf=<?= h($csrf) ?>"
                       onclick="return confirm('Remove this mapping?')">Remove</a>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($mapped)): ?>
                <tr><td colspan="4" class="text-muted text-center">No linked users.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header py-2">Assigned Clients (<?= (int)$clientCount ?>)</div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
              <thead class="table-light">
                <tr>
                  <th>#</th>
                  <th>Name</th>
                  <th>PPPoE/User</th>
                  <th>Area</th>
                  <th>Monthly</th>
                  <th>Status</th>
                  <th class="text-end">View</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($clients as $c): ?>
                <tr>
                  <td><?= (int)$c['id'] ?></td>
                  <td><?= h($c['name'] ?? $c['client_code'] ?? '') ?></td>
                  <td><?= h($c['pppoe_id'] ?? '') ?></td>
                  <td><?= h($c['area'] ?? '') ?></td>
                  <td><?= number_format((float)($c['monthly_bill'] ?? 0), 2) ?></td>
                  <td>
                    <?php $st = strtolower((string)($c['status'] ?? '')); ?>
                    <span class="badge <?= $st==='active'?'bg-success':($st==='left'?'bg-secondary':'bg-warning') ?>">
                      <?= h($c['status'] ?? '') ?>
                    </span>
                  </td>
                  <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="client_view.php?id=<?= (int)$c['id'] ?>">Open</a></td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($clients)): ?>
                <tr><td colspan="7" class="text-center text-muted">No clients yet.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <small class="text-muted">Tip: add <code>reseller_id</code> selector in client add/edit to assign.</small>
        </div>
      </div>
    </div>
  </div>

  <div class="card mt-3">
    <div class="card-header py-2">Pricing Preview</div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
          <thead class="table-light">
            <tr>
              <th>Package</th>
              <th>Base Price</th>
              <th>Mode</th>
              <th>Reseller Price</th>
              <th>Commission (Base − Reseller)</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($pricing as $p):
              $base = (float)($p['base_price'] ?? 0);
              $rp = null;
              if(($p['mode'] ?? '') === 'fixed' && $p['price_override'] !== null){
                $rp = (float)$p['price_override'];
              } elseif(($p['mode'] ?? '') === 'percent' && $p['share_percent'] !== null){
                $rp = round($base * ((float)$p['share_percent']/100), 2);
              }
              $comm = $rp!==null ? round($base - $rp, 2) : null;
            ?>
            <tr>
              <td><?= h($p['package_name'] ?? 'Package #'.$p['package_id']) ?></td>
              <td><?= number_format($base,2) ?></td>
              <td><?= h($p['mode'] ?? '-') ?></td>
              <td><?= $rp!==null ? number_format($rp,2) : '-' ?></td>
              <td><?= $comm!==null ? number_format($comm,2) : '-' ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($pricing)): ?>
              <tr><td colspan="5" class="text-center text-muted">No pricing set. Click “Pricing”.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <a class="btn btn-sm btn-dark" href="reseller_packages.php?id=<?= (int)$reseller_id ?>">Manage Pricing</a>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../partials/partials_footer.php'; ?>
