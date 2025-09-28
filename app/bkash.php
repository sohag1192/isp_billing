<?php
// /app/bkash.php
// UI: English; Comments: বাংলা
// Purpose: bKash PGW helper (token + verify). Credentials are read from app/config.php or env.

// strict
declare(strict_types=1);

if (!function_exists('bkash_cfg')) {
  function bkash_cfg(string $key, $default = null) {
    // বাংলা: কনফিগ ফাংশন—app/config.php থাকলে সেখানকার কনস্ট্যান্ট/ভ্যার থেকে নিবে, না হলে ENV
    // Keys: BKASH_BASE_URL, BKASH_USERNAME, BKASH_PASSWORD, BKASH_APP_KEY, BKASH_APP_SECRET
    if (defined($key)) return constant($key);
    $env = getenv($key);
    if ($env !== false && $env !== '') return $env;
    // fallback to global $CONFIG if app/config.php exposes array
    if (isset($GLOBALS['CONFIG'][$key])) return $GLOBALS['CONFIG'][$key];
    return $default;
  }
}

if (!function_exists('bkash_http')) {
  function bkash_http(string $url, array $headers = [], ?array $json = null, int $timeout = 15): array {
    // বাংলা: cURL রিকোয়েস্ট হেল্পার
    $ch = curl_init($url);
    $hdrs = array_merge(['Content-Type: application/json'], $headers);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT        => $timeout,
      CURLOPT_HTTPHEADER     => $hdrs,
    ]);
    if (!is_null($json)) {
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_SLASHES));
    }
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err) throw new RuntimeException("bKash HTTP error: $err");
    $data = json_decode((string)$body, true);
    if (!is_array($data)) $data = ['raw' => $body];
    return ['code' => $code, 'data' => $data];
  }
}

if (!function_exists('bkash_get_token')) {
  function bkash_get_token(): string {
    // বাংলা: OAuth টোকেন নেবে (PGW)
    $base   = rtrim((string)bkash_cfg('BKASH_BASE_URL', ''), '/');
    $user   = (string)bkash_cfg('BKASH_USERNAME', '');
    $pass   = (string)bkash_cfg('BKASH_PASSWORD', '');
    $appKey = (string)bkash_cfg('BKASH_APP_KEY', '');
    $secret = (string)bkash_cfg('BKASH_APP_SECRET', '');

    if (!$base || !$user || !$pass || !$appKey || !$secret) {
      throw new RuntimeException('bKash credentials are not configured.');
    }

    $url = $base . '/checkout/token/grant';
    $headers = [
      'username: ' . $user,
      'password: ' . $pass,
    ];
    $payload = [
      'app_key'    => $appKey,
      'app_secret' => $secret,
    ];
    $res = bkash_http($url, $headers, $payload);
    $token = $res['data']['id_token'] ?? $res['data']['token'] ?? '';
    if (!$token) {
      throw new RuntimeException('Failed to obtain bKash token.');
    }
    return $token;
  }
}

if (!function_exists('bkash_verify_trx')) {
  /**
   * Verify a bKash transaction by trxID (Payment Status API).
   * Returns normalized array:
   * [
   *   'ok' => bool, 'amount' => float, 'currency' => 'BDT',
   *   'trxID' => '...', 'payer' => '...', 'status' => 'Completed|Initiated|Failed|...'
   * ]
   */
  function bkash_verify_trx(string $trxID): array {
    // বাংলা: ট্রান্স্যাকশন স্ট্যাটাস API (merchant query endpoint) হিট করে ভেরিফাই
    $base   = rtrim((string)bkash_cfg('BKASH_BASE_URL', ''), '/');
    $appKey = (string)bkash_cfg('BKASH_APP_KEY', '');
    if (!$base || !$appKey) {
      // বাংলা: কনফিগ নাই—ম্যানুয়াল মোডে falsy রেজাল্ট, UI-তে allow manual save
      return ['ok' => false, 'status' => 'CONFIG_MISSING'];
    }
    $token = bkash_get_token();

    // NOTE: PGW v2 has multiple variants; keeping a common status path pattern below.
    // If your environment differs, adjust the endpoint accordingly.
    $url = $base . '/checkout/payment/status/' . rawurlencode($trxID);
    $headers = [
      'authorization: ' . $token,
      'x-app-key: ' . $appKey,
    ];
    $res = bkash_http($url, $headers, null);
    $d   = $res['data'];

    // বাংলা: ভেন্ডর রেসপন্স নরমালাইজ
    $status = $d['transactionStatus'] ?? $d['status'] ?? ($d['paymentStatus'] ?? 'UNKNOWN');
    $amount = (float)($d['amount'] ?? 0);
    $payer  = $d['payerReference'] ?? $d['customerMsisdn'] ?? '';
    $id     = $d['trxID'] ?? $trxID;

    $ok = in_array(strtoupper((string)$status), ['COMPLETED', 'SUCCESS', 'CAPTURED', 'CAPTURED_COMPLETED'], true);

    return [
      'ok'       => $ok,
      'amount'   => $amount,
      'currency' => 'BDT',
      'trxID'    => $id,
      'payer'    => $payer,
      'status'   => $status,
      'raw'      => $d,
    ];
  }
}
