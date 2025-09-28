notify.php<?php
// /app/notify.php
// UI: English; Comments: বাংলা — নোটিফাই কোর: টেমপ্লেট রেন্ডার, কিউ, সেন্ডার

declare(strict_types=1);

if (!function_exists('notify_cfg')) {
  // বাংলা: কনফিগ — চাইলে /app/config.php থেকে ওভাররাইড করো
  function notify_cfg(): array {
    return [
      // SMS Provider (generic HTTP POST)
      'sms_api_url' => getenv('SMS_API_URL') ?: '',        // e.g., https://api.example.com/sms/send
      'sms_api_key' => getenv('SMS_API_KEY') ?: '',        // e.g., xyz
      'sms_sender'  => getenv('SMS_SENDER')  ?: 'ISP',
      // Email (basic mail() fallback; চাইলে PHPMailer ব্যবহার করো)
      'mail_from'   => getenv('MAIL_FROM') ?: 'no-reply@your-isp.tld',
      'mail_name'   => getenv('MAIL_NAME') ?: 'Your ISP',
      // Anti-spam guard
      'min_gap_days' => 3, // একই ধরনের মেসেজ কত দিনের ব্যবধানে পাঠানো যাবে
      // Batch size
      'batch_limit' => 100,
    ];
  }
}

if (!function_exists('tbl_exists')) {
  function tbl_exists(PDO $pdo, string $t): bool {
    try{ $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
         $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
         $q->execute([$db,$t]); return (bool)$q->fetchColumn(); }catch(Throwable $e){ return false; }
  }
}
if (!function_exists('col_exists')) {
  function col_exists(PDO $pdo, string $t, string $c): bool {
    try{ $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
         $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
         $q->execute([$db,$t,$c]); return (bool)$q->fetchColumn(); }catch(Throwable $e){ return false; }
  }
}
if (!function_exists('pick_tbl')) {
  function pick_tbl(PDO $pdo, array $cands): ?string { foreach($cands as $t) if (tbl_exists($pdo,$t)) return $t; return null; }
}

if (!function_exists('notify_pick_client_cols')) {
  function notify_pick_client_cols(PDO $pdo, string $CT): array {
    // বাংলা: সাধারণ ক্লায়েন্ট কলাম ম্যাপ
    $id   = col_exists($pdo,$CT,'id') ? 'id' : (col_exists($pdo,$CT,'client_id')?'client_id':'id');
    $name = col_exists($pdo,$CT,'name') ? 'name' : (col_exists($pdo,$CT,'client_name')?'client_name':$id);
    $email= col_exists($pdo,$CT,'email') ? 'email' : (col_exists($pdo,$CT,'mail')?'mail':null);
    $mob  = col_exists($pdo,$CT,'mobile') ? 'mobile' : (col_exists($pdo,$CT,'phone')?'phone':null);
    $pppoe= col_exists($pdo,$CT,'pppoe_id') ? 'pppoe_id' : (col_exists($pdo,$CT,'username')?'username':null);
    return compact('id','name','email','mob','pppoe');
  }
}

/* ------------------ queue helpers ------------------ */
if (!function_exists('notify_queue')) {
  /**
   * Queue a notification
   * @param PDO $pdo
   * @param int $client_id
   * @param string $channel sms|email
   * @param string $template_key e.g. due_reminder_sms
   * @param array $payload extra vars (invoice_id, amount, link)
   * @param string|null $uniq_key unique guard (e.g. "due-2025-09-client123")
   */
  function notify_queue(PDO $pdo, int $client_id, string $channel, string $template_key, array $payload = [], ?string $uniq_key=null): bool {
    if (!tbl_exists($pdo,'notifications')) return false;

    $cfg = notify_cfg();
    $minGap = max(0, (int)$cfg['min_gap_days']);

    // বাংলা: min_gap_days এর মধ্যে একই uniq_key থাকলে re-queue করবে না
    if ($uniq_key) {
      $st=$pdo->prepare("SELECT id, created_at FROM notifications WHERE uniq_key=? ORDER BY id DESC LIMIT 1");
      $st->execute([$uniq_key]);
      $ex = $st->fetch(PDO::FETCH_ASSOC);
      if ($ex && $minGap>0) {
        $d1 = new DateTimeImmutable((string)$ex['created_at']);
        $d2 = new DateTimeImmutable('now');
        $diff = (int)$d2->diff($d1)->format('%a');
        if ($diff < $minGap) return false;
      }
    }

    $st=$pdo->prepare("INSERT INTO notifications (client_id, channel, template_key, payload_json, status, uniq_key, send_after, created_at)
                       VALUES (?,?,?,?, 'queued', ?, NOW(), NOW())");
    return $st->execute([$client_id, $channel, $template_key, json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $uniq_key]);
  }
}

/* ------------------ render template ------------------ */
if (!function_exists('notify_render_template')) {
  function notify_render_template(PDO $pdo, string $template_key, string $channel, array $clientRow, array $payload): array {
    // বাংলা: template টেবিল থেকে body/subject নেই? fallback default টেক্সট
    $TT = tbl_exists($pdo,'notification_templates') ? 'notification_templates' : null;
    $tpl = ['subject'=>'', 'body'=>''];
    if ($TT) {
      $st=$pdo->prepare("SELECT subject, body FROM `$TT` WHERE template_key=? AND channel=? AND active=1 LIMIT 1");
      $st->execute([$template_key, $channel]);
      $tpl = $st->fetch(PDO::FETCH_ASSOC) ?: $tpl;
    } else {
      // বাংলা: ডিফল্ট fallback
      if ($template_key==='due_reminder_sms') {
        $tpl['body'] = "Dear {{name}}, your bill is due. Amount: {{amount}}. Pay: {{pay_link}}";
      } elseif ($template_key==='payment_confirm_sms') {
        $tpl['body'] = "Hi {{name}}, we received your payment {{amount}}. Thank you.";
      } elseif ($template_key==='due_reminder_email') {
        $tpl['subject'] = "Invoice Due Reminder";
        $tpl['body'] = "Dear {{name}},<br>Your bill is due ({{amount}}). Please pay: <a href='{{pay_link}}'>Pay Now</a>";
      } elseif ($template_key==='payment_confirm_email') {
        $tpl['subject'] = "Payment Received";
        $tpl['body'] = "Hello {{name}},<br>Payment {{amount}} received. Thank you.";
      }
    }

    // বাংলা: placeholder replace
    $vars = [
      '{{name}}'        => (string)($clientRow['name'] ?? 'Customer'),
      '{{client_id}}'   => (string)($clientRow['id'] ?? ''),
      '{{pppoe}}'       => (string)($clientRow['pppoe'] ?? ''),
      '{{amount}}'      => (string)($payload['amount'] ?? ''),
      '{{invoice_id}}'  => (string)($payload['invoice_id'] ?? ''),
      '{{pay_link}}'    => (string)($payload['pay_link'] ?? ''),
      '{{portal_link}}' => (string)($payload['portal_link'] ?? ''),
    ];

    $subject = strtr((string)($tpl['subject'] ?? ''), $vars);
    $body    = strtr((string)($tpl['body'] ?? ''), $vars);

    return [$subject, $body];
  }
}

/* ------------------ sender backends ------------------ */
if (!function_exists('notify_send_sms')) {
  function notify_send_sms(string $to, string $text, array $cfg): array {
    // বাংলা: নো-অপ যদি কনফিগ নাই
    if (empty($cfg['sms_api_url'])) return [false, 'SMS not configured'];
    $payload = [
      'to'      => $to,
      'message' => $text,
      'sender'  => $cfg['sms_sender'] ?? 'ISP',
      'api_key' => $cfg['sms_api_key'] ?? '',
    ];
    $ch=curl_init($cfg['sms_api_url']);
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 20,
      CURLOPT_POSTFIELDS => http_build_query($payload),
      CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err) return [false, "cURL: $err"];
    if ($code>=200 && $code<300) return [true, 'OK'];
    return [false, "HTTP $code: $resp"];
  }
}

if (!function_exists('notify_send_email')) {
  function notify_send_email(string $to, string $subject, string $body, array $cfg): array {
    // বাংলা: সিম্পল mail() — চাইলে PHPMailer ইন্টিগ্রেট করো
    $from = $cfg['mail_from'] ?? 'no-reply@local';
    $name = $cfg['mail_name'] ?? 'ISP';
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: {$name} <{$from}>\r\n";
    $ok = @mail($to, $subject, $body, $headers);
    return [$ok, $ok?'OK':'mail() failed'];
  }
}

/* ------------------ runner core ------------------ */
if (!function_exists('notify_run_batch')) {
  function notify_run_batch(PDO $pdo): array {
    if (!tbl_exists($pdo,'notifications')) return ['processed'=>0,'sent'=>0,'failed'=>0];

    $cfg  = notify_cfg();
    $lim  = (int)$cfg['batch_limit'];
    $now  = date('Y-m-d H:i:s');

    $st=$pdo->prepare("SELECT * FROM notifications WHERE status='queued' AND send_after<=? ORDER BY id ASC LIMIT $lim");
    $st->execute([$now]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!$rows) return ['processed'=>0,'sent'=>0,'failed'=>0];

    // Client table resolve
    $CT = pick_tbl($pdo, ['clients','customers','subscribers']);
    $Ccols = $CT ? notify_pick_client_cols($pdo,$CT) : [];

    $sent=0; $failed=0;
    foreach ($rows as $n) {
      $id  = (int)$n['id'];
      $cid = (int)$n['client_id'];
      $payload = json_decode((string)$n['payload_json'], true) ?: [];

      // Load client row
      $clientRow = [];
      if ($CT && $cid>0) {
        $sel = ["`{$Ccols['id']}` AS id","`{$Ccols['name']}` AS name"];
        if ($Ccols['email']) $sel[] = "`{$Ccols['email']}` AS email";
        if ($Ccols['mob'])   $sel[] = "`{$Ccols['mob']}` AS mobile";
        if ($Ccols['pppoe']) $sel[] = "`{$Ccols['pppoe']}` AS pppoe";
        $sql = "SELECT ".implode(',',$sel)." FROM `$CT` WHERE `{$Ccols['id']}`=? LIMIT 1";
        $s=$pdo->prepare($sql); $s->execute([$cid]); $clientRow = $s->fetch(PDO::FETCH_ASSOC) ?: [];
      }
      [$subject,$body] = notify_render_template($pdo, (string)$n['template_key'], (string)$n['channel'], $clientRow, $payload);

      $ok=false; $err='n/a';
      if ($n['channel']==='sms') {
        $to = (string)($clientRow['mobile'] ?? '');
        if ($to==='') { $ok=false; $err='Mobile not found'; }
        else { [$ok,$err] = notify_send_sms($to, strip_tags($body), $cfg); }
      } else { // email
        $to = (string)($clientRow['email'] ?? '');
        if ($to==='') { $ok=false; $err='Email not found'; }
        else { [$ok,$err] = notify_send_email($to, $subject ?: 'Notification', $body, $cfg); }
      }

      if ($ok) {
        $upd=$pdo->prepare("UPDATE notifications SET status='sent', sent_at=NOW(), last_error=NULL WHERE id=?");
        $upd->execute([$id]); $sent++;
      } else {
        $retries = (int)$n['retries'] + 1;
        // বাংলা: এক্সপোনেনশিয়াল ব্যাকঅফ (১৫, ৩০, ৬০ মিনিট)
        $mins = min(60, 15 * $retries);
        $upd=$pdo->prepare("UPDATE notifications SET status='queued', retries=?, send_after=DATE_ADD(NOW(), INTERVAL {$mins} MINUTE), last_error=? WHERE id=?");
        $upd->execute([$retries, $err, $id]); $failed++;
      }
    }
    return ['processed'=>count($rows),'sent'=>$sent,'failed'=>$failed];
  }
}
