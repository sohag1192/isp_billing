<?php
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/routeros_api.class.php';
require_once __DIR__ . '/../app/audit.php'; // ✅ Audit helper

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* --------- helpers --------- */
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
 * Save uploaded photo for a client using PPPoE ID for the filename.
 * - Filename: <pppoe-id-sanitized>.<ext>  (no random)
 * - Overwrites existing same-name file; removes previous file if path changed.
 *
 * @return array ['ok'=>bool, 'url'=>?string, 'error'=>?string]
 *
 * (বাংলা) pppoe_id থেকে নিরাপদ ফাইলনেম বানিয়ে সেভ করা;
 * আগের ফাইল থাকলে নিরাপদভাবে রিমুভ/ওভাররাইট।
 */
function handle_client_photo_upload(int $client_id, string $pppoe_id, ?string $existing_url = null): array {
    $out = ['ok'=>true, 'url'=>$existing_url, 'error'=>null];

    // Remove request
    if (!empty($_POST['remove_photo']) && $_POST['remove_photo'] === '1') {
        // delete old file if it lives under uploads/clients
        if ($existing_url && str_starts_with($existing_url, '/uploads/clients/')) {
            $absOld = realpath(__DIR__ . '/..' . $existing_url);
            $baseUploads = realpath(__DIR__ . '/../uploads/clients');
            if ($absOld && $baseUploads && str_starts_with($absOld, $baseUploads)) {
                @unlink($absOld);
            }
        }
        $out['url'] = null;
        return $out;
    }

    // No new file
    if (empty($_FILES['photo']) || (int)($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $out;
    }

    $f = $_FILES['photo'];
    if ($f['error'] !== UPLOAD_ERR_OK) { $out['ok']=false; $out['error']='Upload failed.'; return $out; }

    // Validate
    $maxBytes = 3 * 1024 * 1024; // 3MB
    if ($f['size'] > $maxBytes) { $out['ok']=false; $out['error']='Max 3MB allowed.'; return $out; }

    $mime = function_exists('finfo_open') ? (function($tmp){
        $fi=finfo_open(FILEINFO_MIME_TYPE); $m=finfo_file($fi,$tmp); finfo_close($fi); return $m;
    })($f['tmp_name']) : mime_content_type($f['tmp_name']);

    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if (!isset($allowed[$mime])) { $out['ok']=false; $out['error']='Only JPG/PNG/WebP.'; return $out; }

    // Destination dir
    $upDir = __DIR__ . '/../uploads/clients';
    if (!is_dir($upDir)) { @mkdir($upDir, 0775, true); }

    // Sanitize PPPoE ID for filename
    $slug = strtolower($pppoe_id);
    $slug = preg_replace('/[^a-z0-9-_]+/i', '-', $slug);
    $slug = trim($slug, '-_');
    if ($slug === '') $slug = 'client-'.$client_id;

    $ext   = $allowed[$mime];
    $fname = $slug.'.'.$ext;                          // ← no random
    $dest  = $upDir . '/' . $fname;
    $destWeb = '/uploads/clients/'.$fname;

    // If a file with same name exists, overwrite
    if (file_exists($dest)) @unlink($dest);

    // If previous URL is different file, delete that too (keeps storage clean)
    if ($existing_url && $existing_url !== $destWeb && str_starts_with($existing_url, '/uploads/clients/')) {
        $absOld = realpath(__DIR__ . '/..' . $existing_url);
        $baseUploads = realpath($upDir);
        if ($absOld && $baseUploads && str_starts_with($absOld, $baseUploads)) {
            @unlink($absOld);
        }
    }

    if (!move_uploaded_file($f['tmp_name'], $dest)) { $out['ok']=false; $out['error']='Could not save file.'; return $out; }

    $out['url'] = $destWeb;
    return $out;
}

/* --------- load client & lists --------- */
$client_id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$client_id) { header("Location: clients.php"); exit; }

$sqlClient = "SELECT c.*,
                     p.name AS package_name,
                     r.name AS router_name, r.ip AS router_ip, r.username AS r_user, r.password AS r_pass, r.api_port
              FROM clients c
              LEFT JOIN packages p ON c.package_id = p.id
              LEFT JOIN routers  r ON c.router_id  = r.id
              WHERE c.id = ?";
$st = db()->prepare($sqlClient);
$st->execute([$client_id]);
$client = $st->fetch(PDO::FETCH_ASSOC);
if (!$client) { header("Location: clients.php"); exit; }

$packages = db()->query("SELECT id, name, price FROM packages ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$routers  = db()->query("SELECT id, name FROM routers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

/* --------- optional columns present? --------- */
$HAS_SUB_ZONE    = db_has_column('clients','sub_zone');
$HAS_PHOTO_URL   = db_has_column('clients','photo_url');
$HAS_PPPOE_PASS  = db_has_column('clients','pppoe_pass');
$HAS_UPDATED_AT  = db_has_column('clients','updated_at');

/* --------- process save --------- */
$errors = [];
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name         = trim($_POST['name'] ?? $client['name']);
    $mobile       = trim($_POST['mobile'] ?? $client['mobile']);
    $email        = trim($_POST['email'] ?? $client['email']);
    $address      = trim($_POST['address'] ?? $client['address']);
    $area         = trim($_POST['area'] ?? $client['area']);
    $sub_zone     = trim($_POST['sub_zone'] ?? ($client['sub_zone'] ?? ''));
    $pppoe_id     = trim($_POST['pppoe_id'] ?? $client['pppoe_id']);
    $pppoe_pass   = trim($_POST['pppoe_pass'] ?? ($client['pppoe_pass'] ?? ''));
    $package_id   = intval($_POST['package_id'] ?? $client['package_id']);
    $router_id    = intval($_POST['router_id']  ?? $client['router_id']);
    $monthly_bill = is_numeric($_POST['monthly_bill'] ?? null) ? (0+$_POST['monthly_bill']) : (0+$client['monthly_bill']);
    $expiry_date  = trim($_POST['expiry_date'] ?? ($client['expiry_date'] ?? ''));
    $status       = trim($_POST['status'] ?? $client['status']);

    if ($name === '')        $errors[] = 'Name is required.';
    if ($pppoe_id === '')    $errors[] = 'PPPoE username is required.';
    if ($package_id <= 0)    $errors[] = 'Please select a package.';
    if ($monthly_bill < 0)   $errors[] = 'Monthly bill is invalid.';

    // Handle photo (pppoe-based filename)
    $new_photo_url = $client['photo_url'] ?? null;
    if ($HAS_PHOTO_URL) {
        $photoResult = handle_client_photo_upload($client_id, $pppoe_id, $new_photo_url);
        if (!$photoResult['ok']) $errors[] = $photoResult['error'] ?? 'Photo upload failed.';
        $new_photo_url = $photoResult['url'];
    }

    if (!$errors) {
        // Build dynamic UPDATE
        $sets = [
            'name = :name',
            'mobile = :mobile',
            'email = :email',
            'address = :address',
            'area = :area',
            'pppoe_id = :pppoe_id',
            'package_id = :package_id',
            'router_id = :router_id',
            'monthly_bill = :monthly_bill',
            'expiry_date = :expiry_date',
            'status = :status'
        ];
        $params = [
            ':name'=>$name, ':mobile'=>$mobile, ':email'=>$email, ':address'=>$address, ':area'=>$area,
            ':pppoe_id'=>$pppoe_id, ':package_id'=>$package_id, ':router_id'=>$router_id,
            ':monthly_bill'=>$monthly_bill, ':expiry_date'=>$expiry_date ?: null, ':status'=>$status,
            ':id'=>$client_id
        ];
        if ($HAS_SUB_ZONE)    { $sets[] = 'sub_zone = :sub_zone';      $params[':sub_zone'] = $sub_zone; }
        if ($HAS_PPPOE_PASS)  { $sets[] = 'pppoe_pass = :pppoe_pass';  $params[':pppoe_pass'] = $pppoe_pass; }
        if ($HAS_PHOTO_URL)   { $sets[] = 'photo_url = :photo_url';    $params[':photo_url']  = $new_photo_url; }
        if ($HAS_UPDATED_AT)  { $sets[] = 'updated_at = NOW()'; }

        $sqlUp = "UPDATE clients SET ".implode(',', $sets)." WHERE id = :id";
        $u = db()->prepare($sqlUp);
        $u->execute($params);

        // PPP profile on MikroTik if package changed
        $old_pkg_id = intval($client['package_id']);           // পুরনো প্যাকেজ আইডি (সেভের আগের)
        if ($package_id && $package_id !== $old_pkg_id) {
            $stp = db()->prepare("SELECT name FROM packages WHERE id=?");
            $stp->execute([$package_id]);
            $pkg = $stp->fetch(PDO::FETCH_ASSOC);
            if ($pkg && !empty($pkg['name']) && !empty($client['router_ip'])) {
                $profileName = $pkg['name']; // (বাংলা) প্যাকেজ নাম = PPP প্রোফাইল নাম
                $api = new RouterosAPI(); $api->debug = false;
                if ($api->connect($client['router_ip'], $client['r_user'], $client['r_pass'], $client['api_port'])) {
                    $sec = $api->comm("/ppp/secret/print", ["?name" => $pppoe_id]);
                    if (!empty($sec[0][".id"])) {
                        $api->comm("/ppp/secret/set", [".id"=>$sec[0][".id"], "profile"=>$profileName]);
                    }
                    $api->disconnect();
                } else {
                    $notice = 'Saved, but failed to connect to RouterOS for PPP profile update.';
                }
            }

            // ✅ Audit log: package change
            audit('package_change', 'client', (int)$client_id, [
                'pppoe_id'    => $client['pppoe_id'] ?? '',
                'name'        => $client['name'] ?? '',
                'from_id'     => $old_pkg_id,
                'from_name'   => $client['package_name'] ?? '',
                'to_id'       => (int)$package_id,
                'to_name'     => $pkg['name'] ?? '',
                'router_id'   => (int)($client['router_id'] ?? 0),
                'router_name' => $client['router_name'] ?? '',
            ]);
        }

        // Reload fresh client
        $st = db()->prepare($sqlClient);
        $st->execute([$client_id]);
        $client = $st->fetch(PDO::FETCH_ASSOC);

        $notice = $notice ? ('Saved successfully. '.$notice) : 'Saved successfully.';
    }
}

/* --------- UI helpers --------- */
$photo_url = $HAS_PHOTO_URL ? trim($client['photo_url'] ?? '') : '';
$client_initial = mb_strtoupper(mb_substr($client['name'] ?? '?', 0, 1, 'UTF-8'));

include __DIR__ . '/../partials/partials_header.php';
?>
<style>
.card-block{ border:1px solid #dfe3e8; border-radius:.75rem; background:#f5f6f8; }
.card-block .card-title{ font-weight:700; padding:.65rem .9rem; border-bottom:1px solid #dfe3e8; background:#e9ecef; }
.header-avatar{ width:56px; height:56px; border-radius:50%; overflow:hidden; border:1px solid #e5e7eb; background:#f2f4f7; }
.header-avatar img{ width:100%; height:100%; object-fit:cover; display:block; }
.header-avatar .avatar-fallback{ width:100%; height:100%; display:flex; align-items:center; justify-content:center; font-weight:700; color:#5c6b7a; }
.mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
.form-text.small{ font-size:.8rem; }
.req::after{ content:" *"; color:#dc3545; font-weight:700; }
</style>

<div class="container-fluid py-3 text-start">
  <div class="mb-3 d-flex flex-wrap align-items-center gap-2">
    <div class="d-flex align-items-center gap-2">
      <div class="header-avatar">
        <?php if ($photo_url): ?>
          <img id="topPreview" src="<?= h($photo_url) ?>" alt="<?= h($client['name'] ?? 'Photo') ?>">
        <?php else: ?>
          <div class="avatar-fallback"><?= h($client_initial) ?></div>
        <?php endif; ?>
      </div>
      <div class="d-flex align-items-center gap-2">
        <i class="bi bi-person-lines-fill"></i>
        <span class="fw-bold">Edit Client — <?= h($client['name']) ?></span>
        <span class="badge bg-secondary mono"><?= h($client['client_code'] ?? '') ?></span>
      </div>
    </div>
    <div class="ms-auto d-flex flex-wrap gap-2">
      <a href="/public/client_view.php?id=<?= (int)$client['id'] ?>" class="btn btn-light btn-sm"><i class="bi bi-eye"></i> View</a>
      <a href="/public/clients.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Back</a>
    </div>
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
    <input type="hidden" name="id" value="<?= (int)$client['id'] ?>">

    <div class="row g-3">
      <!-- Account + Photo -->
      <div class="col-12 col-lg-4">
        <div class="card-block h-100">
          <div class="card-title">Account</div>
          <div class="p-3">
            <div class="mb-2">
              <label class="form-label req">Name</label>
              <input type="text" name="name" class="form-control form-control-sm" value="<?= h($client['name']) ?>" required>
            </div>
            <div class="mb-2">
              <label class="form-label">Area</label>
              <input type="text" name="area" class="form-control form-control-sm" value="<?= h($client['area']) ?>">
            </div>
            <?php if ($HAS_SUB_ZONE): ?>
            <div class="mb-2">
              <label class="form-label">Sub Zone</label>
              <input type="text" name="sub_zone" class="form-control form-control-sm" value="<?= h($client['sub_zone'] ?? '') ?>">
            </div>
            <?php endif; ?>
            <div class="mb-2">
              <label class="form-label">Address</label>
              <textarea name="address" class="form-control form-control-sm" rows="2"><?= h($client['address']) ?></textarea>
            </div>

            <hr>

            <div class="mb-2">
              <label class="form-label">Mobile</label>
              <input type="text" name="mobile" class="form-control form-control-sm" value="<?= h($client['mobile']) ?>">
            </div>
            <div class="mb-2">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control form-control-sm" value="<?= h($client['email']) ?>">
            </div>

            <hr>

            <!-- Photo -->
            <div class="card">
              <div class="card-header fw-bold">Profile Photo</div>
              <div class="card-body">
                <div class="d-flex align-items-center gap-3">
                  <div class="rounded-circle overflow-hidden border" style="width:80px;height:80px;background:#f2f4f7">
                    <?php if ($photo_url): ?>
                      <img id="photoPreview" src="<?= h($photo_url) ?>" alt="Photo" style="width:100%;height:100%;object-fit:cover">
                    <?php else: ?>
                      <img id="photoPreview" src="/assets/img/avatar_placeholder.png" alt="Photo" style="width:100%;height:100%;object-fit:cover">
                    <?php endif; ?>
                  </div>
                  <div class="flex-grow-1">
                    <input type="file" name="photo" id="photo" accept="image/*" class="form-control form-control-sm mb-1" <?= $HAS_PHOTO_URL?'':'disabled' ?>>
                    <div class="form-text small">Supported: JPG, PNG, WebP • Max 3MB</div>
                    <?php if ($HAS_PHOTO_URL && $photo_url): ?>
                      <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" value="1" id="remove_photo" name="remove_photo">
                        <label class="form-check-label" for="remove_photo">Remove current photo</label>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
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
              <select name="package_id" class="form-select form-select-sm" required>
                <option value="">-- Select --</option>
                <?php foreach ($packages as $p): ?>
                  <option value="<?= (int)$p['id'] ?>" <?= ((int)$client['package_id']===(int)$p['id'])?'selected':'' ?>>
                    <?= h($p['name']) ?> <?= is_numeric($p['price'])? '— '.(0+$p['price']):'' ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="form-text small">(Package name = MikroTik PPP profile name 1:1)</div>
            </div>
            <div class="mb-2">
              <label class="form-label req">Monthly Bill</label>
              <input type="number" step="0.01" name="monthly_bill" class="form-control form-control-sm" value="<?= h($client['monthly_bill']) ?>" required>
            </div>
            <div class="mb-2">
              <label class="form-label">Expiry Date</label>
              <input type="date" name="expiry_date" class="form-control form-control-sm" value="<?= h($client['expiry_date'] ?? '') ?>">
            </div>
            <div class="mb-2">
              <label class="form-label">Status</label>
              <select name="status" class="form-select form-select-sm">
                <?php
                  $opts = ['active'=>'Active','inactive'=>'Inactive','pending'=>'Pending','hold'=>'Hold','disabled'=>'Disabled','blocked'=>'Blocked','expired'=>'Expired'];
                  $cur  = strtolower(trim($client['status'] ?? 'active'));
                  foreach($opts as $k=>$v){
                    echo '<option value="'.h($k).'"'.($cur===$k?' selected':'').'>'.h($v).'</option>';
                  }
                ?>
              </select>
            </div>
          </div>
        </div>
      </div>

      <!-- Server / PPP -->
      <div class="col-12 col-lg-4">
        <div class="card-block h-100">
          <div class="card-title">Server / PPP</div>
          <div class="p-3">
            <div class="mb-2">
              <label class="form-label">Router</label>
              <select name="router_id" class="form-select form-select-sm">
                <option value="">-- Select --</option>
                <?php foreach ($routers as $r): ?>
                  <option value="<?= (int)$r['id'] ?>" <?= ((int)$client['router_id']===(int)$r['id'])?'selected':'' ?>>
                    <?= h($r['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="mb-2">
              <label class="form-label req">PPPoE Username</label>
              <input type="text" name="pppoe_id" class="form-control form-control-sm mono" value="<?= h($client['pppoe_id']) ?>" required>
            </div>

            <?php if ($HAS_PPPOE_PASS): ?>
            <div class="mb-2">
              <label class="form-label">PPPoE Password</label>
              <input type="text" name="pppoe_pass" class="form-control form-control-sm mono" value="<?= h($client['pppoe_pass'] ?? '') ?>">
            </div>
            <?php endif; ?>

          </div>
        </div>
      </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mt-3">
      <span class="text-muted small">Client Code: <span class="mono"><?= h($client['client_code'] ?? '') ?></span></span>
      <div class="d-flex gap-2">
        <a href="/public/client_view.php?id=<?= (int)$client['id'] ?>" class="btn btn-outline-secondary btn-sm">Cancel</a>
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save2"></i> Save Changes</button>
      </div>
    </div>
  </form>
</div>

<script>
// (বাংলা) photo preview
document.getElementById('photo')?.addEventListener('change', function(){
  const [file] = this.files || [];
  if(!file) return;
  const obj = URL.createObjectURL(file);
  const img1 = document.getElementById('photoPreview');
  const img2 = document.getElementById('topPreview');
  if(img1) img1.src = obj;
  if(img2) img2.src = obj;
});
</script>

<?php include __DIR__ . '/../partials/partials_footer.php'; ?>
