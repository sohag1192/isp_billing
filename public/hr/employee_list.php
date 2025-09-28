<?php
// /public/hr/employee_list.php
// UI text English; Comments in Bangla.
// Feature: Employees list with search, filters, sorting, pagination, CSV export (schema-aware).

declare(strict_types=1);

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/app/require_login.php';
require_once $ROOT . '/app/db.php';

function dbh(): PDO { $pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); return $pdo; }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------- helpers: schema detection (বাংলা: টেবিল/কলাম স্ক্যান) ---------- */
function table_exists(PDO $pdo, string $table): bool {
  try{
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
    $st->execute([$db, $table]);
    return (bool)$st->fetchColumn();
  }catch(Throwable $e){ return false; }
}
function col_exists(PDO $pdo, string $table, string $col): bool {
  try{
    $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetchColumn();
  }catch(Throwable $e){ return false; }
}
function pick_col(PDO $pdo, string $table, array $cands, string $fallback='id'): string {
  foreach ($cands as $c) if (col_exists($pdo, $table, $c)) return $c;
  return $fallback;
}
function pick_table(PDO $pdo, array $cands, string $fallback='employees'): string {
  foreach ($cands as $t) if (table_exists($pdo, $t)) return $t;
  return $fallback;
}

/* ---------- schema map (বাংলা: সম্ভাব্য টেবিল/কলাম) ---------- */
$pdo = dbh();

$emp_tbl = pick_table($pdo, ['employees','emp_info']);
$dept_tbl = pick_table($pdo, ['departments','department','emp_departments','emp_dept'], '');

$EMP_ID   = pick_col($pdo, $emp_tbl, ['employee_id','emp_id','e_id','emp_code','code','id']);
$EMP_NAME = pick_col($pdo, $emp_tbl, ['name','emp_name','full_name']);
$EMP_MOB  = pick_col($pdo, $emp_tbl, ['mobile','phone','contact','contact_no','mobile_no'], '');
$EMP_PHOT = pick_col($pdo, $emp_tbl, ['photo','photo_url','image','photo_path'], '');
$EMP_NID  = pick_col($pdo, $emp_tbl, ['nid','nid_path','nid_copy'], '');
$EMP_STAT = pick_col($pdo, $emp_tbl, ['status','emp_status','is_active'], '');
$EMP_JOIN = pick_col($pdo, $emp_tbl, ['join_date','joined_at','hire_date','created_at'], '');
$EMP_DEPT_FK = pick_col($pdo, $emp_tbl, ['dept_id','department_id','dept','department'], '');

$DEPT_ID   = $dept_tbl ? pick_col($pdo, $dept_tbl, ['id','dept_id'], 'id') : '';
$DEPT_NAME = $dept_tbl ? pick_col($pdo, $dept_tbl, ['name','dept_name','title'], 'name') : '';

/* ---------- inputs (বাংলা: ইনপুট/ফিল্টার/সোর্ট/পেজ) ---------- */
$search = trim($_GET['search'] ?? '');
$dept   = trim($_GET['dept']   ?? '');
$status = trim($_GET['status'] ?? '');
$sort   = trim($_GET['sort']   ?? 'name');
$dir    = strtolower(trim($_GET['dir']   ?? 'asc'));
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = max(10, min(100, (int)($_GET['limit'] ?? 20)));
$export = (int)($_GET['export'] ?? 0);

/* ---------- sort whitelist (বাংলা: সুরক্ষিত সোর্ট ম্যাপিং) ---------- */
$sort_map = [
  'id'         => "e.`$EMP_ID`",
  'name'       => "e.`$EMP_NAME`",
  'department' => $dept_tbl && $EMP_DEPT_FK ? "d.`$DEPT_NAME`" : "e.`$EMP_DEPT_FK`",
  'status'     => $EMP_STAT ? "e.`$EMP_STAT`" : "e.`$EMP_ID`",
  'join'       => $EMP_JOIN ? "e.`$EMP_JOIN`" : "e.`$EMP_ID`",
];
$sort_sql = $sort_map[$sort] ?? $sort_map['name'];
$dir_sql  = ($dir === 'desc') ? 'DESC' : 'ASC';

/* ---------- base sql (বাংলা: বেস কুয়েরি + জয়েন) ---------- */
$select_cols = "e.*";
$join_sql = "";
if ($dept_tbl && $EMP_DEPT_FK) {
  $select_cols .= ", d.`$DEPT_NAME` AS dept_name";
  $join_sql = " LEFT JOIN `$dept_tbl` d ON d.`$DEPT_ID` = e.`$EMP_DEPT_FK` ";
}

$where = [];
$bind  = [];

/* বাংলা: সার্চ — আইডি/নাম/মোবাইল এপ্লাই */
if ($search !== '') {
  $pieces = [];
  $pieces[] = "e.`$EMP_ID` LIKE ?";
  $bind[] = "%$search%";
  if ($EMP_NAME) { $pieces[] = "e.`$EMP_NAME` LIKE ?"; $bind[] = "%$search%"; }
  if ($EMP_MOB)  { $pieces[] = "e.`$EMP_MOB`  LIKE ?"; $bind[] = "%$search%"; }
  $where[] = "(" . implode(" OR ", $pieces) . ")";
}

/* বাংলা: ডিপার্টমেন্ট ফিল্টার */
if ($dept !== '' && $EMP_DEPT_FK) {
  $where[] = "e.`$EMP_DEPT_FK` = ?";
  $bind[]  = $dept;
}

/* বাংলা: স্ট্যাটাস ফিল্টার (string/number দুইভাবেই কাজ করবে) */
if ($status !== '' && $EMP_STAT) {
  $where[] = "e.`$EMP_STAT` = ?";
  $bind[]  = $status;
}

$where_sql = $where ? (" WHERE " . implode(" AND ", $where)) : "";

/* ---------- count ---------- */
$count_sql = "SELECT COUNT(*) FROM `$emp_tbl` e $join_sql $where_sql";
$stc = $pdo->prepare($count_sql);
$stc->execute($bind);
$total = (int)$stc->fetchColumn();

/* ---------- fetch rows ---------- */
$offset = ($page - 1) * $limit;
$list_sql =
  "SELECT $select_cols
   FROM `$emp_tbl` e
   $join_sql
   $where_sql
   ORDER BY $sort_sql $dir_sql";

if (!$export) {
  $list_sql .= " LIMIT $limit OFFSET $offset";
}
$st = $pdo->prepare($list_sql);
$st->execute($bind);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

/* ---------- dept options (বাংলা: ড্রপডাউন) ---------- */
$dept_opts = [];
if ($dept_tbl && $EMP_DEPT_FK) {
  $q = $pdo->query("SELECT `$DEPT_ID` AS id, `$DEPT_NAME` AS name FROM `$dept_tbl` ORDER BY `$DEPT_NAME` ASC");
  $dept_opts = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
} elseif ($EMP_DEPT_FK) {
  // বাংলা: ডিপার্টমেন্ট টেবিল না থাকলে এমপ্লয়ি টেবিলের distinct ভ্যালু
  $q = $pdo->query("SELECT DISTINCT `$EMP_DEPT_FK` AS id FROM `$emp_tbl` WHERE `$EMP_DEPT_FK` IS NOT NULL AND `$EMP_DEPT_FK`<>'' ORDER BY `$EMP_DEPT_FK`");
  $dept_opts = array_map(fn($r)=>['id'=>$r['id'],'name'=>$r['id']], $q->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

/* ---------- CSV export ---------- */
if ($export === 1) {
  // বাংলা: এক্সপোর্টে পেজিনেশন বাদ, একই ফিল্টার/সোর্ট ধরে
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="employees.csv"');
  $out = fopen('php://output', 'w');

  $headers = ['Employee ID','Name'];
  if ($dept_tbl || $EMP_DEPT_FK) $headers[] = 'Department';
  if ($EMP_STAT) $headers[] = 'Status';
  if ($EMP_JOIN) $headers[] = 'Joined';
  if ($EMP_MOB)  $headers[] = 'Mobile';
  fputcsv($out, $headers);

  $stmt = $pdo->prepare($list_sql); // same $list_sql built above (no LIMIT for export)
  $stmt->execute($bind);
  while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $row = [
      (string)($r[$EMP_ID] ?? ''),
      (string)($r[$EMP_NAME] ?? ''),
    ];
    $row[] = (string)($r['dept_name'] ?? ($EMP_DEPT_FK ? ($r[$EMP_DEPT_FK] ?? '') : ''));
    if ($EMP_STAT) $row[] = (string)($r[$EMP_STAT] ?? '');
    if ($EMP_JOIN) $row[] = (string)($r[$EMP_JOIN] ?? '');
    if ($EMP_MOB)  $row[] = (string)($r[$EMP_MOB] ?? '');
    fputcsv($out, $row);
  }
  fclose($out);
  exit;
}

/* ---------- helpers: URL builders (বাংলা: বর্তমান ফিল্টার/সোর্ট রেখে লিংক বানাও) ---------- */
function qs(array $merge=[]): string {
  $base = $_GET;
  foreach ($merge as $k=>$v) {
    if ($v === null) unset($base[$k]); else $base[$k] = $v;
  }
  return '?' . http_build_query($base);
}
function sort_link(string $key): string {
  $curr_sort = $_GET['sort'] ?? 'name';
  $curr_dir  = strtolower($_GET['dir'] ?? 'asc');
  $next_dir  = ($curr_sort === $key && $curr_dir === 'asc') ? 'desc' : 'asc';
  return qs(['sort'=>$key,'dir'=>$next_dir,'page'=>1]);
}

/* ---------- header ---------- */
$page_title = "Employees";
include $ROOT . '/partials/partials_header.php';
?>
<div class="container-fluid py-3">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0">Employees</h4>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary" href="<?php echo h(qs(['export'=>1])); ?>">
        <i class="bi bi-download"></i> Export CSV
      </a>
      <a class="btn btn-primary" href="/public/hr/employee_add.php">
        <i class="bi bi-plus-lg"></i> Add Employee
      </a>
    </div>
  </div>

  <!-- Filters (বাংলা: সার্চ/ডিপার্টমেন্ট/স্ট্যাটাস) -->
  <form class="row g-2 align-items-end mb-3" method="get">
    <div class="col-md-4">
      <label class="form-label">Search</label>
      <input type="text" class="form-control" name="search" placeholder="Search by ID / Name / Mobile"
             value="<?php echo h($search); ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">Department</label>
      <select class="form-select" name="dept">
        <option value="">All</option>
        <?php foreach ($dept_opts as $opt): ?>
          <option value="<?php echo h($opt['id']); ?>" <?php echo ($dept !== '' && (string)$dept===(string)$opt['id'])?'selected':''; ?>>
            <?php echo h($opt['name']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Status</label>
      <input type="text" class="form-control" name="status" placeholder="e.g., active / 1"
             value="<?php echo h($status); ?>">
    </div>
    <div class="col-md-2 d-flex gap-2">
      <button class="btn btn-dark w-100" type="submit"><i class="bi bi-search"></i> Filter</button>
      <a class="btn btn-outline-secondary w-100" href="<?php echo h(qs(['search'=>null,'dept'=>null,'status'=>null,'page'=>1])); ?>">
        Reset
      </a>
    </div>
  </form>

  <!-- Summary (বাংলা: কাউন্টার/পেজিং) -->
  <div class="d-flex justify-content-between align-items-center mb-2">
    <div>
      <span class="badge bg-secondary">Total: <?php echo (int)$total; ?></span>
    </div>
    <div class="d-flex align-items-center gap-2">
      <form method="get" class="d-flex align-items-center gap-2">
        <?php
          // preserve filters while changing limit
          foreach (['search','dept','status','sort','dir','page'] as $k) {
            if (isset($_GET[$k])) echo '<input type="hidden" name="'.h($k).'" value="'.h((string)$_GET[$k]).'">';
          }
        ?>
        <label class="form-label mb-0">Per page</label>
        <select class="form-select form-select-sm" name="limit" onchange="this.form.submit()">
          <?php foreach ([10,20,50,100] as $opt): ?>
            <option value="<?php echo $opt; ?>" <?php echo ($limit===$opt)?'selected':''; ?>><?php echo $opt; ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
  </div>

  <!-- Table (বাংলা: সোর্টেবল হেডার) -->
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead class="table-light">
        <tr>
          <th style="width: 140px;"><a class="text-decoration-none" href="<?php echo h(sort_link('id')); ?>">Employee ID</a></th>
          <th><a class="text-decoration-none" href="<?php echo h(sort_link('name')); ?>">Name</a></th>
          <th>Mobile</th>
          <th><a class="text-decoration-none" href="<?php echo h(sort_link('department')); ?>">Department</a></th>
          <th><a class="text-decoration-none" href="<?php echo h(sort_link('status')); ?>">Status</a></th>
          <th><a class="text-decoration-none" href="<?php echo h(sort_link('join')); ?>">Joined</a></th>
          <th class="text-end" style="width:120px;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No employees found.</td></tr>
        <?php endif; ?>

        <?php foreach ($rows as $r): ?>
          <?php
            $eid   = (string)($r[$EMP_ID]   ?? '');
            $ename = (string)($r[$EMP_NAME] ?? '');
            $emob  = $EMP_MOB  ? (string)($r[$EMP_MOB]  ?? '') : '';
            $estat = $EMP_STAT ? (string)($r[$EMP_STAT] ?? '') : '';
            $ejoin = $EMP_JOIN ? (string)($r[$EMP_JOIN] ?? '') : '';
            $dname = (string)($r['dept_name'] ?? ($EMP_DEPT_FK ? (string)($r[$EMP_DEPT_FK] ?? '') : ''));
            // বাংলা: স্ট্যাটাস ব্যাজ
            $badge = 'secondary';
            $v = strtolower(trim($estat));
            if ($v==='1' || $v==='active' || $v==='enabled') $badge='success';
            if ($v==='0' || $v==='inactive' || $v==='disabled') $badge='danger';
          ?>
          <tr>
            <td><code><?php echo h($eid); ?></code></td>
            <td><?php echo h($ename); ?></td>
            <td><?php echo h($emob); ?></td>
            <td><?php echo h($dname); ?></td>
            <td><span class="badge bg-<?php echo $badge; ?>"><?php echo h($estat === '' ? '—' : $estat); ?></span></td>
            <td><?php echo h($ejoin); ?></td>
            <td class="text-end">
              <div class="btn-group">
                <a class="btn btn-sm btn-outline-primary" href="/public/hr/employee_view.php?e_id=<?php echo urlencode($eid); ?>">
                  <i class="bi bi-eye"></i>
                </a>
                <a class="btn btn-sm btn-outline-secondary" href="/public/hr/employee_edit.php?e_id=<?php echo urlencode($eid); ?>">
                  <i class="bi bi-pencil"></i>
                </a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination (বাংলা: পেজিনেশন) -->
  <?php
    $total_pages = max(1, (int)ceil($total / $limit));
    if ($page > $total_pages) $page = $total_pages;
    $window = 3;
    $start = max(1, $page - $window);
    $end   = min($total_pages, $page + $window);
  ?>
  <nav aria-label="Employee pagination">
    <ul class="pagination justify-content-center">
      <li class="page-item <?php echo ($page<=1)?'disabled':''; ?>">
        <a class="page-link" href="<?php echo h(qs(['page'=>1])); ?>">« First</a>
      </li>
      <li class="page-item <?php echo ($page<=1)?'disabled':''; ?>">
        <a class="page-link" href="<?php echo h(qs(['page'=>max(1,$page-1)])); ?>">‹ Prev</a>
      </li>
      <?php for($p=$start;$p<=$end;$p++): ?>
        <li class="page-item <?php echo ($p===$page)?'active':''; ?>">
          <a class="page-link" href="<?php echo h(qs(['page'=>$p])); ?>"><?php echo $p; ?></a>
        </li>
      <?php endfor; ?>
      <li class="page-item <?php echo ($page>=$total_pages)?'disabled':''; ?>">
        <a class="page-link" href="<?php echo h(qs(['page'=>min($total_pages,$page+1)])); ?>">Next ›</a>
      </li>
      <li class="page-item <?php echo ($page>=$total_pages)?'disabled':''; ?>">
        <a class="page-link" href="<?php echo h(qs(['page'=>$total_pages])); ?>">Last »</a>
      </li>
    </ul>
  </nav>
</div>
<?php include $ROOT . '/partials/partials_footer.php'; ?>
