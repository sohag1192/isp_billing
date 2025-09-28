<?php
// /public/import_clients.php
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

/* ------------------ Helpers ------------------ */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function norm($s){ return strtolower(trim((string)$s)); }
function is_date($s){ return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$s); }

/* CSV delimiter guess (",", ";", or TAB) */
function guess_delim($path){
  $s = @file_get_contents($path, false, null, 0, 4096);
  if ($s === false) return ',';
  $c  = substr_count($s, ',');
  $sc = substr_count($s, ';');
  $t  = substr_count($s, "\t");
  $best = max($c,$sc,$t);
  if ($best === 0) return ',';
  return ($best===$t) ? "\t" : (($best===$sc) ? ';' : ',');
}

/* Header → index map with synonyms */
function header_index_syn($hdrs){
  $map = [];
  $aliases = [
    'client_code'  => ['client_code','client code','code','clientcode','customer code','customer_code','cid','client id','clientid'],
    'name'         => ['name','client name','customer name','full name'],
    'pppoe_id'     => ['pppoe_id','pppoe id','pppoe','pppoe username','username','user','uid'],
    'mobile'       => ['mobile','phone','phone number','mobile number','msisdn','contact'],
    'email'        => ['email','e-mail','mail'],
    'address'      => ['address','addr','street','location','address1'],
    'area'         => ['area','zone','block'],
    'status'       => ['status','state'],
    'package'      => ['package','plan','profile','package name','package_name'],
    'package_id'   => ['package_id','pkg_id','package id'],
    'router'       => ['router','router name','nas','nasname'],
    'router_id'    => ['router_id','router id','nas_id'],
    'join_date'    => ['join_date','join date','joined','start','start_date','activation_date'],
    'expiry_date'  => ['expiry_date','expire','expire date','end','end_date','exp date'],
    'monthly_bill' => ['monthly_bill','monthly','bill','price','amount'],
    'remarks'      => ['remarks','note','notes'],
    'onu_mac'      => ['onu_mac','mac','onu mac','mac address'],
    'onu_model'    => ['onu_model','onu model','model'],
    'ip_address'   => ['ip_address','ip','ip address','ipv4'],
  ];
  $norm = function($s){
    $s = strtolower(trim((string)$s));
    $s = preg_replace('/[^a-z0-9]+/',' ', $s);
    $s = preg_replace('/\s+/',' ', $s);
    return $s;
  };
  $normHdr = array_map($norm, $hdrs);
  foreach ($aliases as $canon => $list){
    foreach ($list as $alt){
      $idx = array_search($norm($alt), $normHdr, true);
      if ($idx !== false){ $map[$canon] = $idx; break; }
    }
  }
  return $map;
}
function val($row, $idx){ return ($idx !== null && $idx >= 0 && isset($row[$idx])) ? trim((string)$row[$idx]) : ''; }

/* ------------------ CSRF ------------------ */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(16)); }
$CSRF = $_SESSION['csrf_token'];

/* ------------------ Template download ------------------ */
if (isset($_GET['template']) && $_GET['template']=='1') {
  $headers = [
    'client_code','name','pppoe_id','mobile','email','address','area',
    'status','package','package_id','router','router_id','join_date',
    'expiry_date','monthly_bill','remarks','onu_mac','onu_model','ip_address'
  ];
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename=client_import_template.csv');
  echo "\xEF\xBB\xBF"; // UTF-8 BOM
  $out=fopen('php://output','w');
  fputcsv($out, $headers);
  // 2 sample rows
  fputcsv($out, ['C-1001','Rahim Uddin','rahim01','01711111111','rahim@example.com','Road 1','Mirpur','active','20Mbps','','Main Router','','2025-01-01','2025-01-31','1200','','','','']);
  fputcsv($out, ['C-1002','Karim Mia','karim02','01722222222','','Road 2','Uttara','inactive','','2','','1','2025-01-05','','','','','']);
  fclose($out); exit;
}

/* ------------------ Step & lookups ------------------ */
$step = $_POST['step'] ?? 'form';
$packages = db()->query("SELECT id, name, price FROM packages ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$routers  = db()->query("SELECT id, name        FROM routers  ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

/* Build quick maps */
$pkgById = []; $pkgByName = [];
foreach($packages as $p){ $pkgById[(int)$p['id']]=$p; $pkgByName[norm($p['name'])]=$p; }
$rtById  = []; $rtByName  = [];
foreach($routers as $r){ $rtById[(int)$r['id']]=$r; $rtByName[norm($r['name'])]=$r; }

/* Existing uniqueness (for duplicate guard) */
$existing = db()->query("SELECT client_code, pppoe_id FROM clients")->fetchAll(PDO::FETCH_ASSOC);
$exists_code = []; $exists_pppoe = [];
foreach($existing as $e){
  if (!empty($e['client_code'])) $exists_code[norm($e['client_code'])]=1;
  if (!empty($e['pppoe_id']))    $exists_pppoe[norm($e['pppoe_id'])]=1;
}

/* =======================================================
   ===============   IMPORT CONFIRM STEP   ===============
   ======================================================= */
if ($step === 'import' && isset($_POST['csrf']) && hash_equals($CSRF, $_POST['csrf'] ?? '')) {
  $payload = json_decode($_POST['payload'] ?? '[]', true);
  if (!is_array($payload)) $payload = [];

  $inserted=0; $skipped=0; $errors=[];
  $pdo = db();
  $pdo->beginTransaction();
  try{
    $stmt = $pdo->prepare("
      INSERT INTO clients
      (client_code, name, pppoe_id, mobile, email, address, area, status, package_id, router_id,
       join_date, expiry_date, monthly_bill, remarks, onu_mac, onu_model, ip_address,
       is_left, created_at, updated_at)
      VALUES
      (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,NOW(),NOW())
    ");

    foreach($payload as $i=>$row){
      // re-check duplicate (race-safe)
      $chk = $pdo->prepare("SELECT 1 FROM clients WHERE client_code=? OR pppoe_id=? LIMIT 1");
      $chk->execute([$row['client_code'], $row['pppoe_id']]);
      if ($chk->fetchColumn()){ $skipped++; continue; }

      try{
        $stmt->execute([
          $row['client_code'], $row['name'], $row['pppoe_id'], $row['mobile'], $row['email'],
          $row['address'], $row['area'], $row['status'], $row['package_id'] ?: null, $row['router_id'] ?: null,
          $row['join_date'] ?: null, $row['expiry_date'] ?: null,
          $row['monthly_bill'] !== '' ? $row['monthly_bill'] : null,
          $row['remarks'], $row['onu_mac'], $row['onu_model'], $row['ip_address']
        ]);
        $inserted++;
      }catch(Exception $ex){
        $errors[] = "Row ".($i+1).": DB insert failed (".$ex->getMessage().")";
      }
    }
    $pdo->commit();
  }catch(Exception $e){
    $pdo->rollBack();
    $errors[] = "Transaction failed: ".$e->getMessage();
  }

  include __DIR__ . '/../partials/partials_header.php';
  ?>
  <div class="container py-4">
    <h5 class="mb-3"><i class="bi bi-upload"></i> Import Result</h5>
    <div class="alert alert-success"><b><?= (int)$inserted ?></b> rows inserted.</div>
    <?php if ($skipped): ?><div class="alert alert-warning"><b><?= (int)$skipped ?></b> rows skipped (duplicates).</div><?php endif; ?>
    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger"><b><?= count($errors) ?></b> errors occurred.</div>
      <div class="card"><div class="card-body"><ul class="mb-0">
        <?php foreach(array_slice($errors,0,100) as $er): ?><li><?= h($er) ?></li><?php endforeach; ?>
        <?php if (count($errors)>100): ?><li>...and more...</li><?php endif; ?>
      </ul></div></div>
    <?php endif; ?>
    <div class="mt-3 d-flex gap-2">
      <a href="import_clients.php" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> Back</a>
      <a class="btn btn-outline-primary btn-sm" href="clients.php"><i class="bi bi-people"></i> Client List</a>
    </div>
  </div>
  <?php
  include __DIR__ . '/../partials/partials_footer.php';
  exit;
}

/* =======================================================
   ===============      PREVIEW STEP        ===============
   ======================================================= */
$errors=[]; $validRows=[];
if ($step === 'preview' && isset($_POST['csrf']) && hash_equals($CSRF, $_POST['csrf'] ?? '')) {
  if (!isset($_FILES['csv']) || $_FILES['csv']['error']!==UPLOAD_ERR_OK) {
    $errors[] = "CSV ফাইল পাওয়া যায়নি।";
  } else {
    $tmp = $_FILES['csv']['tmp_name'];
    $delim = guess_delim($tmp);

    if (($fh = fopen($tmp, 'r')) === false){
      $errors[] = "ফাইল ওপেন করা যাচ্ছে না।";
    } else {
      // BOM skip
      $first = fgets($fh);
      if ($first === false){ $errors[]="ফাইল ফাঁকা।"; }
      else {
        // Rewind and read via fgetcsv using delimiter
        rewind($fh);
        $hdr = fgetcsv($fh, 0, $delim);
        if (!$hdr || count($hdr) < 1) { $errors[]="ভ্যালিড হেডার পাওয়া যায়নি।"; }
        else {
          $idx = header_index_syn($hdr);
          foreach (['client_code','name','pppoe_id'] as $need){
            if (!isset($idx[$need])) $errors[] = "Missing required column: {$need}";
          }

          $rownum=0;
          $seen_code=[]; $seen_pppoe=[];
          while(($row=fgetcsv($fh, 0, $delim)) !== false){
            // skip empty rows
            if (count(array_filter($row, fn($x)=>trim((string)$x)!==''))===0) continue;

            $rownum++;
            $client_code = val($row, $idx['client_code'] ?? -1);
            $name        = val($row, $idx['name'] ?? -1);
            $pppoe_id    = val($row, $idx['pppoe_id'] ?? -1);
            $mobile      = val($row, $idx['mobile'] ?? -1);
            $email       = val($row, $idx['email'] ?? -1);
            $address     = val($row, $idx['address'] ?? -1);
            $area        = val($row, $idx['area'] ?? -1);
            $status_in   = val($row, $idx['status'] ?? -1);
            $pkg_name    = val($row, $idx['package'] ?? -1);
            $pkg_id_in   = val($row, $idx['package_id'] ?? -1);
            $rt_name     = val($row, $idx['router'] ?? -1);
            $rt_id_in    = val($row, $idx['router_id'] ?? -1);
            $join_date   = val($row, $idx['join_date'] ?? -1);
            $expiry_date = val($row, $idx['expiry_date'] ?? -1);
            $monthly_bill= val($row, $idx['monthly_bill'] ?? -1);
            $remarks     = val($row, $idx['remarks'] ?? -1);
            $onu_mac     = val($row, $idx['onu_mac'] ?? -1);
            $onu_model   = val($row, $idx['onu_model'] ?? -1);
            $ip_address  = val($row, $idx['ip_address'] ?? -1);

            $rowErrors = [];
            if ($client_code==='') $rowErrors[]='client_code blank';
            if ($name==='')        $rowErrors[]='name blank';
            if ($pppoe_id==='')    $rowErrors[]='pppoe_id blank';

            // duplicate guard: within file
            $ncode = norm($client_code); $nppp = norm($pppoe_id);
            if ($ncode!=='' && isset($seen_code[$ncode])) $rowErrors[]='client_code duplicate in CSV';
            if ($nppp!==''  && isset($seen_pppoe[$nppp])) $rowErrors[]='pppoe_id duplicate in CSV';
            $seen_code[$ncode]=1; $seen_pppoe[$nppp]=1;

            // duplicate guard: against DB
            if ($ncode!=='' && isset($exists_code[$ncode])) $rowErrors[]='client_code already exists';
            if ($nppp!==''  && isset($exists_pppoe[$nppp])) $rowErrors[]='pppoe_id already exists';

            // status normalize
            $st = norm($status_in);
            $allowed = ['active','inactive','pending','expired'];
            if (!in_array($st, $allowed, true)) $st = 'inactive';

            // package resolve
            $package_id = 0;
            if ($pkg_id_in !== '' && is_numeric($pkg_id_in) && isset($pkgById[(int)$pkg_id_in])) {
              $package_id = (int)$pkg_id_in;
            } elseif ($pkg_name !== '' && isset($pkgByName[norm($pkg_name)])) {
              $package_id = (int)$pkgByName[norm($pkg_name)]['id'];
            } elseif ($pkg_name!=='' || $pkg_id_in!=='') {
              $rowErrors[] = 'package not found';
            }

            // router resolve
            $router_id = 0;
            if ($rt_id_in !== '' && is_numeric($rt_id_in) && isset($rtById[(int)$rt_id_in])) {
              $router_id = (int)$rt_id_in;
            } elseif ($rt_name !== '' && isset($rtByName[norm($rt_name)])) {
              $router_id = (int)$rtByName[norm($rt_name)]['id'];
            } elseif ($rt_name!=='' || $rt_id_in!=='') {
              $rowErrors[] = 'router not found';
            }

            // dates
            if ($join_date!==''  && !is_date($join_date))   { $rowErrors[]='join_date invalid'; $join_date=''; }
            if ($expiry_date!==''&& !is_date($expiry_date)) { $rowErrors[]='expiry_date invalid'; $expiry_date=''; }

            // monthly_bill
            if ($monthly_bill===''){
              if ($package_id && isset($pkgById[$package_id])) $monthly_bill = (string)$pkgById[$package_id]['price'];
            } elseif (!is_numeric($monthly_bill)) {
              $rowErrors[]='monthly_bill invalid'; $monthly_bill='';
            }

            if (empty($rowErrors)){
              $validRows[] = [
                'client_code'=>$client_code, 'name'=>$name, 'pppoe_id'=>$pppoe_id,
                'mobile'=>$mobile, 'email'=>$email, 'address'=>$address, 'area'=>$area,
                'status'=>$st, 'package_id'=>$package_id, 'router_id'=>$router_id,
                'join_date'=>$join_date, 'expiry_date'=>$expiry_date, 'monthly_bill'=>$monthly_bill,
                'remarks'=>$remarks, 'onu_mac'=>$onu_mac, 'onu_model'=>$onu_model, 'ip_address'=>$ip_address
              ];
            } else {
              $errors[] = "Row $rownum: ".implode(', ', $rowErrors);
            }
          }
          fclose($fh);
        }
      }
    }
  }

  /* ===== Preview UI ===== */
  include __DIR__ . '/../partials/partials_header.php';
  ?>
  <div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <h5 class="mb-0"><i class="bi bi-filetype-csv"></i> CSV Preview</h5>
      <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="import_clients.php"><i class="bi bi-arrow-left"></i> Back</a>
      </div>
    </div>

    <div class="row g-3">
      <div class="col-12 col-lg-4">
        <div class="card shadow-sm">
          <div class="card-body">
            <div class="mb-2"><b>Total valid:</b> <?= count($validRows) ?></div>
            <div class="mb-2"><b>Total errors:</b> <?= count($errors) ?></div>
            <?php if (!empty($errors)): ?>
              <details class="mt-2">
                <summary>See first errors</summary>
                <div class="mt-2 small">
                  <ul class="mb-0">
                    <?php foreach(array_slice($errors,0,50) as $er): ?>
                      <li><?= h($er) ?></li>
                    <?php endforeach; ?>
                    <?php if (count($errors)>50): ?><li>...and more...</li><?php endif; ?>
                  </ul>
                </div>
              </details>
            <?php endif; ?>

            <form method="post">
              <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
              <input type="hidden" name="step" value="import">
              <input type="hidden" name="payload" value='<?= h(json_encode($validRows)) ?>'>
              <button class="btn btn-success btn-sm" <?= count($validRows)?'':'disabled' ?>>
                <i class="bi bi-check-lg"></i> Import <?= count($validRows) ?> rows
              </button>
            </form>
          </div>
        </div>
      </div>

      <div class="col-12 col-lg-8">
        <div class="card shadow-sm">
          <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
              <thead class="table-light">
                <tr>
                  <th>#</th><th>Client Code</th><th>Name</th><th>PPPoE</th>
                  <th>Package</th><th>Router</th><th>Status</th>
                  <th>Mobile</th><th>Monthly</th><th>Join</th><th>Expire</th>
                </tr>
              </thead>
              <tbody>
              <?php if ($validRows): foreach(array_slice($validRows,0,200) as $i=>$r): ?>
                <tr>
                  <td><?= $i+1 ?></td>
                  <td><?= h($r['client_code']) ?></td>
                  <td><?= h($r['name']) ?></td>
                  <td><?= h($r['pppoe_id']) ?></td>
                  <td><?= $r['package_id'] && isset($pkgById[$r['package_id']]) ? h($pkgById[$r['package_id']]['name']) : '—' ?></td>
                  <td><?= $r['router_id']  && isset($rtById[$r['router_id']])   ? h($rtById[$r['router_id']]['name'])   : '—' ?></td>
                  <td>
                    <span class="badge <?= $r['status']==='active'?'bg-success':($r['status']==='inactive'?'bg-danger':'bg-secondary') ?>">
                      <?= h($r['status']) ?>
                    </span>
                  </td>
                  <td><?= h($r['mobile']) ?></td>
                  <td><?= h($r['monthly_bill']) ?></td>
                  <td><?= h($r['join_date']) ?></td>
                  <td><?= h($r['expiry_date']) ?></td>
                </tr>
              <?php endforeach; else: ?>
                <tr><td colspan="11" class="text-muted text-center py-4">কোনো ভ্যালিড রো নেই।</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
          <?php if (count($validRows)>200): ?>
            <div class="card-footer small text-muted">শুধু প্রথম ২০০টি রো দেখানো হলো…</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <?php
  include __DIR__ . '/../partials/partials_footer.php';
  exit;
}

/* =======================================================
   ===============   DEFAULT UPLOAD FORM    ===============
   ======================================================= */
include __DIR__ . '/../partials/partials_header.php';
?>
<div class="container py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h5 class="mb-0"><i class="bi bi-upload"></i> Client CSV Import</h5>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="?template=1">
        <i class="bi bi-download"></i> Download Template
      </a>
      <a class="btn btn-outline-primary btn-sm" href="clients.php">
        <i class="bi bi-people"></i> Client List
      </a>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-body">
      <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
        <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
        <input type="hidden" name="step" value="preview">

        <div class="col-12 col-md-6">
          <label class="form-label">CSV File</label>
          <input type="file" name="csv" accept=".csv,text/csv" class="form-control" required>
          <div class="form-text">UTF-8 CSV; delimiter auto (comma / semicolon / tab)।</div>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">Notes</label>
          <ul class="small mb-0">
            <li><b>Required:</b> client_code, name, pppoe_id</li>
            <li>package বা package_id — যেকোনো একটা দিলেই হবে</li>
            <li>router বা router_id — যেকোনো একটা দিলেই হবে</li>
            <li>monthly_bill ফাঁকা থাকলে প্যাকেজের price বসবে</li>
            <li>তারিখ ফরম্যাট: YYYY-MM-DD</li>
          </ul>
        </div>

        <div class="col-12">
          <button class="btn btn-dark"><i class="bi bi-search"></i> Preview</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../partials/partials_footer.php'; ?>
