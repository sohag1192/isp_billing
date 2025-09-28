<?php
// /public/reseller_add.php
// UI: English; Comments: বাংলা — schema-aware form (only show fields that exist)

declare(strict_types=1);

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

// (optional) ACL
$acl_file = __DIR__ . '/../app/acl.php';
if (is_file($acl_file)) require_once $acl_file;
if (function_exists('require_perm')) require_perm('reseller.manage');

// CSRF
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf_token'];

// Safe HTML
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// --- detect available columns ---
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$cols = [];
try{
  $cols = $pdo->query("SHOW COLUMNS FROM resellers")->fetchAll(PDO::FETCH_COLUMN);
}catch(Throwable $e){ $cols = []; }

$has = fn(string $c) => in_array($c, $cols, true);

require_once __DIR__ . '/../partials/partials_header.php';
?>
<div class="container my-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Add Reseller</h4>
    <a class="btn btn-sm btn-outline-secondary" href="resellers.php">Back</a>
  </div>

  <?php if(!empty($_SESSION['flash_err'])): ?>
    <div class="alert alert-danger py-2"><?= h($_SESSION['flash_err']); unset($_SESSION['flash_err']); ?></div>
  <?php endif; ?>

  <div class="card">
    <div class="card-body">
      <form method="post" action="reseller_add_query.php" class="row g-3">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <?php if($has('code')): ?>
        <div class="col-md-3">
          <label class="form-label">Code (optional)</label>
          <input type="text" name="code" class="form-control" placeholder="e.g. RS20250901">
        </div>
        <?php endif; ?>

        <div class="<?= $has('code') ? 'col-md-6' : 'col-md-9' ?>">
          <label class="form-label">Name <span class="text-danger">*</span></label>
          <input type="text" name="name" class="form-control" required>
        </div>

        <?php if($has('is_active')): ?>
        <div class="col-md-3">
          <label class="form-label">Status</label>
          <select name="is_active" class="form-select">
            <option value="1" selected>Active</option>
            <option value="0">Inactive</option>
          </select>
        </div>
        <?php endif; ?>

        <?php if($has('phone')): ?>
        <div class="col-md-4">
          <label class="form-label">Phone</label>
          <input type="text" name="phone" class="form-control">
        </div>
        <?php endif; ?>

        <?php if($has('email')): ?>
        <div class="col-md-4">
          <label class="form-label">Email</label>
          <input type="email" name="email" class="form-control">
        </div>
        <?php endif; ?>

        <?php if($has('address')): ?>
        <div class="col-md-12">
          <label class="form-label">Address</label>
          <input type="text" name="address" class="form-control">
        </div>
        <?php endif; ?>

        <div class="col-12">
          <button class="btn btn-primary">Save Reseller</button>
        </div>
      </form>
      <?php if(empty($cols)): ?>
        <div class="alert alert-warning mt-3 mb-0">
          Could not read columns from <code>resellers</code> table. Form shows minimal fields.
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../partials/partials_footer.php'; ?>
