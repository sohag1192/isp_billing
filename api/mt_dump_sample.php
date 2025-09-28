<?php
// /api/mt_dump_sample.php
declare(strict_types=1);
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/routeros_api.class.php';

header('Content-Type: application/json; charset=utf-8');
function out($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT); exit; }

try{
  $pdo = db();

  $rid     = intval($_GET['router_id'] ?? 0);
  $rip     = trim((string)($_GET['ip'] ?? ''));      // <-- শুধু ip
  $rname   = trim((string)($_GET['name'] ?? ''));
  $profile = trim((string)($_GET['profile'] ?? ''));
  $limit   = max(1, intval($_GET['limit'] ?? 5));

  // resolve router (address কলাম নেই, তাই ব্যবহার করবো না)
  if($rid>0){
    $st = $pdo->prepare("SELECT * FROM routers WHERE id=?");
    $st->execute([$rid]);
  } elseif($rip !== ''){
    $st = $pdo->prepare("SELECT * FROM routers WHERE ip=? LIMIT 1");
    $st->execute([$rip]);
  } elseif($rname !== ''){
    $st = $pdo->prepare("SELECT * FROM routers WHERE name=? LIMIT 1");
    $st->execute([$rname]);
  } else {
    out(['ok'=>false,'msg'=>'router_id or ip or name required']);
  }

  $r = $st->fetch(PDO::FETCH_ASSOC);
  if(!$r){ out(['ok'=>false,'msg'=>'Router not found']); }

  $API = new RouterosAPI();
  // connect with routers.ip only
  if(!$API->connect($r['ip'], $r['username'], $r['password'], intval($r['api_port'] ?? 8728))){
    out(['ok'=>false,'msg'=>'API connect failed']);
  }

  $attempts = []; $rows = null;
  $args = [];
  if($profile!=='') $args['?profile'] = $profile;

  try{ $rows = $API->comm('/ppp/secret/print', $args + ['=.show-ids'=>'']); $attempts[]=['comm.dot',is_array($rows)?count($rows):0]; }catch(Throwable $e){ $attempts[]=['comm.dot.err',$e->getMessage()]; }
  if(!$rows || !is_array($rows)){
    try{ $rows = $API->comm('/ppp/secret/print', $args); $attempts[]=['comm.plain',is_array($rows)?count($rows):0]; }catch(Throwable $e){ $attempts[]=['comm.plain.err',$e->getMessage()]; }
  }
  if(!$rows || !is_array($rows)){
    $API->disconnect();
    out(['ok'=>false,'msg'=>'No rows','attempts'=>$attempts]);
  }

  $outRows = [];
  foreach($rows as $i=>$row){
    if($i>=$limit) break;
    $masked = $row;
    foreach(['password','=.password','.password'] as $k){
      if(isset($masked[$k]) && is_string($masked[$k]) && $masked[$k] !== '') $masked[$k] = '••••••';
    }
    $outRows[] = ['keys'=>array_keys($row), 'row'=>$masked];
  }
  $API->disconnect();
  out(['ok'=>true,'attempts'=>$attempts,'count'=>count($rows),'sample'=>$outRows]);
}catch(Throwable $e){
  out(['ok'=>false,'msg'=>$e->getMessage()]);
}
