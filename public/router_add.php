<?php
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name           = trim($_POST['name']);
    $type           = trim($_POST['type']);
    $ip				= trim($_POST['ip']);
    $username       = trim($_POST['username']);
    $password       = trim($_POST['password']);
    $api_port       = intval($_POST['api_port']);
    $snmp_community = trim($_POST['snmp_community']);
    $status         = isset($_POST['status']) ? 1 : 0;

    if ($name && $type && $ip && $username && $password) {
        $stmt = db()->prepare("INSERT INTO routers 
            (name, type, ip, username, password, api_port, snmp_community, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $type, $ip, $username, $password, $api_port, $snmp_community, $status]);

        $message = '<div class="alert alert-success">✅ Router added successfully!</div>';
    } else {
        $message = '<div class="alert alert-danger">❌ Please fill all required fields!</div>';
    }
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">



<title>Add Router</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css">
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/../partials/partials_header.php'; ?>


<?php if (hasPermission('add.router')){ ?>



<div class="main-content p-4">
    <h3 class="mb-4"><i class="bi bi-hdd-network"></i> Add Router</h3>
    <?php echo $message; ?>
    <form method="POST" class="p-3 border rounded bg-light">
        <div class="mb-3">
            <label>Router Name *</label>
            <input type="text" name="name" class="form-control" placeholder="Main MikroTik" required>
        </div>
        <div class="mb-3">
            <label>Router Type *</label>
            <select name="type" class="form-control" required>
                <option value="mikrotik">MikroTik</option>
                <option value="olt">OLT</option>
                <option value="switch">Switch</option>
                <option value="other">Other</option>
            </select>
        </div>
        <div class="mb-3">
            <label>IP Address *</label>
            <input type="text" name="ip" class="form-control" placeholder="192.168.88.1" required>
        </div>
        <div class="mb-3">
            <label>Username *</label>
            <input type="text" name="username" class="form-control" placeholder="admin" required>
        </div>
        <div class="mb-3">
            <label>Password *</label>
            <input type="password" name="password" class="form-control" placeholder="Password" required>
        </div>
        <div class="mb-3">
            <label>API Port</label>
            <input type="number" name="api_port" class="form-control" value="8728">
        </div>
        <div class="mb-3">
            <label>SNMP Community</label>
            <input type="text" name="snmp_community" class="form-control" placeholder="public">
        </div>
        <div class="form-check mb-3">
            <input type="checkbox" name="status" class="form-check-input" id="status" checked>
            <label class="form-check-label" for="status">Active</label>
        </div>
        <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Router</button>
    </form>
</div>




<?php } ?> 


<?php include __DIR__ . '/../partials/alert_denied.php'; ?>
<?php include __DIR__ . '/../partials/partials_footer.php'; ?>
</body>
</html>
