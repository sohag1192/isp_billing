<?php
// /tg/settings.php
// UI: English; Comments: বাংলা — Telegram Settings (save to DB + test + set webhook)

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');

$ROOT = dirname(__DIR__, 1);
require_once $ROOT.'/app/require_login.php';
require_once $ROOT.'/app/db.php';
require_once __DIR__.'/telegram.php';
$acl_file = $ROOT.'/app/acl.php'; if (is_file($acl_file)) require_once $acl_file;
if (function_exists('require_perm')) { require_perm('admin.config'); } // বাংলা: কনফিগ পেজে অ্যাডমিন দরকার

if (session_status()===PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// বাংলা: settings টেবিল না থাকলে তৈরি
$pdo->exec("CREATE TABLE IF NOT EXISTS telegram_settings (
  k VARCHAR(64) PRIMARY KEY,
  v TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// helper—সব সেটিং লোড
function tg_settings_all(PDO $pdo): array {
  $rows = $pdo->query("SELECT k, v FROM telegram_settings")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
  return $rows;
}
// helper—সেভ (upsert)
function tg_settings_save(PDO $pdo, array $kv): void {
  $ins = $pdo->prepare("INSERT INTO telegram_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v=VALUES(v), updated_at=NOW()");
  foreach ($kv as $k=>$v) { $ins->execute([$k, (string)$v]); }
}

$msg=''; $err='';
$view = tg_settings_all($pdo);

// Actions
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!hash_equals($CSRF, (string)($_POST['csrf'] ?? ''))) {
    $err='Invalid CSRF token.';
  } else {
    $act = (string)($_POST['act'] ?? '');

    if ($act==='save') {
      // বাংলা: ইনপুট নিন
      $bot_token = trim((string)($_POST['bot_token'] ?? ''));
      $bot_user  = trim((string)($_POST['bot_user'] ?? ''));
      $wh_secret = trim((string)($_POST['wh_secret'] ?? ''));
      $app_base  = trim((string)($_POST['app_base_url'] ?? ''));
      $parse     = in_array(($_POST['parse_mode'] ?? 'HTML'), ['','HTML','MarkdownV2'], true) ? (string)$_POST['parse_mode'] : 'HTML';
      $batch     = max(1, (int)($_POST['batch_limit'] ?? 100));
      $gapm      = max(0, (int)($_POST['min_gap_m'] ?? 30));
      $test_chat = trim((string)($_POST['test_chat_id'] ?? ''));

      // বাংলা: সেভ করুন
      tg_settings_save($pdo, [
        'bot_token'   => $bot_token,
        'bot_user'    => $bot_user,
        'wh_secret'   => $wh_secret,
        'app_base_url'=> $app_base,
        'parse_mode'  => $parse,
        'batch_limit' => (string)$batch,
        'min_gap_m'   => (string)$gapm,
        'test_chat_id'=> $test_chat,
      ]);
      $view = tg_settings_all($pdo);
      $msg = 'Settings saved.';
    }

    if ($act==='test_send') {
      // বাংলা: টেস্ট মেসেজ পাঠান (bot token + chat id লাগবে)
      $chat_id = trim((string)($_POST['test_chat_id'] ?? ($view['test_chat_id'] ?? '')));
      if ($chat_id==='') { $err='Provide Test Chat ID first.'; }
      else {
        // অস্থায়ীভাবে কনফিগ ওভাররাইড (DB পড়ার জন্য: tg_cfg() এখন DB-ও পড়ে — patch নিচে)
        $text = "Telegram test OK ✅\nTime: ".date('Y-m-d H:i:s');
        [$ok,$why] = tg_send_text($chat_id, $text, $view['parse_mode'] ?? 'HTML');
        if ($ok) $msg='Test message sent to chat_id='.$chat_id;
        else $err='Failed: '.$why;
      }
    }

    if ($act==='set_webhook') {
      $token = (string)($view['bot_token'] ?? '');
      $base  = rtrim((string)($view['app_base_url'] ?? ''), '/');
      $secret= (string)($view['wh_secret'] ?? '');
      if ($token==='' || $base==='' || $secret==='') {
        $err='Bot Token, Base URL and Webhook Secret are required.';
      } else {
        $url = $base.'/tg/webhook.php?secret='.$secret;
        // বাংলা: setWebhook কল
        $raw = [];
        [$ok,$why] = tg_api('setWebhook', ['url'=>$url], $raw);
        if ($ok) $msg='Webhook set to: '.$url;
        else $err='setWebhook failed: '.$why;
      }
    }

    if ($act==='delete_webhook') {
      $raw=[];
      [$ok,$why] = tg_api('deleteWebhook', [], $raw);
      if ($ok) $msg='Webhook deleted.';
      else $err='deleteWebhook failed: '.$why;
    }

    if ($act==='get_me') {
      $raw=[];
      [$ok,$why] = tg_api('getMe', [], $raw);
      if ($ok) $msg='Bot info: @'.(($raw['result']['username']??'unknown'));
      else $err='getMe failed: '.$why;
    }
  }
}

require_once $ROOT.'/partials/partials_header.php'; ?>
<div class="container py-4">
  <h4 class="mb-3"><i class="bi bi-telegram"></i> Telegram Settings</h4>

  <?php if($msg): ?><div class="alert alert-success py-2"><?php echo h($msg); ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert alert-danger py-2"><?php echo h($err); ?></div><?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-body">
      <form method="post" class="row g-3">
        <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
        <input type="hidden" name="act" value="save">

        <div class="col-md-6">
          <label class="form-label">Bot Token</label>
          <div class="input-group">
            <input type="password" class="form-control" name="bot_token" id="bot_token" value="<?php echo h($view['bot_token'] ?? ''); ?>" placeholder="123456:ABCDEF..." autocomplete="off">
            <button type="button" class="btn btn-outline-secondary" onclick="togglePwd('bot_token', this)">Show</button>
          </div>
          <div class="form-text">From @BotFather (e.g., <code>123456:ABC...</code>)</div>
        </div>

        <div class="col-md-3">
          <label class="form-label">Bot Username</label>
          <input type="text" class="form-control" name="bot_user" value="<?php echo h($view['bot_user'] ?? ''); ?>" placeholder="MyIspBot">
        </div>

        <div class="col-md-3">
          <label class="form-label">Webhook Secret</label>
          <input type="text" class="form-control" name="wh_secret" value="<?php echo h($view['wh_secret'] ?? ''); ?>" placeholder="random-secret">
          <div class="form-text">Used as <code>?secret=...</code> guard on webhook URL</div>
        </div>

        <div class="col-md-6">
          <label class="form-label">Site Base URL</label>
          <input type="url" class="form-control" name="app_base_url" value="<?php echo h($view['app_base_url'] ?? ''); ?>" placeholder="https://your-domain.tld">
          <div class="form-text">Used to build webhook URL: <code>{base}/tg/webhook.php?secret=...</code></div>
        </div>

        <div class="col-md-3">
          <label class="form-label">Parse Mode</label>
          <select name="parse_mode" class="form-select">
            <?php $pm=$view['parse_mode'] ?? 'HTML'; ?>
            <option value="" <?php echo $pm===''?'selected':''; ?>>None</option>
            <option value="HTML" <?php echo $pm==='HTML'?'selected':''; ?>>HTML</option>
            <option value="MarkdownV2" <?php echo $pm==='MarkdownV2'?'selected':''; ?>>MarkdownV2</option>
          </select>
        </div>

        <div class="col-md-3">
          <label class="form-label">Batch Limit</label>
          <input type="number" min="1" class="form-control" name="batch_limit" value="<?php echo h($view['batch_limit'] ?? '100'); ?>">
        </div>

        <div class="col-md-3">
          <label class="form-label">Min Gap (minutes)</label>
          <input type="number" min="0" class="form-control" name="min_gap_m" value="<?php echo h($view['min_gap_m'] ?? '30'); ?>">
          <div class="form-text">Uniq-key requeue gap control</div>
        </div>

        <div class="col-md-3">
          <label class="form-label">Test Chat ID (optional)</label>
          <input type="text" class="form-control" name="test_chat_id" value="<?php echo h($view['test_chat_id'] ?? ''); ?>" placeholder="e.g., 123456789">
          <div class="form-text">For quick test. Subscribers auto-saved via /start link.</div>
        </div>

        <div class="col-12 d-flex gap-2">
          <button class="btn btn-primary"><i class="bi bi-save2"></i> Save Settings</button>
          <button class="btn btn-outline-secondary" name="act" value="get_me"><i class="bi bi-info-circle"></i> Get Bot Info</button>
          <button class="btn btn-outline-success" name="act" value="test_send"><i class="bi bi-send"></i> Send Test</button>
          <button class="btn btn-outline-dark" name="act" value="set_webhook"><i class="bi bi-link-45deg"></i> Set Webhook</button>
          <button class="btn btn-outline-danger" name="act" value="delete_webhook"><i class="bi bi-x-circle"></i> Delete Webhook</button>
        </div>
      </form>
    </div>
  </div>

  <div class="mt-3 small text-muted">
    Webhook URL example: 
    <code><?php
      $ex_base = rtrim((string)($view['app_base_url'] ?? 'https://your-domain.tld'), '/');
      $ex_sec  = (string)($view['wh_secret'] ?? 'your-secret');
      echo h($ex_base.'/tg/webhook.php?secret='.$ex_sec);
    ?></code>
  </div>
</div>

<script>
function togglePwd(id, btn){
  const i = document.getElementById(id);
  if (!i) return;
  if (i.type === 'password') { i.type = 'text'; btn.innerText='Hide'; }
  else { i.type = 'password'; btn.innerText='Show'; }
}
</script>
<?php require_once $ROOT.'/partials/partials_footer.php';
