<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../app/db.php';

/**
 * Optional config integration:
 * define('SMS_API_URL', 'https://your-sms-gateway/send');
 * define('SMS_API_KEY', 'XXXX');
 * define('SMS_SENDER',  'YourBrand');
 * define('MAIL_FROM',   'no-reply@yourdomain.com');
 * define('MAIL_NAME',   'Billing System');
 */

function respond($arr){ echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

$type    = $payload['type'] ?? ''; // 'sms' | 'email'
$ids     = $payload['ids'] ?? [];
$subject = trim($payload['subject'] ?? '');
$message = trim($payload['message'] ?? '');

if (!in_array($type, ['sms','email']) || !is_array($ids) || count($ids)===0 || $message==='') {
    respond(['status'=>'error','message'=>'Invalid payload']);
}

// Load clients
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$st = db()->prepare("SELECT id, name, mobile, email FROM clients WHERE is_deleted=0 AND id IN ($placeholders)");
$st->execute($ids);
$clients = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$clients) respond(['status'=>'error','message'=>'No valid clients found']);

$processed = 0; $ok=0; $fail=0; $errors=[];

// SMS sender
function send_sms($to, $text){
    if (!$to) return false;
    if (defined('SMS_API_URL')) {
        $url = SMS_API_URL . '?to=' . urlencode($to) . '&text=' . urlencode($text);
        if (defined('SMS_API_KEY')) $url .= '&api_key=' . urlencode(SMS_API_KEY);
        if (defined('SMS_SENDER'))  $url .= '&sender='  . urlencode(SMS_SENDER);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        $code= curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ($res !== false && $code>=200 && $code<300);
    } else {
        // No gateway configured; simulate success (or write to a log table)
        return true;
    }
}

// Email sender (simple mail())
function send_email($to, $subject, $html){
    if (!$to) return false;
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8\r\n";
    if (defined('MAIL_FROM')) {
        $fromName = defined('MAIL_NAME') ? MAIL_NAME : 'Billing System';
        $headers .= "From: ".$fromName." <".MAIL_FROM.">\r\n";
    }
    return mail($to, $subject ?: 'Notification', $html, $headers);
}

// Loop
foreach ($clients as $c){
    $processed++;
    if ($type==='sms'){
        $to = preg_replace('/[^0-9+]/', '', $c['mobile'] ?? '');
        if (!$to){ $fail++; $errors[]=['id'=>$c['id'],'error'=>'no mobile']; continue; }
        $ok += send_sms($to, $message) ? 1 : 0;
        if (!$ok) { $fail++; $errors[]=['id'=>$c['id'],'error'=>'sms failed']; }
    } else {
        $to = trim($c['email'] ?? '');
        if (!$to){ $fail++; $errors[]=['id'=>$c['id'],'error'=>'no email']; continue; }
        $sent = send_email($to, $subject, nl2br(htmlentities($message, ENT_QUOTES, 'UTF-8')));
        if ($sent) $ok++; else { $fail++; $errors[]=['id'=>$c['id'],'error'=>'email failed']; }
    }
}

respond([
    'status'=>'success',
    'processed'=>$processed,
    'succeeded'=>$ok,
    'failed'=>$fail,
    'errors'=>$errors
]);
?>