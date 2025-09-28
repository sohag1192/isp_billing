<?php
// /app/wallet_resolver.php
// Comments: Bangla; Procedural + PDO

require_once __DIR__ . '/db.php';

function dbx(){ static $p; if(!$p){ $p=db(); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);} return $p; }
function h($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
function tbl_exists($t){ try{ dbx()->query("SELECT 1 FROM `$t` LIMIT 1"); return true; }catch(Throwable $e){ return false; } }
function hascol($t,$c){ try{ $cols=dbx()->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN)?:[]; return in_array($c,$cols,true); }catch(Throwable $e){ return false; } }

/* ইউজারের নাম/লেবেল (লগ/ডিফল্টে কাজে লাগে) */
function user_label(int $uid): string {
  if($uid<=0 || !tbl_exists('users')) return 'User#'.$uid;
  $pick='name';
  $cols=dbx()->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
  foreach(['name','full_name','username','email'] as $c) if(in_array($c,$cols,true)){ $pick=$c; break; }
  $st=dbx()->prepare("SELECT $pick FROM users WHERE id=?"); $st->execute([$uid]);
  $u=$st->fetchColumn();
  return $u!==false && $u!==null && $u!=='' ? (string)$u : 'User#'.$uid;
}

/* ইউজারের ওয়ালেট নিশ্চিত করা (না থাকলে অটো-ক্রিয়েট) */
function ensure_user_account(int $uid): int {
  if($uid<=0 || !tbl_exists('accounts')) return 0;
  if(!hascol('accounts','user_id')) dbx()->exec("ALTER TABLE accounts ADD COLUMN user_id INT NULL");

  $q=dbx()->prepare("SELECT id FROM accounts WHERE user_id=? LIMIT 1"); $q->execute([$uid]);
  $id=(int)$q->fetchColumn(); if($id>0) return $id;

  $fields=['user_id']; $marks=['?']; $vals=[$uid];
  if(hascol('accounts','name'))      { $fields[]='name';      $marks[]='?'; $vals[]='Wallet of '.user_label($uid); }
  if(hascol('accounts','type'))      { $fields[]='type';      $marks[]='?'; $vals[]='user'; }
  if(hascol('accounts','is_active')) { $fields[]='is_active'; $marks[]='?'; $vals[]=1; }
  if(hascol('accounts','created_at')){ $fields[]='created_at';$marks[]='?'; $vals[]=date('Y-m-d H:i:s'); }
  $sql="INSERT INTO accounts (".implode(',',$fields).") VALUES (".implode(',',$marks).")";
  dbx()->prepare($sql)->execute($vals);
  return (int)dbx()->lastInsertId();
}

/* কোম্পানি ভল্ট খুঁজে বের করা (fallback) */
function company_account_id(): int {
  if(!tbl_exists('accounts')) return 0;
  if(hascol('accounts','type')){
    $id=(int)dbx()->query("SELECT id FROM accounts WHERE type IN('company','vault') AND user_id IS NULL ORDER BY id LIMIT 1")->fetchColumn();
    if($id>0) return $id;
  }
  if(hascol('accounts','name')){
    $id=(int)dbx()->query("SELECT id FROM accounts WHERE user_id IS NULL AND (name LIKE '%Company%' OR name LIKE '%Vault%' OR name='Undeposited Funds') ORDER BY id LIMIT 1")->fetchColumn();
    if($id>0) return $id;
  }
  // একদম কিছু না পেলে প্রথম company-ish রো, বা কোনো NULL user_id রো
  $id=(int)dbx()->query("SELECT id FROM accounts WHERE user_id IS NULL ORDER BY id LIMIT 1")->fetchColumn();
  return $id>0?$id:0;
}

/* Collector → কোন অ্যাকাউন্টে ক্রেডিট হবে */
function resolve_credit_account(int $collector_user_id): int {
  // 1) ইউজারের নিজস্ব ওয়ালেট
  $acc = ensure_user_account($collector_user_id);
  if($acc>0) return $acc;
  // 2) কোম্পানি/ভল্ট
  return company_account_id();
}

/* পেমেন্ট সেভ (account_id + received_by সহ) */
function save_payment_with_wallet(array $p): int {
  // $p = ['customer_id'=>?, 'amount'=>?, 'method'=>?, 'ref_no'=>?, 'notes'=>?, 'received_by'=>?]
  if(!tbl_exists('payments')) throw new Exception('payments table not found.');
  $amount = (float)($p['amount'] ?? 0);
  if($amount<=0) throw new Exception('Invalid amount.');

  // ensure columns
  if(!hascol('payments','account_id')) dbx()->exec("ALTER TABLE payments ADD COLUMN account_id INT NULL");
  if(!hascol('payments','received_by')) dbx()->exec("ALTER TABLE payments ADD COLUMN received_by INT NULL");

  $collector = (int)($p['received_by'] ?? 0);
  if($collector<=0) throw new Exception('received_by missing.');

  $account_id = resolve_credit_account($collector);
  if($account_id<=0) throw new Exception('No target account found.');

  // dynamic columns present in your schema
  $cols=dbx()->query("SHOW COLUMNS FROM payments")->fetchAll(PDO::FETCH_COLUMN);
  $fields=['account_id','received_by','amount']; $marks=['?','?','?']; $vals=[$account_id,$collector,$amount];

  if(in_array('customer_id',$cols,true)){ $fields[]='customer_id'; $marks[]='?'; $vals[]=(int)($p['customer_id']??0); }
  if(in_array('method',$cols,true)){      $fields[]='method';      $marks[]='?'; $vals[]=(string)($p['method']??''); }
  if(in_array('ref_no',$cols,true)){      $fields[]='ref_no';      $marks[]='?'; $vals[]=(string)($p['ref_no']??''); }
  elseif(in_array('txn_ref',$cols,true)){ $fields[]='txn_ref';     $marks[]='?'; $vals[]=(string)($p['ref_no']??''); }
  if(in_array('notes',$cols,true)){       $fields[]='notes';       $marks[]='?'; $vals[]=(string)($p['notes']??''); }
  if(in_array('created_at',$cols,true)){  $fields[]='created_at';  $marks[]='?'; $vals[]=date('Y-m-d H:i:s'); }
  if(in_array('paid_at',$cols,true)){     $fields[]='paid_at';     $marks[]='?'; $vals[]=date('Y-m-d H:i:s'); }

  $sql="INSERT INTO payments (".implode(',',$fields).") VALUES (".implode(',',$marks).")";
  dbx()->prepare($sql)->execute($vals);
  return (int)dbx()->lastInsertId();
}
