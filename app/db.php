<?php
// ===============================
// ডাটাবেজ কানেকশন ফাইল (PDO)
// ===============================
require_once __DIR__ . '/config.php';

function db() {
    static $pdo;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
        } catch (PDOException $e) {
            die("Database Connection Failed: " . $e->getMessage());
        }
    }
    return $pdo;
}
