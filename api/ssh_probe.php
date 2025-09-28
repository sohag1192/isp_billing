<?php
header('Content-Type: application/json; charset=utf-8');
$host = $_GET['host'] ?? '192.168.200.2';
$port = (int)($_GET['port'] ?? 22);
$timeout = 3;

$start = microtime(true);
$fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
if (!$fp) {
  echo json_encode(['status'=>'error','reachable'=>false,'errno'=>$errno,'error'=>$errstr]); exit;
}
stream_set_timeout($fp, 2);
$banner = fgets($fp, 4096);
fclose($fp);
$ms = (int)((microtime(true)-$start)*1000);
echo json_encode(['status'=>'success','reachable'=>true,'rtt_ms'=>$ms,'banner'=>trim((string)$banner)]);
