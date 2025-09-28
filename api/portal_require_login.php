<?php
// app/portal_require_login.php
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['portal_user_id'])) {
    header("Location: /public/portal/login.php");
    exit;
}

function portal_user_id() {
    return $_SESSION['portal_user_id'] ?? null;
}

function portal_client_id() {
    return $_SESSION['portal_client_id'] ?? null;
}

function portal_username() {
    return $_SESSION['portal_username'] ?? null;
}
