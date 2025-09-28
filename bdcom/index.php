<?php
// bdcom_telnet_monitor.php
// Telnet → enable → show interface brief → header-mapped robust parse
// + show interface description merge (always) + per-port TX/RX + desc fallback
// Bootstrap টেবিল ও কাউন্টারসহ সুন্দর ভিউ

/* ---------------- Basic Config ---------------- */
$host = "10.250.250.7";
$port = 23;
$user = "admin";
$pass = "admin";
$timeout = 8;

/* ---------------- Polyfills (PHP 7 safety) ---------------- */
if (!function_exists('str_starts_with')) {
  function str_starts_with($haystack, $needle) {
    return $needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
  }
}

/* ---------------- Telnet helpers ---------------- */
function telnet_read($fp, $timeout = 7){
  $data=''; $start=time();
  while((time() - $start) < $timeout){
    $chunk = fread($fp, 8192);
    if($chunk !== false && $chunk !== ''){
      $data .= $chunk;
      // pager হলে space পাঠাই
      if(preg_match('/--More--/i', $data)) { fwrite($fp, " "); continue; }
      // prompt দেখা গেলে থামি
      if(preg_match('/[>#]\s*$/m', $data)) break;
    } else {
      usleep(120000);
    }
  }
  return $data;
}
function telnet_write($fp, $cmd){ fwrite($fp, $cmd . "\r\n"); }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------------- Normalizers ---------------- */
function norm_admin(string $s): string {
  $s = strtolower(trim($s));
  if($s==='') return '';
  // non-letters বাদ
  $s = preg_replace('/[^a-z ]+/', '', $s);
  $s = preg_replace('/\s+/', ' ', $s);

  $up   = ['up','enable','enabled','on','yes'];
  $down = ['down','disable','disabled','off','shutdown','administratively down','adm down'];
  if(in_array($s, $up, true))   return 'up';
  if(in_array($s, $down, true)) return 'down';
  if(str_starts_with($s,'administratively')) return 'down';
  return in_array($s, ['up','down'], true) ? $s : $s;
}
function norm_oper(string $s): string {
  $s = strtolower(trim($s));
  $s = preg_replace('/[^a-z ]+/', '', $s);
  $s = preg_replace('/\s+/', ' ', $s);
  return in_array($s, ['up','down'], true) ? $s : $s;
}

/* ---------------- Parsers ---------------- */
// Per-port optics
function parse_txrx_dbm(string $txt): array {
  $tx=null; $rx=null;
  if(preg_match('/Transmit\s+Power.*\(([-\d\.]+)\s*dBm\)/i', $txt, $m)) $tx=(float)$m[1];
  if(preg_match('/(?:Receive|Rx)\s+Power.*\(([-\d\.]+)\s*dBm\)/i', $txt, $n)) $rx=(float)$n[1];
  if($tx===null && preg_match('/\bTx\b.*?:\s*([\-+]?\d+(?:\.\d+)?)\s*dBm/i',$txt,$m2)) $tx=(float)$m2[1];
  if($rx===null && preg_match('/\bRx\b.*?:\s*([\-+]?\d+(?:\.\d+)?)\s*dBm/i',$txt,$n2)) $rx=(float)$n2[1];
  return [$tx,$rx];
}
// Per-port detail থেকে Description
function parse_desc_detail(string $txt): ?string {
  if(preg_match('/^\s*Description\s*:\s*(.+)$/mi', $txt, $m)) return trim($m[1]);
  if(preg_match('/^\s*Port\s+Description\s*:\s*(.+)$/mi', $txt, $m)) return trim($m[1]);
  return null;
}

// "show interface brief" header-mapped robust parser
function parse_interface_brief(string $brief): array {
  $lines = preg_split('/\R/', $brief);
  $rows = [];
  $headerIdx = -1; $cols = [];

  // 1) header খুঁজুন
  foreach($lines as $i=>$ln){
    $t = trim($ln);
    if($t==='') continue;
    if(preg_match('/^\s*(Interface|Port)\b/i', $t)){
      $headerIdx = $i;
      $parts = preg_split('/\s{2,}/', $t);
      foreach($parts as $idx=>$label){
        $lab = strtolower(trim($label));
        if(str_starts_with($lab,'interface') || $lab==='port') $cols['iface']=$idx;
        if(in_array($lab, ['admin','physical','state','status'], true)) $cols['admin']=$idx;
        if(in_array($lab, ['oper','protocol','link'], true))         $cols['oper']=$idx;
        if(str_starts_with($lab,'desc'))                              $cols['desc']=$idx;
      }
      break;
    }
  }

  // 2) ডেটা লাইনগুলো
  for($i = ($headerIdx>=0 ? $headerIdx+1 : 0); $i<count($lines); $i++){
    $raw = rtrim($lines[$i]);
    $ln  = trim($raw);
    if($ln==='') continue;
    if(preg_match('/^\s*-{3,}\s*$/', $ln)) continue;

    $parts = preg_split('/\s{2,}/', $ln);
    if(count($parts) < 2) continue;

    $iface = $parts[$cols['iface']??0] ?? '';
    if($iface==='') continue;

    // VLAN/Null বাদ দিতে চাইলে অন রাখুন; দেখাতে চাইলে পরের লাইনটি কমেন্ট আউট করুন
    if(preg_match('/^(vlan|null)\d*/i', $iface)) continue;

    $adminVal = $parts[$cols['admin']??1] ?? '';
    $operVal  = $parts[$cols['oper'] ??2] ?? '';
    $descVal  = $parts[$cols['desc'] ?? (count($parts)-1)] ?? '';

    $admin = norm_admin($adminVal);
    $oper  = norm_oper($operVal);

    $rows[] = [
      'iface' => preg_replace('/\s+/', '', $iface), // "TGigaEthernet 0/1" → "TGigaEthernet0/1"
      'admin' => $admin,
      'oper'  => $oper,
      'desc'  => trim($descVal),
      'tx'    => 'N/A',
      'rx'    => 'N/A',
    ];
  }

  // 3) fallback pattern (rare)
  if(!$rows){
    foreach($lines as $ln){
      $t = trim($ln);
      if($t==='') continue;
      if(preg_match('/^([A-Za-z]*GigaEthernet\s*0\/\d+)\s+is\s+((administratively\s+down)|up|down)\s*,\s*line\s+protocol\s+is\s+(up|down)(?:\s*-\s*(.*))?$/i',$t,$m)){
        $iface = preg_replace('/\s+/', '', $m[1]);
        $admin = strtolower($m[2]); if($admin==='administratively down') $admin='down';
        $oper  = strtolower($m[4]);
        $desc  = isset($m[5]) ? trim($m[5]) : '';
        $rows[] = ['iface'=>$iface,'admin'=>$admin,'oper'=>$oper,'desc'=>$desc,'tx'=>'N/A','rx'=>'N/A'];
      }
    }
  }
  return $rows;
}

/* ---------------- Connect & Login ---------------- */
$fp = fsockopen($host, $port, $errno, $errstr, $timeout);
if(!$fp) die("Connect failed: $errstr ($errno)");
stream_set_blocking($fp, true);
stream_set_timeout($fp, $timeout);

telnet_read($fp, 3);
telnet_write($fp, $user); telnet_read($fp, 3);
telnet_write($fp, $pass); telnet_read($fp, 3);

telnet_write($fp, "enable");
$buf = telnet_read($fp, 3);
if(stripos($buf, "Password:") !== false){
  telnet_write($fp, $pass);
  telnet_read($fp, 3);
}

/* Pager off (3 variants for safety) */
telnet_write($fp, "terminal length 0");           telnet_read($fp, 2);
telnet_write($fp, "screen-length 0 temporary");   telnet_read($fp, 2);
telnet_write($fp, "no page");                     telnet_read($fp, 2);

/* ---------------- Get interface brief ---------------- */
telnet_write($fp, "show interface brief");
$brief = telnet_read($fp, 8);
$rows  = parse_interface_brief($brief);

/* ---------------- Always merge from 'show interface description' ---------------- */
telnet_write($fp, "show interface description");
$dout = telnet_read($fp, 7);
$dm = [];
foreach(parse_interface_brief($dout) as $dr){
  if(($dr['desc']??'')!=='') $dm[strtolower($dr['iface'])] = $dr['desc'];
}
foreach($rows as &$r){
  $k = strtolower($r['iface']);
  if((!isset($r['desc']) || $r['desc']==='' || strtolower($r['desc'])==='n/a') && isset($dm[$k])){
    $r['desc'] = $dm[$k];
  }
}
unset($r);

/* ---------------- Per-port optics + desc fallback ---------------- */
foreach($rows as &$r){
  telnet_write($fp, "show interface ".$r['iface']);
  $out = telnet_read($fp, 10); // বড় আউটপুটে বাড়তি সময়
  [$tx, $rx] = parse_txrx_dbm($out);
  if($tx!==null) $r['tx'] = number_format($tx, 2).' dBm';
  if($rx!==null) $r['rx'] = number_format($rx, 2).' dBm';

  if(($r['desc']==='' || strtolower($r['desc'])==='n/a')){
    $d = parse_desc_detail($out);
    if($d) $r['desc'] = $d;
  }
}
unset($r);

fclose($fp);

/* ---------------- Totals & UI helpers ---------------- */
$total = count($rows);
$up    = count(array_filter($rows, fn($r)=>$r['oper']==='up'));
$down  = $total - $up;

function badge_state($s,$upClass='badge bg-success',$downClass='badge bg-danger'){
  $s = strtolower($s);
  if($s==='up')   return '<span class="'.$upClass.'">up</span>';
  if($s==='down') return '<span class="'.$downClass.'">down</span>';
  return h($s);
}
function classify_badge($kind,$val){
  if($val==='N/A') return '';
  $v = (float)$val;
  if($kind==='tx'){
    if($v>=-2 && $v<=4)   return '<span class="badge bg-success">Good</span>';
    if($v>=-6 && $v<-2)   return '<span class="badge bg-warning text-dark">Warn</span>';
    return '<span class="badge bg-danger">Bad</span>';
  } else { // rx
    if($v>=-10 && $v<=-2) return '<span class="badge bg-success">Good</span>';
    if($v>=-13 && $v<-10) return '<span class="badge bg-warning text-dark">Warn</span>';
    return '<span class="badge bg-danger">Bad</span>';
  }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>BDCOM Telnet Monitor</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
.badge-up{background:#28a745;}
.badge-down{background:#dc3545;}
.card-metric h2{font-size:40px;margin:0;}
.table td,.table th{vertical-align:middle;}
</style>
</head>
<body class="bg-light">
<div class="container py-4">

  <div class="d-flex justify-content-between mb-3">
    <h2>BDCOM Switch Monitor (Telnet)</h2>
    <a href="?refresh=1" class="btn btn-primary">Refresh</a>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card h-100"><div class="card-body">
      <h6 class="text-muted">Device</h6>
    </div></div></div>
    <div class="col-md-8"><div class="card h-100"><div class="card-body">
      <div class="row text-center">
        <div class="col-4"><div class="card-metric"><div class="text-muted">Total</div><h2><?php echo $total; ?></h2></div></div>
        <div class="col-4"><div class="card-metric"><div class="text-muted">Up</div><h2 class="text-success"><?php echo $up; ?></h2></div></div>
        <div class="col-4"><div class="card-metric"><div class="text-muted">Down</div><h2 class="text-danger"><?php echo $down; ?></h2></div></div>
      </div>
    </div></div></div>
  </div>

  <div class="card"><div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light">
          <tr>
            <th>#</th><th>Interface</th><th>Admin</th><th>Oper</th><th>Description</th>
            <th>TX Power</th><th>RX Power</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($rows as $i=>$r): ?>
          <tr>
            <td class="text-muted"><?php echo $i; ?></td>
            <td><?php echo h($r['iface']); ?></td>
            <td><?php echo badge_state($r['admin']); ?></td>
            <td><?php echo badge_state($r['oper']); ?></td>
            <td class="text-muted"><?php echo h($r['desc']); ?></td>
            <td><?php echo h($r['tx']).' '.classify_badge('tx',$r['tx']); ?></td>
            <td><?php echo h($r['rx']).' '.classify_badge('rx',$r['rx']); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div></div>

</div>
</body>
</html>
