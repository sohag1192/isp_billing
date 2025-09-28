<?php
// /public/migrate_user_wallets.php  (fixed: no hard dependency on users.name)
declare(strict_types=1);
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$rep = ['wallets_new'=>0,'wallets_exist'=>0,'backfilled'=>0,'vault_id'=>null,'errors'=>[]];

try {
  /* 1) Ensure accounts table/columns */
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS accounts (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(100) NOT NULL,
      type ENUM('cash','bank','mfs','other') DEFAULT 'other',
      number VARCHAR(100) NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      user_id INT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX(is_active), INDEX(type), INDEX(user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
  // older MySQL variants may ignore IF NOT EXISTS in ALTER silently — that's ok
  try { $pdo->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS user_id INT NULL"); } catch(Throwable $e){}
  try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_accounts_user ON accounts (user_id)"); } catch(Throwable $e){}

  // try to extend enum with 'user' (fallback to 'cash' if fails)
  $hasUserType = false;
  try {
    $stmt = $pdo->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='accounts' AND COLUMN_NAME='type' LIMIT 1");
    $stmt->execute(); $colType = (string)$stmt->fetchColumn();
    $hasUserType = stripos($colType, "'user'") !== false;
    if(!$hasUserType){
      $pdo->exec("ALTER TABLE accounts MODIFY type ENUM('cash','bank','mfs','other','user') DEFAULT 'user'");
      $hasUserType = true;
    }
  } catch(Throwable $e) {
    // leave $hasUserType=false -> we will fallback to 'cash'
  }

  /* 2) Ensure Company Vault (user_id NULL) */
  $st = $pdo->prepare("SELECT id FROM accounts WHERE user_id IS NULL AND name='Company Vault' LIMIT 1");
  $st->execute(); $vault = (int)($st->fetchColumn() ?: 0);
  if (!$vault){
    try{
      $pdo->exec("INSERT INTO accounts (name,type,is_active,user_id) VALUES ('Company Vault','cash',1,NULL)");
      $vault = (int)$pdo->lastInsertId();
    }catch(Throwable $e){ $rep['errors'][]='Vault create failed: '.$e->getMessage(); }
  }
  $rep['vault_id'] = $vault;

  /* 3) Load users WITHOUT assuming a 'name' column */
  $userCols = [];
  try { $userCols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN); } catch(Throwable $e){}
  $pick = null;
  foreach (['name','full_name','username','email'] as $c) {
    if (in_array($c, $userCols, true)) { $pick = $c; break; }
  }
  if ($pick) {
    $users = $pdo->query("SELECT id, $pick AS uname FROM users")->fetchAll(PDO::FETCH_ASSOC);
  } else {
    // absolutely minimal schema: only id
    $users = $pdo->query("SELECT id, CONCAT('User#',id) AS uname FROM users")->fetchAll(PDO::FETCH_ASSOC);
  }

  /* 4) Create per-user wallets if missing */
  $existing = $pdo->query("SELECT user_id FROM accounts WHERE user_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
  $has = array_flip(array_map('intval',$existing));
  $ins = $pdo->prepare("INSERT INTO accounts (name,type,is_active,user_id) VALUES (?,?,1,?)");
  foreach ($users as $u){
    $uid=(int)$u['id']; if($uid<=0) continue;
    if(isset($has[$uid])){ $rep['wallets_exist']++; continue; }
    $nm = (trim((string)$u['uname']) !== '' ? $u['uname'] : ('User#'.$uid));
    try { $ins->execute([$nm.' Wallet', $hasUserType?'user':'cash', $uid]); $rep['wallets_new']++; }
    catch(Throwable $e){ $ins->execute([$nm.' Wallet', 'cash', $uid]); $rep['wallets_new']++; }
  }

  /* 5) Ensure settlement table */
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS wallet_transfers (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      from_account_id INT NOT NULL,
      to_account_id   INT NOT NULL,
      amount DECIMAL(12,2) NOT NULL,
      method VARCHAR(50) NULL,
      ref_no VARCHAR(100) NULL,
      notes VARCHAR(255) NULL,
      created_by INT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX(from_account_id), INDEX(to_account_id), INDEX(created_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  /* 6) Backfill payments.account_id from received_by */
  $pcols = $pdo->query("SHOW COLUMNS FROM payments")->fetchAll(PDO::FETCH_COLUMN);
  if (in_array('received_by',$pcols,true) && in_array('account_id',$pcols,true)){
    $map=[]; foreach($pdo->query("SELECT user_id,id FROM accounts WHERE user_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $r){ $map[(int)$r['user_id']] = (int)$r['id']; }
    $sel = $pdo->query("SELECT id, received_by FROM payments WHERE received_by IS NOT NULL AND (account_id IS NULL OR account_id=0)");
    $upd = $pdo->prepare("UPDATE payments SET account_id=? WHERE id=?");
    foreach ($sel->fetchAll(PDO::FETCH_ASSOC) as $p){
      $uid=(int)$p['received_by']; $aid=$map[$uid]??0; if($aid>0){ $upd->execute([$aid,(int)$p['id']]); $rep['backfilled']++; }
    }
  }

} catch(Throwable $e){
  $rep['errors'][] = $e->getMessage();
}

include __DIR__.'/../partials/partials_header.php';
?>
<div class="container my-4">
  <h3>Wallet Migration</h3>
  <ul>
    <li>Wallets created: <b><?= (int)$rep['wallets_new'] ?></b></li>
    <li>Wallets already existed: <b><?= (int)$rep['wallets_exist'] ?></b></li>
    <li>Payments backfilled: <b><?= (int)$rep['backfilled'] ?></b></li>
    <li>Company Vault ID: <b><?= (int)$rep['vault_id'] ?></b></li>
  </ul>

  <?php if ($rep['errors']): ?>
    <div class="alert alert-warning">
      <b>Notes:</b>
      <ul class="mb-0"><?php foreach($rep['errors'] as $e) echo '<li>'.h($e).'</li>'; ?></ul>
    </div>
  <?php else: ?>
    <div class="alert alert-success">All good!</div>
  <?php endif; ?>

  <div class="mt-3 d-flex gap-2">
    <a href="/public/wallets.php" class="btn btn-primary">Open Wallet Dashboard</a>
    <a href="/public/wallet_settlement.php" class="btn btn-outline-primary">New Settlement</a>
  </div>
</div>
<?php include __DIR__.'/../partials/partials_footer.php'; ?>
