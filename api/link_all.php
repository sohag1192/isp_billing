<?php
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/linker.php'; // আগে দেওয়া link_all_clients_ppp_to_onu()

header('Content-Type: application/json; charset=utf-8');
$res = link_all_clients_ppp_to_onu();
echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
