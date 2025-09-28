<?php
if (!isset($_GET['ip'])) {
    echo '<span class="text-danger">No IP</span>';
    exit;
}

$ip = escapeshellarg($_GET['ip']);
$os = strtoupper(substr(PHP_OS, 0, 3));

if ($os == 'WIN') {
    $ping = exec("ping -n 1 $ip", $output, $status);
} else {
    $ping = exec("ping -c 1 $ip", $output, $status);
}

if ($status === 0) {
    echo '<span class="badge bg-success">Online</span>';
} else {
    echo '<span class="badge bg-danger">Offline</span>';
}
