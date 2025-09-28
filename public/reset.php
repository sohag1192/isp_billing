<?php
// /public/reset.php
// বাংলা: token/OTP যাচাই → users টেবিলে পাসওয়ার্ড আপডেট (schema-aware) → সেশন লগইন

declare(strict_types=1);
require_once dirname(__DIR__) . '/app/db.php';

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','domain'=>'','secure'=>$https,'httponly'=>true,'samesite'=>'Lax']);
session_start();

// CSRF
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$token = trim((string)($_GET['token'] ?? ''));
$msg=''; $err='';

// Handle POST (OTP + new password)
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
    $err = 'Invalid request';
  } else {
    $otp  = trim((string)($_POST['otp'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    $token = trim((string)($_POST['token'] ?? $token));
    if (strlen($otp) !== 6 || strlen($pass) < 6) {
      $err = 'Invalid OTP or password too short (min 6).';
    } else {
      try {
        $pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Load reset row by token or OTP (unused + not expired)
        if ($token !== '') {
          $st = $pdo->prepare("SELECT * FROM password_resets WHERE token=? AND used=0 ORDER BY id DESC LIMIT 1");
          $st->execute([$token]);
        } else {
          $st = $pdo->prepare("SELECT * FROM password_resets WHERE otp=? AND used=0 ORDER BY id DESC LIMIT 1");
          $st->execute([$otp]);
        }
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Invalid or already used.');
        if (new DateTime($row['expires_at']) < new DateTime()) throw new RuntimeException('OTP expired.');

        $email = $row['email'];

        // Detect users schema
        $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
        $emailCol = in_array('email',$cols,true) ? 'email' : null;
        $userCol  = in_array('username',$cols,true) ? 'username' :
                    (in_array('user_name',$cols,true) ? 'user_name' : null);
        $passCol  = in_array('password',$cols,true) ? 'password' :
                    (in_array('pass_hash',$cols,true) ? 'pass_hash' : null);
        $idCol    = in_array('id',$cols,true) ? 'id' : null;
        if (!$passCol) throw new RuntimeException('users table needs password/pass_hash column');

        // Find the user by email (or username==email fallback)
        $uid = 0;
        if ($emailCol) {
          $st = $pdo->prepare("SELECT `$idCol` FROM users WHERE `$emailCol`=? LIMIT 1");
          $st->execute([$email]); $uid = (int)($st->fetchColumn() ?: 0);
        }
        if (!$uid && $userCol) {
          $st = $pdo->prepare("SELECT `$idCol` FROM users WHERE `$userCol`=? LIMIT 1");
          $st->execute([$email]); $uid = (int)($st->fetchColumn() ?: 0);
        }
        if (!$uid) throw new RuntimeException('No matching account for this email.');

        // Update password
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $stU = $pdo->prepare("UPDATE users SET `$passCol`=? WHERE `$idCol`=?");
        $stU->execute([$hash, $uid]);

        // Mark used
        $pdo->prepare("UPDATE password_resets SET used=1 WHERE id=?")->execute([$row['id']]);

        // Auto login
        $_SESSION['user_id'] = $uid;
        $_SESSION['username'] = $email;
        $msg = 'Password updated. You are now signed in.';
        header('Location: /public/index.php'); exit;

      } catch (Throwable $e) {
        $err = $e->getMessage();
      }
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reset Password - ISP Billing</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.10.5/font/bootstrap-icons.min.css">
</head>
<body class="bg-light">
<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
      <div class="card shadow-sm">
        <div class="card-body p-4">
          <h1 class="h5 mb-3"><i class="bi bi-shield-lock me-1"></i> Set a new password</h1>
          <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
          <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

          <form method="post" action="/public/reset.php">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="token" value="<?= h($token) ?>">
            <div class="mb-3">
              <label class="form-label">OTP (6 digits)</label>
              <input type="text" name="otp" class="form-control" maxlength="6" required placeholder="------">
            </div>
            <div class="mb-3">
              <label class="form-label">New password</label>
              <input type="password" name="password" class="form-control" minlength="6" maxlength="200" required>
            </div>
            <div class="d-grid gap-2">
              <button class="btn btn-primary" type="submit"><i class="bi bi-check2-circle me-1"></i> Update password</button>
              <a class="btn btn-outline-secondary" href="/public/login.php"><i class="bi bi-box-arrow-in-left me-1"></i> Back to sign in</a>
            </div>
            <p class="small text-muted mt-3 mb-0">Link and OTP are valid for 15 minutes.</p>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
