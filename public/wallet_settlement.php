<?php
// /public/wallet_settlement.php
// UI: English; Comments: বাংলা

declare(strict_types=1);
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function dbh(){ return db(); }
function is_ajax(): bool {
  if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH'])==='xmlhttprequest') return true;
  if (!empty($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) return true;
  return false;
}
function jres($arr, int $code=200){
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($arr);
  exit;
}
function tbl_exists(string $t): bool {
  try{ dbh()->query("SELECT 1 FROM `$t` LIMIT 1"); return true; }catch(Throwable $e){ return false; }
}
function hascol(string $t, string $c): bool {
  static $m = [];
  try{
    if (!isset($m[$t])) {
      $cols = dbh()->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN) ?: [];
      $m[$t] = array_flip($cols);
    }
    return isset($m[$t][$c]);
  }catch(Throwable $e){ return false; }
}
function can_approve(): bool {
  // বাংলা: কে অ্যাপ্রুভ করতে পারবে
  $is_admin = (int)($_SESSION['user']['is_admin'] ?? 0);
  $role = strtolower((string)($_SESSION['user']['role'] ?? ''));
  return $is_admin===1 || in_array($role, ['admin','superadmin','manager','accounts','accountant','billing'], true);
}

/* ===== CSRF ===== */
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];

/* ===== Ensure wallet_transfers table (safe baseline) ===== */
try{
  dbh()->exec("
    CREATE TABLE IF NOT EXISTS wallet_transfers (
      id INT AUTO_INCREMENT PRIMARY KEY,
      from_account_id INT NOT NULL,
      to_account_id   INT NOT NULL,
      amount DECIMAL(12,2) NOT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'pending',
      method VARCHAR(50) NULL,
      ref_no  VARCHAR(120) NULL,
      note    TEXT NULL,
      created_by INT NULL,
      created_at DATETIME NULL,
      approved_by INT NULL,
      approved_at DATETIME NULL,
      INDEX idx_from (from_account_id),
      INDEX idx_to (to_account_id),
      INDEX idx_status (status),
      INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
}catch(Throwable $e){ /* ignore */ }

/* ===== Users (label fallback) ===== */
$users = []; // id => label
try{
  $ucols = dbh()->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
  $pick = null;
  foreach (['name','full_name','username','email'] as $c) if (in_array($c,$ucols,true)) { $pick=$c; break; }
  if ($pick){
    $st = dbh()->query("SELECT id, $pick AS u FROM users ORDER BY id");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r){
      $users[(int)$r['id']] = ($r['u']!==null && $r['u']!=='') ? (string)$r['u'] : ('User#'.(int)$r['id']);
    }
  } else {
    $st = dbh()->query("SELECT id FROM users ORDER BY id");
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $uid) $users[(int)$uid] = 'User#'.(int)$uid;
  }
} catch(Throwable $e) {
  include __DIR__.'/../partials/partials_header.php';
  echo '<div class="alert alert-danger m-3">Users read failed: '.h($e->getMessage()).'</div>';
  include __DIR__.'/../partials/partials_footer.php';
  exit;
}

/* ===== Accounts map (user_id -> account_id) ===== */
$wallet_map = [];
try{
  if (!tbl_exists('accounts')) throw new Exception('Accounts table missing.');
  if (!hascol('accounts','user_id')) {
    // বাংলা: user_id না থাকলে অটো-অ্যাড করার চেষ্টা (শুধু একবারই চলবে)
    dbh()->exec("ALTER TABLE accounts ADD COLUMN user_id INT NULL");
  }
  $wallet_map = dbh()->query("SELECT user_id, id FROM accounts WHERE user_id IS NOT NULL")->fetchAll(PDO::FETCH_KEY_PAIR);
}catch(Throwable $e){
  include __DIR__.'/../partials/partials_header.php';
  echo '<div class="alert alert-danger m-3">Accounts mapping failed: '.h($e->getMessage()).'</div>';
  include __DIR__.'/../partials/partials_footer.php';
  exit;
}

/* ===== Company vault autodetect ===== */
$company_account_id = 0;
try{
  if (hascol('accounts','type')) {
    $st = dbh()->query("SELECT id FROM accounts WHERE type IN ('company','vault') ORDER BY id LIMIT 1");
    $company_account_id = (int)$st->fetchColumn();
  }
  if ($company_account_id<=0 && hascol('accounts','name')) {
    $st = dbh()->query("SELECT id FROM accounts WHERE (user_id IS NULL) AND (name LIKE '%Company%' OR name LIKE '%Vault%') ORDER BY id LIMIT 1");
    $company_account_id = (int)$st->fetchColumn();
  }
  if ($company_account_id<=0) {
    $st = dbh()->query("SELECT id FROM accounts WHERE user_id IS NULL ORDER BY id LIMIT 1");
    $company_account_id = (int)$st->fetchColumn();
  }
} catch(Throwable $e){ /* ignore */ }

/* ===== Balance helpers ===== */
function wallet_balance(int $account_id): float {
  // বাংলা: payments - approved out + approved in
  $payments = 0.0; $out=0.0; $in=0.0;

  if (tbl_exists('payments') && hascol('payments','account_id') && hascol('payments','amount')) {
    $p = dbh()->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE account_id=?");
    $p->execute([$account_id]);
    $payments = (float)$p->fetchColumn();
  }

  if (tbl_exists('wallet_transfers')) {
    if (hascol('wallet_transfers','from_account_id') && hascol('wallet_transfers','status') && hascol('wallet_transfers','amount')) {
      $o = dbh()->prepare("SELECT COALESCE(SUM(amount),0) FROM wallet_transfers WHERE from_account_id=? AND status='approved'");
      $o->execute([$account_id]);
      $out = (float)$o->fetchColumn();
    }
    if (hascol('wallet_transfers','to_account_id') && hascol('wallet_transfers','status') && hascol('wallet_transfers','amount')) {
      $i = dbh()->prepare("SELECT COALESCE(SUM(amount),0) FROM wallet_transfers WHERE to_account_id=? AND status='approved'");
      $i->execute([$account_id]);
      $in = (float)$i->fetchColumn();
    }
  }

  return $payments - $out + $in;
}

/* ===== Auto-create helper: ensure accounts row for a user ===== */
function ensure_user_wallet(int $user_id, array $users): int {
  if ($user_id<=0 || !tbl_exists('accounts') || !hascol('accounts','user_id')) return 0;

  // already exists?
  $st = dbh()->prepare("SELECT id FROM accounts WHERE user_id=? LIMIT 1");
  $st->execute([$user_id]);
  $id = (int)$st->fetchColumn();
  if ($id>0) return $id;

  // ডাইনামিক ফিল্ড সাপোর্ট
  $fields=['user_id']; $marks=['?']; $vals=[$user_id];

  if (hascol('accounts','name'))      { $fields[]='name';      $marks[]='?'; $vals[]='Wallet of '.($users[$user_id] ?? ('User#'.$user_id)); }
  if (hascol('accounts','type'))      { $fields[]='type';      $marks[]='?'; $vals[]='user'; }
  if (hascol('accounts','is_active')) { $fields[]='is_active'; $marks[]='?'; $vals[]=1; }
  if (hascol('accounts','created_at')){ $fields[]='created_at';$marks[]='?'; $vals[]=date('Y-m-d H:i:s'); }

  $sql = "INSERT INTO accounts (".implode(',',$fields).") VALUES (".implode(',',$marks).")";
  dbh()->prepare($sql)->execute($vals);
  return (int)dbh()->lastInsertId();
}

/* ===== AJAX: live balance for a user ===== */
if (isset($_GET['ajax']) && $_GET['ajax']==='balance') {
  $uid = (int)($_GET['user_id'] ?? 0);
  $acct = (int)($wallet_map[$uid] ?? 0);
  $bal  = $acct>0 ? wallet_balance($acct) : 0.0;
  jres(['ok'=>1,'account_id'=>$acct,'balance'=>$bal]);
}

/* ===== POST: create settlement ===== */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  try{
    // CSRF
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
      throw new Exception('Invalid CSRF token.');
    }

    $from_user_id  = (int)($_POST['from_user_id'] ?? 0);
    $to_type       = (string)($_POST['to_type'] ?? 'company'); // 'company' | 'user'
    $to_user_id    = (int)($_POST['to_user_id'] ?? 0);
    $amount        = (float)($_POST['amount'] ?? 0);
    $method        = trim((string)($_POST['method'] ?? ''));
    $ref_no        = trim((string)($_POST['ref_no'] ?? ''));
    $notes         = trim((string)($_POST['notes'] ?? ''));
    $approve_now   = (int)($_POST['approve_now'] ?? 0);
    $now           = date('Y-m-d H:i:s');

    if ($from_user_id<=0) throw new Exception('Select “From user”.');
    if ($amount <= 0)     throw new Exception('Amount must be greater than 0.');

    // From account → না থাকলে অটো-ক্রিয়েট
    $from_account_id = (int)($wallet_map[$from_user_id] ?? 0);
    if ($from_account_id<=0) {
      $from_account_id = ensure_user_wallet($from_user_id, $users);
      if ($from_account_id<=0) throw new Exception('From user has no wallet and auto-create failed.');
      $wallet_map[$from_user_id] = $from_account_id; // cache update
    }

    // To account
    if ($to_type==='user') {
      if ($to_user_id<=0) throw new Exception('Select “To user”.');
      $to_account_id = (int)($wallet_map[$to_user_id] ?? 0);
      if ($to_account_id<=0) {
        $to_account_id = ensure_user_wallet($to_user_id, $users);
        if ($to_account_id<=0) throw new Exception('Selected “To user” has no wallet and auto-create failed.');
        $wallet_map[$to_user_id] = $to_account_id;
      }
      if ($to_account_id === $from_account_id) throw new Exception('From and To wallet cannot be same.');
    } else {
      $to_account_id = $company_account_id;
      if ($to_account_id<=0) throw new Exception('Company vault not found.');
    }

    // dynamic insert fields based on existing columns
    $cols = dbh()->query("SHOW COLUMNS FROM wallet_transfers")->fetchAll(PDO::FETCH_COLUMN);
    $has_method      = in_array('method', $cols, true);
    $has_ref_no      = in_array('ref_no', $cols, true) || in_array('txn_ref',$cols,true);
    $ref_field       = in_array('ref_no',$cols,true) ? 'ref_no' : (in_array('txn_ref',$cols,true) ? 'txn_ref' : null);
    $has_note        = in_array('note', $cols, true) || in_array('notes',$cols,true);
    $note_field      = in_array('note', $cols, true) ? 'note' : (in_array('notes',$cols,true) ? 'notes' : null);
    $has_created_by  = in_array('created_by', $cols, true);
    $has_created_at  = in_array('created_at', $cols, true);
    $has_status      = in_array('status', $cols, true);
    $has_approved_by = in_array('approved_by', $cols, true);
    $has_approved_at = in_array('approved_at', $cols, true);

    $status = 'pending';
    $approved_by = null; $approved_at = null;
    if ($approve_now && can_approve()) {
      $status = 'approved';
      $approved_by = (int)($_SESSION['user']['id'] ?? 0);
      $approved_at = $now;
    }

    $fields = ['from_account_id','to_account_id','amount'];
    $marks  = ['?','?','?'];
    $vals   = [$from_account_id, $to_account_id, $amount];

    if ($has_status){      $fields[]='status';       $marks[]='?'; $vals[]=$status; }
    if ($has_method){      $fields[]='method';       $marks[]='?'; $vals[]=$method; }
    if ($ref_field){       $fields[]=$ref_field;     $marks[]='?'; $vals[]=$ref_no; }
    if ($note_field){      $fields[]=$note_field;    $marks[]='?'; $vals[]=$notes; }
    if ($has_created_by){  $fields[]='created_by';   $marks[]='?'; $vals[]=(int)($_SESSION['user']['id'] ?? 0); }
    if ($has_created_at){  $fields[]='created_at';   $marks[]='?'; $vals[]=$now; }
    if ($has_approved_by){ $fields[]='approved_by';  $marks[]='?'; $vals[]=$approved_by; }
    if ($has_approved_at){ $fields[]='approved_at';  $marks[]='?'; $vals[]=$approved_at; }

    $sql = "INSERT INTO wallet_transfers (".implode(',',$fields).") VALUES (".implode(',',$marks).")";
    $ins = dbh()->prepare($sql);
    $ok  = $ins->execute($vals);
    if(!$ok) throw new Exception('Failed to save settlement.');

    $msg = 'Settlement saved'.($status==='approved'?' (approved).':' (pending approval).');
    if (is_ajax()){
      jres(['status'=>'success','message'=>$msg]);
    } else {
      $_SESSION['flash_success'] = $msg;
      header('Location: /public/wallets.php'); exit;
    }
  }catch(Throwable $e){
    if (is_ajax()) jres(['status'=>'error','message'=>$e->getMessage()], 400);
    http_response_code(400);
    include __DIR__.'/../partials/partials_header.php';
    echo '<div class="container my-4"><div class="alert alert-danger">'.h($e->getMessage()).'</div></div>';
    include __DIR__.'/../partials/partials_footer.php';
    exit;
  }
}

/* ===== GET: show form ===== */
$from_user_id = (int)($_GET['from_user_id'] ?? ($_SESSION['user']['id'] ?? 0));
$from_account_id = (int)($wallet_map[$from_user_id] ?? 0);
$current_balance = $from_account_id>0 ? wallet_balance($from_account_id) : 0.0;

include __DIR__.'/../partials/partials_header.php';
?>
<div class="container-fluid py-3">
  <div class="d-flex align-items-center mb-3">
    <h5 class="mb-0">Wallet Settlement</h5>
    <a href="/public/wallets.php" class="btn btn-outline-secondary btn-sm ms-auto">
      <i class="bi bi-wallet2"></i> Wallets
    </a>
  </div>

  <div id="balWarn" class="alert alert-danger <?= ($current_balance<=0?'':'d-none') ?>">
    Current balance is <?= number_format($current_balance,2) ?>. You can still proceed — balance may go negative.
  </div>

  <div class="card shadow-sm">
    <div class="card-header bg-light py-2"><strong>New Settlement</strong></div>
    <div class="card-body">
      <form id="settleForm" action="/public/wallet_settlement.php" method="post" class="row g-3">
        <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
        <div class="col-12 col-md-4">
          <label class="form-label">From User</label>
          <select id="fromUser" name="from_user_id" class="form-select" required>
            <option value="">Select user...</option>
            <?php foreach($users as $uid=>$uname): ?>
              <option value="<?= (int)$uid ?>" <?= $uid===$from_user_id?'selected':'' ?>><?= h($uname) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Current balance: <strong id="fromBal"><?= number_format($current_balance,2) ?></strong></div>
        </div>

        <div class="col-12 col-md-4">
          <label class="form-label">Amount</label>
          <input id="amount" type="number" step="0.01" name="amount" class="form-control" required>
          <div id="amtNote" class="form-text text-danger d-none">Amount exceeds current balance; will go negative.</div>
        </div>

        <div class="col-12 col-md-4">
          <label class="form-label">Method</label>
          <select name="method" class="form-select">
            <option value="">Select...</option>
            <option value="Cash">Cash</option>
            <option value="bKash">bKash</option>
            <option value="Nagad">Nagad</option>
            <option value="Bank">Bank</option>
            <option value="Online">Online</option>
          </select>
        </div>

        <div class="col-12 col-md-4">
          <label class="form-label">Ref No (Slip/TRX)</label>
          <input type="text" name="ref_no" class="form-control">
        </div>

        <div class="col-12 col-md-8">
          <label class="form-label">Notes</label>
          <input type="text" name="notes" class="form-control" placeholder="optional">
        </div>

        <div class="col-12">
          <label class="form-label">To</label>
          <div class="row g-2">
            <div class="col-sm-4">
              <select id="toType" name="to_type" class="form-select">
                <option value="company" <?= $company_account_id>0?'':'disabled' ?>>Company Vault</option>
                <option value="user">Other User Wallet</option>
              </select>
            </div>
            <div class="col-sm-8">
              <select id="toUser" name="to_user_id" class="form-select" disabled>
                <option value="">Select user...</option>
                <?php foreach($users as $uid=>$uname): ?>
                  <option value="<?= (int)$uid ?>"><?= h($uname) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="form-text">Choose company vault or transfer to another user's wallet.</div>
        </div>

        <?php if (can_approve()): ?>
        <div class="col-12">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="approveNow" name="approve_now" value="1">
            <label class="form-check-label" for="approveNow">Approve now</label>
          </div>
        </div>
        <?php endif; ?>

        <div class="col-12 d-flex align-items-center gap-2">
          <button type="submit" class="btn btn-primary">Save Settlement</button>
          <a href="/public/wallets.php" class="btn btn-secondary">Close</a>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function(){
  const fromUser = document.getElementById('fromUser');
  const fromBalEl = document.getElementById('fromBal');
  const balWarn   = document.getElementById('balWarn');
  const amountEl  = document.getElementById('amount');
  const amtNote   = document.getElementById('amtNote');
  let currentBal  = parseFloat(fromBalEl?.textContent || '0') || 0;

  async function loadBalance(uid){
    if (!uid) { setBal(0); return; }
    try{
      const url = '/public/wallet_settlement.php?ajax=balance&user_id=' + encodeURIComponent(uid);
      const res = await fetch(url, { headers:{'X-Requested-With':'XMLHttpRequest'} });
      const data = await res.json();
      if (data && data.ok){
        setBal(parseFloat(data.balance || 0));
      }
    }catch(e){ console.error(e); }
  }
  function setBal(v){
    currentBal = +(+v).toFixed(2);
    if (fromBalEl) fromBalEl.textContent = currentBal.toFixed(2);
    if (balWarn){
      if (currentBal <= 0) balWarn.classList.remove('d-none');
      else balWarn.classList.add('d-none');
    }
    checkAmount();
  }
  function checkAmount(){
    if (!amountEl || !amtNote) return;
    const a = parseFloat(amountEl.value || '0') || 0;
    if (a > 0 && a > currentBal) amtNote.classList.remove('d-none');
    else amtNote.classList.add('d-none');
  }

  if (fromUser){
    fromUser.addEventListener('change', e => loadBalance(e.target.value));
  }
  if (amountEl){
    ['input','change','keyup'].forEach(ev => amountEl.addEventListener(ev, checkAmount));
  }

  const toType = document.getElementById('toType');
  const toUser = document.getElementById('toUser');
  function syncTo(){
    const isUser = toType && toType.value==='user';
    if (toUser){
      toUser.disabled = !isUser;
      if (!isUser) toUser.value='';
    }
  }
  if (toType){ toType.addEventListener('change', syncTo); syncTo(); }
})();
</script>

<?php include __DIR__.'/../partials/partials_footer.php'; ?>
