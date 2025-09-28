<?php
// /tg/tools_migrate.php
// UI: English; Comments: বাংলা — টেবিল তৈরি + ডিফল্ট টেমপ্লেট সিড
declare(strict_types=1);
error_reporting(E_ALL); ini_set('display_errors','0'); ini_set('log_errors','1');

$ROOT = dirname(__DIR__, 1);
require_once $ROOT . '/app/require_login.php';
require_once $ROOT . '/app/db.php';
$acl_file = $ROOT . '/app/acl.php'; if (is_file($acl_file)) require_once $acl_file;
if (function_exists('require_perm')) { require_perm('admin.migrate'); }

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));
$CSRF=$_SESSION['csrf'];
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES,'UTF-8'); }

$pdo=db(); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$msg=''; $err='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!hash_equals($CSRF, (string)($_POST['csrf'] ?? ''))) { $err='Invalid CSRF.'; }
  else try {
    // subscribers
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_subscribers (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      client_id BIGINT NOT NULL,
      chat_id BIGINT NOT NULL,
      username VARCHAR(100) NULL,
      first_name VARCHAR(100) NULL,
      last_name VARCHAR(100) NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uk_client (client_id),
      UNIQUE KEY uk_chat (chat_id),
      KEY idx_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // link tokens
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_link_tokens (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      token VARCHAR(50) NOT NULL UNIQUE,
      client_id BIGINT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      used_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // templates
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_templates (
      id INT AUTO_INCREMENT PRIMARY KEY,
      template_key VARCHAR(80) NOT NULL UNIQUE,
      body TEXT NOT NULL,
      active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // queue
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_queue (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      client_id BIGINT NOT NULL,
      template_key VARCHAR(80) NOT NULL,
      payload_json JSON NULL,
      status ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
      retries INT NOT NULL DEFAULT 0,
      send_after DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      sent_at DATETIME NULL,
      uniq_key VARCHAR(120) NULL,
      last_error TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_status (status, send_after),
      KEY idx_client (client_id),
      KEY idx_uniq (uniq_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // seed default templates
    $seed = [
      ['due_reminder',    "প্রিয় {{name}}, আপনার বিল বকেয়া। {{amount}} টাকা। পরিশোধ করুন: {{pay_link}}"],
      ['payment_confirm', "{{name}}, আপনার {{amount}} টাকা পেমেন্ট পেয়েছি। ধন্যবাদ।"],
      ['generic',         "Hi {{name}}, {{message}}"],
    ];
    $ins=$pdo->prepare("INSERT IGNORE INTO telegram_templates (template_key, body) VALUES (?,?)");
    foreach($seed as $t){ $ins->execute($t); }

    $msg='Migration successful: telegram tables created and templates seeded.';
  } catch (Throwable $e) { $err='Migration failed: '.$e->getMessage(); }
}

require_once $ROOT . '/partials/partials_header.php'; ?>
<div class="container py-4">
  <h5 class="mb-3"><i class="bi bi-telegram"></i> Telegram — DB Setup</h5>
  <?php if($msg): ?><div class="alert alert-success py-2"><?php echo h($msg); ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert alert-danger py-2"><?php echo h($err); ?></div><?php endif; ?>
  <form method="post" class="card p-3">
    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
    <p class="mb-2">Creates: <code>telegram_subscribers</code>, <code>telegram_link_tokens</code>, <code>telegram_templates</code>, <code>telegram_queue</code>.</p>
    <button class="btn btn-primary btn-sm"><i class="bi bi-database-add"></i> Run Migration</button>
  </form>
</div>
<?php require_once $ROOT . '/partials/partials_footer.php';
