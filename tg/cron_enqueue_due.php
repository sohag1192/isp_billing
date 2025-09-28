<?php
// /tg/cron_enqueue_due.php
// বাংলা: বকেয়া ক্লায়েন্টদের জন্য টেলিগ্রাম নোটিফিকেশন কিউতে তোলা
declare(strict_types=1);
error_reporting(E_ALL); ini_set('display_errors','0'); ini_set('log_errors','1');

$ROOT = dirname(__DIR__, 1);
require_once $ROOT.'/app/db.php';
require_once __DIR__.'/telegram.php';

$pdo=db(); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);

// helpers
function tbl_exists(PDO $pdo, string $t): bool {
  try{ $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
       $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
       $q->execute([$db,$t]); return (bool)$q->fetchColumn(); }catch(Throwable $e){ return false; }
}
function col_exists(PDO $pdo, string $t, string $c): bool {
  try{ $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
       $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
       $q->execute([$db,$t,$c]); return (bool)$q->fetchColumn(); }catch(Throwable $e){ return false; }
}
function pick_tbl(PDO $pdo, array $cands): ?string { foreach($cands as $t) if (tbl_exists($pdo,$t)) return $t; return null; }

const STRICT_MONTH = true;
const GRACE_DAYS   = 0;

// Safe due check
function client_is_due(PDO $pdo, int $client_id): bool {
  if (!tbl_exists($pdo,'invoices')) return false;
  $hasMonth = col_exists($pdo,'invoices','month') || col_exists($pdo,'invoices','bill_month');
  $hasYear  = col_exists($pdo,'invoices','year')  || col_exists($pdo,'invoices','bill_year');

  $cols=['id','status']; if($hasMonth)$cols[]=col_exists($pdo,'invoices','month')?'month':'bill_month AS month'; if($hasYear)$cols[]=col_exists($pdo,'invoices','year')?'year':'bill_year AS year'; if(col_exists($pdo,'invoices','created_at'))$cols[]='created_at';
  $st=$pdo->prepare("SELECT ".implode(',',$cols)." FROM invoices WHERE client_id=? ORDER BY id DESC LIMIT 1"); $st->execute([$client_id]); $inv=$st->fetch(PDO::FETCH_ASSOC);
  $today=new DateTimeImmutable(date('Y-m-d'));
  if($inv){
    $status=strtolower((string)($inv['status']??''));
    if(STRICT_MONTH && $hasMonth && $hasYear){
      $cm=(int)date('n'); $cy=(int)date('Y'); $im=(int)($inv['month']??$cm); $iy=(int)($inv['year']??$cy);
      if($iy>$cy || ($iy===$cy && $im>$cm)) return false;
    }
    if($status==='due'){
      if(GRACE_DAYS>0 && !empty($inv['created_at'])){
        try{$base=new DateTimeImmutable($inv['created_at']); $diff=(int)$today->diff($base)->format('%a'); if($diff<GRACE_DAYS) return false;}catch(Throwable $e){}
      }
      return true;
    }
    if($status==='paid') return false;
  }
  $st1=$pdo->prepare("SELECT COALESCE(SUM(payable),0) FROM invoices WHERE client_id=?"); $st1->execute([$client_id]); $total=(float)$st1->fetchColumn();
  if(!tbl_exists($pdo,'payments')) return $total>0.000001;
  $paid=$pdo->prepare("SELECT COALESCE(SUM(p.amount + COALESCE(p.discount,0)),0) FROM payments p JOIN invoices i ON p.bill_id=i.id WHERE i.client_id=?");
  $paid->execute([$client_id]); $tp=(float)$paid->fetchColumn();
  return ($total-$tp) > 0.000001;
}

// fetch clients
$CT = pick_tbl($pdo, ['clients','customers','subscribers']);
if (!$CT) { echo "No clients table.\n"; exit; }
$id = col_exists($pdo,$CT,'id')?'id':(col_exists($pdo,$CT,'client_id')?'client_id':'id');
$name= col_exists($pdo,$CT,'name')?'name':(col_exists($pdo,$CT,'client_name')?'client_name':$id);

// enqueue
$rows=$pdo->query("SELECT `$id` AS id, `$name` AS name FROM `$CT`")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$enq=0;
foreach ($rows as $r) {
  $cid=(int)$r['id'];
  // only if subscribed
  $sub=$pdo->prepare("SELECT 1 FROM telegram_subscribers WHERE client_id=? AND is_active=1 LIMIT 1");
  $sub->execute([$cid]); if(!$sub->fetchColumn()) continue;
  if (!client_is_due($pdo,$cid)) continue;

  $payload=[
    'amount' => '', // চাইলে পরিমাণ হিসাব যোগ করবে
    'pay_link' => "/public/pay.php?client_id={$cid}",
    'portal_link' => "/public/portal.php?client_id={$cid}",
  ];
  $uk="due-tg-".date('Y-m-d')."-c{$cid}";
  if (tg_queue($pdo,$cid,'due_reminder',$payload,$uk)) $enq++;
}
echo "Enqueued: {$enq}\n";
