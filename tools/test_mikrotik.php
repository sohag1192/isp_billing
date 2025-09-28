<?php
require_once __DIR__ . '/../app/routeros_api.class.php';
$ip = '172.16.171.22';
$user = 'billing';
$pass = 'billing';

$API = new RouterosAPI();
$API->debug = true; // সমস্যা হলে লগ দেখবে

if (!$API->connect($ip, $user, $pass)) { die("Connect failed\n"); }
print_r($API->comm("/ppp/secret/print", ["=.proplist=name,profile,disabled"]));
$API->disconnect();
