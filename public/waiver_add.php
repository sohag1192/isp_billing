<?php
// /public/waiver_add.php
// Add Waiver/Adjustment for a single client (ledger-safe)
// UI: English; Comments: বাংলা
declare(strict_types=1);

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------- DB helpers ---------- */
// (বাংলা) টেবিলের কলাম আছে কিনা — একবার চেক করে cache করি
function db_has_column(string $table, string $column): bool {
  static $cache = [];
  if (!isset($cache[$table])) {
    $rows = db()->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
    $cache[$table] = array_flip($rows ?: []);
  }
  return isset($cache[$table][$column]);
}

$payments_has_type     = db_has_column('payments','type');
$payments_has_method   = db_has_column('payments','method');
$payments_has_client   = db_has_column('payments','client_id');
$payments_has_notes    = db_has_column('payments','notes');
$payments_has_paid_at  = db_has_column('payments','paid_at');
$clients_has_ledger    = db_has_column('clients','ledger_balance');

// (বাংলা) ক্লায়েন্ট ফেচ: id / pppoe_id যেভাবেই দেয়া হোক
function find_client(array $input): ?array {
  $pdo = db();
  if (!empty($input['client_id'])) {
    $st = $pdo->prepare("SELECT * FROM clients WHERE id=? LIMIT 1");
    $st->execute([ (int)$input['client_id'] ]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;
  }
  if (!empty($input['pppoe_id'])) {
    $st = $pdo->prepare("SELECT * FROM clients WHERE pppoe_id=? LIMIT 1");
    $st->execute([ trim($input['pppoe_id']) ]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;
  }
  return null;
}

// (বাংলা) পোস্ট হ্যান্ডলার
$errors = [];
$done_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $pppoe_id = trim($_POST['pppoe_id'] ?? '');
  $client_id = (int)($_POST['client_id'] ?? 0);
  $amount = (float)($_POST['amount'] ?? 0);
  $reason = trim($_POST['reason'] ?? '');
  $paid_at = trim($_POST['paid_at'] ?? date('Y-m-d'));
  $allow_exceed = isset($_POST['allow_exceed']) ? 1 : 0;

  // Validate
  if (!$pppoe_id && !$client_id) $errors[] = 'Provide either PPPoE ID or Client ID.';
  if ($amount <= 0) $errors[] = 'Amount must be greater than 0.';
  if ($reason === '') $errors[] = 'Reason is required.';
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paid_at)) $errors[] = 'Invalid date.';

  $client = find_client(['client_id'=>$client_id, 'pppoe_id'=>$pppoe_id]);
  if (!$client) $errors[] = 'Client not found.';

  if (!$errors) {
    $pdo = db();
    $pdo->beginTransaction();
    try {
      // (বাংলা) Due (ledger_balance > 0) হলে ডিফল্টে ক্যাপ; exceed চেক থাকলে ক্যাপ নয়
      $apply_amount = $amount;
      if ($clients_has_ledger) {
        $current_ledger = (float)($client['ledger_balance'] ?? 0);
        if (!$allow_exceed) {
          $due = max(0, $current_ledger);
          if ($apply_amount > $due) $apply_amount = $due; // ক্যাপ
        }
      }

      // (বাংলা) payments টেবিলে ইনসার্ট
      $cols = ['amount'];
      $vals = [ $apply_amount ];
      $ph   = ['?'];

      // আমরা waiver-কে "type" বা "method" যে কলাম আছে তাতে সেট করব
      if ($payments_has_type) { $cols[]='type'; $vals[]='waiver'; $ph[]='?'; }
      elseif ($payments_has_method) { $cols[]='method'; $vals[]='waiver'; $ph[]='?'; }

      if ($payments_has_client) { $cols[]='client_id'; $vals[]=(int)$client['id']; $ph[]='?'; }
      if ($payments_has_notes) { $cols[]='notes'; $vals[]=$reason; $ph[]='?'; }
      if ($payments_has_paid_at) { $cols[]='paid_at'; $vals[]=$paid_at; $ph[]='?'; }

      // (বাংলা) ন্যূনতম কলাম নিশ্চিত
      $sql = "INSERT INTO payments (`".implode('`,`',$cols)."`) VALUES (".implode(',',$ph).")";
      $stmt = $pdo->prepare($sql);
      $stmt->execute($vals);

      // (বাংলা) Ledger update: ledger_balance -= apply_amount
      if ($clients_has_ledger && $apply_amount > 0) {
        $st2 = $pdo->prepare("UPDATE clients SET ledger_balance = COALESCE(ledger_balance,0) - ? WHERE id=?");
        $st2->execute([$apply_amount, (int)$client['id']]);
      }

      // (বাংলা) চাইলে এখানে ইনভয়েস রিকম্পিউট কল দেয়া যেত (আপনার সিস্টেমে থাকলে)
      // উদাহরণ: recompute_invoices_for_client_month($client['id']); // placeholder

      $pdo->commit();
      $cap_note = ($apply_amount < $amount) ? " (capped to current due)" : "";
      $done_msg = "Waiver added for {$client['pppoe_id']} - TK {$apply_amount}{$cap_note}.";
    } catch (Throwable $e) {
      $pdo->rollBack();
      $errors[] = 'DB error: '.$e->getMessage();
    }
  }
}

?>
<?php require_once __DIR__ . '/../partials/partials_header.php'; ?>
<div class="container my-4">
  <h3 class="mb-3">Add Waiver / Adjustment</h3>

  <?php if ($done_msg): ?>
    <div class="alert alert-success"><?=h($done_msg)?></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0"><?php foreach($errors as $e) echo '<li>'.h($e).'</li>'; ?></ul>
    </div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-body">
      <form method="post" class="row g-3">
        <div class="col-md-4">
          <label class="form-label">PPPoE ID</label>
          <input type="text" name="pppoe_id" class="form-control" placeholder="client PPPoE ID">
          <div class="form-text">Or provide Client ID below.</div>
        </div>
        <div class="col-md-2">
          <label class="form-label">Client ID</label>
          <input type="number" name="client_id" class="form-control" placeholder="0">
        </div>
        <div class="col-md-3">
          <label class="form-label">Waiver Amount (TK)</label>
          <input type="number" step="0.01" min="0.01" name="amount" required class="form-control" placeholder="e.g., 300">
        </div>
        <div class="col-md-3">
          <label class="form-label">Date</label>
          <input type="date" name="paid_at" value="<?=h(date('Y-m-d'))?>" class="form-control">
        </div>
        <div class="col-12">
          <label class="form-label">Reason</label>
          <input type="text" name="reason" required maxlength="255" class="form-control" placeholder="e.g., Line issue compensation">
        </div>
        <div class="col-12">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" value="1" id="allow_exceed" name="allow_exceed">
            <label class="form-check-label" for="allow_exceed">
              Allow exceed ledger (let ledger go below zero)
            </label>
          </div>
          <div class="form-text">If unchecked, waiver will be capped to current due (ledger &gt; 0).</div>
        </div>
        <div class="col-12">
          <button type="submit" class="btn btn-primary"><i class="bi bi-patch-minus"></i> Save Waiver</button>
          <a href="/public/waivers.php" class="btn btn-outline-secondary">View Waivers</a>
        </div>
      </form>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../partials/partials_footer.php'; ?>
