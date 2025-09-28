<?php
// /public/migrate_wallet_approvals.php
// Add approval fields to wallet_transfers and set safe defaults
declare(strict_types=1);
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$log = [];

try {
  // ensure table exists (created by previous migration)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS wallet_transfers (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      from_account_id INT NOT NULL,
      to_account_id   INT NOT NULL,
      amount DECIMAL(12,2) NOT NULL,
      method VARCHAR(50) NULL,
      ref_no VARCHAR(100) NULL,
      notes VARCHAR(255) NULL,
      created_by INT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX(from_account_id), INDEX(to_account_id), INDEX(created_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  // columns
  $cols = $pdo->query("SHOW COLUMNS FROM wallet_transfers")->fetchAll(PDO::FETCH_COLUMN);
  $have = array_flip($cols ?: []);

  if (!isset($have['status'])) {
    $pdo->exec("ALTER TABLE wallet_transfers ADD COLUMN status ENUM('pending','approved','rejected') DEFAULT 'approved'");
    $log[] = "Added status (default approved for legacy rows).";
  }
  if (!isset($have['approved_by'])) {
    $pdo->exec("ALTER TABLE wallet_transfers ADD COLUMN approved_by INT NULL");
    $log[] = "Added approved_by";
  }
  if (!isset($have['approved_at'])) {
    $pdo->exec("ALTER TABLE wallet_transfers ADD COLUMN approved_at DATETIME NULL");
    $log[] = "Added approved_at";
  }
  if (!isset($have['decision_note'])) {
    $pdo->exec("ALTER TABLE wallet_transfers ADD COLUMN decision_note VARCHAR(255) NULL");
    $log[] = "Added decision_note";
  }
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_wt_status ON wallet_transfers (status)");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_wt_approved_by ON wallet_transfers (approved_by)");

  // legacy rows -> approved
  $pdo->exec("UPDATE wallet_transfers SET status='approved' WHERE status IS NULL OR status=''");

  // new rows -> pending by default
  try {
    $pdo->exec("ALTER TABLE wallet_transfers MODIFY status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'");
    $log[] = "Default changed to pending for new rows.";
  } catch(Throwable $e){ $log[] = "Note: default to pending may not change on older MySQL, ok."; }

} catch(Throwable $e){
  $log[] = "ERROR: ".$e->getMessage();
}

include __DIR__.'/../partials/partials_header.php';
?>
<div class="container my-4">
  <h3>Wallet Approval Migration</h3>
  <ul><?php foreach($log as $l) echo "<li>".h($l)."</li>"; ?></ul>
  <a class="btn btn-primary" href="/public/wallets.php">Open Wallets</a>
  <a class="btn btn-outline-primary" href="/public/wallet_approvals.php">Open Approvals</a>
</div>
<?php include __DIR__.'/../partials/partials_footer.php'; ?>
