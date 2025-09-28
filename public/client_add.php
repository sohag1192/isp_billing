<?php
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/acl.php';


require_once __DIR__ . '/../app/routeros_api.class.php';
@include_once __DIR__ . '/../app/audit.php'; // (বাংলা) থাকলে অডিট লগ করবো

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ==================== Audit wrapper (client context) ==================== */
// বাংলা: বিভিন্ন প্রোজেক্টে audit_log() সিগনেচার আলাদা হতে পারে।
// এখানে সেফ-কলার রাখলাম: আগে (action,'client',id,details) ট্রাই করবে,
// টাইপ এরর হলে (action,id,details) ট্রাই করবে, না পারলে চুপ করে ফেল করবে।
function audit_client(string $action, int $client_id, array $details = []): void {
    if (!function_exists('audit_log')) return;
    try {
        audit_log($action, 'client', $client_id, $details);
    } catch (TypeError $e) {
        try { audit_log($action, $client_id, $details); } catch (Throwable $e2) {}
    } catch (Throwable $e) { /* ignore */ }
}

/* ==================== Helpers ==================== */
// (বাংলা) টেবিলের কলাম আছে কিনা — একবার চেক করে cache করি
function db_has_column(string $table, string $column): bool {
    static $cache = [];
    if (!isset($cache[$table])) {
        $rows = db()->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
        $cache[$table] = array_flip($rows ?: []);
    }
    return isset($cache[$table][$column]);
}

/**
 * Save uploaded photo using PPPoE ID as filename: <pppoe-id>.<ext>
 * Overwrites any existing same-name file.
 * @return array ['ok'=>bool, 'url'=>?string, 'error'=>?string]
 * (বাংলা) নতুন ইউজারের ছবির জন্য pppoe_id দিয়ে ফাইলনেম বানিয়ে সেভ করি।
 */
function save_photo_with_pppoe_filename(string $pppoe_id): array {
    $out = ['ok'=>true, 'url'=>null, 'error'=>null];

    if (empty($_FILES['photo']) || (int)($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $out; // no file selected
    }

    $f = $_FILES['photo'];
    if ($f['error'] !== UPLOAD_ERR_OK) { $out['ok']=false; $out['error']='Upload failed.'; return $out; }

    $maxBytes = 3 * 1024 * 1024; // 3MB
    if ((int)$f['size'] > $maxBytes) { $out['ok']=false; $out['error']='Max 3MB allowed.'; return $out; }

    $mime = function_exists('finfo_open') ? (function($tmp){
        $fi=finfo_open(FILEINFO_MIME_TYPE); $m=finfo_file($fi,$tmp); finfo_close($fi); return $m;
    })($f['tmp_name']) : mime_content_type($f['tmp_name']);

    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if (!isset($allowed[$mime])) { $out['ok']=false; $out['error']='Only JPG/PNG/WebP.'; return $out; }

    $upDir = __DIR__ . '/../uploads/clients';
    if (!is_dir($upDir)) { @mkdir($upDir, 0775, true); }

    // (বাংলা) PPPoE আইডি স্যানিটাইজ করে ফাইলনেম বানাই
    $slug = strtolower($pppoe_id);
    $slug = preg_replace('/[^a-z0-9-_]+/i', '-', $slug);
    $slug = trim($slug, '-_');
    if ($slug === '') $slug = 'client';

    $ext   = $allowed[$mime];
    $fname = $slug.'.'.$ext;
    $dest  = $upDir . '/' . $fname;
    $destWeb = '/uploads/clients/'.$fname;

    if (file_exists($dest)) @unlink($dest);
    if (!move_uploaded_file($f['tmp_name'], $dest)) { $out['ok']=false; $out['error']='Could not save file.'; return $out; }

    $out['url'] = $destWeb;
    return $out;
}

/* ==================== Invoice helpers ==================== */
// (বাংলা) ইনভয়েস স্কিমা-ফ্ল্যাগস
function invoice_schema(): array {
    $has = fn($c)=> db_has_column('invoices', $c);
    $amount_target = $has('total') ? 'total' : ($has('payable') ? 'payable' : ($has('amount') ? 'amount' : null));
    return [
        'has_invoice_number' => $has('invoice_number'),
        'has_status'         => $has('status'),
        'has_is_void'        => $has('is_void'),
        'has_created'        => $has('created_at'),
        'has_updated'        => $has('updated_at'),
        'has_billing_month'  => $has('billing_month'),
        'has_invoice_date'   => $has('invoice_date'),
        'has_due_date'       => $has('due_date'),
        'has_period_start'   => $has('period_start'),
        'has_period_end'     => $has('period_end'),
        'has_remarks'        => $has('remarks'),
        'amount_target'      => $amount_target,
    ];
}

// (বাংলা) নতুন ক্লায়েন্ট হলে বর্তমান মাসের বিল অটো-জেনারেট করি
function create_first_invoice(int $client_id, float $amount, ?int $package_id = null): array {
    $pdo = db();
    $sch = invoice_schema();
    if (!$sch['amount_target']) {
        return ['ok'=>false, 'message'=>'No suitable amount column (total/payable/amount) in invoices table.'];
    }
    $has_ledger = db_has_column('clients','ledger_balance');

    $ym_start = date('Y-m-01');
    $ym_end   = date('Y-m-t', strtotime($ym_start));

    $pdo->beginTransaction();
    try {
        // (বাংলা) একই মাসে পুরনো ইনভয়েস থাকলে void + লেজার থেকে minus
        $rangeExpr = $sch['has_billing_month'] ? "billing_month BETWEEN ? AND ?" :
                    ($sch['has_invoice_date'] ? "DATE(invoice_date) BETWEEN ? AND ?" :
                    ($sch['has_created'] ? "DATE(created_at) BETWEEN ? AND ?" : null));

        if ($rangeExpr) {
            $sqlOld = "SELECT id, ".$sch['amount_target']." AS amt FROM invoices
                       WHERE client_id=? AND $rangeExpr " .
                       ($sch['has_is_void'] ? " AND COALESCE(is_void,0)=0" : "") .
                       ($sch['has_status']  ? " AND status <> 'void' " : "");
            $stOld = $pdo->prepare($sqlOld);
            $stOld->execute([$client_id, $ym_start, $ym_end]);
            $olds = $stOld->fetchAll(PDO::FETCH_ASSOC);
            if ($olds) {
                if ($sch['has_is_void'])      $void = $pdo->prepare("UPDATE invoices SET is_void=1 ".($sch['has_updated']?", updated_at=NOW()":"")." WHERE id=?");
                elseif ($sch['has_status'])   $void = $pdo->prepare("UPDATE invoices SET status='void' ".($sch['has_updated']?", updated_at=NOW()":"")." WHERE id=?");
                else                          $void = $pdo->prepare("DELETE FROM invoices WHERE id=?");

                foreach($olds as $o){
                    if ($has_ledger) {
                        $pdo->prepare("UPDATE clients SET ledger_balance = ledger_balance + :d WHERE id=:cid")
                           ->execute([':d'=> -1*(float)$o['amt'], ':cid'=>$client_id]);
                    }
                    $void->execute([(int)$o['id']]);
                    audit_client('invoice_void', $client_id, ['invoice_id'=>(int)$o['id']]);
                }
            }
        }

        // (বাংলা) নতুন ইনভয়েস insert
        $cols = ['client_id', $sch['amount_target']];
        $vals = [':client_id', ':amount'];
        if ($sch['has_invoice_number']) { $cols[]='invoice_number'; $vals[]=':invoice_number'; }
        if ($sch['has_billing_month'])  { $cols[]='billing_month';  $vals[]=':billing_month'; }
        if ($sch['has_invoice_date'])   { $cols[]='invoice_date';   $vals[]=':invoice_date'; }
        if ($sch['has_due_date'])       { $cols[]='due_date';       $vals[]=':due_date'; }
        if ($sch['has_period_start'])   { $cols[]='period_start';   $vals[]=':period_start'; }
        if ($sch['has_period_end'])     { $cols[]='period_end';     $vals[]=':period_end'; }
        if ($sch['has_status'])         { $cols[]='status';         $vals[]="'unpaid'"; }
        if ($sch['has_remarks'])        { $cols[]='remarks';        $vals[]=':remarks'; }
        if ($sch['has_created'])        { $cols[]='created_at';     $vals[]='NOW()'; }
        if ($sch['has_updated'])        { $cols[]='updated_at';     $vals[]='NOW()'; }

        $sqlIns = "INSERT INTO invoices (".implode(',', $cols).") VALUES (".implode(',', $vals).")";
        $ins = $pdo->prepare($sqlIns);

        $invNo = null;
        if ($sch['has_invoice_number']) {
            $rand = strtoupper(substr(bin2hex(random_bytes(2)),0,4));
            $invNo = 'INV-'.date('Ym').'-'.$client_id.'-'.$rand;
        }
        $remarks = $sch['has_remarks'] ? ('Auto created on client add (pkg='.$package_id.')') : null;

        $ins->bindValue(':client_id', $client_id, PDO::PARAM_INT);
        $ins->bindValue(':amount', $amount);
        if ($sch['has_invoice_number']) $ins->bindValue(':invoice_number', $invNo);
        if ($sch['has_billing_month'])  $ins->bindValue(':billing_month', $ym_start);
        if ($sch['has_invoice_date'])   $ins->bindValue(':invoice_date', date('Y-m-d'));
        if ($sch['has_due_date'])       $ins->bindValue(':due_date', date('Y-m-d', strtotime('+7 days')));
        if ($sch['has_period_start'])   $ins->bindValue(':period_start', $ym_start);
        if ($sch['has_period_end'])     $ins->bindValue(':period_end', $ym_end);
        if ($sch['has_remarks'])        $ins->bindValue(':remarks', $remarks);
        $ins->execute();
        $inv_id = (int)$pdo->lastInsertId();

        // (বাংলা) লেজার += amount
        if ($has_ledger) {
            $pdo->prepare("UPDATE clients SET ledger_balance = ledger_balance + :d WHERE id=:cid")
               ->execute([':d'=>$amount, ':cid'=>$client_id]);
        }

        audit_client('invoice_create', $client_id, ['invoice_id'=>$inv_id,'total'=>$amount]);

        $pdo->commit();
        return ['ok'=>true, 'invoice_id'=>$inv_id, 'amount'=>$amount];
    } catch(Throwable $e){
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok'=>false, 'message'=>$e->getMessage()];
    }
}

/* ==================== Load dropdowns ==================== */
$packages = db()->query("SELECT id, name, price FROM packages ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$routers  = db()->query("SELECT id, name, ip, username, password, api_port FROM routers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

/* ==================== Optional columns present? ==================== */
//$HAS_CLIENT_CODE = db_has_column('clients','client_code');
$HAS_SUB_ZONE    = db_has_column('clients','sub_zone');
$HAS_PHOTO_URL   = db_has_column('clients','photo_url');
$HAS_PPPOE_PASS  = db_has_column('clients','pppoe_pass');
$HAS_UPDATED_AT  = db_has_column('clients','updated_at');
$HAS_CREATED_AT  = db_has_column('clients','created_at');

/* ==================== Handle POST ==================== */
$errors = [];
$notice = null;
$new_id = null;
$new_invoice = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // (বাংলা) ইনপুট নিন
    //$client_code  = trim($_POST['client_code'] ?? '');
    $name         = trim($_POST['name'] ?? '');
    $mobile       = trim($_POST['mobile'] ?? '');
    // (বাংলা) মোবাইল normalize (স্পেস/ড্যাশ রিমুভ)
    $mobile       = preg_replace('/[\s\-]+/', '', $mobile);
    $email        = trim($_POST['email'] ?? '');
    $address      = trim($_POST['address'] ?? '');
    $area         = trim($_POST['area'] ?? '');
    $sub_zone     = trim($_POST['sub_zone'] ?? '');
    $pppoe_id     = trim($_POST['pppoe_id'] ?? '');
    $pppoe_pass   = trim($_POST['pppoe_pass'] ?? '');
    $package_id   = (int)($_POST['package_id'] ?? 0);
    $router_id    = (int)($_POST['router_id']  ?? 0);
    $monthly_bill = isset($_POST['monthly_bill']) && is_numeric($_POST['monthly_bill']) ? (float)$_POST['monthly_bill'] : 0.0;
    $expiry_date  = trim($_POST['expiry_date'] ?? '');
    $status       = trim($_POST['status'] ?? 'active');
    $auto_invoice = isset($_POST['auto_invoice']) ? 1 : 0; // checkbox

    // (বাংলা) ভ্যালিডেশন
    if ($name === '')        $errors[] = 'Name is required.';
    if ($pppoe_id === '')    $errors[] = 'PPPoE username is required.';
    if ($package_id <= 0)    $errors[] = 'Please select a package.';
    if ($monthly_bill < 0)   $errors[] = 'Monthly bill is invalid.';

    // (বাংলা) PPPoE uniqueness
    $chk = db()->prepare("SELECT id FROM clients WHERE pppoe_id = ? LIMIT 1");
    $chk->execute([$pppoe_id]);
    if ($chk->fetch()) $errors[] = 'PPPoE username already exists.';

    // (বাংলা) Mobile দেওয়া থাকলে তবেই ডুপ্লিকেট চেক
    if ($mobile !== '') {
        $chkM = db()->prepare("SELECT id FROM clients WHERE mobile = ? LIMIT 1");
        $chkM->execute([$mobile]);
        if ($chkM->fetch()) $errors[] = 'Mobile already exists.';
    }

    // (বাংলা) Photo upload (filename = ppppoe_id)
    $photo_url = null;
    if ($HAS_PHOTO_URL) {
        $ph = save_photo_with_pppoe_filename($pppoe_id);
        if (!$ph['ok']) $errors[] = $ph['error'] ?? 'Photo upload failed.';
        $photo_url = $ph['url'];
    }

    if (!$errors) {
        // (বাংলা) ডায়নামিক INSERT তৈরি + ট্রানজেকশন
        $pdo = db(); $pdo->beginTransaction();
        try {
            $cols = ['name','mobile','email','address','area','pppoe_id','package_id','router_id','monthly_bill','status'];
            $vals = [':name',':mobile',':email',':address',':area',':pppoe_id',':package_id',':router_id',':monthly_bill',':status'];
            $params = [
                ':name'=>$name,
                ':mobile'=> ($mobile === '') ? null : $mobile, // (বাংলা) খালি হলে NULL
                ':email'=> ($email === '') ? null : $email,
                ':address'=> ($address === '') ? null : $address,
                ':area'=> ($area === '') ? null : $area,
                ':pppoe_id'=>$pppoe_id,
                ':package_id'=>$package_id,
                ':router_id'=>$router_id ?: null,
                ':monthly_bill'=>$monthly_bill,
                ':status'=>$status
            ];

            //if ($HAS_CLIENT_CODE) { $cols[]='client_code'; $vals[]=':client_code'; $params[':client_code']= ($client_code==='')? null : $client_code; }
            if ($HAS_SUB_ZONE)    { $cols[]='sub_zone';     $vals[]=':sub_zone';     $params[':sub_zone']= ($sub_zone==='')? null : $sub_zone; }
            if ($HAS_PPPOE_PASS)  { $cols[]='pppoe_pass';   $vals[]=':pppoe_pass';   $params[':pppoe_pass']= ($pppoe_pass==='')? null : $pppoe_pass; }
            if ($HAS_PHOTO_URL)   { $cols[]='photo_url';    $vals[]=':photo_url';    $params[':photo_url']= $photo_url; }
            if ($expiry_date!==''){ $cols[]='expiry_date';  $vals[]=':expiry_date';  $params[':expiry_date']= $expiry_date; }
            if ($HAS_CREATED_AT)  { $cols[]='created_at';   $vals[]='NOW()'; }
            if ($HAS_UPDATED_AT)  { $cols[]='updated_at';   $vals[]='NOW()'; }

            $sql = "INSERT INTO clients (".implode(',', $cols).") VALUES (".implode(',', $vals).")";
            $ins = $pdo->prepare($sql);
            $ins->execute($params);
            $new_id = (int)$pdo->lastInsertId();

            // (বাংলা) MikroTik-এ PPP secret তৈরি (optional, best-effort)
            try {
                if ($router_id && $pppoe_id) {
                    $r = $pdo->prepare("SELECT ip, username, password, api_port FROM routers WHERE id=?");
                    $r->execute([$router_id]);
                    if ($router = $r->fetch(PDO::FETCH_ASSOC)) {
                        // profile = package name
                        $pp = $pdo->prepare("SELECT name FROM packages WHERE id=?");
                        $pp->execute([$package_id]);
                        $pkg = $pp->fetch(PDO::FETCH_ASSOC);
                        if ($pkg && !empty($pkg['name'])) {
                            $api = new RouterosAPI(); $api->debug = false;
                            if ($api->connect($router['ip'], $router['username'], $router['password'], $router['api_port'])) {
                                $sec = $api->comm("/ppp/secret/print", ["?name"=>$pppoe_id]);
                                if (!empty($sec[0][".id"])) {
                                    $api->comm("/ppp/secret/set", [
                                        ".id"      => $sec[0][".id"],
                                        "password" => $pppoe_pass,
                                        "profile"  => $pkg['name']
                                    ]);
                                } else {
                                    $api->comm("/ppp/secret/add", [
                                        "name"     => $pppoe_id,
                                        "password" => $pppoe_pass,
                                        "profile"  => $pkg['name'],
                                        "service"  => "pppoe"
                                    ]);
                                }
                                $api->disconnect();
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                // (বাংলা) রাউটার সংক্রান্ত ত্রুটি হলেও মূল সেভ সফল—চুপচাপ ignore
            }

            $pdo->commit();
            $notice = 'Client created successfully.';
            audit_client('client_create', $new_id, [
                'pppoe_id' => $pppoe_id,
                'name'     => $name,
                'mobile'   => $mobile,
                'router_id'=> $router_id,
                'package_id'=>$package_id,
            ]);
        } catch(Throwable $e){
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'Save failed: '.$e->getMessage();
        }

        // (বাংলা) সেভ সফল হলে এবং অটো-ইনভয়েস চাইলে — বর্তমান মাসের বিল বানাও
        if ($new_id && $auto_invoice && $monthly_bill > 0) {
            $new_invoice = create_first_invoice($new_id, (float)$monthly_bill, $package_id);
            if (!$new_invoice['ok']) {
                $notice .= ' (Invoice skipped: '.$new_invoice['message'].')';
            } else {
                $notice .= ' (Invoice #'.$new_invoice['invoice_id'].' created)';
            }
        }
    }
}

include __DIR__ . '/../partials/partials_header.php';
?>
<style>
.card-block{ border:6px solid #dfe3e8; border-radius:.75rem; background:#f5f6f8; }
.card-block .card-title{ font-weight:700; padding:.65rem .9rem; border-bottom:2px solid #dfe3e8; background:#e9ecef; }
.mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
.form-text.small{ font-size:.8rem; }
.req::after{ content:" *"; color:#dc3545; font-weight:700; }
</style>




<?php if (hasPermission('add.client')){ ?>



<div class="container-fluid py-3 text-start">
  <div class="mb-3 d-flex justify-content-between align-items-center">
    <h6 class="mb-0"><i class="bi bi-person-plus"></i> Add Client</h6>
    <div class="d-flex gap-2">
      <?php if ($new_id): ?>
        <a class="btn btn-light btn-sm" href="/public/client_view.php?id=<?= (int)$new_id ?>"><i class="bi bi-eye"></i> View</a>
      <?php endif; ?>
      <a class="btn btn-outline-secondary btn-sm" href="/public/clients.php"><i class="bi bi-arrow-left"></i> Back</a></div>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= h(implode(' | ', $errors)) ?></div>
  <?php elseif ($notice): ?>
    <div class="alert alert-success"><i class="bi bi-check2-circle"></i> <?= h($notice) ?></div>
  <?php endif; ?>

  <?php if (!$HAS_PHOTO_URL): ?>
    <div class="alert alert-warning py-2">
      <strong>Heads up:</strong> photo cannot be saved because <code>clients.photo_url</code> column is missing.
      Run once: <code>ALTER TABLE clients ADD COLUMN photo_url VARCHAR(255) NULL;</code>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="needs-validation" novalidate>
    <div class="row g-3">
      <!-- Account -->
      <div class="col-12 col-lg-4">
        <div class="card-block h-100">
          <div class="card-title">Account</div>
          <div class="p-3">
		  
            <div class="mb-2">
              <label class="form-label req">Name</label>
              <input type="text" name="name" class="form-control form-control-sm" value="<?= h($_POST['name'] ?? '') ?>" required>
            </div>

            <div class="mb-2">
              <label class="form-label">Area</label>
              <input type="text" name="area" class="form-control form-control-sm" value="<?= h($_POST['area'] ?? '') ?>">
            </div>

            <?php if ($HAS_SUB_ZONE): ?>
            <div class="mb-2">
              <label class="form-label">Sub Zone</label>
              <input type="text" name="sub_zone" class="form-control form-control-sm" value="<?= h($_POST['sub_zone'] ?? '') ?>">
            </div>
            <?php endif; ?>

            <div class="mb-2">
              <label class="form-label">Address</label>
              <textarea name="address" class="form-control form-control-sm" rows="2"><?= h($_POST['address'] ?? '') ?></textarea>
            </div>

            <div class="mb-2">
              <label class="form-label">Mobile</label>
              <input type="text" name="mobile" class="form-control form-control-sm" value="<?= h($_POST['mobile'] ?? '') ?>">
              <div class="form-text small">Optional; left blank will be saved as NULL.</div>
            </div>

            <div class="mb-2">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control form-control-sm" value="<?= h($_POST['email'] ?? '') ?>">
            </div>
          </div>
        </div>
      </div>

      <!-- Billing -->
      <div class="col-12 col-lg-4">
        <div class="card-block h-100">
          <div class="card-title">Billing</div>
          <div class="p-3">
            <div class="mb-2">
              <label class="form-label req">Package</label>
              <select name="package_id" id="package_id" class="form-select form-select-sm" required>
                <option value="">-- Select --</option>
                <?php foreach ($packages as $p): ?>
                  <option
                    value="<?= (int)$p['id'] ?>"
                    data-price="<?= is_numeric($p['price']) ? (0+$p['price']) : 0 ?>"
                    <?= (isset($_POST['package_id']) && (int)$_POST['package_id']===(int)$p['id'])?'selected':'' ?>
                  >
                    <?= h($p['name']) ?> <?= is_numeric($p['price'])? '— '.(0+$p['price']):'' ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="form-text small">(Package name = MikroTik PPP profile name 1:1)</div>
            </div>

            <div class="mb-2">
              <label class="form-label req">Monthly Bill</label>
              <input type="number" step="0.01" name="monthly_bill" id="monthly_bill" class="form-control form-control-sm" value="<?= h($_POST['monthly_bill'] ?? '0') ?>" required>
              <div class="form-text small" id="autoHint">Auto from package price when selection changes.</div>
            </div>

            <div class="mb-2">
              <label class="form-label">Expiry Date</label>
              <input type="date" name="expiry_date" class="form-control form-control-sm" value="<?= h($_POST['expiry_date'] ?? '') ?>">
            </div>

            <div class="mb-2">
              <label class="form-label">Status</label>
              <select name="status" class="form-select form-select-sm">
                <?php
                  $opts = ['active'=>'Active','inactive'=>'Inactive','pending'=>'Pending','hold'=>'Hold','disabled'=>'Disabled','blocked'=>'Blocked','expired'=>'Expired'];
                  $cur  = strtolower(trim($_POST['status'] ?? 'active'));
                  foreach($opts as $k=>$v){
                    echo '<option value="'.h($k).'"'.($cur===$k?' selected':'').'>'.h($v).'</option>';
                  }
                ?>
              </select>
            </div>

            <div class="form-check mt-2">
              <input class="form-check-input" type="checkbox" id="auto_invoice" name="auto_invoice" value="1" <?= isset($_POST['auto_invoice']) ? ( ($_POST['auto_invoice']?'checked':'') ) : 'checked' ?>>
              <label class="form-check-label" for="auto_invoice">Generate first invoice now</label>
            </div>
          </div>
        </div>
      </div>

      <!-- Server / PPP + Photo -->
      <div class="col-12 col-lg-4">
        <div class="card-block h-100">
          <div class="card-title">Server / PPP</div>
          <div class="p-3">
            <div class="mb-2">
              <label class="form-label">Router</label>
              <select name="router_id" class="form-select form-select-sm">
                <option value="">-- Select --</option>
                <?php foreach ($routers as $r): ?>
                  <option value="<?= (int)$r['id'] ?>" <?= (isset($_POST['router_id']) && (int)$_POST['router_id']===(int)$r['id'])?'selected':'' ?>>
                    <?= h($r['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="mb-2">
              <label class="form-label req">PPPoE Username</label>
              <input type="text" name="pppoe_id" class="form-control form-control-sm mono" value="<?= h($_POST['pppoe_id'] ?? '') ?>" required>
            </div>

            <?php if ($HAS_PPPOE_PASS): ?>
            <div class="mb-2">
              <label class="form-label">PPPoE Password</label>
              <input type="text" name="pppoe_pass" class="form-control form-control-sm mono" value="<?= h($_POST['pppoe_pass'] ?? '') ?>">
            </div>
            <?php endif; ?>

            <div class="card mt-3">
              <div class="card-header fw-bold">Profile Photo</div>
              <div class="card-body">
                <input type="file" name="photo" id="photo" accept="image/*" class="form-control form-control-sm" <?= $HAS_PHOTO_URL?'':'disabled' ?>>
                <div class="form-text small">Supported: JPG, PNG, WebP • Max 3MB • Filename will be PPPoE-ID</div>
              </div>
            </div>

          </div>
        </div>
      </div>
    </div>

    <div class="d-flex justify-content-end mt-3">
      <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save2"></i> Create Client</button>
    </div>
  </form>
</div>

<script>
// (বাংলা) প্যাকেজ সিলেক্ট করলে price অটো-ফিল
(function(){
  const sel = document.getElementById('package_id');
  const bill= document.getElementById('monthly_bill');
  function setBillFromPackage(){
    const opt = sel?.options?.[sel.selectedIndex];
    const price = parseFloat(opt?.dataset?.price || '0');
    if (!isNaN(price)) bill.value = price.toFixed(2);
  }
  sel?.addEventListener('change', setBillFromPackage);

  // (বাংলা) পেজ লোডে যদি ইউজার কিছু না দিয়ে থাকে, সিলেক্টেড প্যাকেজ প্রাইসে সেট করো
  if (!bill.value || parseFloat(bill.value) === 0) setBillFromPackage();
})();
</script>


<?php } ?> 





<?php include __DIR__ . '/../partials/partials_footer.php'; ?>
