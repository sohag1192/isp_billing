<?php
// /partials/client_activity_widget.php
// Purpose: Show last 10 audit log entries for this client on client_view.php
// Works even if audit_logs lacks entity_type or JSON functions.

if (!isset($pdo) || !isset($client['id'])) { return; }
$clientId = (int)$client['id'];

if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
}

// ------ table present? ------
$has_audit = true;
try { $pdo->query("SELECT 1 FROM audit_logs LIMIT 1"); }
catch (Throwable $e) { $has_audit = false; }
if (!$has_audit) { return; }

// ------ schema detect ------
$colExists = function(string $col) use ($pdo): bool {
  $st = $pdo->prepare("SHOW COLUMNS FROM `audit_logs` LIKE ?");
  $st->execute([$col]);
  return (bool)$st->fetchColumn();
};

$tsExpr = null;
foreach (['ts','created_at','logged_at','time','timestamp','event_time'] as $c) {
  if ($colExists($c)) { $tsExpr = "al.`$c`"; break; }
}
if ($tsExpr === null) { $tsExpr = 'al.id'; }

$has_user_id  = $colExists('user_id');
$has_actor    = $colExists('actor');
$has_ip       = $colExists('ip');
$has_details  = $colExists('details');
$has_entityid = $colExists('entity_id');
$has_action   = $colExists('action');
$has_etype    = $colExists('entity_type');

// SELECT list (safe aliases)
$selects = [
  "al.id",
  "$tsExpr AS ts",
  $has_user_id ? "al.user_id" : "NULL AS user_id",
  $has_actor   ? "al.actor"   : "NULL AS actor",
  $has_action  ? "al.action"  : "''   AS action",
  $has_etype   ? "al.entity_type" : "'' AS entity_type",
  $has_entityid? "al.entity_id"   : "NULL AS entity_id",
  $has_details ? "al.details" : "NULL AS details",
  $has_ip      ? "al.ip"      : "NULL AS ip",
];

// ------ WHERE build (no missing columns in predicates) ------
$conds = [];
$params = [];

// client row: if entity_id exists, use it; if entity_type also exists, narrow to 'client'
if ($has_entityid) {
  $conds[]  = $has_etype ? "(al.entity_type='client' AND al.entity_id=?)" : "(al.entity_id=?)";
  $params[] = $clientId;
}

// invoice row: only use details if details column exists;
// if entity_type exists, add 'invoice' guard; otherwise just details LIKE
if ($has_details) {
  $like1 = '%"client_id":'.(string)$clientId.'%';          // numeric
  $like2 = '%"client_id":"'.(string)$clientId.'"%';        // string
  $pattern = "(COALESCE(al.details,'') LIKE ? OR COALESCE(al.details,'') LIKE ?)";
  if ($has_etype) {
    $conds[] = "(al.entity_type='invoice' AND $pattern)";
  } else {
    $conds[] = "($pattern)";
  }
  $params[] = $like1;
  $params[] = $like2;
}

// nothing detectable? show nothing rather than full table
if (!$conds) { $conds[] = "1=0"; }

$sql = "SELECT ".implode(", ", $selects)."
        FROM audit_logs al
        WHERE ".implode(" OR ", $conds)."
        ORDER BY ts DESC, al.id DESC
        LIMIT 10";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="card mb-3">
  <div class="card-body">
    <div class="d-flex align-items-center justify-content-between mb-2">
      <h5 class="mb-0">Recent Activity</h5>
      <a class="btn btn-sm btn-outline-secondary"
         href="/public/audit_logs.php?search=<?php echo urlencode((string)$clientId); ?>"
         target="_blank" rel="noopener">Open Logs</a>
    </div>

    <?php if (!$rows): ?>
      <div class="text-muted small">No recent activity found.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead class="table-light">
            <tr>
              <th>Time</th>
              <th>Action</th>
              <th>Entity</th>
              <th>User</th>
              <th>IP</th>
              <th>Details</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r):
            $a = strtolower((string)($r['action'] ?? ''));
            $badge = in_array($a, ['enable','payment_add','invoice_generate','undo_left','package_change'], true) ? 'success'
                   : (in_array($a, ['disable','left','invoice_void'], true) ? 'danger' : 'secondary');

            $short = '';
            if (!empty($r['details'])) {
              $arr  = json_decode((string)$r['details'], true);
              $json = $arr ? json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : (string)$r['details'];
              $short = mb_substr($json, 0, 120) . (mb_strlen($json) > 120 ? '…' : '');
            }
          ?>
            <tr>
              <td><small class="text-muted"><?php echo h($r['ts']); ?></small></td>
              <td><span class="badge bg-<?php echo $badge; ?>"><?php echo h($r['action'] ?? ''); ?></span></td>
              <td><small><?php echo h(($r['entity_type'] ?? 'entity').'#'.(string)($r['entity_id'] ?? '')); ?></small></td>
              <td><small><?php echo h(($r['actor'] ?? '') ?: ('#'.(string)($r['user_id'] ?? ''))); ?></small></td>
              <td><small><?php echo h($r['ip'] ?? ''); ?></small></td>
              <td><small class="text-muted"><?php echo h($short); ?></small></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
