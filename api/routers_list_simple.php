<?php
// /api/routers_list_simple.php
declare(strict_types=1);
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$rows = $pdo->query("SELECT id, name, ip, api_port FROM routers ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['ok'=>true,'routers'=>$rows], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
