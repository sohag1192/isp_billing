<?php
// /public/expense_edit.php — Edit Expense (schema-aware) + Audit
// কোড ইংরেজি; কমেন্ট বাংলা।

declare(strict_types=1);
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/acl.php';
require_once __DIR__ . '/../app/audit.php';

require_perm('expense.edit'); // পারমিশন চেক

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$pdo = db();

/* ---- helpers: detect columns ---- */
function col_exists(PDO $pdo, string $t, string $c): bool{
  $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->execute([$db,$t,$c]);
  return (bool)$q->fetchColumn();
}
function pick_col_or_null(PDO $pdo, string $t, array $cands): ?string{
  foreach($cands as $c) if(col_exists($pdo,$t,$c)) return $c; return null;
}

/* ---- ensure soft-delete columns (safe) ---- */
if (!col_exists($pdo,'expenses','is_deleted')) $pdo->exec("ALTER TABLE expenses ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0, ADD KEY(is_deleted)");
if (!col_exists($pdo,'expenses','deleted_at')) $pdo->exec("ALTER TABLE expenses ADD COLUMN deleted_at DATETIME NULL");
if (!col_exists($pdo,'expenses','deleted_by')) $pdo->exec("ALTER TABLE expenses ADD COLUMN deleted_by BIGINT NULL");

/* ---- resolve column names ---- */
$dateCol   = pick_col_or_null($pdo,'expenses',['paid_at','expense_date','date','created_at']) ?? 'paid_at';
$amountCol = pick_col_or_null($pdo,'expenses',['amount','total','value']) ?? 'amount';
$methodCol = pick_col_or_null($pdo,'expenses',['method','payment_method']);
$headCol   = pick_col_or_null($pdo,'expenses',['head','title','purpose','category']); // legacy category-as-text
$refCol    = pick_col_or_null($pdo,'expenses',['ref_no','reference','voucher_no','ref']);
$noteCol   = pick_col_or_null($pdo,'expenses',['note','notes','remarks','description']);
$accCol    = col_exists($pdo,'expenses','account_id')  ? 'account_id'  : null;
$catCol    = col_exists($pdo,'expenses','category_id') ? 'category_id' : null;

/* ---- load dropdowns ---- */
$accounts = $pdo->query("SELECT id,name,type FROM expense_accounts WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$cats     = $pdo->query("SELECT id,name FROM expense_categories WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

/* ---- load expense ---- */
$id = (int)($_GET['id'] ?? 0);
if ($id<=0) { header('Location: /public/expenses.php'); exit; }

$row = $pdo->prepare("SELECT * FROM expenses WHERE id=? AND COALESCE(is_deleted,0)=0");
$row->execute([$id]);
$exp = $row->fetch(PDO::FETCH_ASSOC);
if (!$exp) { header('Location: /public/expenses.php'); exit; }

$err=''; $msg='';
if ($_SERVER['REQUEST_METHOD']==='POST'){
  // form values
  $amount  = (float)($_POST['amount'] ?? 0);
  $method  = trim($_POST['method'] ?? '');
  $head    = trim($_POST['head'] ?? '');
  $ref     = trim($_POST['ref_no'] ?? '');
  $note    = trim($_POST['note'] ?? '');
  $account = (int)($_POST['account_id'] ?? 0);
  $category= (int)($_POST['category_id'] ?? 0);
  $d       = trim($_POST['paid_at'] ?? ''); $t = trim($_POST['paid_time'] ?? '');
  $paid_at = $d ? ($d.' '.($t?:'00:00').':00') : ($exp[$dateCol] ?? date('Y-m-d H:i:s'));

  if($amount<=0) $err='Amount must be greater than 0';

  if($err===''){
    // old snapshot (subset)
    $old = [
      'date'=>$exp[$dateCol]??null,'amount'=>$exp[$amountCol]??null,
      'account_id'=>$accCol?($exp[$accCol]??null):null,
      'category_id'=>$catCol?($exp[$catCol]??null):null,
      'method'=>$methodCol?($exp[$methodCol]??null):null,
      'head'=>$headCol?($exp[$headCol]??null):null,
      'ref_no'=>$refCol?($exp[$refCol]??null):null,
      'note'=>$noteCol?($exp[$noteCol]??null):null,
    ];

    // build update list
    $cols=[]; $vals=[];
    $cols[]="`$dateCol`=?";   $vals[]=$paid_at;
    $cols[]="`$amountCol`=?"; $vals[]=round($amount,2);
    if($accCol){ $cols[]="`$accCol`=?"; $vals[]=$account?:null; }
    if($catCol){ $cols[]="`$catCol`=?"; $vals[]=$category?:null; }
    if($methodCol){ $cols[]="`$methodCol`=?"; $vals[]=$method?:null; }
    if($headCol){   $cols[]="`$headCol`=?";   $vals[]=$head!==''?$head:null; }
    if($refCol){    $cols[]="`$refCol`=?";    $vals[]=$ref?:null; }
    if($noteCol){   $cols[]="`$noteCol`=?";   $vals[]=$note?:null; }
    $vals[]=$id;

    $sql="UPDATE expenses SET ".implode(',',$cols)." WHERE id=?";
    $st=$pdo->prepare($sql); $st->execute($vals);

    // new snapshot
    $new = [
      'date'=>$paid_at,'amount'=>round($amount,2),
      'account_id'=>$accCol?($account?:null):null,
      'category_id'=>$catCol?($category?:null):null,
      'method'=>$methodCol?($method?:null):null,
      'head'=>$headCol?($head?:null):null,
      'ref_no'=>$refCol?($ref?:null):null,
      'note'=>$noteCol?($note?:null):null,
    ];

    audit_log('expense',$id,'update',$old,$new);
    $msg='Expense updated successfully.';
    // reload current row
    $row->execute([$id]); $exp=$row->fetch(PDO::FETCH_ASSOC);
  }
}

include __DIR__ . '/../partials/partials_header.php';
?>
<div class="container py-3">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h5 class="m-0"><i class="bi bi-pencil-square"></i> Edit Expense #<?= (int)$id ?></h5>
    <div class="d-flex gap-2">
      <a href="/public/expenses.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-list-ul"></i> Back to list</a>
      <?php if (show_if_can('expense.delete')): ?>
      <button class="btn btn-outline-danger btn-sm" id="btnDel"><i class="bi bi-trash"></i> Delete</button>
      <?php endif; ?>
    </div>
  </div>

  <?php if($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

  <?php
    // form defaults
    $dt = $exp[$dateCol] ?? date('Y-m-d H:i:s');
    $dPart = substr($dt,0,10);
    $tPart = substr($dt,11,5);
  ?>
  <form method="post" class="card p-3 shadow-sm">
    <div class="row g-3">
      <div class="col-12 col-md-3">
        <label class="form-label">Account</label>
        <select name="account_id" class="form-select">
          <option value="">(None)</option>
          <?php foreach($accounts as $a): ?>
            <option value="<?= (int)$a['id'] ?>" <?= ($accCol && (int)$exp[$accCol]===(int)$a['id'])?'selected':'' ?>>
              <?= h($a['name']) ?><?= $a['type']?' — '.h($a['type']):'' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">Category</label>
        <select name="category_id" class="form-select">
          <option value="">(None)</option>
          <?php foreach($cats as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= ($catCol && (int)$exp[$catCol]===(int)$c['id'])?'selected':'' ?>>
              <?= h($c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-2">
        <label class="form-label">Amount</label>
        <input type="number" step="0.01" min="0.01" name="amount" class="form-control" value="<?= h($exp[$amountCol] ?? '0.00') ?>" required>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Date</label>
        <input type="date" name="paid_at" class="form-control" value="<?= h($dPart) ?>">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Time</label>
        <input type="time" name="paid_time" class="form-control" value="<?= h($tPart) ?>">
      </div>

      <div class="col-12 col-md-3">
        <label class="form-label">Method</label>
        <select name="method" class="form-select">
          <?php $mth = $methodCol?($exp[$methodCol]??''):''; ?>
          <?php foreach(['','Cash','bKash','Nagad','Bank','Online'] as $m): ?>
            <option value="<?= h($m) ?>" <?= ($m===$mth)?'selected':''; ?>><?= $m?:'Select' ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-md-3">
        <label class="form-label">Head</label>
        <input name="head" class="form-control" value="<?= h($headCol?($exp[$headCol]??''):'') ?>" placeholder="e.g., Upstream Bill">
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">Reference No.</label>
        <input name="ref_no" class="form-control" value="<?= h($refCol?($exp[$refCol]??''):'') ?>">
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">Note</label>
        <input name="note" class="form-control" value="<?= h($noteCol?($exp[$noteCol]??''):'') ?>">
      </div>
    </div>

    <div class="mt-3 d-flex gap-2">
      <button class="btn btn-primary"><i class="bi bi-save"></i> Save changes</button>
      <a href="/public/expenses.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>

<?php if (show_if_can('expense.delete')): ?>
<script>
document.getElementById('btnDel')?.addEventListener('click', async ()=>{
  if(!confirm('Delete this expense? (soft-delete)')) return;
  const res = await fetch('/api/expense_delete.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ id: <?= (int)$id ?> })
  }).then(r=>r.json()).catch(()=>({ok:false}));
  if(res && res.ok){ window.location='/public/expenses.php?toast=Expense+deleted&type=success'; }
  else{ alert(res.error||'Delete failed'); }
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../partials/partials_footer.php'; ?>
