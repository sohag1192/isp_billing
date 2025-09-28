<?php
// /public/reseller_packages.php
// UI: English; Comments: বাংলা — রিসেলার প্যাকেজ রেট সেটআপ (fixed/percent)

declare(strict_types=1);
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
$acl_file = __DIR__ . '/../app/acl.php';
if (is_file($acl_file)) require_once $acl_file;

if(function_exists('require_perm')) require_perm('reseller.pricing');

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf_token'];

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$reseller_id = (int)($_GET['id'] ?? 0);
if($reseller_id<=0){ http_response_code(400); exit('Invalid reseller'); }

// বাংলা: POST → Save/Update
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(($_POST['csrf'] ?? '') !== $csrf){ http_response_code(403); exit('Bad CSRF'); }

  $package_id     = (int)($_POST['package_id'] ?? 0);
  $mode           = $_POST['mode'] === 'percent' ? 'percent' : 'fixed';
  $price_override = $_POST['price_override'] !== '' ? (float)$_POST['price_override'] : null;
  $share_percent  = $_POST['share_percent'] !== '' ? (float)$_POST['share_percent'] : null;
  $note           = trim($_POST['note'] ?? '');

  // বাংলা: স্যানিটি
  if($mode==='fixed' && $price_override===null){ $err='Price (fixed) is required'; }
  if($mode==='percent' && ($share_percent===null || $share_percent<=0)){ $err='Share % is required'; }

  if(empty($err) && $package_id>0){
    // upsert
    $st = $pdo->prepare("INSERT INTO reseller_packages (reseller_id, package_id, mode, price_override, share_percent, note)
                         VALUES (?,?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE mode=VALUES(mode), price_override=VALUES(price_override),
                                                 share_percent=VALUES(share_percent), note=VALUES(note)");
    $st->execute([$reseller_id,$package_id,$mode,$price_override,$share_percent,$note]);
    $_SESSION['flash_ok'] = 'Saved';
    header("Location: reseller_packages.php?id=".$reseller_id);
    exit;
  }else{
    $_SESSION['flash_err'] = $err ?? 'Failed';
  }
}

// বাংলা: রিসেলার, প্যাকেজ লোড
$rz = $pdo->prepare("SELECT id, code, name, is_active FROM resellers WHERE id=?");
$rz->execute([$reseller_id]);
$reseller = $rz->fetch(PDO::FETCH_ASSOC);
if(!$reseller){ http_response_code(404); exit('Reseller not found'); }

$pk = $pdo->query("SELECT id, name, price FROM packages ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$map = $pdo->prepare("SELECT package_id, mode, price_override, share_percent, note
                        FROM reseller_packages WHERE reseller_id=?");
$map->execute([$reseller_id]);
$current = [];
foreach($map as $row){
  $current[(int)$row['package_id']] = $row;
}
?>
<?php require_once __DIR__ . '/../partials/partials_header.php'; ?>
<div class="container my-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Reseller Pricing — <?= h($reseller['name']) ?></h4>
    <a class="btn btn-sm btn-outline-secondary" href="resellers.php">Back</a>
  </div>

  <?php if(!empty($_SESSION['flash_ok'])): ?>
    <div class="alert alert-success py-2"><?= h($_SESSION['flash_ok']); unset($_SESSION['flash_ok']); ?></div>
  <?php endif; ?>
  <?php if(!empty($_SESSION['flash_err'])): ?>
    <div class="alert alert-danger py-2"><?= h($_SESSION['flash_err']); unset($_SESSION['flash_err']); ?></div>
  <?php endif; ?>

  <div class="card">
    <div class="card-body">
      <form method="post" class="row g-2 align-items-end">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <div class="col-md-4">
          <label class="form-label">Package</label>
          <select name="package_id" class="form-select" required>
            <option value="">Select package</option>
            <?php foreach($pk as $p): ?>
              <option value="<?= (int)$p['id'] ?>">
                <?= h($p['name'].' — Base '.number_format((float)$p['price'],2)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Mode</label>
          <select name="mode" class="form-select" id="modeSel">
            <option value="fixed">Fixed</option>
            <option value="percent">Percent</option>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Price (fixed)</label>
          <input type="number" step="0.01" name="price_override" class="form-control" placeholder="e.g. 250">
        </div>
        <div class="col-md-2">
          <label class="form-label">Share %</label>
          <input type="number" step="0.001" name="share_percent" class="form-control" placeholder="e.g. 50">
        </div>
        <div class="col-md-2">
          <label class="form-label">Note</label>
          <input type="text" name="note" class="form-control" placeholder="optional">
        </div>
        <div class="col-md-12">
          <button class="btn btn-primary">Save</button>
        </div>
      </form>
      <hr>

      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
          <thead class="table-light">
            <tr>
              <th>Package</th>
              <th>Base Price</th>
              <th>Mode</th>
              <th>Reseller Price</th>
              <th>Share %</th>
              <th>Commission vs Base</th>
              <th>Note</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($pk as $p):
              $pid = (int)$p['id'];
              $base = (float)$p['price'];
              $row = $current[$pid] ?? null;
              $mode = $row['mode'] ?? '';
              $rp = null;
              if($row){
                if($mode==='fixed'){ $rp = (float)$row['price_override']; }
                elseif($mode==='percent' && $row['share_percent']!==''){
                  $rp = round($base * ((float)$row['share_percent']/100), 2);
                }
              }
              $comm = $rp!==null ? round($base - $rp, 2) : null;
            ?>
              <tr>
                <td><?= h($p['name']) ?></td>
                <td><?= number_format($base,2) ?></td>
                <td><?= h($mode ?: '-') ?></td>
                <td><?= $rp!==null ? number_format($rp,2) : '-' ?></td>
                <td><?= isset($row['share_percent']) && $row['share_percent']!==null ? h($row['share_percent']).'%' : '-' ?></td>
                <td><?= $comm!==null ? number_format($comm,2) : '-' ?></td>
                <td><?= h($row['note'] ?? '') ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if(empty($pk)): ?>
              <tr><td colspan="7" class="text-center text-muted">No packages</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <small class="text-muted">* Commission = Base Price − Reseller Price (shown for preview)</small>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../partials/partials_footer.php'; ?>

<script>
// বাংলা: UI হিন্ট—mode নির্বাচন করলে কোথায় ভ্যালু দেবেন
(function(){
  const modeSel = document.getElementById('modeSel');
  if(!modeSel) return;
  const fix = document.querySelector('input[name="price_override"]');
  const per = document.querySelector('input[name="share_percent"]');
  function sync(){
    if(modeSel.value==='fixed'){ fix.removeAttribute('disabled'); per.setAttribute('disabled','disabled'); }
    else { per.removeAttribute('disabled'); fix.setAttribute('disabled','disabled'); }
  }
  modeSel.addEventListener('change', sync);
  sync();
})();
</script>
