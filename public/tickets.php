<?php
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

$stmt = db()->query("SELECT t.*, c.name AS client_name 
                     FROM tickets t
                     LEFT JOIN clients c ON c.id = t.client_id
                     ORDER BY t.created_at DESC");
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../partials/partials_header.php';
?>
<div class="container py-4">
    <h3>All Tickets</h3>
    <table class="table table-bordered">
        <tr>
            <th>ID</th>
            <th>Client</th>
            <th>Subject</th>
            <th>Status</th>
            <th>Created</th>
            <th>Action</th>
        </tr>
        <?php foreach ($tickets as $t): ?>
        <tr>
            <td><?= $t['id'] ?></td>
            <td><?= htmlspecialchars($t['client_name']) ?></td>
            <td><?= htmlspecialchars($t['subject']) ?></td>
            <td><?= ucfirst($t['status']) ?></td>
            <td><?= $t['created_at'] ?></td>
            <td><a href="ticket_view.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-info">View</a></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php include __DIR__ . '/../partials/partials_footer.php'; ?>
