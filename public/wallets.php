<?php
// /public/wallets.php – User Wallet dashboard (approval-aware: settlements only if status='approved')
// UI: English; Comments: বাংলা

declare(strict_types=1);

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/wallets_helper.php';

/* ---------------------- helpers ---------------------- */
// বাংলা: সেফ HTML escape
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
// বাংলা: PDO হ্যান্ডেল (একবারই db() কল)
function dbh(): PDO { return db(); }

/* -------------------------------------------------
 * (বাংলা) টেবিলের কলাম ক্যাশ-মেকানিজম
 * ------------------------------------------------- */
function hasColumn(string $table, string $column): bool {
    static $cache = [];
    if (!isset($cache[$table])) {
        $stmt = dbh()->query("SHOW COLUMNS FROM `$table`");
        $cols = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $cache[$table] = array_flip($cols);
    }
    return isset($cache[$table][$column]);
}

/* -------------------------------------------------
 * (বাংলা) অনুমোদন চেক – admin / manager ইত্যাদি
 * ------------------------------------------------- */
function can_approve(): bool {
    $is_admin = (int)($_SESSION['user']['is_admin'] ?? 0);
    $role = strtolower((string)($_SESSION['user']['role'] ?? ''));
    return $is_admin === 1 || in_array($role, [
        'admin', 'superadmin', 'manager',
        'accounts', 'accountant', 'billing'
    ], true);
}

/* -------------------------------------------------
 * (বাংলা) ইউজারকে নিজস্ব ডেটা দেখার অনুমতি
 * ------------------------------------------------- */
function can_view_user(int $viewerId, int $targetId): bool {
    // নিজের ডেটা দেখতে পারবে, অথবা অনুমোদন-প্রাপকরা সব দেখতে পারবে
    return $viewerId === $targetId || can_approve();
}

/* -------------------- availability checks -------------------- */
$has_account     = hasColumn('payments', 'account_id');
$has_received_by = hasColumn('payments', 'received_by');
$has_paid_at     = hasColumn('payments', 'paid_at');

if (!$has_account || !$has_received_by) {
    include __DIR__ . '/../partials/partials_header.php';
    echo '<div class="alert alert-danger m-3">payments.account_id / payments.received_by columns are required.</div>';
    include __DIR__ . '/../partials/partials_footer.php';
    exit;
}

/* -------------------- input filters -------------------- */
$user_id   = (int)($_GET['user_id'] ?? 0);
$wallet_id = (int)($_GET['wallet_id'] ?? 0); // ✅ নতুন: নির্দিষ্ট ওয়ালেট ফিল্টার
$date_from = trim((string)($_GET['date_from'] ?? '')); // YYYY-MM-DD
$date_to   = trim((string)($_GET['date_to'] ?? ''));   // YYYY-MM-DD
$page      = max(1, (int)($_GET['page'] ?? 1));
$limit     = 25;

// বাংলা: ভিউয়ার আইডি বের করি
$viewerId = (int)($_SESSION['user']['id'] ?? 0);

/* -------------------- users map (fallback name) -------------------- */
$users = []; // id => display name
try {
    $cols = dbh()->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    $pick = null;
    foreach (['name', 'full_name', 'username', 'email'] as $c) {
        if (in_array($c, $cols, true)) { $pick = $c; break; }
    }

    if ($pick) {
        $stmt = dbh()->query("SELECT id, $pick AS u FROM users ORDER BY id");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $uid = (int)$r['id'];
            $users[$uid] = ($r['u'] !== null && $r['u'] !== '') ? (string)$r['u'] : "User#$uid";
        }
    } else {
        $stmt = dbh()->query("SELECT id FROM users ORDER BY id");
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            $uid = (int)$uid;
            $users[$uid] = "User#$uid";
        }
    }
} catch (Throwable $e) {
    include __DIR__ . '/../partials/partials_header.php';
    echo '<div class="alert alert-danger m-3">Failed to read users table: ' . h($e->getMessage()) . '</div>';
    include __DIR__ . '/../partials/partials_footer.php';
    exit;
}

/* -------------------- accounts meta: multi-wallet support -------------------- */
// বাংলা: accounts টেবিল থেকে নাম কলাম autodetect
$accCols = dbh()->query("SHOW COLUMNS FROM accounts")->fetchAll(PDO::FETCH_COLUMN);
$accNameCol = null;
foreach (['name','account_name','title','label','account_title'] as $c) {
    if (in_array($c, $accCols, true)) { $accNameCol = $c; break; }
}

// বাংলা: user→[wallets] এবং wallet_id→meta ম্যাপ বানাই
$accounts_by_user = [];      // [user_id] => [ [id, name], ... ]
$wallet_meta      = [];      // [account_id] => ['user_id'=>..., 'name'=>...]

$acctSelect = 'id, user_id' . ($accNameCol ? ", $accNameCol AS acc_name" : '');
$accStmt = dbh()->query("SELECT $acctSelect FROM accounts WHERE user_id IS NOT NULL ORDER BY user_id, id");
foreach ($accStmt->fetchAll(PDO::FETCH_ASSOC) as $ar) {
    $aid = (int)$ar['id'];
    $uid = (int)$ar['user_id'];
    $anm = isset($ar['acc_name']) && $ar['acc_name'] !== '' ? (string)$ar['acc_name'] : ("Account#$aid");
    $accounts_by_user[$uid] = $accounts_by_user[$uid] ?? [];
    $accounts_by_user[$uid][] = ['id'=>$aid, 'name'=>$anm];
    $wallet_meta[$aid] = ['user_id'=>$uid, 'name'=>$anm];
}

/* -------------------- date clause helper (inclusive end-of-day) -------------------- */
function addDateClause(string $col, string $from, string $to, array &$sql, array &$params): void {
    if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $sql[]    = "$col >= ?";
        $params[] = $from;
    }
    if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        // বাংলা: DATETIME হলে দিনশেষ 23:59:59 পর্যন্ত ইনক্লুসিভ করতে
        $sql[]    = "$col <= ?";
        $params[] = $to . ' 23:59:59';
    }
}

/* -------------------- date clauses -------------------- */
$date_col = $has_paid_at ? 'p.paid_at' : 'p.created_at';

/* payments (balances aggregate) */
$pay_where = $pay_params = [];
addDateClause($date_col, $date_from, $date_to, $pay_where, $pay_params);
$pay_wsql = $pay_where ? ('AND ' . implode(' AND ', $pay_where)) : '';

/* settlements — prefer approved_at if present */
$has_approved_at = hasColumn('wallet_transfers', 'approved_at');
$set_date_col    = $has_approved_at ? 't.approved_at' : 't.created_at';

$set_where = $set_params = [];
addDateClause($set_date_col, $date_from, $date_to, $set_where, $set_params);
$set_wsql = $set_where ? ('AND ' . implode(' AND ', $set_where)) : '';

/* -------------------- payments per user (credit) -------------------- */
$pay_sql = "
    SELECT a.user_id, COALESCE(SUM(p.amount),0) AS amt
    FROM accounts a
    LEFT JOIN payments p ON p.account_id = a.id $pay_wsql
    WHERE a.user_id IS NOT NULL
    GROUP BY a.user_id
";
$stp = dbh()->prepare($pay_sql);
$stp->execute($pay_params);
$paid_by_user = [];
foreach ($stp->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $paid_by_user[(int)$r['user_id']] = (float)$r['amt'];
}

/* -------------------- approved settlements out (company receives) -------------------- */
$so_sql = "
    SELECT a.user_id, COALESCE(SUM(t.amount),0) AS amt
    FROM accounts a
    JOIN wallet_transfers t ON t.from_account_id = a.id
       AND t.status = 'approved' $set_wsql
    WHERE a.user_id IS NOT NULL
    GROUP BY a.user_id
";
$sto = dbh()->prepare($so_sql);
$sto->execute($set_params);
$settled_out = [];
foreach ($sto->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $settled_out[(int)$r['user_id']] = (float)$r['amt'];
}

/* -------------------- approved settlements in (user receives) -------------------- */
$si_sql = "
    SELECT a.user_id, COALESCE(SUM(t.amount),0) AS amt
    FROM accounts a
    JOIN wallet_transfers t ON t.to_account_id = a.id
       AND t.status = 'approved' $set_wsql
    WHERE a.user_id IS NOT NULL
    GROUP BY a.user_id
";
$sti = dbh()->prepare($si_sql);
$sti->execute($set_params);
$settled_in = [];
foreach ($sti->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $settled_in[(int)$r['user_id']] = (float)$r['amt'];
}

/* -------------------- combine balances (access-aware) -------------------- */
$balances = [];
foreach ($users as $uid => $uname) {
    if (!can_view_user($viewerId, (int)$uid)) {
        // বাংলা: অনুমতি না থাকলে সংখ্যাগুলো হাইড করি
        $balances[$uid] = [
            'paid_sum'    => null,
            'settled_out' => null,
            'settled_in'  => null,
            'balance'     => null,
        ];
        continue;
    }
    $p = $paid_by_user[$uid] ?? 0.0;
    $o = $settled_out[$uid]  ?? 0.0;
    $i = $settled_in[$uid]   ?? 0.0;
    $balances[$uid] = [
        'paid_sum'    => $p,
        'settled_out' => $o,
        'settled_in'  => $i,
        'balance'     => ($p - $o + $i),
    ];
}

/* -------------------- grand totals (access + filter aware) -------------------- */
$grand = [
    'paid_sum'    => 0.0,
    'settled_out' => 0.0,
    'settled_in'  => 0.0,
    'balance'     => 0.0,
];
foreach ($balances as $uid => $b) {
    if ($b['paid_sum'] === null) continue; // hidden row skip
    $grand['paid_sum']    += (float)$b['paid_sum'];
    $grand['settled_out'] += (float)$b['settled_out'];
    $grand['settled_in']  += (float)$b['settled_in'];
    $grand['balance']     += (float)$b['balance'];
}

/* -------------------- Transactions list (multi-wallet aware) -------------------- */
/* বাংলা: permission guard আগে—user_id ও wallet_id দুইটাই validate করি */
if ($user_id > 0 && !can_view_user($viewerId, $user_id)) {
    $user_id = $viewerId ?: 0;
}
if ($wallet_id > 0) {
    // ওয়ালেটের owner বের করি; owner না হলে approver না হলে disallow
    $own = dbh()->prepare("SELECT user_id FROM accounts WHERE id=?");
    $own->execute([$wallet_id]);
    $wid_uid = (int)($own->fetchColumn() ?: 0);
    if ($wid_uid > 0 && !can_view_user($viewerId, $wid_uid)) {
        $wallet_id = 0; // অনুমতি নাই—ওয়ালেট ফিল্টার সরিয়ে দেই
    }
    // যদি user_id সেট আছে এবং নির্বাচিত wallet অন্য user-এর হয় → wallet silently drop
    if ($user_id > 0 && $wid_uid > 0 && $wid_uid !== $user_id) {
        $wallet_id = 0;
    }
}

$list_where  = [];
$list_params = [];
addDateClause($date_col, $date_from, $date_to, $list_where, $list_params);

if ($wallet_id > 0) {
    $list_where[] = 'p.account_id = ?';
    $list_params[] = $wallet_id;
} elseif ($user_id > 0) {
    // ঐ ইউজারের সব linked wallets (multi-wallet)
    $acctIds = array_map(fn($a) => (int)$a['id'], $accounts_by_user[$user_id] ?? []);
    if ($acctIds) {
        $ph = implode(',', array_fill(0, count($acctIds), '?'));
        $list_where[] = "p.account_id IN ($ph)";
        foreach ($acctIds as $aid) { $list_params[] = $aid; }
    } else {
        // linked wallet নাই → খালি দেখাই
        $list_where[] = '1=0';
    }
}
// else: All users + All wallets (date-only filter)

$list_sql = $list_where ? ('WHERE ' . implode(' AND ', $list_where)) : '';

/* ---- pagination counters ---- */
$cnt_stmt = dbh()->prepare("SELECT COUNT(*) FROM payments p $list_sql");
$cnt_stmt->execute($list_params);
$total_rows  = (int)$cnt_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $limit));
$page        = max(1, min($page, $total_pages));
$offset      = ($page - 1) * $limit;

/* ---- actual rows ---- */
// বাংলা: ট্রানজ্যাকশনে কোন ওয়ালেট—এইজন্য accounts join করে নাম/আইডি নিই
$accJoinCols = $accNameCol ? "acc.$accNameCol AS acc_name" : "NULL AS acc_name";
$row_stmt = dbh()->prepare("
    SELECT p.*, inv.id AS inv_id, inv.status AS inv_status,
           c.pppoe_id, c.name AS client_name,
           acc.id AS account_id, $accJoinCols
    FROM payments p
    LEFT JOIN invoices inv ON inv.id = p.invoice_id
    LEFT JOIN clients  c   ON c.id = p.client_id
    LEFT JOIN accounts acc ON acc.id = p.account_id
    $list_sql
    ORDER BY $date_col DESC, p.id DESC
    LIMIT $limit OFFSET $offset
");
$row_stmt->execute($list_params);
$rows = $row_stmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------- pending approvals count (for button badge) -------------------- */
$pending_count = 0;
if (can_approve()) {
    try {
        $pending_count = (int)dbh()->query(
            "SELECT COUNT(*) FROM wallet_transfers WHERE status = 'pending'"
        )->fetchColumn();
    } catch (Throwable $e) { /* ignore */ }
}

/* -------------------- output -------------------- */
include __DIR__ . '/../partials/partials_header.php';
?>

<div class="d-flex align-items-center justify-content-between mt-2">
  <h3 class="mb-0">User Wallets</h3>
  <div class="d-flex gap-2">
    <?php if (can_approve()): ?>
      <a href="/public/wallet_approvals.php" class="btn btn-outline-success btn-sm">
        <i class="bi bi-check2-square"></i> Approvals
        <?php if ($pending_count): ?><span class="badge bg-success ms-1"><?= (int)$pending_count ?></span><?php endif; ?>
      </a>
    <?php endif; ?>

    <a href="/public/wallet_settlement.php" class="btn btn-outline-primary btn-sm">
      <i class="bi bi-arrow-left-right"></i> New Settlement
    </a>

    <?php if (can_approve()): // ছোট আইকন-অনলি Manage Accounts বাটন ?>
      <a href="/public/accounts_manage.php"
         class="btn btn-outline-dark btn-sm d-inline-flex align-items-center"
         data-bs-toggle="tooltip" data-bs-placement="bottom"
         title="Manage Accounts">
        <i class="bi bi-people-gear"></i>
        <span class="d-none d-md-inline ms-1">Manage</span>
      </a>
    <?php endif; ?>

    <?php $refresh_q = '?' . h(http_build_query($_GET)); ?>
    <a href="<?= $refresh_q ?>" class="btn btn-outline-secondary btn-sm" data-bs-toggle="tooltip" title="Refresh">
      <i class="bi bi-arrow-clockwise"></i>
    </a>
  </div>
</div>

<!-- Balances Table -->
<div class="card shadow-sm mt-3">
  <div class="card-header">Balances = Payments − Approved Settlements</div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-striped align-middle">
        <thead class="table-light">
          <tr>
            <th>User</th>
            <th class="text-end">Payments</th>
            <th class="text-end">Settled Out</th>
            <th class="text-end">Settled In</th>
            <th class="text-end">Balance</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$users): ?>
          <tr><td colspan="6" class="text-center text-muted">No users.</td></tr>
        <?php else: foreach ($users as $uid => $uname):
          $bal = $balances[$uid] ?? ['paid_sum'=>null,'settled_out'=>null,'settled_in'=>null,'balance'=>null];
          $q   = $_GET; $q['user_id'] = $uid; $q['wallet_id']=0; $link = '?' . h(http_build_query($q));
        ?>
          <tr>
            <td><?= h($uname) ?></td>
            <?php if ($bal['paid_sum'] === null): ?>
              <td class="text-end text-muted">-</td>
              <td class="text-end text-muted">-</td>
              <td class="text-end text-muted">-</td>
              <td class="text-end text-muted">-</td>
            <?php else:
              $val = (float)$bal['balance'];
              $balClass = $val < 0 ? 'text-danger' : ($val > 0 ? 'text-success' : 'text-muted');
            ?>
              <td class="text-end"><?= number_format((float)$bal['paid_sum'], 2) ?></td>
              <td class="text-end"><?= number_format((float)$bal['settled_out'], 2) ?></td>
              <td class="text-end"><?= number_format((float)$bal['settled_in'], 2) ?></td>
              <td class="text-end fw-semibold <?= $balClass ?>"><?= number_format($val, 2) ?></td>
            <?php endif; ?>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-primary" href="<?= $link ?>">View</a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
        <tfoot class="table-light">
          <tr class="table-secondary">
            <th>Total</th>
            <th class="text-end"><?= number_format($grand['paid_sum'], 2) ?></th>
            <th class="text-end"><?= number_format($grand['settled_out'], 2) ?></th>
            <th class="text-end"><?= number_format($grand['settled_in'], 2) ?></th>
            <th class="text-end fw-bold"><?= number_format($grand['balance'], 2) ?></th>
            <th></th>
          </tr>
        </tfoot>
      </table>
    </div>
    <div class="small text-muted">
      Totals respect the selected date range and include only <strong>approved</strong> settlements.
    </div>
    <div class="mt-2 small">
      <span class="text-success">Positive = wallet holds cash</span>,
      <span class="text-danger">Negative = needs settlement</span>.
    </div>
  </div>
</div>

<!-- Filters Form -->
<form class="row g-2 mt-4 mb-2" method="get">
  <div class="col-md-3">
    <label class="form-label">User</label>
    <select name="user_id" class="form-select" onchange="this.form.submit()">
      <option value="0">All</option>
      <?php foreach ($users as $uid => $uname): ?>
        <option value="<?= (int)$uid ?>" <?= ($user_id === (int)$uid) ? 'selected' : '' ?>><?= h($uname) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-3">
    <label class="form-label">Wallet</label>
    <?php if ($user_id > 0): $uw = $accounts_by_user[$user_id] ?? []; ?>
      <select name="wallet_id" class="form-select" <?= $user_id ? '' : 'disabled' ?> onchange="this.form.submit()">
        <option value="0" <?= $wallet_id===0?'selected':''; ?>>All wallets</option>
        <?php foreach ($uw as $w): ?>
          <option value="<?= (int)$w['id'] ?>" <?= $wallet_id===(int)$w['id']?'selected':''; ?>>
            #<?= (int)$w['id'] ?> — <?= h($w['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    <?php else: ?>
      <select class="form-select" disabled>
        <option>Select user first</option>
      </select>
    <?php endif; ?>
  </div>
  <div class="col-md-2">
    <label class="form-label">From</label>
    <input type="date" name="date_from" value="<?= h($date_from) ?>" class="form-control">
  </div>
  <div class="col-md-2">
    <label class="form-label">To</label>
    <input type="date" name="date_to" value="<?= h($date_to) ?>" class="form-control">
  </div>
  <div class="col-md-2 d-flex align-items-end gap-2">
    <button class="btn btn-outline-secondary"><i class="bi bi-funnel"></i> Filter</button>
    <a class="btn btn-outline-dark" href="/public/wallets.php"><i class="bi bi-x-circle"></i> Reset</a>
  </div>
</form>

<!-- Transactions Table -->
<div class="card shadow-sm">
  <div class="card-header">
    Transactions
    <?php
      $hdr_parts = [];
      if ($user_id > 0 && isset($users[$user_id])) $hdr_parts[] = h($users[$user_id]);
      if ($wallet_id > 0 && isset($wallet_meta[$wallet_id])) $hdr_parts[] = 'Wallet: ' . h($wallet_meta[$wallet_id]['name']) . ' (#' . (int)$wallet_id . ')';
      echo $hdr_parts ? (' — ' . implode(' | ', $hdr_parts)) : '';
    ?>
  </div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-striped align-middle">
        <thead class="table-light">
          <tr>
            <th style="width:120px;">Date</th>
            <th>Invoice</th>
            <th>Client</th>
            <th>Wallet</th>
            <th class="text-end">Amount</th>
            <th>Method</th>
            <th>Txn</th>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8" class="text-center text-muted">No transactions.</td></tr>
        <?php else: foreach ($rows as $r):
          $d = $has_paid_at ? ($r['paid_at'] ?? '') : ($r['created_at'] ?? '');
          $acc_label = ($r['acc_name'] ?? '') !== '' ? (string)$r['acc_name'] : ('Account#' . (int)($r['account_id'] ?? 0));
        ?>
          <tr>
            <td><?= h($d) ?></td>
            <td>
              <?php if (!empty($r['inv_id'])): ?>
                <a href="/public/invoice_view.php?id=<?= (int)$r['inv_id'] ?>">#<?= (int)$r['inv_id'] ?></a>
                <span class="badge bg-secondary"><?= h((string)($r['inv_status'] ?? '')) ?></span>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td><?= h(trim((string)($r['pppoe_id'] ?? ''))) ?><?= $r['client_name'] ? ' — ' . h((string)$r['client_name']) : '' ?></td>
            <td><?= h($acc_label) ?></td>
            <td class="text-end fw-semibold"><?= number_format((float)$r['amount'], 2) ?></td>
            <td><?= h((string)($r['method'] ?? '')) ?></td>
            <td><?= h((string)($r['txn_id'] ?? '')) ?></td>
            <td><?= h((string)($r['notes'] ?? '')) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <?php
    // Pagination – ensure window size does not exceed total pages
    $win = 5;
    if ($total_pages <= $win) {
        $start = 1;
        $end   = $total_pages;
    } else {
        $start = max(1, $page - intdiv($win - 1, 2));
        $end   = min($total_pages, $start + $win - 1);
        if ($end - $start + 1 < $win) {
            $start = max(1, $end - $win + 1);
        }
    }

    // বাংলা: Pagination URLs – XSS-safe
    $q = $_GET; $q['page'] = 1;                              $first = '?' . h(http_build_query($q));
    $q = $_GET; $q['page'] = max(1, $page-1);                $prev  = '?' . h(http_build_query($q));
    $q = $_GET; $q['page'] = min($total_pages, $page+1);     $next  = '?' . h(http_build_query($q));
    $q = $_GET; $q['page'] = $total_pages;                   $last  = '?' . h(http_build_query($q));
    ?>
    <nav aria-label="pagination">
      <ul class="pagination justify-content-center">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
          <a class="page-link" href="<?= $first ?>">&laquo;</a>
        </li>
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
          <a class="page-link" href="<?= $prev ?>">Prev</a>
        </li>
        <?php for ($i = $start; $i <= $end; $i++):
          $q = $_GET; $q['page'] = $i; $url = '?' . h(http_build_query($q)); ?>
          <li class="page-item <?= $i === $page ? 'active' : '' ?>">
            <a class="page-link" href="<?= $url ?>"><?= (int)$i ?></a>
          </li>
        <?php endfor; ?>
        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
          <a class="page-link" href="<?= $next ?>">Next</a>
        </li>
        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
          <a class="page-link" href="<?= $last ?>">&raquo;</a>
        </li>
      </ul>
    </nav>
  </div>
</div>

<!-- Tooltip init -->
<script>
(function(){
  function initTips(){
    if (!window.bootstrap || !bootstrap.Tooltip) return false;
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el){
      try { new bootstrap.Tooltip(el); } catch(e){}
    });
    return true;
  }
  if (document.readyState !== 'loading') {
    if (!initTips()) window.addEventListener('load', initTips, {once:true});
  } else {
    document.addEventListener('DOMContentLoaded', function(){
      if (!initTips()) window.addEventListener('load', initTips, {once:true});
    });
  }
})();
</script>

<?php include __DIR__ . '/../partials/partials_footer.php';
