<?php
// /public/portal/tickets.php
// Client Portal — Tickets list (nice UI + sidebar + filters + pagination)

declare(strict_types=1);
require_once __DIR__ . '/../../app/portal_require_login.php';
require_once __DIR__ . '/../../app/db.php';

function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------- schema helpers ---------- */
function col_exists(PDO $pdo, string $tbl, string $col): bool {
  try{
    $st=$pdo->prepare("SHOW COLUMNS FROM `$tbl` LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetchColumn();
  }catch(Throwable $e){ return false; }
}
$has_status     = col_exists($pdo,'tickets','status');
$has_created_at = col_exists($pdo,'tickets','created_at');
$has_updated_at = col_exists($pdo,'tickets','updated_at');

/* ---------- inputs ---------- */
$client_id = (int) portal_client_id();
$search    = trim((string)($_GET['q'] ?? ''));
$status    = trim((string)($_GET['status'] ?? ''));
$page      = max(1, (int)($_GET['page'] ?? 1));
$limit     = 20;
$offset    = ($page-1)*$limit;

/* ---------- where ---------- */
$where = ["client_id = ?"];
$args  = [$client_id];

if ($search !== '') {
  $where[] = "(subject LIKE ? OR message LIKE ?)";
  $args[]  = '%'.$search.'%';
  $args[]  = '%'.$search.'%';
}
if ($status !== '' && $has_status) {
  $where[] = "LOWER(status) = ?";
  $args[]  = strtolower($status);
}
$where_sql = 'WHERE '.implode(' AND ', $where);

/* ---------- ordering ---------- */
$order_col = $has_created_at ? 'created_at' : 'id';
$order_sql = $order_col.' DESC';

/* ---------- count ---------- */
$stc = $pdo->prepare("SELECT COUNT(*) FROM tickets $where_sql");
$stc->execute($args);
$total_rows  = (int)$stc->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows/$limit));

/* ---------- fetch page ---------- */
$sql = "SELECT id, subject, ".($has_status?'COALESCE(status, \'\') AS status':'\'\' AS status').",
               ".($has_created_at?'created_at':'NULL AS created_at').",
               ".($has_updated_at?'updated_at':'NULL AS updated_at')."
        FROM tickets
        $where_sql
        ORDER BY $order_sql
        LIMIT $limit OFFSET $offset";
$st = $pdo->prepare($sql);
$st->execute($args);
$tickets = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* ---------- ui helpers ---------- */
function status_badge(string $s): string {
  $k = strtolower(trim($s));
  return match($k){
    'open'     => 'badge text-bg-primary',
    'pending'  => 'badge text-bg-warning text-dark',
    'closed'   => 'badge text-bg-secondary',
    'resolved' => 'badge text-bg-success',
    default    => 'badge text-bg-light text-dark'
  };
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>My Support Tickets</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  body{ background: linear-gradient(180deg,#f7f9fc 0%, #eef3ff 100%); }
  .navbar-dark { background: linear-gradient(90deg, #111 0%, #1b1f2a 100%); }
  .page-wrap{ display:flex; }
  .sidebar-wrap{ min-width:260px; background:linear-gradient(180deg,#eef5ff,#ffffff); border-right:1px solid #e9edf3; }
  .sidebar-inner{ padding:16px; position:sticky; top:0; height:100vh; overflow:auto; }
  .content{ flex:1; min-width:0; padding:1rem; }
  .card-soft{ border:1px solid #e9edf3; border-radius:14px; box-shadow:0 10px 30px rgba(0,0,0,.05); }
  .card-header.soft{ background: linear-gradient(90deg, rgba(99,91,255,.12), rgba(34,166,240,.12)); border-bottom: 1px solid #e9edf8; }
  .table thead th{ white-space:nowrap; }
  .search-chip{ display:inline-flex; align-items:center; gap:.4rem; border:1px solid #e5e7eb; border-radius:999px; padding:.3rem .6rem; background:#fff; }
</style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark">
  <div class="container">
    <a class="navbar-brand fw-bold" href="/public/portal/index.php">
      <i class="bi bi-life-preserver me-1"></i> Support
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nv">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div id="nv" class="collapse navbar-collapse justify-content-end">
      <ul class="navbar-nav">
        <li class="nav-item"><a class="nav-link" href="/public/portal/ticket_new.php"><i class="bi bi-plus-circle"></i> New Ticket</a></li>
      </ul>
    </div>
  </div>
</nav>

<div class="page-wrap">
  <?php
    // Sidebar include (portal_sidebar.php → sidebar.php)
    $sb1 = __DIR__ . '/portal_sidebar.php';
    $sb2 = __DIR__ . '/sidebar.php';
    echo '<div class="sidebar-wrap d-none d-md-block"><div class="sidebar-inner">';
    if (is_file($sb1)) include $sb1; elseif (is_file($sb2)) include $sb2;
    echo '</div></div>';
  ?>

  <div class="content">
    <div class="container-fluid px-0">

      <?php if (!empty($_GET['created'])): ?>
        <div class="alert alert-success d-flex align-items-center" role="alert">
          <i class="bi bi-check2-circle me-2"></i>
          <div>Ticket created successfully.</div>
        </div>
      <?php endif; ?>

      <div class="card card-soft mb-3">
        <div class="card-header soft d-flex align-items-center justify-content-between">
          <div class="fw-bold"><i class="bi bi-card-list me-1"></i> My Tickets</div>
          <a href="/public/portal/ticket_new.php" class="btn btn-sm btn-primary"><i class="bi bi-plus-circle"></i> New Ticket</a>
        </div>
        <div class="card-body">
          <form class="row g-2 mb-3" method="get">
            <div class="col-sm-6">
              <label class="form-label mb-1">Search</label>
              <input type="text" name="q" value="<?= h($search) ?>" class="form-control" placeholder="Subject / message">
            </div>
            <div class="col-sm-3">
              <label class="form-label mb-1">Status</label>
              <select name="status" class="form-select">
                <option value="">All</option>
                <?php foreach (['open','pending','resolved','closed'] as $opt): ?>
                  <option value="<?= h($opt) ?>" <?= $status===$opt?'selected':'' ?>><?= ucfirst($opt) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-3 d-flex align-items-end">
              <button class="btn btn-outline-secondary me-2"><i class="bi bi-funnel"></i> Filter</button>
              <a class="btn btn-outline-dark" href="/public/portal/tickets.php"><i class="bi bi-x-circle"></i> Reset</a>
            </div>
          </form>

          <?php if ($search || $status): ?>
            <div class="mb-2">
              <span class="search-chip"><i class="bi bi-filter"></i> Showing filtered results</span>
            </div>
          <?php endif; ?>

          <?php if ($tickets): ?>
            <div class="table-responsive">
              <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th style="width:80px">ID</th>
                    <th>Subject</th>
                    <th style="width:130px">Status</th>
                    <th style="width:180px"><?= $has_created_at?'Created':'Ref' ?></th>
                    <?php if ($has_updated_at): ?><th style="width:180px">Updated</th><?php endif; ?>
                    <th style="width:110px" class="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($tickets as $t): ?>
                    <?php
                      $id     = (int)$t['id'];
                      $sub    = (string)($t['subject'] ?? '');
                      $sttxt  = (string)($t['status'] ?? '');
                      $cdate  = (string)($t['created_at'] ?? '');
                      $udate  = (string)($t['updated_at'] ?? '');
                      $badge  = status_badge($sttxt);
                      $view   = '/public/portal/ticket_view.php?id='.$id;
                    ?>
                    <tr id="t<?= $id ?>">
                      <td class="text-muted">#<?= $id ?></td>
                      <td>
                        <a href="<?= h($view) ?>" class="link-dark text-decoration-none fw-semibold"><?= h($sub) ?></a>
                      </td>
                      <td><span class="<?= h($badge) ?>"><?= h($sttxt!==''?ucfirst($sttxt):'—') ?></span></td>
                      <td><?= h($cdate ?: '—') ?></td>
                      <?php if ($has_updated_at): ?><td><?= h($udate ?: '—') ?></td><?php endif; ?>
                      <td class="text-end">
                        <a href="<?= h($view) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i> View</a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <?php if ($total_pages > 1): ?>
              <div class="pt-3">
                <nav aria-label="Page">
                  <ul class="pagination pagination-sm mb-0">
                    <?php
                      $q = $_GET;
                      for ($p=1; $p<=$total_pages; $p++){
                        $q['page'] = $p;
                        $url = '?'.http_build_query($q);
                        $active = ($p===$page)?'active':'';
                        echo '<li class="page-item '.$active.'"><a class="page-link" href="'.h($url).'">'.$p.'</a></li>';
                      }
                    ?>
                  </ul>
                </nav>
              </div>
            <?php endif; ?>

          <?php else: ?>
            <div class="text-center text-muted py-4">
              <i class="bi bi-inbox" style="font-size:2rem"></i>
              <div class="mt-2">No tickets found.</div>
              <a class="btn btn-sm btn-primary mt-2" href="/public/portal/ticket_new.php"><i class="bi bi-plus-circle"></i> Create Ticket</a>
            </div>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
