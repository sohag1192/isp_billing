<?php
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

$id = intval($_GET['id'] ?? 0);

if ($id) {
    $stmt = db()->prepare("UPDATE clients SET is_deleted = 0 WHERE id = ?");
    $stmt->execute([$id]);
}

header("Location: deleted_clients.php?msg=Client restored successfully");
exit;
