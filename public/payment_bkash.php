<?php
// /public/payment_bkash.php
// UI: English; Comments: বাংলা
// Feature: Add bKash payment with optional API verification; duplicate guard; insert into `payments`.
//          Also supports mapping to a specific client (client_id or by client_code/pppoe_id).

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');

$ROOT = dirname(__DIR__, 1);
require_once $ROOT . '/app/require_login.php';
require_once $ROOT . '/app/db.php';

// (optional) ACL
$acl_file = $ROOT . '/app/acl.php';
if (is_file($acl_file)) require_once $acl_file;
if (function_exists('require_perm')) {
  // বাংলা: শুধু উদাহরণ—আপনার প্রকল্পে আলাদা perm key থাকলে সেটি ব্যবহার করুন
  require_perm('payments.add');
}

// Header partial (keeps light theme/page chrome consistent)
$page_title = 'Add Payment (bKash)';
$active_menu = 'billing';
require_once $ROOT . '/partials/partials_header.php';

// Helpers
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function dbh(): PDO { $pdo=db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); return $pdo; }

// CSRF
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
$csrf = $_SESSION['csrf'];

// Fetch client by flexible keys
function find_client(PDO $pdo, array $input): ?array {
  // বাংলা: client খুঁজবে client_id / client_code / pppoe_id—যেটা আসছে সেটায়
  $keys = [
    ['client_id','id'],
    ['client_code','client_code'],
    ['pppoe_id','pppoe_id'],
  ];
  foreach ($keys as [$in, $col]) {
    if (!empty($input[$in])) {
      $st = $pdo->prepare("SELECT * FROM clients WHERE `$col` = ? LIMIT 1");
      $st->execute([trim((string)$input[$in])]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if ($row) return $row;
    }
  }
  return null;
}

// Duplicate guard on txn_id (method=bkash)
function payment_exists(PDO $pdo, string $txn_id): bool {
  $st = $pdo->prepare("SELECT 1 FROM payments WHERE txn_id = ? AND method = 'bkash' LIMIT 1");
  $st->execute([$txn_id]);
  return (bool)$st->fetchColumn();
}

// Insert payment + optional Telegram hook
function insert_payment(PDO $pdo, int $client_id, float $amount, string $txn_id, string $note): int {
  // বাংলা: payments টেবিল স্কিমা: id, bill_id(nullable), amount, discount, method, txn_id, paid_at, notes
  $paid_at = date('Y-m-d H:i:s');
  $st = $pdo->prepare("INSERT INTO payments (bill_id, amount, discount, method, txn_id, paid_at, notes, client_id)
                       VALUES (NULL, ?, 0, 'bkash', ?, ?, ?, ?)");
  // বাংলা: কিছু প্রোজেক্টে payments.client_id নাও থাকতে পারে—থাকলে ভালো। না থাকলে ALTER দরকার।
  try {
    $st->execute([$amount, $txn_id, $paid_at, $note, $client_id]);
  } catch (Throwable $e) {
    // fallback: client_id নেই—notes-এ client info যোগ করে insert
    $st2 = $pdo->prepare("INSERT INTO payments (bill_id, amount, discount, method, txn_id, paid_at, notes)
                          VALUES (NULL, ?, 0, 'bkash', ?, ?, ?)");
    $st2->execute([$amount, $txn_id, $paid_at, "[client:$client_id] ".$note]);
  }
  $pid = (int)$pdo->lastInsertId();

  // Telegram hook (optional)
  $TG_HOOK = $ROOT . '/../tg/hook_payment.php';
  $TG_CORE = $ROOT . '/../tg/telegram.php';
  if (is_readable($TG_HOOK)) {
    require_once $TG_HOOK;
    if (function_exists('tg_payment_notify')) {
      // বাংলা: আপনার হুক সিগনেচার অনুযায়ী কাস্টমাইজ করুন
      try { tg_payment_notify('bkash', $txn_id, $amount, $paid_at); } catch(Throwable $e) {}
    }
  } elseif (is_readable($TG_CORE)) {
    require_once $TG_CORE;
    if (function_exists('tg_send_payment')) {
      try { tg_send_payment('bkash', $txn_id, $amount, $paid_at); } catch(Throwable $e) {}
    }
  }

  return $pid;
}

// Handle POST
$errors = [];
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($csrf, $_POST['csrf'] ?? '')) {
    $errors[] = 'Invalid CSRF token.';
  } else {
    $pdo = dbh();
    $trx  = trim((string)($_POST['trx_id'] ?? ''));
    $amt  = (float)($_POST['amount'] ?? 0);
    $note = trim((string)($_POST['note'] ?? ''));
    $verify = isset($_POST['verify']) ? 1 : 0;

    if ($trx === '')      $errors[] = 'Transaction ID is required.';
    if ($amt <= 0)        $errors[] = 'Amount must be greater than zero.';

    // Resolve client (any of the fields can be used)
    $client_input = [
      'client_id'   => trim((string)($_POST['client_id'] ?? '')),
      'client_code' => trim((string)($_POST['client_code'] ?? '')),
      'pppoe_id'    => trim((string)($_POST['pppoe_id'] ?? '')),
    ];
    $client = find_client($pdo, $client_input);
    if (!$client) $errors[] = 'Client not found. Provide a valid client reference.';

    if (empty($errors)) {
      if (payment_exists($pdo, $trx)) {
        $errors[] = 'Duplicate trxID detected for bKash.';
      } else {
        $verified_info = null;
        if ($verify) {
          require_once $ROOT . '/app/bkash.php';
          try {
            $verified_info = bkash_verify_trx($trx);
            if (!($verified_info['ok'] ?? false)) {
              // বাংলা: কনফিগ না থাকলে ম্যানুয়াল সেভ আলাউ করব; কিন্তু WARN দেখাব
              if (($verified_info['status'] ?? '') !== 'CONFIG_MISSING') {
                $errors[] = 'Verification failed: ' . h((string)($verified_info['status'] ?? 'UNKNOWN'));
              }
            } else {
              // Amount mismatch guard (tolerate small rounding)
              $apiAmt = (float)$verified_info['amount'];
              if ($apiAmt > 0 && abs($apiAmt - $amt) > 0.5) {
                $errors[] = 'Amount mismatch with bKash status. Expected ' . $apiAmt . ' BDT.';
              }
            }
          } catch (Throwable $e) {
            $errors[] = 'Verification exception: ' . $e->getMessage();
          }
        }

        if (empty($errors)) {
          $note2 = $note;
          if (is_array($verified_info)) {
            $note2 .= ($note2 ? ' ' : '') . '[bkash:' . ($verified_info['status'] ?? 'NA') . '; payer:' . ($verified_info['payer'] ?? '') . ']';
          }
          $pid = insert_payment($pdo, (int)$client['id'], $amt, $trx, $note2);
          $success = 'Payment saved successfully (ID: ' . (int)$pid . ').';
        }
      }
    }
  }
}
?>
<div class="container my-4">
  <?php if ($success): ?>
    <div class="alert alert-success shadow-sm"><?= h($success) ?></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="alert alert-danger shadow-sm">
      <strong>Could not save payment:</strong>
      <ul class="mb-0">
        <?php foreach ($errors as $e): ?>
          <li><?= h($e) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-header bg-light">
      <h5 class="mb-0">Add bKash Payment</h5>
    </div>
    <div class="card-body">
      <form method="post" class="row g-3">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

        <div class="col-md-4">
          <label class="form-label">Client ID</label>
          <input type="text" name="client_id" class="form-control" placeholder="e.g., 123">
          <div class="form-text">Either Client ID, Client Code, or PPPoE ID is required.</div>
        </div>

        <div class="col-md-4">
          <label class="form-label">Client Code</label>
          <input type="text" name="client_code" class="form-control" placeholder="e.g., C-2025-0012">
        </div>

        <div class="col-md-4">
          <label class="form-label">PPPoE ID</label>
          <input type="text" name="pppoe_id" class="form-control" placeholder="e.g., user123">
        </div>

        <div class="col-md-6">
          <label class="form-label">bKash trxID <span class="text-danger">*</span></label>
          <input type="text" name="trx_id" class="form-control" required>
        </div>

        <div class="col-md-3">
          <label class="form-label">Amount (BDT) <span class="text-danger">*</span></label>
          <input type="number" step="0.01" min="0" name="amount" class="form-control" required>
        </div>

        <div class="col-md-3">
          <label class="form-label">Verify via API</label>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" value="1" name="verify" id="verifyBox" checked>
            <label class="form-check-label" for="verifyBox">Verify transaction status</label>
          </div>
          <div class="form-text">If credentials missing, manual save will still work with a warning.</div>
        </div>

        <div class="col-12">
          <label class="form-label">Notes</label>
          <input type="text" name="note" class="form-control" placeholder="optional memo">
        </div>

        <div class="col-12 d-flex gap-2">
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-wallet2"></i> Save Payment
          </button>
          <a href="/public/index.php" class="btn btn-outline-secondary">Back</a>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once $ROOT . '/partials/partials_footer.php'; ?>
