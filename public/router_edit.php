<?php
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

$message = '';

// রাউটার আইডি চেক
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: routers.php");
    exit;
}

$id = intval($_GET['id']);

// বর্তমান রাউটার ডেটা লোড
$stmt = db()->prepare("SELECT * FROM routers WHERE id = ?");
$stmt->execute([$id]);
$router = $stmt->fetch();

if (!$router) {
    header("Location: routers.php");
    exit;
}

// আপডেট প্রসেস
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name           = trim($_POST['name']);
    $type           = trim($_POST['type']);
    $ip_address     = trim($_POST['ip_address']);
    $username       = trim($_POST['username']);
    $password       = trim($_POST['password']);
    $api_port       = intval($_POST['api_port']);
    $snmp_community = trim($_POST['snmp_community']);
    $status         = isset($_POST['status']) ? 1 : 0;

    if ($name && $type && $ip_address && $username && $password) {
        $stmt = db()->prepare("UPDATE routers SET 
            name = ?, type = ?, ip_address = ?, username = ?, password = ?, 
            api_port = ?, snmp_community = ?, status = ? WHERE id = ?");
        $stmt->execute([$name, $type, $ip_address, $username, $password, $api_port, $snmp_community, $status, $id]);

        $message = '<div class="alert alert-success">✅ Router updated successfully!</div>';
        // আপডেটের পর নতুন ডেটা লোড
        $stmt = db()->prepare("SELECT * FROM routers WHERE id = ?");
        $stmt->execute([$id]);
        $router = $stmt->fetch();
    } else {
        $message = '<div class="alert alert-danger">❌ Please fill all required fields!</div>';
    }
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<title>Edit Router</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css">
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/../partials/partials_header.php'; ?>

<?php if (hasPermission('edit_router')){ ?>   
        <div class="main-content p-4">
        <h3 class="mb-4"><i class="bi bi-pencil-square"></i> Edit Router</h3>
        <?php echo $message; ?>

    <form method="POST" class="p-3 border rounded bg-light">
        <div class="mb-3">
            <label>Router Name *</label>
            <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($router['name']); ?>" required>
        </div>
        <div class="mb-3">
            <label>Router Type *</label>
            <select name="type" class="form-control" required>
                <option value="mikrotik" <?php if($router['type']=='mikrotik') echo 'selected'; ?>>MikroTik</option>
                <option value="olt" <?php if($router['type']=='olt') echo 'selected'; ?>>OLT</option>
                <option value="switch" <?php if($router['type']=='switch') echo 'selected'; ?>>Switch</option>
                <option value="other" <?php if($router['type']=='other') echo 'selected'; ?>>Other</option>
            </select>
        </div>
        <div class="mb-3">
            <label>IP Address *</label>
            <input type="text" name="ip_address" class="form-control" value="<?php echo $router['ip_address']; ?>" required>
        </div>
        <div class="mb-3">
            <label>Username *</label>
            <input type="text" name="username" class="form-control" value="<?php echo $router['username']; ?>" required>
        </div>
        <div class="mb-3">
            <label>Password *</label>
            <input type="text" name="password" class="form-control" value="<?php echo $router['password']; ?>" required>
        </div>
        <div class="mb-3">
            <label>API Port</label>
            <input type="number" name="api_port" class="form-control" value="<?php echo $router['api_port']; ?>">
        </div>
        <div class="mb-3">
            <label>SNMP Community</label>
            <input type="text" name="snmp_community" class="form-control" value="<?php echo $router['snmp_community']; ?>">
        </div>
        <div class="form-check mb-3">
            <input type="checkbox" name="status" class="form-check-input" id="status" <?php if($router['status']==1) echo 'checked'; ?>>
            <label class="form-check-label" for="status">Active</label>
        </div>
        <button type="submit" class="btn btn-success"><i class="bi bi-save"></i> Update Router</button>
        <a href="routers.php" class="btn btn-secondary">Back</a>
    </form>
</div>
<?php } ?>
<?= 'Not permitted Please contact wih your Administrator' ?>


<?php include __DIR__ . '/../partials/partials_footer.php'; ?>
</body>
</html>
