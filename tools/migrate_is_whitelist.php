<?php
// /public/tools/migrate_is_whitelist.php
// UI: English; Comments: বাংলা — এককালীন মাইগ্রেশন স্ক্রিপ্ট
declare(strict_types=1);
error_reporting(E_ALL); ini_set('display_errors','0'); ini_set('log_errors','1');

$ROOT = dirname(__DIR__, 1);
require_once $ROOT . '/app/require_login.php';
require_once $ROOT . '/app/db.php';
$acl_file = $ROOT . '/app/acl.php'; if (is_file($acl_file)) require_once $acl_file;

if (function_exists('require_perm')) { require_perm('admin.migrate'); }
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function tbl_exists(PDO $pdo, string $t): bool {
  try { $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
        $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
        $q->execute([$db,$t]); return (bool)$q->fetchColumn(); } catch(Throwable $e){ return false; }
}
function col_exists(PDO $pdo, string $t, string $c): bool {
  try { $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
        $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
        $q->execute([$db,$t,$c]); return (bool)$q->fetchColumn(); } catch(Throwable $e){ return false; }
}

$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$msg = ''; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($CSRF, (string)($_POST['csrf'] ?? ''))) { $err='Invalid CSRF.'; }
  else {
    $CT = tbl_exists($pdo,'clients') ? 'clients' : (tbl_exists($pdo,'customers')?'customers': (tbl_exists($pdo,'subscribers')?'subscribers': null));
    if (!$CT) { $err='Clients table not found (clients/customers/subscribers).'; }
    else if (col_exists($pdo,$CT,'is_whitelist')) { $msg='Column already exists.'; }
    else {
      try {
        $pdo->exec("ALTER TABLE `$CT` ADD COLUMN `is_whitelist` TINYINT(1) NOT NULL DEFAULT 0");
        $msg="Column `is_whitelist` added to `$CT`.";
      } catch(Throwable $e){ $err='Migration failed: '.$e->getMessage(); }
    }
  }
}

require_once $ROOT . '/partials/partials_header.php'; ?>
<div class="container py-4">
  <h5 class="mb-3">DB Migration: Add <code>is_whitelist</code> to Clients</h5>
  <?php if ($msg): ?><div class="alert alert-success py-2"><?php echo h($msg); ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger py-2"><?php echo h($err); ?></div><?php endif; ?>
  <form method="post" class="card p-3">
    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
    <p class="mb-2">This adds <code>is_whitelist TINYINT(1) DEFAULT 0</code> to your clients table.</p>
    <button class="btn btn-primary btn-sm"><i class="bi bi-database-add"></i> Run Migration</button>
  </form>
</div>
<?php require_once $ROOT . '/partials/partials_footer.php';
