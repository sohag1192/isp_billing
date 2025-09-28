<?php
// /public/expense_accounts.php
// Expense Accounts: list + add + activate/deactivate
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* --- schema helpers --- */
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
function ensure_accounts_table(PDO $pdo){
  if(!table_exists($pdo,'expense_accounts')){
    $pdo->exec("
      CREATE TABLE expense_accounts(
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        type VARCHAR(20) NULL,           -- Cash/Bank/Mobile/Other
        opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY(is_active), KEY(type)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
  }else{
    // ensure columns
    if(!col_exists($pdo,'expense_accounts','is_active')) $pdo->exec("ALTER TABLE expense_accounts ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
    if(!col_exists($pdo,'expense_accounts','opening_balance')) $pdo->exec("ALTER TABLE expense_accounts ADD COLUMN opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0");
  }
}
$pdo = db();
ensure_accounts_table($pdo);

/* --- actions --- */
$err=''; $ok='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  $name=trim($_POST['name']??'');
  $type=trim($_POST['type']??'');
  $opening=(float)($_POST['opening_balance']??0);
  if($name===''){ $err='Account name required'; }
  else{
    $st=$pdo->prepare("INSERT INTO expense_accounts(name,type,opening_balance,is_active) VALUES(?,?,?,1)");
    $st->execute([$name, $type?:null, round($opening,2)]);
    $ok='Account added';
  }
}
if(isset($_GET['toggle'])){
  $id=(int)$_GET['toggle'];
  $pdo->prepare("UPDATE expense_accounts SET is_active=1-is_active WHERE id=?")->execute([$id]);
  header("Location: expense_accounts.php"); exit;
}

/* --- data --- */
$rows=$pdo->query("SELECT * FROM expense_accounts ORDER BY is_active DESC, name ASC")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../partials/partials_header.php';
?>
<div class="container py-3">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0"><i class="bi bi-wallet2"></i> Expense Accounts</h4>
    <a class="btn btn-outline-secondary btn-sm" href="/public/expense_add.php"><i class="bi bi-plus-circle"></i> Add Expense</a>
  </div>

  <?php if($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
  <?php if($ok):  ?><div class="alert alert-success"><?= h($ok) ?></div><?php endif; ?>

  <form method="post" class="card p-3 mb-3">
    <div class="row g-2">
      <div class="col-12 col-md-4">
        <label class="form-label">Account Name <span class="text-danger">*</span></label>
        <input name="name" class="form-control" placeholder="e.g., Cash, Brac Bank, bKash Merchant" required>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Type</label>
        <select name="type" class="form-select">
          <?php foreach(['','Cash','Bank','Mobile','Other'] as $t): ?>
            <option value="<?= h($t) ?>"><?= $t?:'Select' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Opening Balance</label>
        <input type="number" step="0.01" name="opening_balance" class="form-control" value="0.00">
      </div>
      <div class="col-12 col-md-2 d-grid">
        <label class="form-label">&nbsp;</label>
        <button class="btn btn-primary"><i class="bi bi-save"></i> Save</button>
      </div>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-sm table-striped align-middle">
      <thead class="table-light">
        <tr><th>Name</th><th>Type</th><th class="text-end">Opening</th><th>Status</th><th>Action</th></tr>
      </thead>
      <tbody>
        <?php foreach($rows as $r): ?>
          <tr>
            <td class="fw-semibold"><?= h($r['name']) ?></td>
            <td><?= h($r['type'] ?: '-') ?></td>
            <td class="text-end"><?= number_format((float)$r['opening_balance'],2) ?></td>
            <td><?= $r['is_active']?'Active':'Inactive' ?></td>
            <td><a class="btn btn-sm <?= $r['is_active']?'btn-outline-danger':'btn-outline-success' ?>" href="?toggle=<?= (int)$r['id'] ?>">
              <?= $r['is_active']?'Deactivate':'Activate' ?></a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/../partials/partials_footer.php'; ?>
