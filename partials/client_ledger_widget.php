<?php
// /partials/client_ledger_widget.php
// Purpose: Show last 12 months Bills vs Payments + running ledger for a given client on client_view.php
// Assumes: $pdo (PDO), $client (array with id, ledger_balance) already available in parent

if (!isset($pdo) || !isset($client['id'])) { return; }

$clientId = (int)$client['id'];

/* ---------- schema detect (বাংলা) ইনভয়েস/পেমেন্ট টেবিল স্ট্রাকচার বুঝি ---------- */
function col_exists_local(PDO $pdo, string $tbl, string $col): bool {
  $st = $pdo->prepare("SHOW COLUMNS FROM `$tbl` LIKE ?");
  $st->execute([$col]);
  return (bool)$st->fetchColumn();
}

$has_inv_tbl       = true;  try { $pdo->query("SELECT 1 FROM invoices LIMIT 1"); } catch(Exception $e){ $has_inv_tbl = false; }
$has_pay_tbl       = true;  try { $pdo->query("SELECT 1 FROM payments LIMIT 1"); } catch(Exception $e){ $has_pay_tbl = false; }

$inv_has_bm        = $has_inv_tbl && col_exists_local($pdo,'invoices','billing_month'); // YYYY-MM
$inv_has_date      = $has_inv_tbl && col_exists_local($pdo,'invoices','invoice_date');
$inv_has_month     = $has_inv_tbl && col_exists_local($pdo,'invoices','month');
$inv_has_year      = $has_inv_tbl && col_exists_local($pdo,'invoices','year');
$inv_has_total     = $has_inv_tbl && col_exists_local($pdo,'invoices','total');
$inv_has_amount    = $has_inv_tbl && col_exists_local($pdo,'invoices','amount');
$inv_has_payable   = $has_inv_tbl && col_exists_local($pdo,'invoices','payable');
$inv_has_status    = $has_inv_tbl && col_exists_local($pdo,'invoices','status');

$pay_has_date      = $has_pay_tbl && col_exists_local($pdo,'payments','payment_date');
$pay_has_created   = $has_pay_tbl && col_exists_local($pdo,'payments','created_at');
$pay_has_amount    = $has_pay_tbl && col_exists_local($pdo,'payments','amount');

// (বাংলা) ইনভয়েস গ্রুপিং তারিখ এক্সপ্রেশন
$invDateExpr = $inv_has_date
  ? "DATE_FORMAT(inv.invoice_date,'%Y-%m')"
  : ($inv_has_bm
      ? "inv.billing_month"
      : (($inv_has_year && $inv_has_month)
          ? "DATE_FORMAT(STR_TO_DATE(CONCAT(inv.year,'-',LPAD(inv.month,2,'0'),'-01'),'%Y-%m-%d'),'%Y-%m')"
          : "DATE_FORMAT(NOW(),'%Y-%m')")); // fallback (shouldn't hit)

$invAmtExpr  = $inv_has_total ? 'inv.total' : ($inv_has_amount ? 'inv.amount' : '0');
$invPayExpr  = $inv_has_payable ? 'inv.payable' : $invAmtExpr; // payable preferred

// (বাংলা) পেমেন্ট গ্রুপিং তারিখ এক্সপ্রেশন
$payDateExpr = $pay_has_date ? "DATE_FORMAT(pm.payment_date,'%Y-%m')" :
               ($pay_has_created ? "DATE_FORMAT(pm.created_at,'%Y-%m')" : "DATE_FORMAT(NOW(),'%Y-%m')");
$payAmtExpr  = $pay_has_amount ? 'pm.amount' : '0';

/* ---------- build last 12 months map ---------- */
$months = [];
$labels = [];
$mapBills = [];
$mapPays  = [];

$dt = new DateTime('first day of this month');
for ($i=11; $i>=0; $i--) {
  $m = (clone $dt)->modify("-$i month")->format('Y-m');
  $months[] = $m;
  $labels[] = date('M Y', strtotime($m.'-01'));
  $mapBills[$m] = 0.0;
  $mapPays[$m]  = 0.0;
}

/* ---------- fetch bills per month (বাংলা) ---------- */
if ($has_inv_tbl) {
  $sqlB = "SELECT $invDateExpr AS ym, SUM($invPayExpr) AS sum_payable
           FROM invoices inv
           WHERE inv.client_id = :cid
             ".($inv_has_status ? " AND (LOWER(inv.status) IN ('unpaid','partial','due','paid')) " : '')."
           GROUP BY ym";
  $stB = $pdo->prepare($sqlB);
  $stB->execute([':cid'=>$clientId]);
  foreach ($stB->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $ym = (string)$row['ym'];
    if (isset($mapBills[$ym])) {
      $mapBills[$ym] = (float)$row['sum_payable'];
    }
  }
}

/* ---------- fetch payments per month (বাংলা) ---------- */
if ($has_pay_tbl) {
  $sqlP = "SELECT $payDateExpr AS ym, SUM($payAmtExpr) AS sum_paid
           FROM payments pm
           WHERE pm.client_id = :cid
           GROUP BY ym";
  $stP = $pdo->prepare($sqlP);
  $stP->execute([':cid'=>$clientId]);
  foreach ($stP->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $ym = (string)$row['ym'];
    if (isset($mapPays[$ym])) {
      $mapPays[$ym] = (float)$row['sum_paid'];
    }
  }
}

/* ---------- compute running ledger (বাংলা) ---------- */
$running = [];
$acc = 0.0; // start from 0; প্রতি মাসে বিল - পেমেন্ট যোগ হয়; শেষ মান ≈ বর্তমান লেজার (ধরে নিচ্ছি অতীত থেকে কনসিসটেন্ট)
foreach ($months as $ym) {
  $delta = ($mapBills[$ym] ?? 0) - ($mapPays[$ym] ?? 0);
  $acc += $delta;
  $running[] = round($acc,2);
}

/* ---------- present totals ---------- */
$sum_bills = array_sum($mapBills);
$sum_pays  = array_sum($mapPays);
$current_ledger = isset($client['ledger_balance']) ? (float)$client['ledger_balance'] : end($running);
?>
<div class="card mb-3">
  <div class="card-body">
    <div class="d-flex align-items-center justify-content-between mb-2">
      <h5 class="mb-0">Ledger & Activity (12 months)</h5>
      <div class="d-flex gap-2">
        <span class="badge bg-dark">Bills: <?php echo number_format($sum_bills,2); ?></span>
        <span class="badge bg-success">Payments: <?php echo number_format($sum_pays,2); ?></span>
        <?php if ($current_ledger > 0): ?>
          <span class="badge bg-danger">Ledger (Due): <?php echo number_format($current_ledger,2); ?></span>
        <?php elseif ($current_ledger < 0): ?>
          <span class="badge bg-success">Ledger (Advance): <?php echo number_format(abs($current_ledger),2); ?></span>
        <?php else: ?>
          <span class="badge bg-secondary">Ledger: 0.00</span>
        <?php endif; ?>
      </div>
    </div>

    <div class="row g-3">
      <div class="col-12">
        <canvas id="ledgerChart" height="120"></canvas>
      </div>
    </div>
  </div>
</div>

<!-- (বাংলা) Chart.js CDN; পেজে একবারই লোড হবে -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function(){
  const labels   = <?php echo json_encode($labels, JSON_UNESCAPED_SLASHES); ?>;
  const bills    = <?php echo json_encode(array_values($mapBills), JSON_UNESCAPED_SLASHES); ?>;
  const pays     = <?php echo json_encode(array_values($mapPays), JSON_UNESCAPED_SLASHES); ?>;
  const running  = <?php echo json_encode($running, JSON_UNESCAPED_SLASHES); ?>;

  const ctx = document.getElementById('ledgerChart').getContext('2d');
  // (বাংলা) বার + লাইন কম্বো
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [
        { label: 'Bills (Payable)', data: bills },
        { label: 'Payments', data: pays },
        { type: 'line', label: 'Running Ledger', data: running, yAxisID: 'y' }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        y: { beginAtZero: true, title: { display: true, text: 'Amount' } },
        x: { ticks: { autoSkip: true, maxTicksLimit: 12 } }
      },
      plugins: {
        tooltip: { mode: 'index', intersect: false },
        legend: { position: 'top' }
      }
    }
  });
})();
</script>
