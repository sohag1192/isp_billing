<?php
// /public/tools/migrate_notifications.php
// UI: English; Comments: বাংলা — নোটিফিকেশন টেবিল + টেমপ্লেট সিডার
declare(strict_types=1);
error_reporting(E_ALL); ini_set('display_errors','0'); ini_set('log_errors','1');

$ROOT = dirname(__DIR__, 1);
require_once $ROOT . '/app/require_login.php';
require_once $ROOT . '/app/db.php';
$acl_file = $ROOT . '/app/acl.php'; if (is_file($acl_file)) require_once $acl_file;
if (function_exists('require_perm')) { require_perm('admin.migrate'); }

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$msg=''; $err='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!hash_equals($CSRF, (string)($_POST['csrf'] ?? ''))) { $err='Invalid CSRF.'; }
  else {
    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS notification_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        template_key VARCHAR(80) NOT NULL,
        channel ENUM('sms','email') NOT NULL,
        subject VARCHAR(200) NULL,
        body TEXT NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_tpl (template_key, channel)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        client_id BIGINT NOT NULL,
        channel ENUM('sms','email') NOT NULL,
        template_key VARCHAR(80) NOT NULL,
        payload_json JSON NULL,
        status ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
        retries INT NOT NULL DEFAULT 0,
        send_after DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        sent_at DATETIME NULL,
        uniq_key VARCHAR(120) NULL,
        last_error TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_status_sendafter (status, send_after),
        KEY idx_client (client_id),
        KEY idx_uniq (uniq_key)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      // seed templates
      $seed = [
        ['due_reminder_sms','sms',null,"প্রিয় {{name}}, আপনার বিল বকেয়া আছে। পরিমাণ: {{amount}}. এখনই পরিশোধ করুন: {{pay_link}}"],
        ['payment_confirm_sms','sms',null,"{{name}}, আপনার {{amount}} টাকা পেমেন্ট পেয়েছি। ধন্যবাদ।"],
        ['due_reminder_email','email',"Invoice Due Reminder","Dear {{name}},<br>Your bill is due ({{amount}}). Please pay: <a href='{{pay_link}}'>Pay Now</a>"],
        ['payment_confirm_email','email',"Payment Received","Hello {{name}},<br>Payment {{amount}} received. Thank you."],
      ];
      $ins=$pdo->prepare("INSERT IGNORE INTO notification_templates (template_key, channel, subject, body) VALUES (?,?,?,?)");
      foreach($seed as $t){ $ins->execute($t); }

      $msg='Migration successful: tables created and templates seeded.';
    } catch (Throwable $e) { $err='Migration failed: '.$e->getMessage(); }
  }
}

require_once $ROOT . '/partials/partials_header.php'; ?>
<div class="container py-4">
  <h5 class="mb-3"><i class="bi bi-bell"></i> Notifications Migration</h5>
  <?php if($msg): ?><div class="alert alert-success py-2"><?php echo h($msg); ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert alert-danger py-2"><?php echo h($err); ?></div><?php endif; ?>
  <form method="post" class="card p-3">
    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
    <p class="mb-2">Create <code>notification_templates</code> and <code>notifications</code> tables and seed default templates.</p>
    <button class="btn btn-primary btn-sm"><i class="bi bi-database-add"></i> Run Migration</button>
  </form>
</div>
<?php require_once $ROOT . '/partials/partials_footer.php';
