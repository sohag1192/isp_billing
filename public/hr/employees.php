<?php
// /public/hr/employees.php
// UI text: English; Comments: Bengali
// Features: schema-aware listing + search/filter/sort/pagination + CSV export + inline toggle + delete
//           + Wallet view via subqueries (supports wallets.employee_id OR wallets.employee_code); fallback 0.00

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');

$page_title = 'Employees';
require_once __DIR__ . '/../../partials/partials_header.php';
require_once __DIR__ . '/../../app/db.php';

// (Optional) ACL
$acl_file = __DIR__ . '/../../app/acl.php';
if (is_file($acl_file)) require_once $acl_file;

if (session_status() === PHP_SESSION_NONE) { session_start(); }
// Canonical CSRF for HR pages
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
$_SESSION['csrf_hr'] = $_SESSION['csrf'];
$CSRF = (string)$_SESSION['csrf'];

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ------------------------- helpers ------------------------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function tbl_exists(PDO $pdo, string $t): bool {
  try{ $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
       $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
       $q->execute([$db,$t]); return (bool)$q->fetchColumn(); }catch(Throwable $e){ return false; }
}
function col_exists(PDO $pdo, string $t, string $c): bool {
  try{ $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
       $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
       $q->execute([$db,$t,$c]); return (bool)$q->fetchColumn(); }catch(Throwable $e){ return false; }
}
function find_emp_table(PDO $pdo): string {
  foreach (['emp_info','employees','hr_employees','employee'] as $t) if (tbl_exists($pdo,$t)) return $t;
  return 'emp_info'; // safe default
}
function find_dept_table(PDO $pdo): ?string {
  foreach (['departments','department','dept','department_info'] as $t) if (tbl_exists($pdo,$t)) return $t;
  return null;
}
function pick_col(PDO $pdo, string $t, array $cands, ?string $fallback=null): ?string {
  foreach ($cands as $c) if (col_exists($pdo,$t,$c)) return $c;
  return $fallback && col_exists($pdo,$t,$fallback) ? $fallback : null;
}

// Wallet badge helper
function wallet_badge($x): string{
  if($x===null || $x==='') return '<span class="badge bg-secondary-subtle text-secondary">—</span>';
  $b = (float)$x;
  $cls = $b < 0 ? 'bg-danger' : 'bg-success';
  return '<span class="badge '.$cls.'">'.number_format($b,2).'</span>';
}

$T = find_emp_table($pdo);
$D = find_dept_table($pdo);

// main id/code/name
$COL_ID        = pick_col($pdo,$T,['emp_id','e_id','employee_id','emp_code','id'],'id') ?? 'id';
$COL_CODE      = pick_col($pdo,$T,['emp_id','emp_code','employee_code'],$COL_ID) ?? $COL_ID;
$COL_NAME      = pick_col($pdo,$T,['e_name','name','full_name','employee_name'],'name') ?? 'name';
$COL_DEPT_FK   = pick_col($pdo,$T,['dept_id','department_id','dept','department','e_dept']);
$COL_JOIN_DATE = pick_col($pdo,$T,['e_j_date','join_date','joined','hired_at','joining_date']);
$COL_SALARY    = pick_col($pdo,$T,['gross_total','gross','salary','basic']);
$COL_STATUS    = pick_col($pdo,$T,['status']);
$COL_ACTIVE    = pick_col($pdo,$T,['is_active']);
$COL_LEFT      = pick_col($pdo,$T,['is_left']);
$COL_UPDATED   = pick_col($pdo,$T,['updated_at']);

$DEPT_NAME     = $D ? (pick_col($pdo,$D,['dept_name','name','title'],'name') ?? 'name') : null;
$DEPT_ID       = $D ? (pick_col($pdo,$D,['dept_id','id'],'id') ?? 'id') : null;

/* ------------------------- inputs ------------------------- */
$q        = trim($_GET['q'] ?? '');
$dept     = trim($_GET['dept'] ?? '');
$status   = trim($_GET['status'] ?? ''); // active/inactive/left/all
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = min(200, max(10, (int)($_GET['per_page'] ?? 25)));
$export   = strtolower(trim($_GET['export'] ?? ''));
$sort     = strtolower(trim($_GET['sort'] ?? '')); // id,name,dept,join,salary,status,updated,wallet
$dir      = strtoupper(trim($_GET['dir'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

/* ---------------- Wallet presence (table/columns) ---------------- */
$WALLET_TABLE_EXISTS = tbl_exists($pdo,'wallets');
$HAS_WALLET_ID       = $WALLET_TABLE_EXISTS && col_exists($pdo,'wallets','employee_id');
$HAS_WALLET_CODE     = $WALLET_TABLE_EXISTS && col_exists($pdo,'wallets','employee_code');

/* ------------------------- sort map ------------------------- */
$sort_map = [
  'id'      => "$T.$COL_ID",
  'name'    => "$T.$COL_NAME",
  'dept'    => ($D && $DEPT_NAME ? "$D.$DEPT_NAME" : "$T.$COL_NAME"),
  'join'    => $COL_JOIN_DATE ? "$T.$COL_JOIN_DATE" : "$T.$COL_NAME",
  'salary'  => $COL_SALARY ? "$T.$COL_SALARY" : "$T.$COL_NAME",
  'status'  => $COL_STATUS ? "$T.$COL_STATUS" : ($COL_ACTIVE ? "$T.$COL_ACTIVE" : "$T.$COL_NAME"),
  'updated' => $COL_UPDATED ? "$T.$COL_UPDATED" : "$T.$COL_NAME",
  'wallet'  => "wallet_balance", // alias
];
$order_by = $sort_map[$sort] ?? "$T.$COL_ID";

/* ------------------------- base SQL ------------------------- */
$cols = ["$T.$COL_ID AS emp_code","$T.$COL_NAME AS emp_name"];
if ($COL_JOIN_DATE) $cols[] = "$T.$COL_JOIN_DATE AS joined_at";
if ($COL_SALARY)    $cols[] = "$T.$COL_SALARY AS salary";
if ($COL_STATUS)    $cols[] = "$T.$COL_STATUS AS status";
if ($COL_ACTIVE)    $cols[] = "$T.$COL_ACTIVE AS is_active";
if ($COL_LEFT)      $cols[] = "$T.$COL_LEFT AS is_left";
if ($COL_UPDATED)   $cols[] = "$T.$COL_UPDATED AS updated_at";

// Wallet via subqueries (id/code) + fallback 0
$sub_by_id   = $HAS_WALLET_ID   ? "(SELECT w.balance FROM wallets w WHERE w.employee_id = `$T`.`$COL_ID` LIMIT 1)" : "NULL";
$sub_by_code = $HAS_WALLET_CODE ? "(SELECT w2.balance FROM wallets w2 WHERE w2.employee_code = `$T`.`$COL_CODE` LIMIT 1)" : "NULL";
$cols[] = "COALESCE($sub_by_id, $sub_by_code, 0) AS wallet_balance";

$joins = '';
if ($D && $COL_DEPT_FK && $DEPT_ID) {
  $cols[] = "$D.$DEPT_NAME AS dept_name";
  $joins  = " LEFT JOIN `$D` ON `$D`.`$DEPT_ID` = `$T`.`$COL_DEPT_FK` ";
}

$where = [];
$args  = [];
if ($q !== '') {
  $like = '%' . $q . '%';
  $w = ["$T.$COL_NAME LIKE ?", "$T.$COL_ID LIKE ?"];
  foreach (['mobile','phone','email'] as $c) if (col_exists($pdo,$T,$c)) $w[] = "$T.$c LIKE ?";
  $where[] = '(' . implode(' OR ', $w) . ')';
  $args = array_merge($args, array_fill(0, count($w), $like));
}
if ($dept !== '' && $D && $COL_DEPT_FK) {
  if (ctype_digit($dept)) { $where[] = "$T.$COL_DEPT_FK = ?"; $args[] = (int)$dept; }
  else { $where[] = "$D.$DEPT_NAME = ?"; $args[] = $dept; }
}
if ($status !== '' && $status !== 'all') {
  if ($status === 'left' && $COL_LEFT) {
    $where[] = "$T.$COL_LEFT = 1";
  } elseif (in_array($status,['active','inactive'],true)) {
    if ($COL_ACTIVE) {
      $where[] = "$T.$COL_ACTIVE = ?"; $args[] = ($status==='active'?1:0);
    } elseif ($COL_STATUS) {
      $where[] = "$T.$COL_STATUS = ?"; $args[] = $status;
    }
  }
}

$sql_where = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

// Count
$count_sql = "SELECT COUNT(1) FROM `$T` $joins $sql_where";
$stc = $pdo->prepare($count_sql);
$stc->execute($args);
$total = (int)$stc->fetchColumn();

$pages = max(1, (int)ceil($total / $per_page));
$page  = min($page, $pages);
$offset = ($page - 1) * $per_page;

$list_sql = "SELECT " . implode(',',$cols) . " FROM `$T` $joins $sql_where ORDER BY $order_by $dir LIMIT $per_page OFFSET $offset";
$st = $pdo->prepare($list_sql);
$st->execute($args);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* ------------------------- export CSV ------------------------- */
if ($export === 'csv') {
  $exp_sql = "SELECT " . implode(',',$cols) . " FROM `$T` $joins $sql_where ORDER BY $order_by $dir";
  $ex = $pdo->prepare($exp_sql); $ex->execute($args);
  $fn = 'employees_' . date('Ymd_His') . '.csv';
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename=' . $fn);
  $out = fopen('php://output','w');
  fprintf($out, "\xEF\xBB\xBF");
  $header = ['Employee ID','Name'];
  if ($D && $DEPT_NAME) $header[] = 'Department';
  if ($COL_JOIN_DATE)   $header[] = 'Join Date';
  if ($COL_SALARY)      $header[] = 'Salary';
  if ($COL_ACTIVE)      $header[] = 'Active';
  if ($COL_LEFT)        $header[] = 'Left';
  if ($COL_STATUS)      $header[] = 'Status';
  if ($COL_UPDATED)     $header[] = 'Updated At';
  fputcsv($out, $header);
  while($r = $ex->fetch(PDO::FETCH_ASSOC)){
    $line = [$r['emp_code'] ?? '', $r['emp_name'] ?? ''];
    if ($D && $DEPT_NAME) $line[] = $r['dept_name'] ?? '';
    if ($COL_JOIN_DATE)   $line[] = $r['joined_at'] ?? '';
    if ($COL_SALARY)      $line[] = $r['salary'] ?? '';
    if ($COL_ACTIVE)      $line[] = isset($r['is_active']) ? ((int)$r['is_active'] ? 'Yes' : 'No') : '';
    if ($COL_LEFT)        $line[] = isset($r['is_left']) ? ((int)$r['is_left'] ? 'Yes' : 'No') : '';
    if ($COL_STATUS)      $line[] = $r['status'] ?? '';
    if ($COL_UPDATED)     $line[] = $r['updated_at'] ?? '';
    fputcsv($out, $line);
  }
  fclose($out); exit;
}

/* ------------------------- dept options ------------------------- */
$dept_opts = [];
if ($D && $DEPT_NAME) {
  $dq = $pdo->query("SELECT `$DEPT_ID` AS id, `$DEPT_NAME` AS name FROM `$D` ORDER BY `$DEPT_NAME` ASC");
  $dept_opts = $dq->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// Permissions
$can_toggle = function_exists('acl_can') ? acl_can('hr.toggle') : true;
$can_delete = function_exists('acl_can') ? acl_can('hr.delete') : true;

$qs = function(array $overrides = []){
  $p = array_merge($_GET, $overrides);
  return '?' . http_build_query($p);
};
?>
<div class="container-fluid py-3">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="mb-0">Employees</h3>
    <div class="btn-group">
      <a href="<?php echo h($qs(['export'=>'csv','page'=>1])); ?>" class="btn btn-outline-success btn-sm"><i class="bi bi-filetype-csv"></i> Export CSV</a>
      <a href="/public/hr/employee_add.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle"></i> Add Employee</a>
    </div>
  </div>

  <form class="row g-2 align-items-end mb-3" method="get">
    <div class="col-sm-4 col-md-3">
      <label class="form-label">Search</label>
      <input type="text" name="q" value="<?php echo h($q); ?>" class="form-control" placeholder="Name, ID, phone, email">
    </div>
    <div class="col-sm-3 col-md-3">
      <label class="form-label">Department</label>
      <select name="dept" class="form-select">
        <option value="">All</option>
        <?php foreach($dept_opts as $opt): ?>
          <option value="<?php echo h((string)$opt['id']); ?>" <?php echo ($dept!=='' && (string)$opt['id']===$dept)?'selected':''; ?>>
            <?php echo h((string)$opt['name']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-sm-3 col-md-2">
      <label class="form-label">Status</label>
      <select name="status" class="form-select">
        <?php foreach(['all'=>'All','active'=>'Active','inactive'=>'Inactive','left'=>'Left'] as $k=>$v): ?>
          <option value="<?php echo $k; ?>" <?php echo ($status===$k)?'selected':''; ?>><?php echo $v; ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-sm-2 col-md-2">
      <label class="form-label">Per Page</label>
      <input type="number" name="per_page" class="form-control" value="<?php echo h((string)$per_page); ?>" min="10" max="200">
    </div>
    <div class="col-sm-12 col-md-2 d-grid">
      <button class="btn btn-secondary"><i class="bi bi-search"></i> Apply</button>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle">
      <thead class="table-light">
        <tr>
          <th><a class="text-decoration-none" href="<?php echo h($qs(['sort'=>'id','dir'=>($sort==='id' && $dir==='ASC')?'DESC':'ASC'])); ?>">ID <?php if($sort==='id') echo $dir==='ASC'?'▲':'▼'; ?></a></th>
          <th><a class="text-decoration-none" href="<?php echo h($qs(['sort'=>'name','dir'=>($sort==='name' && $dir==='ASC')?'DESC':'ASC'])); ?>">Name <?php if($sort==='name') echo $dir==='ASC'?'▲':'▼'; ?></a></th>
          <?php if ($D && $DEPT_NAME): ?>
          <th><a class="text-decoration-none" href="<?php echo h($qs(['sort'=>'dept','dir'=>($sort==='dept' && $dir==='ASC')?'DESC':'ASC'])); ?>">Department <?php if($sort==='dept') echo $dir==='ASC'?'▲':'▼'; ?></a></th>
          <?php endif; ?>
          <?php if ($COL_JOIN_DATE): ?>
          <th><a class="text-decoration-none" href="<?php echo h($qs(['sort'=>'join','dir'=>($sort==='join' && $dir==='ASC')?'DESC':'ASC'])); ?>">Join Date <?php if($sort==='join') echo $dir==='ASC'?'▲':'▼'; ?></a></th>
          <?php endif; ?>
          <?php if ($COL_SALARY): ?>
          <th><a class="text-decoration-none" href="<?php echo h($qs(['sort'=>'salary','dir'=>($sort==='salary' && $dir==='ASC')?'DESC':'ASC'])); ?>">Salary <?php if($sort==='salary') echo $dir==='ASC'?'▲':'▼'; ?></a></th>
          <?php endif; ?>
          <th><a class="text-decoration-none" href="<?php echo h($qs(['sort'=>'wallet','dir'=>($sort==='wallet' && $dir==='ASC')?'DESC':'ASC'])); ?>">Wallet <?php if($sort==='wallet') echo $dir==='ASC'?'▲':'▼'; ?></a></th>
          <?php if ($COL_STATUS || $COL_ACTIVE || $COL_LEFT): ?>
          <th>Status</th>
          <?php endif; ?>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">No employees found.</td></tr>
      <?php else: foreach($rows as $r): ?>
        <?php
          $emp_code = (string)($r['emp_code'] ?? '');
          $is_active = isset($r['is_active']) ? (int)$r['is_active'] : null;
          $is_left   = isset($r['is_left'])   ? (int)$r['is_left']   : null;
          $status_tx = $r['status'] ?? null;
        ?>
        <tr>
          <td class="fw-semibold"><?php echo h($emp_code); ?></td>
          <td><?php echo h($r['emp_name'] ?? ''); ?></td>
          <?php if ($D && $DEPT_NAME): ?>
          <td><?php echo h($r['dept_name'] ?? ''); ?></td>
          <?php endif; ?>
          <?php if ($COL_JOIN_DATE): ?>
          <td><?php echo h($r['joined_at'] ? date('Y-m-d', strtotime((string)$r['joined_at'])) : ''); ?></td>
          <?php endif; ?>
          <?php if ($COL_SALARY): ?>
          <td><?php echo h($r['salary'] ?? ''); ?></td>
          <?php endif; ?>
          <td class="text-nowrap"><?php echo wallet_badge($r['wallet_balance'] ?? null); ?></td>
          <?php if ($COL_STATUS || $COL_ACTIVE || $COL_LEFT): ?>
          <td>
            <?php if (!is_null($is_left) && $is_left===1): ?>
              <span class="badge bg-secondary">Left</span>
            <?php else: ?>
              <?php if (!is_null($is_active)): ?>
                <span class="badge <?php echo $is_active? 'bg-success':'bg-danger'; ?>"><?php echo $is_active? 'Active':'Inactive'; ?></span>
              <?php elseif (!is_null($status_tx)): ?>
                <span class="badge <?php echo ($status_tx==='active')?'bg-success':(($status_tx==='inactive')?'bg-danger':'bg-info'); ?>"><?php echo h((string)$status_tx); ?></span>
              <?php else: ?>
                <span class="badge bg-light text-dark">Unknown</span>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <?php endif; ?>
          <td>
            <div class="btn-group btn-group-sm" role="group">
              <a class="btn btn-outline-primary" href="/public/hr/employee_view.php?e_id=<?php echo urlencode($emp_code); ?>"><i class="bi bi-eye"></i> View</a>
              <a class="btn btn-outline-secondary" href="/public/hr/employee_edit.php?e_id=<?php echo urlencode($emp_code); ?>"><i class="bi bi-pencil"></i> Edit</a>

              <?php if ($can_toggle): ?>
                <form method="post" action="/public/hr/employee_toggle.php" class="d-inline" onsubmit="return confirmToggle(this)">
                  <input type="hidden" name="emp_id" value="<?php echo h($emp_code); ?>">
                  <?php
                    $tok = function_exists('csrf_token') ? (string)csrf_token() : $CSRF;
                  ?>
                  <input type="hidden" name="csrf_token" value="<?php echo h($tok); ?>">
                  <input type="hidden" name="csrf" value="<?php echo h($tok); ?>">
                  <button type="submit" class="btn btn-outline-warning"><i class="bi bi-arrow-repeat"></i> Toggle</button>
                </form>
              <?php endif; ?>

              <?php if ($can_delete): ?>
                <form method="post" action="/public/hr/employee_delete.php" class="d-inline" onsubmit="return confirmDelete(this)" >
                  <input type="hidden" name="emp_id" value="<?php echo h($emp_code); ?>">
                  <?php
                    $tok = function_exists('csrf_token') ? (string)csrf_token() : $CSRF;
                  ?>
                  <input type="hidden" name="csrf_token" value="<?php echo h($tok); ?>">
                  <input type="hidden" name="csrf" value="<?php echo h($tok); ?>">
                  <button type="submit" class="btn btn-outline-danger"><i class="bi bi-trash"></i> Delete</button>
                </form>
              <?php endif; ?>

            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div class="d-flex justify-content-between align-items-center">
    <div class="text-muted small">Showing <?php echo count($rows); ?> of <?php echo (int)$total; ?> item(s)</div>
    <nav aria-label="Page navigation">
      <ul class="pagination pagination-sm mb-0">
        <?php
          $page_link = function($p, $label=null, $disabled=false, $active=false) use ($qs){
            $label = $label ?? (string)$p;
            $cls = 'page-item' . ($disabled?' disabled':'') . ($active?' active':'');
            $href = $disabled? '#': h($qs(['page'=>$p]));
            echo '<li class="'.$cls.'"><a class="page-link" href="'.$href.'">'.h($label).'</a></li>';
          };
          $page_link(1, '«', $page<=1);
          $page_link(max(1,$page-1), '‹', $page<=1);
          $start = max(1, $page-2); $end = min($pages, $page+2);
          for($i=$start;$i<=$end;$i++) $page_link($i, (string)$i, false, $i===$page);
          $page_link(min($pages,$page+1), '›', $page>=$pages);
          $page_link($pages, '»', $page>=$pages);
        ?>
      </ul>
    </nav>
  </div>
</div>

<script>
function confirmToggle(form){
  return confirm('Toggle status for this employee?');
}
function confirmDelete(form){
  const id = form.querySelector('input[name="emp_id"]')?.value || 'this employee';
  return confirm('Permanently delete ' + id + ' from database? This cannot be undone.');
}
</script>

<?php require_once __DIR__ . '/../../partials/partials_footer.php'; ?>
