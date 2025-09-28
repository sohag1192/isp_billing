<?php
// /public/theme_toggle.php
// (বাংলা) থিম টগল + রিডাইরেক্ট: session+cookie সেট করে Referer বা index.php তে ফিরে যায়।

declare(strict_types=1);
require_once __DIR__ . '/../app/require_login.php';

$next = strtolower($_GET['theme'] ?? '');
if (!in_array($next, ['dark','light'], true)) {
  // (বাংলা) invalid হলে বর্তমান থেকে টগল
  $curr = $_SESSION['ui_theme'] ?? ($_COOKIE['ui_theme'] ?? 'light');
  $curr = in_array($curr, ['dark','light'], true) ? $curr : 'light';
  $next = ($curr === 'dark') ? 'light' : 'dark';
}

$_SESSION['ui_theme'] = $next;
setcookie('ui_theme', $next, [
  'expires'  => time() + 31536000,
  'path'     => '/',
  'secure'   => !empty($_SERVER['HTTPS']),
  'httponly' => false,
  'samesite' => 'Lax',
]);

$back = $_GET['return'] ?? ($_SERVER['HTTP_REFERER'] ?? '/public/index.php');
header('Location: ' . $back);
exit;
