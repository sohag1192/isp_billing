<?php
require_once __DIR__ . '/db.php';
session_start();

$username = trim($_POST['username'] ?? '');
$password = trim($_POST['password'] ?? '');

// ডাটাবেজ থেকে ইউজার তথ্য আনা (MD5 পাসওয়ার্ড মিলানো)
$sql = "SELECT id, username, role, role_id FROM users WHERE username = ? AND password = MD5(?) LIMIT 1";
$stmt = db()->prepare($sql);
$stmt->execute([$username, $password]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['role_id'] = $user['role_id'];
    $_SESSION['last_activity'] = time();

    header("Location: /public/index.php");
    exit;
} else {
    header("Location: /public/login.php?error=Invalid username or password");
    exit;
}



