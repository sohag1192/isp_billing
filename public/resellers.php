<?php
// /public/resellers.php
// UI: English; Comments: বাংলা — রিসেলার লিস্ট + কুইক সার্চ

declare(strict_types=1);
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
$acl_file = __DIR__ . '/../app/acl.php';
if (is_file($acl_file)) require_once $acl_file;

if(function_exists('require_perm')) require_perm('reseller.view');

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// বাংলা: ইনপুট
$q = trim($_GET['q'] ?? '');
$sql = "SELECT id, code, name, phone, email, is_active, created_at
          FROM resellers
         WHERE 1";
$args = [];
if($q!==''){
  $sql .= " AND (name LIKE ? OR code LIKE ? OR phone LIKE ? OR email LIKE ?)";
  $like = "%$q%";
  $args = [$like,$like,$like,$like];
}
$sql .= " ORDER BY created_at DESC LIMIT 200";
$rows = $pdo->prepare($sql);
$rows->execute($args);
$list = $rows->fetchAll(PDO::FETCH_ASSOC);
?>
<?php require_once __DIR__ . '/../partials/partials_header.php'; ?>
<div class="container my-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Resellers</h4>
    <div class="d-flex gap-2">
      <form class="d-flex" method="get">
        <input type="text" name="q" value="<?=h($q)?>" class="form-control form-control-sm" placeholder="Search name/phone/email">
        <button class="btn btn-sm btn-outline-secondary ms-2">Search</button>
      </form>
      <?php if(!function_exists('has_perm') || has_perm('reseller.manage')): ?>
      <a class="btn btn-sm btn-primary" href="reseller_add.php">+ Add Reseller</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>Code</th>
          <th>Name</th>
          <th>Phone</th>
          <th>Email</th>
          <th>Status</th>
          <th>Created</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($list as $r): ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td><?= h($r['code'] ?? '') ?></td>
            <td><?= h($r['name']) ?></td>
            <td><?= h($r['phone'] ?? '') ?></td>
            <td><?= h($r['email'] ?? '') ?></td>
            <td>
              <?php if((int)$r['is_active']===1): ?>
                <span class="badge bg-success">Active</span>
              <?php else: ?>
                <span class="badge bg-secondary">Inactive</span>
              <?php endif; ?>
            </td>
            <td><?= h($r['created_at']) ?></td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-primary" href="reseller_view.php?id=<?= (int)$r['id'] ?>">View</a>
              <?php if(!function_exists('has_perm') || has_perm('reseller.pricing')): ?>
              <a class="btn btn-sm btn-outline-dark" href="reseller_packages.php?id=<?= (int)$r['id'] ?>">Pricing</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if(empty($list)): ?>
          <tr><td colspan="8" class="text-center text-muted">No resellers found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/../partials/partials_footer.php'; ?>
