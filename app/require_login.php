<?php
// /app/require_login.php
// Purpose: Common login guard + session policy + ACL bootstrap
// বাংলা কমেন্ট: UI/টেক্সট ইংরেজি রাখুন; কেবল কমেন্ট বাংলায়।

declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/acl.php'; // updated ACL

/* ------------------------------
   Safety defaults / constants
   ------------------------------ */
// বাংলা: SESSION_LIFETIME না থাকলে ডিফল্ট 6 ঘন্টা
if (!defined('SESSION_LIFETIME')) {
    define('SESSION_LIFETIME', 60 * 60 * 6);
}

/* ------------------------------
   Redirect helper
   ------------------------------ */
function _rl_redirect_login(string $reason = ''): void {
    $loc = '../public/login.php';
    if ($reason !== '') {
        $loc .= '?error=' . rawurlencode($reason);
    }
    header("Location: {$loc}");
    exit;
}

/* ------------------------------
   Auth: not logged in → redirect
   ------------------------------ */
// বাংলা: সিস্টেমে আমরা user_id ধরে রাখি; ACL-এ user['id'] ইত্যাদিও সাপোর্টেড।
if (empty($_SESSION['user_id'])) {
    _rl_redirect_login('Please login');
}

/* ------------------------------
   Session expiry check (idle)
   ------------------------------ */
$now = time();
$last = (int)($_SESSION['last_activity'] ?? 0);
if ($last > 0 && ($now - $last) > (int)SESSION_LIFETIME) {
    session_unset();
    session_destroy();
    _rl_redirect_login('Session expired');
}
$_SESSION['last_activity'] = $now;

/* ------------------------------
   Normalize role into session
   ------------------------------ */
// বাংলা: users.role (বা roles টেবিল) থেকে রোল নাম sync, default 'viewer'.
if (empty($_SESSION['role'])) {
    $st = db()->prepare("SELECT COALESCE(LOWER(role), 'viewer') FROM users WHERE id = ? LIMIT 1");
    $st->execute([ (int)$_SESSION['user_id'] ]);
    $role = strtolower(trim((string)$st->fetchColumn()));
    $_SESSION['role'] = $role !== '' ? $role : 'viewer';
}
// বাংলা: ACL helper গুলো session['user']['role'] পড়ে — সেটাও সিঙ্ক করি
$_SESSION['user']['role']  = $_SESSION['role'];
$_SESSION['acl_role_name'] = $_SESSION['role'];

/* ------------------------------
   Ensure user struct consistency
   ------------------------------ */
// বাংলা: ACL current_user_id() একাধিক কী চেক করে; তারপরও safest সেট করলাম
$_SESSION['user']['id']       = (int)($_SESSION['user']['id'] ?? $_SESSION['user_id']);
$_SESSION['user']['username'] = $_SESSION['user']['username'] ?? ($_SESSION['username'] ?? '');

/* ------------------------------
   ACL bootstrap (cache sync + viewer readonly)
   ------------------------------ */
// বাংলা: viewer হলে POST/PUT/PATCH/DELETE ব্লক + সেশন ইউজার কেশ সিঙ্ক
if (function_exists('acl_boot')) {
    acl_boot();
} else {
    // fallback: অন্তত readonly enforce করি
    acl_enforce_readonly_on_post();
}

/* ------------------------------
   Admin-only helper (ACL-based)
   ------------------------------ */
// বাংলা: যেখানে একদম অ্যাডমিন ছাড়া কেউ ঢুকবে না, সেখানে কল করুন
function require_admin(): void {
    if (!acl_can_write_everything()) {
        acl_forbid_403('Admin privilege required.');
    }
}

/* ------------------------------
   Permission helper (compat layer)
   ------------------------------ */
/**
 * has_permission($code):
 * - প্রথমে ACL (roles/permissions + wildcard + viewer rules)
 * - তারপর (ঐচ্ছিক) user_permissions (per-user grants) — permission_code (exact or namespace wildcard)
 * বাংলা: নতুন পেজে সবসময় require_permission() ব্যবহার করুন; এটি কেবল ব্যাকওয়ার্ড-কম্প্যাট।
 */
function has_permission(string $code): bool {
    // 1) ACL সিদ্ধান্ত
    if (acl_can($code)) return true;

    // 2) Per-user fallback permissions (optional table)
    static $userPermCache = null;
    if ($userPermCache === null) {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $userPermCache = [];

        if ($uid > 0) {
            try {
                $q = db()->prepare("SELECT permission_code FROM user_permissions WHERE user_id = ?");
                $q->execute([$uid]);
                $rows = $q->fetchAll(PDO::FETCH_COLUMN) ?: [];
                foreach ($rows as $p) {
                    $p = strtolower(trim((string)$p));
                    if ($p !== '') { $userPermCache[$p] = true; }
                }
            } catch (Throwable $e) {
                // বাংলা: টেবিল না থাকলে/এরর হলে সাইলেন্ট—শুধু ACL-ই কাজ করবে
            }
        }

        // বাংলা: সেশন ACL পারমিশন (যদি কী হিশেবে সংরক্ষিত) — মার্জ
        if (!empty($_SESSION['acl_perms']) && is_array($_SESSION['acl_perms'])) {
            foreach ($_SESSION['acl_perms'] as $k => $v) {
                if ($v) { $userPermCache[strtolower((string)$k)] = true; }
            }
        }
    }

    // বাংলা: exact or wildcard match (foo.bar.baz → foo.bar.* → foo.*)
    $key = strtolower($code);
    if (isset($userPermCache[$key])) return true;

    // '.' namespace wildcard
    if (strpos($key, '.') !== false) {
        $parts = explode('.', $key);
        while (count($parts) > 1) {
            array_pop($parts);
            $cand = implode('.', $parts) . '.*';
            if (isset($userPermCache[$cand])) return true;
        }
    }
    // ':' namespace wildcard
    if (strpos($key, ':') !== false) {
        $parts = explode(':', $key);
        while (count($parts) > 1) {
            array_pop($parts);
            $cand = implode(':', $parts) . ':*';
            if (isset($userPermCache[$cand])) return true;
        }
    }

    // বাংলা: বিশেষ কেস — view_all থাকলে যেকোনো view.* allow
    if (isset($userPermCache['view_all'])) {
        if ($key === 'view' || str_starts_with($key, 'view.') || str_starts_with($key, 'view:')) {
            return true;
        }
    }

    return false;
}

/* ------------------------------
   (Deprecated) Global GET-guard
   ------------------------------ */
// বাংলা: আগে ছিল — if (!acl_can_view_everything()) {403} — রিডানড্যান্ট/রিস্কি বলে বাদ।
