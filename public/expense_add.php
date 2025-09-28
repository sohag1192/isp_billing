<?php
// /public/expense_add.php — schema-aware; maps category_id→category(name) when legacy string column is NOT NULL
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ===== schema helpers ===== */
function table_exists(PDO $pdo, string $t): bool{
  try{ $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
       $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
       $q->execute([$db,$t]); return (bool)$q->fetchColumn();
  }catch(Throwable $e){ return false; }
}
function col_exists(PDO $pdo, string $t, string $c): bool{
  try{ $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
       $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
       $q->execute([$db,$t,$c]); return (bool)$q->fetchColumn();
  }catch(Throwable $e){ return false; }
}
function pick_col_or_null(PDO $pdo, string $t, array $candidates): ?string{
  foreach($candidates as $c){ if(col_exists($pdo,$t,$c)) return $c; }
  return null;
}
function col_nullable(PDO $pdo, string $t, string $c): bool{
  try{
    $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
    $q=$pdo->prepare("SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
    $q->execute([$db,$t,$c]);
    return strtoupper((string)$q->fetchColumn()) !== 'NO';
  }catch(Throwable $e){ return true; }
}

/* ===== ensure tables/columns (safe) ===== */
function ensure_core_tables(PDO $pdo){
  if(!table_exists($pdo,'expenses')){
    $pdo->exec("
      CREATE TABLE expenses(
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        paid_at DATETIME NOT NULL,
        head VARCHAR(100) NULL,
        method VARCHAR(50) NULL,
        ref_no VARCHAR(100) NULL,
        note TEXT NULL,
        account_id INT NULL,
        category_id INT NULL,
        created_by BIGINT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY(paid_at), KEY(account_id), KEY(category_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
  }else{
    if(!col_exists($pdo,'expenses','amount'))   $pdo->exec("ALTER TABLE expenses ADD COLUMN amount DECIMAL(14,2) NOT NULL DEFAULT 0");
    if(!col_exists($pdo,'expenses','paid_at')){
      $pdo->exec("ALTER TABLE expenses ADD COLUMN paid_at DATETIME NULL DEFAULT NULL");
      $src=null; foreach(['expense_date','date','created_at'] as $c){ if(col_exists($pdo,'expenses',$c)){ $src=$c; break; } }
      if($src){
        $pdo->exec("UPDATE expenses SET paid_at = CASE WHEN $src IN ('0000-00-00','0000-00-00 00:00:00') OR $src IS NULL THEN NOW() ELSE $src END WHERE paid_at IS NULL");
      }else{
        $pdo->exec("UPDATE expenses SET paid_at = NOW() WHERE paid_at IS NULL");
      }
      try{ $pdo->exec("ALTER TABLE expenses MODIFY COLUMN paid_at DATETIME NOT NULL"); }catch(Throwable $e){}
    }
    if(!col_exists($pdo,'expenses','account_id'))  $pdo->exec("ALTER TABLE expenses ADD COLUMN account_id INT NULL");
    if(!col_exists($pdo,'expenses','category_id')) $pdo->exec("ALTER TABLE expenses ADD COLUMN category_id INT NULL");
  }
  if(!table_exists($pdo,'expense_accounts')){
    $pdo->exec("CREATE TABLE expense_accounts(id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL,type VARCHAR(20) NULL,opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0,is_active TINYINT(1) NOT NULL DEFAULT 1,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  }
  if(!table_exists($pdo,'expense_categories')){
    $pdo->exec("CREATE TABLE expense_categories(id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL,parent_id INT NULL,is_active TINYINT(1) NOT NULL DEFAULT 1,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY(parent_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  }
}

$pdo = db();
ensure_core_tables($pdo);

/* ===== detect actual columns ===== */
$dateCol   = pick_col_or_null($pdo,'expenses',['paid_at','expense_date','date','created_at']) ?? 'paid_at';
$amountCol = pick_col_or_null($pdo,'expenses',['amount','total','value']) ?? 'amount';
$headCol   = pick_col_or_null($pdo,'expenses',['head','category','title','name','purpose']);            // may be 'category' (legacy string)
$methodCol = pick_col_or_null($pdo,'expenses',['method','payment_method']);
$refCol    = pick_col_or_null($pdo,'expenses',['ref_no','ref','reference','voucher_no']);
$noteCol   = pick_col_or_null($pdo,'expenses',['note','notes','remarks','description']);
$accCol    = col_exists($pdo,'expenses','account_id')  ? 'account_id'  : null;
$catCol    = col_exists($pdo,'expenses','category_id') ? 'category_id' : null;

/* legacy string columns (some old schemas) */
$legacyAccountStr = col_exists($pdo,'expenses','account')  ? 'account'  : null;
$legacyCategoryStr= col_exists($pdo,'expenses','category') ? 'category' : null; // same as $headCol in many cases

$legacyAccountNotNull  = $legacyAccountStr ? !col_nullable($pdo,'expenses',$legacyAccountStr) : false;
$legacyCategoryNotNull = $legacyCategoryStr ? !col_nullable($pdo,'expenses',$legacyCategoryStr) : false;

$createdBy = pick_col_or_null($pdo,'expenses',['created_by','user_id','added_by']);

/* dropdown data */
$accounts = $pdo->query("SELECT id,name,type FROM expense_accounts WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$cats     = $pdo->query("SELECT id,name FROM expense_categories WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

/* ===== handle POST ===== */
$err='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  $amount   = (float)($_POST['amount'] ?? 0);
  $account  = (int)($_POST['account_id'] ?? 0);
  $category = (int)($_POST['category_id'] ?? 0);
  $method   = trim($_POST['method'] ?? '');
  $head     = trim($_POST['head'] ?? '');            // optional text
  $ref      = trim($_POST['ref_no'] ?? '');
  $note     = trim($_POST['note'] ?? '');
  $d        = trim($_POST['paid_at'] ?? date('Y-m-d'));
  $t        = trim($_POST['paid_time'] ?? '00:00');
  $paid_at  = $d ? ($d.' '.($t?:'00:00').':00') : date('Y-m-d H:i:s');

  if($amount<=0){ $err='Amount must be > 0'; }

  // --- Resolve names for legacy NOT NULL string columns ---
  $accountName  = null;
  $categoryName = null;
  if($legacyAccountStr || $legacyCategoryStr){
    if($account>0){
      $st=$pdo->prepare("SELECT name FROM expense_accounts WHERE id=?");
      $st->execute([$account]); $accountName = $st->fetchColumn() ?: null;
    }
    if($category>0){
      $st=$pdo->prepare("SELECT name FROM expense_categories WHERE id=?");
      $st->execute([$category]); $categoryName = $st->fetchColumn() ?: null;
    }
  }
  // যদি legacy category string NOT NULL হয়, প্রাধান্য পাবে:
  if($legacyCategoryNotNull){
    if($head===''){                     // form 'head' খালি থাকলে
      $head = $categoryName ?: 'Uncategorized';
    }
  }

  if($err===''){
    $cols=[]; $ph=[]; $vals=[];

    // date & amount (must)
    $cols[]="`$dateCol`";   $ph[]='?'; $vals[]=$paid_at;
    $cols[]="`$amountCol`"; $ph[]='?'; $vals[]=round($amount,2);

    // ids
    if($accCol){ $cols[]="`$accCol`"; $ph[]='?'; $vals[] = $account ?: null; }
    if($catCol){ $cols[]="`$catCol`"; $ph[]='?'; $vals[] = $category ?: null; }

    // legacy string columns mapping
    if($legacyAccountStr){
      $cols[]="`$legacyAccountStr`"; $ph[]='?';
      $vals[] = $accountName ?: ($legacyAccountNotNull ? 'Unknown' : null);
    }
    if($legacyCategoryStr){
      $cols[]="`$legacyCategoryStr`"; $ph[]='?';
      $vals[] = ($head !== '' ? $head : ($categoryName ?: ($legacyCategoryNotNull ? 'Uncategorized' : null)));
    }

    // other optional columns
    if($methodCol){ $cols[]="`$methodCol`"; $ph[]='?'; $vals[] = ($method?:null); }
    if($headCol && $headCol!=='category'){ // যদি headCol 'category' হয়, উপরে legacy ক্যাটাগরি দিয়েই সেট হলো
      $cols[]="`$headCol`"; $ph[]='?'; $vals[] = ($head!==''?$head:null);
    }
    if($refCol){  $cols[]="`$refCol`";  $ph[]='?'; $vals[] = ($ref?:null); }
    if($noteCol){ $cols[]="`$noteCol`"; $ph[]='?'; $vals[] = ($note?:null); }
    if($createdBy){
      $uid=null; if(isset($_SESSION['user']['id'])) $uid=(int)$_SESSION['user']['id']; elseif(isset($_SESSION['user_id'])) $uid=(int)$_SESSION['user_id'];
      $cols[]="`$createdBy`"; $ph[]='?'; $vals[]=$uid;
    }

    try{
      $sql="INSERT INTO expenses(".implode(',',$cols).") VALUES (".implode(',',$ph).")";
      $st=$pdo->prepare($sql); $st->execute($vals);
      header("Location: /public/expenses.php?toast=".rawurlencode('Expense added')."&type=success"); exit;
    }catch(Throwable $e){
      $err='Insert failed: '.$e->getMessage();
    }
  }
}

include __DIR__ . '/../partials/partials_header.php';
?>
<div class="container py-3">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0"><i class="bi bi-clipboard-plus"></i> Add Expense</h4>
    <div class="d-flex gap-2">
      <a href="/public/expense_accounts.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-wallet2"></i> Accounts</a>
      <a href="/public/expense_categories.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-tags"></i> Categories</a>
      <a href="/public/expenses.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-list-ul"></i> Expenses</a>
    </div>
  </div>

  <?php if($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

  <form method="post" class="card p-3 shadow-sm">
    <div class="row g-3">
      <div class="col-12 col-md-3">
        <label class="form-label">Account <span class="text-danger">*</span></label>
        <select name="account_id" class="form-select" required>
          <option value="">Select Account</option>
          <?php foreach($accounts as $a): ?>
            <option value="<?= (int)$a['id'] ?>"><?= h($a['name']) ?><?= $a['type']?' — '.h($a['type']):'' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">Category <span class="text-danger">*</span></label>
        <select name="category_id" class="form-select" required>
          <option value="">Select Category</option>
          <?php foreach($cats as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-2">
        <label class="form-label">Amount <span class="text-danger">*</span></label>
        <input type="number" step="0.01" min="0.01" name="amount" class="form-control" required>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Date</label>
        <input type="date" name="paid_at" value="<?= date('Y-m-d') ?>" class="form-control">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Time</label>
        <input type="time" name="paid_time" value="<?= date('H:i') ?>" class="form-control">
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">Method</label>
        <select name="method" class="form-select">
          <?php foreach(['','Cash','bKash','Nagad','Bank','Online'] as $m): ?>
            <option value="<?= h($m) ?>"><?= $m?:'Select' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">Head (optional)</label>
        <input name="head" class="form-control" placeholder="e.g., Upstream Bill">
        <div class="form-text">
          If your legacy table has a NOT NULL <code>category</code> column, this will auto-fill from the selected Category.
        </div>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">Reference No.</label>
        <input name="ref_no" class="form-control" placeholder="Voucher/Txn">
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">Note</label>
        <input name="note" class="form-control" placeholder="optional note">
      </div>
    </div>
    <div class="mt-3 d-flex gap-2">
      <button class="btn btn-primary"><i class="bi bi-save"></i> Save Expense</button>
      <a class="btn btn-outline-secondary" href="/public/expenses.php"><i class="bi bi-x"></i> Cancel</a>
    </div>
  </form>
</div>
<?php include __DIR__ . '/../partials/partials_footer.php'; ?>
