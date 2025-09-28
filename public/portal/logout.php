<?php
if (session_status() === PHP_SESSION_NONE) session_start();
unset($_SESSION['portal_user_id'], $_SESSION['portal_client_id'], $_SESSION['portal_username']);
session_write_close();
header("Location: /public/portal/login.php");
exit;
