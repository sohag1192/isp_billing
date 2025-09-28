<?php
require_once __DIR__ . '/../app/db.php';
header('Content-Type: application/json');

$id = intval($_GET['id'] ?? 0);
if($id){
    $stmt = db()->prepare("SELECT price FROM packages WHERE id = ?");
    $stmt->execute([$id]);
    $price = $stmt->fetchColumn();
    if($price){
        echo json_encode(['price' => $price]);
        exit;
    }
}
echo json_encode(['price' => 0]);
