<?php
// /public/users.php
// Users: list + create + role change + reset password
//        + Single-button Enable/Disable (no toggle op)
//        + Soft Delete + Hard Delete
// PDO + Bootstrap 5. UI English; comments Bangla.

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

/* ---------- helpers ---------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function db_safe(){ $pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); return $pdo; }

/* (বাংলা) SQL identifier-quote: dynamic কলাম ব্যাকটিক দিয়ে সেফলি */
function qi(string $col): string {
    $c = strtolower(trim($col));
    if ($c === '' || !preg_match('/^[a-z0-9_]+$/', $c)) {
        throw new RuntimeException('Unsafe SQL identifier: '.$col);
    }
    return "`$c`";
}
/* (বাংলা) সিলেক্ট লিস্টে কলাম অপশনাল হলে NULL AS alias */
function qcol_safe(?string $col, string $alias): string {
    $col = $col !== null ? trim($col) : '';
    return $col !== '' ? (qi($col) . " AS " . $alias) : ("NULL AS " . $alias);
}

/* ===== users table introspection ===== */
function users_cols_full(): array {
    static $cols=null; if ($cols!==null) return $cols;
    $st = db_safe()->query("SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE
                            FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'");
    $cols = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r){
        $name = strtolower($r['COLUMN_NAME']);
        $cols[$name] = [
            'name'=>$name,
            'data_type'=>strtolower($r['DATA_TYPE'] ?? ''),
            'column_type'=>strtolower($r['COLUMN_TYPE'] ?? ''),
            'nullable'=> (strtoupper($r['IS_NULLABLE'] ?? '') === 'YES'),
        ];
    }
    return $cols;
}
function users_cols_list(): array { return array_keys(users_cols_full()); }
function pick(array $cands, array $avail, $def=null){
    foreach($cands as $c){ if (in_array(strtolower($c), $avail, true)) return strtolower($c); } return $def;
}
function resolve_cols(): array {
    $av = users_cols_list();
    $ID         = pick(['id','user_id'], $av, 'id'); // ডিফল্ট id
    $USERNAME   = pick(['username','user_name','login','uid'], $av, 'username');
    $NAME       = pick(['full_name','name','display_name','realname'], $av, null); // না থাকলে username দেখাবো
    $EMAIL      = pick(['email','email_address','mail'], $av, null);
    $PASSHASH   = pick(['password_hash','password','pass_hash','passwd'], $av, 'password_hash');
    $ROLE       = pick(['role','user_type','type','user_role'], $av, null);
    $STATUS     = pick(['status','state','active','is_active','enabled'], $av, null);
    $CREATED    = pick(['created_at','created','created_on','inserted_at','reg_date'], $av, null);
    $DELETED    = pick(['is_deleted','deleted','removed','is_remove'], $av, null);
    $DELETED_AT = pick(['deleted_at','removed_at','archived_at'], $av, null);
    return compact('ID','USERNAME','NAME','EMAIL','PASSHASH','ROLE','STATUS','CREATED','DELETED','DELETED_AT');
}
$UC = resolve_cols();

/* ===== status tokens (active/inactive) ===== */
function detect_status_tokens(array $UC): array {
    $cols = users_cols_full();
    $c = strtolower($UC['STATUS'] ?? '');
    if ($c==='' || !isset($cols[$c])) return ['active'=>'active','inactive'=>'inactive','is_bool'=>false];
    $dt = $cols[$c]['data_type']; $ct = $cols[$c]['column_type'];
    if (in_array($dt, ['tinyint','smallint','int','bigint','bit'], true)) return ['active'=>1,'inactive'=>0,'is_bool'=>true];
    if ($dt==='enum' && preg_match_all("/'([^']*)'/", (string)$ct, $m)) {
        $opts = array_map('strtolower', $m[1]);
        if (in_array('active',$opts,true) && in_array('inactive',$opts,true)) return ['active'=>'active','inactive'=>'inactive','is_bool'=>false];
        if (in_array('1',$opts,true) && in_array('0',$opts,true)) return ['active'=>'1','inactive'=>'0','is_bool'=>true];
        return ['active'=>($opts[0]??'active'),'inactive'=>($opts[1]??'inactive'),'is_bool'=>false];
    }
    return ['active'=>'active','inactive'=>'inactive','is_bool'=>false];
}
$STAT = detect_status_tokens($UC);
function is_active_val($v, $tok): bool {
    return $tok['is_bool'] ? ((int)$v === (int)$tok['active']) : (strtolower((string)$v)===strtolower((string)$tok['active']));
}

/* ===== deleted tokens (soft delete) ===== */
function detect_deleted_tokens(array $UC): array {
    $cols = users_cols_full();
    $c = strtolower($UC['DELETED'] ?? '');
    if ($c==='' || !isset($cols[$c])) return ['deleted'=>1,'not_deleted'=>0,'exists'=>false];
    $dt = $cols[$c]['data_type']; $ct = $cols[$c]['column_type'];
    if (in_array($dt, ['tinyint','smallint','int','bigint','bit'], true)) return ['deleted'=>1,'not_deleted'=>0,'exists'=>true];
    if ($dt==='enum' && preg_match_all("/'([^']*)'/", (string)$ct, $m)) {
        $opts = array_map('strtolower', $m[1]);
        $yes = in_array('yes',$opts,true) ? 'yes' : (in_array('1',$opts,true)?'1':($opts[0]??'1'));
        $no  = in_array('no',$opts,true)  ? 'no'  : (in_array('0',$opts,true)?'0':($opts[1]??'0'));
        return ['deleted'=>$yes,'not_deleted'=>$no,'exists'=>true];
    }
    return ['deleted'=>'1','not_deleted'=>'0','exists'=>true];
}
$DEL = detect_deleted_tokens($UC);

/* ===== roles ===== */
function get_allowed_roles(array $UC): array {
    $allowed = [];
    try {
        $cols = users_cols_full();
        $rc   = strtolower($UC['ROLE'] ?? '');
        if ($rc && isset($cols[$rc]) && $cols[$rc]['data_type'] === 'enum') {
            if (preg_match_all("/'([^']*)'/", (string)$cols[$rc]['column_type'], $m)) {
                foreach ($m[1] as $v) { $allowed[] = strtolower($v); }
            }
        }
        if (!$allowed && $rc) {
            $sql = "SELECT DISTINCT ".qi($rc)." AS r FROM `users` WHERE ".qi($rc)." IS NOT NULL AND ".qi($rc)."<>'' LIMIT 200";
            $st = db_safe()->query($sql);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) { $allowed[] = strtolower($v); }
        }
    } catch (Throwable $e) {}
    if (!$allowed) { $allowed = ['account','support','manager','viewer','admin']; }
    return array_values(array_unique($allowed));
}
function pick_safe_default_role(array $opts): string {
    foreach (['account','support','manager','viewer'] as $pref) if (in_array($pref, $opts, true)) return $pref;
    return $opts[0] ?? 'admin';
}
$ROLE_OPTIONS = get_allowed_roles($UC);
$DEFAULT_ROLE = pick_safe_default_role($ROLE_OPTIONS);

/* ===== permissions ===== */
function cur_role(): string {
    $r = (
        $_SESSION['user']['role'] ??
        $_SESSION['role'] ??
        $_SESSION['user_type'] ??
        $_SESSION['SESS_USER_TYPE'] ??
        ''
    );
    $r = strtolower(trim((string)$r));
    if ($r !== '') return $r;
    if (!empty($_SESSION['user']['is_admin']) || !empty($_SESSION['is_admin'])) return 'admin';
    return 'guest';
}
function cur_user_id_guess(array $UC): int {
    $candidates = [
        $_SESSION['user']['id'] ?? null,
        $_SESSION['user_id'] ?? null,
        $_SESSION['uid'] ?? null,
        $_SESSION['SESS_USER_ID'] ?? null,
    ];
    foreach ($candidates as $v) { if (is_numeric($v) && (int)$v>0) return (int)$v; }
    return 0;
}
function can_view_users(): bool {
    return in_array(cur_role(), ['admin','superadmin','manager','account','support','viewer'], true);
}
function can_manage_users(): bool {
    return in_array(cur_role(), ['admin','superadmin'], true);
}
if (!can_view_users()) { http_response_code(403); echo "Permission denied."; exit; }

/* ===== CSRF ===== */
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$CSRF = $_SESSION['csrf'];
function csrf_check(){
    if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) {
        throw new RuntimeException('Invalid CSRF token.');
    }
}

/* ===== POST handler ===== */
$alert = ''; $alert_type = 'success';
try {
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if (!can_manage_users()) throw new RuntimeException('Read-only: your role is not allowed to modify users.');
        csrf_check();
        $pdo = db_safe();
        $op = $_POST['op'] ?? '';

        if ($op==='create') {
            $name     = trim($_POST['name'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $email    = trim($_POST['email'] ?? '');
            $role_in  = strtolower(trim($_POST['role'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            if ($username==='' || $password==='') throw new RuntimeException('Username and Password are required.');
            $role = in_array($role_in, $ROLE_OPTIONS, true) ? $role_in : $DEFAULT_ROLE;

            if (!empty($UC['EMAIL'])) {
                $sql = "SELECT 1 FROM `users` WHERE ".qi($UC['USERNAME'])."=? OR ".qi($UC['EMAIL'])."=? LIMIT 1";
                $dup = $pdo->prepare($sql); $dup->execute([$username, $email]);
            } else {
                $sql = "SELECT 1 FROM `users` WHERE ".qi($UC['USERNAME'])."=? LIMIT 1";
                $dup = $pdo->prepare($sql); $dup->execute([$username]);
            }
            if ($dup->fetch()) throw new RuntimeException('Username or Email already exists.');

            $hash = password_hash($password, PASSWORD_DEFAULT);

            $cols = [ $UC['USERNAME'], $UC['PASSHASH'] ]; $vals = [ $username, $hash ];
            if (!empty($UC['ROLE']))    { $cols[]=$UC['ROLE'];    $vals[]=$role; }
            if (!empty($UC['NAME']))    { $cols[]=$UC['NAME'];    $vals[]= ($name!==''?$name:$username); }
            if (!empty($UC['EMAIL']))   { $cols[]=$UC['EMAIL'];   $vals[]= ($email!==''?$email:''); }
            if (!empty($UC['STATUS']))  { $cols[]=$UC['STATUS'];  $vals[]= detect_status_tokens($UC)['active']; }
            if (!empty($UC['CREATED'])) { $cols[]=$UC['CREATED']; $vals[]= date('Y-m-d H:i:s'); }
            if (!empty($UC['DELETED'])) { $cols[]=$UC['DELETED']; $vals[]= detect_deleted_tokens($UC)['not_deleted']; }

            $place = implode(',', array_fill(0, count($cols), '?'));
            $col_sql = implode(',', array_map('qi', $cols));
            $sql = "INSERT INTO `users` ($col_sql) VALUES ($place)";
            $pdo->prepare($sql)->execute($vals);
            $alert = 'User created successfully.';

        } elseif ($op==='update_role') {
            if (empty($UC['ROLE'])) { throw new RuntimeException('Role column not found in users table.'); }
            $id = (int)($_POST['id'] ?? 0);
            $role = strtolower(trim($_POST['role'] ?? ''));
            if (!in_array($role, $ROLE_OPTIONS, true)) { $role = $DEFAULT_ROLE; }
            if ($id<=0 || $role==='') throw new RuntimeException('Invalid input.');
            $sql = "UPDATE `users` SET ".qi($UC['ROLE'])."=? WHERE ".qi($UC['ID'])."=?";
            $pdo->prepare($sql)->execute([$role, $id]);
            $alert = 'Role updated.';

        } elseif ($op === 'set_status') { // single-button enable/disable
            if (empty($UC['STATUS'])) throw new RuntimeException('Status column not found.');
            $id = (int)($_POST['id'] ?? 0);
            $to = strtolower(trim($_POST['to'] ?? '')); // 'active' | 'inactive'
            if ($id <= 0 || !in_array($to, ['active','inactive'], true)) {
                throw new RuntimeException('Invalid input.');
            }
            $tok = detect_status_tokens($UC);
            $newVal = ($to === 'active') ? $tok['active'] : $tok['inactive'];
            // নিজেকে disable করা যাবে না
            $me = cur_user_id_guess($UC);
            if ($me > 0 && $me === $id && $to === 'inactive') {
                throw new RuntimeException('You cannot disable your own account.');
            }
            $sql = "UPDATE `users` SET ".qi($UC['STATUS'])."=? WHERE ".qi($UC['ID'])."=?";
            $pdo->prepare($sql)->execute([$newVal, $id]);
            $alert = ($to === 'active') ? 'User enabled.' : 'User disabled.';

        } elseif ($op==='reset_password') {
            $id = (int)($_POST['id'] ?? 0);
            $newpass = (string)($_POST['new_password'] ?? '');
            if ($id<=0 || $newpass==='') throw new RuntimeException('New password required.');
            if (empty($UC['PASSHASH'])) throw new RuntimeException('Password column not found.');
            $hash = password_hash($newpass, PASSWORD_DEFAULT);
            $sql = "UPDATE `users` SET ".qi($UC['PASSHASH'])."=? WHERE ".qi($UC['ID'])."=?";
            $pdo->prepare($sql)->execute([$hash, $id]);
            $alert = 'Password reset successfully.';

        } elseif ($op==='delete_user') { // Soft delete
            $id = (int)($_POST['id'] ?? 0);
            if ($id<=0) throw new RuntimeException('Invalid user id.');
            $me = cur_user_id_guess($UC);
            if ($me>0 && $me===$id) throw new RuntimeException('You cannot delete your own account.');

            $sets = []; $vals = [];
            if (!empty($UC['DELETED']))   { $sets[] = qi($UC['DELETED'])."=?";    $vals[] = detect_deleted_tokens($UC)['deleted']; }
            if (!empty($UC['DELETED_AT'])){ $sets[] = qi($UC['DELETED_AT'])."=?"; $vals[] = date('Y-m-d H:i:s'); }
            if (!empty($UC['STATUS']))    { $sets[] = qi($UC['STATUS'])."=?";     $vals[] = detect_status_tokens($UC)['inactive']; }

            if (!$sets) {
                if (!empty($UC['STATUS'])) {
                    $sql = "UPDATE `users` SET ".qi($UC['STATUS'])."=? WHERE ".qi($UC['ID'])."=?";
                    $vals = [detect_status_tokens($UC)['inactive'], $id];
                    $pdo->prepare($sql)->execute($vals);
                    $alert = 'User marked inactive (no delete columns present).';
                } else {
                    throw new RuntimeException('No delete/status columns found to perform a safe delete.');
                }
            } else {
                $sql = "UPDATE `users` SET ".implode(',', $sets)." WHERE ".qi($UC['ID'])."=?";
                $vals[] = $id;
                $pdo->prepare($sql)->execute($vals);
                $alert = 'User deleted (soft).';
            }

        } elseif ($op==='hard_delete_user') { // Hard delete (permanent)
            $id = (int)($_POST['id'] ?? 0);
            if ($id<=0) throw new RuntimeException('Invalid user id.');
            $me = cur_user_id_guess($UC);
            if ($me>0 && $me===$id) throw new RuntimeException('You cannot delete your own account.');
            $sql = "DELETE FROM `users` WHERE ".qi($UC['ID'])."=?";
            $pdo->prepare($sql)->execute([$id]);
            $alert = 'User permanently deleted.';

        } else {
            throw new RuntimeException('Unknown operation.');
        }
    }
} catch (Throwable $e) {
    $alert_type='danger'; $alert='Failed: '.$e->getMessage();
}

/* ===== GET + list ===== */
$search = trim($_GET['search'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20; $offset = ($page-1)*$limit;

/* SELECT list (কোন কলাম না থাকলে NULL AS alias) */
$sel = [];
$sel[] = qi($UC['ID'])." AS id";
$sel[] = ($UC['NAME'] ? qcol_safe($UC['NAME'], 'name') : qcol_safe($UC['USERNAME'], 'name')); // name না থাকলে username->name
$sel[] = qcol_safe($UC['USERNAME'], 'username');
$sel[] = qcol_safe($UC['EMAIL'], 'email');
$sel[] = qcol_safe($UC['ROLE'], 'role');
$sel[] = qcol_safe($UC['STATUS'], 'status');
$sel[] = qcol_safe($UC['CREATED'], 'created_at');
$select_list = implode(", ", $sel);

/* WHERE + exclude deleted */
$where = "WHERE 1=1"; $params = [];
if (!empty($UC['DELETED']) && $DEL['exists']) {
    $where .= " AND (".qi($UC['DELETED'])." IS NULL OR ".qi($UC['DELETED'])." IN (?, ?, '0', 'no', 'false'))";
    $params[] = $DEL['not_deleted'];
    $params[] = is_numeric($DEL['not_deleted']) ? 0 : $DEL['not_deleted'];
}
if ($search!=='') {
    $like = "%$search%";
    $ors = [];
    $ors[] = qi($UC['USERNAME'])." LIKE ?"; $params[]=$like;
    if (!empty($UC['NAME']))  { $ors[] = qi($UC['NAME'])." LIKE ?";  $params[]=$like; }
    if (!empty($UC['EMAIL'])) { $ors[] = qi($UC['EMAIL'])." LIKE ?"; $params[]=$like; }
    if (!empty($UC['ROLE']))  { $ors[] = qi($UC['ROLE'])." LIKE ?";  $params[]=$like; }
    $where .= " AND (".implode(" OR ", $ors).")";
}

$pdo = db_safe();
$cnt = $pdo->prepare("SELECT COUNT(*) FROM `users` $where");
$cnt->execute($params); $total = (int)$cnt->fetchColumn();

$list_sql = "SELECT $select_list FROM `users` $where ORDER BY ".qi($UC['ID'])." DESC LIMIT $limit OFFSET $offset";
$list = $pdo->prepare($list_sql);
$list->execute($params);
$rows = $list->fetchAll(PDO::FETCH_ASSOC);

$total_pages = max(1, (int)ceil($total/$limit));
$start_page = max(1, $page-2); $end_page = min($total_pages, $start_page+4);



?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Users</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="/assets/bootstrap.min.css" rel="stylesheet">
<style>
.container-narrow{max-width:1100px;}
.table thead th{white-space:nowrap;}
.form-required:after{content:" *"; color:#dc3545;}
form.inline-form{ display:inline-flex; align-items:center; gap:.5rem; }
</style>
</head>
<body>
<?php $ph = __DIR__ . '/../partials/partials_header.php'; if (file_exists($ph)) include $ph; ?>

<div class="container container-narrow my-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="mb-0">Users</h3>
  </div>

  <?php if ($alert!==''): ?>
    <div class="alert alert-<?php echo $alert_type; ?> py-2"><?php echo h($alert); ?></div>
  <?php endif; ?>

  <!-- Create User -->
  <?php if (can_manage_users()): ?>
  <div class="card mb-3">
    <div class="card-header">Create New User</div>
    <div class="card-body">
      <form method="post" action="users.php" class="row g-2">
        <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
        <input type="hidden" name="op" value="create">
        <div class="col-md-3">
          <label class="form-label">Name</label>
          <input class="form-control" name="name" placeholder="(optional)">
        </div>
        <div class="col-md-3">
          <label class="form-label form-required">Username</label>
          <input class="form-control" name="username" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">Email</label>
          <input class="form-control" type="email" name="email" placeholder="(optional)">
        </div>
        <div class="col-md-2">
          <label class="form-label form-required">Password</label>
          <input class="form-control" type="password" name="password" autocomplete="new-password" required>
        </div>
        <div class="col-md-1">
          <label class="form-label">Role</label>
          <select class="form-select" name="role" <?php echo empty($UC['ROLE'])?'disabled':''; ?>>
            <?php foreach($ROLE_OPTIONS as $opt): ?>
              <option value="<?php echo h($opt); ?>" <?php echo ($opt===$DEFAULT_ROLE)?'selected':''; ?>>
                <?php echo ucfirst($opt); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (empty($UC['ROLE'])): ?>
            <div class="form-text text-danger">No role column</div>
          <?php endif; ?>
        </div>
        <div class="col-12">
          <button type="submit" class="btn btn-primary">Save User</button>
        </div>
      </form>
    </div>
  </div>
  <?php else: ?>
    <div class="alert alert-info">Read-only: you can view users, but cannot create.</div>
  <?php endif; ?>

  <!-- Search -->
  <form class="row g-2 mb-3" method="get" action="users.php">
    <div class="col-sm-6 col-md-4">
      <input type="text" name="search" class="form-control" placeholder="Search name/username/email/role" value="<?php echo h($search); ?>">
    </div>
    <div class="col-auto">
      <button type="submit" class="btn btn-outline-secondary">Search</button>
    </div>
  </form>

  <!-- List -->
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>Name</th>
          <th>Username</th>
          <th>Email</th>
          <th>Role</th>
          <th>Status</th>
          <th>Created</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">No users found.</td></tr>
        <?php else: foreach($rows as $r): ?>
          <tr>
            <td><?php echo (int)$r['id']; ?></td>
            <td><?php echo h($r['name'] ?? ''); ?></td>
            <td><?php echo h($r['username'] ?? ''); ?></td>
            <td><?php echo h($r['email'] ?? ''); ?></td>

            <!-- Role cell -->
            <td>
              <?php if (!empty($UC['ROLE'])): ?>
                <?php if (can_manage_users()): ?>
                  <form method="post" action="users.php" class="d-flex gap-2">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="op" value="update_role">
                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                    <select name="role" class="form-select form-select-sm" style="min-width:120px">
                      <?php foreach($ROLE_OPTIONS as $opt): ?>
                        <option value="<?php echo h($opt); ?>" <?php echo (strtolower((string)$r['role'])===strtolower($opt))?'selected':''; ?>>
                          <?php echo ucfirst($opt); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-sm btn-outline-primary">Update</button>
                  </form>
                <?php else: ?>
                  <span class="badge text-bg-light"><?php echo ucfirst((string)($r['role'] ?? '')); ?></span>
                <?php endif; ?>
              <?php else: ?>
                <span class="badge text-bg-secondary">N/A</span>
              <?php endif; ?>
            </td>

            <!-- Status cell -->
            <td>
              <?php if (!empty($UC['STATUS'])): ?>
                <?php if (is_active_val($r['status'] ?? '', $STAT)): ?>
                  <span class="badge text-bg-success">Active</span>
                <?php else: ?>
                  <span class="badge text-bg-secondary">Inactive</span>
                <?php endif; ?>
              <?php else: ?>
                <span class="badge text-bg-light">N/A</span>
              <?php endif; ?>
            </td>

            <!-- Created -->
            <td><small class="text-muted"><?php echo h($r['created_at'] ?? ''); ?></small></td>

            <!-- Actions -->
            <td class="text-end">
              <?php if (can_manage_users()): ?>
                <!-- Reset password -->
                <form method="post" action="users.php" class="inline-form">
                  <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                  <input type="hidden" name="op" value="reset_password">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <input class="form-control form-control-sm" type="password" name="new_password" placeholder="New password" autocomplete="new-password" style="max-width:160px" required>
                  <button type="submit" class="btn btn-sm btn-warning">Reset</button>
                </form>

                <?php $isInactive = !is_active_val($r['status'] ?? '', $STAT); ?>

                <!-- Single Enable/Disable button -->
                <?php if (!empty($UC['STATUS'])): ?>
                <form method="post" action="users.php" class="inline-form"
                      onsubmit="return confirm('<?php echo $isInactive ? 'Enable this user?' : 'Disable this user?'; ?>');">
                  <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                  <input type="hidden" name="op" value="set_status">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <input type="hidden" name="to" value="<?php echo $isInactive ? 'active' : 'inactive'; ?>">
                  <button type="submit"
                          class="btn btn-sm <?php echo $isInactive ? 'btn-success' : 'btn-outline-secondary'; ?>">
                    <?php echo $isInactive ? 'Enable' : 'Disable'; ?>
                  </button>
                </form>
                <?php endif; ?>

 

                <!-- Hard Delete -->
                <form method="post" action="users.php" class="inline-form" onsubmit="return confirm('PERMANENT DELETE! This cannot be undone. Continue?');">
                  <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                  <input type="hidden" name="op" value="hard_delete_user">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger">Hard Delete</button>
                </form>
				
				
				
              <?php else: ?>
                <span class="text-muted">Read-only</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($total_pages>1): ?>
    <nav>
      <ul class="pagination pagination-sm">
        <li class="page-item <?php echo ($page<=1?'disabled':''); ?>">
          <a class="page-link" href="?<?php echo http_build_query(['search'=>$search,'page'=>max(1,$page-1)]); ?>">&laquo;</a>
        </li>
        <?php for($p=$start_page; $p<=$end_page; $p++): ?>
          <li class="page-item <?php echo ($p===$page?'active':''); ?>">
            <a class="page-link" href="?<?php echo http_build_query(['search'=>$search,'page'=>$p]); ?>"><?php echo $p; ?></a>
          </li>
        <?php endfor; ?>
        <li class="page-item <?php echo ($page>=$total_pages?'disabled':''); ?>">
          <a class="page-link" href="?<?php echo http_build_query(['search'=>$search,'page'=>min($total_pages,$page+1)]); ?>">&raquo;</a>
        </li>
      </ul>
    </nav>
  <?php endif; ?>


</div>
<link href="/assets/bootstrap-icons.css" rel="stylesheet">
<script src="/assets/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php include __DIR__ . '/../partials/partials_footer.php'; ?>