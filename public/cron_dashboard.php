<?php
// /public/cron_dashboard.php
// Cron Dashboard — Run now + Last run history (+ month picker for invoice job)
// UI: English; Comments: বাংলা
declare(strict_types=1);

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function dbh(){ return db(); }

/* ---------- (ঐচ্ছিক) Admin-only গার্ড ----------
   যদি আপনার সিস্টেমে $_SESSION['user']['role']=='admin' থাকে, আনকমেন্ট করুন
// if (($_SESSION['user']['role'] ?? '') !== 'admin') {
//   http_response_code(403);
//   echo 'Forbidden';
//   exit;
// }
*/

/* ---------- Ensure log table ---------- */
// (বাংলা) ক্রন রান লগ টেবিল — না থাকলে বানাই
dbh()->exec("
CREATE TABLE IF NOT EXISTS cron_runs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_key VARCHAR(64) NOT NULL,
  title   VARCHAR(255) NOT NULL,
  status  ENUM('success','failed') DEFAULT NULL,
  started_at DATETIME NOT NULL,
  finished_at DATETIME DEFAULT NULL,
  duration_ms INT UNSIGNED DEFAULT NULL,
  output MEDIUMTEXT NULL,
  error  MEDIUMTEXT NULL,
  triggered_by INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX(job_key),
  INDEX(status),
  INDEX(started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* ---------- Declare cron jobs ---------- */
/* (বাংলা) নতুন জব যোগ করতে $jobs অ্যারে-তে আরেকটা কী যোগ করুন */
$current_month = date('Y-m');
$jobs = [
  'mt_sync' => [
    'title' => 'MikroTik Sync Clients',
    'url'   => '/api/mt_sync_clients.php',
    'method'=> 'GET',
    'desc'  => 'Upsert clients from RouterOS (show-sensitive aware).',
    'timeout' => 180,
    'supports' => [], // extra inputs নেই
  ],
  'invoice_month' => [
    'title' => 'Generate Monthly Invoices (commit)',
    // (বাংলা) {month} প্লেসহোল্ডার রিপ্লেস হবে; নিচে ফর্মে month নেওয়া হচ্ছে
    'url'   => '/public/invoice_generate.php?month={month}&commit=1',
    'method'=> 'GET',
    'desc'  => 'Create/replace invoices for a given month and update ledgers.',
    'timeout' => 240,
    'supports' => ['month'], // extra input: month (YYYY-MM)
  ],
  // উদাহরণ:
  // 'auto_suspend' => [
  //   'title' => 'Auto Suspend Unpaid',
  //   'url'   => '/api/auto_suspend.php',
  //   'method'=> 'POST',
  //   'desc'  => 'Disable PPPoE for long-due clients.',
  //   'timeout' => 120,
  //   'supports' => [],
  // ],
];

/* ---------- Helpers ---------- */
function local_url(string $path): string {
  if ($path === '' || $path[0] !== '/') $path = '/'.$path;
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  return $scheme.'://'.$host.$path;
}

/* (বাংলা) লোকাল HTTP কল — cURL প্রেফার্ড; নইলে file_get_contents */
function http_call(string $url, string $method = 'GET', array $post = [], int $timeout = 120): array {
  $full = local_url($url);
  $method = strtoupper($method);

  if (function_exists('curl_init')) {
    $ch = curl_init();
    if ($method === 'GET' && $post) {
      $full .= (str_contains($full, '?') ? '&' : '?').http_build_query($post);
    }
    curl_setopt_array($ch, [
      CURLOPT_URL => $full,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HEADER => false,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_TIMEOUT => $timeout,
      CURLOPT_SSL_VERIFYPEER => false,
      CURLOPT_SSL_VERIFYHOST => false,
    ]);
    if ($method === 'POST') {
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }
    $out = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['ok' => ($err==='' && $status>=200 && $status<300), 'status'=>$status, 'output'=>$out?:'', 'error'=>$err];
  }

  // fallback
  $opts = ['http' => ['method'=>$method, 'timeout'=>$timeout, 'ignore_errors'=>true]];
  if ($method === 'POST') {
    $opts['http']['header']  = "Content-Type: application/x-www-form-urlencoded\r\n";
    $opts['http']['content'] = http_build_query($post);
  }
  $ctx = stream_context_create($opts);
  $out = @file_get_contents($full, false, $ctx);
  $status = 0;
  if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
    $status = (int)$m[1];
  }
  $ok = ($status>=200 && $status<300 && $out!==false);
  return ['ok'=>$ok, 'status'=>$status, 'output'=>$out!==false?$out:'', 'error'=>$ok?'':'HTTP error or timeout'];
}

/* ---------- Run handler ---------- */
$flash_success = '';
$flash_error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_job'])) {
  $job_key = trim($_POST['run_job']);
  if (!isset($jobs[$job_key])) {
    $flash_error = 'Unknown job.';
  } else {
    $job = $jobs[$job_key];
    $title = $job['title'];
    $user_id = (int)($_SESSION['user']['id'] ?? 0);

    // (বাংলা) ইনপুট: month সাপোর্ট করলে নিন
    $url = $job['url'];
    if (!empty($job['supports']) && in_array('month', $job['supports'], true)) {
      $month = trim($_POST['month'] ?? date('Y-m'));
      // validate YYYY-MM
      if (!preg_match('/^\d{4}\-\d{2}$/', $month)) {
        $flash_error = 'Invalid month format. Use YYYY-MM.';
        goto after_run; // graceful
      }
      $url = str_replace('{month}', $month, $url);
      $title .= " [$month]";
    } else {
      $url = str_replace('{month}', $current_month, $url); // যদি ভুলে থেকেও placeholder থাকে
    }

    // স্টার্ট লগ
    $st = dbh()->prepare("INSERT INTO cron_runs (job_key, title, started_at, triggered_by) VALUES (?, ?, NOW(), ?)");
    $st->execute([$job_key, $title, $user_id]);
    $run_id = (int)dbh()->lastInsertId();

    // Execute
    $started = microtime(true);
    @set_time_limit(max(60, (int)($job['timeout'] ?? 120)));
    try {
      $res = http_call($url, $job['method'] ?? 'GET', [], (int)($job['timeout'] ?? 120));
      $duration = (int)round((microtime(true)-$started)*1000);
      $status = $res['ok'] ? 'success' : 'failed';

      // Output trim (1MB)
      $output = (string)($res['output'] ?? '');
      if (strlen($output) > 1024*1024) {
        $output = substr($output, 0, 1024*1024)."\n...[truncated]...";
      }

      $upd = dbh()->prepare("
        UPDATE cron_runs 
        SET status=?, finished_at=NOW(), duration_ms=?, output=?, error=?
        WHERE id=?");
      $upd->execute([$status, $duration, $output, (string)($res['error'] ?? ''), $run_id]);

      $flash_success = $status==='success'
        ? "Job '{$title}' finished successfully."
        : "Job '{$title}' failed (HTTP ".$res['status'].").";
    } catch (Throwable $e) {
      $duration = (int)round((microtime(true)-$started)*1000);
      $upd = dbh()->prepare("
        UPDATE cron_runs 
        SET status='failed', finished_at=NOW(), duration_ms=?, error=?
        WHERE id=?");
      $upd->execute([$duration, $e->getMessage(), $run_id]);
      $flash_error = "Job '{$title}' failed: ".$e->getMessage();
    }
  }
}
after_run:

/* ---------- Filters & pagination for history ---------- */
$f_job   = trim($_GET['job'] ?? '');
$f_state = trim($_GET['state'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$limit   = 20;
$offset  = ($page-1)*$limit;

$where = [];
$params = [];
if ($f_job !== '' && isset($jobs[$f_job])) { $where[]="job_key=?"; $params[]=$f_job; }
if (in_array($f_state, ['success','failed'], true)) { $where[]="status=?"; $params[]=$f_state; }
$where_sql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

// Count & fetch
$stc = dbh()->prepare("SELECT COUNT(*) FROM cron_runs $where_sql");
$stc->execute($params);
$total_rows = (int)$stc->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows/$limit));

$st = dbh()->prepare("SELECT * FROM cron_runs $where_sql ORDER BY id DESC LIMIT $limit OFFSET $offset");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Last run per job
$last = [];
$stl = dbh()->query("
  SELECT r.*
  FROM (SELECT job_key, MAX(id) mx FROM cron_runs GROUP BY job_key) x
  JOIN cron_runs r ON r.id = x.mx
  ORDER BY r.id DESC
");
foreach ($stl->fetchAll(PDO::FETCH_ASSOC) as $r) {
  $last[$r['job_key']] = $r;
}
?>
<?php require_once __DIR__ . '/../partials/partials_header.php'; ?>
<div class="container my-4">
  <div class="d-flex align-items-center justify-content-between">
    <h3 class="mb-0">Cron Dashboard</h3>
    <a href="?<?=h(http_build_query($_GET))?>" class="btn btn-outline-secondary">
      <i class="bi bi-arrow-clockwise"></i> Refresh
    </a>
  </div>

  <?php if ($flash_success): ?>
    <div class="alert alert-success mt-3"><?=h($flash_success)?></div>
  <?php endif; ?>
  <?php if ($flash_error): ?>
    <div class="alert alert-danger mt-3"><?=h($flash_error)?></div>
  <?php endif; ?>

  <!-- Jobs -->
  <div class="card shadow-sm mt-3">
    <div class="card-header">Available Jobs</div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table align-middle">
          <thead class="table-light">
            <tr>
              <th>Job</th>
              <th>Description</th>
              <th>Endpoint</th>
              <th>Last run</th>
              <th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($jobs as $key => $j): 
            $lr = $last[$key] ?? null;
            $url_preview = $j['url'];
            if (!empty($j['supports']) && in_array('month', $j['supports'], true)) {
              $url_preview = str_replace('{month}', $current_month, $url_preview);
            }
          ?>
            <tr>
              <td>
                <div class="fw-semibold"><?=h($j['title'])?></div>
                <div class="text-muted small"><?=h($key)?></div>
              </td>
              <td><?=h($j['desc'])?></td>
              <td>
                <code><?=h($j['method'] ?? 'GET')?> <?=h($url_preview)?></code>
                <div class="text-muted small">Timeout: <?=h((string)($j['timeout'] ?? 120))?>s</div>
              </td>
              <td>
                <?php if ($lr): ?>
                  <div>
                    <span class="badge bg-<?= ($lr['status']==='success'?'success':($lr['status']==='failed'?'danger':'secondary')) ?>">
                      <?=h($lr['status'] ?? 'running')?>
                    </span>
                    <span class="small text-muted ms-2">
                      <?=h($lr['started_at'])?><?php if($lr['finished_at']):?> → <?=h($lr['finished_at'])?><?php endif; ?>
                      <?php if($lr['duration_ms']):?> (<?=h((string)$lr['duration_ms'])?> ms)<?php endif; ?>
                    </span>
                  </div>
                <?php else: ?>
                  <span class="text-muted">No runs</span>
                <?php endif; ?>
              </td>
              <td class="text-end">
                <form method="post" class="d-inline">
                  <input type="hidden" name="run_job" value="<?=h($key)?>">
                  <?php if (!empty($j['supports']) && in_array('month', $j['supports'], true)): ?>
                    <div class="input-group input-group-sm" style="width: 260px;">
                      <span class="input-group-text">Month</span>
                      <input type="month" name="month" class="form-control" value="<?=h($current_month)?>">
                      <button class="btn btn-primary"><i class="bi bi-play-fill"></i> Run</button>
                    </div>
                  <?php else: ?>
                    <button class="btn btn-primary btn-sm">
                      <i class="bi bi-play-fill"></i> Run now
                    </button>
                  <?php endif; ?>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- History filters -->
  <form class="row g-2 mt-4 mb-2">
    <div class="col-md-3">
      <label class="form-label">Job</label>
      <select name="job" class="form-select">
        <option value="">All</option>
        <?php foreach($jobs as $k=>$j): ?>
          <option value="<?=h($k)?>" <?= $f_job===$k?'selected':'' ?>><?=h($j['title'])?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Status</label>
      <select name="state" class="form-select">
        <option value="">All</option>
        <option value="success" <?= $f_state==='success'?'selected':'' ?>>Success</option>
        <option value="failed"  <?= $f_state==='failed'?'selected':'' ?>>Failed</option>
      </select>
    </div>
    <div class="col-md-6 d-flex align-items-end gap-2">
      <button class="btn btn-outline-secondary"><i class="bi bi-funnel"></i> Filter</button>
      <a class="btn btn-outline-dark" href="/public/cron_dashboard.php"><i class="bi bi-x-circle"></i> Reset</a>
    </div>
  </form>

  <!-- History table -->
  <div class="card shadow-sm">
    <div class="card-header">Last Runs</div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-striped align-middle">
          <thead class="table-light">
            <tr>
              <th style="width:90px;">ID</th>
              <th>Job</th>
              <th>Title</th>
              <th>Status</th>
              <th>Started</th>
              <th>Finished</th>
              <th class="text-end">Duration</th>
              <th>Output (first lines)</th>
              <th>Error</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="9" class="text-center text-muted">No runs recorded.</td></tr>
            <?php else: foreach($rows as $r): ?>
              <tr>
                <td>#<?=h((string)$r['id'])?></td>
                <td><code><?=h($r['job_key'])?></code></td>
                <td><?=h($r['title'])?></td>
                <td>
                  <?php if ($r['status']): ?>
                    <span class="badge bg-<?= $r['status']==='success'?'success':'danger' ?>"><?=h($r['status'])?></span>
                  <?php else: ?>
                    <span class="badge bg-secondary">running</span>
                  <?php endif; ?>
                </td>
                <td><?=h((string)$r['started_at'])?></td>
                <td><?=h((string)($r['finished_at'] ?? ''))?></td>
                <td class="text-end"><?=h($r['duration_ms'] !== null ? (string)$r['duration_ms'].' ms' : '')?></td>
                <td>
                  <?php
                    $out = trim((string)($r['output'] ?? ''));
                    if ($out === '') echo '<span class="text-muted">—</span>';
                    else echo '<pre class="small mb-0" style="max-height:120px;overflow:auto">'.h(mb_substr($out,0,2000)).'</pre>';
                  ?>
                </td>
                <td>
                  <?php
                    $er = trim((string)($r['error'] ?? ''));
                    if ($er === '') echo '<span class="text-muted">—</span>';
                    else echo '<pre class="small mb-0" style="max-height:120px;overflow:auto">'.h(mb_substr($er,0,2000)).'</pre>';
                  ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination (max 5 pages) -->
      <?php
        $win=5; $start=max(1, $page-intdiv($win-1,2)); $end=min($total_pages, $start+$win-1);
        if ($end-$start+1<$win) $start=max(1, $end-$win+1);
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
  </div>
</div>
<?php require_once __DIR__ . '/../partials/partials_footer.php'; ?>
