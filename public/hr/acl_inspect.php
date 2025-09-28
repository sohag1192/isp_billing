<?php
// /public/hr/acl_inspect.php
// UI: English; Comments: Bangla

declare(strict_types=1);

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/acl.php';

// বাংলা: শুধু অ্যাডমিন ইউজারই এই পেজ দেখতে পারবে
if (acl_username() !== ACL_ADMIN_USERNAME) {
    http_response_code(403);
    echo "Access denied.";
    exit;
}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Input (optional quick lookups)
$q_emp = trim($_GET['emp_id'] ?? acl_emp_id()); // default: current user EMP_ID

// Fetch: roles summary
$roles = $pdo->query("
  SELECT r.id, r.name, COALESCE(cnt.c,0) AS perm_count
  FROM roles r
  LEFT JOIN (
    SELECT role_id, COUNT(*) c
    FROM role_permissions
    GROUP BY role_id
  ) cnt ON cnt.role_id = r.id
  ORDER BY r.name ASC
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Fetch: permissions summary
$perms = $pdo->query("
  SELECT p.id, p.perm_key, p.description
  FROM permissions p
  ORDER BY p.perm_key ASC
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Role->permission map (for table)
$rp = $pdo->query("
  SELECT r.name AS role_name, p.perm_key
  FROM role_permissions rp
  JOIN roles r ON r.id = rp.role_id
  JOIN permissions p ON p.id = rp.permission_id
  ORDER BY r.name, p.perm_key
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

// EMP roles + perms (lookup)
$emp_roles = $q_emp ? acl_emp_roles($q_emp) : [];
$emp_perms = $q_emp ? acl_emp_permissions($q_emp) : [];

// Partials (optional)
$header = __DIR__ . '/../../partials/partials_header.php';
$footer = __DIR__ . '/../../partials/partials_footer.php';
if (is_file($header)) require $header;
?>
<div class="container-fluid py-3">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="mb-0">ACL Inspector</h3>
    <span class="text-muted small">Admin only</span>
  </div>

  <form class="row g-2 align-items-end mb-3" method="get">
    <div class="col-md-4">
      <label class="form-label">EMP ID</label>
      <input type="text" class="form-control" name="emp_id" value="<?php echo h($q_emp); ?>" placeholder="EMP20250901">
    </div>
    <div class="col-md-2 d-grid">
      <button class="btn btn-secondary">Inspect</button>
    </div>
  </form>

  <div class="row g-3">
    <div class="col-lg-4">
      <div class="card shadow-sm">
        <div class="card-header">Roles (summary)</div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead class="table-light">
                <tr><th>ID</th><th>Name</th><th>Perms</th></tr>
              </thead>
              <tbody>
                <?php foreach($roles as $r): ?>
                <tr>
                  <td><?php echo (int)$r['id']; ?></td>
                  <td class="fw-semibold"><?php echo h($r['name']); ?></td>
                  <td><?php echo (int)$r['perm_count']; ?></td>
                </tr>
                <?php endforeach; if(!$roles): ?>
                <tr><td colspan="3" class="text-center text-muted">No roles</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-8">
      <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between">
          <span>Role → Permissions</span>
          <span class="text-muted small">Total: <?php echo count($rp); ?></span>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive" style="max-height:420px;">
            <table class="table table-sm mb-0">
              <thead class="table-light">
                <tr><th>Role</th><th>perm_key</th></tr>
              </thead>
              <tbody>
                <?php foreach($rp as $row): ?>
                <tr>
                  <td class="fw-semibold"><?php echo h($row['role_name']); ?></td>
                  <td><code><?php echo h($row['perm_key']); ?></code></td>
                </tr>
                <?php endforeach; if(!$rp): ?>
                <tr><td colspan="2" class="text-center text-muted">No mappings</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12">
      <div class="card shadow-sm">
        <div class="card-header">Permissions (catalog)</div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead class="table-light">
                <tr><th>ID</th><th>perm_key</th><th>Description</th></tr>
              </thead>
              <tbody>
                <?php foreach($perms as $p): ?>
                <tr>
                  <td><?php echo (int)$p['id']; ?></td>
                  <td><code><?php echo h($p['perm_key']); ?></code></td>
                  <td><?php echo h($p['description'] ?? ''); ?></td>
                </tr>
                <?php endforeach; if(!$perms): ?>
                <tr><td colspan="3" class="text-center text-muted">No permissions</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12">
      <div class="card shadow-sm">
        <div class="card-header">Current EMP Lookup</div>
        <div class="card-body">
          <p class="mb-1"><strong>EMP ID:</strong> <code><?php echo h($q_emp ?: '(none)'); ?></code></p>
          <p class="mb-2"><strong>Roles:</strong>
            <?php if ($emp_roles): ?>
              <?php foreach($emp_roles as $rn): ?>
                <span class="badge bg-primary me-1"><?php echo h($rn); ?></span>
              <?php endforeach; ?>
            <?php else: ?>
              <span class="text-muted">No roles</span>
            <?php endif; ?>
          </p>
          <div>
            <strong>Effective permissions:</strong>
            <?php if ($emp_perms): ?>
              <div class="mt-2">
                <?php foreach($emp_perms as $pk): ?>
                  <span class="badge bg-success me-1"><?php echo h($pk); ?></span>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="text-muted">No permissions</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

  </div><!-- /row -->
</div>

<?php if (is_file($footer)) require $footer; ?>
