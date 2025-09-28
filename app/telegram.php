<?php
// /app/telegram.php
// বাংলা: Telegram Bot API helper — robust, short timeouts, verbose diagnostics (to error_log)

declare(strict_types=1);
require_once __DIR__ . '/config.php';

/*
 * Config tips:
 * define('TELEGRAM_BOT_TOKEN', '123:ABC');
 * define('TELEGRAM_CHAT_ID',  '-1001234567890'); // user/group/channel
 * Optional:
 * define('TELEGRAM_INSECURE', false); // true করলে SSL verify অফ (লোকাল Windows এ প্রয়োজন হলে, শেষ বিকল্প)
 * define('TELEGRAM_CAINFO',  __DIR__.'/../storage/cacert.pem'); // custom CA bundle path
 */

if (!function_exists('tg_http_post')) {
  function tg_http_post(string $url, array $payload): array {
    $body = null; $err = null; $code = 0;

    // Prefer cURL
    if (function_exists('curl_init')) {
      $ch = curl_init($url);
      $opts = [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => 'ISP-Billing-TelegramBot/1.0',
        CURLOPT_CONNECTTIMEOUT => 2,   // seconds
        CURLOPT_TIMEOUT        => 3,   // seconds
        CURLOPT_SSL_VERIFYPEER => !(defined('TELEGRAM_INSECURE') && TELEGRAM_INSECURE),
        CURLOPT_SSL_VERIFYHOST => (defined('TELEGRAM_INSECURE') && TELEGRAM_INSECURE) ? 0 : 2,
      ];
      if (defined('TELEGRAM_CAINFO') && TELEGRAM_CAINFO && is_file(TELEGRAM_CAINFO)) {
        $opts[CURLOPT_CAINFO] = TELEGRAM_CAINFO;
      }
      curl_setopt_array($ch, $opts);
      $body = curl_exec($ch);
      if ($body === false) $err = 'curl_error: '.curl_error($ch);
      $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
      curl_close($ch);
    } else {
      // Fallback: stream context
      $opts = [
        'http' => [
          'method'  => 'POST',
          'header'  => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: ISP-Billing-TelegramBot/1.0\r\n",
          'content' => http_build_query($payload),
          'timeout' => 3,
        ],
        'ssl' => [
          'verify_peer'      => !(defined('TELEGRAM_INSECURE') && TELEGRAM_INSECURE),
          'verify_peer_name' => !(defined('TELEGRAM_INSECURE') && TELEGRAM_INSECURE),
          'allow_self_signed'=> (defined('TELEGRAM_INSECURE') && TELEGRAM_INSECURE),
        ],
      ];
      if (defined('TELEGRAM_CAINFO') && TELEGRAM_CAINFO && is_file(TELEGRAM_CAINFO)) {
        $opts['ssl']['cafile'] = TELEGRAM_CAINFO;
      }
      $ctx  = stream_context_create($opts);
      $resp = @file_get_contents($url, false, $ctx);
      if ($resp === false) {
        $err = 'stream_context error';
      } else {
        $body = $resp;
      }
      // Try to read HTTP code from $http_response_header
      if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $h) {
          if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) { $code = (int)$m[1]; break; }
        }
      }
    }

    return [$code, $body, $err];
  }
}

if (!function_exists('tg_send')) {
  function tg_send(string $text, array $opts = []): bool {
    $token  = defined('TELEGRAM_BOT_TOKEN') ? TELEGRAM_BOT_TOKEN : '';
    $chatId = $opts['chat_id'] ?? (defined('TELEGRAM_CHAT_ID') ? TELEGRAM_CHAT_ID : '');
    if ($token === '' || $chatId === '') {
      error_log('[tg_send] Missing token/chat_id');
      return false;
    }

    $payload = [
      'chat_id'                  => $chatId,
      'text'                     => $text,
      'disable_web_page_preview' => $opts['disable_web_page_preview'] ?? true,
      'parse_mode'               => $opts['parse_mode'] ?? 'HTML',
    ];
    $url = "https://api.telegram.org/bot{$token}/sendMessage";

    [$code, $body, $err] = tg_http_post($url, $payload);

    // Diagnostics to error_log (not thrown)
    if ($err) error_log('[tg_send] HTTP error: '.$err);
    if ($code && $code !== 200) error_log('[tg_send] HTTP code='.$code.' body='.$body);
    if ($body) {
      $j = json_decode($body, true);
      if (!$j || empty($j['ok'])) {
        error_log('[tg_send] API response not ok: '.$body);
        return false;
      }
    } else if ($code !== 200) {
      return false;
    }
    return true;
  }
}
