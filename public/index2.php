<?php
// /public/index.php
// বাংলা: ড্যাশবোর্ড — ডিফল্টে is_left=0; Left আলাদা কার্ড।
// Financial Summary-তে: Ledger KPIs + Today's Collection + This Month Collection + Unpaid/Partial Invoices
declare(strict_types=1);

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------- helpers: col_exists + tbl_exists + q_count + nf ---------- */
// বাংলা: টেবিলের কলাম আছে কিনা সেফ চেক
if (!function_exists('col_exists')) {
  function col_exists(PDO $pdo, string $table, string $column): bool {
    try {
      $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
      $st->execute([$column]);
      return (bool)$st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return false; }
  }
}
// বাংলা: টেবিল আছে কিনা
if (!function_exists('tbl_exists')) {
  function tbl_exists(PDO $pdo, string $t): bool {
    try {
      $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
      $q  = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
      $q->execute([$db, $t]);
      return (bool)$q->fetchColumn();
    } catch (Throwable $e) { return false; }
  }
}
// বাংলা: সেফ COUNT(*) — গ্লোবাল $pdo ব্যবহার
function q_count(string $sql, array $params = []): int {
  /** @var PDO $pdo */
  global $pdo;
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return (int)$st->fetchColumn();
}
// বাংলা: নম্বর সুন্দর ফরম্যাট
function nf($n): string { return number_format((float)$n, 0, '.', ','); }

/* ---------- schema guards ---------- */
$has_is_online       = col_exists($pdo, 'clients', 'is_online');
$has_ledger_balance  = col_exists($pdo, 'clients', 'ledger_balance'); // +ve=Due, -ve=Advance
$has_suspend_by_bill = col_exists($pdo, 'clients', 'suspend_by_billing');
$has_expiry_date     = col_exists($pdo, 'clients', 'expiry_date');
$has_updated_at      = col_exists($pdo, 'clients', 'updated_at');

$today = date('Y-m-d');

/* ---------- Auto suspended (by billing) ---------- */
if ($has_suspend_by_bill) {
  $auto_suspended = q_count("SELECT COUNT(*) FROM clients WHERE suspend_by_billing=1");
} else {
  // বাংলা: fallback — inactive + due>0 (ledger_balance থাকলে), না থাকলে শুধু inactive
  $cond_due = $has_ledger_balance ? "COALESCE(ledger_balance,0)>0" : "1=1";
  $auto_suspended = q_count("SELECT COUNT(*) FROM clients WHERE $cond_due AND COALESCE(status,'')='inactive'");
}

/* ---------- Totals (default scope: is_left=0) ---------- */
$total_clients    = q_count("SELECT COUNT(*) FROM clients WHERE is_left=0");
$active_clients   = q_count("SELECT COUNT(*) FROM clients WHERE is_left=0 AND status='active'");
$inactive_clients = q_count("SELECT COUNT(*) FROM clients WHERE is_left=0 AND status='inactive'");
$pending_clients  = q_count("SELECT COUNT(*) FROM clients WHERE is_left=0 AND status='pending'");
$total_disabled   = q_count("SELECT COUNT(*) FROM clients WHERE is_left=0 AND status='disabled'");
$left_clients     = q_count("SELECT COUNT(*) FROM clients WHERE is_left=1");

/* ---------- Expired / Online / Offline ---------- */
$expired_clients = 0;
if ($has_expiry_date) {
  $expired_clients = q_count("SELECT COUNT(*) FROM clients WHERE is_left=0 AND expiry_date IS NOT NULL AND DATE(expiry_date) < ?", [$today]);
}

if ($has_is_online) {
  $total_online  = q_count("SELECT COUNT(*) FROM clients WHERE is_left=0 AND is_online=1");
  $total_offline = q_count("SELECT COUNT(*) FROM clients WHERE is_left=0 AND is_online=0");
} else {
  $total_online  = 0;
  $total_offline = $total_clients;
}

/* ---------- Today ---------- */
$today_joined = q_count("SELECT COUNT(*) FROM clients WHERE is_left=0 AND DATE(join_date)=?", [$today]);

$today_expired = 0;
if ($has_expiry_date) {
  $today_expired = q_count("SELECT COUNT(*) FROM clients WHERE is_left=0 AND expiry_date IS NOT NULL AND DATE(expiry_date)=?", [$today]);
}

$today_inactive = 0;
if ($has_updated_at) {
  $today_inactive = q_count("SELECT COUNT(*) FROM clients WHERE is_left=0 AND status='inactive' AND DATE(updated_at)=?", [$today]);
}

/* ---------- Ledger totals (for Financial Summary) ---------- */
$total_due = 0.0; $total_adv = 0.0;
if ($has_ledger_balance) {
  $st = $pdo->query("
    SELECT
      SUM(CASE WHEN ledger_balance > 0 THEN ledger_balance ELSE 0 END) AS due_sum,
      SUM(CASE WHEN ledger_balance < 0 THEN -ledger_balance ELSE 0 END) AS adv_sum
    FROM clients
    WHERE is_left=0
  ");
  $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['due_sum'=>0,'adv_sum'=>0];
  $total_due = (float)($row['due_sum'] ?? 0);
  $total_adv = (float)($row['adv_sum'] ?? 0);
}

/* ---------- Payments & Invoices quick metrics ---------- */
// বাংলা: টেবিল/কলাম আছে কিনা চেক
$has_payments = tbl_exists($pdo,'payments') && col_exists($pdo,'payments','paid_at') && col_exists($pdo,'payments','amount');
$has_invoices = tbl_exists($pdo,'invoices') && col_exists($pdo,'invoices','status');

// বাংলা: Today's Collection + This Month Collection
$todays_collection = 0.0;
$this_month_collection = 0.0;
$unpaid_invoices = 0;

if ($has_payments) {
  // আজকের শুরুর/শেষের টাইম রেঞ্জ
  $dStart = date('Y-m-d 00:00:00');
  $dEnd   = date('Y-m-d 23:59:59');
  $st = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE paid_at BETWEEN ? AND ?");
  $st->execute([$dStart, $dEnd]);
  $todays_collection = (float)($st->fetchColumn() ?: 0);

  // মাসিক সংগ্রহ
  $mStart = date('Y-m-01 00:00:00');
  $mEnd   = date('Y-m-t 23:59:59');
  $st = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE paid_at BETWEEN ? AND ?");
  $st->execute([$mStart, $mEnd]);
  $this_month_collection = (float)($st->fetchColumn() ?: 0);
}

if ($has_invoices) {
  $st = $pdo->query("SELECT COUNT(*) FROM invoices WHERE status IN ('unpaid','partial')");
  $unpaid_invoices = (int)$st->fetchColumn();
}

$page_title = "Dashboard";
?>
<?php require __DIR__ . '/../partials/partials_header.php'; ?>

 <!----------- css আলাদা ফোল্ডার এ রাখা আছে index.css নামে --------------------------->
<?php require __DIR__ . '/../assets/css/index.css'; ?>






















<div class="dashboard-hero container-fluid">
  <!-- Top: page title + date chip -->
  <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
    <h4 class="mb-0">Dashboard</h4>
    

 <!-- Quick Links (Billing/Invoices enabled) -->
  <div class="mt-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span class="section-title mb-0"></span>
    <div class="d-flex align-items-center gap-2">
      <a class="btn btn-sm btn-pill btn-indigo" href="/public/clients.php">
        <i class="bi bi-people me-1"></i> All Clients      </a>
      <a class="btn btn-sm btn-pill btn-sky" href="/public/clients_online.php">
        <i class="bi bi-wifi me-1"></i> Online      </a>
      <a class="btn btn-sm btn-pill btn-slate" href="/public/clients_offline.php">
        <i class="bi bi-wifi-off me-1"></i> Offline      </a>
      <a class="btn btn-sm btn-pill btn-indigo" href="/public/billing.php">
        <i class="bi bi-receipt me-1"></i> Billing      </a>

		</div>
		</div>  
		</div>



  <!-- KPI Row (Status-centric; no ledger here) -->
  <div class="row g-2">
    <div class="col-6 col-lg-3">
      <div class="kpi-card kpi-total">
        <i class="bi bi-people-fill kpi-icon"></i>
        <div><p class="kpi-title">Total (Active scope)</p><h3 class="kpi-value"><?= nf($total_clients) ?></h3></div>
      </div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="kpi-card kpi-active">
        <i class="bi bi-check-circle-fill kpi-icon"></i>
        <div><p class="kpi-title">Active</p><h3 class="kpi-value"><?= nf($active_clients) ?></h3></div>
      </div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="kpi-card kpi-inact">
        <i class="bi bi-x-circle-fill kpi-icon"></i>
        <div><p class="kpi-title">Inactive</p><h3 class="kpi-value"><?= nf($inactive_clients) ?></h3></div>
      </div>
    </div>
    <div class="col-6 col-lg-3">
      <a href="/public/suspended_clients.php" class="text-decoration-none" style="color:inherit">
        <div class="kpi-card kpi-inact">
          <i class="bi bi-slash-circle-fill kpi-icon"></i>
          <div><p class="kpi-title">Auto Inactive</p><h3 class="kpi-value"><?= nf($auto_suspended) ?></h3></div>
        </div>
      </a>
    </div>
    <div class="col-6 col-lg-3">
      <div class="kpi-card kpi-exp">
        <i class="bi bi-exclamation-circle-fill kpi-icon"></i>
        <div><p class="kpi-title">Expired</p><h3 class="kpi-value"><?= nf($expired_clients) ?></h3></div>
      </div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="kpi-card kpi-pend">
        <i class="bi bi-hourglass-split kpi-icon"></i>
        <div><p class="kpi-title">Pending</p><h3 class="kpi-value"><?= nf($pending_clients) ?></h3></div>
      </div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="kpi-card kpi-left">
        <i class="bi bi-arrow-left-right kpi-icon"></i>
        <div><p class="kpi-title">Left</p><h3 class="kpi-value"><?= nf($left_clients) ?></h3></div>
      </div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="kpi-card kpi-online">
        <i class="bi bi-wifi kpi-icon"></i>
        <div><p class="kpi-title">Online</p><h3 class="kpi-value"><?= nf($total_online) ?></h3></div>
      </div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="kpi-card kpi-off">
        <i class="bi bi-wifi-off kpi-icon"></i>
        <div><p class="kpi-title">Offline</p><h3 class="kpi-value"><?= nf($total_offline) ?></h3></div>
      </div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="kpi-card kpi-dis">
        <i class="bi bi-slash-circle-fill kpi-icon"></i>
        <div><p class="kpi-title">Disabled</p><h3 class="kpi-value"><?= nf($total_disabled) ?></h3></div>
      </div>
    </div>
  </div>

 

  <!-- Secondary stats (glass cards) -->
  <div class="row g-2 mt-2">
    <div class="col-6 col-lg-3">
      <a class="text-decoration-none" href="/public/clients.php" style="color:inherit">
        <div class="stat-card">
          <i class="bi bi-people"></i>
          <div>
            <div class="small text-muted">Total Clients</div>
            <div class="fw-bold"><?= nf($total_clients) ?></div>
          </div>
        </div>
      </a>
    </div>
    <div class="col-6 col-lg-3">
      <a class="text-decoration-none" href="/public/client_list_by_status.php?status=active&amp;date=<?= urlencode($today) ?>" style="color:inherit">
        <div class="stat-card">
          <i class="bi bi-person-plus"></i>
          <div>
            <div class="small text-muted">Today's Joined</div>
            <div class="fw-bold"><?= nf($today_joined) ?></div>
          </div>
        </div>
      </a>
    </div>
    <div class="col-6 col-lg-3">
      <a class="text-decoration-none" href="/public/client_list_by_status.php?status=expired&amp;date=<?= urlencode($today) ?>" style="color:inherit">
        <div class="stat-card">
          <i class="bi bi-calendar-x"></i>
          <div>
            <div class="small text-muted">Today's Expired</div>
            <div class="fw-bold"><?= nf($today_expired) ?></div>
          </div>
        </div>
      </a>
    </div>
    <div class="col-6 col-lg-3">
      <a class="text-decoration-none" href="/public/client_list_by_status.php?status=inactive&amp;date=<?= urlencode($today) ?>" style="color:inherit">
        <div class="stat-card">
          <i class="bi bi-slash-circle"></i>
          <div>
            <div class="small text-muted">Today's Inactive</div>
            <div class="fw-bold"><?= nf($today_inactive) ?></div>
          </div>
        </div>
      </a>
    </div>
  </div>

  <?php if ($has_ledger_balance || $has_payments || $has_invoices): ?>
    <!-- Financial Summary (ledger + today's/month collection + invoices) -->
    <div class="mt-4">
      <span class="section-title mb-2 d-block">Financial Summary</span>
      <div class="row g-2">
        <?php if ($has_ledger_balance): ?>
          <div class="col-6 col-lg-3">
            <a class="text-decoration-none" href="/public/due_report.php" style="color:inherit">
              <div class="kpi-card kpi-due">
                <i class="bi bi-cash-coin kpi-icon"></i>
                <div>
                  <p class="kpi-title">Total Due (Tk)</p>
                  <h3 class="kpi-value"><?= nf($total_due) ?></h3>
                </div>
              </div>
            </a>
          </div>
          <div class="col-6 col-lg-3">
            <div class="kpi-card kpi-adv">
              <i class="bi bi-piggy-bank-fill kpi-icon"></i>
              <div>
                <p class="kpi-title">Total Advance (Tk)</p>
                <h3 class="kpi-value"><?= nf($total_adv) ?></h3>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($has_payments): ?>
          <!-- Bengali: Today's Collection আলাদা কার্ড -->
          <div class="col-6 col-lg-3">
            <div class="kpi-card kpi-active">
              <i class="bi bi-cash-stack kpi-icon"></i>
              <div>
                <p class="kpi-title">Today's Collection (Tk)</p>
                <h3 class="kpi-value"><?= nf($todays_collection) ?></h3>
              </div>
            </div>
          </div>
          <!-- Bengali: This Month Collection কার্ড -->
          <div class="col-6 col-lg-3">
            <div class="kpi-card kpi-active">
              <i class="bi bi-collection kpi-icon"></i>
              <div>
                <p class="kpi-title">This Month Collection (Tk)</p>
                <h3 class="kpi-value"><?= nf($this_month_collection) ?></h3>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($has_invoices): ?>
          <div class="col-6 col-lg-3">
            <a class="text-decoration-none" href="/public/invoices.php?status=unpaid" style="color:inherit">
              <div class="kpi-card kpi-inact">
                <i class="bi bi-exclamation-triangle-fill kpi-icon"></i>
                <div>
                  <p class="kpi-title">Unpaid / Partial Invoices</p>
                  <h3 class="kpi-value"><?= nf($unpaid_invoices) ?></h3>
                </div>
              </div>
            </a>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

</div><!-- /.dashboard-hero -->


</div>
    </div></div>
    </div></div>
    </div></div>
    </div></div>
    </div></div>
    </div>




<a href="https://info.flagcounter.com/HCBe"><img src="https://s01.flagcounter.com/count2/HCBe/bg_FFFFFF/txt_000000/border_CCCCCC/columns_2/maxflags_10/viewers_0/labels_0/pageviews_0/flags_0/percent_0/" alt="Flag Counter" border="0"></a>

<?php require __DIR__ . '/../partials/partials_footer.php'; ?>
