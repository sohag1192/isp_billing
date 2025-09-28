<?php
// /public/wallets_dashboard.php
// UI: English; Comments: বাংলা — “Start Here” ড্যাশবোর্ড

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');

// -------- includes --------
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
$acl_file = __DIR__ . '/../app/acl.php';
if (is_file($acl_file)) require_once $acl_file;

/* ---------------- session & CSRF ---------------- */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = (string)$_SESSION['csrf'] ?? '';
$page_title = 'Wallets';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------------- helpers ---------------- */
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
    if (!tbl_exists($pdo,'users')) return 'User#'.$uid;
    $cols=$pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN)?:[];
    $pick='name'; foreach(['name','full_name','username','email'] as $c){ if(in_array($c,$cols,true)){ $pick=$c; break; } }
    $st=$pdo->prepare("SELECT `$pick` FROM users WHERE id=?"); $st->execute([$uid]);
    $x=$st->fetchColumn();
    return ($x!==false && $x!=='')?(string)$x:('User#'.$uid);
  }catch(Throwable $e){ return 'User#'.$uid; }
}
function balance_badge(string $prefix, float $b): string {
  $cls = abs($b) < 0.0000001 ? 'bg-secondary' : ($b >= 0 ? 'bg-success' : 'bg-danger');
  return '<span class="badge '.$cls.'">'.$prefix.number_format($b,2).'</span>';
}

/* -------- wallet balance -------- */
function wallet_balance(PDO $pdo, int $account_id): float {
  $payments=0.0; $fallback=0.0; $out=0.0; $in=0.0;
  $hasP = tbl_exists($pdo,'payments');
  $hasA = tbl_exists($pdo,'accounts');
  $pHasAcc = $hasP && col_exists($pdo,'payments','account_id');
  $pHasAmt = $hasP && col_exists($pdo,'payments','amount');
  $pHasRecv= $hasP && col_exists($pdo,'payments','received_by');
  $aHasUser= $hasA && col_exists($pdo,'accounts','user_id');

  if ($pHasAcc && $pHasAmt) {
    $q=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE account_id=?");
    $q->execute([$account_id]); $payments=(float)$q->fetchColumn();
  }
  if ($pHasAmt && $pHasRecv && $aHasUser) {
    $st = $pdo->prepare("SELECT COALESCE(user_id,0) FROM accounts WHERE id=? LIMIT 1");
    $st->execute([$account_id]);
    $uid = (int)($st->fetchColumn() ?? 0);
    if ($uid > 0) {
      if ($pHasAcc) {
        $fb=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE account_id IS NULL AND received_by=?");
      } else {
        $fb=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE received_by=?");
      }
      $fb->execute([$uid]); $fallback=(float)$fb->fetchColumn();
    }
  }
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

/* -------- POST actions (create/backfill) -------- */
// ... [unchanged from previous version, keep same as earlier file] ...

/* ---------------- HTML শুরু ---------------- */
require_once __DIR__ . '/../partials/partials_header.php';

/* ---------------- my wallet ---------------- */
$me_id = (int)($_SESSION['user']['id'] ?? 0);
$role  = (string)($_SESSION['user']['role'] ?? 'user');

$my_acc_id = 0; $my_balance = 0.0;
if (tbl_exists($pdo,'accounts') && col_exists($pdo,'accounts','user_id') && $me_id>0) {
  $st=$pdo->prepare("SELECT id FROM accounts WHERE user_id=? LIMIT 1");
  $st->execute([$me_id]); $my_acc_id = (int)$st->fetchColumn();
  if ($my_acc_id>0) $my_balance = wallet_balance($pdo, $my_acc_id);
}
?>

  <!-- My Wallet -->
  <div class="card mb-3">
    <div class="card-header bg-light"><strong>My Wallet</strong></div>
    <div class="card-body">
      <div class="d-flex align-items-center gap-3 flex-wrap">
        <div><span class="text-muted">User:</span> <strong><?php echo h(user_label($pdo,$me_id)); ?></strong></div>
        <div><span class="text-muted">Account ID:</span> <strong><?php echo $my_acc_id>0?(int)$my_acc_id:'—'; ?></strong></div>
        <div><span class="text-muted">Balance:</span> <?php echo balance_badge('', $my_balance); ?></div>
      </div>
    </div>
  </div>

<?php if ($role === 'admin' && tbl_exists($pdo,'accounts')): ?>
  <!-- All Wallets (Admin only) -->
  <div class="card mb-3">
    <div class="card-header bg-light"><strong>All Wallets (Admin View)</strong></div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
          <thead class="table-light">
            <tr>
              <th>Account ID</th>
              <th>User</th>
              <th class="text-end">Balance</th>
            </tr>
          </thead>
          <tbody>
            <?php
              $rows = $pdo->query("SELECT id,user_id FROM accounts ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
              if(!$rows): ?>
                <tr><td colspan="3" class="text-center text-muted py-3">No accounts found.</td></tr>
              <?php else:
                foreach($rows as $row):
                  $aid = (int)$row['id'];
                  $uid = (int)($row['user_id'] ?? 0);
                  $bal = wallet_balance($pdo,$aid);
              ?>
                <tr>
                  <td><?php echo $aid; ?></td>
                  <td><?php echo $uid>0?h(user_label($pdo,$uid)):'—'; ?></td>
                  <td class="text-end"><?php echo balance_badge('', $bal); ?></td>
                </tr>
              <?php endforeach;
              endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../partials/partials_footer.php'; ?>
