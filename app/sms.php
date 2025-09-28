<?php
// /app/sms.php
// (বাংলা) SMS গেটওয়ে কনফিগ + সিম্পল সেন্ড ফাংশন (cURL)

if (!defined('SMS_GATEWAY_URL')) {
  // (বাংলা) আপনার গেটওয়ে URL বসান (উদাহরণ Generic GET API)
  define('SMS_GATEWAY_URL', 'https://api.example-sms.com/send');
  define('SMS_SENDER_ID',   'DISCOVERY');    // আপনার Sender ID
  define('SMS_API_KEY',     'REPLACE_ME');   // API key/token
}

/**
 * send_sms: Minimal sender using GET or POST (আপনার গেটওয়ে অনুযায়ী ফিল্ড নাম মিলিয়ে নিন)
 */
function send_sms(string $mobile, string $message): array {
  // (বাংলা) গেটওয়ের চাহিদা অনুযায়ী প্যারাম—এগুলো আপনার আসল ফরম্যাটে দিন
  $params = [
    'api_key'  => SMS_API_KEY,
    'sender'   => SMS_SENDER_ID,
    'to'       => $mobile,
    'message'  => $message,
  ];

  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, SMS_GATEWAY_URL);
  curl_setopt($ch, CURLOPT_POST, true);                   // GET দরকার হলে false করে QueryString বানান
  curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
  curl_setopt($ch, CURLOPT_TIMEOUT, 15);

  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  // (বাংলা) সাকসেস ডিটেকশন আপনার গেটওয়ে রেসপন্স অনুসারে কাস্টোমাইজ করুন
  $ok = ($err === '' && $code >= 200 && $code < 300 && stripos((string)$resp, 'success') !== false);

  return [
    'ok'        => $ok,
    'http_code' => $code,
    'error'     => $err,
    'raw'       => $resp,
  ];
}
