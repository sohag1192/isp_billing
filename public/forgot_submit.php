<?php
// /public/forgot_submit.php
// বাংলা: ইমেইল ভেরিফাই → password_resets টেবিলে টোকেন/OTP সেভ → মেইল পাঠান (লিংক+OTP)

declare(strict_types=1);
require_once dirname(__DIR__) . '/app/db.php';
require_once dirname(__DIR__) . '/app/mailer.php';

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','domain'=>'','secure'=>$https,'httponly'=>true,'samesite'=>'Lax']);
session_start();

// CSRF
if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
  header('Location: /public/forgot.php?err=Invalid+request'); exit;
}

$email = trim((string)($_POST['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  header('Location: /public/forgot.php?err=Invalid+email'); exit;
}

try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // Ensure table (বাংলা: না থাকলে তৈরি)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS password_resets (
      id INT AUTO_INCREMENT PRIMARY KEY,
      email VARCHAR(200) NOT NULL,
      token VARCHAR(64) NOT NULL,
      otp VARCHAR(10) NOT NULL,
      expires_at DATETIME NOT NULL,
      created_at DATETIME NOT NULL,
      used TINYINT(1) NOT NULL DEFAULT 0,
      KEY idx_email (email),
      KEY idx_token (token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  // Throttle: last 60s (বাংলা: স্প্যাম প্রতিরোধ)
  $st = $pdo->prepare("SELECT created_at FROM password_resets WHERE email=? ORDER BY id DESC LIMIT 1");
  $st->execute([$email]);
  $last = $st->fetchColumn();
  if ($last && (time() - strtotime($last)) < 60) {
    header('Location: /public/forgot.php?msg=If+the+email+exists,+we%27ve+sent+instructions.'); exit;
  }

  $token = bin2hex(random_bytes(16));
  $otp   = str_pad((string)random_int(0,999999), 6, '0', STR_PAD_LEFT);
  $exp   = (new DateTime('+15 minutes'))->format('Y-m-d H:i:s');
  $now   = (new DateTime())->format('Y-m-d H:i:s');

  // Insert — আমরা ইউজার আছে কিনা চেক করলেও response একই থাকবে (privacy)
  $stI = $pdo->prepare("INSERT INTO password_resets(email, token, otp, expires_at, created_at) VALUES(?,?,?,?,?)");
  $stI->execute([$email, $token, $otp, $exp, $now]);

  // Build email
  $verifyUrl = ( ($https?'https':'http').'://'.$_SERVER['HTTP_HOST']."/public/reset.php?token=".$token );
  $subject = "Reset your ISP Billing password";
  $html = "
    <p>Hello,</p>
    <p>We received a request to reset your password.</p>
    <p><strong>OTP:</strong> <code>{$otp}</code> (valid for 15 minutes)</p>
    <p>You can also click this button:</p>
    <p><a href=\"{$verifyUrl}\" style=\"display:inline-block;padding:10px 16px;background:#0d6efd;color:#fff;text-decoration:none;border-radius:6px\">Reset Password</a></p>
    <p>If the button doesn’t work, open this link:<br>".htmlspecialchars($verifyUrl,ENT_QUOTES,'UTF-8')."</p>
    <p>If you didn’t request this, ignore this email.</p>";
  $text = "OTP: {$otp} (15 min)\nReset: {$verifyUrl}\nIf you didn't request, ignore.";

  [$ok, $mailErr] = send_mail($email, $subject, $html, $text);
  // আমরা যাই হোক generic success দেখাবো
  header('Location: /public/forgot.php?msg=If+the+email+exists,+we%27ve+sent+instructions.');
  exit;

} catch (Throwable $e) {
  header('Location: /public/forgot.php?msg=If+the+email+exists,+we%27ve+sent+instructions.'); exit;
}
