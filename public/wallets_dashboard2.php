<?php
// /public/wallets_dashboard.php
// UI: English; Comments: বাংলা — “Start Here” ড্যাশবোর্ড

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');

require_once __DIR__ . '/../app/db.php';
// (অপশনাল) ACL থাকলে ইনক্লুড করো; না থাকলে নীরবভাবে এগোবে
$acl_file = __DIR__ . '/../app/acl.php';
if (is_file($acl_file)) require_once $acl_file;

/* ---------------- session & CSRF (output-এর আগে) ---------------- */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = (string)$_SESSION['csrf'];

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------------- helpers ---------------- */
// বাংলা: সেফ হেল্পার
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function tbl_exists(PDO $pdo, string $t): bool {
  try{ $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); return true; }catch(Throwable $e){ return false; }
}
function col_exists(PDO $pdo, string $t, string $c): bool {
  try{ $cols=$pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN)?:[]; return in_array($c,$cols,true); }
  catch(Throwable $e){ return false; }
}
function user_label(PDO $pdo, int $uid): string {
  try{
    $cols=$pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN)?:[];
    $pick='name'; foreach(['name','full_name','username','email'] as $c){ if(in_array($c,$cols,true)){ $pick=$c; break; } }
    $st=$pdo->prepare("SELECT `$pick` FROM users WHERE id=?"); $st->execute([$uid]);
    $x=$st->fetchColumn();
    return ($x!==false && $x!=='')?(string)$x:('User#'.$uid);
  }catch(Throwable $e){ return 'User#'.$uid; }
}
// বাংলা: ব্যালান্স ব্যাজ (পজিটিভ=success, নেগেটিভ=danger, জিরো=secondary)
function balance_badge(string $prefix, float $b): string {
  $cls = abs($b) < 0.0000001 ? 'bg-secondary' : ($b >= 0 ? 'bg-success' : 'bg-danger');
  return '<span class="badge '.$cls.'">'.$prefix.number_format($b,2).'</span>';
}

/* -------- smart wallet balance -------- */
/*
বাংলা: স্বাভাবিকভাবে payments.account_id অনুযায়ী যোগ হবে।
যদি account_id ফাঁকা থাকে কিন্তু payments.received_by সেট থাকে এবং accounts.user_id কলাম থাকে,
তাহলে সেই received_by ইউজারের ওয়ালেটে (accounts.user_id) ক্রেডিট ধরা হবে (fallback) —
যাতে এমপ্লয়ি রিসিভ করা পেমেন্টগুলোও ওয়ালেটে reflect করে।
*/
function wallet_balance(PDO $pdo, int $account_id): float {
  $payments=0.0; $fallback=0.0; $out=0.0; $in=0.0;

  $hasP = tbl_exists($pdo,'payments');
  $hasA = tbl_exists($pdo,'accounts');
  $pHasAcc = $hasP && col_exists($pdo,'payments','account_id');
  $pHasAmt = $hasP && col_exists($pdo,'payments','amount');
  $pHasRecv= $hasP && col_exists($pdo,'payments','received_by');
  $aHasUser= $hasA && col_exists($pdo,'accounts','user_id');

  // 1) base: account_id == current
  if ($pHasAcc && $pHasAmt) {
    $q=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE account_id=?");
    $q->execute([$account_id]); $payments=(float)$q->fetchColumn();
  }

  // 2) fallback: account_id IS NULL && received_by matches this wallet's user_id
  if ($pHasAmt && $pHasRecv && $aHasUser) {
    $st = $pdo->prepare("SELECT COALESCE(user_id,0) FROM accounts WHERE id=? LIMIT 1");
    $st->execute([$account_id]);
    $uid = (int)($st->fetchColumn() ?? 0);
    if ($uid > 0) {
      if ($pHasAcc) {
        $fb=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE account_id IS NULL AND received_by=?");
      } else {
        // যদি account_id কলামই না থাকে—তাহলে শুধু received_by দিয়েই ধরব
        $fb=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE received_by=?");
      }
      $fb->execute([$uid]); $fallback=(float)$fb->fetchColumn();
    }
  }

  // 3) transfers effect
  if (tbl_exists($pdo,'wallet_transfers')) {
    if (col_exists($pdo,'wallet_transfers','from_account_id') && col_exists($pdo,'wallet_transfers','status') && col_exists($pdo,'wallet_transfers','amount')) {
      $o=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM wallet_transfers WHERE from_account_id=? AND status='approved'");
      $o->execute([$account_id]); $out=(float)$o->fetchColumn();
    }
    if (col_exists($pdo,'wallet_transfers','to_account_id') && col_exists($pdo,'wallet_transfers','status') && col_exists($pdo,'wallet_transfers','amount')) {
      $i=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM wallet_transfers WHERE to_account_id=? AND status='approved'");
      $i->execute([$account_id]); $in=(float)$i->fetchColumn();
    }
  }

  return ($payments + $fallback) - $out + $in;
}

/* -------- POST actions -------- */
// 1) Create my wallet
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='create_my_wallet') {
  try{
    if (!hash_equals($CSRF, (string)($_POST['csrf']??''))) throw new Exception('Bad CSRF.');
    if (function_exists('require_perm')) { @require_perm('wallets.create'); } // বাংলা: ACL থাকলে পারমিশন চেক

    $uid = (int)($_SESSION['user']['id'] ?? 0);
    if ($uid<=0) throw new Exception('Not logged in.');

    if (!tbl_exists($pdo,'accounts')) throw new Exception('accounts table missing.');
    if (!col_exists($pdo,'accounts','user_id')) { try{ $pdo->exec("ALTER TABLE accounts ADD COLUMN user_id INT NULL"); }catch(Throwable $e){} }

    $pdo->beginTransaction();
    $q=$pdo->prepare("SELECT id FROM accounts WHERE user_id=? LIMIT 1");
    $q->execute([$uid]); $aid=(int)$q->fetchColumn();

    if ($aid<=0) {
      $fields=['user_id']; $marks=['?']; $vals=[$uid];
      if (col_exists($pdo,'accounts','name'))      { $fields[]='name';      $marks[]='?'; $vals[]='Wallet of '.user_label($pdo,$uid); }
      if (col_exists($pdo,'accounts','type'))      { $fields[]='type';      $marks[]='?'; $vals[]='user'; }
      if (col_exists($pdo,'accounts','is_active')) { $fields[]='is_active'; $marks[]='?'; $vals[]=1; }
      if (col_exists($pdo,'accounts','created_at')){ $fields[]='created_at';$marks[]='?'; $vals[]=date('Y-m-d H:i:s'); }
      $sql="INSERT INTO accounts (".implode(',',$fields).") VALUES (".implode(',',$marks).")";
      $pdo->prepare($sql)->execute($vals);
      $aid=(int)$pdo->lastInsertId();
    }

    $pdo->commit();
    $_SESSION['flash_success'] = 'Wallet ready (Account ID: '.$aid.').';
    header('Location: /public/wallets_dashboard.php'); exit;
  }catch(Throwable $e){
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['flash_error'] = $e->getMessage();
    header('Location: /public/wallets_dashboard.php'); exit;
  }
}

// 2) Backfill: payments.account_id ← accounts.id via received_by→user_id
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='backfill_payment_accounts') {
  try{
    if (!hash_equals($CSRF, (string)($_POST['csrf']??''))) throw new Exception('Bad CSRF.');
    if (function_exists('require_perm')) { @require_perm('wallets.fix'); } // বাংলা: ACL থাকলে পারমিশন চেক

    if (!tbl_exists($pdo,'payments')) throw new Exception('payments table missing.');
    if (!col_exists($pdo,'payments','received_by')) throw new Exception('payments.received_by missing.');
    if (!col_exists($pdo,'payments','amount')) throw new Exception('payments.amount missing.');
    if (!col_exists($pdo,'payments','account_id')) throw new Exception('payments.account_id missing.');
    if (!tbl_exists($pdo,'accounts') || !col_exists($pdo,'accounts','user_id')) throw new Exception('accounts.user_id missing.');

    $pdo->beginTransaction();
    // বাংলা: NULL account_id যেগুলোর received_by আছে—সেগুলো ইউজারের ওয়ালেটে বসাও
    $sql = "UPDATE payments p
            JOIN accounts a ON a.user_id = p.received_by
            SET p.account_id = a.id
            WHERE p.account_id IS NULL";
    $affected = $pdo->exec($sql);
    $pdo->commit();

    $_SESSION['flash_success'] = 'Backfilled account_id for '.(int)$affected.' payment(s).';
  }catch(Throwable $e){
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['flash_error'] = 'Backfill failed: '.$e->getMessage();
  }
  header('Location: /public/wallets_dashboard.php'); exit;
}

/* ---------------- HTML শুরু ---------------- */
require_once __DIR__ . '/../partials/partials_header.php';

/* ---------------- schema status ---------------- */
$hasAccounts   = tbl_exists($pdo,'accounts');
$acc_user_id   = $hasAccounts && col_exists($pdo,'accounts','user_id');
$acc_balance   = $hasAccounts && col_exists($pdo,'accounts','balance');
$acc_name      = $hasAccounts && col_exists($pdo,'accounts','name');
$acc_type      = $hasAccounts && col_exists($pdo,'accounts','type');

$hasPayments   = tbl_exists($pdo,'payments');
$pay_acc_id    = $hasPayments && col_exists($pdo,'payments','account_id');
$pay_recv_by   = $hasPayments && col_exists($pdo,'payments','received_by');
$pay_date_col  = $hasPayments && (col_exists($pdo,'payments','paid_at') || col_exists($pdo,'payments','created_at'));

$hasTransfers  = tbl_exists($pdo,'wallet_transfers');

/* ---------------- my wallet ---------------- */
$me_id = (int)($_SESSION['user']['id'] ?? 0);
$my_acc_id = 0; $my_balance = 0.0;
if ($hasAccounts && $acc_user_id && $me_id>0) {
  $st=$pdo->prepare("SELECT id FROM accounts WHERE user_id=? LIMIT 1");
  $st->execute([$me_id]); $my_acc_id = (int)$st->fetchColumn();
  if ($my_acc_id>0) $my_balance = wallet_balance($pdo, $my_acc_id);
}

/* ---------------- missing mapping counter (for banner) ---------------- */
$missing_map = 0;
if ($hasPayments && $pay_acc_id && $pay_recv_by && $hasAccounts && $acc_user_id) {
  $c = $pdo->query("SELECT COUNT(1) FROM payments p WHERE p.account_id IS NULL AND p.received_by IS NOT NULL");
  $missing_map = (int)($c->fetchColumn() ?? 0);
}

/* ---------------- recent payments (+fallback join) ---------------- */
$recent = [];
if ($hasPayments) {
  $cols = ['p.id','p.amount'];
  if (col_exists($pdo,'payments','method'))   $cols[]='p.method';
  if (col_exists($pdo,'payments','paid_at'))  $cols[]='p.paid_at';
  elseif (col_exists($pdo,'payments','created_at')) $cols[]='p.created_at AS paid_at';

  // primary credited account
  if ($pay_acc_id && $hasAccounts){
    $cols[]='a.id AS account_id';
    if ($acc_name)    $cols[]='a.name AS account_name';
    if ($acc_user_id) $cols[]='a.user_id AS wallet_user_id';
  }

  // fallback: when account_id is NULL but received_by → accounts.user_id
  $fallbackJoin = '';
  if ($pay_recv_by && $acc_user_id) {
    $fallbackJoin = " LEFT JOIN accounts af ON (".($pay_acc_id ? "p.account_id IS NULL AND " : "")."af.user_id = p.received_by) ";
    $cols[] = 'af.id AS fb_account_id';
    if ($acc_name)    $cols[] = 'af.name AS fb_account_name';
    if ($acc_user_id) $cols[] = 'af.user_id AS fb_wallet_user_id';
  }

  // receiver name
  if ($pay_recv_by && tbl_exists($pdo,'users')){
    $ucols=$pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    $upick='name'; foreach(['name','full_name','username','email'] as $c) if(in_array($c,$ucols,true)){ $upick=$c; break; } 
    $cols[]="u.`$upick` AS received_by_name";
  }

  $sql = "SELECT ".implode(',',$cols)." FROM payments p
          ".($pay_acc_id && $hasAccounts? "LEFT JOIN accounts a ON a.id=p.account_id":"").
          $fallbackJoin.
          ($pay_recv_by && tbl_exists($pdo,'users')? " LEFT JOIN users u ON u.id=p.received_by ":"").
          " ORDER BY p.id DESC LIMIT 10";
  $recent = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
?>



  <!-- My Wallet -->
  <div class="card mb-3">
    <div class="card-header bg-light"><strong>My Wallet</strong></div>
    <div class="card-body">
      <?php if($me_id<=0): ?>
        <div class="alert alert-warning">Not logged in.</div>
      <?php else: ?>
        <div class="d-flex align-items-center gap-3 flex-wrap">
          <div><span class="text-muted">User:</span> <strong><?php echo h(user_label($pdo,$me_id)); ?></strong></div>
          <div><span class="text-muted">Account ID:</span> <strong><?php echo $my_acc_id>0?(int)$my_acc_id:'—'; ?></strong></div>
          <div><span class="text-muted">Balance:</span> <?php echo balance_badge('', $my_balance); ?></div>
          <?php if($my_acc_id<=0 && $hasAccounts): ?>
            <form method="post" class="ms-auto">
              <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
              <input type="hidden" name="action" value="create_my_wallet">
              <button class="btn btn-sm btn-primary">Create my wallet</button>
            </form>
          <?php endif; ?>
        </div>
         <?php endif; ?>
    </div>
  </div>

  <!-- Quick Actions -->
  <div class="card mb-3">
    <div class="card-header bg-light"><strong>Quick Actions</strong></div>
    <div class="card-body">
      <div class="row g-2">
        <div class="col-sm-4">
          <a class="btn btn-outline-primary w-100" href="/public/report_payments.php" target="_blank">
            <i class="bi bi-collection"></i> Open Payments Report
          </a>
        </div>
        <div class="col-sm-4">
          <a class="btn btn-outline-secondary w-100" href="/public/wallet_settlement.php" target="_blank">
            <i class="bi bi-arrow-left-right"></i> New Wallet Settlement
          </a>
        </div>
        <div class="col-sm-4">
          <form class="d-flex" action="/public/client_payments.php" method="get" target="_blank">
            <input class="form-control me-2" name="client_id" type="number" placeholder="Client ID">
            <button class="btn btn-outline-dark"><i class="bi bi-box-arrow-up-right"></i> Client Payments</button>
          </form>
        </div>
      </div>
 
    </div>
  </div>

  <!-- Recent Payments -->
  <div class="card">
    <div class="card-header bg-light"><strong>Recent Payments</strong></div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
          <thead class="table-light">
            <tr>
              <th>ID</th>
              <th>Date</th>
              <th class="text-end">Amount</th>
              <th>Method</th>
              <th>Credited To</th>
              <th>Received By</th>
            </tr>
          </thead>
          <tbody>
            <?php if(!$recent): ?>
              <tr><td colspan="6" class="text-center text-muted py-4">No payments found.</td></tr>
            <?php else: foreach($recent as $r): ?>
              <?php
                $dt   = isset($r['paid_at']) && $r['paid_at'] ? date('Y-m-d H:i', strtotime((string)$r['paid_at'])) : '';
                $amt  = number_format((float)$r['amount'],2);
                $meth = (string)($r['method']??'');
                // বাংলা: কোন ওয়ালেটে ক্রেডিট হয়েছে তার লেবেল (primary → fallback-by-received_by)
                $accLabel='';
                $accId = (int)($r['account_id'] ?? 0);
                $accNm = (string)($r['account_name'] ?? '');
                $wuid  = (int)($r['wallet_user_id'] ?? 0);

                $fbId = (int)($r['fb_account_id'] ?? 0);
                $fbNm = (string)($r['fb_account_name'] ?? '');
                $fbUid= (int)($r['fb_wallet_user_id'] ?? 0);

                if ($accId>0) {
                  $accLabel = $wuid>0 ? ($accNm!==''? "User Wallet ($accNm)" : "User Wallet (#$wuid)")
                                      : ($accNm!==''? "Company/Vault ($accNm)" : "Company/Vault (#$accId)");
                } elseif ($fbId>0) {
                  $accLabel = $fbUid>0 ? ($fbNm!==''? "User Wallet (via receiver: $fbNm)" : "User Wallet (via receiver #$fbUid)")
                                       : ($fbNm!==''? "Company/Vault (via receiver: $fbNm)" : "Company/Vault (via receiver #$fbId)");
                } else {
                  $accLabel = "—";
                }
                $recv = (string)($r['received_by_name'] ?? '');
              ?>
              <tr>
                <td><?php echo (int)$r['id']; ?></td>
                <td><?php echo h($dt); ?></td>
                <td class="text-end"><?php echo $amt; ?></td>
                <td><?php echo h($meth); ?></td>
                <td><?php echo h($accLabel); ?></td>
                <td><?php echo h($recv); ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../partials/partials_footer.php'; ?>
