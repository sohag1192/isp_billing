<?php
// app/snmp.php
function snmp_walk_php($host, $community, $oid, $timeout_ms=1500, $retries=1){
    if (!function_exists('snmp2_walk')) return null;
    ini_set('snmp.timeout', (string)max(1, intval($timeout_ms/1000)));
    ini_set('snmp.retry', (string)max(0, $retries));
    $r = @snmp2_walk($host, $community, $oid, $timeout_ms*1000, $retries);
    if ($r === false) return [];
    return $r; // array of "OID = TYPE: value"
}

function snmp_walk_cli($host, $community, $oid){
    $cmd = sprintf('snmpwalk -v2c -c %s -Ov -OQ %s %s 2>&1',
        escapeshellarg($community), escapeshellarg($host), escapeshellarg($oid));
    $out = @shell_exec($cmd);
    if ($out === null) return [];
    $lines = preg_split('/\r?\n/', trim($out));
    return array_filter($lines, fn($l)=>$l!=='');
}

function snmp_walk2($host, $community, $oid, $timeout_ms=1500, $retries=1){
    $r = snmp_walk_php($host, $community, $oid, $timeout_ms, $retries);
    if ($r === null) $r = snmp_walk_cli($host, $community, $oid);
    return is_array($r) ? $r : [];
}
