<?php
// Simple helpers for logging + placeholder notifiers

function ensure_dir($path){
    if (!is_dir($path)) @mkdir($path, 0777, true);
}

function log_msg($message, $file = 'monitor.log'){
    $dir = __DIR__ . '/../storage/logs';
    ensure_dir($dir);
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents($dir . '/' . $file, $line, FILE_APPEND);
}

/** Placeholder SMS — চাইলে এখানে তোমার গেটওয়ের কোড বসাও */
function send_sms($to, $text){
    log_msg("SMS to {$to}: {$text}", 'alerts.log');
    return true;
}

/** Simple email (PHP mail). প্রোডাকশনে PHPMailer ব্যবহার করাই ভালো */
function send_email($to, $subject, $body){
    $headers = "From: billing@example.com\r\n";
    $ok = @mail($to, $subject, $body, $headers);
    log_msg("Email to {$to} ({$subject}): " . ($ok ? 'OK' : 'FAIL'), 'alerts.log');
    return $ok;
}


//----------- ক্লায়েন্ট রাউটার এর ম্যাক ডাটাবেজ এ স্টোর করার স্ক্রিট 

<?php
// Bengali: MAC → "AA:BB:CC:DD:EE:FF"
if (!function_exists('normalize_mac_for_store')) {
    function normalize_mac_for_store(string $mac): string {
        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac));
        if ($hex === '') return '';
        $hex = substr(str_pad($hex, 12, '0', STR_PAD_RIGHT), 0, 12);
        return implode(':', str_split($hex, 2));
    }
}
