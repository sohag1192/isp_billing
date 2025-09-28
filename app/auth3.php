<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

// ইনপুট নিন
$username = trim($_POST['username'] ?? '');
$password = trim($_POST['password'] ?? '');

if ($username === '' || $password === '') {
    header("Location: ../public/login.php?error=Username or Password Missing");
    exit;
}

// ইউজার চেক করুন
$stmt = db()->prepare("SELECT * FROM users WHERE username = ? AND status = 1 LIMIT 1");
$stmt->execute([$username]);
$user = $stmt->fetch();

if ($user && password_verify($password, $user['password'])) {
    // সেশন তৈরি
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['last_activity'] = time();

    header("Location: ../public/index.php");
    exit;
} else {
    header("Location: ../public/login.php?error=Invalid Username or Password");
    exit;
}
