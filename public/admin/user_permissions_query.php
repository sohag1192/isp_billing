<?php
// /public/admin/user_permissions_query.php — Save user allow/deny selections
declare(strict_types=1);
$ROOT = dirname(__DIR__, 2);
require_once $ROOT.'/app/require_login.php';
require_once $ROOT.'/app/db.php';
require_once $ROOT.'/app/acl.php';

if (!(acl_is_admin_role() || acl_is_username_admin())) { acl_forbid_403('Admin only.'); }

// CSRF
$t=$_POST['csrf_token']??''; if(!$t || !hash_equals((string)($_SESSION['csrf_token']??''),(string)$t)) acl_forbid_403('Invalid CSRF.');

$uid=(int)($_POST['user_id']??0);
$allow_ids=array_map('intval', $_POST['allow_ids']??[]);
$deny_ids =array_map('intval', $_POST['deny_ids'] ??[]);
if($uid<=0){ http_response_code(422); echo "Invalid user."; exit; }

$pdo=db(); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);

// ensure tables
function tbl_exists(PDO $pdo,string $t):bool{ $db=$pdo->query('SELECT DATABASE()')->fetchColumn(); $st=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?"); $st->execute([$db,$t]); return (bool)$st->fetchColumn(); }
if(!tbl_exists($pdo,'user_permissions') || !tbl_exists($pdo,'user_permission_denies')){
  http_response_code(500); echo "Missing tables. Run /tools/seed_user_perms.php"; exit;
}

// replace strategy — clear then insert
$pdo->beginTransaction();
try{
  $d=$pdo->prepare("DELETE FROM user_permissions WHERE user_id=?"); $d->execute([$uid]);
  if($allow_ids){
    $ins=$pdo->prepare("INSERT IGNORE INTO user_permissions(user_id,permission_id) VALUES(?,?)");
    foreach($allow_ids as $pid){ if($pid>0) $ins->execute([$uid,$pid]); }
  }

  $d2=$pdo->prepare("DELETE FROM user_permission_denies WHERE user_id=?"); $d2->execute([$uid]);
  if($deny_ids){
    $ins2=$pdo->prepare("INSERT IGNORE INTO user_permission_denies(user_id,permission_id) VALUES(?,?)");
    foreach($deny_ids as $pid){ if($pid>0) $ins2->execute([$uid,$pid]); }
  }
  $pdo->commit();
}catch(Throwable $e){
  $pdo->rollBack();
  http_response_code(500); echo "Save failed.";
  exit;
}

// refresh ACL cache for this user if same session
acl_reset_cache();
header("Location: /public/admin/user_permissions.php?user_id=".$uid."&saved=1");
exit;
