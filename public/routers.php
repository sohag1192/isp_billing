<?php
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

$message = '';

// রাউটার ডিলিট
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $stmt = db()->prepare("DELETE FROM routers WHERE id = ?");
    if ($stmt->execute([$id])) {
        $message = '<div class="alert alert-success">✅ Router deleted successfully!</div>';
    } else {
        $message = '<div class="alert alert-danger">❌ Failed to delete router!</div>';
    }
}

// সব রাউটার লোড
$stmt = db()->query("SELECT * FROM routers ORDER BY id DESC");
$routers = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<title>Routers List</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css">
<link rel="stylesheet" href="../assets/css/style.css">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
<?php include __DIR__ . '/../partials/partials_header.php'; ?>

<div class="main-content p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3><i class="bi bi-hdd-network"></i> Routers List</h3>
        <a href="router_add.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Add Router</a>
    </div>

    <?php echo $message; ?>

    <div class="table-responsive">
        <table class="table table-bordered table-hover table-sm align-middle">
            <thead class="table-dark">
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Type</th>
                    <th>IP Address</th>
                    <th>Server Status</th>
                    <th>Ping</th>
                    <th>Online Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (count($routers) > 0): ?>
                <?php foreach ($routers as $r): ?>
                <tr>
                    <td><?php echo $r['id']; ?></td>
                    <td><?php echo htmlspecialchars($r['name']); ?></td>
                    <td><?php echo htmlspecialchars($r['type']); ?></td>
                    <td><?php echo $r['ip']; ?></td>
                    <td>
                        <?php if ($r['status'] == 1): ?>
                            <span class="badge bg-success">Active</span>
                        <?php else: ?>
                            <span class="badge bg-danger">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-info ping-btn" data-ip="<?php echo $r['ip']; ?>" data-id="<?php echo $r['id']; ?>">
                            <i class="bi bi-wifi"></i> Ping
                        </button>
                        <span class="ping-result" id="ping-result-<?php echo $r['id']; ?>"></span>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-primary online-btn" data-id="<?php echo $r['id']; ?>">
                            <i class="bi bi-people-fill"></i> Online
                        </button>
                        <span class="online-result" id="online-result-<?php echo $r['id']; ?>"></span>
                    </td>
                    <td>
                        <a href="router_edit.php?id=<?php echo $r['id']; ?>" class="btn btn-sm btn-warning"><i class="bi bi-pencil"></i></a>
                        <a href="?delete=<?php echo $r['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure?');"><i class="bi bi-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="8" class="text-center">No routers found</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../partials/partials_footer.php'; ?>

<script>
function doPing(id, ip) {
    $.get("router_ping.php", { ip: ip }, function(data){
        $("#ping-result-" + id).html(data);
    }).fail(function(){
        $("#ping-result-" + id).html('<span class="text-danger">Error</span>');
    });
}

function getOnlineUsers(id) {
    $.get("router_online.php", { id: id }, function(data){
        $("#online-result-" + id).html(data);
    }).fail(function(){
        $("#online-result-" + id).html('<span class="text-danger">Error</span>');
    });
}

$(document).ready(function(){
    $(".ping-btn").click(function(){
        var ip = $(this).data("ip");
        var id = $(this).data("id");
        $("#ping-result-" + id).html('<span class="text-warning">Pinging...</span>');
        doPing(id, ip);
    });

    $(".online-btn").click(function(){
        var id = $(this).data("id");
        $("#online-result-" + id).html('<span class="text-warning">Loading...</span>');
        getOnlineUsers(id);
    });

    // Auto refresh every 30s
    setInterval(function(){
        $(".ping-btn").each(function(){
            doPing($(this).data("id"), $(this).data("ip"));
        });
        $(".online-btn").each(function(){
            getOnlineUsers($(this).data("id"));
        });
    }, 3000);
});
</script>
</body>
</html>
