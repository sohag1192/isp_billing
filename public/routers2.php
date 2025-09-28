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
<?php include __DIR__ . '/../app/header.php'; ?>

<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<title>Routers List</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css">
<link rel="stylesheet" href="../assets/css/style.css">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<style>
.router-card {
    border-radius: 10px;
    box-shadow: 0 0 10px rgba(0,0,0,0.08);
    transition: 0.3s;
}
.router-card:hover {
    transform: scale(1.02);
}
.router-header {
    font-size: 1.1rem;
    font-weight: bold;
}
.badge-status {
    font-size: 0.8rem;
    padding: 5px 8px;
}
</style>
</head>
<body>
<?php include __DIR__ . '/../partials/partials_header.php'; ?>

<div class="main-content p-4">


    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3><i class="bi bi-hdd-network"></i> Routers</h3>
        
<?php if (hasPermission('add.router')){ ?>       
        
        <a href="router_add.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Add Router</a>
        
<?php } ?>      
        
    </div>

    <?php echo $message; ?>

    <div class="row g-3">
        <?php if (count($routers) > 0): ?>
            <?php foreach ($routers as $r): ?>
                <div class="col-lg-3 col-md-4 col-sm-6 col-12">
                    <div class="card router-card p-3">
                        <div class="router-header d-flex justify-content-between align-items-center">
                            <?php echo htmlspecialchars($r['name']); ?>
                            <?php if ($r['status'] == 1): ?>
                                <span class="badge bg-success badge-status">Active</span>
                            <?php else: ?>
                                <span class="badge bg-danger badge-status">Inactive</span>
                            <?php endif; ?>
                        </div>
                        <small class="text-muted"><?php echo htmlspecialchars($r['type']); ?></small>
                        <div class="mt-2">
                            <i class="bi bi-geo-alt"></i> <?php echo $r['ip']; ?><br>
                            <i class="bi bi-person"></i> <?php echo $r['username']; ?>
                        </div>
                        <hr>
                        <div class="d-flex justify-content-between">
                            <button class="btn btn-sm btn-info ping-btn" data-ip="<?php echo $r['ip']; ?>" data-id="<?php echo $r['id']; ?>">
                                <i class="bi bi-wifi"></i> Ping </button>
                            <span class="ping-result" id="ping-result-<?php echo $r['id']; ?>"></span></div>
                        <div class="d-flex justify-content-between mt-2">
                            <button class="btn btn-sm btn-primary online-btn" data-id="<?php echo $r['id']; ?>">
                                <i class="bi bi-people-fill"></i> Online </button>
                            <span class="online-result" id="online-result-<?php echo $r['id']; ?>"></span>
                        </div>
                        <div class="mt-3 text-center">
                        
<?php if (hasPermission('router.edit')){ ?>
                            <a href="router_edit.php?id=<?php echo $r['id']; ?>" class="btn btn-warning btn-sm"><i class="bi bi-pencil"></i> Edit</a>
<?php } ?> 

<?php if (hasPermission('router.delete')){ ?>                            
                            <a href="?delete=<?php echo $r['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure?');"><i class="bi bi-trash"></i> Delete</a>
                            
<?php } ?>
                             
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="col-12">
                <div class="alert alert-info text-center">No routers found</div>
            </div>
        <?php endif; ?>
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
    }, 30000);
});
</script>
<?php include __DIR__ . '/../app/footer.php'; ?>
</body>
</html>
