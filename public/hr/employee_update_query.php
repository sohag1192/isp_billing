<?php
// /public/hr/employee_update_query.php
// Update name by numeric id (safe), re-upload Photo/NID.

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','0'); ini_set('log_errors','1');

session_start();
if(!isset($_POST['_csrf'],$_SESSION['csrf']) || !hash_equals($_SESSION['csrf'],$_POST['_csrf'])){ http_response_code(419); exit('Invalid CSRF token.'); }

require_once __DIR__.'/../../app/db.php';
$pdo=db(); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);

/* helpers */
function h($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
function tbl_exists(PDO $pdo,string $t):bool{ try{$db=$pdo->query('SELECT DATABASE()')->fetchColumn();$q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");$q->execute([$db,$t]);return (bool)$q->fetchColumn();}catch(Throwable $e){return false;}}
function col_exists(PDO $pdo,string $t,string $c):bool{ try{$db=$pdo->query('SELECT DATABASE()')->fetchColumn();$q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");$q->execute([$db,$t,$c]);return (bool)$q->fetchColumn();}catch(Throwable $e){return false;}}
function pick_col(PDO $pdo,string $t,array $cands):?string{ foreach($cands as $c) if(col_exists($pdo,$t,$c)) return $c; return null; }
function mime_to_ext(string $m):?string{ $map=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/pdf'=>'pdf']; $m=strtolower($m); return $map[$m]??null; }
if(!function_exists('audit_log_safe')){ function audit_log_safe(string $a,?int $id=null,array $meta=[]):void{ if(!function_exists('audit_log'))return; try{audit_log($a,$id,$meta);return;}catch(Throwable $e){} try{audit_log($a,$id);return;}catch(Throwable $e){} try{audit_log($a);}catch(Throwable $e){} }}

/* table */
$T_EMP = tbl_exists($pdo,'emp_info') ? 'emp_info' : (tbl_exists($pdo,'employees') ? 'employees' : null);
if(!$T_EMP){ http_response_code(500); exit('Employees table not found.'); }

/* load row */
$id=(int)($_POST['id'] ?? 0);
$posted_code=trim((string)($_POST['e_id'] ?? ''));
$CODE_COL = pick_col($pdo,$T_EMP,['e_id','emp_id','employee_id','emp_code']);

$where=''; $param=null;
if($id>0){ $where="WHERE `id`=?"; $param=$id; }
elseif($posted_code!=='' && $CODE_COL){ $where="WHERE `$CODE_COL`=?"; $param=$posted_code; }
elseif($posted_code!=='' && ctype_digit($posted_code)){ $where="WHERE `id`=?"; $param=(int)$posted_code; }
else { http_response_code(400); exit('Missing employee identifier.'); }

$st=$pdo->prepare("SELECT * FROM `$T_EMP` $where LIMIT 1"); $st->execute([$param]); $row=$st->fetch(PDO::FETCH_ASSOC);
if(!$row){ http_response_code(404); exit('Employee not found.'); }

$pk_id=(int)($row['id'] ?? 0);
$emp_code = ($CODE_COL && !empty($row[$CODE_COL])) ? (string)$row[$CODE_COL] : (string)$pk_id;

$NAME_COL = pick_col($pdo,$T_EMP,['e_name','name']); if(!$NAME_COL){ http_response_code(500); exit('Name column not found.'); }
$new_name = trim((string)($_POST['e_name'] ?? '')); if($new_name===''){ http_response_code(400); exit('Name required.'); }
$HAS_UPDATED = col_exists($pdo,$T_EMP,'updated_at');

/* update + uploads */
$pdo->beginTransaction();
try{
  $sql="UPDATE `$T_EMP` SET `$NAME_COL`=:nm"; $params=[':nm'=>$new_name,':id'=>$pk_id];
  if($HAS_UPDATED){ $sql.=", `updated_at`=:ua"; $params[':ua']=date('Y-m-d H:i:s'); }
  $sql.=" WHERE `id`=:id LIMIT 1"; $q=$pdo->prepare($sql); $q->execute($params);

  $ROOT=dirname(__DIR__,2); $dir=$ROOT.'/upload/employee'; if(!is_dir($dir)) @mkdir($dir,0755,true);
  $finfo=new finfo(FILEINFO_MIME_TYPE); $MAX_IMG=5*1024*1024; $MAX_DOC=10*1024*1024;

  if(!empty($_FILES['photo']['tmp_name']) && is_uploaded_file($_FILES['photo']['tmp_name'])){
    if(filesize($_FILES['photo']['tmp_name'])>$MAX_IMG) throw new RuntimeException('Photo too large');
    $ext=mime_to_ext($finfo->file($_FILES['photo']['tmp_name']) ?: ''); if(!$ext || !in_array($ext,['jpg','png','webp'],true)) throw new RuntimeException('Invalid photo type');
    foreach(['jpg','png','webp'] as $e) @unlink($dir.'/'.$emp_code.'.'.$e);
    if(!move_uploaded_file($_FILES['photo']['tmp_name'],$dir.'/'.$emp_code.'.'.$ext)) throw new RuntimeException('Photo upload failed');
  }
  if(!empty($_FILES['nid_file']['tmp_name']) && is_uploaded_file($_FILES['nid_file']['tmp_name'])){
    if(filesize($_FILES['nid_file']['tmp_name'])>$MAX_DOC) throw new RuntimeException('NID file too large');
    $ext=mime_to_ext($finfo->file($_FILES['nid_file']['tmp_name']) ?: ''); if(!$ext || !in_array($ext,['jpg','png','webp','pdf'],true)) throw new RuntimeException('Invalid NID type');
    foreach(['jpg','png','webp','pdf'] as $e) @unlink($dir.'/'.$emp_code.'_NID.'.$e);
    if(!move_uploaded_file($_FILES['nid_file']['tmp_name'],$dir.'/'.$emp_code.'_NID.'.$ext)) throw new RuntimeException('NID upload failed');
  }

  $af=__DIR__.'/../../app/audit.php'; if(is_file($af)) require_once $af;
  audit_log_safe('employee_update',$pk_id,['e_id'=>$emp_code,'e_name'=>$new_name]);

  $pdo->commit();
  header('Location: /public/hr/employee_view.php?'.($CODE_COL ? 'e_id='.urlencode($emp_code) : 'id='.$pk_id).'&ok=1');
  exit;
}catch(Throwable $e){
  $pdo->rollBack(); http_response_code(500); echo 'Error: '.h($e->getMessage());
}
