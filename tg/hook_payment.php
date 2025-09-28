<?php
// /tg/hook_payment.php
// UI: English; Comments: বাংলা — Payment → Telegram notification hooks (instant send + fail→queue)

declare(strict_types=1);

require_once __DIR__ . '/telegram.php';

/* -----------------------------------------------------------------------------
 | Always-queue helper (backup)
 | বাংলা: subscriber থাকুক/না থাকুক—queue তোলা হবে; পরে runner/cron পাঠাবে।
 -----------------------------------------------------------------------------*/
if (!function_exists('tg_notify_payment_direct')) {
  function tg_notify_payment_direct(PDO $pdo, int $client_id, float $amount, ?int $invoice_id=null, ?int $payment_id=null, array $extraPayload=[]): bool {
    if ($client_id <= 0 || $amount <= 0) return false;
    if (!function_exists('tg_queue') || !function_exists('tg_tbl_exists') || !tg_tbl_exists($pdo, 'telegram_queue')) return false;

    $cfg = tg_cfg();
    $payload = array_merge([
      'amount'      => number_format($amount, 2, '.', ''),
      'invoice_id'  => $invoice_id ? (string)$invoice_id : '',
      'portal_link' => ($cfg['app_base_url'] ? rtrim($cfg['app_base_url'],'/') : '') . "/public/portal.php?client_id={$client_id}",
    ], $extraPayload);

    $uniq = $payment_id ? "pay-confirm-{$payment_id}" : ("pay-confirm-".date('Ymd')."-c{$client_id}-a".(int)round($amount*100));
    return (bool)tg_queue($pdo, $client_id, 'payment_confirm', $payload, $uniq);
  }
}

/* -----------------------------------------------------------------------------
 | Optional: notify by payment_id (schema-aware)
 | বাংলা: payments টেবিল থেকে resolve করে queue তোলা (যদি দরকার হয়)
 -----------------------------------------------------------------------------*/
if (!function_exists('tg_notify_payment_by_id')) {
  function tg_notify_payment_by_id(PDO $pdo, int $payment_id): bool {
    if ($payment_id <= 0) return false;

    $PT = tg_pick_tbl($pdo, ['payments','payment','client_payments','bills_payments','transactions']);
    if (!$PT) { error_log('[TG] payments table not found'); return false; }

    $idcol   = null; foreach (['id','payment_id','pid'] as $c) if (tg_col_exists($pdo,$PT,$c)) { $idcol=$c; break; }
    if (!$idcol) $idcol = 'id';

    $amtcol  = null; foreach (['amount','paid','pay_amount','money','total','value'] as $c) if (tg_col_exists($pdo,$PT,$c)) { $amtcol=$c; break; }
    $clidcol = null; foreach (['client_id','customer_id','subscriber_id','user_id','c_id'] as $c) if (tg_col_exists($pdo,$PT,$c)) { $clidcol=$c; break; }
    $invcol  = null; foreach (['bill_id','invoice_id','inv_id','invoice','bill'] as $c) if (tg_col_exists($pdo,$PT,$c)) { $invcol=$c; break; }
    $disccol = tg_col_exists($pdo,$PT,'discount') ? 'discount' : null;

    $sel = ["`$idcol` AS id"];
    if ($amtcol)  $sel[] = "`$amtcol` AS amount";
    if ($clidcol) $sel[] = "`$clidcol` AS client_id";
    if ($invcol)  $sel[] = "`$invcol` AS invoice_id";
    if ($disccol) $sel[] = "`$disccol` AS discount";

    $sql = "SELECT ".implode(',', $sel)." FROM `$PT` WHERE `$idcol`=? LIMIT 1";
    $st  = $pdo->prepare($sql); $st->execute([$payment_id]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) return false;

    $amount = (float)($p['amount'] ?? 0);
    if ($disccol && isset($p['discount'])) $amount += (float)$p['discount'];

    $client_id  = isset($p['client_id'])  ? (int)$p['client_id']  : 0;
    $invoice_id = isset($p['invoice_id']) ? (int)$p['invoice_id'] : null;

    if ($client_id <= 0 || $amount <= 0.0) return false;
    return tg_notify_payment_direct($pdo, $client_id, $amount, $invoice_id, $payment_id);
  }
}

/* -----------------------------------------------------------------------------
 | Instant send (no pending) — FAIL ⇒ re-queue as 'queued' (pending)
 | বাংলা: সাথে সাথে পাঠানোর চেষ্টা; ব্যর্থ হলে FAILED রো-টাকে আবার queued করি।
 -----------------------------------------------------------------------------*/
if (!function_exists('tg_send_payment_now')) {
  function tg_send_payment_now(PDO $pdo, int $client_id, float $amount, ?int $invoice_id=null, ?int $payment_id=null, array $extraPayload=[]): bool {
    if ($client_id <= 0 || $amount <= 0) return false;
    if (!function_exists('tg_send_now')) {
      // instant sender নাই → queue
      return tg_notify_payment_direct($pdo, $client_id, $amount, $invoice_id, $payment_id, $extraPayload);
    }

    $cfg = tg_cfg();
    $payload = array_merge([
      'amount'      => number_format($amount, 2, '.', ''),
      'invoice_id'  => $invoice_id ? (string)$invoice_id : '',
      'portal_link' => ($cfg['app_base_url'] ? rtrim($cfg['app_base_url'],'/') : '') . "/public/portal.php?client_id={$client_id}",
    ], $extraPayload);

    $uniq = $payment_id ? "pay-confirm-{$payment_id}" : ("pay-confirm-".date('Ymd')."-c{$client_id}-a".(int)round($amount*100));

    $res = tg_send_now($pdo, $client_id, 'payment_confirm', $payload, $uniq);
    if (!($res['ok'] ?? false)) {
      // FAILED → back to QUEUED (pending)
      $qid = (int)($res['queue_id'] ?? 0);
      if ($qid > 0 && function_exists('tg_tbl_exists') && tg_tbl_exists($pdo,'telegram_queue')) {
        $up = $pdo->prepare("UPDATE telegram_queue SET status='queued', send_after=NOW() WHERE id=?");
        $up->execute([$qid]);
      } elseif (function_exists('tg_queue') && function_exists('tg_tbl_exists') && tg_tbl_exists($pdo,'telegram_queue')) {
        tg_queue($pdo, $client_id, 'payment_confirm', $payload, $uniq);
      }
      if (!empty($res['error'])) error_log('[TG_PAY_NOW] '.$res['error']);
    }
    return (bool)($res['ok'] ?? false);
  }
}
