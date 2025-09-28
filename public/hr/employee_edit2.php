<?php
// /public/hr/employee_edit.php
// Load by e_id/emp_id/... or id; name editable; EMPID readonly; re-upload Photo/NID.

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','0'); ini_set('log_errors','1');

require_once __DIR__.'/../../partials/partials_header.php';
require_once __DIR__.'/../../app/db.php';

$pdo=db(); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
if(session_status()===PHP_SESSION_NONE) session_start();
if(!isset($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));
$csrf=$_SESSION['csrf'];

/* helpers */
function h($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
function tbl_exists(PDO $pdo,string $t):bool{ try{$db=$pdo->query('SELECT DATABASE()')->fetchColumn();$q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");$q->execute([$db,$t]);return (bool)$q->fetchColumn();}catch(Throwable $e){return false;}}
function col_exists(PDO $pdo,string $t,string $c):bool{ try{$db=$pdo->query('SELECT DATABASE()')->fetchColumn();$q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");$q->execute([$db,$t,$c]);return (bool)$q->fetchColumn();}catch(Throwable $e){return false;}}
function pick_col(PDO $pdo,string $t,array $cands):?string{ foreach($cands as $c) if(col_exists($pdo,$t,$c)) return $c; return null; }

/* table */
$T_EMP = tbl_exists($pdo,'emp_info') ? 'emp_info' : (tbl_exists($pdo,'employees') ? 'employees' : null);
if(!$T_EMP){ echo '<div class="container py-4"><div class="alert alert-danger">Employees table not found.</div></div>'; require_once __DIR__.'/../../partials/partials_footer.php'; exit; }

/* identifier */
$e_id=trim($_GET['e_id']??$_GET['emp_id']??$_GET['employee_id']??$_GET['emp_code']??$_POST['e_id']??'');
$id=(int)($_GET['id']??$_POST['id']??0);
$CODE_COL=pick_col($pdo,$T_EMP,['e_id','emp_id','employee_id','emp_code']);

$where=''; $param=null;
if($id>0){ $where="WHERE `id`=?"; $param=$id; }
elseif($e_id!=='' && $CODE_COL){ $where="WHERE `$CODE_COL`=?"; $param=$e_id; }
elseif($e_id!=='' && ctype_digit($e_id)){ $where="WHERE `id`=?"; $param=(int)$e_id; }
else { header('Location: /public/hr/employees.php'); exit; }

$st=$pdo->prepare("SELECT * FROM `$T_EMP` $where LIMIT 1"); $st->execute([$param]); $emp=$st->fetch(PDO::FETCH_ASSOC);
if(!$emp){ echo '<div class="container py-4"><div class="alert alert-danger">Employee not found.</div></div>'; require_once __DIR__.'/../../partials/partials_footer.php'; exit; }

$emp_code = ($CODE_COL && !empty($emp[$CODE_COL])) ? (string)$emp[$CODE_COL] : (string)($emp['id']??'');
$NAME_COL = pick_col($pdo,$T_EMP,['e_name','name']);
$curr_name = $NAME_COL ? (string)($emp[$NAME_COL]??'') : '';

$ROOT=dirname(__DIR__,2); $upDir=$ROOT.'/upload/employee'; $photo=''; $nid='';
foreach(['jpg','png','webp'] as $e){ $p=$upDir.'/'.$emp_code.'.'.$e; if(is_file($p)){ $photo='/upload/employee/'.basename($p); break; } }
foreach(['jpg','png','webp','pdf'] as $e){ $p=$upDir.'/'.$emp_code.'_NID.'.$e; if(is_file($p)){ $nid='/upload/employee/'.basename($p); break; } }
?>
<div class="container my-3">
  <div class="d-flex align-items-center gap-2 mb-3">
    <a href="/public/hr/employees.php" class="btn btn-outline-secondary btn-sm">← Back</a>
    <h5 class="mb-0">Edit Employee</h5>
  </div>

  <form method="post" action="/public/hr/employee_update_query.php" enctype="multipart/form-data" class="needs-validation" novalidate>
    <input type="hidden" name="_csrf" value="<?php echo h($csrf); ?>">
    <input type="hidden" name="e_id" value="<?php echo h($emp_code); ?>">
    <input type="hidden" name="id" value="<?php echo (int)($emp['id']??0); ?>">

    <div class="card shadow-sm">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-12 col-md-4">
            <label class="form-label">Employee ID</label>
            <input class="form-control" value="<?php echo h($emp_code); ?>" readonly>
          </div>
          <div class="col-12 col-md-8">
            <label class="form-label">Employee Name <span class="text-danger">*</span></label>
            <input class="form-control" name="e_name" value="<?php echo h($curr_name); ?>" required>
            <div class="invalid-feedback">Employee name is required.</div>
          </div>
        </div>
      </div>
    </div>

    <div class="card shadow-sm mt-3">
      <div class="card-header bg-light"><strong>Documents (Re-upload)</strong></div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-12 col-md-6">
            <label class="form-label">Employee Photo</label>
            <input type="file" name="photo" class="form-control" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
            <?php if($photo): ?><div class="form-text">Current: <a href="<?php echo h($photo); ?>" target="_blank">Open</a></div><?php endif; ?>
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label">NID</label>
            <input type="file" name="nid_file" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf">
            <?php if($nid): ?><div class="form-text">Current: <a href="<?php echo h($nid); ?>" target="_blank">Open</a></div><?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="d-flex justify-content-end gap-2 my-3">
      <a class="btn btn-outline-secondary" href="<?php echo ($CODE_COL? '/public/hr/employee_view.php?e_id='.urlencode($emp_code) : '/public/hr/employee_view.php?id='.(int)($emp['id']??0)); ?>">View Profile</a>
      <button class="btn btn-primary">Save Changes</button>
    </div>
  </form>
</div>

<script>
(function(){'use strict';const forms=document.querySelectorAll('.needs-validation');Array.prototype.slice.call(forms).forEach(function(f){f.addEventListener('submit',function(e){if(!f.checkValidity()){e.preventDefault();e.stopPropagation();}f.classList.add('was-validated');},false);});})();
</script>

<?php require_once __DIR__.'/../../partials/partials_footer.php'; ?>
