<?php
// /public/payment_add.php (ACCOUNTS-ONLY FULL-PAGE + POPUP + DISCOUNT + RECEIPT + Telegram instant notify)
// UI: English; Comments: বাংলা

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

/* ---------------------------------------------------------------------------
   Telegram loader (Hook → Core fallback)
   বাংলা: Telegram payment notification loader — hook থাকলে সেটা, না থাকলে core লোড
---------------------------------------------------------------------------- */
$TG_HOOK = __DIR__ . '/../tg/hook_payment.php';
$TG_CORE = __DIR__ . '/../tg/telegram.php';
if (is_readable($TG_HOOK)) {
  require_once $TG_HOOK;
} elseif (is_readable($TG_CORE)) {
  require_once $TG_CORE; // fallback: direct queue/sender helpers
}

/* ----------------- helpers ----------------- */
function resolve_user_name(PDO $pdo, int $user_id): string {
  if ($user_id<=0) return '';
  $cands = ['users','staff','employees','admin_users','admins'];
  $tbl = '';
  foreach ($cands as $t) {
    try {
      $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
      $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
      $q->execute([$db,$t]); if ($q->fetchColumn()) { $tbl=$t; break; }
    } catch(Throwable $e){}
  }
  if ($tbl==='') return '';
  // pick columns
  $id='id'; foreach(['id','user_id','uid'] as $c){ try{$db=$pdo->query('SELECT DATABASE()')->fetchColumn(); $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?"); $q->execute([$db,$tbl,$c]); if($q->fetchColumn()){ $id=$c; break; }}catch(Throwable $e){} }
  foreach(['name','full_name','display_name','username'] as $c){
    try{$db=$pdo->query('SELECT DATABASE()')->fetchColumn(); $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?"); $q->execute([$db,$tbl,$c]); if($q->fetchColumn()){ $st=$pdo->prepare("SELECT `$c` FROM `$tbl` WHERE `$id`=? LIMIT 1"); $st->execute([$user_id]); $v=$st->fetchColumn(); if($v) return (string)$v; }}catch(Throwable $e){}
  }
  // first+last
  $hasF=false;$hasL=false;
  try{$db=$pdo->query('SELECT DATABASE()')->fetchColumn(); $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME='first_name'"); $q->execute([$db,$tbl]); $hasF=(bool)$q->fetchColumn();}catch(Throwable $e){}
  try{$db=$pdo->query('SELECT DATABASE()')->fetchColumn(); $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME='last_name'");  $q->execute([$db,$tbl]); $hasL=(bool)$q->fetchColumn();}catch(Throwable $e){}
  if ($hasF || $hasL) {
    $sel=[]; if($hasF)$sel[]='first_name'; if($hasL)$sel[]='last_name';
    $st=$pdo->prepare("SELECT ".implode(',',array_map(fn($c)=>"`$c`",$sel))." FROM `$tbl` WHERE `$id`=? LIMIT 1");
    $st->execute([$user_id]); $u=$st->fetch(PDO::FETCH_ASSOC)?:[];
    $nm = trim(($u['first_name']??'').' '.($u['last_name']??''));
    if ($nm!=='') return $nm;
  }
  return '';
}

function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
function now_ts(): string { return date('Y-m-d H:i:s'); }
function ip_remote(): ?string {
  // বাংলা: প্রক্সি-aware
  $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
  if ($xff) { $ip = trim(explode(',', $xff)[0]); return $ip !== '' ? $ip : ($_SERVER['REMOTE_ADDR'] ?? null); }
  return $_SERVER['REMOTE_ADDR'] ?? null;
}
function jexit(array $p){
  if(!headers_sent()) header('Content-Type: application/json; charset=utf-8', true);
  echo json_encode($p, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}
function rollback_if(PDO $pdo){ try{ if($pdo->inTransaction()) $pdo->rollBack(); }catch(Throwable $e){} }
function tbl_exists(PDO $pdo, string $table): bool {
  static $memo=[]; if(isset($memo[$table])) return $memo[$table];
  try{
    $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
    $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
    $q->execute([$db,$table]);
    return $memo[$table]=(bool)$q->fetchColumn();
  }catch(Throwable $e){ return $memo[$table]=false; }
}
function col_exists(PDO $pdo, string $table, string $col): bool {
  static $memo=[]; $k="$table::$col";
  if(isset($memo[$k])) return $memo[$k];
  try{
    $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
    $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
    $q->execute([$db,$table,$col]);
    return $memo[$k]=(bool)$q->fetchColumn();
  }catch(Throwable $e){ return $memo[$k]=false; }
}
function ensure_ledger_col(PDO $pdo){
  // বাংলা: clients.ledger_balance না থাকলে যোগ করার চেষ্টা (silent)
  if(!col_exists($pdo,'clients','ledger_balance')){
    try{ $pdo->exec("ALTER TABLE clients ADD COLUMN ledger_balance DECIMAL(14,2) NOT NULL DEFAULT 0"); }catch(Throwable $e){}
  }
}
function parse_paid_at(?string $in): string {
  $in = trim((string)$in);
  if($in==='') return now_ts();
  $in = str_replace('T',' ',$in);
  $in = preg_replace('/[^0-9:\- ]/','',$in) ?? '';
  if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $in)) {
    if (strlen($in)===16) $in .= ':00';
    return $in;
  }
  return now_ts();
}

/* ----------------- Invoice recompute (discount-aware) ----------------- */
// return: [paid_sum, status, remaining, net_total]
function invoice_recompute(PDO $pdo, int $invoice_id): array {
  $has_disc = col_exists($pdo,'invoices','discount');
  $sel = "SELECT total".($has_disc?", COALESCE(discount,0) AS discount":"")." FROM invoices WHERE id=?";
  $st=$pdo->prepare($sel); $st->execute([$invoice_id]);
  $inv=$st->fetch(PDO::FETCH_ASSOC);
  if(!$inv) return [0.0,'unpaid',0.0,0.0];

  $payCol = col_exists($pdo,'payments','invoice_id') ? 'invoice_id' : (col_exists($pdo,'payments','bill_id') ? 'bill_id' : '');
  if ($payCol==='') return [0.0,'unpaid',0.0,(float)($inv['total'] ?? 0)];

  $sp=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE {$payCol}=?");
  $sp->execute([$invoice_id]);
  $paid=(float)$sp->fetchColumn();

  $total=(float)($inv['total'] ?? 0);
  $disc =(float)($has_disc ? ($inv['discount'] ?? 0) : 0);
  $net  = max(0.0, round($total - $disc, 2));
  $remaining=max(0.0, round($net-$paid,2));

  $status='unpaid';
  if($paid+0.01 >= $net){ $status='paid'; $remaining=0.0; }
  elseif($paid>0.00){ $status='partial'; }

  $fields=[]; $params=[];
  if (col_exists($pdo,'invoices','paid_amount')) { $fields[]='paid_amount=?'; $params[] = round($paid,2); }
  if (col_exists($pdo,'invoices','status'))      { $fields[]='status=?';      $params[] = $status; }
  if (col_exists($pdo,'invoices','updated_at'))  { $fields[]='updated_at=?';  $params[] = now_ts(); }
  if ($fields) {
    $sql = "UPDATE invoices SET ".implode(',', $fields)." WHERE id=?";
    $params[]=$invoice_id;
    $up=$pdo->prepare($sql); $up->execute($params);
  }
  return [round($paid,2), $status, $remaining, $net];
}

/* ========================== RENDER (GET) ========================== */
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$method_is_post = ($_SERVER['REQUEST_METHOD'] === 'POST');
$is_popup = (int)($_GET['popup'] ?? 0) === 1;

/* --- new: purpose & client_id resolve (GET/POST both) --- */
$purpose   = strtolower(trim($_GET['purpose'] ?? $_POST['purpose'] ?? ''));
$client_id_param = (int)($_GET['client_id'] ?? $_POST['client_id'] ?? 0);

if (!$method_is_post) {
  $invoice_id = (int)($_GET['invoice_id'] ?? 0);
  $return_url = trim($_GET['return'] ?? '');

  /* ---------------- NEW: Advance Payment form (no invoice required) ---------------- */
  if ($invoice_id <= 0 && $purpose === 'advance' && $client_id_param > 0) {
    // Load client
    $cst = $pdo->prepare("SELECT id, name, ledger_balance FROM clients WHERE id=? LIMIT 1");
    $cst->execute([$client_id_param]);
    $cli = $cst->fetch(PDO::FETCH_ASSOC);
    if (!$cli) {
      include __DIR__ . '/../partials/partials_header.php';
      echo '<div class="container py-4"><div class="alert alert-warning">Client not found.</div></div>';
      include __DIR__ . '/../partials/partials_footer.php';
      exit;
    }

    // accounts for select
    $me_id = (int)($_SESSION['user']['id'] ?? 0);
    $accs = [];
    if (tbl_exists($pdo,'accounts')) {
      $order = [];
      if (col_exists($pdo,'accounts','is_active')) $order[]='COALESCE(is_active,1) DESC';
      if (col_exists($pdo,'accounts','priority'))  $order[]='priority DESC';
      $order[]='name';
      $accs = $pdo->query("SELECT id,name FROM accounts ORDER BY ".implode(', ',$order))->fetchAll(PDO::FETCH_ASSOC);
    }
    $pref_acc = 0;
    if ($me_id && tbl_exists($pdo,'accounts') && col_exists($pdo,'accounts','user_id')) {
      $m=$pdo->prepare("SELECT id FROM accounts WHERE user_id=? LIMIT 1");
      $m->execute([$me_id]); $pref_acc=(int)($m->fetchColumn() ?: 0);
    }

    $page_title = 'Advance Payment';
    include __DIR__ . '/../partials/partials_header.php';
    ?>
    <div class="container py-4" style="max-width:680px">
      <div class="mb-2 d-flex justify-content-between align-items-center">
        <strong><?= h($page_title) ?></strong>
        <a class="btn btn-sm btn-outline-secondary" href="<?= h($return_url ?: '/public/billing.php') ?>">← Back</a>
      </div>

      <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between">
          <span><strong><?= h($cli['name'] ?? '-') ?></strong></span>
          <span class="text-muted">Client #<?= (int)$cli['id'] ?></span>
        </div>
        <div class="card-body">
          <div class="mb-3">
            <span class="badge <?= ((float)$cli['ledger_balance'])>0 ? 'bg-danger' : (((float)$cli['ledger_balance'])<0 ? 'bg-success':'bg-secondary') ?>">
              Ledger: <?= number_format((float)$cli['ledger_balance'],2) ?>
            </span>
            <div class="form-text">Advance দিলে ledger কমে গিয়ে নেগেটিভ হলে Advance হিসেবে ধরা হবে।</div>
          </div>

          <form method="POST" action="payment_add.php">
            <input type="hidden" name="purpose" value="advance">
            <input type="hidden" name="client_id" value="<?= (int)$cli['id'] ?>">
            <input type="hidden" name="return" value="<?= h($return_url) ?>">

            <div class="mb-3">
              <label class="form-label">Account <span class="text-danger">*</span></label>
              <select name="account_id" class="form-select" required>
                <option value="">— Select Account —</option>
                <?php foreach($accs as $a): $aid=(int)$a['id']; ?>
                  <option value="<?= $aid ?>" <?= $pref_acc && $pref_acc===$aid ? 'selected':'' ?>><?= h($a['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="row g-2">
              <div class="col-6">
                <label class="form-label">Amount</label>
                <input type="number" step="0.01" min="0.01" name="amount" class="form-control" value="0.00" required>
              </div>
              <div class="col-6">
                <label class="form-label">Method</label>
                <select name="method" class="form-select">
                  <option value="Cash" selected>Cash</option>
                  <option value="BKash">BKash</option>
                  <option value="Nagad">Nagad</option>
                  <option value="Bank">Bank</option>
                </select>
              </div>
              <div class="col-6">
                <label class="form-label">Transaction ID</label>
                <input type="text" name="txn_id" class="form-control" placeholder="Optional">
              </div>
              <div class="col-6">
                <label class="form-label">Paid At</label>
                <input type="datetime-local" name="paid_at" class="form-control" value="<?= h(date('Y-m-d\TH:i')) ?>">
              </div>
              <div class="col-12">
                <label class="form-label">Remarks</label>
                <input type="text" name="remarks" class="form-control" placeholder="Optional">
              </div>
            </div>

            <div class="mt-3 d-flex gap-2">
              <button class="btn btn-success"><i class="bi bi-check2-circle"></i> Save Advance</button>
              <a class="btn btn-outline-secondary" href="<?= h($return_url ?: '/public/billing.php') ?>">Cancel</a>
            </div>
          </form>
        </div>
      </div>
    </div>
    <?php
    include __DIR__ . '/../partials/partials_footer.php';
    exit;
  }
  /* ---------------- /NEW Advance form ---------------- */

  /* ======= Old Invoice-based GET form (unchanged) ======= */
  if ($invoice_id <= 0) {
    http_response_code(400);
    $page_title = 'Add Payment';
    include __DIR__ . '/../partials/partials_header.php';
    echo '<div class="container py-4"><div class="alert alert-danger">Invalid invoice_id.</div></div>';
    include __DIR__ . '/../partials/partials_footer.php';
    exit;
  }

  // Load invoice + client
  $has_disc = col_exists($pdo,'invoices','discount');
  $st=$pdo->prepare("SELECT i.id,i.client_id,i.total,i.paid_amount,i.status,i.method,i.billing_month".
                    ($has_disc?", COALESCE(i.discount,0) AS discount":"").
                    ",c.name client_name
                     FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.id=? LIMIT 1");
  $st->execute([$invoice_id]);
  $inv=$st->fetch(PDO::FETCH_ASSOC);
  if(!$inv){
    http_response_code(404);
    $page_title = 'Add Payment';
    include __DIR__ . '/../partials/partials_header.php';
    echo '<div class="container py-4"><div class="alert alert-warning">Invoice not found.</div></div>';
    include __DIR__ . '/../partials/partials_footer.php';
    exit;
  }

  // Accounts (for select)
  $me_id = (int)($_SESSION['user']['id'] ?? 0);
  $accs = [];
  if (tbl_exists($pdo,'accounts')) {
    $order = [];
    if (col_exists($pdo,'accounts','is_active')) $order[]='COALESCE(is_active,1) DESC';
    if (col_exists($pdo,'accounts','priority'))  $order[]='priority DESC';
    $order[]='name';
    $sql = "SELECT id,name FROM accounts ORDER BY ".implode(', ',$order);
    $accs = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
  }
  $pref_acc = 0;
  if ($me_id && tbl_exists($pdo,'accounts') && col_exists($pdo,'accounts','user_id')) {
    $m=$pdo->prepare("SELECT id FROM accounts WHERE user_id=? LIMIT 1");
    $m->execute([$me_id]);
    $pref_acc = (int)($m->fetchColumn() ?: 0);
  }

  // Remaining calc (discount aware)
  $paid_now = (float)($inv['paid_amount'] ?? 0);
  $disc     = (float)($inv['discount'] ?? 0);
  $net      = max(0.0, (float)$inv['total'] - $disc);
  $remain   = max(0.0, $net - $paid_now);

  $page_title = 'Add Payment';

  include __DIR__ . '/../partials/partials_header.php';
  ?>
  <div class="main-content p-3 p-md-4">
    <div class="container-fluid" style="max-width:680px">
      <div class="mb-2 d-flex justify-content-between align-items-center">
        <strong><?= h($page_title) ?></strong>
        <a class="btn btn-sm btn-outline-secondary" href="<?= h($_GET['return'] ?? '/public/billing.php') ?>">← Back</a>
      </div>

      <div class="card shadow-sm">
        <div class="card-header">
          <div class="d-flex justify-content-between">
            <span><strong><?= h($inv['client_name'] ?? '-') ?></strong></span>
            <span class="muted">Invoice #<?= (int)$invoice_id ?></span>
          </div>
        </div>
        <div class="card-body">
          <style>
            .muted{color:#6b7280;}
            .mini-help{font-size:.85rem;color:#6b7280}
          </style>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label">Invoice Total</label>
              <input type="text" class="form-control" value="<?= number_format((float)$inv['total'],2) ?>" readonly>
            </div>
            <div class="col-6">
              <label class="form-label">Current Discount</label>
              <input type="text" class="form-control" value="<?= number_format($disc,2) ?>" readonly>
            </div>
            <div class="col-6">
              <label class="form-label">Already Paid</label>
              <input type="text" class="form-control" value="<?= number_format($paid_now,2) ?>" readonly>
            </div>
            <div class="col-6">
              <label class="form-label">Net Payable</label>
              <input type="text" class="form-control" id="net_payable" value="<?= number_format($net,2) ?>" readonly>
            </div>
          </div>

          <form method="POST" action="payment_add.php" id="payForm">
            <input type="hidden" name="invoice_id" value="<?= (int)$invoice_id ?>">
            <input type="hidden" name="return" value="<?= h($_GET['return'] ?? '') ?>">

            <div class="mb-3">
              <label class="form-label">Account <span class="text-danger">*</span></label>
              <select name="account_id" class="form-select" required>
                <option value="">— Select Account —</option>
                <?php foreach($accs as $a): $aid=(int)$a['id']; ?>
                  <option value="<?= $aid ?>" <?= $pref_acc && $pref_acc===$aid ? 'selected':'' ?>><?= h($a['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Received money will be credited to this account.</div>
            </div>

            <div class="row g-2">
              <div class="col-6">
                <label class="form-label">Amount</label>
                <div class="input-group">
                  <input type="number" step="0.01" min="0.01" name="amount" id="amount" class="form-control" value="<?= number_format($remain,2,'.','') ?>" required>
                  <button class="btn btn-outline-secondary" type="button" onclick="setFull()">Full</button>
                </div>
                <div class="mini-help">Click <b>Full</b> to pay remaining (after discount).</div>
              </div>
              <div class="col-6">
                <label class="form-label">Discount (optional)</label>
                <input type="number" step="0.01" min="0" name="discount" id="discount" class="form-control" value="0.00">
                <div class="mini-help">This reduces the invoice payable and ledger.</div>
              </div>

              <div class="col-6">
                <label class="form-label">Method</label>
                <select name="method" class="form-select" id="method">
                  <option value="">Select…</option>
                  <option value="Cash" selected>Cash</option>
                  <option value="BKash">BKash</option>
                  <option value="Nagad">Nagad</option>
                  <option value="Bank">Bank</option>
                </select>
              </div>
              <div class="col-6">
                <label class="form-label">Transaction ID</label>
                <input type="text" name="txn_id" class="form-control" placeholder="Optional">
              </div>
              <div class="col-6">
                <label class="form-label">Paid At</label>
                <input type="datetime-local" name="paid_at" class="form-control" value="<?= h(date('Y-m-d\TH:i')) ?>">
              </div>
              <div class="col-6">
                <label class="form-label">Remarks</label>
                <input type="text" name="remarks" class="form-control" placeholder="Optional">
              </div>
            </div>

            <div class="mt-3 d-flex gap-2">
              <button class="btn btn-success"><i class="bi bi-check2-circle"></i> Save Payment</button>
              <a class="btn btn-outline-secondary" href="<?= h($_GET['return'] ?? '/public/billing.php') ?>">Cancel</a>
            </div>
          </form>

          <script>
          const elTotal   = <?= json_encode((float)$inv['total']) ?>;
          const elPaidNow = <?= json_encode((float)$inv['paid_amount']) ?>;
          const elDiscCur = <?= json_encode((float)$disc) ?>;
          const netEl     = document.getElementById('net_payable');
          const amtEl     = document.getElementById('amount');
          const dEl       = document.getElementById('discount');

          function recalc(){
            const addDisc = parseFloat(dEl.value||'0');
            const net = Math.max(0, +(elTotal - (elDiscCur + (isNaN(addDisc)?0:addDisc))).toFixed(2));
            if (netEl) netEl.value = net.toFixed(2);
            const remain = Math.max(0, +(net - elPaidNow).toFixed(2));
            if (document.activeElement !== amtEl) amtEl.value = remain.toFixed(2);
          }
          function setFull(){
            const addDisc = parseFloat(dEl.value||'0');
            const net = Math.max(0, +(elTotal - (elDiscCur + (isNaN(addDisc)?0:addDisc))).toFixed(2));
            const remain = Math.max(0, +(net - elPaidNow).toFixed(2));
            amtEl.value = remain.toFixed(2);
            amtEl.focus();
          }
          dEl?.addEventListener('input', recalc);
          </script>
        </div>
      </div>
    </div>
  </div>
  <?php
  include __DIR__ . '/../partials/partials_footer.php';
  exit;
}

/* ========================== PROCESS (POST) ========================== */

// Merge JSON (optional)
$raw = file_get_contents('php://input');
if ($raw && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
  $json = json_decode($raw, true);
  if (is_array($json)) foreach($json as $k=>$v){ if(!isset($_POST[$k])) $_POST[$k]=$v; }
}

/* ---------- Inputs ---------- */
$invoice_id = (int)($_POST['invoice_id'] ?? 0);
$amount     = (float)($_POST['amount'] ?? 0);
$discount   = (float)($_POST['discount'] ?? 0);
$method     = substr(trim($_POST['method'] ?? ($_POST['payment_method'] ?? 'Cash')), 0, 50);
if ($method==='') $method = 'Cash';
$txn_id     = substr(trim($_POST['txn_id'] ?? ($_POST['reference'] ?? '')), 0, 100);
$remarks    = substr(trim($_POST['remarks'] ?? ''), 0, 255);
$paid_at    = parse_paid_at($_POST['paid_at'] ?? '');
$return_url = trim($_POST['return'] ?? ($_GET['return'] ?? ''));
$is_popup   = (int)($_POST['popup'] ?? ($_GET['popup'] ?? 0)) === 1;

// ajax?
$is_ajax    = (($_POST['ajax'] ?? '') === '1');

$purpose    = strtolower(trim($_POST['purpose'] ?? $_GET['purpose'] ?? ''));
$client_id  = (int)($_POST['client_id'] ?? $_GET['client_id'] ?? 0);

$account_id = (int)($_POST['account_id'] ?? 0);
$received_by = (int)($_SESSION['user']['id'] ?? ($_SESSION['user_id'] ?? 0));
$received_ip = ip_remote();

/* -------------------- NEW: ADVANCE PAYMENT (no invoice) -------------------- */
if ($purpose === 'advance') {
  if ($client_id <= 0 || $amount <= 0) {
    jexit(['ok'=>false,'error'=>'invalid_input','message'=>'client_id and positive amount required for advance']);
  }
  if (!tbl_exists($pdo,'payments')) {
    jexit(['ok'=>false,'error'=>'schema_missing','message'=>'payments table not found']);
  }
  if (!col_exists($pdo,'payments','account_id')) {
    jexit(['ok'=>false,'error'=>'schema_missing','message'=>'payments.account_id column is required']);
  }
  ensure_ledger_col($pdo);

  // verify client
  $cc=$pdo->prepare("SELECT id FROM clients WHERE id=? LIMIT 1");
  $cc->execute([$client_id]);
  if(!(int)$cc->fetchColumn()){
    jexit(['ok'=>false,'error'=>'client_not_found']);
  }

  // resolve account
  if ($account_id <= 0 && $received_by > 0 && tbl_exists($pdo,'accounts') && col_exists($pdo,'accounts','user_id')) {
    $m=$pdo->prepare("SELECT id FROM accounts WHERE user_id=? LIMIT 1");
    $m->execute([$received_by]); $account_id=(int)($m->fetchColumn() ?: 0);
  }
  if ($account_id <= 0) {
    jexit(['ok'=>false,'error'=>'account_required','message'=>'Please provide account_id or map current user to accounts.user_id']);
  }
  $chk=$pdo->prepare("SELECT id FROM accounts WHERE id=? LIMIT 1");
  $chk->execute([$account_id]);
  if(!(int)$chk->fetchColumn()){
    jexit(['ok'=>false,'error'=>'account_not_found']);
  }

  try{ $pdo->beginTransaction(); }catch(Throwable $e){}

  // build insert (invoice_id/bill_id → NULL)
  $invCol = col_exists($pdo,'payments','invoice_id') ? 'invoice_id' : (col_exists($pdo,'payments','bill_id') ? 'bill_id' : '');
  $cols = ['client_id','amount'];
  $vals = [':client_id',':amount'];
  $params = [':client_id'=>$client_id, ':amount'=>round($amount,2)];

  if ($invCol!==''){ $cols[]=$invCol; $vals[]=':invoice_id'; $params[':invoice_id']=null; }
  if (col_exists($pdo,'payments','method'))     { $cols[]='method';     $vals[]=':method';     $params[':method']=$method; }
  if (col_exists($pdo,'payments','txn_id'))     { $cols[]='txn_id';     $vals[]=':txn_id';     $params[':txn_id']=($txn_id!==''?$txn_id:null); }
  if (col_exists($pdo,'payments','paid_at'))    { $cols[]='paid_at';    $vals[]=':paid_at';    $params[':paid_at']=$paid_at; }
  if (col_exists($pdo,'payments','remarks'))    { $cols[]='remarks';    $vals[]=':remarks';    $params[':remarks']=($remarks!==''?$remarks:null); }
  if (col_exists($pdo,'payments','received_by')){ $cols[]='received_by';$vals[]=':received_by';$params[':received_by']=($received_by>0?$received_by:null); }
  if (col_exists($pdo,'payments','account_id')) { $cols[]='account_id'; $vals[]=':account_id'; $params[':account_id']=$account_id; }
  if (col_exists($pdo,'payments','received_ip')){ $cols[]='received_ip';$vals[]=':received_ip';$params[':received_ip']=$received_ip; }
  if (col_exists($pdo,'payments','created_at')) { $cols[]='created_at'; $vals[]='NOW()'; }

  $sql = "INSERT INTO payments (".implode(',',$cols).") VALUES (".implode(',',$vals).")";
  $pdo->prepare($sql)->execute($params);
  $payment_id = (int)$pdo->lastInsertId();

  // client ledger ↓ amount (discount নেই এই পথে)
  $pdo->prepare("UPDATE clients SET ledger_balance=ROUND(ledger_balance-?,2)".(col_exists($pdo,'clients','updated_at')?', updated_at=NOW()':'')." WHERE id=?")
      ->execute([round($amount,2), $client_id]);

  // accounts.balance ↑ amount
  if (tbl_exists($pdo,'accounts') && col_exists($pdo,'accounts','balance')) {
    $pdo->prepare("UPDATE accounts SET balance=ROUND(balance+?,2) WHERE id=?")->execute([round($amount,2), $account_id]);
  }

  // latest ledger
  $st=$pdo->prepare("SELECT ledger_balance FROM clients WHERE id=?");
  $st->execute([$client_id]);
  $ledger_balance=(float)$st->fetchColumn();

  try{ if($pdo->inTransaction()) $pdo->commit(); }catch(Throwable $e){}

  // Telegram quick notify (invoice_id = 0)
  try {
    $receiver_name = resolve_user_name($pdo, (int)$received_by);
    $payload = [
      'amount'            => number_format((float)$amount,2,'.',''),
      'invoice_id'        => 0,
      'method'            => $method,
      'txn_id'            => $txn_id,
      'paid_at'           => $paid_at,
      'received_by'       => (int)$received_by,
      'received_by_name'  => $receiver_name,
      'note'              => 'Advance payment',
    ];
    if (function_exists('tg_send_payment_now')) {
      tg_send_payment_now($pdo, (int)$client_id, (float)$amount, 0, (int)$payment_id, $payload);
    } elseif (function_exists('tg_send_now')) {
      $res = tg_send_now($pdo, (int)$client_id, 'payment_confirm', $payload, "pay-adv-{$payment_id}");
      if (!($res['ok'] ?? false) && function_exists('tg_queue')) {
        tg_queue($pdo, (int)$client_id, 'payment_confirm', $payload, "pay-adv-{$payment_id}");
      }
    } elseif (function_exists('tg_queue')) {
      tg_queue($pdo, (int)$client_id, 'payment_confirm', $payload, "pay-adv-{$payment_id}");
    }
  } catch(Throwable $e){ error_log("[TG_ADV] ".$e->getMessage()); }

  // response
  if ($is_ajax) {
    jexit(['ok'=>true, 'id'=>$payment_id, 'invoice_id'=>0, 'client_id'=>$client_id, 'ledger'=>$ledger_balance, 'status'=>'n/a', 'remaining'=>0.0, 'net_total'=>0.0]);
  }
  $receipt = '/public/receipt_payment.php?payment_id='.(int)$payment_id.
             '&return='.urlencode($return_url ?: '/public/billing.php');
  header("Location: ".$receipt);
  exit;
}
/* ------------------ /NEW ADVANCE PAYMENT branch ------------------ */

/* ------------------ Old invoice-based POST flow ------------------ */
$account_id = (int)($account_id ?? 0);
if($invoice_id<=0 || $amount<=0){
  jexit(['ok'=>false,'error'=>'invalid_input','message'=>'invoice_id and positive amount required']);
}

// Schema guards
if (!tbl_exists($pdo,'payments')) {
  jexit(['ok'=>false,'error'=>'schema_missing','message'=>'payments table not found']);
}
if (!col_exists($pdo,'payments','account_id')) {
  jexit(['ok'=>false,'error'=>'schema_missing','message'=>'payments.account_id column is required']);
}
ensure_ledger_col($pdo);

try{ $pdo->beginTransaction(); }catch(Throwable $e){}

/* ---------- Invoice verify ---------- */
$has_disc_col = col_exists($pdo,'invoices','discount');
$st=$pdo->prepare("SELECT id, client_id, total, method".($has_disc_col?", COALESCE(discount,0) AS discount":"")." FROM invoices WHERE id=? LIMIT 1");
$st->execute([$invoice_id]);
$inv=$st->fetch(PDO::FETCH_ASSOC);
if(!$inv){ rollback_if($pdo); jexit(['ok'=>false,'error'=>'invoice_not_found']); }
$client_id = (int)($inv['client_id'] ?? 0);
if ($client_id <= 0) { rollback_if($pdo); jexit(['ok'=>false,'error'=>'invoice_client_missing']); }

/* ---------- Resolve/verify account_id ---------- */
if ($account_id <= 0 && $received_by > 0 && tbl_exists($pdo,'accounts') && col_exists($pdo,'accounts','user_id')) {
  $m=$pdo->prepare("SELECT id FROM accounts WHERE user_id=? LIMIT 1");
  $m->execute([$received_by]);
  $account_id = (int)($m->fetchColumn() ?: 0);
}
if ($account_id <= 0) {
  rollback_if($pdo);
  jexit(['ok'=>false,'error'=>'account_required','message'=>'Please provide account_id or map current user to accounts.user_id']);
}
$chk=$pdo->prepare("SELECT id FROM accounts WHERE id=? LIMIT 1");
$chk->execute([$account_id]);
if(!(int)$chk->fetchColumn()){
  rollback_if($pdo);
  jexit(['ok'=>false,'error'=>'account_not_found']);
}

/* ---------- Apply new discount (if any) ---------- */
$applied_disc = 0.00;
if ($discount > 0 && $has_disc_col) {
  $applied_disc = round($discount,2);
  $pdo->prepare("UPDATE invoices SET discount=ROUND(COALESCE(discount,0)+?,2)".(col_exists($pdo,'invoices','updated_at')?", updated_at=NOW()":"")." WHERE id=?")
      ->execute([$applied_disc, $invoice_id]);
}

/* ---------- INSERT into payments ---------- */
$invCol = col_exists($pdo,'payments','invoice_id') ? 'invoice_id' : (col_exists($pdo,'payments','bill_id') ? 'bill_id' : '');
if ($invCol===''){ rollback_if($pdo); jexit(['ok'=>false,'error'=>'schema_missing','message'=>'payments needs invoice_id or bill_id']); }

$cols = [$invCol,'client_id','amount'];
$vals = [':invoice_id',':client_id',':amount'];
$params = [ ':invoice_id'=>$invoice_id, ':client_id'=>$client_id, ':amount'=>round($amount,2) ];

if (col_exists($pdo,'payments','method'))     { $cols[]='method';     $vals[]=':method';     $params[':method']=$method; }
if (col_exists($pdo,'payments','txn_id'))     { $cols[]='txn_id';     $vals[]=':txn_id';     $params[':txn_id']=($txn_id!==''?$txn_id:null); }
if (col_exists($pdo,'payments','paid_at'))    { $cols[]='paid_at';    $vals[]=':paid_at';    $params[':paid_at']=$paid_at; }
if (col_exists($pdo,'payments','remarks'))    { $cols[]='remarks';    $vals[]=':remarks';    $params[':remarks']=($remarks!==''?$remarks:null); }
if (col_exists($pdo,'payments','received_by')){ $cols[]='received_by';$vals[]=':received_by';$params[':received_by']=($received_by>0?$received_by:null); }
if (col_exists($pdo,'payments','account_id')) { $cols[]='account_id'; $vals[]=':account_id'; $params[':account_id']=$account_id; }
if (col_exists($pdo,'payments','received_ip')){ $cols[]='received_ip';$vals[]=':received_ip';$params[':received_ip']=$received_ip; }
if (col_exists($pdo,'payments','created_at')) { $cols[]='created_at'; $vals[]='NOW()'; }

$sql = "INSERT INTO payments (".implode(',',$cols).") VALUES (".implode(',',$vals).")";
$pdo->prepare($sql)->execute($params);
$payment_id = (int)$pdo->lastInsertId();

/* ---------- Recompute invoice (discount-aware) ---------- */
[$paid_sum, $new_status, $remaining, $net_total] = invoice_recompute($pdo, $invoice_id);

// invoice method/paid_at fallback-safe
if (col_exists($pdo,'invoices','paid_at') || col_exists($pdo,'invoices','method')) {
  $f=[]; $p=[];
  if (col_exists($pdo,'invoices','paid_at')) { $f[]='paid_at=?'; $p[]=$paid_at; }
  if (col_exists($pdo,'invoices','method'))  { $f[]='method=?';  $p[]=($method?:$inv['method']); }
  if ($f) {
    $p[]=$invoice_id;
    $pdo->prepare("UPDATE invoices SET ".implode(',',$f)." WHERE id=?")->execute($p);
  }
}

/* ---------- Client ledger decrease (amount + discount) ---------- */
$pdo->prepare("UPDATE clients SET ledger_balance=ROUND(ledger_balance-?,2)".(col_exists($pdo,'clients','updated_at')?', updated_at=NOW()':'')." WHERE id=?")
    ->execute([round($amount + $applied_disc,2), $client_id]);

// Optional client fields
if (col_exists($pdo,'clients','last_payment_date')) {
  $pdo->prepare("UPDATE clients SET last_payment_date=? WHERE id=?")->execute([$paid_at, $client_id]);
}
if (col_exists($pdo,'clients','payment_status')) {
  $stb=$pdo->prepare("SELECT ledger_balance FROM clients WHERE id=?");
  $stb->execute([$client_id]);
  $ledger_now=(float)$stb->fetchColumn();
  $ps = ($ledger_now<=0.0 ? 'clear' : 'due');
  $pdo->prepare("UPDATE clients SET payment_status=? WHERE id=?")->execute([$ps, $client_id]);
}

/* ---------- Credit accounts.balance by amount (NOT discount) ---------- */
if (tbl_exists($pdo,'accounts') && col_exists($pdo,'accounts','balance')) {
  $pdo->prepare("UPDATE accounts SET balance=ROUND(balance+?,2) WHERE id=?")->execute([round($amount,2), $account_id]);
}

/* ---------- Read fresh ledger ---------- */
$stb=$pdo->prepare("SELECT ledger_balance FROM clients WHERE id=?");
$stb->execute([$client_id]);
$ledger_balance=(float)$stb->fetchColumn();

try{ if($pdo->inTransaction()) $pdo->commit(); }catch(Throwable $e){}

/* ---------------------------------------------------------------------------
   Telegram: Instant send; on failure → keep as PENDING (queued)
---------------------------------------------------------------------------- */
try {
  $portal_link = '';
  if (function_exists('tg_cfg')) {
    $cfg = tg_cfg();
    $base = rtrim((string)($cfg['app_base_url'] ?? ''), '/');
    if ($base !== '') $portal_link = $base."/public/portal.php?client_id=".(int)$client_id;
  }
  $receiver_name = resolve_user_name($pdo, (int)$received_by);

  $payload = [
    'amount'            => number_format((float)$amount, 2, '.', ''),
    'invoice_id'        => (int)$invoice_id,
    'portal_link'       => $portal_link,
    'method'            => $method,
    'txn_id'            => $txn_id,
    'paid_at'           => $paid_at,
    'received_by'       => (int)$received_by,
    'received_by_name'  => $receiver_name,
  ];

  if (function_exists('tg_send_payment_now')) {
    tg_send_payment_now($pdo, (int)$client_id, (float)$amount, (int)$invoice_id, (int)$payment_id, $payload);
  } elseif (function_exists('tg_send_now')) {
    $res = tg_send_now($pdo, (int)$client_id, 'payment_confirm', $payload, "pay-confirm-{$payment_id}");
    if (!($res['ok'] ?? false) && function_exists('tg_queue')) {
      tg_queue($pdo, (int)$client_id, 'payment_confirm', $payload, "pay-confirm-{$payment_id}");
    }
  } elseif (function_exists('tg_queue')) {
    tg_queue($pdo, (int)$client_id, 'payment_confirm', $payload, "pay-confirm-{$payment_id}");
  }
} catch (Throwable $e) {
  error_log("[TG_PAY] exception: ".$e->getMessage());
}

/* ---------- Optional auto control (silent) ---------- */
try{
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $base   = rtrim("$scheme://$host", '/');
  if (is_readable(__DIR__ . '/../api/auto_control_client.php')) {
    $ch = curl_init();
    curl_setopt_array($ch, [
      CURLOPT_URL            => $base.'/api/auto_control_client.php',
      CURLOPT_POST           => true,
      CURLOPT_POSTFIELDS     => ['client_id' => $client_id],
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT        => 5,
    ]);
    curl_exec($ch); curl_close($ch);
  }
}catch(Throwable $e){ /* ignore */ }

/* ---------- Success response ---------- */
if ($is_ajax) {
  jexit([
    'ok'          => true,
    'id'          => $payment_id,
    'invoice_id'  => $invoice_id,
    'client_id'   => $client_id,
    'ledger'      => $ledger_balance,
    'status'      => $new_status,
    'remaining'   => $remaining,
    'net_total'   => $net_total,
  ]);
}

/* ---------- Redirect to Receipt ---------- */
$receipt = '/public/receipt_payment.php?payment_id='.(int)$payment_id.
           '&return='.urlencode($return_url ?: '/public/billing.php');
header("Location: ".$receipt);
exit;
