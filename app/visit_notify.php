<?php
// /app/visit_notify.php
// বাংলা: যেকোন পেজ রিকোয়েস্টে (ভিউতে) টেলিগ্রামে নোটিফাই—বট/অ্যাসেট বাদ, থ্রটলিং

declare(strict_types=1);
require_once __DIR__ . '/telegram.php';

function vn_client_ip(): string {
  $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
  if ($xff) { $p = array_map('trim', explode(',', $xff)); if (!empty($p[0])) return substr($p[0], 0, 45); }
  return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/** বাংলা: সরল বট ডিটেক্ট */
function vn_is_bot(string $ua): bool {
  $ua = strtolower($ua);
  foreach (['bot','spider','crawl','slurp','facebookexternalhit','telegram'] as $kw) {
    if (strpos($ua, $kw) !== false) return true;
  }
  return false;
}

/** বাংলা: অ্যাসেট/স্ট্যাটিক বাদ (css, js, img, fonts, phpMyAdmin, etc.) */
function vn_is_asset(string $uri): bool {
  if (preg_match('~\.(?:css|js|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|map)$~i', $uri)) return true;
  if (preg_match('~/(?:assets|vendor|node_modules|phpmyadmin|uploads)/~i', $uri)) return true;
  return false;
}

/** বাংলা: ফাইল থ্রটলিং (একই IP+URI X সেকেন্ডে একবার) */
function vn_throttle_hit(string $key, int $cooldown = 60): bool {
  $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'visit_notify';
  if (!is_dir($dir)) @mkdir($dir, 0777, true);
  $file = $dir . DIRECTORY_SEPARATOR . sha1($key) . '.lock';
  $now = time();
  if (is_file($file)) {
    $last = (int)@file_get_contents($file);
    if ($last && ($now - $last) < $cooldown) return false; // কুলডাউন চলছে
  }
  @file_put_contents($file, (string)$now);
  return true;
}

/** বাংলা: এই রিকোয়েস্টের জন্য নোটিফাই করো (নিয়ম মেনে) */
function vn_maybe_notify(array $options = []): void {
  $ua   = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
  $uri  = (string)($_SERVER['REQUEST_URI'] ?? $_SERVER['PHP_SELF'] ?? '/');
  $ref  = (string)($_SERVER['HTTP_REFERER'] ?? '');
  $ip   = vn_client_ip();

  // রুলস: বট না, অ্যাসেট না, লোকাল হেলথচেক না
  if ($ua === '' || vn_is_bot($ua) || vn_is_asset($uri)) return;

  // থ্রটল: একই IP+URI 60 সেকেন্ডে ১টাই
  $cooldown = $options['cooldown'] ?? 60; // সেকেন্ড
  if (!vn_throttle_hit($ip.'|'.$uri, (int)$cooldown)) return;

  // ইউজার (যদি সেশন থাকে)
  $userTxt = '-';
  if (isset($_SESSION['user'])) {
    $uid = $_SESSION['user']['id'] ?? '';
    $un  = $_SESSION['user']['username'] ?? ($_SESSION['user']['name'] ?? '');
    if ($uid || $un) $userTxt = trim($un.' #'.$uid);
  }

  // মেসেজ প্রস্তুত (HTML parse_mode)
  $host = $_SERVER['HTTP_HOST'] ?? '';
  $text = "👀 <b>Page View</b>\n".
          "🔗 <code>{$uri}</code>\n".
          ($ref ? "↩️ <i>from</i> <code>{$ref}</code>\n" : "").
          "👤 <code>{$userTxt}</code>\n".
          "🌐 <code>{$ip}</code> @ <code>{$host}</code>\n".
          "📱 <code>".htmlspecialchars(substr($ua,0,190), ENT_QUOTES, 'UTF-8')."</code>";

  tg_send($text, ['parse_mode'=>'HTML', 'disable_web_page_preview'=>true]);
}
