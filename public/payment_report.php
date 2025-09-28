<?php
// /public/payment_report.php
// (বাংলা) Payments Report — ফিল্টার + পেজিনেশন + CSV এক্সপোর্ট

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$from   = $_GET['from'] ?? date('Y-m-01');
$to     = $_GET['to']   ?? date('Y-m-d');
$method = trim($_GET['method'] ?? '');
$client = (int)($_GET['client_id'] ?? 0);
$qtext  = trim($_GET['q'] ?? ''); // invoice id / txn / remarks search
$sort   = $_GET['sort'] ?? 'paid_at';
$dir    = strtolower($_GET['dir'] ?? 'desc');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = max(1, min(200, (int)($_GET['limit'] ?? 50)));
$offset = ($page-1)*$limit;

$allowSort = ['paid_at','amount','method','client_name','invoice_id'];
if(!in_array($sort,$allowSort,true)) $sort='paid_at';
$dir = $dir==='asc'?'asc':'desc';

$w=[]; $p=[];
if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)) { $w[]="DATE(p.paid_at) >= ?"; $p[]=$from; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$to))   { $w[]="DATE(p.paid_at) <= ?"; $p[]=$to; }
if ($method!==''){ $w[]="p.method = ?"; $p[]=$method; }
if ($client>0)   { $w[]="p.client_id = ?"; $p[]=$client; }
if ($qtext!==''){ 
  $w[]="(p.txn_id LIKE ? OR p.remarks LIKE ? OR p.invoice_id = ?)";
  $p[]="%$qtext%"; $p[]="%$qtext%"; $p[]=(int)$qtext;
}
$where = $w?('WHERE '.implode(' AND ',$w)):'';

$pdo = db();

/* total rows */
$stc = $pdo->prepare("SELECT COUNT(*) FROM payments p $where");
$stc->execute($p);
$total = (int)$stc->fetchColumn();

/* page rows */
$sql = "SELECT p.*, c.name AS client_name
        FROM payments p
        LEFT JOIN clients c ON c.id = p.client_id
        $where
        ORDER BY $sort $dir, p.id $dir
        LIMIT $limit OFFSET $offset";
$st = $pdo->prepare($sql);
$st->execute($p);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

/* summary */
$sts = $pdo->prepare("SELECT COALESCE(SUM(p.amount),0) FROM payments p $where");
$sts->execute($p);
$sum_amount = (float)$sts->fetchColumn();

/* group by day for quick view */
$gsql = "SELECT DATE(p.paid_at) d, COUNT(*) n, SUM(p.amount) s
         FROM payments p $where GROUP BY DATE(p.paid_at) ORDER BY d ASC";
$sg = $pdo->prepare($gsql);
$sg->execute($p);
$groups = $sg->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../partials/partials_header.php';
?>
<div class="container-fluid py-3">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0">Payments Report</h4>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-dark btn-sm" target="_blank" rel="noopener"
         href="/public/payment_report_export.php?<?= h(http_build_query($_GET)) ?>">
        <i class="bi bi-download"></i> Export CSV
      </a>
    </div>
  </div>

  <!-- Filters -->
  <form class="row g-2 mb-3" method="get">
    <div class="col-6 col-md-2">
      <label class="form-label small text-muted">From</label>
      <input type="date" name="from" value="<?= h($from) ?>" class="form-control form-control-sm">
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small text-muted">To</label>
      <input type="date" name="to" value="<?= h($to) ?>" class="form-control form-control-sm">
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small text-muted">Method</label>
      <select name="method" class="form-select form-select-sm">
        <?php $opts=['','Cash','bKash','Nagad','Bank','Online']; foreach($opts as $op): ?>
          <option value="<?= h($op) ?>" <?= $op===$method?'selected':'' ?>><?= $op?:'All' ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small text-muted">Client ID</label>
      <input type="number" name="client_id" value="<?= $client?:'' ?>" class="form-control form-control-sm" placeholder="e.g. 12">
    </div>
    <div class="col-12 col-md-3">
      <label class="form-label small text-muted">Search</label>
      <input name="q" value="<?= h($qtext) ?>" class="form-control form-control-sm" placeholder="Invoice/Txn/Remarks">
    </div>
    <div class="col-12 col-md-1 d-grid">
      <label class="form-label small text-muted">&nbsp;</label>
      <button class="btn btn-primary btn-sm"><i class="bi bi-search"></i> Filter</button>
    </div>
  </form>

  <div class="alert alert-light border">
    <div class="d-flex flex-wrap align-items-center gap-3">
      <div><span class="badge bg-primary">Total Amount</span> <strong><?= number_format($sum_amount,2) ?></strong></div>
      <div><span class="badge bg-secondary">Rows</span> <?= number_format($total) ?></div>
      <div class="ms-auto small text-muted">Period: <?= h($from) ?> → <?= h($to) ?></div>
    </div>
  </div>

  <!-- Group by day -->
  <?php if($groups): ?>
  <div class="table-responsive mb-3">
    <table class="table table-sm table-striped align-middle">
      <thead class="table-light">
        <tr><th>Date</th><th class="text-end">Count</th><th class="text-end">Amount</th></tr>
      </thead>
      <tbody>
        <?php foreach($groups as $g): ?>
        <tr>
          <td class="mono"><?= h($g['d']) ?></td>
          <td class="text-end"><?= (int)$g['n'] ?></td>
          <td class="text-end"><?= number_format((float)$g['s'],2) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Rows -->
  <div class="table-responsive">
    <table class="table table-sm table-striped align-middle">
      <thead class="table-light">
        <tr>
          <?php
            // sort links
            function s($title,$col){ $q=$_GET; $cur=$q['sort']??'paid_at'; $dir=strtolower($q['dir']??'desc'); $q['sort']=$col; $q['dir']=($cur===$col&&$dir==='asc')?'desc':'asc'; $ic=''; if($cur===$col) $ic=$dir==='asc'?'▲':'▼'; return '<a href="?'.h(http_build_query($q)).'">'.h($title).' '.$ic.'</a>'; }
          ?>
          <th><?= s('Paid At','paid_at') ?></th>
          <th>Client</th>
          <th><?= s('Invoice','invoice_id') ?></th>
          <th><?= s('Method','method') ?></th>
          <th class="text-end"><?= s('Amount','amount') ?></th>
          <th>Remarks</th>
        </tr>
      </thead>
      <tbody>
        <?php if(!$rows): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No payments found</td></tr>
        <?php else: foreach($rows as $r): ?>
          <tr>
            <td class="mono"><?= h($r['paid_at']) ?></td>
            <td><a href="/public/client_view.php?id=<?= (int)$r['client_id'] ?>" target="_blank" rel="noopener"><?= h($r['client_name'] ?: ('#'.$r['client_id'])) ?></a></td>
            <td class="mono">
              <?php if(!empty($r['invoice_id'])): ?>
                <a href="/public/invoice_view.php?id=<?= (int)$r['invoice_id'] ?>" target="_blank" rel="noopener">#<?= (int)$r['invoice_id'] ?></a>
              <?php else: ?>-<?php endif; ?>
            </td>
            <td><?= h($r['method'] ?: '-') ?></td>
            <td class="text-end"><?= number_format((float)$r['amount'],2) ?></td>
            <td class="text-break"><?= nl2br(h($r['remarks'] ?? '')) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php
    $pages = max(1, (int)ceil($total / $limit));
    if($pages>1):
      $q = $_GET;
  ?>
  <nav>
    <ul class="pagination pagination-sm">
      <?php for($i=1;$i<=$pages;$i++): $q['page']=$i; $u='?'.http_build_query($q); ?>
        <li class="page-item <?= $i===$page?'active':'' ?>"><a class="page-link" href="<?= h($u) ?>"><?= $i ?></a></li>
      <?php endfor; ?>
    </ul>
  </nav>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/../partials/partials_footer.php'; ?>
