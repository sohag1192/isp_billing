<?php
// /public/wallet_approvals.php — Approve/Reject pending transfers
declare(strict_types=1);
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function can_approve(): bool {
  $is_admin=(int)($_SESSION['user']['is_admin'] ?? 0);
  $role=strtolower((string)($_SESSION['user']['role'] ?? ''));
  return $is_admin===1 || in_array($role,['admin','superadmin','manager','accounts','accountant','billing'],true);
}
if(!can_approve()){
  include __DIR__.'/../partials/partials_header.php';
  echo '<div class="container my-4"><div class="alert alert-danger">You are not allowed to approve.</div></div>';
  include __DIR__.'/../partials/partials_footer.php'; exit;
}

// filters
$status = $_GET['status'] ?? 'pending'; // pending|approved|rejected|all
$where = []; $params=[];
if ($status !== 'all'){ $where[] = "t.status=?"; $params[]=$status; }
$wsql = $where ? ('WHERE '.implode(' AND ',$where)) : '';
$rows = $pdo->prepare("
  SELECT t.*, fa.user_id AS from_user, ta.user_id AS to_user, 
         u1.id AS cu_id, ". // creator
         "u2.id AS ap_id
  FROM wallet_transfers t
  LEFT JOIN accounts fa ON fa.id=t.from_account_id
  LEFT JOIN accounts ta ON ta.id=t.to_account_id
  LEFT JOIN users u1 ON u1.id=t.created_by
  LEFT JOIN users u2 ON u2.id=t.approved_by
  $wsql
  ORDER BY t.created_at DESC, t.id DESC
");
$rows->execute($params);
$data = $rows->fetchAll(PDO::FETCH_ASSOC);

// user labels
function user_label(PDO $pdo,int $uid): string {
  if($uid<=0) return '—';
  $cols=$pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN); $pick=null;
  foreach(['name','full_name','username','email'] as $c){ if(in_array($c,$cols,true)){ $pick=$c; break; } }
  if($pick){ $st=$pdo->prepare("SELECT $pick FROM users WHERE id=?"); $st->execute([$uid]); $v=$st->fetchColumn(); return ($v?:('User#'.$uid)); }
  return 'User#'.$uid;
}
include __DIR__.'/../partials/partials_header.php';
?>
<div class="container my-4">
  <div class="d-flex align-items-center justify-content-between">
    <h3 class="mb-0">Wallet Approvals</h3>
    <div class="d-flex gap-2">
      <a href="?status=pending" class="btn btn-outline-primary <?= $status==='pending'?'active':'' ?>">Pending</a>
      <a href="?status=approved" class="btn btn-outline-success <?= $status==='approved'?'active':'' ?>">Approved</a>
      <a href="?status=rejected" class="btn btn-outline-danger <?= $status==='rejected'?'active':'' ?>">Rejected</a>
      <a href="?status=all" class="btn btn-outline-secondary <?= $status==='all'?'active':'' ?>">All</a>
    </div>
  </div>

  <div class="card shadow-sm mt-3">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-striped align-middle">
          <thead class="table-light">
            <tr>
              <th>ID</th>
              <th>Created</th>
              <th>From</th>
              <th>To</th>
              <th class="text-end">Amount</th>
              <th>Method</th>
              <th>Ref</th>
              <th>Status</th>
              <th>Notes</th>
              <th style="width:180px;" class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if(!$data): ?>
              <tr><td colspan="10" class="text-center text-muted">No records.</td></tr>
            <?php else: foreach($data as $r): 
              $from = (int)($r['from_user'] ?? 0);
              $to   = (int)($r['to_user'] ?? 0);
              $toTxt = $to>0 ? user_label($pdo,$to) : 'Company Vault';
            ?>
              <tr>
                <td>#<?= (int)$r['id'] ?></td>
                <td>
                  <div><?= h($r['created_at']) ?></div>
                  <div class="small text-muted">by <?= h(user_label($pdo,(int)($r['created_by'] ?? 0))) ?></div>
                </td>
                <td><?= h(user_label($pdo,$from)) ?></td>
                <td><?= h($toTxt) ?></td>
                <td class="text-end fw-semibold"><?= number_format((float)$r['amount'],2) ?></td>
                <td><?= h($r['method'] ?? '') ?></td>
                <td><?= h($r['ref_no'] ?? '') ?></td>
                <td>
                  <?php if($r['status']==='pending'): ?>
                    <span class="badge bg-warning text-dark">pending</span>
                  <?php elseif($r['status']==='approved'): ?>
                    <span class="badge bg-success">approved</span>
                  <?php else: ?>
                    <span class="badge bg-danger">rejected</span>
                  <?php endif; ?>
                </td>
                <td>
                  <div><?= h($r['notes'] ?? '') ?></div>
                  <?php if($r['decision_note']): ?>
                    <div class="small text-muted">decision: <?= h($r['decision_note']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="text-end">
                  <?php if($r['status']==='pending'): ?>
                    <form class="d-inline" method="post" action="/public/wallet_transfer_action.php" onsubmit="return confirm('Approve this transfer?');">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="action" value="approve">
                      <button class="btn btn-sm btn-success">Approve</button>
                    </form>
                    <form class="d-inline" method="post" action="/public/wallet_transfer_action.php" onsubmit="return confirm('Reject this transfer?');">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="action" value="reject">
                      <input type="hidden" name="note" value="Rejected by approver">
                      <button class="btn btn-sm btn-outline-danger">Reject</button>
                    </form>
                  <?php else: ?>
                    <div class="small text-muted">
                      <?= h($r['approved_at'] ?? '') ?> by <?= h(user_label($pdo,(int)($r['approved_by'] ?? 0))) ?>
                    </div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__.'/../partials/partials_footer.php'; ?>
