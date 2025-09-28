<?php
// /public/accounts.php
// Accounts module (CRUD) — list, add, edit, activate/deactivate, filters, sorting, pagination
// UI: English; Comments: বাংলা (procedural PHP + PDO + Bootstrap 5)

declare(strict_types=1);

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function is_ajax(): bool {
  if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH'])==='xmlhttprequest') return true;
  if (!empty($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) return true;
  return false;
}
function jres($arr, int $code=200){ http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode($arr); exit; }
function dbh(){ return db(); }

/* -----------------------------------------
   Bootstrap: ensure table (idempotent)
   (বাংলা) টেবল না থাকলে বানাই — একই স্কিমা আগেই ব্যবহার করেছি
------------------------------------------*/
dbh()->exec("
CREATE TABLE IF NOT EXISTS accounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  type ENUM('cash','bank','mfs','other') DEFAULT 'other',
  number VARCHAR(100) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX(is_active),
  INDEX(type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* -----------------------------------------
   Helpers
------------------------------------------*/
function valid_type(string $t): string {
  $t = strtolower(trim($t));
  return in_array($t, ['cash','bank','mfs','other'], true) ? $t : 'other';
}
function read_int($arr, string $key, int $def=0): int {
  return isset($arr[$key]) ? (int)$arr[$key] : $def;
}

/* -----------------------------------------
   POST actions: add / edit / toggle
------------------------------------------*/
$flash_success = '';
$flash_error   = '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $action = $_POST['action'] ?? '';

  // (বাংলা) সাধারণ ভ্যালিডেশন শেয়ার্ড
  $name   = trim($_POST['name'] ?? '');
  $type   = valid_type($_POST['type'] ?? 'other');
  $number = trim($_POST['number'] ?? '');
  $id     = read_int($_POST, 'id');

  try {
    if ($action === 'add') {
      if ($name === '') throw new Exception('Name is required.');
      $st = dbh()->prepare("INSERT INTO accounts (name, type, number, is_active) VALUES (?, ?, ?, 1)");
      $st->execute([$name, $type, $number]);
      $flash_success = 'Account created.';
      if (is_ajax()) jres(['status'=>'success','message'=>$flash_success]);
    }
    elseif ($action === 'edit') {
      if ($id<=0) throw new Exception('Invalid ID.');
      if ($name === '') throw new Exception('Name is required.');
      $st = dbh()->prepare("UPDATE accounts SET name=?, type=?, number=? WHERE id=?");
      $st->execute([$name, $type, $number, $id]);
      $flash_success = 'Account updated.';
      if (is_ajax()) jres(['status'=>'success','message'=>$flash_success]);
    }
    elseif ($action === 'toggle') {
      if ($id<=0) throw new Exception('Invalid ID.');
      $st0 = dbh()->prepare("SELECT is_active FROM accounts WHERE id=?");
      $st0->execute([$id]);
      $cur = $st0->fetchColumn();
      if ($cur===false) throw new Exception('Account not found.');
      $new = ((int)$cur) ? 0 : 1;
      $st = dbh()->prepare("UPDATE accounts SET is_active=? WHERE id=?");
      $st->execute([$new, $id]);
      $flash_success = $new ? 'Account activated.' : 'Account deactivated.';
      if (is_ajax()) jres(['status'=>'success','message'=>$flash_success,'is_active'=>$new]);
    }
    else {
      throw new Exception('Unknown action.');
    }
  } catch (Throwable $e) {
    if (is_ajax()) jres(['status'=>'error','message'=>$e->getMessage()], 400);
    $flash_error = $e->getMessage();
  }

  if (!is_ajax()) {
    $qs = $_GET; // keep current filters
    header('Location: ?'.http_build_query($qs));
    exit;
  }
}

/* -----------------------------------------
   Filters + Sorting + Pagination
------------------------------------------*/
// (বাংলা) ফিল্টার
$search = trim($_GET['search'] ?? '');
$f_type = strtolower(trim($_GET['type'] ?? ''));
$f_state= trim($_GET['state'] ?? ''); // all / active / inactive

// (বাংলা) সোর্ট
$sort = $_GET['sort'] ?? 'created_at'; // name|type|created_at|payments
$dir  = strtolower($_GET['dir'] ?? 'desc'); // asc|desc
$sort_whitelist = [
  'name' => 'a.name',
  'type' => 'a.type',
  'created_at' => 'a.id', // id ~ created_at
  'payments' => 'p.cnt'
];
$order_col = $sort_whitelist[$sort] ?? 'a.id';
$order_dir = $dir === 'asc' ? 'ASC' : 'DESC';

// (বাংলা) পেজিনেশন
$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset= ($page-1)*$limit;

// (বাংলা) WHERE
$where=[]; $params=[];
if ($search!==''){ $where[] = "(a.name LIKE ? OR a.number LIKE ?)"; $params[]="%$search%"; $params[]="%$search%"; }
if (in_array($f_type, ['cash','bank','mfs','other'], true)){ $where[]="a.type=?"; $params[]=$f_type; }
if ($f_state==='active'){ $where[]="a.is_active=1"; }
elseif ($f_state==='inactive'){ $where[]="a.is_active=0"; }
$where_sql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

// (বাংলা) টোটাল কাউন্ট
$stc = dbh()->prepare("SELECT COUNT(*) FROM accounts a $where_sql");
$stc->execute($params);
$total_rows = (int)$stc->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $limit));

// (বাংলা) লিস্ট + payments count (LEFT JOIN সাবকুয়েরি)
$sql = "
  SELECT a.*,
         COALESCE(p.cnt,0) AS payments_cnt,
         COALESCE(p.amount_sum,0) AS payments_sum
  FROM accounts a
  LEFT JOIN (
    SELECT account_id, COUNT(*) cnt, SUM(amount) amount_sum
    FROM payments
    WHERE account_id IS NOT NULL
    GROUP BY account_id
  ) p ON p.account_id = a.id
  $where_sql
  ORDER BY $order_col $order_dir, a.id DESC
  LIMIT $limit OFFSET $offset
";
$st = dbh()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// (বাংলা) সোর্ট লিংক হেল্পার
function sort_link(string $key, string $label){
  $q = $_GET;
  $cur = $_GET['sort'] ?? 'created_at';
  $dir = strtolower($_GET['dir'] ?? 'desc');
  $q['sort'] = $key;
  if ($cur===$key) { $q['dir'] = ($dir==='asc'?'desc':'asc'); } else { $q['dir']='asc'; }
  $icon = '';
  if ($cur===$key) $icon = $dir==='asc' ? '↑' : '↓';
  $u = '?'.h(http_build_query($q));
  return '<a href="'.$u.'" class="text-decoration-none">'.$label.' <small>'.$icon.'</small></a>';
}

include __DIR__ . '/../partials/partials_header.php';
?>
<div class="container my-4">
  <div class="d-flex align-items-center justify-content-between">
    <h3 class="mb-0">Accounts</h3>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#accAddModal">
      <i class="bi bi-plus-circle"></i> New Account
    </button>
  </div>

  <?php if ($flash_success): ?><div class="alert alert-success mt-3"><?=h($flash_success)?></div><?php endif; ?>
  <?php if ($flash_error):   ?><div class="alert alert-danger mt-3"><?=h($flash_error)?></div><?php endif; ?>

  <form class="row g-2 mt-3 mb-3">
    <div class="col-md-4">
      <input type="text" name="search" value="<?=h($search)?>" class="form-control" placeholder="Search name/number">
    </div>
    <div class="col-md-2">
      <select name="type" class="form-select">
        <option value="">All types</option>
        <?php foreach (['cash','mfs','bank','other'] as $t): ?>
          <option value="<?=$t?>" <?= $f_type===$t?'selected':'' ?>><?=ucfirst($t)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <select name="state" class="form-select">
        <option value="">All</option>
        <option value="active"   <?= $f_state==='active'?'selected':'' ?>>Active</option>
        <option value="inactive" <?= $f_state==='inactive'?'selected':'' ?>>Inactive</option>
      </select>
    </div>
    <div class="col-md-4 d-flex gap-2">
      <button class="btn btn-outline-secondary"><i class="bi bi-funnel"></i> Filter</button>
      <a class="btn btn-outline-dark" href="/public/accounts.php"><i class="bi bi-x-circle"></i> Reset</a>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-striped align-middle">
      <thead class="table-light">
        <tr>
          <th style="width:80px;">ID</th>
          <th><?= sort_link('name','Name') ?></th>
          <th><?= sort_link('type','Type') ?></th>
          <th>Number</th>
          <th class="text-end"><?= sort_link('payments','Payments') ?></th>
          <th class="text-end">Amount Sum</th>
          <th><?= sort_link('created_at','Created') ?></th>
          <th>Status</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="9" class="text-center text-muted">No accounts found.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td>#<?=h((string)$r['id'])?></td>
            <td><?=h($r['name'])?></td>
            <td><span class="badge bg-secondary"><?=h($r['type'])?></span></td>
            <td><?=h($r['number'] ?? '')?></td>
            <td class="text-end"><?=h((string)($r['payments_cnt'] ?? 0))?></td>
            <td class="text-end"><?= number_format((float)($r['payments_sum'] ?? 0), 2) ?></td>
            <td><?=h((string)$r['created_at'])?></td>
            <td>
              <span class="badge bg-<?= ((int)$r['is_active'] ? 'success':'secondary') ?>">
                <?= ((int)$r['is_active'] ? 'Active':'Inactive') ?>
              </span>
            </td>
            <td class="text-end">
              <button class="btn btn-sm btn-outline-primary me-1" 
                      data-bs-toggle="modal"
                      data-bs-target="#accEditModal"
                      data-id="<?= (int)$r['id'] ?>"
                      data-name="<?= h($r['name']) ?>"
                      data-type="<?= h($r['type']) ?>"
                      data-number="<?= h($r['number'] ?? '') ?>">
                <i class="bi bi-pencil-square"></i> Edit
              </button>
              <form method="post" class="d-inline">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-sm <?= ((int)$r['is_active'] ? 'btn-outline-warning':'btn-outline-success') ?>">
                  <?= ((int)$r['is_active'] ? '<i class="bi bi-pause"></i> Deactivate':'<i class="bi bi-play"></i> Activate') ?>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php
    $win=5; $start=max(1,$page-intdiv($win-1,2)); $end=min($total_pages,$start+$win-1);
    if ($end-$start+1<$win) $start=max(1,$end-$win+1);
  ?>
  <nav aria-label="Page navigation">
    <ul class="pagination justify-content-center">
      <?php
        $q=$_GET; $q['page']=1; $first='?'.h(http_build_query($q));
        $q=$_GET; $q['page']=max(1,$page-1); $prev='?'.h(http_build_query($q));
      ?>
      <li class="page-item <?=($page<=1?'disabled':'')?>"><a class="page-link" href="<?=$first?>">&laquo;</a></li>
      <li class="page-item <?=($page<=1?'disabled':'')?>"><a class="page-link" href="<?=$prev?>">Prev</a></li>
      <?php for($i=$start;$i<=$end;$i++): $q=$_GET; $q['page']=$i; $u='?'.h(http_build_query($q)); ?>
        <li class="page-item <?=($i==$page?'active':'')?>"><a class="page-link" href="<?=$u?>"><?=$i?></a></li>
      <?php endfor;
        $q=$_GET; $q['page']=min($total_pages,$page+1); $next='?'.h(http_build_query($q));
        $q=$_GET; $q['page']=$total_pages; $last='?'.h(http_build_query($q));
      ?>
      <li class="page-item <?=($page>=$total_pages?'disabled':'')?>"><a class="page-link" href="<?=$next?>">Next</a></li>
      <li class="page-item <?=($page>=$total_pages?'disabled':'')?>"><a class="page-link" href="<?=$last?>">&raquo;</a></li>
    </ul>
  </nav>
</div>

<!-- Add Modal -->
<div class="modal fade" id="accAddModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="action" value="add">
      <div class="modal-header">
        <h5 class="modal-title">New Account</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Name</label>
          <input type="text" name="name" class="form-control" required placeholder="e.g., Cash Box / bKash Personal">
        </div>
        <div class="mb-3">
          <label class="form-label">Type</label>
          <select name="type" class="form-select">
            <option value="cash">Cash</option>
            <option value="mfs">MFS (bKash/Nagad)</option>
            <option value="bank">Bank</option>
            <option value="other">Other</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Number</label>
          <input type="text" name="number" class="form-control" placeholder="Wallet/Account No (optional)">
        </div>
        <div class="text-muted small">Active by default. You can deactivate later.</div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-primary"><i class="bi bi-save"></i> Save</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="accEditModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="edit_id">
      <div class="modal-header">
        <h5 class="modal-title">Edit Account</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Name</label>
          <input type="text" name="name" id="edit_name" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Type</label>
          <select name="type" id="edit_type" class="form-select">
            <option value="cash">Cash</option>
            <option value="mfs">MFS (bKash/Nagad)</option>
            <option value="bank">Bank</option>
            <option value="other">Other</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Number</label>
          <input type="text" name="number" id="edit_number" class="form-control">
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-primary"><i class="bi bi-save"></i> Update</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </form>
  </div>
</div>

<script>
// (বাংলা) এডিট মডাল ওপেন হলে ভ্যালু-পপুলেট
document.getElementById('accEditModal')?.addEventListener('show.bs.modal', function (ev) {
  const btn = ev.relatedTarget;
  if (!btn) return;
  const id = btn.getAttribute('data-id');
  const name = btn.getAttribute('data-name');
  const type = btn.getAttribute('data-type');
  const number = btn.getAttribute('data-number');
  this.querySelector('#edit_id').value = id || '';
  this.querySelector('#edit_name').value = name || '';
  this.querySelector('#edit_type').value = type || 'other';
  this.querySelector('#edit_number').value = number || '';
});
</script>

<?php include __DIR__ . '/../partials/partials_footer.php'; ?>
