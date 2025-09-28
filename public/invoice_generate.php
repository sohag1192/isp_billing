<?php
// /public/invoice_generate.php
// Preview/commit monthly invoices (schema-aware, replace-safe)
// বাংলা নোট: শুধুমাত্র কমেন্ট বাংলায় রাখা হয়েছে

declare(strict_types=1);
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// --- schema helpers (Bangla notes in comments) ---
function list_columns(PDO $pdo, string $tbl): array {
    try {
        $rows = $pdo->query("SHOW COLUMNS FROM `$tbl`")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) { $out[$r['Field']] = $r; }
        return $out;
    } catch (Throwable $e) { return []; }
}
function first_existing(array $cands, array $cols): ?string {
    foreach ($cands as $c) if (isset($cols[$c])) return $c;
    return null;
}
function get_index_columns(PDO $pdo, string $tbl, string $keyName): array {
    // বাংলা: নির্দিষ্ট ইনডেক্সের কলাম সিকোয়েন্স অনুযায়ী রিটার্ন
    try {
        $st = $pdo->prepare("SHOW INDEX FROM `$tbl` WHERE Key_name=? ORDER BY Seq_in_index ASC");
        $st->execute([$keyName]);
        $cols = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) $cols[] = $r['Column_name'];
        return $cols;
    } catch (Throwable $e) { return []; }
}
function make_next_invoice_number(PDO $pdo, string $ym, string $col, array &$reserved): string {
    $prefix = str_replace('-', '', $ym) . '-';
    $st = $pdo->prepare("SELECT `$col` FROM `invoices` WHERE `$col` LIKE ? ORDER BY `$col` DESC LIMIT 1");
    $st->execute([$prefix.'%']);
    $last = (string)$st->fetchColumn();
    $n = 0;
    if ($last && preg_match('/-(\d+)$/', $last, $m)) $n = (int)$m[1];
    do {
        $n++;
        $cand = $prefix . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
    } while (isset($reserved[$cand]));
    $reserved[$cand] = true;
    return $cand;
}

// --- inputs ---
$month  = isset($_GET['month']) ? trim((string)$_GET['month']) : '';
$commit = isset($_GET['commit']) ? 1 : 0;

// computed dates
$today  = date('Y-m-d');                                        // invoice date
$bmDate = preg_match('/^\d{4}-\d{2}$/', $month) ? ($month.'-01') : $month; // period date (YYYY-MM-01)
$now    = date('Y-m-d H:i:s');

// --- invoices schema detection ---
$invCols = list_columns($pdo, 'invoices');
if (!$invCols) {
    include __DIR__ . '/../partials/partials_header.php';
    echo '<div class="container py-4"><div class="alert alert-danger">`invoices` table not found.</div></div>';
    include __DIR__ . '/../partials/partials_footer.php';
    exit;
}

// amount/number/date/period discovery
$amountCol = first_existing(['total','amount','payable','grand_total','net_total'], $invCols);
$invNumCol = first_existing(['invoice_number','invoice_no','number','no'], $invCols);
// generic date-like columns we may use if present
$invDateCol = first_existing(['invoice_date','inv_date','date','bill_date'], $invCols);

// NOTE: period column is whatever is in the unique key `uniq_client_period` (except client_id)
$uniqCols = get_index_columns($pdo, 'invoices', 'uniq_client_period'); // e.g., ['client_id','period'] or ['client_id','period','invoice_date']
$uniqPeriodCols = [];
$uniqOtherDateCols = [];
foreach ($uniqCols as $uc) {
    if ($uc === 'client_id') continue;
    if (preg_match('/period|month|billing|cycle|for_month/i', $uc)) {
        $uniqPeriodCols[] = $uc;
    } elseif (preg_match('/date|inv/i', $uc)) {
        $uniqOtherDateCols[] = $uc;
    }
}

// Fallback period column if unique key not discovered
if (!$uniqPeriodCols) {
    $maybe = first_existing(['billing_month','period','month','billing_period','for_month'], $invCols);
    if ($maybe) $uniqPeriodCols[] = $maybe;
}

// Replace-safe lookup will use the first period-like column if available
$periodKeyCol = $uniqPeriodCols[0] ?? null;

// status/void/desc/timestamps
$hasStatus = isset($invCols['status']);
$hasVoid   = isset($invCols['is_void']);
$hasDesc   = isset($invCols['description']);
$hasCT     = isset($invCols['created_at']);
$hasUT     = isset($invCols['updated_at']);

// --- UI ---
include __DIR__ . '/../partials/partials_header.php';
?>
<div class="container py-4">
  <h3 class="mb-3">Invoice Generate (Monthly)</h3>

  <form class="row gy-2 gx-2 align-items-end mb-3" method="get">
    <div class="col-auto">
      <label class="form-label mb-0">Month</label>
      <input type="month" class="form-control" name="month" required value="<?= h($month ?: date('Y-m')) ?>">
    </div>
    <div class="col-auto">
      <button class="btn btn-primary" type="submit">Preview</button>
    </div>
    <?php if ($month): ?>
    <div class="col-auto">
      <a class="btn btn-success" href="?month=<?= h($month) ?>&commit=1"
         onclick="return confirm('Commit invoices for <?= h($month) ?>? Old same-period invoices will be voided and replaced.');">
        Commit
      </a>
    </div>
    <?php endif; ?>
  </form>
<?php
if (!$month) {
    echo '</div>'; include __DIR__ . '/../partials/partials_footer.php'; exit;
}
if (!$amountCol) {
    echo '<div class="alert alert-danger">No amount column found in invoices (tried: total/amount/payable/grand_total/net_total).</div></div>';
    include __DIR__ . '/../partials/partials_footer.php';
    exit;
}

// --- load client amounts ---
$sql = "
  SELECT c.id AS client_id, c.client_code, c.name, c.pppoe_id, c.is_left,
         COALESCE(c.monthly_bill,0) AS bill, c.package_id,
         p.name AS package_name, p.price AS pkg_price
  FROM clients c
  LEFT JOIN packages p ON p.id = c.package_id
  ORDER BY c.id
";
$clients = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$preview = [];
$stats = ['total'=>0,'zero'=>0,'left'=>0,'payable'=>0.0,'has_old'=>0];

// replace-safe old detection (if we know a period column)
$stOld = null;
if ($periodKeyCol) {
    $stOld = $pdo->prepare(
        "SELECT id, `$amountCol` AS amt FROM invoices
         WHERE client_id=? AND `$periodKeyCol`=? " .
        ($hasVoid ? "AND (is_void=0 OR is_void IS NULL)" : ($hasStatus ? "AND status<>'void'" : "")) .
        " LIMIT 1"
    );
}

foreach ($clients as $r) {
    if ((int)$r['is_left'] === 1) { $stats['left']++; continue; }

    $amt = (float)$r['bill'];
    if ($amt <= 0) $amt = (float)($r['pkg_price'] ?? 0);
    $zero = $amt <= 0;

    $hadOld = false; $oldAmt = null;
    if ($stOld) {
        $stOld->execute([(int)$r['client_id'], $bmDate]);
        if ($o = $stOld->fetch(PDO::FETCH_ASSOC)) { $hadOld = true; $oldAmt = (float)$o['amt']; }
    }

    $preview[] = [
        'client_id'  => (int)$r['client_id'],
        'client'     => $r['name'] ?: $r['pppoe_id'],
        'pppoe_id'   => $r['pppoe_id'],
        'package'    => $r['package_name'],
        'amount'     => $amt,
        'zero'       => $zero,
        'had_old'    => $hadOld,
        'old_amount' => $oldAmt,
    ];
    $stats['total']++;
    if ($zero) $stats['zero']++; else $stats['payable'] += $amt;
    if ($hadOld) $stats['has_old']++;
}

// --- commit ---
if ($commit) {
    $created = 0; $voided = 0; $skipped = 0;

    $pdo->beginTransaction();
    try {
        $stPlus  = $pdo->prepare("UPDATE clients SET ledger_balance=COALESCE(ledger_balance,0)+? WHERE id=?");
        $stMinus = $pdo->prepare("UPDATE clients SET ledger_balance=COALESCE(ledger_balance,0)-? WHERE id=?");

        $stVoid = ($hasVoid || $hasStatus)
            ? $pdo->prepare("UPDATE invoices SET " .
                ($hasVoid ? "is_void=1" : "") .
                ($hasVoid && $hasStatus ? ", " : "") .
                ($hasStatus ? "status='void'" : "") .
                ($hasUT ? ", updated_at=?" : "") .
                " WHERE id=?")
            : null;

        $stGetOld = $periodKeyCol
            ? $pdo->prepare("SELECT id, `$amountCol` AS amt FROM invoices
                             WHERE client_id=? AND `$periodKeyCol`=? " .
                             ($hasVoid ? "AND (is_void=0 OR is_void IS NULL)" : ($hasStatus ? "AND status<>'void'" : "")) .
                             " LIMIT 1")
            : null;

        // build INSERT column list
        $insCols = ['client_id', $amountCol];
        if ($invNumCol) $insCols[] = $invNumCol;

        // include all period/date columns required by unique key
        foreach ($uniqPeriodCols as $c) if (!in_array($c, $insCols, true)) $insCols[] = $c;
        foreach ($uniqOtherDateCols as $c) if (!in_array($c, $insCols, true)) $insCols[] = $c;

        // also include generic invoice_date if present and not already included
        if ($invDateCol && !in_array($invDateCol, $insCols, true)) $insCols[] = $invDateCol;

        if ($hasStatus) $insCols[] = 'status';
        if ($hasDesc)   $insCols[] = 'description';
        if ($hasCT)     $insCols[] = 'created_at';
        if ($hasUT)     $insCols[] = 'updated_at';

        $ph = rtrim(str_repeat('?,', count($insCols)), ',');
        $stIns = $pdo->prepare("INSERT INTO invoices (".implode(',', $insCols).") VALUES ($ph)");

        $reserved = []; // local reservation for invoice_number uniqueness

        foreach ($preview as $row) {
            $cid = (int)$row['client_id'];
            if ($row['zero']) { $skipped++; continue; }

            // replace-safe: void old same-period invoice
            if ($stGetOld) {
                $stGetOld->execute([$cid, $bmDate]);
                if ($old = $stGetOld->fetch(PDO::FETCH_ASSOC)) {
                    if ($stVoid) {
                        $valsV = [];
                        if ($hasUT) $valsV[] = $now;
                        $valsV[] = (int)$old['id'];
                        $stVoid->execute($valsV);
                    }
                    if ($old['amt'] !== null) $stMinus->execute([(float)$old['amt'], $cid]);
                    $voided++;
                }
            }

            // assemble insert values in the same order as $insCols
            $vals = [];
            foreach ($insCols as $col) {
                if ($col === 'client_id') {
                    $vals[] = $cid;
                } elseif ($col === $amountCol) {
                    $vals[] = (float)$row['amount'];
                } elseif ($invNumCol && $col === $invNumCol) {
                    $vals[] = make_next_invoice_number($pdo, $month, $invNumCol, $reserved);
                } elseif (in_array($col, $uniqPeriodCols, true)) {
                    // period-like columns → YYYY-MM-01
                    $vals[] = $bmDate;
                } elseif (in_array($col, $uniqOtherDateCols, true)) {
                    // date-like columns from unique key → today
                    $vals[] = $today;
                } elseif ($invDateCol && $col === $invDateCol) {
                    $vals[] = $today;
                } elseif ($col === 'status') {
                    $vals[] = 'unpaid';
                } elseif ($col === 'description') {
                    $vals[] = 'Auto-generated (invoice_generate.php)';
                } elseif ($col === 'created_at' || $col === 'updated_at') {
                    $vals[] = $now;
                } else {
                    // safe default
                    $vals[] = null;
                }
            }

            $stIns->execute($vals);
            $stPlus->execute([(float)$row['amount'], $cid]);
            $created++;
        }

        $pdo->commit();
        echo '<div class="alert alert-success">Commit OK. New: <b>'.$created.'</b>, Voided: <b>'.$voided.'</b>, Skipped: <b>'.$skipped.'</b>.</div>';

    } catch (Throwable $e) {
        $pdo->rollBack();
        echo '<div class="alert alert-danger">Commit failed: '.h($e->getMessage()).'</div>';
    }
}

// --- preview table ---
?>
  <div class="card">
    <div class="card-header">
      <div class="d-flex justify-content-between">
        <div>Preview — Month: <b><?= h($month) ?></b></div>
        <div>
          Total: <b><?= (int)$stats['total'] ?></b>,
          Zero: <b><?= (int)$stats['zero'] ?></b>,
          Left: <b><?= (int)$stats['left'] ?></b>,
          Had old: <b><?= (int)$stats['has_old'] ?></b>,
          Payable: <b><?= number_format($stats['payable'], 2) ?></b>
          <?= $invNumCol ? ' | Number: <code>'.h($invNumCol).'</code>' : '' ?>
          <?= $periodKeyCol ? ' | Period key: <code>'.h($periodKeyCol).'</code>='.$bmDate : ' | Period key: <span class="text-danger">none</span>' ?>
        </div>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table table-sm table-striped mb-0">
        <thead class="table-light">
          <tr>
            <th>#</th><th>Client</th><th>PPPoE</th><th>Package</th>
            <th class="text-end">Amount</th><th>Old?</th><th>Note</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$preview): ?>
            <tr><td colspan="7" class="text-center text-muted">No clients found.</td></tr>
          <?php else: $i=0; foreach ($preview as $r): $i++; ?>
            <tr class="<?= $r['zero'] ? 'table-warning' : '' ?>">
              <td><?= $i ?></td>
              <td><?= h($r['client']) ?></td>
              <td><code><?= h($r['pppoe_id']) ?></code></td>
              <td><?= h($r['package'] ?: '-') ?></td>
              <td class="text-end"><?= number_format((float)$r['amount'], 2) ?></td>
              <td><?= $r['had_old'] ? '<span class="badge bg-info">Yes</span>' : '<span class="badge bg-secondary">No</span>' ?></td>
              <td><?= $r['zero'] ? '<span class="text-danger">Zero (skip)</span>' : '' ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer text-end">
      <a class="btn btn-success" href="?month=<?= h($month) ?>&commit=1"
         onclick="return confirm('Commit invoices for <?= h($month) ?>? Old same-period invoices will be voided and replaced.');">Commit</a>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../partials/partials_footer.php'; ?>
