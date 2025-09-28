<?php
// file: api/pon_scan.php
// Scan VSOL PON ports → "show pon optical transceiver" per interface, parse TX dBm and save.

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/telnet.php';

$host   = $_GET['host']   ?? '192.168.200.2';
$family = strtolower($_GET['family'] ?? 'epon');   // epon|gpon
$iface  = $_GET['iface']  ?? '';                   // if set, only this (e.g. 0/3)
$start  = (int)($_GET['start'] ?? 1);
$end    = (int)($_GET['end']   ?? 8);
$debug  = (int)($_GET['debug'] ?? 0);

$st = db()->prepare("SELECT * FROM olts WHERE host=? LIMIT 1");
$st->execute([$host]);
$olt = $st->fetch(PDO::FETCH_ASSOC);
if (!$olt) {
  $olt = ['id'=>null,'host'=>$host,'ssh_port'=>23,'username'=>'admin','password'=>'Xpon@Olt9417#','enable_password'=>'Xpon@Olt9417#'];
} else {
  if (empty($olt['ssh_port'])) $olt['ssh_port']=23;
  if (empty($olt['enable_password'])) $olt['enable_password']=$olt['password'];
}
$olt_id = $olt['id'];

function parse_tx_from_transceiver($text){
  // match: Transmit Power : 1.23 mW ( -3.45 dBm )   OR   Tx Power(dBm): -3.45
  if (preg_match('/Transmit\s*Power\s*:\s*[\d\.]+\s*\w+\s*\(([-\d\.]+)\s*dBm\)/i', $text, $m)) return (float)$m[1];
  if (preg_match('/Tx\s*Power(?:\s*\(dBm\))?\s*:\s*([-\d\.]+)/i', $text, $m)) return (float)$m[1];
  return null;
}

function scan_one_pon($olt, $family, $iface, $debug=0){
  $ifFamily = ($family==='gpon')?'gpon':'epon';
  $promptCfg = '\((?:config[^\)]*)?\)\s*#\s*$|#\s*$';
  $cmds = [
    'configure terminal',
    "interface {$ifFamily} {$iface}",
    'no page', 'terminal length 0', 'screen-length 0 temporary',
    'show pon optical transceiver',
    'exit',
  ];
  $res = telnet_run_commands($olt['host'], (int)$olt['ssh_port'], $olt['username'], $olt['password'], $cmds, $promptCfg, true, $olt['enable_password'], $debug?true:false, 10);
  if (!$res['ok']) return ['ok'=>false,'iface'=>$iface,'error'=>$res['error']];
  $tx = parse_tx_from_transceiver($res['output'] ?: '');
  // save
  $db = db();
  if (!$olt['id']) {
    $ins = $db->prepare("INSERT INTO olts (name,host,mgmt_proto,ssh_port,username,password,enable_password) VALUES (?,?,?,?,?,?,?)");
    $ins->execute([$olt['host'],$olt['host'],'telnet',(int)$olt['ssh_port'],$olt['username'],$olt['password'],$olt['enable_password']]);
    $olt_id = (int)$db->lastInsertId();
  } else $olt_id = $olt['id'];

  $insm = $db->prepare("INSERT INTO pon_port_metrics (olt_id, family, iface, tx_dbm) VALUES (?,?,?,?)");
  $insm->execute([$olt_id, $ifFamily, $iface, $tx]);

  return ['ok'=>true, 'iface'=>$iface, 'tx_dbm'=>$tx];
}

$ifaces = [];
if ($iface) {
  if (!preg_match('#^\d+/\d+$#',$iface)) { echo json_encode(['status'=>'error','message'=>'Bad iface']); exit; }
  $ifaces[] = $iface;
} else {
  for($i=max(1,$start); $i<=max($start,$end); $i++) $ifaces[] = "0/$i";
}

$out = ['status'=>'success','host'=>$host,'family'=>$family,'results'=>[]];
foreach ($ifaces as $ifc) $out['results'][] = scan_one_pon($olt, $family, $ifc, $debug);
echo json_encode($out, JSON_UNESCAPED_UNICODE);
