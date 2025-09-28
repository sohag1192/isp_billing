<?php
// /api/mt_list_profiles.php
// Output: { ok, profiles:[] }

declare(strict_types=1);
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/routeros_api.class.php';

header('Content-Type: application/json; charset=utf-8');
function jexit($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

try{
  $pdo = db();
  $rid = intval($_GET['router_id'] ?? 0);
  if($rid<=0) jexit(['ok'=>false,'msg'=>'router_id required']);

  $st = $pdo->prepare("SELECT * FROM routers WHERE id=?");
  $st->execute([$rid]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  if(!$r) jexit(['ok'=>false,'msg'=>'Router not found']);

  $API = new RouterosAPI();
  if(!$API->connect($r['ip'] ?? $r['address'], $r['username'], $r['password'], intval($r['api_port'] ?? 8728))){
    jexit(['ok'=>false,'msg'=>'API connect failed']);
  }

  // প্রোফাইল লিস্ট
  $rows = $API->comm('/ppp/profile/print');
  $API->disconnect();

  $names = [];
  if(is_array($rows)){
    foreach($rows as $row){
      // বিভিন্ন রিটার্ন ফরম্যাট সামলাই
      $nm = $row['name'] ?? $row['=.name'] ?? $row['.name'] ?? null;
      if(!$nm){
        // fallback: যেকোনো কী যাতে 'name' আছে
        foreach($row as $k=>$v){
          if(stripos($k,'name')!==false && is_string($v) && $v!==''){ $nm=$v; break; }
        }
      }
      if($nm) $names[] = $nm;
    }
  }
  $names = array_values(array_unique($names));

  jexit(['ok'=>true,'profiles'=>$names]);
}catch(Throwable $e){
  jexit(['ok'=>false,'msg'=>$e->getMessage()]);
}
