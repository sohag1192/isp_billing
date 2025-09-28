<?php
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

$id = intval($_GET['id'] ?? 0);

if ($id) {
    // Delete related billing records
    $stmt = db()->prepare("DELETE FROM billing WHERE client_id = ?");
    $stmt->execute([$id]);

    // Delete related payment records
    $stmt = db()->prepare("DELETE FROM payments WHERE client_id = ?");
    $stmt->execute([$id]);

    // Delete client permanently
    $stmt = db()->prepare("DELETE FROM clients WHERE id = ?");
    $stmt->execute([$id]);
}

header("Location: deleted_clients.php?msg=Client deleted permanently");
exit;
