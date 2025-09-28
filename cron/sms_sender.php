<?php
// /cron/sms_sender.php
// Purpose: sms_queue থেকে pending মেসেজ সেন্ড করা + status আপডেট
// Notes: rate-limit / batch-size কনফিগ রাখুন; রিট্রাই লিমিট সহ

declare(strict_types=1);
date_default_timezone_set('Asia/Dhaka');

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/sms.php';   // send_sms()

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ---------- Settings ----------
$batch      = max(1, (int)($_GET['batch'] ?? 100));   // প্রতি রান কতগুলো পাঠাবেন
$max_retry  = 3;                                      // ব্যর্থ হলে কতবার পর্যন্ত রিট্রাই
$sleep_ms   = 200;                                    // প্রতিটি সেন্ডের মাঝে ডিলে (ms)

// ---------- Fetch pending ----------
$q = $pdo->prepare("
  SELECT id, client_id, mobile, message, attempts
  FROM sms_queue
  WHERE status='pending' AND scheduled_at <= NOW()
  ORDER BY id ASC
  LIMIT :lim
");
$q->bindValue(':lim', $batch, PDO::PARAM_INT);
$q->execute();
$items = $q->fetchAll(PDO::FETCH_ASSOC);

if (!$items) { echo "No pending SMS.\n"; exit; }

// ---------- Prepare updates ----------
$u_sent = $pdo->prepare("
  UPDATE sms_queue
     SET status='sent', sent_at=NOW(), attempts=attempts+1, last_error=NULL, updated_at=NOW()
   WHERE id=?
");
$u_fail = $pdo->prepare("
  UPDATE sms_queue
     SET status=CASE WHEN attempts+1 >= :maxr THEN 'failed' ELSE 'pending' END,
         attempts=attempts+1,
         last_error=:err,
         updated_at=NOW()
   WHERE id=:id
");

$sent = 0; $failed = 0;
foreach ($items as $it) {
  $id      = (int)$it['id'];
  $mobile  = trim($it['mobile']);
  $message = (string)$it['message'];

  if ($mobile === '' || $message === '') {
    $u_fail->execute([':maxr'=>$max_retry, ':err'=>'missing mobile/message', ':id'=>$id]);
    $failed++;
    continue;
  }

  $res = send_sms($mobile, $message);

  if ($res['ok']) {
    $u_sent->execute([$id]);
    $sent++;
  } else {
    $err = 'HTTP='.$res['http_code'].' ERR='.$res['error'].' RAW='.substr((string)$res['raw'],0,250);
    $u_fail->execute([':maxr'=>$max_retry, ':err'=>$err, ':id'=>$id]);
    $failed++;
  }

  // rate-limit
  if ($sleep_ms > 0) usleep($sleep_ms * 1000);
}

echo "SMS sent={$sent}, failed={$failed}, batch={$batch}\n";
