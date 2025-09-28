<?php
// file: api/auto_link_onu_from_mt.php
// Goal: Take PPPoE caller-id (CPE MAC) from MikroTik and map it to an ONU via OLT MAC table.
// Usage:
//   - One client:  /api/auto_link_onu_from_mt.php?client_id=123
//   - All active:  /api/auto_link_onu_from_mt.php?all=1
// Optional:
//   - host=192.168.200.2 (OLT host override)
//   - family=epon|gpon (default epon)

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/routeros_api.class.php';
require_once __DIR__ . '/../app/telnet.php'; // we will scan OLT MAC table via telnet

header('Content-Type: application/json; charset=utf-8');

$clientId = (int)($_GET['client_id'] ?? 0);
$doAll    = isset($_GET['all']) ? true : false;

$oltHost  = $_GET['host']   ?? '192.168.200.2';
$family   = strtolower($_GET['family'] ?? 'epon'); // used when parsing iface label

if (!$clientId && !$doAll) {
  echo json_encode(['status'=>'error','message'=>'Provide client_id or all=1']); exit;
}

// ---------- Helpers ----------
function norm_mac($mac){
  $m = strtolower(trim($mac));
  $m = preg_replace('/[^0-9a-f]/i', '', $m);
  if (strlen($m)!==12) return null;
  return implode(':', str_split($m,2));
}

function get_caller_mac_for_client($clientRow){
  $api = new RouterosAPI();
  $api->debug = false;

  if (empty($clientRow['router_ip'])) return [null,'no_router'];
  if (!$api->connect($clientRow['router_ip'], $clientRow['username'], $clientRow['password'], $clientRow['api_port'])) {
    return [null,'mt_connect_fail'];
  }

  $caller = null;
  $active = $api->comm('/ppp/active/print', ['?name' => $clientRow['pppoe_id']]);
  if (!empty($active)) {
    // Preferred
    if (!empty($active[0]['caller-id'])) $caller = $active[0]['caller-id'];
    // Fallback via ARP using active IP
    if (!$caller && !empty($active[0]['address'])) {
      $arp = $api->comm('/ip/arp/print', ['?address'=>$active[0]['address']]);
      if (!empty($arp[0]['mac-address'])) $caller = $arp[0]['mac-address'];
    }
  } else {
    // Client offline → last ARP by static IP (if any saved)
    if (!empty($clientRow['ip_address'])) {
      $arp = $api->comm('/ip/arp/print', ['?address'=>$clientRow['ip_address']]);
      if (!empty($arp[0]['mac-address'])) $caller = $arp[0]['mac-address'];
    }
  }
  $api->disconnect();

  return [$caller, $caller ? 'ok':'no_mac'];
}

function load_olt_row($host){
  $st = db()->prepare("SELECT * FROM olts WHERE host=? LIMIT 1");
  $st->execute([$host]);
  $olt = $st->fetch(PDO::FETCH_ASSOC);
  if ($olt) {
    if (empty($olt['ssh_port'])) $olt['ssh_port']=23;
    if (empty($olt['enable_password'])) $olt['enable_password']=$olt['password'];
    return $olt;
  }
  // fallback default (VSOL)
  return [
    'id'=>null,'host'=>$host,'ssh_port'=>23,
    'username'=>'admin','password'=>'Xpon@Olt9417#','enable_password'=>'Xpon@Olt9417#'
  ];
}

/**
 * Scan OLT MAC address-table and return mapping:
 *  each row: [ 'mac' => 'aa:bb:..', 'family'=>'epon', 'iface'=>'0/3', 'onu_id'=>1 ]
 * We try to parse lines like:
 *   xx:xx:xx:xx:xx:xx  ...  EPON0/3:1
 */
function scan_olt_mac_table($olt){
  $prompt = '\((?:config[^\)]*)?\)\s*#\s*$|#\s*$';
  $cmds = ['configure terminal','no page','terminal length 0','screen-length 0 temporary','show mac address-table','exit'];
  $res = telnet_run_commands($olt['host'], (int)$olt['ssh_port'], $olt['username'], $olt['password'], $cmds, $prompt, true, $olt['enable_password'], false, 12);
  if (!$res['ok']) return ['ok'=>false,'error'=>$res['error']];

  $text = $res['output'] ?: '';
  $rows = [];
  $macRe = '/([0-9a-f]{2}(?::[0-9a-f]{2}){5})/i';
  // Try to catch EPON0/3:1 or GPON0/2:32 etc.
  if (preg_match_all('/([0-9A-Za-z:]{17}).{0,80}((?:EPON|GPON)\s*0\/\d\s*[:\/]\s*\d{1,3})/mi', $text, $m, PREG_SET_ORDER)) {
    foreach($m as $hit){
      $mac = norm_mac($hit[1]);
      if (!$mac) continue;
      $tag = strtoupper(preg_replace('/\s+/', '', $hit[2])); // EPON0/3:1
      $fam = (strpos($tag,'GPON')===0) ? 'gpon':'epon';
      if (!preg_match('/0\/(\d)[:\/](\d{1,3})/', $tag, $p)) continue;
      $iface = '0/'.$p[1];
      $onuId = (int)$p[2];
      $rows[] = ['mac'=>$mac,'family'=>$fam,'iface'=>$iface,'onu_id'=>$onuId];
    }
  }
  return ['ok'=>true,'list'=>$rows];
}

function ensure_onu_inventory($olt_id, $family, $iface, $onu_id){
  $db = db();
  $st = $db->prepare("SELECT id FROM onu_inventory WHERE olt_id=? AND family=? AND iface=? AND onu_id=? LIMIT 1");
  $st->execute([$olt_id, $family, $iface, $onu_id]);
  $id = $st->fetchColumn();
  if ($id) return (int)$id;
  $ins = $db->prepare("INSERT INTO onu_inventory (olt_id,family,iface,onu_id,is_active) VALUES (?,?,?,?,1)");
  $ins->execute([$olt_id,$family,$iface,$onu_id]);
  return (int)$db->lastInsertId();
}

function upsert_mac_map($target_id, $mac){
  $db = db();
  $ins = $db->prepare("INSERT INTO onu_mac_map (target_id, mac, vendor, last_seen)
                       VALUES (?,?,NULL,NOW())
                       ON DUPLICATE KEY UPDATE last_seen=VALUES(last_seen)");
  $ins->execute([$target_id, $mac]);
}

function link_client_to_target($client_id, $target_id){
  $db = db();
  $upd = $db->prepare("UPDATE onu_inventory SET client_id=? WHERE id=?");
  $upd->execute([$client_id, $target_id]);
}

function process_one_client($client, $olt){
  list($caller, $why) = get_caller_mac_for_client($client);
  $mac = $caller ? norm_mac($caller) : null;

  $result = ['client_id'=>$client['id'],'pppoe_id'=>$client['pppoe_id'],'caller_raw'=>$caller,'mac'=>$mac,'status'=>'not_found','message'=>$why];

  // quick DB lookup first
  if ($mac) {
    $st = db()->prepare("
      SELECT t.id AS target_id, t.family, t.iface, t.onu_id
      FROM onu_mac_map k
      JOIN onu_inventory t ON t.id = k.target_id
      WHERE k.mac = ?
      ORDER BY k.last_seen DESC
      LIMIT 1
    ");
    $st->execute([$mac]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      link_client_to_target($client['id'], (int)$row['target_id']);
      upsert_mac_map((int)$row['target_id'], $mac);
      $result['status']='linked';
      $result['message']='matched_from_cache';
      $result['target']=$row;
      return $result;
    }
  }

  // Not found → scan OLT MAC table and rebuild cache
  $scan = scan_olt_mac_table($olt);
  if (!$scan['ok']) {
    $result['status']='error';
    $result['message']='olt_scan_fail: '.$scan['error'];
    return $result;
  }

  // save all learned MACs
  $db = db();
  // Ensure OLT row exists
  $olt_id = $olt['id'];
  if (!$olt_id) {
    $i = $db->prepare("INSERT INTO olts (name,host,mgmt_proto,ssh_port,username,password,enable_password) VALUES (?,?,?,?,?,?,?)");
    $i->execute([$olt['host'],$olt['host'],'telnet',(int)$olt['ssh_port'],$olt['username'],$olt['password'],$olt['enable_password']]);
    $olt_id = (int)$db->lastInsertId();
  }

  foreach ($scan['list'] as $r) {
    $tid = ensure_onu_inventory($olt_id, $r['family'], $r['iface'], (int)$r['onu_id']);
    upsert_mac_map($tid, $r['mac']);
  }

  // retry match if we have a mac
  if ($mac) {
    $st = db()->prepare("
      SELECT t.id AS target_id, t.family, t.iface, t.onu_id
      FROM onu_mac_map k
      JOIN onu_inventory t ON t.id = k.target_id
      WHERE k.mac = ?
      ORDER BY k.last_seen DESC
      LIMIT 1
    ");
    $st->execute([$mac]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      link_client_to_target($client['id'], (int)$row['target_id']);
      $result['status']='linked';
      $result['message']='matched_after_olt_scan';
      $result['target']=$row;
      return $result;
    }
  }

  $result['status']='not_found';
  $result['message']='mac_not_in_olt_table';
  return $result;
}

// ---------- main ----------
$olt = load_olt_row($oltHost);

$out = ['status'=>'success','mode' => $doAll?'all':'single', 'host'=>$olt['host'], 'results'=>[]];

if ($clientId) {
  $st = db()->prepare("
    SELECT c.*, r.ip AS router_ip, r.username, r.password, r.api_port
    FROM clients c
    LEFT JOIN routers r ON r.id = c.router_id
    WHERE c.id=?
    LIMIT 1
  ");
  $st->execute([$clientId]);
  $client = $st->fetch(PDO::FETCH_ASSOC);
  if (!$client) { echo json_encode(['status'=>'error','message'=>'Client not found']); exit; }

  $out['results'][] = process_one_client($client, $olt);
} else {
  // all active PPPoE users that have router mapped
  $rows = db()->query("
    SELECT c.*, r.ip AS router_ip, r.username, r.password, r.api_port
    FROM clients c
    JOIN routers r ON r.id = c.router_id
    WHERE c.is_deleted=0 AND c.status IN ('active','pending')
  ")->fetchAll(PDO::FETCH_ASSOC);

  foreach ($rows as $row) {
    $out['results'][] = process_one_client($row, $olt);
  }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
