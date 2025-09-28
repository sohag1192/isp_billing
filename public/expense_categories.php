<?php
// /public/expense_categories.php
// Expense Categories: list + add (optional parent) + activate/deactivate
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

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
function ensure_categories_table(PDO $pdo){
  if(!table_exists($pdo,'expense_categories')){
    $pdo->exec("
      CREATE TABLE expense_categories(
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        parent_id INT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY(parent_id), KEY(is_active)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
  }else{
    if(!col_exists($pdo,'expense_categories','is_active')) $pdo->exec("ALTER TABLE expense_categories ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
  }
}
$pdo=db();
ensure_categories_table($pdo);

/* seed (optional) — একবারই চলবে */
$hasAny = (int)$pdo->query("SELECT COUNT(*) FROM expense_categories")->fetchColumn();
if($hasAny===0){
  $seed = ['Upstream Bill','Product Purchase','Employee Salary','Office Rent','Utility','Other'];
  $ins = $pdo->prepare("INSERT INTO expense_categories(name,is_active) VALUES(?,1)");
  foreach($seed as $s){ $ins->execute([$s]); }
}

/* actions */
$err=''; $ok='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  $name=trim($_POST['name']??'');
  $parent=(int)($_POST['parent_id']??0);
  if($name===''){ $err='Category name required'; }
  else{
    $pdo->prepare("INSERT INTO expense_categories(name,parent_id,is_active) VALUES(?,?,1)")
        ->execute([$name, $parent?:null]);
    $ok='Category added';
  }
}
if(isset($_GET['toggle'])){
  $id=(int)$_GET['toggle'];
  $pdo->prepare("UPDATE expense_categories SET is_active=1-is_active WHERE id=?")->execute([$id]);
  header("Location: expense_categories.php"); exit;
}

/* data */
$cats=$pdo->query("SELECT c.*, (SELECT name FROM expense_categories p WHERE p.id=c.parent_id) parent_name
                   FROM expense_categories c ORDER BY is_active DESC, COALESCE(parent_id,999999), name")->fetchAll(PDO::FETCH_ASSOC);

$parents=$pdo->query("SELECT id,name FROM expense_categories WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../partials/partials_header.php';
?>
<div class="container py-3">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0"><i class="bi bi-tags"></i> Expense Categories</h4>
    <a class="btn btn-outline-secondary btn-sm" href="/public/expense_add.php"><i class="bi bi-plus-circle"></i> Add Expense</a>
  </div>

  <?php if($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
  <?php if($ok):  ?><div class="alert alert-success"><?= h($ok) ?></div><?php endif; ?>

  <form method="post" class="card p-3 mb-3">
    <div class="row g-2">
      <div class="col-12 col-md-5">
        <label class="form-label">Category Name <span class="text-danger">*</span></label>
        <input name="name" class="form-control" placeholder="e.g., Upstream Bill Payment" required>
      </div>
      <div class="col-12 col-md-5">
        <label class="form-label">Parent (optional)</label>
        <select name="parent_id" class="form-select">
          <option value="0">— None —</option>
          <?php foreach($parents as $p): ?>
            <option value="<?= (int)$p['id'] ?>"><?= h($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-2 d-grid">
        <label class="form-label">&nbsp;</label>
        <button class="btn btn-primary"><i class="bi bi-save"></i> Save</button>
      </div>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-sm table-striped align-middle">
      <thead class="table-light"><tr><th>Name</th><th>Parent</th><th>Status</th><th>Action</th></tr></thead>
      <tbody>
        <?php foreach($cats as $c): ?>
          <tr>
            <td class="fw-semibold"><?= h($c['name']) ?></td>
            <td><?= h($c['parent_name'] ?: '-') ?></td>
            <td><?= $c['is_active']?'Active':'Inactive' ?></td>
            <td><a class="btn btn-sm <?= $c['is_active']?'btn-outline-danger':'btn-outline-success' ?>" href="?toggle=<?= (int)$c['id'] ?>">
                <?= $c['is_active']?'Deactivate':'Activate' ?></a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/../partials/partials_footer.php'; ?>
