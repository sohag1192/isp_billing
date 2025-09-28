<?php
// /public/register_submit.php
// বাংলা: ইনপুট ভ্যালিডেট → user_registrations টেবিলে pending সংরক্ষণ → ইমেইলে Verify link + OTP পাঠানো

declare(strict_types=1);
require_once dirname(__DIR__) . '/app/db.php';
require_once dirname(__DIR__) . '/app/mailer.php';

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','domain'=>'','secure'=>$https,'httponly'=>true,'samesite'=>'Lax']);
session_start();

// CSRF
if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
  header('Location: /public/register.php?err=Invalid+request'); exit;
}

// Inputs
$name  = trim((string)($_POST['name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$pass  = (string)($_POST['password'] ?? '');

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 6) {
  header('Location: /public/register.php?err=Invalid+form+data'); exit;
}

try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // বাংলা: users টেবিলে আগে থেকেই আছে কিনা দেখুন (schema-aware email/username)
  $usersCols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
  $emailCol  = in_array('email', $usersCols, true) ? 'email' : null;
  $userCol   = in_array('username', $usersCols, true) ? 'username' : (in_array('user_name', $usersCols,true) ? 'user_name' : null);

  if ($emailCol) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE `$emailCol`=?");
    $st->execute([$email]);
    if ((int)$st->fetchColumn() > 0) {
      header('Location: /public/register.php?err=Email+already+registered'); exit;
    }
  } elseif ($userCol) {
    // কিছু স্কিমায় username == email হয়
    $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE `$userCol`=?");
    $st->execute([$email]);
    if ((int)$st->fetchColumn() > 0) {
      header('Location: /public/register.php?err=Email+already+registered'); exit;
    }
  }

  // Pending table ensure (বাংলা: না থাকলে create)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS user_registrations (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(150) NOT NULL,
      email VARCHAR(200) NOT NULL,
      pass_hash VARCHAR(255) NOT NULL,
      token VARCHAR(64) NOT NULL,
      otp VARCHAR(10) NOT NULL,
      expires_at DATETIME NOT NULL,
      created_at DATETIME NOT NULL,
      ip VARCHAR(64) NULL,
      consumed TINYINT(1) NOT NULL DEFAULT 0,
      UNIQUE KEY uniq_email (email),
      KEY idx_token (token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  $token = bin2hex(random_bytes(16));         // 32 hex
  $otp   = str_pad((string)random_int(0,999999), 6, '0', STR_PAD_LEFT);
  $hash  = password_hash($pass, PASSWORD_DEFAULT);
  $exp   = (new DateTime('+15 minutes'))->format('Y-m-d H:i:s');
  $now   = (new DateTime())->format('Y-m-d H:i:s');
  $ip    = $_SERVER['REMOTE_ADDR'] ?? '';

  $st = $pdo->prepare("INSERT INTO user_registrations(name,email,pass_hash,token,otp,expires_at,created_at,ip) VALUES(?,?,?,?,?,?,?,?)");
  $st->execute([$name,$email,$hash,$token,$otp,$exp,$now,$ip]);

  // Send email (verify link + OTP)
  $verifyUrl = ( ($https?'https':'http').'://'.$_SERVER['HTTP_HOST']."/public/verify.php?token=".$token );
  $subject = "Verify your ISP Billing admin account";
  $html = "
    <p>Hello ".htmlspecialchars($name,ENT_QUOTES,'UTF-8').",</p>
    <p>Please verify your email to activate your admin account.</p>
    <p><strong>OTP:</strong> <code>{$otp}</code> (valid 15 minutes)</p>
    <p>Or click the button below:</p>
    <p><a href=\"{$verifyUrl}\" style=\"display:inline-block;padding:10px 16px;background:#0d6efd;color:#fff;text-decoration:none;border-radius:6px\">Verify Email</a></p>
    <p>If the button does not work, open this link:<br>".htmlspecialchars($verifyUrl,ENT_QUOTES,'UTF-8')."</p>
    <p>Thanks,<br>ISP Billing</p>
  ";
  $text = "Hello {$name},\n\nOTP: {$otp} (valid 15 minutes)\nVerify: {$verifyUrl}\n\n— ISP Billing";

  send_mail($email, $subject, $html, $text);

  header('Location: /public/register.php?msg=Verification+email+sent.+Check+your+inbox.');
  exit;

} catch (Throwable $e) {
  header('Location: /public/register.php?err='.rawurlencode('Server error: '.$e->getMessage())); exit;
}
