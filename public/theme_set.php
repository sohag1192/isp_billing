<?php
// /public/theme_set.php
// (বাংলা) থিম প্রেফারেন্স সেভ: session + cookie (১ বছর)।
// GET: /public/theme_set.php?theme=dark|light

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../app/require_login.php';

$theme = strtolower(trim($_GET['theme'] ?? ''));
if (!in_array($theme, ['dark','light'], true)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'invalid theme']);
  exit;
}

$_SESSION['ui_theme'] = $theme;

// (বাংলা) কুকি ১ বছরের জন্য
setcookie('ui_theme', $theme, [
  'expires'  => time() + 31536000,
  'path'     => '/',
  'secure'   => !empty($_SERVER['HTTPS']),
  'httponly' => false, // (বাংলা) JS থেকে পড়ার দরকার হলে httponly=false
  'samesite' => 'Lax',
]);

echo json_encode(['ok'=>true,'theme'=>$theme]);
