<?php
// /public/hr/employee_view.php
// Compact Bootstrap 5 layout + A4 one-page print;
// Photo only (no NID thumb); Bottom full-width NID Copy;
// Print-only Company Header/Logo; Print table lines; Footer signature boxes.
// + Wallet & Payments view (schema-aware; by employee_id / employee_code; global fallback)

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');

require_once __DIR__.'/../../partials/partials_header.php';
require_once __DIR__.'/../../app/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------- Company meta ---------- */
$COMPANY_NAME  = defined('COMPANY_NAME')  ? COMPANY_NAME  : 'Your Company Name';
$COMPANY_ADDR  = defined('COMPANY_ADDR')  ? COMPANY_ADDR  : 'Address line 1, City, Country';
$COMPANY_PHONE = defined('COMPANY_PHONE') ? COMPANY_PHONE : 'Phone: +8801XXXXXXXXX';
$COMPANY_EMAIL = defined('COMPANY_EMAIL') ? COMPANY_EMAIL : 'Email: info@example.com';
$COMPANY_LOGO  = '/assets/logo.png';

/* ---------- helpers ---------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function tbl_exists(PDO $pdo, string $t): bool {
  try{ $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
    $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
    $q->execute([$db,$t]); return (bool)$q->fetchColumn();
  }catch(Throwable $e){ return false; }
}
function col_exists(PDO $pdo, string $t, string $c): bool {
  try{ $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
    $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
    $q->execute([$db,$t,$c]); return (bool)$q->fetchColumn();
  }catch(Throwable $e){ return false; }
}
function pick_col(PDO $pdo, string $t, array $cands): ?string {
  foreach($cands as $c){ if(col_exists($pdo,$t,$c)) return $c; }
  return null;
}
function fmt_date(?string $d): string {
  $d=trim((string)$d);
  if($d===''||$d==='0000-00-00'||$d==='0000-00-00 00:00:00') return '—';
  $ts=strtotime($d); return $ts?date('Y-m-d',$ts):'—';
}

/* ---------- tables ---------- */
$T_EMP  = tbl_exists($pdo,'emp_info') ? 'emp_info' : (tbl_exists($pdo,'employees') ? 'employees' : null);
$T_DEPT = tbl_exists($pdo,'department_info') ? 'department_info' : (tbl_exists($pdo,'departments') ? 'departments' : null);
if(!$T_EMP){ echo '<div class="container py-4"><div class="alert alert-danger">Employees table not found.</div></div>'; require_once __DIR__.'/../../partials/partials_footer.php'; exit; }

/* ---------- identifier ---------- */
$e_id=trim($_GET['e_id']??$_GET['emp_id']??$_GET['employee_id']??$_GET['emp_code']??$_POST['e_id']??'');
$id=(int)($_GET['id']??$_POST['id']??0);
$CODE_COL=pick_col($pdo,$T_EMP,['e_id','emp_id','employee_id','emp_code']);
$where=''; $param=null;
if($id>0){ $where="WHERE e.`id`=?"; $param=$id; }
elseif($e_id!=='' && $CODE_COL){ $where="WHERE e.`$CODE_COL`=?"; $param=$e_id; }
elseif($e_id!=='' && ctype_digit($e_id)){ $where="WHERE e.`id`=?"; $param=(int)$e_id; }
else { header('Location:/public/hr/employees.php'); exit; }

/* ---------- columns ---------- */
$NAME_COL=pick_col($pdo,$T_EMP,['e_name','name']);
$DES_COL =pick_col($pdo,$T_EMP,['e_des','designation','title']);
$DOB_COL =pick_col($pdo,$T_EMP,['e_b_date','dob','birth_date']);
$JOIN_COL=pick_col($pdo,$T_EMP,['e_j_date','join_date','joining_date']);
$GENDER_COL=pick_col($pdo,$T_EMP,['e_gender','gender']);
$MAR_COL=pick_col($pdo,$T_EMP,['married_stu','marital_status']);
$BG_COL=pick_col($pdo,$T_EMP,['bgroup','blood_group']);
$NID_COL=pick_col($pdo,$T_EMP,['n_id','nid','national_id']);
$PHONE_COL=pick_col($pdo,$T_EMP,['e_cont_per','phone','mobile']);
$EMAIL_COL=pick_col($pdo,$T_EMP,['email']);
$PRES_COL=pick_col($pdo,$T_EMP,['pre_address','present_address']);
$PERM_COL=pick_col($pdo,$T_EMP,['per_address','permanent_address']);
$DEPT_FK=pick_col($pdo,$T_EMP,['department_id','dept_id','e_dept']);
$DEPT_ID=$T_DEPT?pick_col($pdo,$T_DEPT,['dept_id','id']):null;
$DEPT_NAME=$T_DEPT?pick_col($pdo,$T_DEPT,['dept_name','name']):null;
$join=''; $deptSelect='NULL AS department_name';
if($T_DEPT && $DEPT_FK && $DEPT_ID){
  $join="LEFT JOIN `$T_DEPT` d ON d.`$DEPT_ID`=e.`$DEPT_FK`";
  if($DEPT_NAME) $deptSelect="d.`$DEPT_NAME` AS department_name";
}

/* ---------- fetch ---------- */
$st=$pdo->prepare("SELECT e.*,$deptSelect FROM `$T_EMP` e $join $where LIMIT 1");
$st->execute([$param]);
$emp=$st->fetch(PDO::FETCH_ASSOC);
if(!$emp){ echo '<div class="container py-4"><div class="alert alert-danger">Employee not found.</div></div>'; require_once __DIR__.'/../../partials/partials_footer.php'; exit; }

/* ---------- derive ---------- */
$emp_code=($CODE_COL&&!empty($emp[$CODE_COL]))?(string)$emp[$CODE_COL]:(string)($emp['id']??'');
$name=$NAME_COL?(string)($emp[$NAME_COL]??''):'';   $des =$DES_COL?(string)($emp[$DES_COL ]??''):'';
$dob =$DOB_COL?(string)($emp[$DOB_COL ]??''):'';   $join=$JOIN_COL?(string)($emp[$JOIN_COL]??''):'';
$gender=$GENDER_COL?(string)($emp[$GENDER_COL]??''):'';  $mar=$MAR_COL?(string)($emp[$MAR_COL]??''):'';
$bg=$BG_COL?(string)($emp[$BG_COL]??''):'';        $nid=$NID_COL?(string)($emp[$NID_COL]??''):'';
$phone=$PHONE_COL?(string)($emp[$PHONE_COL]??''):''; $email=$EMAIL_COL?(string)($emp[$EMAIL_COL]??''):'';
$addrP=$PRES_COL?(string)($emp[$PRES_COL]??''):''; $addrR=$PERM_COL?(string)($emp[$PERM_COL]??''):'';
$deptName=(string)($emp['department_name']??'');

/* ---------- documents ---------- */
$DB_PHOTO_COL=pick_col($pdo,$T_EMP,['photo_path','photo','image']);
$DB_NID_COL  =pick_col($pdo,$T_EMP,['nid_path','nid_file','nid_scan']);
$photo=$DB_PHOTO_COL&&!empty($emp[$DB_PHOTO_COL])?(string)$emp[$DB_PHOTO_COL]:'';
$nidFile=$DB_NID_COL&&!empty($emp[$DB_NID_COL])?(string)$emp[$DB_NID_COL]:'';
$ROOT=dirname(__DIR__,2); $BASES=['/uploads/employee','/upload/employee'];
if($photo===''){ foreach($BASES as $base){ foreach(['jpg','jpeg','png','webp'] as $ext){ $p=$ROOT.$base.'/'.$emp_code.'.'.$ext; if(is_file($p)){ $photo=$base.'/'.$emp_code.'.'.$ext; break 2; }}}}
if($nidFile===''){ foreach($BASES as $base){ foreach(['jpg','jpeg','png','webp','pdf'] as $ext){ $p=$ROOT.$base.'/'.$emp_code.'_NID.'.$ext; if(is_file($p)){ $nidFile=$base.'/'.$emp_code.'_NID.'.$ext; break 2; }}}}
$nidIsImg=(bool)preg_match('/\.(jpg|jpeg|png|webp)$/i',(string)$nidFile);
$nidIsPdf=(bool)preg_match('/\.pdf$/i',(string)$nidFile);
$editHref=$CODE_COL?"/public/hr/employee_edit.php?e_id=".urlencode($emp_code):"/public/hr/employee_edit.php?id=".(int)($emp['id']??0);

/* ---------- WALLET & PAYMENTS (schema-aware + global fallback) ---------- */
$NUM_ID_COL = pick_col($pdo,$T_EMP,['id','e_id','employee_id']); // numeric PK
$emp_pk_val = $NUM_ID_COL && isset($emp[$NUM_ID_COL]) ? (int)$emp[$NUM_ID_COL] : null;

$walletBalance = null; $walletUpdated = null; $walletScope = null; $walletName = null;
if (tbl_exists($pdo,'wallets')) {
  $hasWId   = col_exists($pdo,'wallets','employee_id');
  $hasWCode = col_exists($pdo,'wallets','employee_code');

  // 1) Personal by id/code
  if ($hasWId && !is_null($emp_pk_val)) {
    $w = $pdo->prepare("SELECT id,name,balance,updated_at FROM wallets WHERE employee_id=? LIMIT 1");
    $w->execute([$emp_pk_val]); $row=$w->fetch(PDO::FETCH_ASSOC);
    if ($row){ $walletBalance=(float)$row['balance']; $walletUpdated=(string)($row['updated_at']??''); $walletScope='personal'; $walletName=(string)($row['name']??''); }
  }
  if ($walletBalance===null && $hasWCode && $emp_code!=='') {
    $w = $pdo->prepare("SELECT id,name,balance,updated_at FROM wallets WHERE employee_code=? LIMIT 1");
    $w->execute([$emp_code]); $row=$w->fetch(PDO::FETCH_ASSOC);
    if ($row){ $walletBalance=(float)$row['balance']; $walletUpdated=(string)($row['updated_at']??''); $walletScope='personal'; $walletName=(string)($row['name']??''); }
  }

  // 2) Global fallback (employee_id=0 OR name='Undeposited Funds')
  if ($walletBalance===null) {
    $row=null;
    if ($hasWId){
      $w=$pdo->query("SELECT id,name,balance,updated_at FROM wallets WHERE employee_id=0 LIMIT 1");
      $row=$w->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if(!$row){
      $w=$pdo->prepare("SELECT id,name,balance,updated_at FROM wallets WHERE name LIKE ? LIMIT 1");
      $w->execute(['Undeposited Funds']); $row=$w->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if($row){ $walletBalance=(float)$row['balance']; $walletUpdated=(string)($row['updated_at']??''); $walletScope='global'; $walletName=(string)($row['name']??'Undeposited Funds'); }
  }
}

// recent payments (optional)
$recentPayments = [];
if (tbl_exists($pdo,'employee_payments')) {
  $qp = "SELECT amount, method, created_at FROM employee_payments WHERE 1=1 ";
  $args = [];
  if (!is_null($emp_pk_val) && col_exists($pdo,'employee_payments','employee_id')) {
    $qp .= " AND (employee_id = ?"; $args[] = $emp_pk_val;
    if (col_exists($pdo,'employee_payments','employee_code')) { $qp .= " OR employee_code = ?"; $args[] = $emp_code; }
    $qp .= ") ";
  } elseif (col_exists($pdo,'employee_payments','employee_code')) {
    $qp .= " AND employee_code = ? "; $args[] = $emp_code;
  }
  $qp .= " ORDER BY created_at DESC LIMIT 10";
  $sp = $pdo->prepare($qp); $sp->execute($args);
  $recentPayments = $sp->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function wallet_badge_html(?float $bal): string {
  if ($bal===null) return '<span class="badge bg-secondary-subtle text-secondary border">—</span>';
  $cls = $bal < 0 ? 'bg-danger' : 'bg-success';
  return '<span class="badge '.$cls.'">'.number_format($bal,2).'</span>';
}
?>
<style>
:root{--ev-fs:14px;--ev-fs-small:12.5px;}
body{font-size:var(--ev-fs);}
.small-text{font-size:var(--ev-fs-small);}
.ev-hero{background:linear-gradient(135deg,#f8f9ff,#f0f7ff);border:1px solid #e9ecef;border-radius:.75rem;padding:.8rem 1rem}
.ev-avatar{width:160px;height:160px;object-fit:cover;border-radius:.5rem;border:1px solid #e9ecef;background:#fff;}
.ev-card{border:1px solid #e9ecef;border-radius:.5rem}
.ev-title{font-weight:600;font-size:.98rem;background:#f8f9fa;border-bottom:1px solid #eef1f4;padding:.55rem .75rem;border-radius:.5rem .5rem 0 0}
.ev-body{padding:.65rem .75rem}
.kv{display:grid;grid-template-columns:150px 1fr;gap:.35rem .75rem}
@media(max-width:576px){.kv{grid-template-columns:120px 1fr}}
.print-header{display:none}
@media print {
  @page{size:A4 portrait;margin:10mm}
  body{font-size:11px;line-height:1.25}
  .no-print{display:none!important}
  .ev-hero,.ev-card{border:1px solid #000!important;background:#fff!important}
  .ev-title{background:#f2f2f2!important;border-bottom:1px solid #000!important}
  .ev-avatar{width:120px;height:120px;border:1px solid #000!important}
  .kv>div{padding:3px 4px}
  .kv>div:nth-child(2n){border-bottom:1px solid #000}
  .kv>div:nth-child(2n-1){border-bottom:1px solid #000;border-right:1px solid #000;width:130px}
  .print-header{display:block;margin-bottom:6mm;border-bottom:1px solid #000;padding-bottom:4mm}
  .print-logo{width:70px;height:70px;object-fit:contain}
  .sign-box{border:1px solid #000;min-height:70px;padding:8px}
  .sig-line{border-top:1px solid #000;height:1px;margin-top:22px}
  .sig-label{font-size:11px;margin-top:4px}
}
</style>

<div class="container my-3">
  <!-- Print-only header -->
  <div class="print-header d-flex align-items-center">
    <?php if($COMPANY_LOGO && @getimagesize($_SERVER['DOCUMENT_ROOT'].$COMPANY_LOGO)): ?>
      <img src="<?php echo h($COMPANY_LOGO); ?>" class="print-logo me-3" alt="Logo">
    <?php endif; ?>
    <div>
      <div class="fw-bold"><?php echo h($COMPANY_NAME); ?></div>
      <div class="small-text"><?php echo h($COMPANY_ADDR); ?></div>
      <div class="small-text"><?php echo h($COMPANY_PHONE.' | '.$COMPANY_EMAIL); ?></div>
    </div>
  </div>

  <!-- Hero -->
  <div class="ev-hero mb-3 d-flex justify-content-between align-items-center flex-wrap">
    <div>
      <h3 class="mb-0"><?php echo h($name?:'—'); ?></h3>
      <?php if($des): ?><span class="badge bg-secondary-subtle text-secondary border"><?php echo h($des); ?></span><?php endif; ?>
      <span class="ms-2 small-text text-muted">Code:</span>
      <span class="badge bg-primary-subtle text-primary border"><?php echo h($emp_code); ?></span>
      <?php if($deptName): ?><span class="ms-2 small-text text-muted">Dept:</span><span class="small-text"><?php echo h($deptName); ?></span><?php endif; ?>
      <!-- Wallet chip -->
      <span class="ms-2 small-text text-muted">Wallet:</span>
      <?php echo wallet_badge_html($walletBalance); ?>
      <?php if($walletScope): ?>
        <small class="text-muted">(<?php echo $walletScope==='personal' ? 'Personal' : 'Global: '.h($walletName); ?>)</small>
      <?php endif; ?>
      <?php if($walletUpdated): ?><small class="text-muted">(updated <?php echo h($walletUpdated); ?>)</small><?php endif; ?>
    </div>
    <div class="no-print d-flex gap-2">
      <a class="btn btn-primary btn-sm" href="<?php echo h($editHref); ?>">Edit</a>
      <?php if($photo): ?><a class="btn btn-outline-secondary btn-sm" href="<?php echo h($photo); ?>" target="_blank">Photo</a><?php endif; ?>
      <?php if($nidFile): ?><a class="btn btn-outline-secondary btn-sm" href="<?php echo h($nidFile); ?>" target="_blank">NID</a><?php endif; ?>
      <button class="btn btn-outline-dark btn-sm" onclick="window.print()">Print</button>
      <a class="btn btn-outline-secondary btn-sm" href="/public/hr/employees.php">Back</a>
      <a class="btn btn-success btn-sm" 
         href="<?php echo h('/public/hr/employee_view_pdf.php?' . ($CODE_COL ? 'e_id='.urlencode($emp_code) : 'id='.(int)($emp['id']??0))); ?>" 
         target="_blank" rel="noopener">PDF</a>
    </div>
  </div>

  <!-- Photo + Details -->
  <div class="ev-card mb-3">
    <div class="ev-body row g-3">
      <div class="col-12 col-md-3">
        <?php if($photo): ?>
          <img src="<?php echo h($photo); ?>" class="ev-avatar" alt="Photo">
        <?php else: ?>
          <div class="ev-avatar d-flex align-items-center justify-content-center text-muted">No Photo</div>
        <?php endif; ?>
      </div>
      <div class="col-12 col-md-9">
        <div class="row g-2 small-text">
          <div class="col-12 col-md-6">
            <div class="ev-card">
              <div class="ev-title">Personal & Job</div>
              <div class="ev-body">
                <div class="kv">
                  <div class="fw-semibold">Department</div><div><?php echo $deptName?:'—'; ?></div>
                  <div class="fw-semibold">Date of Birth</div><div><?php echo h(fmt_date($dob)); ?></div>
                  <div class="fw-semibold">Joining Date</div><div><?php echo h(fmt_date($join)); ?></div>
                  <div class="fw-semibold">Gender</div><div><?php echo $gender?:'—'; ?></div>
                  <div class="fw-semibold">Marital Status</div><div><?php echo $mar?:'—'; ?></div>
                  <div class="fw-semibold">Blood Group</div><div><?php echo $bg?:'—'; ?></div>
                  <div class="fw-semibold">National ID</div><div><?php echo $nid?:'—'; ?></div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-12 col-md-6">
            <div class="ev-card">
              <div class="ev-title">Contact & Address</div>
              <div class="ev-body">
                <div class="kv">
                  <div class="fw-semibold">Personal Phone</div><div><?php echo $phone?:'—'; ?></div>
                  <div class="fw-semibold">Email</div><div><?php echo $email?:'—'; ?></div>
                </div>
                <hr class="my-2">
                <div class="kv">
                  <div class="fw-semibold">Present Address</div><div><?php echo $addrP?nl2br(h($addrP)):'—'; ?></div>
                  <div class="fw-semibold">Permanent Address</div><div><?php echo $addrR?nl2br(h($addrR)):'—'; ?></div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Wallet & Payments -->
  <div class="ev-card mb-3">
    <div class="ev-title">Wallet &amp; Payments</div>
    <div class="ev-body">
      <div class="d-flex align-items-center justify-content-between flex-wrap mb-2">
        <div>
          <span class="me-2">Current Balance:</span>
          <?php echo wallet_badge_html($walletBalance); ?>
          <?php if($walletUpdated): ?><small class="text-muted ms-2">updated <?php echo h($walletUpdated); ?></small><?php endif; ?>
        </div>
        <div class="no-print">
          <a class="btn btn-sm btn-outline-primary"
             href="/public/hr/employee_payment.php?employee_key=<?php echo urlencode($emp_code); ?>">
            <i class="bi bi-wallet2 me-1"></i> Add Payment
          </a>
        </div>
      </div>

      <?php if($recentPayments): ?>
        <div class="table-responsive">
          <table class="table table-sm table-bordered align-middle mb-0">
            <thead class="table-light">
              <tr><th width="120">Date</th><th width="120" class="text-end">Amount</th><th>Method</th></tr>
            </thead>
            <tbody>
              <?php foreach($recentPayments as $p): ?>
                <tr>
                  <td><?php echo h($p['created_at'] ?? ''); ?></td>
                  <td class="text-end"><?php echo number_format((float)($p['amount'] ?? 0),2); ?></td>
                  <td><?php echo h((string)($p['method'] ?? '')); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="text-muted">No recent payments found.</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Full-width NID Copy -->
  <?php if($nidFile): ?>
    <div class="ev-card">
      <div class="ev-title">NID Copy</div>
      <div class="ev-body">
        <?php if($nidIsImg): ?>
          <img src="<?php echo h($nidFile); ?>" alt="NID Copy" style="max-width:100%;height:auto;border:1px solid #dee2e6;border-radius:.5rem;">
        <?php elseif($nidIsPdf): ?>
          <div class="ratio ratio-4x3"><iframe src="<?php echo h($nidFile); ?>" style="border:1px solid #dee2e6;border-radius:.5rem;"></iframe></div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- Signature Boxes -->
  <div class="row g-2 mt-3">
    <div class="col-12 col-md-4"><div class="sign-box"><div class="sig-line"></div><div class="sig-label">Prepared By</div></div></div>
    <div class="col-12 col-md-4"><div class="sign-box"><div class="sig-line"></div><div class="sig-label">Checked By</div></div></div>
    <div class="col-12 col-md-4"><div class="sign-box"><div class="sig-line"></div><div class="sig-label">Authorized Signature</div></div></div>
  </div>
</div>

<?php require_once __DIR__.'/../../partials/partials_footer.php'; ?>
