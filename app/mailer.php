<?php
// /app/mailer.php (diagnostic build)
// বাংলা: SMTP বাধ্যতামূলক কনফিগ থাকলে PHPMailer দিয়ে পাঠায়; যেকোনো ব্যর্থতায় বিস্তারিত error ফেরত দেয়

declare(strict_types=1);

$MAIL_FROM      = getenv('MAIL_FROM')      ?: '01732197767@gmail.com';
$MAIL_FROM_NAME = getenv('MAIL_FROM_NAME') ?: 'ISP Billing';
$SMTP_HOST      = getenv('SMTP_HOST')      ?: 'smtp.gmail.com';      // e.g. smtp.gmail.com
$SMTP_PORT      = (int)(getenv('SMTP_PORT') ?: 587);  // 587 (tls) / 465 (ssl)
$SMTP_USER      = getenv('SMTP_USER')      ?: '01732197767s@gmail.com';
$SMTP_PASS      = getenv('SMTP_PASS')      ?: 'এইখানে আপনার মেইল এর পাসসওার্ড দিন';
$SMTP_SECURE    = getenv('SMTP_SECURE')    ?: 'tls';   // tls|ssl|STARTTLS (PHPMailer uses 'tls'/'ssl')
$MAIL_DEBUG     = (int)(getenv('MAIL_DEBUG') ?: 0);    // 0..4 (2=verbose)
$ALLOW_SELF_SIGNED = (bool)(getenv('SMTP_ALLOW_SELF_SIGNED') ?: false);

/* ---------- Local PHPMailer include ---------- */
// বাংলা: নিচের ৩টি ফাইল এই path-এ থাকতে হবে
$SRC = __DIR__ . '/phpmailer/src';
if (!is_file($SRC.'/PHPMailer.php') || !is_file($SRC.'/SMTP.php') || !is_file($SRC.'/Exception.php')) {
  throw new RuntimeException('PHPMailer not found under /app/phpmailer/src');
}
require_once $SRC.'/PHPMailer.php';
require_once $SRC.'/SMTP.php';
require_once $SRC.'/Exception.php';

/**
 * send_mail — returns [bool success, string errorMsg]
 */
function send_mail(string $to, string $subject, string $html, string $text=''): array {
  global $MAIL_FROM,$MAIL_FROM_NAME,$SMTP_HOST,$SMTP_PORT,$SMTP_USER,$SMTP_PASS,$SMTP_SECURE,$MAIL_DEBUG,$ALLOW_SELF_SIGNED;

  if ($SMTP_HOST === '') return [false, 'SMTP_HOST not set'];

  try {
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = $SMTP_USER;
    $mail->Password   = $SMTP_PASS;
    $mail->SMTPSecure = $SMTP_SECURE;
    $mail->Port       = $SMTP_PORT;

    if ($ALLOW_SELF_SIGNED) {
      $mail->SMTPOptions = ['ssl'=>[
        'verify_peer'=>false,'verify_peer_name'=>false,'allow_self_signed'=>true
      ]];
    }

    $mail->SMTPDebug  = $MAIL_DEBUG; // 0..4
    if ($MAIL_DEBUG > 0) {
      $mail->Debugoutput = function($str,$level){ error_log("[MAILER][$level] $str"); };
    }

    $mail->CharSet = 'UTF-8';
    $mail->setFrom($MAIL_FROM, $MAIL_FROM_NAME);
    $mail->addAddress($to);
    $mail->Subject = $subject;
    $mail->isHTML(true);
    $mail->Body    = $html;
    $mail->AltBody = $text ?: strip_tags($html);
    $mail->send();
    return [true, ''];
  } catch (\PHPMailer\PHPMailer\Exception $e) {
    $err = $e->getMessage();
    $info = isset($mail) ? $mail->ErrorInfo : '';
    return [false, trim($err.($info? " | $info":''))];
  } catch (\Throwable $e) {
    return [false, $e->getMessage() ?: 'Unknown error'];
  }
}