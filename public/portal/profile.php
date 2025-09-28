<?php
require_once __DIR__ . '/../../app/portal_require_login.php';
require_once __DIR__ . '/../../app/db.php';

$stmt = db()->prepare("SELECT * FROM clients WHERE id = ?");
$stmt->execute([portal_client_id()]);
$client = $stmt->fetch();
?>
<div class="d-flex">
    <?php include 'portal_sidebar.php'; ?>
    <div class="p-4" style="flex:1;">
        <h2>My Profile</h2>
        <?php if ($client): ?>
            <ul class="list-group">
                <li class="list-group-item"><strong>Name:</strong> <?= htmlspecialchars($client['name']) ?></li>
                <li class="list-group-item"><strong>PPPoE ID:</strong> <?= htmlspecialchars($client['pppoe_id']) ?></li>
                <li class="list-group-item"><strong>Mobile:</strong> <?= htmlspecialchars($client['mobile']) ?></li>
                <li class="list-group-item"><strong>Email:</strong> <?= htmlspecialchars($client['email']) ?></li>
                <li class="list-group-item"><strong>Address:</strong> <?= htmlspecialchars($client['address']) ?></li>
            </ul>
        <?php else: ?>
            <p class="text-danger">Profile not found.</p>
        <?php endif; ?>
    </div>
</div>
