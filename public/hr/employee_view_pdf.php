<?php
// /public/hr/employee_view_pdf.php
// Generate Employee Profile as A4 PDF (inline view).

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../vendor/autoload.php'; // dompdf autoload

use Dompdf\Dompdf;
use Dompdf\Options;

/* ---------- DB + helpers ---------- */
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);

function h($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
function tbl_exists(PDO $pdo,string $t):bool{ try{$db=$pdo->query('SELECT DATABASE()')->fetchColumn();$q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");$q->execute([$db,$t]);return (bool)$q->fetchColumn();}catch(Throwable $e){return false;}}
function col_exists(PDO $pdo,string $t,string $c):bool{ try{$db=$pdo->query('SELECT DATABASE()')->fetchColumn();$q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");$q->execute([$db,$t,$c]);return (bool)$q->fetchColumn();}catch(Throwable $e){return false;}}
function pick_col(PDO $pdo,string $t,array $cands):?string{ foreach($cands as $c){ if(col_exists($pdo,$t,$c)) return $c; } return null; }
function fmt_date(?string $d): string { $d=trim((string)$d); if($d===''||$d==='0000-00-00'||$d==='0000-00-00 00:00:00') return '—'; $ts=strtotime($d); return $ts?date('Y-m-d',$ts):'—'; }

function abs_path(string $webPath): string {
  $root = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/');
  return $root . $webPath;
}
function fs_uri(string $absPath): string {
  $absPath = realpath($absPath) ?: $absPath;
  return 'file://'.$absPath;
}

/* ---------- Identify table ---------- */
$T_EMP  = tbl_exists($pdo,'emp_info') ? 'emp_info' : (tbl_exists($pdo,'employees') ? 'employees' : null);
if(!$T_EMP){ http_response_code(500); exit('Employees table not found.'); }

$e_id = trim($_GET['e_id'] ?? '');
$id   = (int)($_GET['id'] ?? 0);
$CODE_COL = pick_col($pdo,$T_EMP,['e_id','emp_id','employee_id','emp_code']);
$where=''; $param=null;
if($id>0){ $where="WHERE e.`id`=?"; $param=$id; }
elseif($e_id!=='' && $CODE_COL){ $where="WHERE e.`$CODE_COL`=?"; $param=$e_id; }
else { http_response_code(400); exit('Bad request'); }

/* ---------- Columns ---------- */
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

/* ---------- Fetch ---------- */
$st=$pdo->prepare("SELECT * FROM `$T_EMP` e $where LIMIT 1");
$st->execute([$param]);
$emp=$st->fetch(PDO::FETCH_ASSOC);
if(!$emp){ http_response_code(404); exit('Not found'); }

/* ---------- Derive ---------- */
$emp_code=$CODE_COL && !empty($emp[$CODE_COL]) ? $emp[$CODE_COL] : $emp['id'];
$name=$NAME_COL?$emp[$NAME_COL]:'';
$des=$DES_COL?$emp[$DES_COL]:'';
$dob=$DOB_COL?$emp[$DOB_COL]:'';
$join=$JOIN_COL?$emp[$JOIN_COL]:'';
$gender=$GENDER_COL?$emp[$GENDER_COL]:'';
$mar=$MAR_COL?$emp[$MAR_COL]:'';
$bg=$BG_COL?$emp[$BG_COL]:'';
$nid=$NID_COL?$emp[$NID_COL]:'';
$phone=$PHONE_COL?$emp[$PHONE_COL]:'';
$email=$EMAIL_COL?$emp[$EMAIL_COL]:'';
$addrP=$PRES_COL?$emp[$PRES_COL]:'';
$addrR=$PERM_COL?$emp[$PERM_COL]:'';

/* ---------- Files ---------- */
$photo=''; $nidFile='';
$ROOT=dirname(__DIR__,2); $BASES=['/uploads/employee','/upload/employee'];
foreach($BASES as $base){
  foreach(['jpg','jpeg','png','webp'] as $ext){
    $p=$ROOT.$base.'/'.$emp_code.'.'.$ext; if(is_file($p)){ $photo=$base.'/'.$emp_code.'.'.$ext; break 2; }
  }
}
foreach($BASES as $base){
  foreach(['jpg','jpeg','png','webp','pdf'] as $ext){
    $p=$ROOT.$base.'/'.$emp_code.'_NID.'.$ext; if(is_file($p)){ $nidFile=$base.'/'.$emp_code.'_NID.'.$ext; break 2; }
  }
}
$nidIsImg = preg_match('/\.(jpg|jpeg|png|webp)$/i',$nidFile);
$nidIsPdf = preg_match('/\.pdf$/i',$nidFile);

$photoUri=$photo && is_file(abs_path($photo)) ? fs_uri(abs_path($photo)) : '';
$nidImgUri=$nidIsImg && is_file(abs_path($nidFile)) ? fs_uri(abs_path($nidFile)) : '';

/* ---------- Build HTML ---------- */
$html = '<html><head><meta charset="utf-8">
<style>
body{font-family:DejaVu Sans,Arial,sans-serif;font-size:11px}
.table{border-collapse:collapse;width:100%}
.table td{border:1px solid #000;padding:4px}
.photo{width:100px;height:100px;object-fit:cover;border:1px solid #000}
</style></head><body>
<h3 style="text-align:center;margin:0 0 10px">Employee Profile</h3>
<table class="table">
<tr><td rowspan="5" style="width:120px;text-align:center">'.($photoUri?'<img src="'.$photoUri.'" class="photo">':'No Photo').'</td>
<td><b>Name:</b> '.h($name).'</td></tr>
<tr><td><b>Code:</b> '.h($emp_code).'</td></tr>
<tr><td><b>Designation:</b> '.h($des).'</td></tr>
<tr><td><b>DOB:</b> '.h(fmt_date($dob)).'</td></tr>
<tr><td><b>Joining:</b> '.h(fmt_date($join)).'</td></tr>
<tr><td><b>Gender:</b> '.h($gender).'</td><td><b>Marital:</b> '.h($mar).'</td></tr>
<tr><td><b>Blood Group:</b> '.h($bg).'</td><td><b>NID:</b> '.h($nid).'</td></tr>
<tr><td><b>Phone:</b> '.h($phone).'</td><td><b>Email:</b> '.h($email).'</td></tr>
<tr><td colspan="2"><b>Present Address:</b> '.nl2br(h($addrP)).'</td></tr>
<tr><td colspan="2"><b>Permanent Address:</b> '.nl2br(h($addrR)).'</td></tr>
</table>
<h4>NID Copy:</h4>'.
($nidImgUri?'<img src="'.$nidImgUri.'" style="max-width:100%;height:auto;border:1px solid #000">':
($nidIsPdf?'<p>NID is PDF: '.$nidFile.'</p>':'<p>No NID file.</p>')).
'<br><br><table class="table"><tr>
<td style="height:60px;text-align:center">Prepared By</td>
<td style="height:60px;text-align:center">Checked By</td>
<td style="height:60px;text-align:center">Authorized Signature</td>
</tr></table>
</body></html>';

/* ---------- Render PDF ---------- */
$options = new Options();
$options->set('isRemoteEnabled',true);
$options->setChroot($_SERVER['DOCUMENT_ROOT']);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html,'UTF-8');
$dompdf->setPaper('A4','portrait');
$dompdf->render();
$dompdf->stream('Employee_'.$emp_code.'.pdf',['Attachment'=>false]); // inline view
exit;
