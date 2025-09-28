<?php
// app/roles.php
// Lightweight role helpers (procedural). Include at top of pages/APIs AFTER require_login.php.
// Usage:
//   require_once __DIR__ . '/../app/roles.php';
//   require_role(['admin']); // or ['billing'], ['support'], ['billing','support']

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function user_role() {
    // Return user's role from session; fallback to 'guest' if missing
    return $_SESSION['user']['role'] ?? 'guest';
}

function has_role($roles) {
    if (!is_array($roles)) $roles = [$roles];
    $role = user_role();
    // 'admin' can do everything
    if ($role === 'admin') return true;
    return in_array($role, $roles, true);
}

function require_role($roles) {
    if (!has_role($roles)) {
        http_response_code(403);
        $page403 = __DIR__ . '/../public/403.php';
        if (file_exists($page403)) {
            include $page403;
        } else {
            echo "<h3 style='font-family:system-ui'>Access denied (403)</h3>";
        }
        exit;
    }
}
?>
