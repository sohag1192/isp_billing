<?php
// /public/wallet_statement.php
// Statement per User Wallet: Opening, period transactions, Closing + Print
// UI: English; Comments: বাংলা

declare(strict_types=1);
require_once __DIR__.'/../app/require_login.php';
require_once __DIR__.'/../app/db.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function dbh(){ return db(); }
function hascol($t,$c){
  static $m=[]; if(!isset($m[$t])){
    $m[$t]=array_flip(dbh()->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN)?:[]);
  }
  return isset($m[$t][$c]);
}
function users_label_map(): array{
  $cols = dbh()->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN) ?: [];
  $pick=null; foreach(['name','full_name','username','email'] as $c){ if(in_array($c,$cols,true)){ $pick=$c; break; } }
  $map=[];
  if($pick){
    foreach (dbh()->query("SELECT id,$pick AS u FROM users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r){
      $map[(int)$r['id']] = ($r['u']!==null && $r['u']!=='') ? (string)$r['u'] : ('User#'.(int)$r['id']);
    }
  } else {
    foreach (dbh()->query("SELECT id FROM users ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) as $id){
      $map[(int)$id] = 'User#'.(int)$id;
    }
  }
  return $map;
}

/* -------- Inputs -------- */
$users  = users_label_map();
$acc_by_user = dbh()->query("SELECT user_id,id FROM accounts WHERE user_id IS NOT NULL")->fetchAll(PDO::FETCH_KEY_PAIR);

$user_id   = (int)($_GET['user_id'] ?? 0);
$account_id= (int)($_GET['account_id'] ?? 0);
if(!$account_id && $user_id && isset($acc_by_user[$user_id])) $account_id = (int)$acc_by_user[$user_id];
if(!$user_id && $account_id){
  $st = dbh()->prepare("SELECT user_id FROM accounts WHERE id=?"); $st->execute([$account_id]);
  $user_id = (int)$st->fetchColumn();
}

$start = trim($_GET['start'] ?? date('Y-m-01'));
$end   = trim($_GET['end']   ?? date('Y-m-d'));

$start_dt = preg_match('/^\d{4}-\d{2}-\d{2}$/',$start) ? "$start 00:00:00" : date('Y-m-01 00:00:00');
$end_dt   = preg_match('/^\d{4}-\d{2}-\d{2}$/',$end)   ? "$end 23:59:59"   : date('Y-m-d 23:59:59');

/* -------- Column presence -------- */
$has_paid_at = hascol('payments','paid_at');
$date_col    = $has_paid_at ? 'paid_at' : 'created_at';

/* -------- Guard: need wallet selection -------- */
if($account_id<=0){
  include __DIR__.'/../partials/partials_header.php';
  ?>
  <div class="container my-4">
    <h4 class="mb-3">Wallet Statement</h4>
    <div class="card shadow-sm">
      <div class="card-body">
        <form class="row g-2">
          <div class="col-md-4">
            <label class="form-label">User</label>
            <select name="user_id" class="form-select" required>
              <option value="">Select user…</option>
              <?php foreach($users as $uid=>$nm): ?>
                <option value="<?=$uid?>"><?=$uid?> — <?=h($nm)?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">From</label>
            <input type="date" name="start" value="<?=h($start)?>" class="form-control" required>
          </div>
          <div class="col-md-3">
            <label class="form-label">To</label>
            <input type="date" name="end" value="<?=h($end)?>" class="form-control" required>
          </div>
          <div class="col-md-2 d-flex align-items-end">
            <button class="btn btn-primary w-100"><i class="bi bi-file-text"></i> View</button>
          </div>
        </form>
        <div class="text-muted small mt-2">Tip: statement reflects <b>approved</b> settlements only.</div>
      </div>
    </div>
  </div>
  <?php
  include __DIR__.'/../partials/partials_footer.php'; exit;
}

/* -------- Opening balance (t < start) -------- */
// বাংলা: ওপেনিং = (payments in) − (settlement out approved) + (settlement in approved), সবই start এর আগের
$sum_pay_before = (float)dbh()->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE account_id=? AND $date_col < ?")
                              ->execute([$account_id,$start_dt]) ? dbh()->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE account_id=$account_id AND $date_col < '$start_dt'")->fetchColumn() : 0;

$st = dbh()->prepare("SELECT COALESCE(SUM(amount),0) FROM wallet_transfers WHERE from_account_id=? AND status='approved' AND created_at < ?");
$st->execute([$account_id,$start_dt]); $sum_out_before = (float)$st->fetchColumn();

$st = dbh()->prepare("SELECT COALESCE(SUM(amount),0) FROM wallet_transfers WHERE to_account_id=? AND status='approved' AND created_at < ?");
$st->execute([$account_id,$start_dt]); $sum_in_before  = (float)$st->fetchColumn();

$opening = $sum_pay_before - $sum_out_before + $sum_in_before;

/* -------- Period transactions (start..end) -------- */
$rows=[];

/* Payments into this wallet */
$st = dbh()->prepare("
  SELECT p.id, p.amount, p.method, p.txn_id, p.notes, p.$date_col AS dt,
         p.invoice_id, c.pppoe_id, c.name AS client_name
  FROM payments p
  LEFT JOIN clients c ON c.id=p.client_id
  WHERE p.account_id=? AND p.$date_col BETWEEN ? AND ?
  ORDER BY p.$date_col ASC, p.id ASC
");
$st->execute([$account_id,$start_dt,$end_dt]);
foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){
  $rows[] = [
    'dt' => $r['dt'],
    'type' => 'Payment',
    'sign' => +1,
    'amount' => (float)$r['amount'],
    'method' => $r['method'] ?: '',
    'ref'    => $r['txn_id'] ?: '',
    'info'   => $r['invoice_id'] ? ("Invoice #".$r['invoice_id']." • ".(($r['pppoe_id']??'').' — '.($r['client_name']??''))) : 'Manual payment',
    'status' => 'posted',
    'affects'=> true,
  ];
}

/* Wallet transfers OUT */
$st = dbh()->prepare("
  SELECT id, amount, method, ref_no, notes, status, created_at AS dt
  FROM wallet_transfers
  WHERE from_account_id=? AND created_at BETWEEN ? AND ?
  ORDER BY created_at ASC, id ASC
");
$st->execute([$account_id,$start_dt,$end_dt]);
foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){
  $rows[] = [
    'dt' => $r['dt'],
    'type' => 'Transfer Out',
    'sign' => -1,
    'amount' => (float)$r['amount'],
    'method' => $r['method'] ?: '',
    'ref'    => $r['ref_no'] ?: '',
    'info'   => $r['notes'] ?: '',
    'status' => $r['status'],
    'affects'=> strtolower($r['status'])==='approved',
  ];
}

/* Wallet transfers IN */
$st = dbh()->prepare("
  SELECT id, amount, method, ref_no, notes, status, created_at AS dt
  FROM wallet_transfers
  WHERE to_account_id=? AND created_at BETWEEN ? AND ?
  ORDER BY created_at ASC, id ASC
");
$st->execute([$account_id,$start_dt,$end_dt]);
foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){
  $rows[] = [
    'dt' => $r['dt'],
    'type' => 'Transfer In',
    'sign' => +1,
    'amount' => (float)$r['amount'],
    'method' => $r['method'] ?: '',
    'ref'    => $r['ref_no'] ?: '',
    'info'   => $r['notes'] ?: '',
    'status' => $r['status'],
    'affects'=> strtolower($r['status'])==='approved',
  ];
}

/* Sort all by datetime, then type */
usort($rows, function($a,$b){
  $c = strcmp($a['dt'],$b['dt']); if($c!==0) return $c;
  return strcmp($a['type'],$b['type']);
});

/* Running balance + period totals */
$balance = $opening;
$in_pay=0.0; $in_set=0.0; $out_set=0.0;
foreach($rows as $k=>$r){
  if ($r['affects']) {
    $delta = $r['sign'] * $r['amount'];
    $balance += $delta;
    if($r['type']==='Payment' || $r['type']==='Transfer In') { if($r['sign']>0) $in_pay += $r['amount']; }
    if($r['type']==='Transfer In')  $in_set  += $r['amount'];
    if($r['type']==='Transfer Out') $out_set += $r['amount'];
  }
  $rows[$k]['balance_after'] = $balance;
}

/* Wallet/user label */
$wallet_user_label = isset($users[$user_id]) ? ($user_id.' — '.$users[$user_id]) : ('User#'.$user_id);
include __DIR__.'/../partials/partials_header.php';
?>
<style>
@media print{
  .no-print{ display:none !important; }
  body{ background:#fff !important; }
  .card{ border:0 !important; box-shadow:none !important; }
  .print-title{ margin-top:0 !important; }
}
.badge-pending{ background:#ffe08a; color:#5c4d00; }
.badge-rejected{ background:#ffc5c5; color:#842029; }
.row-dim{ opacity:.7; }
</style>

<div class="container-fluid py-3">
  <div class="d-flex align-items-center gap-2 mb-3">
    <h4 class="mb-0 print-title">Wallet Statement</h4>
    <span class="ms-auto no-print">
      <a href="/public/wallets.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-wallet2"></i> Wallets</a>
      <button onclick="window.print()" class="btn btn-primary btn-sm"><i class="bi bi-printer"></i> Print / PDF</button>
    </span>
  </div>

  <form class="card shadow-sm mb-3 no-print">
    <div class="card-body">
      <div class="row g-2">
        <div class="col-md-4">
          <label class="form-label">User</label>
          <select name="user_id" class="form-select" onchange="this.form.submit()">
            <?php foreach($users as $uid=>$nm): $has = isset($acc_by_user[$uid]); ?>
              <option value="<?=$uid?>" <?=$uid===$user_id?'selected':''?> <?=$has?'':'disabled'?>>
                <?=$uid?> — <?=h($nm)?> <?=$has?'':'(no wallet)';?>
              </option>
            <?php endforeach; ?>
          </select>
          <input type="hidden" name="account_id" value="<?=$account_id?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">From</label>
          <input type="date" name="start" value="<?=h($start)?>" class="form-control" onchange="this.form.submit()">
        </div>
        <div class="col-md-3">
          <label class="form-label">To</label>
          <input type="date" name="end" value="<?=h($end)?>" class="form-control" onchange="this.form.submit()">
        </div>
        <div class="col-md-2 d-flex align-items-end">
          <button class="btn btn-outline-secondary w-100"><i class="bi bi-arrow-repeat"></i> Refresh</button>
        </div>
      </div>
    </div>
  </form>

  <!-- Header summary -->
  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-4">
          <div class="text-muted small">Wallet (User)</div>
          <div class="fw-semibold"><?=$wallet_user_label?></div>
          <div class="text-muted small">Account ID: <?=$account_id?></div>
        </div>
        <div class="col-md-8">
          <div class="row text-end">
            <div class="col-6 col-md-3">
              <div class="text-muted small">Opening</div>
              <div class="h6 mb-0">৳ <?=number_format($opening,2)?></div>
            </div>
            <div class="col-6 col-md-3">
              <div class="text-muted small">Payments In</div>
              <div class="h6 mb-0">৳ <?=number_format($in_pay,2)?></div>
            </div>
            <div class="col-6 col-md-3">
              <div class="text-muted small">Settlements In / Out</div>
              <div class="h6 mb-0">৳ <?=number_format($in_set,2)?> / −<?=number_format($out_set,2)?></div>
            </div>
            <div class="col-6 col-md-3">
              <div class="text-muted small">Closing</div>
              <div class="h6 mb-0">৳ <?=number_format($balance,2)?></div>
            </div>
          </div>
          <div class="text-muted small text-end mt-1">Period: <?=h($start)?> → <?=h($end)?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Transactions table -->
  <div class="card shadow-sm">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead class="table-light">
            <tr>
              <th style="width:160px">Date</th>
              <th>Type</th>
              <th>Details</th>
              <th>Method</th>
              <th>Ref</th>
              <th class="text-end">Amount</th>
              <th class="text-end">Balance</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
          <tr class="table-secondary">
            <td colspan="6"><em>Opening Balance</em></td>
            <td class="text-end fw-semibold">৳ <?=number_format($opening,2)?></td>
            <td></td>
          </tr>
          <?php if(!$rows): ?>
            <tr><td colspan="8" class="text-center text-muted">No transactions in this period.</td></tr>
          <?php else: foreach($rows as $r):
            $amt = $r['sign'] * $r['amount'];
            $is_pending = strtolower($r['status'])==='pending';
          ?>
            <tr class="<?=$is_pending?'row-dim':''?>">
              <td><?=h($r['dt'])?></td>
              <td><?=h($r['type'])?></td>
              <td><?=h($r['info'])?></td>
              <td><?=h($r['method'])?></td>
              <td><?=h($r['ref'])?></td>
              <td class="text-end <?= $amt<0?'text-danger':'text-success' ?>"> <?= $amt<0?'-':'+' ?>৳ <?=number_format(abs($amt),2)?></td>
              <td class="text-end fw-semibold">৳ <?=number_format($r['balance_after'],2)?></td>
              <td>
                <?php if($r['status']==='posted'): ?>
                  <span class="badge bg-secondary">posted</span>
                <?php elseif(strtolower($r['status'])==='approved'): ?>
                  <span class="badge bg-success">approved</span>
                <?php elseif(strtolower($r['status'])==='pending'): ?>
                  <span class="badge badge-pending">pending</span>
                <?php else: ?>
                  <span class="badge badge-rejected"><?=h($r['status'])?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <div class="text-end small text-muted">
        Note: Pending transfers are shown but don’t affect balances until approved.
      </div>
    </div>
  </div>
</div>

<?php include __DIR__.'/../partials/partials_footer.php'; ?>
