<?php
// /app/menu_access.php
// English UI; Comments: Bengali.
// Purpose: User-wise menu overrides + permission check helpers (for partials_header.php)

declare(strict_types=1);

// ---- includes (match your project paths) ----
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/acl.php'; // If missing has_perm(), we provide a safe fallback below

/* ---------------------------------------------------------
   DB accessor
--------------------------------------------------------- */
if (!function_exists('menu_db')) {
  function menu_db(): PDO { return db(); }
}

/* ---------------------------------------------------------
   has_perm() SAFE FALLBACK
   বাংলা: acl.php এ has_perm() না থাকলে এখানে ফfallback
   - Admin bypass (username == 'admin')
   - Session perms: $_SESSION['acl_perms'] বা $_SESSION['perms']
   - exact match অথবা wildcard prefix (e.g., hr.*, billing.*, users.*)
--------------------------------------------------------- */
if (!function_exists('has_perm')) {
  function has_perm(string $perm): bool {
    // Admin bypass
    $u = strtolower((string)($_SESSION['username'] ?? ''));
    if ($u === 'admin') return true;

    // Session perms array
    $perms = $_SESSION['acl_perms'] ?? $_SESSION['perms'] ?? [];
    if (!is_array($perms)) return false;

    // All access
    if (in_array('*', $perms, true)) return true;

    // Exact match
    if (in_array($perm, $perms, true)) return true;

    // Wildcard prefix match: e.g., "hr.toggle" → try "hr.*", "hr.toggle.*"
    // Check progressively shorter prefixes ending with ".*"
    $parts = explode('.', $perm);
    // Try full with trailing .* first (e.g., "hr.toggle.*")
    if (in_array($perm . '.*', $perms, true)) return true;

    while (count($parts) > 0) {
      $wild = implode('.', $parts) . '.*';
      if (in_array($wild, $perms, true)) return true;
      array_pop($parts);
    }
    return false;
  }
}

/* ---------------------------------------------------------
   Ensure overrides table exists (idempotent)
   Table: menu_overrides (user_id, menu_key, allowed, sort_order)
--------------------------------------------------------- */
function menu_ensure_table(): void {
  try {
    menu_db()->exec("
      CREATE TABLE IF NOT EXISTS menu_overrides (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT NOT NULL,
        menu_key VARCHAR(191) NOT NULL,
        allowed TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NULL,
        UNIQUE KEY uniq_user_menu (user_id, menu_key)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
  } catch (Throwable $e) {
    // বাংলা: নীরব ফেল-সেইফ; চাইলে লগ করবেন
  }
}

/* ---------------------------------------------------------
   Read all overrides for a user
   Return: [menu_key => ['allowed'=>0/1, 'sort_order'=>int|null]]
--------------------------------------------------------- */
function menu_get_overrides(int $user_id): array {
  menu_ensure_table();
  $st = menu_db()->prepare("SELECT menu_key, allowed, sort_order FROM menu_overrides WHERE user_id=?");
  $st->execute([$user_id]);
  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
    $out[$r['menu_key']] = [
      'allowed'    => (int)$r['allowed'],
      'sort_order' => isset($r['sort_order']) ? (int)$r['sort_order'] : null,
    ];
  }
  return $out;
}

/* ---------------------------------------------------------
   Can show a single menu item?
   Rules:
   1) user override wins (allowed=1 show, 0 hide)
   2) else if item has 'perm' → check has_perm()
   3) else show by default
--------------------------------------------------------- */
function can_show_menu(array $item, int $user_id, array $overrides=[]): bool {
  $key = $item['key'] ?? '';
  if ($key !== '' && isset($overrides[$key])) {
    return (int)$overrides[$key]['allowed'] === 1;
  }
  $perm = $item['perm'] ?? '';
  if ($perm !== '') return has_perm($perm); // uses real or fallback has_perm()
  return true;
}

/* ---------------------------------------------------------
   Sort items using user-defined order first, then label ASC
   - Items with NULL order go to bottom (9999)
--------------------------------------------------------- */
function sort_menu_items(array $items, array $overrides): array {
  usort($items, function($a,$b) use ($overrides){
    $ka = $a['key'] ?? ''; $kb = $b['key'] ?? '';
    $oa = ($ka && isset($overrides[$ka]) && isset($overrides[$ka]['sort_order']))
          ? (int)$overrides[$ka]['sort_order'] : 9999;
    $ob = ($kb && isset($overrides[$kb]) && isset($overrides[$kb]['sort_order']))
          ? (int)$overrides[$kb]['sort_order'] : 9999;

    if ($oa === $ob) {
      $la = (string)($a['label'] ?? '');
      $lb = (string)($b['label'] ?? '');
      return strcasecmp($la, $lb);
    }
    return $oa <=> $ob;
  });
  return $items;
}

/* ---------------------------------------------------------
   Optional: Upsert a batch of overrides
   বাংলা: অ্যাডমিন UI (/public/users_menu_access.php) এই ফাংশন ব্যবহার করতে পারে
--------------------------------------------------------- */
function menu_save_overrides_for_user(int $user_id, array $allowedMap, array $orderMap, array $registry): void {
  menu_ensure_table();
  $pdo = menu_db();
  $pdo->beginTransaction();
  try {
    $up = $pdo->prepare("
      INSERT INTO menu_overrides(user_id,menu_key,allowed,sort_order)
      VALUES(?,?,?,?)
      ON DUPLICATE KEY UPDATE allowed=VALUES(allowed), sort_order=VALUES(sort_order)
    ");
    foreach ($registry as $blk) {
      foreach ($blk['items'] ?? [] as $it) {
        $key = $it['key'] ?? '';
        if ($key === '') continue;
        $allow = isset($allowedMap[$key]) ? 1 : 0;
        $ord   = isset($orderMap[$key]) && $orderMap[$key] !== '' ? (int)$orderMap[$key] : null;
        $up->execute([$user_id, $key, $allow, $ord]);
      }
    }
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
}
