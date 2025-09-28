<?php
// /public/client_payment_add_query.php
// Comments: বাংলা — পেমেন্ট সেভ + ইউজারের ওয়ালেটে ক্রেডিট

declare(strict_types=1);
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/wallet_book.php';

try{
  if($_SERVER['REQUEST_METHOD']!=='POST') throw new Exception('Invalid request.');

  // আপনার ফর্ম ফিল্ডের নাম অনুযায়ী এগুলো ম্যাপ করুন
  $payload = [
    'customer_id' => (int)($_POST['customer_id'] ?? $_POST['client_id'] ?? 0),
    'amount'      => (float)($_POST['amount'] ?? 0),
    'method'      => trim((string)($_POST['method'] ?? '')),
    'ref_no'      => trim((string)($_POST['ref_no'] ?? '')),
    'notes'       => trim((string)($_POST['notes'] ?? '')),
    // যিনি লগইন আছেন—তিনিই পেমেন্ট নিলেন
    'received_by' => (int)($_SESSION['user']['id'] ?? 0),
  ];

  $payId = save_payment_with_wallet($payload);  // <-- মূল জাদু

  $_SESSION['flash_success'] = 'Payment saved (ID: '.$payId.') and credited to wallet.';
  // আপনার প্রয়োজন অনুযায়ী রিডাইরেক্ট টার্গেট বদলে নিন
  header('Location: /public/client_payments.php?client_id='.$payload['customer_id']);
  exit;

}catch(Throwable $e){
  http_response_code(400);
  $_SESSION['flash_error'] = $e->getMessage();
  header('Location: /public/payment_add.php'); // আপনার ফর্ম পেজ
  exit;
}
