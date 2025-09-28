<?php
// /public/invoice_generate.php
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ------- Inputs (default: current month) -------
$year  = (int)($_REQUEST['year']  ?? date('Y'));
$month = (int)($_REQUEST['month'] ?? date('n')); // 1..12
$year  = ($year < 2000 || $year > 2100) ? (int)date('Y') : $year;
$month = ($month < 1 || $month > 12)    ? (int)date('n') : $month;

// previous month (for carry-forward)
$pm = $month - 1; $py = $year;
if ($pm <= 0) { $pm = 12; $py = $year - 1; }

// UI flags
$did_run  = ($_SERVER['REQUEST_METHOD'] === 'POST');
$results  = [];
$summary  = ['created'=>0,'skipped'=>0,'errors'=>0];

if ($did_run) {
  try{
    db()->beginTransaction();

    // Load all active clients (not deleted)
    $clients = db()->query("SELECT c.id, c.name, c.client_code, c.pppoe_id, c.package_id, c.monthly_bill
                            FROM clients c
                            WHERE c.status='active' AND (c.is_deleted IS NULL OR c.is_deleted=0)
                            ORDER BY c.id ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Packages map (id => price)
    $pkgMap = [];
    $pkg = db()->query("SELECT id, price FROM packages")->fetchAll(PDO::FETCH_ASSOC);
    foreach($pkg as $p){ $pkgMap[(int)$p['id']] = (float)($p['price'] ?? 0); }

    // Prep statements
    $stmtExists = db()->prepare("SELECT id FROM invoices WHERE client_id=? AND month=? AND year=? LIMIT 1");
    $stmtPrev   = db()->prepare("SELECT due FROM invoices WHERE client_id=? AND month=? AND year=? ORDER BY id DESC LIMIT 1");
    $stmtIns    = db()->prepare("
      INSERT INTO invoices
        (client_id, month, year, amount, total, payable, paid_amount, discount_total, due, status, invoice_no, created_at)
      VALUES
        (?, ?, ?, ?, ?, ?, 0, 0, ?, 'due', ?, NOW())
    ");

    foreach($clients as $c){
      $cid   = (int)$c['id'];
      $cname = (string)($c['name'] ?? '');
      $ccode = (string)($c['client_code'] ?? '');
      $pppoe = (string)($c['pppoe_id'] ?? '');

      // already this month?
      $stmtExists->execute([$cid, $month, $year]);
      $existsRow = $stmtExists->fetch(PDO::FETCH_ASSOC);
      if ($existsRow){
        $results[] = ['client_id'=>$cid, 'name'=>$cname, 'client_code'=>$ccode, 'status'=>'skipped', 'reason'=>'invoice exists'];
        $summary['skipped']++;
        continue;
      }

      // current month amount (monthly_bill or package price)
      $amt = (float)($c['monthly_bill'] ?? 0);
      if ($amt <= 0 && !empty($c['package_id'])) {
        $amt = (float)($pkgMap[(int)$c['package_id']] ?? 0);
      }
      $amt = round($amt, 2);

      // last month due (carry-forward)
      $stmtPrev->execute([$cid, $pm, $py]);
      $prevRow = $stmtPrev->fetch(PDO::FETCH_ASSOC);
      $prev_due = round((float)($prevRow['due'] ?? 0), 2);
      if ($prev_due < 0) $prev_due = 0.0;

      $payable = round($amt + $prev_due, 2);
      $due     = $payable;

      // invoice no: INV-YYYYMM-CLIENTID
      $invno = sprintf('INV-%04d%02d-%d', $year, $month, $cid);

      // insert
      $ok = $stmtIns->execute([$cid, $month, $year, $amt, $amt, $payable, $due, $invno]);
      if (!$ok){
        $results[] = ['client_id'=>$cid, 'name'=>$cname, 'client_code'=>$ccode, 'status'=>'error', 'reason'=>'insert failed'];
        $summary['errors']++;
        continue;
      }

      $summary['created']++;
      $results[] = [
        'client_id'=>$cid,
        'name'=>$cname,
        'client_code'=>$ccode,
        'status'=>'created',
        'amount'=>$amt,
        'carry'=>$prev_due,
        'due'=>$due,
        'invoice_no'=>$invno
      ];
    }

    db()->commit();
  }catch(Throwable $e){
    db()->rollBack();
    $summary['errors']++;
    $results[] = ['client_id'=>0, 'name'=>'—', 'client_code'=>'', 'status'=>'error', 'reason'=>$e->getMessage()];
  }
}

include __DIR__ . '/../partials/partials_header.php';
?>
<div class="container-fluid py-3">
  <div class="d-flex align-items-center mb-3">
    <h5 class="mb-0">Generate Monthly Invoices</h5>
    <div class="ms-auto small text-muted">Create: current month bills + carry last month due</div>
  </div>

  <form method="post" class="card shadow-sm mb-3">
    <div class="card-body row g-3 align-items-end">
      <div class="col-6 col-md-3">
        <label class="form-label">Month</label>
        <select name="month" class="form-select">
          <?php for($m=1;$m<=12;$m++): ?>
            <option value="<?=$m?>" <?=$m==$month?'selected':''?>><?=date('F', mktime(0,0,0,$m,1))?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Year</label>
        <input type="number" class="form-control" name="year" value="<?=$year?>">
      </div>
      <div class="col-12 col-md-3 d-grid">
        <button type="submit" class="btn btn-primary"><i class="bi bi-gear"></i> Generate</button>
      </div>
    </div>
  </form>

  <?php if ($did_run): ?>
    <div class="card shadow-sm">
      <div class="card-header bg-light">
        <strong>Result</strong>
        <span class="ms-2 badge bg-success">Created: <?=$summary['created']?></span>
        <span class="ms-1 badge bg-secondary">Skipped: <?=$summary['skipped']?></span>
        <span class="ms-1 badge bg-danger">Errors: <?=$summary['errors']?></span>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>#</th>
                <th>Client</th>
                <th>Client Code</th>
                <th>Status</th>
                <th class="text-end">Amount</th>
                <th class="text-end">Carry (Last Mo)</th>
                <th class="text-end">Due (New)</th>
                <th>Invoice No</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($results)): ?>
                <tr><td colspan="8" class="text-center text-muted py-3">No changes.</td></tr>
              <?php else: $i=1; foreach($results as $r): ?>
                <tr>
                  <td><?=$i++?></td>
                  <td><?=h($r['name']??'—')?></td>
                  <td><?=h($r['client_code']??'')?></td>
                  <td>
                    <?php
                      $s = $r['status']??'';
                      $badge = ($s==='created')?'bg-success':(($s==='skipped')?'bg-secondary':(($s==='error')?'bg-danger':'bg-light'));
                    ?>
                    <span class="badge <?=$badge?>"><?=h(strtoupper($s))?></span>
                    <?php if (!empty($r['reason'])): ?>
                      <small class="text-muted ms-1"><?=h($r['reason'])?></small>
                    <?php endif; ?>
                  </td>
                  <td class="text-end"><?=isset($r['amount'])?number_format((float)$r['amount'],2):'—'?></td>
                  <td class="text-end"><?=isset($r['carry'])?number_format((float)$r['carry'],2):'—'?></td>
                  <td class="text-end"><?=isset($r['due'])?number_format((float)$r['due'],2):'—'?></td>
                  <td><?=h($r['invoice_no']??'—')?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../partials/partials_footer.php'; ?>
