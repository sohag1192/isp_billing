<?php
require_once __DIR__ . '/require_login.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Panel</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            background: #f5f6fa;
        }
        .header-bar {
            background: #f8f9fa;
            padding: 10px 15px;
            border-bottom: 1px solid #ddd;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header-left {
            font-size: 18px;
            font-weight: bold;
        }
        .header-right {
            font-size: 14px;
        }
        .header-right a {
            margin-left: 15px;
            text-decoration: none;
            color: #007bff;
        }
        .header-right a:hover {
            text-decoration: underline;
        }
        .content {
            padding: 15px;
        }
    </style>
</head>
<body>

<div class="header-bar">
    <div class="header-left">
        📡 Admin Panel
    </div>
    <div class="header-right">
        Welcome, <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong>
        (<?php echo htmlspecialchars($_SESSION['role']); ?>)
        <a href="/public/logout.php">Logout</a>
    </div>
</div>

<div class="content">
