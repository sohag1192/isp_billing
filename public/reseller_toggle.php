<?php
// /public/reseller_toggle.php
// UI English; Comments Bangla — Toggle Active/Inactive (GET confirm → POST do)

declare(strict_types=1);

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

// (অপশনাল) ACL
$acl_file = __DIR__ . '/../app/acl.php';
if (is_file($acl_file)) require_once $acl_file;
if (function_exists('require_perm')) require_perm('reseller.manage');

// CSRF
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf_token'];

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$id = (int)($_REQUEST['id'] ?? 0);
if($id<=0){ http_response_code(400); exit('Invalid id'); }

if($_SERVER['REQUEST_METHOD'] === 'POST'){
  if(($_POST['csrf'] ?? '') !== $csrf){ http_response_code(403); exit('Bad CSRF'); }
  $pdo->prepare("UPDATE resellers SET is_active = 1 - is_active, updated_at=NOW() WHERE id=?")->execute([$id]);
  header('Location: reseller_view.php?id='.$id); exit;
}

// GET confirm UI
require_once __DIR__ . '/../partials/partials_header.php';
?>
<div class="container my-4">
  <h5>Toggle Reseller Status</h5>
  <p>Are you sure you want to toggle this reseller?</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
    <button class="btn btn-warning">Yes, toggle</button>
    <a class="btn btn-secondary" href="reseller_view.php?id=<?= (int)$id ?>">Cancel</a>
  </form>
</div>
<?php require_once __DIR__ . '/../partials/partials_footer.php'; ?>
