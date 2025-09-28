<?php
// /public/hr/employee_payment.php
// UI: English; Comments: Bangla
// Feature: Schema-aware payment + target wallet resolve (personal by id/code → else global Undeposited Funds → else create personal)
//          Saves employee_payments with wallet_id; updates wallets.balance atomically.

declare(strict_types=1);
require_once __DIR__ . '/../../partials/partials_header.php';
require_once __DIR__ . '/../../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------------- helpers ---------------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function tbl_exists(PDO $pdo, string $t): bool {
  try{ $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
       $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
       $q->execute([$db,$t]); return (bool)$q->fetchColumn(); }catch(Throwable $e){ return false; }
}
function col_exists(PDO $pdo, string $t, string $c): bool {
  try{ $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
       $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
       $q->execute([$db,$t,$c]); return (bool)$q->fetchColumn(); }catch(Throwable $e){ return false; }
}
function pick_col(PDO $pdo, string $t, array $cands): ?string {
  foreach($cands as $c) if(col_exists($pdo,$t,$c)) return $c;
  return null;
}
function find_emp_table(PDO $pdo): string {
  foreach (['emp_info','employees','hr_employees','employee'] as $t) if (tbl_exists($pdo,$t)) return $t;
  return 'emp_info';
}

/* ---------------- detect employees schema ---------------- */
$EMP_TBL = find_emp_table($pdo);
$EMP_PK_NUM = pick_col($pdo,$EMP_TBL,['id','e_id','employee_id']); // numeric
$EMP_CODE   = pick_col($pdo,$EMP_TBL,['emp_id','emp_code','employee_code',$EMP_PK_NUM ?? 'id']) ?? 'id';
$EMP_NAME   = pick_col($pdo,$EMP_TBL,['e_name','name','full_name','employee_name']) ?? $EMP_CODE;

/* ---------------- ensure tables/columns ---------------- */
// wallets (create if missing — আপনার টেবিল থাকলে কিছুই করবে না)
$pdo->prepare("CREATE TABLE IF NOT EXISTS wallets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NULL,
  employee_code VARCHAR(64) NULL,
  name VARCHAR(120) NULL,
  description TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NULL,
  balance DECIMAL(12,2) NOT NULL DEFAULT 0,
  updated_at DATETIME NULL,
  UNIQUE KEY uniq_wallet_empid (employee_id),
  UNIQUE KEY uniq_wallet_empcode (employee_code)
)")->execute();

$pdo->prepare("CREATE TABLE IF NOT EXISTS employee_payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NULL,
  employee_code VARCHAR(64) NULL,
  wallet_id INT NULL,
  amount DECIMAL(12,2) NOT NULL,
  method VARCHAR(50) NULL,
  notes TEXT NULL,
  created_at DATETIME
)")->execute();

/* ---------------- inputs ---------------- */
if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];

$msg=''; $err='';
$prefill_key = trim($_GET['employee_key'] ?? '');

/* ---------------- POST ---------------- */
if($_SERVER['REQUEST_METHOD']==='POST'){
  try{
    if(!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) throw new Exception('CSRF token mismatch');
    $employee_key = trim($_POST['employee_key'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $method = trim($_POST['method'] ?? '');
    $notes  = trim($_POST['notes'] ?? '');
    if($employee_key==='' || $amount<=0) throw new Exception('Employee and valid amount are required');

    // (বাংলা) এমপ্লয়ি সার্চ: numeric PK হলে id দিয়ে; নইলে কোড দিয়ে; দুটোই ট্রাই
    $emp = null;
    if($EMP_PK_NUM && ctype_digit($employee_key)){
      $st=$pdo->prepare("SELECT * FROM `$EMP_TBL` WHERE `$EMP_PK_NUM`=? LIMIT 1");
      $st->execute([(int)$employee_key]); $emp=$st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if(!$emp){
      $st=$pdo->prepare("SELECT * FROM `$EMP_TBL` WHERE `$EMP_CODE`=? LIMIT 1");
      $st->execute([$employee_key]); $emp=$st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if(!$emp){
      throw new Exception("Employee not found: ".$employee_key);
    }

    $emp_pk_val   = $EMP_PK_NUM ? (int)$emp[$EMP_PK_NUM] : null;
    $emp_code_val = (string)$emp[$EMP_CODE];
    $emp_name_val = (string)$emp[$EMP_NAME];

    // wallets columns
    $hasWId   = col_exists($pdo,'wallets','employee_id');
    $hasWCode = col_exists($pdo,'wallets','employee_code');

    // টার্গেট ওয়ালেট খুঁজে নাও: personal → global (employee_id=0 / Undeposited Funds) → create personal
    $walletRow = null;

    // 1) personal by id
    if($hasWId && $emp_pk_val!==null){
      $st=$pdo->prepare("SELECT * FROM wallets WHERE employee_id=? LIMIT 1");
      $st->execute([$emp_pk_val]); $walletRow=$st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    // 1b) personal by code
    if(!$walletRow && $hasWCode){
      $st=$pdo->prepare("SELECT * FROM wallets WHERE employee_code=? LIMIT 1");
      $st->execute([$emp_code_val]); $walletRow=$st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // 2) global fallback
    if(!$walletRow){
      if($hasWId){
        $st=$pdo->query("SELECT * FROM wallets WHERE employee_id=0 LIMIT 1");
        $walletRow=$st->fetch(PDO::FETCH_ASSOC) ?: null;
      }
      if(!$walletRow){
        $st=$pdo->prepare("SELECT * FROM wallets WHERE name LIKE ? LIMIT 1");
        $st->execute(['Undeposited Funds']); $walletRow=$st->fetch(PDO::FETCH_ASSOC) ?: null;
      }
    }

    // 3) still none → create personal
    if(!$walletRow){
      if($hasWId && $emp_pk_val!==null){
        $pdo->prepare("INSERT INTO wallets (employee_id, employee_code, name, balance, is_active, created_at, updated_at)
                       VALUES (?, ?, ?, 0, 1, NOW(), NOW())")
            ->execute([$emp_pk_val, ($hasWCode?$emp_code_val:null), "Wallet of ".$emp_name_val]);
      } elseif($hasWCode){
        $pdo->prepare("INSERT INTO wallets (employee_code, name, balance, is_active, created_at, updated_at)
                       VALUES (?, ?, 0, 1, NOW(), NOW())")
            ->execute([$emp_code_val, "Wallet of ".$emp_name_val]);
      } else {
        throw new Exception("wallets table missing required columns (employee_id or employee_code).");
      }
      $wid=(int)$pdo->lastInsertId();
      $st=$pdo->prepare("SELECT * FROM wallets WHERE id=?"); $st->execute([$wid]); $walletRow=$st->fetch(PDO::FETCH_ASSOC);
    }

    // --- Transaction: log payment + update wallet
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO employee_payments (employee_id, employee_code, wallet_id, amount, method, notes, created_at)
                   VALUES (?,?,?,?,?,?,NOW())")
        ->execute([$emp_pk_val, $emp_code_val, (int)$walletRow['id'], $amount, $method, $notes]);

    $pdo->prepare("UPDATE wallets SET balance = balance + ?, updated_at = NOW() WHERE id=?")
        ->execute([$amount, (int)$walletRow['id']]);
    $pdo->commit();

    $msg="Payment recorded & wallet updated (Wallet ID: ".(int)$walletRow['id'].").";
    $prefill_key = $emp_code_val;
  }catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    $err=$e->getMessage();
  }
}

/* ---------------- employees dropdown ---------------- */
$employees=[];
$sql="SELECT `$EMP_CODE` AS code, `$EMP_NAME` AS name".($EMP_PK_NUM?(", `$EMP_PK_NUM` AS pk"):"")." FROM `$EMP_TBL` ORDER BY `$EMP_NAME`";
$employees=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

?>
<div class="container my-4">
  <h3>Employee Payment</h3>
  <?php if($msg): ?><div class="alert alert-success"><?=h($msg)?></div><?php endif; ?>
  <?php if($err): ?><div class="alert alert-danger" style="white-space:pre-line"><?=h($err)?></div><?php endif; ?>

  <form method="post" class="card p-3 shadow-sm">
    <input type="hidden" name="csrf" value="<?=h($CSRF)?>">
    <div class="mb-3">
      <label class="form-label">Employee <span class="text-danger">*</span></label>
      <select name="employee_key" class="form-select" required>
        <option value="">-- Select Employee --</option>
        <?php foreach($employees as $e): $val=$e['code']!==''?$e['code']:($e['pk']??''); ?>
          <option value="<?=h((string)$val)?>" <?= $prefill_key!=='' && $prefill_key==(string)$val?'selected':''; ?>>
            <?=h(($e['name']??'').($e['code']?(' ('.$e['code'].')'):'') )?>
          </option>
        <?php endforeach; ?>
      </select>
      <div class="form-text">Select by Code (preferred). The system resolves PK automatically.</div>
    </div>
    <div class="mb-3">
      <label class="form-label">Amount <span class="text-danger">*</span></label>
      <input type="number" step="0.01" min="0.01" name="amount" class="form-control" required>
    </div>
    <div class="mb-3">
      <label class="form-label">Method</label>
      <input type="text" name="method" class="form-control" placeholder="Cash / Bank / Mobile">
    </div>
    <div class="mb-3">
      <label class="form-label">Notes</label>
      <textarea name="notes" class="form-control" rows="2"></textarea>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-primary" type="submit"><i class="bi bi-wallet2 me-1"></i> Record Payment</button>
      <a class="btn btn-outline-secondary" href="/public/hr/employees.php">Back to list</a>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../../partials/partials_footer.php'; ?>
