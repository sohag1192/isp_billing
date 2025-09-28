<?php
// /public/wallet_seed.php
// One wallet per user seeder (schema-aware: wallets / accounts)
// UI: English; Comments: বাংলা

declare(strict_types=1);
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

/* ---------------- Basic helpers ---------------- */
// (বাংলা) HTML escape
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// (বাংলা) PDO হ্যান্ডেল
$pdo = db();

// (বাংলা) টেবিল/কলাম আছে কিনা চেক
function tbl_exists(PDO $pdo,string $t): bool {
  try{
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $q  = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
    $q->execute([$db,$t]);
    return (bool)$q->fetchColumn();
  }catch(Throwable $e){ return false; }
}
function col_exists(PDO $pdo,string $t,string $c): bool {
  try{
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $q  = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
    $q->execute([$db,$t,$c]);
    return (bool)$q->fetchColumn();
  }catch(Throwable $e){ return false; }
}
// (বাংলা) scalar helper
function scalar(PDO $pdo,string $sql,array $p=[]){
  $st=$pdo->prepare($sql); $st->execute($p); return $st->fetchColumn();
}

// (বাংলা) কেবল অ্যাডমিন এই পেইজ রান করতে পারবে
function is_admin_user(): bool {
  $is_admin = (int)($_SESSION['user']['is_admin'] ?? 0);
  $role     = strtolower((string)($_SESSION['user']['role'] ?? ''));
  return $is_admin===1 || in_array($role, ['admin','superadmin','manager','accounts','accountant','billing'], true);
}
if(!is_admin_user()){
  http_response_code(403);
  echo "<h3 style='color:#b00'>Forbidden</h3><p>Admin only.</p>";
  exit;
}

/* ---------------- Sanity checks ---------------- */
if(!tbl_exists($pdo,'users')){
  echo "<h3 style='color:#b00'>Error</h3><p>Table <code>users</code> not found.</p>";
  exit;
}
$has_wallets  = tbl_exists($pdo,'wallets');
$has_accounts = tbl_exists($pdo,'accounts');

/* ---------------- Decide target table & owner column ---------------- */
// (বাংলা) কোন টেবিলে ওয়ালেট রাখছি: wallets (owner_user_id/user_id) অথবা accounts (user_id)
$target_table = null;   // 'wallets' | 'accounts'
$owner_col    = null;   // 'owner_user_id' | 'user_id'
$auto_added_col = false;

if($has_wallets){
  if(col_exists($pdo,'wallets','owner_user_id')) { $target_table='wallets'; $owner_col='owner_user_id'; }
  elseif(col_exists($pdo,'wallets','user_id'))   { $target_table='wallets'; $owner_col='user_id'; }
}
if(!$target_table && $has_accounts){
  if(col_exists($pdo,'accounts','user_id')) { $target_table='accounts'; $owner_col='user_id'; }
}

// (বাংলা) কিছুই না পেলে wallets.user_id কলাম নিজে যোগ করে নেই
if(!$target_table && $has_wallets){
  try{
    $pdo->exec("ALTER TABLE wallets ADD COLUMN user_id INT NULL");
    $target_table='wallets'; $owner_col='user_id'; $auto_added_col=true;
  }catch(Throwable $e){
    // ignore; নিচে error দেখাব
  }
}

if(!$target_table){
  echo "<h3 style='color:#b00'>Error</h3><p>Neither <code>wallets</code> nor <code>accounts</code> has a user ownership column. Please add <code>wallets.user_id</code> or <code>accounts.user_id</code>.</p>";
  exit;
}

/* ---------------- Optional column detection on target ---------------- */
// (বাংলা) যে টেবিলে ইনসার্ট করবো সেখানে কোন কোন কলাম আছে — ডাইনামিক চেক
$has = [
  'name'        => col_exists($pdo,$target_table,'name'),
  'status'      => col_exists($pdo,$target_table,'status'),
  'is_active'   => col_exists($pdo,$target_table,'is_active'),
  'active'      => col_exists($pdo,$target_table,'active'),
  'enabled'     => col_exists($pdo,$target_table,'enabled'),
  'is_deleted'  => col_exists($pdo,$target_table,'is_deleted'),
  'deleted'     => col_exists($pdo,$target_table,'deleted'),
  'balance'     => col_exists($pdo,$target_table,'balance'),
  'is_default'  => col_exists($pdo,$target_table,'is_default'),
  'created_at'  => col_exists($pdo,$target_table,'created_at'),
];
// (বাংলা) users.default_wallet_id থাকলে সেট করবো (শুধু wallets হলে)
$has_user_default = col_exists($pdo,'users','default_wallet_id');

/* ---------------- Users list with safe display field ---------------- */
// (বাংলা) users টেবিল থেকে কোন ফিল্ড দেখাবো তা সেফলি পিক করি
$user_cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN) ?: [];
$pick = null;
foreach(['name','full_name','username','email'] as $c){
  if(in_array($c,$user_cols,true)){ $pick=$c; break; }
}
if($pick){
  $users = $pdo->query("SELECT id, {$pick} AS u FROM users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
}else{
  $users = $pdo->query("SELECT id FROM users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
}

/* ---------------- Prepare statements ---------------- */
// (বাংলা) ইউজারের জন্য রেকর্ড আছে কিনা
$st_check = $pdo->prepare("SELECT id FROM {$target_table} WHERE {$owner_col}=? LIMIT 1");

// (বাংলা) INSERT স্টেটমেন্ট — আছে এমন কলাম দিয়েই
$fields = [$owner_col];
$place  = [':owner_id'];
if($has['name'])       { $fields[]='name';        $place[]=':name'; }
if($has['status'])     { $fields[]='status';      $place[]=':status'; }
if($has['is_active'])  { $fields[]='is_active';   $place[]=':is_active'; }
if($has['active'])     { $fields[]='active';      $place[]=':active'; }
if($has['enabled'])    { $fields[]='enabled';     $place[]=':enabled'; }
if($has['is_deleted']) { $fields[]='is_deleted';  $place[]=':is_deleted'; }
if($has['deleted'])    { $fields[]='deleted';     $place[]=':deleted'; }
if($has['balance'])    { $fields[]='balance';     $place[]=':balance'; }
if($has['is_default']) { $fields[]='is_default';  $place[]=':is_default'; }
if($has['created_at']) { $fields[]='created_at';  $place[]='NOW()'; }

$ins_sql = "INSERT INTO {$target_table} (".implode(',',$fields).") VALUES (".implode(',',$place).")";
$st_ins  = $pdo->prepare($ins_sql);

/* ---------------- Run seeding ---------------- */
$created=[]; $skipped=[]; $updated_default=[];

try{ $pdo->beginTransaction(); }catch(Throwable $e){ /* ignore */ }

try{
  foreach($users as $u){
    $uid = (int)$u['id'];
    $uname = trim((string)($u['u'] ?? '')) ?: ('user-'.$uid);

    // (বাংলা) আগেই আছে?
    $st_check->execute([$uid]);
    $existing_id = (int)$st_check->fetchColumn();

    if($existing_id>0){
      $skipped[] = [$uid,$uname,$existing_id];
      continue;
    }

    // (বাংলা) নতুন তৈরি
    $params = [ ':owner_id'=>$uid ];
    if($has['name'])       $params[':name']       = ucfirst($uname).' Wallet';
    if($has['status'])     $params[':status']     = 'active';
    if($has['is_active'])  $params[':is_active']  = 1;
    if($has['active'])     $params[':active']     = 1;
    if($has['enabled'])    $params[':enabled']    = 1;
    if($has['is_deleted']) $params[':is_deleted'] = 0;
    if($has['deleted'])    $params[':deleted']    = 0;
    if($has['balance'])    $params[':balance']    = 0.00;
    if($has['is_default']) $params[':is_default'] = 1;

    $st_ins->execute($params);

    // (বাংলা) নতুন আইডি — কিছু স্কিমায় AUTO_INCREMENT নাও থাকতে পারে
    $new_id = (int)$pdo->lastInsertId();
    if($new_id<=0){
      $new_id = (int)scalar($pdo,"SELECT id FROM {$target_table} WHERE {$owner_col}=? ORDER BY id DESC LIMIT 1",[$uid]);
    }
    $created[] = [$uid,$uname,$new_id];

    // (বাংলা) users.default_wallet_id সেট (শুধু wallets হলে)
    if($has_user_default && $target_table==='wallets' && $new_id>0){
      $cur = (int)scalar($pdo,"SELECT default_wallet_id FROM users WHERE id=?",[$uid]);
      if($cur<=0){
        $pdo->prepare("UPDATE users SET default_wallet_id=? WHERE id=?")->execute([$new_id,$uid]);
        $updated_default[] = [$uid,$uname,$new_id];
      }
    }
  }

  try{ if($pdo->inTransaction()) $pdo->commit(); }catch(Throwable $e){}
}catch(Throwable $e){
  try{ if($pdo->inTransaction()) $pdo->rollBack(); }catch(Throwable $e2){}
  echo "<h3 style='color:#b00'>Failed</h3><pre>".h($e->getMessage())."</pre>";
  exit;
}

/* ---------------- Output ---------------- */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Wallet Seeder</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
</head>
<body class="p-3">
  <h3>Wallet Seeder</h3>
  <p class="text-muted mb-2">
    Target table: <code><?=h($target_table)?></code>,
    owner column: <code><?=h($owner_col)?></code>
    <?php if($auto_added_col): ?>
      <br><span class="text-warning">Auto-added column <code>wallets.user_id</code>.</span>
    <?php endif; ?>
  </p>

  <div class="row g-3">

    <div class="col-md-6">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-success text-white">Created</div>
        <div class="card-body">
          <?php if(!$created): ?>
            <div class="text-muted">Nothing to create — all users already have a record.</div>
          <?php else: ?>
            <ul class="mb-0">
              <?php foreach($created as [$uid,$uname,$wid]): ?>
                <li>User <b><?=h($uname)?></b> (ID: <?=$uid?>) → <?=h($target_table)?> ID <b><?=$wid?></b></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-md-6">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-secondary text-white">Skipped (already had one)</div>
        <div class="card-body">
          <?php if(!$skipped): ?>
            <div class="text-muted">No existing records.</div>
          <?php else: ?>
            <ul class="mb-0">
              <?php foreach($skipped as [$uid,$uname,$wid]): ?>
                <li>User <b><?=h($uname)?></b> (ID: <?=$uid?>) already has <?=h($target_table)?> ID <b><?=$wid?></b></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if($updated_default): ?>
    <div class="col-12">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-info text-white">Default Wallet Set</div>
        <div class="card-body">
          <ul class="mb-0">
            <?php foreach($updated_default as [$uid,$uname,$wid]): ?>
              <li>Set <b><?=h($uname)?></b> (ID: <?=$uid?>) default_wallet_id = <b><?=$wid?></b></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    </div>
    <?php endif; ?>

  </div>

  <div class="mt-3">
    <a href="/public/billing.php" class="btn btn-primary">Back to Billing</a>
  </div>
</body>
</html>
