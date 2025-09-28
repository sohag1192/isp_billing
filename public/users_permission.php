<?php
// /public/users_permission.php
// English UI; Comments: Bengali (maintainers friendly)

declare(strict_types=1);
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/acl.php';

$page_title  = 'Roles & Permissions';
$active_menu = 'users_permission';

require_perm('users.manage');

function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
function dbx(): PDO { return db(); }

/* ---------------- Schema helpers (auto map columns) ----------------
   বাংলা: টেবিলগুলোর কলাম নাম ফ্লেক্সিবল। ক্যান্ডিডেট নাম দেখে ম্যাপ করা।
-------------------------------------------------------------------- */
function cols(PDO $pdo, string $table): array {
  $st = $pdo->prepare("SHOW COLUMNS FROM `$table`");
  $st->execute();
  return array_column($st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'Field');
}
function pick(array $available, array $candidates, $fallback=null){
  foreach($candidates as $c) if (in_array($c, $available, true)) return $c;
  return $fallback;
}

/* ---------------- Safe audit logger ----------------
   বাংলা: audit_log() থাকলে সেটাই কল; না থাকলে audit_logs টেবিলে fallback।
---------------------------------------------------- */
function safe_audit(string $action, array $meta=[]): void {
  try {
    if (function_exists('audit_log')) {
      // বিভিন্ন সিগনেচার ট্রাই
      try { audit_log($_SESSION['user_id'] ?? null, null, $action, $meta); return; } catch(Throwable $e){}
      try { audit_log($action, $meta); return; } catch(Throwable $e){}
    }
    // Fallback table
    $pdo = dbx();
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id BIGINT NULL,
      entity_id BIGINT NULL,
      action VARCHAR(191) NOT NULL,
      meta JSON NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $st = $pdo->prepare("INSERT INTO audit_logs(user_id, entity_id, action, meta) VALUES(?,?,?,?)");
    $st->execute([$_SESSION['user_id'] ?? null, null, $action, json_encode($meta, JSON_UNESCAPED_UNICODE)]);
  } catch (Throwable $e) {
    // নীরব ফেল-সেইফ
  }
}

$pdo = dbx();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---- permissions table map ---- */
$permCols = cols($pdo, 'permissions');
$PERM_ID    = pick($permCols, ['id'], 'id');
$PERM_CODE  = pick($permCols, ['code','perm_key','key','slug','name']);
$PERM_LABEL = pick($permCols, ['name','label','title'], $PERM_CODE);
$PERM_CAT   = pick($permCols, ['category','group','section'], null); // optional
if (!$PERM_CODE) throw new RuntimeException("permissions table: need a code-like column (code/perm_key/key/slug/name).");

/* ---- roles table map ---- */
$roleCols = cols($pdo, 'roles');
$ROLE_ID    = pick($roleCols, ['id'], 'id');
$ROLE_NAME  = pick($roleCols, ['name','code','slug'], 'name');
$ROLE_LABEL = pick($roleCols, ['label','title','display'], $ROLE_NAME);

/* ---- role_permissions table map ---- */
$rpCols = cols($pdo, 'role_permissions');
$RP_ROLE = pick($rpCols, ['role_id','rid','role'], 'role_id');
$RP_PERM = pick($rpCols, ['permission_id','perm_id','permission','pid'], 'permission_id');

/* ---- users table map ---- */
$userCols = cols($pdo, 'users');
$U_ID   = pick($userCols, ['id'], 'id');
$U_UN   = pick($userCols, ['username','user','login']);
if (!$U_UN) throw new RuntimeException("users table: need username-like column (username/user/login).");
$U_NAME = pick($userCols, ['name']);
$U_MAIL = pick($userCols, ['email']);
$U_ROLE = pick($userCols, ['role_id','rid','role'], 'role_id');

// ---- CSRF ----
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];

$msg = ''; $err = '';

/* ---------------- Export helper (pre-output) ---------------- */
function stream_json_download(string $filename, array $data): void {
  header('Content-Type: application/json');
  header('Content-Disposition: attachment; filename="'.$filename.'"');
  echo json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
  exit;
}

/* ---------------- POST actions ---------------- */
try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) throw new Exception('Invalid request token.');
    $action = $_POST['action'] ?? '';

    /* ===== Role → Permissions save ===== */
    if ($action === 'save_role_perms') {
      $role_id = (int)($_POST['role_id'] ?? 0);
      $perm_codes = $_POST['perm_codes'] ?? [];
      if ($role_id <= 0) throw new Exception('Invalid role.');

      $pdo->beginTransaction();
      $pdo->prepare("DELETE FROM role_permissions WHERE `$RP_ROLE`=?")->execute([$role_id]);

      if (!empty($perm_codes)) {
        $sel = $pdo->prepare("SELECT `$PERM_ID` FROM permissions WHERE `$PERM_CODE` = ?");
        $ins = $pdo->prepare("INSERT INTO role_permissions(`$RP_ROLE`, `$RP_PERM`) VALUES(?, ?)");
        foreach ($perm_codes as $code) {
          $code = trim((string)$code);
          if ($code === '') continue;
          $sel->execute([$code]);
          $pid = $sel->fetchColumn();
          if ($pid) $ins->execute([$role_id, $pid]);
        }
      }
      $pdo->commit();
      $msg = 'Permissions updated.';
      safe_audit('role_permissions.updated', ['role_id'=>$role_id, 'count'=>count($perm_codes)]);
      unset($_SESSION['acl_perms']);
    }

    /* ===== Assign Role to User ===== */
    elseif ($action === 'assign_role') {
      $user_id = (int)($_POST['user_id'] ?? 0);
      $role_id = (int)($_POST['role_id'] ?? 0); // 0 => NULL
      if ($user_id <= 0) throw new Exception('Invalid user.');
      $stmt = $pdo->prepare("UPDATE users SET `$U_ROLE`=:r WHERE `$U_ID`=:u");
      $stmt->execute([':r' => ($role_id ?: null), ':u' => $user_id]);
      $msg = 'User role updated.';
      safe_audit('user.role.assigned', ['user_id'=>$user_id, 'role_id'=>($role_id ?: null)]);
      unset($_SESSION['acl_perms']);
    }

    /* ===== Create Role ===== */
    elseif ($action === 'add_role') {
      $name  = trim($_POST['name'] ?? '');
      $label = trim($_POST['label'] ?? '');
      if ($name === '' || $label === '') throw new Exception('Role name/label required.');
      $stmt = $pdo->prepare("INSERT INTO roles(`$ROLE_NAME`,`$ROLE_LABEL`) VALUES(?,?)");
      $stmt->execute([$name,$label]);
      $msg = 'Role created.';
      safe_audit('role.created', ['name'=>$name,'label'=>$label]);
    }

    /* ===== Rename/Update Role ===== */
    elseif ($action === 'rename_role') {
      $role_id = (int)($_POST['role_id'] ?? 0);
      $name  = trim($_POST['name'] ?? '');
      $label = trim($_POST['label'] ?? '');
      if ($role_id<=0 || $name==='' || $label==='') throw new Exception('Invalid role or empty fields.');
      $pdo->prepare("UPDATE roles SET `$ROLE_NAME`=?, `$ROLE_LABEL`=? WHERE `$ROLE_ID`=?")->execute([$name,$label,$role_id]);
      $msg = 'Role updated.';
      safe_audit('role.updated', ['role_id'=>$role_id,'name'=>$name,'label'=>$label]);
      unset($_SESSION['acl_perms']);
    }

    /* ===== Delete Role ===== */
    elseif ($action === 'delete_role') {
      $role_id = (int)($_POST['role_id'] ?? 0);
      if ($role_id <= 0) throw new Exception('Invalid role.');
      $s = $pdo->prepare("SELECT `$ROLE_NAME` FROM roles WHERE `$ROLE_ID`=?"); $s->execute([$role_id]); $rname = (string)$s->fetchColumn();
      if (strtolower($rname) === 'admin') throw new Exception('Cannot delete admin role.');
      $pdo->prepare("UPDATE users SET `$U_ROLE`=NULL WHERE `$U_ROLE`=?")->execute([$role_id]);
      $pdo->prepare("DELETE FROM role_permissions WHERE `$RP_ROLE`=?")->execute([$role_id]);
      $pdo->prepare("DELETE FROM roles WHERE `$ROLE_ID`=?")->execute([$role_id]);
      $msg = 'Role deleted.';
      safe_audit('role.deleted', ['role_id'=>$role_id,'name'=>$rname]);
      unset($_SESSION['acl_perms']);
    }

    /* ===== Clone Role ===== */
    elseif ($action === 'clone_role') {
      $src_id = (int)($_POST['src_role_id'] ?? 0);
      $name   = trim($_POST['name'] ?? '');
      $label  = trim($_POST['label'] ?? '');
      if ($src_id<=0 || $name==='' || $label==='') throw new Exception('Source role and new name/label required.');
      $pdo->beginTransaction();
      $pdo->prepare("INSERT INTO roles(`$ROLE_NAME`,`$ROLE_LABEL`) VALUES(?,?)")->execute([$name,$label]);
      $new_id = (int)$pdo->lastInsertId();
      $pdo->prepare("INSERT INTO role_permissions(`$RP_ROLE`,`$RP_PERM`)
                     SELECT ?, `$RP_PERM` FROM role_permissions WHERE `$RP_ROLE`=?")->execute([$new_id,$src_id]);
      $pdo->commit();
      $msg = 'Role cloned.';
      safe_audit('role.cloned', ['src_role_id'=>$src_id,'new_role_id'=>$new_id,'name'=>$name,'label'=>$label]);
      unset($_SESSION['acl_perms']);
    }

    /* ===== Export Role (JSON) ===== */
    elseif ($action === 'export_role') {
      $role_id = (int)($_POST['role_id'] ?? 0);
      if ($role_id<=0) throw new Exception('Invalid role.');
      // role basic
      $st = $pdo->prepare("SELECT `$ROLE_NAME` AS name, `$ROLE_LABEL` AS label FROM roles WHERE `$ROLE_ID`=? LIMIT 1");
      $st->execute([$role_id]);
      $role = $st->fetch(PDO::FETCH_ASSOC) ?: [];
      // permission codes
      $ps = $pdo->prepare("SELECT p.`$PERM_CODE` AS code FROM role_permissions rp JOIN permissions p ON p.`$PERM_ID`=rp.`$RP_PERM` WHERE rp.`$RP_ROLE`=? ORDER BY p.`$PERM_CODE`");
      $ps->execute([$role_id]);
      $codes = $ps->fetchAll(PDO::FETCH_COLUMN) ?: [];
      $payload = ['role'=>$role, 'permissions'=>$codes, 'exported_at'=>date('c')];
      safe_audit('role.exported', ['role_id'=>$role_id,'count'=>count($codes)]);
      stream_json_download('role_'.$role_id.'_export.json', $payload); // exit
    }

    /* ===== Import Role Perms (by code) ===== */
    elseif ($action === 'import_role_perms') {
      $role_id = (int)($_POST['role_id'] ?? 0);
      $json = trim($_POST['json'] ?? '');
      if ($role_id<=0 || $json==='') throw new Exception('Role & JSON required.');
      $data = json_decode($json, true);
      if (!is_array($data)) throw new Exception('Invalid JSON.');
      $codes = $data['permissions'] ?? $data['perms'] ?? [];
      if (!is_array($codes)) throw new Exception('JSON must contain "permissions" array.');
      // save like save_role_perms
      $pdo->beginTransaction();
      $pdo->prepare("DELETE FROM role_permissions WHERE `$RP_ROLE`=?")->execute([$role_id]);
      if (!empty($codes)) {
        $sel = $pdo->prepare("SELECT `$PERM_ID` FROM permissions WHERE `$PERM_CODE` = ?");
        $ins = $pdo->prepare("INSERT INTO role_permissions(`$RP_ROLE`, `$RP_PERM`) VALUES(?, ?)");
        foreach ($codes as $code) {
          $code = trim((string)$code);
          if ($code==='') continue;
          $sel->execute([$code]);
          $pid = $sel->fetchColumn();
          if ($pid) $ins->execute([$role_id,$pid]);
        }
      }
      $pdo->commit();
      $msg = 'Permissions imported to role.';
      safe_audit('role_permissions.imported', ['role_id'=>$role_id,'count'=>count($codes)]);
      unset($_SESSION['acl_perms']);
    }

    /* ===== Permission CRUD ===== */

    elseif ($action === 'add_permission') {
      $code  = trim($_POST['code'] ?? '');
      $label = trim($_POST['label'] ?? '');
      $cat   = trim($_POST['category'] ?? '');
      if ($code==='' || $label==='') throw new Exception('Permission code/label required.');
      // duplicate guard
      $chk = $pdo->prepare("SELECT COUNT(1) FROM permissions WHERE `$PERM_CODE`=?");
      $chk->execute([$code]);
      if ($chk->fetchColumn() > 0) throw new Exception('Permission code already exists.');
      $sql = $PERM_CAT
        ? "INSERT INTO permissions(`$PERM_CODE`,`$PERM_LABEL`,`$PERM_CAT`) VALUES(?,?,?)"
        : "INSERT INTO permissions(`$PERM_CODE`,`$PERM_LABEL`) VALUES(?,?)";
      $st = $pdo->prepare($sql);
      $PERM_CAT ? $st->execute([$code,$label,$cat]) : $st->execute([$code,$label]);
      $msg = 'Permission created.';
      safe_audit('permission.created', ['code'=>$code,'label'=>$label,'category'=>$cat ?: null]);
    }

    elseif ($action === 'edit_permission') {
      $id    = (int)($_POST['id'] ?? 0);
      $code  = trim($_POST['code'] ?? '');
      $label = trim($_POST['label'] ?? '');
      $cat   = trim($_POST['category'] ?? '');
      if ($id<=0 || $code==='' || $label==='') throw new Exception('Invalid permission.');
      // duplicate guard (exclude self)
      $chk = $pdo->prepare("SELECT COUNT(1) FROM permissions WHERE `$PERM_CODE`=? AND `$PERM_ID`<>?");
      $chk->execute([$code,$id]);
      if ($chk->fetchColumn() > 0) throw new Exception('Permission code already exists.');
      $sql = $PERM_CAT
        ? "UPDATE permissions SET `$PERM_CODE`=?, `$PERM_LABEL`=?, `$PERM_CAT`=? WHERE `$PERM_ID`=?"
        : "UPDATE permissions SET `$PERM_CODE`=?, `$PERM_LABEL`=? WHERE `$PERM_ID`=?";
      $st = $pdo->prepare($sql);
      $PERM_CAT ? $st->execute([$code,$label,$cat,$id]) : $st->execute([$code,$label,$id]);
      $msg = 'Permission updated.';
      safe_audit('permission.updated', ['id'=>$id,'code'=>$code,'label'=>$label,'category'=>$cat ?: null]);
      unset($_SESSION['acl_perms']);
    }

    elseif ($action === 'delete_permission') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id<=0) throw new Exception('Invalid permission.');
      $pdo->beginTransaction();
      // detach from role_permissions first (safe)
      $pdo->prepare("DELETE rp FROM role_permissions rp WHERE rp.`$RP_PERM`=?")->execute([$id]);
      $pdo->prepare("DELETE FROM permissions WHERE `$PERM_ID`=?")->execute([$id]);
      $pdo->commit();
      $msg = 'Permission deleted.';
      safe_audit('permission.deleted', ['id'=>$id]);
      unset($_SESSION['acl_perms']);
    }
  }
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  $err = $e->getMessage();
}

/* ---------------- Load data (schema-aware) ---------------- */
$roles = $pdo->query("SELECT `$ROLE_ID` AS id, `$ROLE_NAME` AS name, `$ROLE_LABEL` AS label FROM roles ORDER BY `$ROLE_NAME`")
             ->fetchAll(PDO::FETCH_ASSOC);

$permOrder = $PERM_CAT ? "`$PERM_CAT`, `$PERM_CODE`" : "`$PERM_CODE`";
$permissions = $pdo->query("SELECT `$PERM_ID` AS id, `$PERM_CODE` AS code, `$PERM_LABEL` AS name"
                          .($PERM_CAT? ", `$PERM_CAT` AS category":"")
                          ." FROM permissions ORDER BY $permOrder")->fetchAll(PDO::FETCH_ASSOC);

// Role select default
$selected_role_id = (int)($_GET['role'] ?? ($_POST['role_id'] ?? 0));
if ($selected_role_id <= 0 && !empty($roles)) $selected_role_id = (int)$roles[0]['id'];

// Role → current perms map
$role_perm_map = [];
if ($selected_role_id > 0) {
  $st = $pdo->prepare("
    SELECT p.`$PERM_CODE`
      FROM role_permissions rp
      JOIN permissions p ON p.`$PERM_ID` = rp.`$RP_PERM`
     WHERE rp.`$RP_ROLE` = ?
  ");
  $st->execute([$selected_role_id]);
  foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) $role_perm_map[$c] = true;
}

// Users list
$sqlUsers = "SELECT `$U_ID` AS id, `$U_UN` AS username"
          . ($U_NAME ? ", `$U_NAME` AS name" : "")
          . ($U_MAIL ? ", `$U_MAIL` AS email" : "")
          . ", `$U_ROLE` AS role_id FROM users ORDER BY `$U_UN`";
$users = $pdo->query($sqlUsers)->fetchAll(PDO::FETCH_ASSOC);

/* ===== Include header AFTER data load (safe for export exit) ===== */
require_once __DIR__ . '/../partials/partials_header.php';
?>
<style>
  /* Mobile-first responsive styles */
  .toolbar { 
    gap: .5rem; 
    flex-wrap: wrap;
  }
  
  .perm-list { 
    border: 1px solid #eee; 
    border-radius: .75rem; 
    overflow: hidden; 
  }
  
  .perm-item, .perm-row {
    display: grid; 
    grid-template-columns: 1fr auto;
    gap: .75rem; 
    align-items: center; 
    padding: .75rem .9rem; 
    border-top: 1px solid #f2f2f2;
  }
  
  .perm-row:first-child, .perm-item:first-child { 
    border-top: 0; 
  }
  
  .perm-row:hover, .perm-item:hover { 
    background: #fafafa; 
  }
  
  .perm-row.checked, .perm-item.checked { 
    background: #f7fff7; 
  }
  
  .perm-code { 
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, "Courier New", monospace; 
    font-size: 0.85rem;
    word-break: break-all;
  }
  
  .perm-muted { 
    color: #6c757d; 
    font-size: .95rem; 
  }
  
  .badge-cat { 
    background: #f2f2f2; 
    color: #555; 
    font-size: 0.75rem;
  }
  
  .form-switch .form-check-input { 
    width: 2.9rem; 
    height: 1.45rem; 
    cursor: pointer; 
  }
  
  .sticky-save {
    position: sticky; 
    bottom: 0; 
    z-index: 2; 
    background: var(--bs-body-bg);
    border-top: 1px solid #e9ecef; 
    padding: .75rem 1rem;
  }
  
  /* Mobile responsiveness improvements */
  @media (max-width: 768px) {
    .container {
      padding-left: 0.5rem;
      padding-right: 0.5rem;
    }
    
    .perm-row {
      grid-template-columns: 1fr;
      gap: 0.5rem;
      padding: 0.75rem;
    }
    
    .perm-row > div:last-child {
      justify-self: start;
    }
    
    .toolbar {
      flex-direction: column;
      align-items: stretch;
    }
    
    .toolbar > div {
      margin-bottom: 0.5rem;
    }
    
    .toolbar .d-flex {
      flex-wrap: wrap;
      gap: 0.25rem;
    }
    
    .form-control, .form-select {
      font-size: 0.9rem;
    }
    
    .btn-sm {
      font-size: 0.8rem;
      padding: 0.25rem 0.5rem;
    }
    
    .table-responsive {
      font-size: 0.85rem;
    }
    
    .table th, .table td {
      padding: 0.5rem 0.25rem;
      white-space: nowrap;
    }
    
    .sticky-save {
      padding: 0.5rem;
    }
    
    .sticky-save .d-flex {
      flex-direction: column;
      gap: 0.5rem;
    }
    
    .sticky-save .d-flex > div:first-child {
      order: 2;
      text-align: center;
    }
    
    .sticky-save .d-flex > div:last-child {
      order: 1;
    }
  }
  
  @media (max-width: 576px) {
    .nav-pills .nav-link {
      font-size: 0.8rem;
      padding: 0.5rem 0.75rem;
    }
    
    .card-header {
      padding: 0.75rem;
    }
    
    .card-body {
      padding: 0.75rem;
    }
    
    .perm-code {
      font-size: 0.75rem;
    }
    
    .perm-muted {
      font-size: 0.85rem;
    }
    
    .badge-cat {
      font-size: 0.7rem;
    }
    
    .modal-dialog {
      margin: 0.5rem;
    }
    
    .modal-body {
      padding: 1rem;
    }
    
    .form-label {
      font-size: 0.9rem;
      margin-bottom: 0.25rem;
    }
  }
  
  /* Table responsiveness */
  .table-responsive {
    border: none;
  }
  
  .table {
    margin-bottom: 0;
  }
  
  /* Form improvements */
  .row.g-2 > * {
    margin-bottom: 0.5rem;
  }
  
  .row.g-3 > * {
    margin-bottom: 1rem;
  }
  
  /* Button group improvements */
  .btn-group-vertical .btn {
    margin-bottom: 0.25rem;
  }
  
  /* Search and filter improvements */
  #permSearch {
    min-width: 200px;
  }
  
  @media (max-width: 768px) {
    #permSearch {
      min-width: 150px;
    }
  }
  
  @media (max-width: 576px) {
    #permSearch {
      min-width: 120px;
    }
  }
  
  /* Empty state styling */
  .empty-hint {
    text-align: center;
    padding: 2rem;
    color: #6c757d;
    font-style: italic;
  }
</style>

<div class="container py-3">
  <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between mb-2">
    <h5 class="m-0 mb-2 mb-md-0"><i class="bi bi-shield-lock"></i> Roles & Permissions</h5>
    <span class="text-muted small d-none d-sm-inline">Minimal, mobile-friendly permission management</span>
  </div>

  <?php if ($msg): ?><div class="alert alert-success"><?php echo h($msg); ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger"><?php echo h($err); ?></div><?php endif; ?>

  <!-- Top Tabs (back to top as requested) -->
  <ul class="nav nav-pills my-3 flex-wrap" id="tabs">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-matrix"><i class="bi bi-grid-3x3-gap"></i> <span class="d-none d-sm-inline">Role → Permissions</span><span class="d-sm-none">Matrix</span></a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-assign"><i class="bi bi-person-gear"></i> <span class="d-none d-sm-inline">Assign Role</span><span class="d-sm-none">Assign</span></a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-roles"><i class="bi bi-diagram-3"></i> <span class="d-none d-sm-inline">Manage Roles</span><span class="d-sm-none">Roles</span></a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-perms"><i class="bi bi-list-check"></i> <span class="d-none d-sm-inline">Permissions</span><span class="d-sm-none">Perms</span></a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-import-export"><i class="bi bi-filetype-json"></i> <span class="d-none d-sm-inline">Import/Export</span><span class="d-sm-none">I/E</span></a></li>
  </ul>

  <div class="tab-content">

    <!-- TAB: Role → Permission matrix -->
    <div class="tab-pane fade show active" id="tab-matrix">
      <form method="post" class="card border-0">
        <div class="card-header d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2">
          <div class="mb-2 mb-md-0">
            <strong>Permission Matrix</strong>
            <div class="small text-muted">Select a role and toggle permissions</div>
          </div>
          <select name="role_id" class="form-select form-select-sm w-100 w-md-auto" style="max-width:260px"
                  onchange="location.href='?role='+encodeURIComponent(this.value)+'#tab-matrix'">
            <?php foreach($roles as $r): ?>
              <option value="<?php echo (int)$r['id']; ?>" <?php echo $selected_role_id==$r['id']?'selected':''; ?>>
                <?php echo h($r['label'].' ('.$r['name'].')'); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="card-body">

          <!-- Toolbar -->
          <div class="toolbar d-flex flex-column flex-md-row justify-content-between align-items-stretch align-items-md-center mb-3 gap-2">
            <div class="d-flex flex-column flex-md-row align-items-stretch align-items-md-center gap-2 flex-wrap">
              <input type="text" id="permSearch" class="form-control form-control-sm" placeholder="Search permissions (code/label/category)" style="min-width:220px;">
              <?php if($PERM_CAT): ?>
                <select id="permCategory" class="form-select form-select-sm">
                  <option value="">All categories</option>
                  <?php
                    $cats=[]; foreach($permissions as $p) $cats[]=(string)($p['category'] ?? '');
                    $cats = array_values(array_unique($cats));
                    foreach($cats as $c){
                      $label = trim($c) !== '' ? $c : '(Uncategorized)';
                      echo '<option value="'.h(strtolower($c)).'">'.h($label).'</option>';
                    }
                  ?>
                </select>
              <?php endif; ?>
              <div class="d-flex gap-2">
                <button type="button" id="btnSelectFiltered" class="btn btn-outline-success btn-sm">Select filtered</button>
                <button type="button" id="btnDeselectFiltered" class="btn btn-outline-secondary btn-sm">Deselect</button>
              </div>
            </div>
            <div class="d-flex flex-column flex-md-row align-items-center gap-2">
              <div class="text-muted small text-center text-md-start">
                Selected: <span id="selCount">0</span> / <?php echo count($permissions); ?>
              </div>
              <!-- Global New Permission -->
              <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#permCreateModal">
                <i class="bi bi-plus-lg"></i> <span class="d-none d-sm-inline">New Permission</span><span class="d-sm-none">New</span>
              </button>
            </div>
          </div>

          <!-- Permission list -->
          <div id="permList" class="perm-list">
            <?php
            $currCat = null;
            foreach($permissions as $p):
              $code = h($p['code']);
              $label = h($p['name']);
              $catRaw = $PERM_CAT ? (string)($p['category'] ?? '') : '';
              $cat    = h($catRaw);
              $isChecked = !empty($role_perm_map[$p['code']]);

              if ($PERM_CAT && $catRaw !== $currCat) {
                $currCat = $catRaw;
                echo '<div class="px-3 py-2 bg-light border-top small"><span class="badge badge-cat">'.($cat!==''?$cat:'(Uncategorized)').'</span></div>';
              }
            ?>
              <div class="perm-row <?php echo $isChecked ? 'checked' : ''; ?>"
                   data-code="<?php echo strtolower($code); ?>"
                   data-name="<?php echo strtolower($label); ?>"
                   data-cat="<?php echo strtolower($cat); ?>">
                <div>
                  <span class="perm-code me-2"><code><?php echo $code; ?></code></span>
                  <span class="perm-muted"><?php echo $label; ?></span>
                  <?php if($PERM_CAT): ?><span class="badge badge-cat ms-2"><?php echo $cat!==''?$cat:'—'; ?></span><?php endif; ?>
                </div>
                <div class="text-end">
                  <div class="form-check form-switch m-0">
                    <input class="form-check-input perm-toggle"
                           type="checkbox"
                           name="perm_codes[]"
                           value="<?php echo $code; ?>"
                           <?php echo $isChecked ? 'checked' : ''; ?>>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="sticky-save d-flex flex-column flex-md-row align-items-center justify-content-between gap-2">
          <div class="text-muted small text-center text-md-start">
            Role:
            <?php $rmap = array_column($roles, null, 'id');
              echo isset($rmap[$selected_role_id]) ? h($rmap[$selected_role_id]['label'].' ('.$rmap[$selected_role_id]['name'].')') : '—';
            ?>
          </div>
          <div class="d-flex gap-2 w-100 w-md-auto justify-content-center justify-content-md-end">
            <input type="hidden" name="role_id" value="<?php echo (int)$selected_role_id; ?>">
            <input type="hidden" name="action" value="save_role_perms">
            <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
            <a class="btn btn-outline-secondary btn-sm" href="?role=<?php echo (int)$selected_role_id; ?>#tab-matrix">Reset</a>
            <button class="btn btn-success btn-sm"><i class="bi bi-save"></i> Save</button>
          </div>
        </div>
      </form>
    </div>

    <!-- TAB: Assign Role -->
    <div class="tab-pane fade" id="tab-assign">
      <div class="card">
        <div class="card-header"><strong>Assign Role to User</strong></div>
        <div class="card-body">
          <form method="post" class="row g-2 align-items-end">
            <div class="col-12 col-md-5">
              <label class="form-label">User</label>
              <select name="user_id" class="form-select" required>
                <?php foreach($users as $u): ?>
                  <option value="<?php echo (int)$u['id']; ?>">
                    <?php $disp=$u['username']; if(!empty($u['name']))$disp.=' — '.$u['name']; if(!empty($u['email']))$disp.=' — '.$u['email']; echo h($disp); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-5">
              <label class="form-label">Role</label>
              <select name="role_id" class="form-select">
                <option value="">(No Role)</option>
                <?php foreach($roles as $r): ?>
                  <option value="<?php echo (int)$r['id']; ?>"><?php echo h($r['label'].' ('.$r['name'].')'); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-2 text-end">
              <input type="hidden" name="action" value="assign_role">
              <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
              <button class="btn btn-primary w-100"><i class="bi bi-check2-circle"></i> <span class="d-none d-sm-inline">Assign</span><span class="d-sm-none">✓</span></button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- TAB: Manage Roles -->
    <div class="tab-pane fade" id="tab-roles">
      <div class="row g-3">
        <div class="col-12 col-lg-6">
          <div class="card h-100">
            <div class="card-header"><strong>Existing Roles</strong></div>
            <div class="card-body table-responsive">
              <table class="table table-sm align-middle">
                <thead class="table-light">
                  <tr>
                    <th class="d-none d-sm-table-cell">ID</th>
                    <th>Name</th>
                    <th class="d-none d-md-table-cell">Label</th>
                    <th class="text-end">Action</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach($roles as $r): ?>
                  <tr>
                    <td class="d-none d-sm-table-cell"><?php echo (int)$r['id']; ?></td>
                    <td>
                      <code><?php echo h($r['name']); ?></code>
                      <div class="d-md-none small text-muted"><?php echo h($r['label']); ?></div>
                    </td>
                    <td class="d-none d-md-table-cell"><?php echo h($r['label']); ?></td>
                    <td class="text-end">
                      <div class="btn-group-vertical btn-group-sm d-md-none" role="group">
                        <a class="btn btn-outline-primary" href="?role=<?php echo (int)$r['id']; ?>#tab-matrix" title="Configure"><i class="bi bi-sliders"></i></a>
                        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#rn<?php echo (int)$r['id']; ?>" title="Rename"><i class="bi bi-pencil-square"></i></button>
                        <button type="button" class="btn btn-outline-info" data-bs-toggle="collapse" data-bs-target="#cl<?php echo (int)$r['id']; ?>" title="Clone"><i class="bi bi-files"></i></button>
                        <?php if (strtolower($r['name']) !== 'admin'): ?>
                          <form method="post" onsubmit="return confirm('Delete role & detach users?');" class="d-inline">
                            <input type="hidden" name="action" value="delete_role">
                            <input type="hidden" name="role_id" value="<?php echo (int)$r['id']; ?>">
                            <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                            <button class="btn btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                          </form>
                        <?php else: ?>
                          <span class="badge bg-secondary">System</span>
                        <?php endif; ?>
                      </div>
                      
                      <div class="d-none d-md-flex gap-1">
                        <a class="btn btn-outline-primary btn-sm" href="?role=<?php echo (int)$r['id']; ?>#tab-matrix" title="Configure"><i class="bi bi-sliders"></i></a>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="collapse" data-bs-target="#rn<?php echo (int)$r['id']; ?>" title="Rename"><i class="bi bi-pencil-square"></i></button>
                        <button type="button" class="btn btn-outline-info btn-sm" data-bs-toggle="collapse" data-bs-target="#cl<?php echo (int)$r['id']; ?>" title="Clone"><i class="bi bi-files"></i></button>
                        <?php if (strtolower($r['name']) !== 'admin'): ?>
                          <form method="post" onsubmit="return confirm('Delete role & detach users?');" class="d-inline">
                            <input type="hidden" name="action" value="delete_role">
                            <input type="hidden" name="role_id" value="<?php echo (int)$r['id']; ?>">
                            <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                            <button class="btn btn-outline-danger btn-sm" title="Delete"><i class="bi bi-trash"></i></button>
                          </form>
                        <?php else: ?>
                          <span class="badge bg-secondary">System</span>
                        <?php endif; ?>
                      </div>

                      <!-- Rename -->
                      <div id="rn<?php echo (int)$r['id']; ?>" class="collapse mt-2">
                        <form method="post" class="row g-1">
                          <div class="col-12 col-sm-5">
                            <input type="text" name="name" class="form-control form-control-sm" value="<?php echo h($r['name']); ?>" required placeholder="Role name">
                          </div>
                          <div class="col-12 col-sm-5">
                            <input type="text" name="label" class="form-control form-control-sm" value="<?php echo h($r['label']); ?>" required placeholder="Role label">
                          </div>
                          <div class="col-12 col-sm-2 text-end">
                            <input type="hidden" name="action" value="rename_role">
                            <input type="hidden" name="role_id" value="<?php echo (int)$r['id']; ?>">
                            <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                            <button class="btn btn-success btn-sm w-100"><i class="bi bi-save"></i></button>
                          </div>
                        </form>
                      </div>

                      <!-- Clone -->
                      <div id="cl<?php echo (int)$r['id']; ?>" class="collapse mt-2">
                        <form method="post" class="row g-1">
                          <div class="col-12 col-sm-5">
                            <input type="text" name="name" class="form-control form-control-sm" placeholder="new_role_name" required>
                          </div>
                          <div class="col-12 col-sm-5">
                            <input type="text" name="label" class="form-control form-control-sm" placeholder="New Role Label" required>
                          </div>
                          <div class="col-12 col-sm-2 text-end">
                            <input type="hidden" name="action" value="clone_role">
                            <input type="hidden" name="src_role_id" value="<?php echo (int)$r['id']; ?>">
                            <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                            <button class="btn btn-info btn-sm w-100"><i class="bi bi-check2"></i></button>
                          </div>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="col-12 col-lg-6">
          <div class="card h-100">
            <div class="card-header"><strong>Create New Role</strong></div>
            <div class="card-body">
              <form method="post" class="vstack gap-2">
                <div>
                  <label class="form-label">Role Name (unique, e.g., manager)</label>
                  <input type="text" name="name" class="form-control" required>
                </div>
                <div>
                  <label class="form-label">Role Label (e.g., Manager)</label>
                  <input type="text" name="label" class="form-control" required>
                </div>
                <input type="hidden" name="action" value="add_role">
                <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                <button class="btn btn-success"><i class="bi bi-plus-lg"></i> Add Role</button>
              </form>
            </div>
          </div>
        </div>
      </div> <!-- row -->
    </div>

    <!-- TAB: Permissions CRUD -->
    <div class="tab-pane fade" id="tab-perms">
      <div class="row g-3">
        <div class="col-12 col-lg-6">
          <div class="card h-100">
            <div class="card-header"><strong>Existing Permissions</strong></div>
            <div class="card-body table-responsive">
              <table class="table table-sm align-middle">
                <thead class="table-light">
                  <tr>
                    <th>Code</th>
                    <th class="d-none d-md-table-cell">Label</th>
                    <?php if($PERM_CAT): ?><th class="d-none d-lg-table-cell">Category</th><?php endif; ?>
                    <th class="text-end">Action</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach($permissions as $p): ?>
                  <tr>
                    <td>
                      <code><?php echo h($p['code']); ?></code>
                      <div class="d-md-none small text-muted"><?php echo h($p['name']); ?></div>
                      <?php if($PERM_CAT): ?><div class="d-lg-none small"><span class="badge badge-cat"><?php echo h((string)($p['category'] ?? '')); ?></span></div><?php endif; ?>
                    </td>
                    <td class="d-none d-md-table-cell"><?php echo h($p['name']); ?></td>
                    <?php if($PERM_CAT): ?><td class="d-none d-lg-table-cell"><?php echo h((string)($p['category'] ?? '')); ?></td><?php endif; ?>
                    <td class="text-end">
                      <div class="btn-group-vertical btn-group-sm d-lg-none" role="group">
                        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#pe<?php echo (int)$p['id']; ?>" title="Edit"><i class="bi bi-pencil-square"></i></button>
                        <form method="post" onsubmit="return confirm('Delete permission and detach from roles?');" class="d-inline">
                          <input type="hidden" name="action" value="delete_permission">
                          <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                          <button class="btn btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                        </form>
                      </div>
                      
                      <div class="d-none d-lg-flex gap-1">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="collapse" data-bs-target="#pe<?php echo (int)$p['id']; ?>" title="Edit"><i class="bi bi-pencil-square"></i></button>
                        <form method="post" onsubmit="return confirm('Delete permission and detach from roles?');" class="d-inline">
                          <input type="hidden" name="action" value="delete_permission">
                          <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                          <button class="btn btn-outline-danger btn-sm" title="Delete"><i class="bi bi-trash"></i></button>
                        </form>
                      </div>

                      <!-- Edit -->
                      <div id="pe<?php echo (int)$p['id']; ?>" class="collapse mt-2">
                        <form method="post" class="row g-1">
                          <div class="col-12 col-sm-4">
                            <input type="text" name="code" class="form-control form-control-sm" value="<?php echo h($p['code']); ?>" required placeholder="Code">
                          </div>
                          <div class="col-12 col-sm-4">
                            <input type="text" name="label" class="form-control form-control-sm" value="<?php echo h($p['name']); ?>" required placeholder="Label">
                          </div>
                          <?php if($PERM_CAT): ?>
                          <div class="col-12 col-sm-3">
                            <input type="text" name="category" class="form-control form-control-sm" value="<?php echo h((string)($p['category'] ?? '')); ?>" placeholder="Category">
                          </div>
                          <?php endif; ?>
                          <div class="col-12 col-sm-1 text-end">
                            <input type="hidden" name="action" value="edit_permission">
                            <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                            <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                            <button class="btn btn-success btn-sm w-100"><i class="bi bi-save"></i></button>
                          </div>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
              <div class="small text-muted">Tip: Keep codes stable; apps rely on codes.</div>
            </div>
          </div>
        </div>

        <div class="col-12 col-lg-6">
          <div class="card h-100">
            <div class="card-header"><strong>Add Permission</strong></div>
            <div class="card-body">
              <form method="post" class="vstack gap-2">
                <div>
                  <label class="form-label">Code (unique, e.g., hr.toggle)</label>
                  <input type="text" name="code" class="form-control" required>
                </div>
                <div>
                  <label class="form-label">Label</label>
                  <input type="text" name="label" class="form-control" required>
                </div>
                <?php if($PERM_CAT): ?>
                <div>
                  <label class="form-label">Category (optional)</label>
                  <input type="text" name="category" class="form-control" placeholder="e.g., HR / Billing / Network">
                </div>
                <?php endif; ?>
                <input type="hidden" name="action" value="add_permission">
                <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                <button class="btn btn-success"><i class="bi bi-plus-lg"></i> Add Permission</button>
              </form>
            </div>
          </div>
        </div>
      </div><!-- row -->
    </div>

    <!-- TAB: Import / Export -->
    <div class="tab-pane fade" id="tab-import-export">
      <div class="row g-3">
        <div class="col-12 col-lg-6">
          <div class="card h-100">
            <div class="card-header"><strong>Export Role (JSON)</strong></div>
            <div class="card-body">
              <form method="post" class="row g-2 align-items-end">
                <div class="col-12 col-md-8">
                  <label class="form-label">Role</label>
                  <select name="role_id" class="form-select" required>
                    <?php foreach($roles as $r): ?>
                      <option value="<?php echo (int)$r['id']; ?>"><?php echo h($r['label'].' ('.$r['name'].')'); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-12 col-md-4 text-end">
                  <input type="hidden" name="action" value="export_role">
                  <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                  <button class="btn btn-outline-primary w-100"><i class="bi bi-download"></i> <span class="d-none d-sm-inline">Download JSON</span><span class="d-sm-none">Export</span></button>
                </div>
              </form>
              <div class="small text-muted mt-2">Contains role name/label and permission codes.</div>
            </div>
          </div>
        </div>

        <div class="col-12 col-lg-6">
          <div class="card h-100">
            <div class="card-header"><strong>Import Permissions → Role</strong></div>
            <div class="card-body">
              <form method="post" class="vstack gap-2">
                <div>
                  <label class="form-label">Role</label>
                  <select name="role_id" class="form-select" required>
                    <?php foreach($roles as $r): ?>
                      <option value="<?php echo (int)$r['id']; ?>"><?php echo h($r['label'].' ('.$r['name'].')'); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label class="form-label">Permissions JSON</label>
                  <textarea name="json" class="form-control" rows="6" placeholder='{"permissions":["hr.toggle","users.manage","clients.view"]}' required></textarea>
                </div>
                <input type="hidden" name="action" value="import_role_perms">
                <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                <button class="btn btn-success"><i class="bi bi-upload"></i> <span class="d-none d-sm-inline">Import</span><span class="d-sm-none">Import</span></button>
              </form>
              <div class="small text-muted mt-2">This replaces existing permissions for the selected role.</div>
            </div>
          </div>
        </div>
      </div><!-- row -->
    </div>

  </div><!-- /.tab-content -->
</div>

<?php
  // category কলাম আছে কি না – একই কন্ডিশন ব্যবহার করছি
  $HAS_CATEGORY = (bool) $PERM_CAT;
?>
<!-- Global: New Permission Modal -->
<div class="modal fade" id="permCreateModal" tabindex="-1" aria-labelledby="permCreateLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content" id="permCreateForm">
      <div class="modal-header">
        <h6 class="modal-title" id="permCreateLabel"><i class="bi bi-plus-lg"></i> Add Permission</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Code <span class="text-danger">*</span></label>
          <input type="text" name="code" class="form-control" placeholder="e.g., hr.toggle" required>
          <div class="form-text">Unique, stable code (apps rely on this).</div>
        </div>
        <div class="mb-3">
          <label class="form-label">Label <span class="text-danger">*</span></label>
          <input type="text" name="label" class="form-control" placeholder="e.g., HR Toggle" required>
        </div>

        <?php if ($HAS_CATEGORY): ?>
        <div class="mb-3">
          <label class="form-label">Category (optional)</label>
          <input type="text" name="category" class="form-control" placeholder="e.g., HR / Billing / Network">
        </div>
        <?php endif; ?>
      </div>

      <div class="modal-footer">
        <input type="hidden" name="action" value="add_permission">
        <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary">
          <i class="bi bi-save"></i> Save Permission
        </button>
      </div>
    </form>
  </div>
</div>

<script>
  // Bengali: Permission matrix interactions (search, category filter, select/deselect filtered, count, row highlighting), modal UX
  (function(){
    const list = document.getElementById('permList');
    if(!list) return;
    const search = document.getElementById('permSearch');
    const catSel = document.getElementById('permCategory');
    const btnAll = document.getElementById('btnSelectFiltered');
    const btnNone = document.getElementById('btnDeselectFiltered');
    const selCount = document.getElementById('selCount');

    function updateRowStyle(row){
      const toggle = row.querySelector('.perm-toggle');
      row.classList.toggle('checked', toggle && toggle.checked);
    }
    function refreshCount(){
      selCount.textContent = list.querySelectorAll('.perm-toggle:checked').length;
    }
    function filteredRows(){
      return Array.from(list.querySelectorAll('.perm-row')).filter(r => r.style.display !== 'none');
    }

    // init
    Array.from(list.querySelectorAll('.perm-row')).forEach(row=>{
      const toggle = row.querySelector('.perm-toggle');
      if (!toggle) return;
      toggle.addEventListener('change', () => { updateRowStyle(row); refreshCount(); });
      updateRowStyle(row);
    });
    refreshCount();

    // filter
    function applyFilter(){
      const q = (search?.value || '').trim().toLowerCase();
      const cat = (catSel?.value || '').trim();
      let any=false;
      Array.from(list.querySelectorAll('.perm-row')).forEach(row=>{
        const hay = (row.dataset.code||'') + ' ' + (row.dataset.name||'') + ' ' + (row.dataset.cat||'');
        const hitq = q==='' || hay.includes(q);
        const hitc = cat==='' || (row.dataset.cat||'')===cat;
        const show = hitq && hitc;
        row.style.display = show ? '' : 'none';
        if (show) any = true;
      });
      const hintId='emptyHint';
      let hint=document.getElementById(hintId);
      if(!any){
        if(!hint){
          hint=document.createElement('div');
          hint.id=hintId;
          hint.className='empty-hint';
          hint.textContent='No permissions match your search.';
          hint.style.cssText = 'text-align: center; padding: 2rem; color: #6c757d; font-style: italic;';
          list.appendChild(hint);
        }
      } else if(hint){ hint.remove(); }
    }
    search?.addEventListener('input', applyFilter);
    catSel?.addEventListener('change', applyFilter);
    applyFilter();

    // bulk select/deselect for filtered rows
    btnAll?.addEventListener('click', ()=>{
      filteredRows().forEach(row=>{
        const t = row.querySelector('.perm-toggle');
        if (t && !t.checked){ t.checked = true; updateRowStyle(row); }
      });
      refreshCount();
    });
    btnNone?.addEventListener('click', ()=>{
      filteredRows().forEach(row=>{
        const t = row.querySelector('.perm-toggle');
        if (t && t.checked){ t.checked = false; updateRowStyle(row); }
      });
      refreshCount();
    });

    // Modal UX: open হলে ফর্ম রিসেট + code input-এ ফোকাস, submit-এ ডাবল-ক্লিক গার্ড
    const modalEl = document.getElementById('permCreateModal');
    const form = document.getElementById('permCreateForm');
    if (modalEl && form){
      modalEl.addEventListener('shown.bs.modal', function(){
        form.reset();
        const code = form.querySelector('input[name="code"]');
        code && code.focus();
      });
      form.addEventListener('submit', function(){
        const btn = form.querySelector('button.btn.btn-primary');
        if (btn){ btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving…'; }
      });
    }
  })();
</script>

<?php
// ===== Include footer =====
require_once __DIR__ . '/../partials/partials_footer.php';
