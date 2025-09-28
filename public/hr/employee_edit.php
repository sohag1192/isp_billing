<?php
// /public/hr/employee_edit.php
// UI English; Comments Bengali.
// Feature: Schema-aware employee edit + optional photo/NID replace.

declare(strict_types=1);

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/app/require_login.php';
require_once $ROOT . '/app/db.php';

function dbh(): PDO { $pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); return $pdo; }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------- helpers: schema ---------- */
function table_exists(PDO $pdo, string $t): bool {
  try{
    $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
    $st=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
    $st->execute([$db,$t]);
    return (bool)$st->fetchColumn();
  }catch(Throwable $e){ return false; }
}
function col_exists(PDO $pdo, string $tbl, string $col): bool {
  try{
    $st=$pdo->prepare("SHOW COLUMNS FROM `$tbl` LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetchColumn();
  }catch(Throwable $e){ return false; }
}
function pick_table(PDO $pdo, array $cands, string $fallback='employees'): string {
  foreach($cands as $t) if (table_exists($pdo,$t)) return $t;
  return $fallback;
}
function pick_col(PDO $pdo, string $tbl, array $cands, string $fallback=''): string {
  foreach($cands as $c) if (col_exists($pdo,$tbl,$c)) return $c;
  return $fallback ?: $cands[0] ?? 'id';
}

/* ---------- schema map ---------- */
// বাংলা: সম্ভাব্য টেবিল/কলাম নামগুলো থেকে অটো-পিক
$pdo = dbh();
$emp_tbl  = pick_table($pdo, ['employees','emp_info']);
$dept_tbl = pick_table($pdo, ['departments','department','emp_departments','emp_dept'], '');

$EMP_ID   = pick_col($pdo,$emp_tbl,['employee_id','emp_id','e_id','emp_code','code','id'],'id');
$EMP_NAME = pick_col($pdo,$emp_tbl,['name','emp_name','full_name'],'name');
$EMP_MOB  = pick_col($pdo,$emp_tbl,['mobile','phone','contact','contact_no','mobile_no'],'');
$EMP_ADDR = pick_col($pdo,$emp_tbl,['address','present_address','addr'],'');
$EMP_SAL  = pick_col($pdo,$emp_tbl,['salary','gross','basic_salary','gross_total'],'');
$EMP_STAT = pick_col($pdo,$emp_tbl,['status','emp_status','is_active'],'');
$EMP_JOIN = pick_col($pdo,$emp_tbl,['join_date','joined_at','hire_date','created_at'],'');
$EMP_DEPT_FK = pick_col($pdo,$emp_tbl,['dept_id','department_id','dept','department'],'');
$EMP_PHOT = pick_col($pdo,$emp_tbl,['photo','photo_url','image','photo_path'],'');
$EMP_NID  = pick_col($pdo,$emp_tbl,['nid','nid_path','nid_copy'],'');
$EMP_UPD  = col_exists($pdo,$emp_tbl,'updated_at') ? 'updated_at' : '';

$DEPT_ID   = $dept_tbl ? pick_col($pdo,$dept_tbl,['id','dept_id'],'id') : '';
$DEPT_NAME = $dept_tbl ? pick_col($pdo,$dept_tbl,['name','dept_name','title'],'name') : '';

/* ---------- param: e_id (EMPID) ---------- */
$e_id = trim($_GET['e_id'] ?? '');
if ($e_id === '') {
  http_response_code(400);
  echo "Missing e_id.";
  exit;
}

/* ---------- fetch employee ---------- */
// বাংলা: EMP_ID কলাম string/number যাই হোক, as string bind করলাম
$st = $pdo->prepare("SELECT * FROM `$emp_tbl` WHERE `$EMP_ID` = ? LIMIT 1");
$st->execute([$e_id]);
$emp = $st->fetch(PDO::FETCH_ASSOC);
if (!$emp) {
  http_response_code(404);
  echo "Employee not found.";
  exit;
}

/* ---------- dept options ---------- */
$dept_opts = [];
if ($dept_tbl && $EMP_DEPT_FK) {
  $q = $pdo->query("SELECT `$DEPT_ID` AS id, `$DEPT_NAME` AS name FROM `$dept_tbl` ORDER BY `$DEPT_NAME` ASC");
  $dept_opts = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* ---------- file paths (preview) ---------- */
// বাংলা: ভিউতে শুধু প্রিভিউ দেখানোর জন্য—সেভ হবে update_query তে
$uploads1 = '/uploads/employee';
$uploads2 = '/upload/employee';
$photo_db = (string)($emp[$EMP_PHOT] ?? '');
$nid_db   = (string)($emp[$EMP_NID]  ?? '');

function guess_path(string $dbPath, string $empid, string $suffix=''): string {
  // বাংলা: DB path থাকলে সেটাই; না থাকলে ক্যানডিডেট বানাই
  if ($dbPath !== '') return $dbPath;
  $cands = [];
  if ($suffix==='NID') {
    foreach (['jpg','jpeg','png','webp','pdf'] as $ext) $cands[] = "/uploads/employee/{$empid}_NID.$ext";
    foreach (['jpg','jpeg','png','webp','pdf'] as $ext) $cands[] = "/upload/employee/{$empid}_NID.$ext";
  } else {
    foreach (['jpg','jpeg','png','webp'] as $ext) $cands[] = "/uploads/employee/{$empid}.$ext";
    foreach (['jpg','jpeg','png','webp'] as $ext) $cands[] = "/upload/employee/{$empid}.$ext";
  }
  // বাংলা: শুধু UI তে দেখানোর জন্য প্রথম ক্যান্ডিডেট রিটার্ন
  return $cands[0] ?? '';
}

$photo_url = guess_path($photo_db, $e_id, '');
$nid_url   = guess_path($nid_db,   $e_id, 'NID');

$page_title = "Edit Employee";
include $ROOT . '/partials/partials_header.php';
?>
<div class="container py-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Edit Employee</h4>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary" href="/public/hr/employee_view.php?e_id=<?php echo urlencode($e_id); ?>">View</a>
      <a class="btn btn-outline-dark" href="/public/hr/employee_list.php">Back to list</a>
    </div>
  </div>

  <form class="card shadow-sm" action="/public/hr/employee_edit_query.php" method="post" enctype="multipart/form-data">
    <div class="card-body">
      <input type="hidden" name="e_id" value="<?php echo h($e_id); ?>">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Employee ID</label>
          <input type="text" class="form-control" value="<?php echo h($e_id); ?>" disabled>
        </div>
        <div class="col-md-6">
          <label class="form-label">Status</label>
          <input type="text" name="status" class="form-control" value="<?php echo h((string)($emp[$EMP_STAT] ?? '')); ?>" placeholder="active / inactive / 1 / 0">
        </div>

        <div class="col-md-6">
          <label class="form-label">Name <span class="text-danger">*</span></label>
          <input type="text" name="name" class="form-control" required value="<?php echo h((string)($emp[$EMP_NAME] ?? '')); ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Mobile</label>
          <input type="text" name="mobile" class="form-control" value="<?php echo h((string)($emp[$EMP_MOB] ?? '')); ?>">
        </div>

        <div class="col-md-6">
          <label class="form-label">Department</label>
          <?php if ($dept_tbl && $EMP_DEPT_FK): ?>
            <select class="form-select" name="dept_id">
              <option value="">Select department</option>
              <?php
                $curr = (string)($emp[$EMP_DEPT_FK] ?? '');
                foreach($dept_opts as $d){
                  $sel = ((string)$d['id'] === $curr) ? 'selected' : '';
                  echo '<option value="'.h($d['id']).'" '.$sel.'>'.h($d['name']).'</option>';
                }
              ?>
            </select>
          <?php else: ?>
            <input type="text" name="dept_id" class="form-control" value="<?php echo h((string)($emp[$EMP_DEPT_FK] ?? '')); ?>" placeholder="Department">
          <?php endif; ?>
        </div>

        <div class="col-md-6">
          <label class="form-label">Join Date</label>
          <input type="date" name="join_date" class="form-control" value="<?php echo h(substr((string)($emp[$EMP_JOIN] ?? ''),0,10)); ?>">
        </div>

        <div class="col-12">
          <label class="form-label">Address</label>
          <input type="text" name="address" class="form-control" value="<?php echo h((string)($emp[$EMP_ADDR] ?? '')); ?>">
        </div>

        <div class="col-md-6">
          <label class="form-label">Salary (Gross)</label>
          <input type="number" step="0.01" name="salary" class="form-control" value="<?php echo h((string)($emp[$EMP_SAL] ?? '')); ?>">
        </div>

        <div class="col-md-6"></div>

        <div class="col-md-6">
          <label class="form-label d-flex justify-content-between">
            <span>Photo</span>
            <?php if ($photo_url): ?>
              <a class="small" href="<?php echo h($photo_url); ?>" target="_blank" rel="noopener">Open</a>
            <?php endif; ?>
          </label>
          <?php if ($photo_url): ?>
            <div class="mb-2">
              <img src="<?php echo h($photo_url); ?>" alt="Photo" class="img-thumbnail" style="max-height:140px">
            </div>
          <?php endif; ?>
          <input type="file" name="photo" class="form-control" accept=".jpg,.jpeg,.png,.webp">
          <div class="form-text">Replace photo (optional). Saved as <code>EMPID.ext</code></div>
        </div>

        <div class="col-md-6">
          <label class="form-label d-flex justify-content-between">
            <span>NID Copy</span>
            <?php if ($nid_url): ?>
              <a class="small" href="<?php echo h($nid_url); ?>" target="_blank" rel="noopener">Open</a>
            <?php endif; ?>
          </label>
          <?php if ($nid_url && !str_ends_with(strtolower($nid_url), '.pdf')): ?>
            <div class="mb-2">
              <img src="<?php echo h($nid_url); ?>" alt="NID" class="img-thumbnail" style="max-height:140px">
            </div>
          <?php endif; ?>
          <input type="file" name="nid" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf">
          <div class="form-text">Replace NID (optional). Saved as <code>EMPID_NID.ext</code></div>
        </div>

      </div>
    </div>
    <div class="card-footer d-flex justify-content-between">
      <a class="btn btn-outline-secondary" href="/public/hr/employee_view.php?e_id=<?php echo urlencode($e_id); ?>">Cancel</a>
      <button class="btn btn-primary" type="submit">Save Changes</button>
    </div>
  </form>
</div>
<?php include $ROOT . '/partials/partials_footer.php'; ?>
