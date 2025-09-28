<?php
// file: api/sfp_scan.php
// Scan VSOL gigabitethernet uplinks → parse transceiver Tx/Rx/Bias/Volt/Temp.

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/telnet.php';

$host   = $_GET['host']   ?? '192.168.200.2';
$start  = (int)($_GET['start'] ?? 1);      // e.g., GE 0/1 .. 0/24
$end    = (int)($_GET['end']   ?? 24);
$list   = $_GET['list']    ?? '';          // optional: comma list "0/9,0/10"
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

function ge_cmds($iface){
  // VSOL-এ ভ্যারিয়েন্ট থাকে—দুই ধরনের কমান্ডে ট্রাই করি
  return [
    ["configure terminal", "interface gigabitethernet {$iface}", "no page", "terminal length 0", "screen-length 0 temporary", "show transceiver", "exit"],
    ["configure terminal", "no page", "terminal length 0", "screen-length 0 temporary", "show interface gigabitethernet {$iface} transceiver", "exit"],
  ];
}

function parse_ge_transceiver($text){
  $tx = $rx = $bias = $vcc = $temp = null;
  // সাধারণ ফরম্যাট
  if (preg_match('/Transmit\s*Power\s*:\s*[\d\.]+\s*\w+\s*\(([-\d\.]+)\s*dBm\)/i', $text, $m)) $tx = (float)$m[1];
  if (preg_match('/Receive\s*Power\s*:\s*[\d\.]+\s*\w+\s*\(([-\d\.]+)\s*dBm\)/i', $text, $m))  $rx = (float)$m[1];
  // অল্টারনেট কীওয়ার্ড
  if ($tx===null && preg_match('/Tx\s*Power(?:\s*\(dBm\))?\s*:\s*([-\d\.]+)/i', $text, $m)) $tx=(float)$m[1];
  if ($rx===null && preg_match('/Rx\s*Power(?:\s*\(dBm\))?\s*:\s*([-\d\.]+)/i', $text, $m)) $rx=(float)$m[1];

  if (preg_match('/(?:Laser\s*)?Bias\s*Current\s*:\s*([-\d\.]+)/i', $text, $m)) $bias = (float)$m[1];
  if (preg_match('/Supply\s*Voltage\s*:\s*([-\d\.]+)/i', $text, $m)) $vcc  = (float)$m[1];
  if (preg_match('/(?:Temperature|Module\s*Temp)\s*:\s*([-\d\.]+)/i', $text, $m)) $temp = (float)$m[1];
  return [$tx,$rx,$bias,$vcc,$temp];
}

function scan_one_ge($olt, $iface, $debug=0){
  $promptCfg = '\((?:config[^\)]*)?\)\s*#\s*$|#\s*$';
  $all = ge_cmds($iface);
  $lastErr = null; $out=NULL;
  foreach ($all as $cmds){
    $res = telnet_run_commands($olt['host'], (int)$olt['ssh_port'], $olt['username'], $olt['password'], $cmds, $promptCfg, true, $olt['enable_password'], $debug?true:false, 10);
    if ($res['ok']) { $out = $res['output'] ?: ''; break; } else $lastErr = $res['error'];
  }
  if ($out===NULL) return ['ok'=>false,'if'=>"GE {$iface}",'error'=>$lastErr?:'no_output'];

  list($tx,$rx,$bias,$vcc,$temp) = parse_ge_transceiver($out);

  // save
  $db = db();
  if (!$olt['id']) {
    $ins = $db->prepare("INSERT INTO olts (name,host,mgmt_proto,ssh_port,username,password,enable_password) VALUES (?,?,?,?,?,?,?)");
    $ins->execute([$olt['host'],$olt['host'],'telnet',(int)$olt['ssh_port'],$olt['username'],$olt['password'],$olt['enable_password']]);
    $olt_id = (int)$db->lastInsertId();
  } else $olt_id = $olt['id'];

  $name = "GigabitEthernet{$iface}";
  $insm = $db->prepare("INSERT INTO sfp_port_metrics (olt_id, ifname, tx_dbm, rx_dbm, tx_bias, supply_v, temp_c) VALUES (?,?,?,?,?,?,?)");
  $insm->execute([$olt_id, $name, $tx, $rx, $bias, $vcc, $temp]);

  return ['ok'=>true,'if'=>$name,'tx_dbm'=>$tx,'rx_dbm'=>$rx,'tx_bias'=>$bias,'vcc'=>$vcc,'temp'=>$temp];
}

$ifaces = [];
if ($list) {
  foreach (explode(',', $list) as $p){
    $p = trim($p); if ($p==='') continue;
    if (!preg_match('#^\d+/\d+$#',$p)) continue;
    $ifaces[] = $p;
  }
} else {
  for($i=max(1,$start); $i<=max($start,$end); $i++) $ifaces[] = "0/$i";
}

$out = ['status'=>'success','host'=>$host,'results'=>[]];
foreach ($ifaces as $ifc) $out['results'][] = scan_one_ge($olt, $ifc, $debug);
echo json_encode($out, JSON_UNESCAPED_UNICODE);
