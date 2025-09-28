<?php
// /public/due_analytics.php
// Purpose: Visualize Due (ledger_balance > 0) by Router and Area
// Features: router-wise bar, area-wise top-20 bar, router×area color-matrix
// Notes: UI English; কমেন্টগুলো বাংলায়

declare(strict_types=1);
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// (বাংলা) Filters: optional area/package/router (consistency with other pages)
$routerId = (int)($_GET['router_id'] ?? 0);
$packageId= (int)($_GET['package_id'] ?? 0);
$area     = trim($_GET['area'] ?? '');
$minDue   = trim($_GET['min_due'] ?? ''); // ঐচ্ছিক
$maxDue   = trim($_GET['max_due'] ?? '');

$where=["c.ledger_balance > 0"]; $params=[];
if ($routerId>0){ $where[]="c.router_id = ?";  $params[]=$routerId; }
if ($packageId>0){$where[]="c.package_id = ?"; $params[]=$packageId; }
if ($area!==''){ $where[]="c.area = ?";        $params[]=$area; }
if ($minDue!=='' && is_numeric($minDue)){ $where[]="c.ledger_balance >= ?"; $params[]=(float)$minDue; }
if ($maxDue!=='' && is_numeric($maxDue)){ $where[]="c.ledger_balance <= ?"; $params[]=(float)$maxDue; }
$where_sql = 'WHERE '.implode(' AND ', $where);

// (বাংলা) preload dropdown
$routers = $pdo->query("SELECT id,name FROM routers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$packages= $pdo->query("SELECT id,name FROM packages ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$areas   = $pdo->query("SELECT DISTINCT area FROM clients WHERE area<>'' ORDER BY area LIMIT 200")->fetchAll(PDO::FETCH_COLUMN);

// Router-wise due
$sqlR = "SELECT r.name AS router, SUM(c.ledger_balance) AS due
         FROM clients c LEFT JOIN routers r ON r.id=c.router_id
         $where_sql
         GROUP BY r.name
         HAVING due > 0
         ORDER BY due DESC";
$stR = $pdo->prepare($sqlR); $stR->execute($params);
$rowsR = $stR->fetchAll(PDO::FETCH_ASSOC);

// Area-wise due (top 20)
$sqlA = "SELECT c.area AS area, SUM(c.ledger_balance) AS due
         FROM clients c
         $where_sql
         GROUP BY c.area
         HAVING area IS NOT NULL AND area<>'' AND due > 0
         ORDER BY due DESC
         LIMIT 20";
$stA = $pdo->prepare($sqlA); $stA->execute($params);
$rowsA = $stA->fetchAll(PDO::FETCH_ASSOC);

// Router × Area matrix (top routers x top areas)
$topRouters = array_slice(array_map(fn($r)=>$r['router'], $rowsR), 0, 8);
$topAreas   = array_slice(array_map(fn($a)=>$a['area'], $rowsA),   0, 8);

$matrix = [];
if ($topRouters && $topAreas) {
  $inR = implode(',', array_fill(0, count($topRouters), '?'));
  $inA = implode(',', array_fill(0, count($topAreas),   '?'));
  $sqlM = "SELECT r.name AS router, c.area AS area, SUM(c.ledger_balance) AS due
           FROM clients c
           LEFT JOIN routers r ON r.id=c.router_id
           WHERE c.ledger_balance > 0
             AND r.name IN ($inR)
             AND c.area IN ($inA)
           GROUP BY r.name, c.area";
  $stM = $pdo->prepare($sqlM);
  $stM->execute(array_merge($topRouters, $topAreas));
  foreach ($stM->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $matrix[$row['router']][$row['area']] = (float)$row['due'];
  }
}

// stats
$sqlS = "SELECT COUNT(*) AS cnt, SUM(c.ledger_balance) AS total_due, AVG(c.ledger_balance) AS avg_due, MAX(c.ledger_balance) AS max_due
         FROM clients c $where_sql";
$stS=$pdo->prepare($sqlS); $stS->execute($params);
$stat=$stS->fetch(PDO::FETCH_ASSOC) ?: ['cnt'=>0,'total_due'=>0,'avg_due'=>0,'max_due'=>0];

$page_title = 'Due Analytics';
include __DIR__ . '/../partials/partials_header.php';
?>
<div class="container py-3">
  <h4 class="mb-3">Due Analytics</h4>
  <form class="row g-2 align-items-end mb-3" method="get">
    <div class="col-sm-3">
      <label class="form-label">Router</label>
      <select class="form-select" name="router_id">
        <option value="0">All</option>
        <?php foreach($routers as $r): ?>
          <option value="<?php echo (int)$r['id']; ?>" <?php echo ($routerId===(int)$r['id']?'selected':''); ?>>
            <?php echo h($r['name']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-sm-3">
      <label class="form-label">Package</label>
      <select class="form-select" name="package_id">
        <option value="0">All</option>
        <?php foreach($packages as $p): ?>
          <option value="<?php echo (int)$p['id']; ?>" <?php echo ($packageId===(int)$p['id']?'selected':''); ?>>
            <?php echo h($p['name']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-sm-3">
      <label class="form-label">Area</label>
      <input list="areaList" class="form-control" name="area" value="<?php echo h($area); ?>" placeholder="Area">
      <datalist id="areaList">
        <?php foreach($areas as $a): ?><option value="<?php echo h($a); ?>"></option><?php endforeach; ?>
      </datalist>
    </div>
    <div class="col-sm-1">
      <label class="form-label">Min Due</label>
      <input type="number" step="0.01" class="form-control" name="min_due" value="<?php echo h($minDue); ?>">
    </div>
    <div class="col-sm-1">
      <label class="form-label">Max Due</label>
      <input type="number" step="0.01" class="form-control" name="max_due" value="<?php echo h($maxDue); ?>">
    </div>
    <div class="col-sm-1">
      <button class="btn btn-primary w-100">Apply</button>
    </div>
    <div class="col-sm-1">
      <a class="btn btn-outline-secondary w-100" href="/public/due_analytics.php">Reset</a>
    </div>
  </form>

  <div class="d-flex gap-2 mb-3">
    <span class="badge bg-dark">Due Clients: <?php echo (int)$stat['cnt']; ?></span>
    <span class="badge bg-danger">Total Due: <?php echo number_format((float)$stat['total_due'],2); ?></span>
    <span class="badge bg-secondary">Avg Due: <?php echo number_format((float)$stat['avg_due'],2); ?></span>
    <span class="badge bg-warning text-dark">Max Due: <?php echo number_format((float)$stat['max_due'],2); ?></span>
  </div>

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card">
        <div class="card-body">
          <h6 class="mb-2">By Router (Total Due)</h6>
          <canvas id="routerChart" height="150"></canvas>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card">
        <div class="card-body">
          <h6 class="mb-2">Top Areas (Total Due)</h6>
          <canvas id="areaChart" height="150"></canvas>
        </div>
      </div>
    </div>
  </div>

  <div class="card mt-3">
    <div class="card-body">
      <h6 class="mb-2">Router × Area Matrix (Top)</h6>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead class="table-light">
            <tr>
              <th>Router \ Area</th>
              <?php foreach($topAreas as $a): ?><th><?php echo h($a); ?></th><?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php
              // (বাংলা) কালার স্কেলিংয়ের জন্য সব ভ্যালু সংগ্রহ
              $vals=[];
              foreach($topRouters as $r){
                foreach($topAreas as $a){
                  $v = (float)($matrix[$r][$a] ?? 0);
                  $vals[]=$v;
                }
              }
              $max = max($vals ?: [0]);
              $q1 = $max * 0.25; $q2 = $max * 0.5; $q3 = $max * 0.75;
              $cellClass = function($v) use($q1,$q2,$q3){
                if ($v<=0) return 'bg-body';
                if ($v<=$q1) return 'table-success';
                if ($v<=$q2) return 'table-warning';
                if ($v<=$q3) return 'table-danger';
                return 'table-dark text-white';
              };
            ?>
            <?php foreach($topRouters as $r): ?>
              <tr>
                <th><?php echo h($r); ?></th>
                <?php foreach($topAreas as $a):
                  $v = (float)($matrix[$r][$a] ?? 0);
                  $cls = $cellClass($v);
                ?>
                  <td class="<?php echo $cls; ?>"><small><?php echo $v>0 ? number_format($v,2) : '-'; ?></small></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="text-muted small">Color legend: low → high (green → yellow → red → dark)</div>
    </div>
  </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function(){
  const rLabels = <?php echo json_encode(array_map(fn($r)=>$r['router']?:'N/A',$rowsR)); ?>;
  const rData   = <?php echo json_encode(array_map(fn($r)=>(float)$r['due'],$rowsR)); ?>;
  const aLabels = <?php echo json_encode(array_map(fn($a)=>$a['area']?:'N/A',$rowsA)); ?>;
  const aData   = <?php echo json_encode(array_map(fn($a)=>(float)$a['due'],$rowsA)); ?>;

  // Router bar
  new Chart(document.getElementById('routerChart').getContext('2d'), {
    type: 'bar',
    data: { labels: rLabels, datasets: [{ label: 'Total Due', data: rData }] },
    options: {
      indexAxis: 'y',
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { x: { beginAtZero: true } }
    }
  });

  // Area bar
  new Chart(document.getElementById('areaChart').getContext('2d'), {
    type: 'bar',
    data: { labels: aLabels, datasets: [{ label: 'Total Due', data: aData }] },
    options: {
      indexAxis: 'y',
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { x: { beginAtZero: true } }
    }
  });
})();
</script>

<?php include __DIR__ . '/../partials/partials_footer.php'; ?>
