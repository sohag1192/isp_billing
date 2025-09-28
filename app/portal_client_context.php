<?php
// /app/portal_client_context.php
// বাংলা: পোর্টাল পেজে ক্লায়েন্ট কন্টেক্সট রিজল্ভ করার শেয়ার্ড হেল্পার

declare(strict_types=1);
require_once __DIR__ . '/db.php';

function portal_col_exists(PDO $pdo, string $tbl, string $col): bool {
  try{
    $st = $pdo->prepare("SHOW COLUMNS FROM `$tbl` LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetchColumn();
  }catch(Throwable $e){ return false; }
}

/**
 * বাংলা: সেশন/কুয়েরি থেকে ক্লায়েন্ট আইডি বের করি।
 * কভার করা সেশন কী: client_id, SESS_CLIENT_ID, SESS_USER_ID
 * username/pppoe: pppoe_id, username, SESS_USER_NAME, SESS_USERNAME, user_name
 * email: email, SESS_USER_EMAIL
 * mobile: mobile, phone
 * কুয়েরি প্রিভিউ (লগইন থাকলেই): ?cid= / ?pppoe=
 */
function portal_resolve_client_id(PDO $pdo): ?int {
  if (session_status() === PHP_SESSION_NONE) { session_start(); }

  // 1) direct id
  foreach (['client_id','SESS_CLIENT_ID','SESS_USER_ID'] as $k) {
    if (!empty($_SESSION[$k]) && ctype_digit((string)$_SESSION[$k])) {
      return (int)$_SESSION[$k];
    }
  }

  // 2) username / pppoe / code / name
  $cands = [];
  foreach (['pppoe_id','username','SESS_USER_NAME','SESS_USERNAME','user_name'] as $k) {
    if (!empty($_SESSION[$k])) $cands[] = trim((string)$_SESSION[$k]);
  }
  foreach ($cands as $u) {
    $q = $pdo->prepare("SELECT id FROM clients WHERE pppoe_id = ? OR client_code = ? OR name = ?");
    $q->execute([$u,$u,$u]);
    if ($id = $q->fetchColumn()) return (int)$id;
  }

  // 3) email
  foreach (['email','SESS_USER_EMAIL'] as $k) {
    if (!empty($_SESSION[$k])) {
      $em = trim((string)$_SESSION[$k]);
      if ($em !== '') {
        $q = $pdo->prepare("SELECT id FROM clients WHERE email = ?");
        $q->execute([$em]);
        if ($id = $q->fetchColumn()) return (int)$id;
      }
    }
  }

  // 4) mobile
  foreach (['mobile','phone'] as $k) {
    if (!empty($_SESSION[$k])) {
      $m = preg_replace('/\D+/', '', (string)$_SESSION[$k]); // digit only
      if ($m !== '') {
        $q = $pdo->prepare("SELECT id FROM clients WHERE REPLACE(REPLACE(REPLACE(mobile,'-',''),' ','') , '+', '') LIKE ?");
        $q->execute(['%'.$m.'%']);
        if ($id = $q->fetchColumn()) return (int)$id;
      }
    }
  }

  // 5) Logged-in preview via query (secure enough for portal; require_login গ্যারান্টিযুক্ত)
  if (!empty($_GET['cid']) && ctype_digit((string)$_GET['cid'])) {
    return (int)$_GET['cid'];
  }
  if (!empty($_GET['pppoe'])) {
    $pp = trim((string)$_GET['pppoe']);
    $q = $pdo->prepare("SELECT id FROM clients WHERE pppoe_id = ? OR client_code = ? OR name = ?");
    $q->execute([$pp,$pp,$pp]);
    if ($id = $q->fetchColumn()) return (int)$id;
  }

  return null;
}

/** 
 * বাংলা: ক্লায়েন্ট পাওয়া **অবশ্যই** দরকার — না পেলে সুন্দর বার্তা সহ exit
 * রিটার্ন: ['id'=>int, 'name'=>string|null, 'pppoe_id'=>string|null]
 */
function portal_require_client(PDO $pdo): array {
  $cid = portal_resolve_client_id($pdo);
  if (!$cid) {
    http_response_code(403);
    echo '<div style="max-width:680px;margin:40px auto;font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif">';
    echo '<h4 style="margin-bottom:8px;color:#b02a37">Access denied</h4>';
    echo '<p style="margin:0 0 12px">Client context not found. Please sign in to the portal.</p>';
    echo '<a href="/public/portal/index.php" style="text-decoration:none;border:1px solid #ccc;padding:6px 10px;border-radius:6px">Back to Portal</a>';
    echo '</div>';
    exit;
  }

  $st = $pdo->prepare("SELECT id, name, pppoe_id FROM clients WHERE id=?");
  $st->execute([$cid]);
  $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['id'=>$cid,'name'=>null,'pppoe_id'=>null];

  // বাংলা: সেশন নরমালাইজ — পরে পেজগুলো সরাসরি client_id পাবে
  $_SESSION['client_id'] = (int)$row['id'];

  return $row;
}
