<?php
// /app/acl.php
// Robust ACL system: admin bypass, viewer read-only, wildcard perms, HR helpers.
// বাংলা কমেন্ট: কোড ইংরেজি, শুধু ব্যাখ্যা বাংলায়।

declare(strict_types=1);

require_once __DIR__ . '/db.php';

require_perm('reseller.view');     // লিস্ট/ভিউ দেখতে
require_perm('reseller.manage');   // add/edit/delete reseller
require_perm('reseller.pricing');  // প্যাকেজ-প্রাইসিং সেট/এডিট


if (session_status() === PHP_SESSION_NONE) { session_start(); }

/* ===================== Config ===================== */
// (বাংলা) একাধিক admin ইউজারনেম সাপোর্ট (username-based bypass)
const ACL_ADMIN_USERNAMES = ['admin']; // add more if needed
define('ACL_ALLOW_USERNAME_ADMIN_BYPASS', true); // backward-compat flag

// (বাংলা) viewer রিড-অনলি: কোন কোন prefix/view action আলাউ হবে
const ACL_VIEW_PREFIXES = ['view','read','list','show','get','index','report','detail','download','export'];

// (বাংলা) viewer ব্লকড write কী-ওয়ার্ড (permission key এর ভিতরে থাকলে write ধরা হবে)
const ACL_WRITE_KEYWORDS = [
  'create','store','add','update','edit','write','patch','post','put','delete','remove','destroy',
  'toggle','reset','approve','assign','change','manage','import','upload','password','role','status',
  'activate','deactivate','ban','void'
];

// (বাংলা) PHP<8 হলে starts_with পলিফিল
if (!function_exists('str_starts_with')) {
  function str_starts_with(string $haystack, string $needle): bool {
    return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
  }
}

/* ===================== User basics ===================== */
function acl_username(): ?string {
  // (বাংলা) সেশন থেকে সম্ভাব্য ইউজারনেম কী-গুলো খুঁজে বের করি
  foreach ([
    $_SESSION['user']['username'] ?? null,
    $_SESSION['username'] ?? null,
    $_SESSION['SESS_USERNAME'] ?? null,
    $_SESSION['user']['user_name'] ?? null,
  ] as $u) {
    $u = trim((string)$u);
    if ($u !== '') return $u;
  }
  return null;
}

function acl_current_user_id(): int {
  // (বাংলা) কস্টলি কুয়েরি এড়াতে লোকাল স্ট্যাটিক ক্যাশ
  static $cache = null;
  if ($cache !== null) return $cache;

  foreach ([
    $_SESSION['user']['id'] ?? null,
    $_SESSION['user_id'] ?? null,
    $_SESSION['SESS_USER_ID'] ?? null,
    $_SESSION['id'] ?? null,
  ] as $v) {
    $id = (int)$v;
    if ($id > 0) { $cache = $id; return $cache; }
  }

  // (বাংলা) ফেলে দিলে ইউজার টেবিল থেকে username দিয়ে id বের করি (optional)
  $uname = acl_username();
  if ($uname) {
    $st = db()->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
    $st->execute([$uname]);
    $cache = (int)$st->fetchColumn();
    return $cache;
  }
  return 0;
}

// (বাংলা) সেশন ক্যাশ ক্লিয়ার
function acl_reset_cache(): void {
  unset($_SESSION['acl_role_name'], $_SESSION['acl_perms'], $_SESSION['acl_cache_user_id']);
}
// (বাংলা) বাইরে থেকেও সহজে বুস্ট করার হেল্পার
function acl_bust_cache(): void { acl_reset_cache(); }

// (বাংলা) আলাদা ইউজারে সুইচ করলে ক্যাশ রিসেট
function acl_sync_cache_user(int $uid): void {
  if (!isset($_SESSION['acl_cache_user_id']) || (int)$_SESSION['acl_cache_user_id'] !== $uid) {
    $_SESSION['acl_cache_user_id'] = $uid;
    unset($_SESSION['acl_role_name'], $_SESSION['acl_perms']);
  }
}

/* ===================== Role helpers ===================== */
function acl_role_name(?int $user_id=null): string {
  $user_id = $user_id ?? acl_current_user_id();
  if ($user_id <= 0) return 'guest';

  acl_sync_cache_user($user_id);
  if (!empty($_SESSION['acl_role_name'])) return strtolower((string)$_SESSION['acl_role_name']);

  // (বাংলা) users.role_id → roles.name, না থাকলে users.role টেক্সট
  $sql = "SELECT LOWER(COALESCE(r.name, u.role)) AS role_name
          FROM users u LEFT JOIN roles r ON u.role_id = r.id
          WHERE u.id=? LIMIT 1";
  $st = db()->prepare($sql);
  $st->execute([$user_id]);
  $role = (string)$st->fetchColumn();
  $role = $role !== '' ? $role : 'guest';
  $_SESSION['acl_role_name'] = $role;
  return $role;
}

function acl_is_username_admin(): bool {
  $u = acl_username();
  if (!$u) return false;
  if (ACL_ALLOW_USERNAME_ADMIN_BYPASS && strtolower($u)==='admin') return true;
  return in_array(strtolower($u), array_map('strtolower', ACL_ADMIN_USERNAMES), true);
}
function acl_is_admin_role(?int $uid=null): bool { return acl_role_name($uid)==='admin'; }
function acl_is_viewer(?int $uid=null): bool { return acl_role_name($uid)==='viewer'; }

/* ===================== Employee helpers (optional merge) ===================== */
// (বাংলা) সেশন থেকে EMP_ID (থাকলে employee-based roles merge করা হবে)
function acl_emp_id(): ?string {
  foreach (['EMP_ID','emp_id','employee_id'] as $k) {
    $v = trim((string)($_SESSION[$k] ?? ''));
    if ($v !== '') return $v;
  }
  return null;
}

/* ===================== Permission loading ===================== */
function acl_load_user_permissions(int $uid): array {
  // (বাংলা) নতুন স্কিমা perm_key; পুরোনো হলে code — দুটোই সাপোর্ট
  $pdo = db();
  $perms = [];

  // 1) users.role_id → role_permissions → permissions
  $sql1 = "SELECT LOWER(COALESCE(p.perm_key)) AS k
           FROM users u
           LEFT JOIN roles r ON u.role_id = r.id
           LEFT JOIN role_permissions rp ON rp.role_id = r.id
           LEFT JOIN permissions p ON p.id = rp.permission_id
           WHERE u.id=?";
  $st1 = $pdo->prepare($sql1); 
  $st1->execute([$uid]);
  foreach ($st1->fetchAll(PDO::FETCH_COLUMN) as $k) {
    if ($k) $perms[(string)$k] = true;
  }

  // 2) employees_roles (EMP_ID ভিত্তিক) → merge
  if ($emp = acl_emp_id()) {
    $sql2 = "SELECT LOWER(COALESCE(p.perm_key, p.code)) AS k
             FROM employees_roles er
             JOIN role_permissions rp ON rp.role_id = er.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE er.emp_id = ?";
    $st2 = $pdo->prepare($sql2);
    $st2->execute([$emp]);
    foreach ($st2->fetchAll(PDO::FETCH_COLUMN) as $k) {
      if ($k) $perms[(string)$k] = true;
    }
  }

  return $perms; // (বাংলা) অ্যাসোসিয়েটিভ সেট (k => true)
}

function acl_perms(): array {
  $uid = acl_current_user_id();
  if ($uid<=0) return [];
  acl_sync_cache_user($uid);
  if (empty($_SESSION['acl_perms']) || !is_array($_SESSION['acl_perms'])) {
    $_SESSION['acl_perms'] = acl_load_user_permissions($uid);
  }
  return $_SESSION['acl_perms'];
}

/* ===================== Viewer & keyword detection ===================== */
function acl_perm_is_write(string $k): bool {
  $k = strtolower($k);
  foreach (ACL_WRITE_KEYWORDS as $kw) if (strpos($k,$kw)!==false) return true;
  return false;
}
function acl_perm_is_view(string $k): bool {
  $k = strtolower($k);
  foreach (ACL_VIEW_PREFIXES as $pref) {
    if ($k===$pref || str_starts_with($k,$pref.'.') || str_starts_with($k,$pref.':')) return true;
  }
  return false;
}

/* ===================== Wildcard matching ===================== */
// (বাংলা) hr.* / report:* টাইপ ওয়াইল্ডকার্ড সাপোর্ট
function acl_perm_match(array $permSet, string $key): bool {
  $key = strtolower($key);
  if (isset($permSet['*'])) return true;     // global *
  if (isset($permSet[$key])) return true;

  if (strpos($key,'.')!==false) {
    $parts = explode('.',$key);
    while(count($parts)>1){
      array_pop($parts);
      if (isset($permSet[implode('.',$parts).'.*'])) return true;
    }
  }
  if (strpos($key,':')!==false) {
    $parts = explode(':',$key);
    while(count($parts)>1){
      array_pop($parts);
      if (isset($permSet[implode(':',$parts).':*'])) return true;
    }
  }
  return false;
}

/* ===================== Core check ===================== */
function acl_can(string $perm_key): bool {
  $uid = acl_current_user_id();
  if ($uid<=0) return false;

  // (বাংলা) অ্যাডমিন (role/admin username) সব পারে
  if (acl_is_admin_role($uid) || acl_is_username_admin()) return true;

  $role  = acl_role_name($uid);
  $perms = acl_perms();

  // (বাংলা) এক্সপ্লিসিট পারমিশন/ওয়াইল্ডকার্ড
  if (!empty($perms) && acl_perm_match($perms,$perm_key)) return true;

  // (বাংলা) viewer হলে read-only অ্যালাউ
  if ($role==='viewer') {
    if (acl_perm_is_write($perm_key)) return false;
    return true;
  }

  // (বাংলা) অন্য কোনো রোলের ক্ষেত্রে ডিফল্ট ব্লক
  return false;
}

function acl_can_any(array $keys): bool { foreach($keys as $k) if (acl_can((string)$k)) return true; return false; }
function acl_can_all(array $keys): bool { foreach($keys as $k) if (!acl_can((string)$k)) return false; return true; }

/* ===================== Guards ===================== */
function acl_forbid_403(string $msg='You do not have permission.'): void {
  http_response_code(403);
  $msg = htmlspecialchars($msg,ENT_QUOTES,'UTF-8');
  echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>403</title>';
  echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">';
  echo '</head><body class="bg-light"><div class="container py-5">';
  echo '<div class="alert alert-danger">'.$msg.'</div>';
  echo '<a href="/" class="btn btn-secondary">Back</a>';
  echo '</div></body></html>'; exit;
}
function require_perm(string $k): void { if(!acl_can($k)) acl_forbid_403('Permission denied: '.$k); }
function require_permission(string $k): void { require_perm($k); }
function require_any(array $keys): void { if(!acl_can_any($keys)) acl_forbid_403('Need any of: '.implode(', ',$keys)); }
function require_all(array $keys): void { if(!acl_can_all($keys)) acl_forbid_403('Need all of: '.implode(', ',$keys)); }

function show_if_can(string $k): bool { return acl_can($k); }
function acl_show(string $k): bool { return acl_can($k); }

/* ===================== System-wide viewer readonly ===================== */
function acl_enforce_readonly_on_post(): void {
  if (acl_is_viewer()) {
    $m = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (in_array($m,['POST','PUT','PATCH','DELETE'],true)) {
      acl_forbid_403('Read-only account: write disabled.');
    }
  }
}

// (বাংলা) হেডারে/গ্লোবাল বুটে এটা কল করো
function acl_boot(): void {
  $uid = acl_current_user_id();
  if ($uid>0) acl_sync_cache_user($uid);
  // optional: URL থেকে cache bust
  if (isset($_GET['acl_bust']) && (string)$_GET['acl_bust'] === '1') { acl_bust_cache(); }
  acl_enforce_readonly_on_post();
}

/* ===================== HR helpers ===================== */
function hr_can(string $action): bool { return acl_can('hr.'.strtolower($action)); }
function require_hr(string $action): void { require_perm('hr.'.strtolower($action)); }

/* ===================== Debug ===================== */
function acl_debug_dump(): void {
  if (!isset($_GET['__acl_debug'])) return;
  header('Content-Type:text/plain; charset=utf-8');
  echo "User: ".(acl_username()??'-').PHP_EOL;
  echo "UserID: ".acl_current_user_id().PHP_EOL;
  echo "Role: ".acl_role_name().PHP_EOL;
  echo "IsAdminUsername: ".(acl_is_username_admin()?'yes':'no').PHP_EOL;
  echo "Perms: ".json_encode(array_keys(acl_perms()),JSON_PRETTY_PRINT).PHP_EOL;
  if ($emp = acl_emp_id()) echo "EMP_ID: ".$emp.PHP_EOL;
  exit;
}
