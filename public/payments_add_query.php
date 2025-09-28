<?php
// /public/payments_add_query.php
// বাংলা কমেন্ট; ফর্ম থেকে আসা পেমেন্ট সেভ করে — account_id + received_by সেট করে

declare(strict_types=1);
require_once __DIR__.'/../app/require_login.php';
require_once __DIR__.'/../app/wallet_resolver.php';

try{
  if ($_SERVER['REQUEST_METHOD']!=='POST') throw new Exception('Invalid request.');
  // আপনার ফর্ম ফিল্ড নাম অনুযায়ী অ্যাডজাস্ট করুন
  $payload = [
    'customer_id' => (int)($_POST['customer_id'] ?? 0),
    'amount'      => (float)($_POST['amount'] ?? 0),
    'method'      => trim((string)($_POST['method'] ?? '')),
    'ref_no'      => trim((string)($_POST['ref_no'] ?? '')),
    'notes'       => trim((string)($_POST['notes'] ?? '')),
    'received_by' => (int)($_SESSION['user']['id'] ?? 0), // যিনি লগইন আছেন তিনিই collector
  ];
  $payId = save_payment_with_wallet($payload);

  $_SESSION['flash_success'] = 'Payment saved (ID: '.$payId.')';
  header('Location: /public/payments.php'); // আপনার লিস্ট পেজ
  exit;
}catch(Throwable $e){
  http_response_code(400);
  $_SESSION['flash_error'] = $e->getMessage();
  header('Location: /public/payment_add.php'); // আপনার ফর্ম পেজ
  exit;
}
