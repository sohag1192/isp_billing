<?php
// /cron/auto_billing.php
// -------------------------------------------------------
// Auto Billing (প্রতি মাসে ইনভয়েস জেনারেট; replace-safe)
// Rules: No renew; শুধুই bill generate + payment/due.
// Timezone: Asia/Dhaka
// Safety: file lock + already-generated check + logging + date guard
// Usage (CLI/HTTP):
//   php /path/to/cron/auto_billing.php
//   php /path/to/cron/auto_billing.php 2025-09        (backfill specific month)
//   php /path/to/cron/auto_billing.php --force        (ignore 1st-day guard)
//   php /path/to/cron/auto_billing.php --dry          (preview; no write)
//   GET /cron/auto_billing.php?month=YYYY-MM          (backfill specific month)
//   GET /cron/auto_billing.php?force=1|0&dry=1|0
// -------------------------------------------------------

declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);

$ROOT = dirname(__DIR__);
require_once $ROOT . '/app/db.php'; // PDO db() expected

// ==== Config ====
// (বাংলা) লগ ফাইল কোথায় যাবে (ওয়েব/ক্রন ইউজারের রাইট পারমিশন লাগবে)
$LOG_DIR  = $ROOT . '/storage/logs';
$LOG_FILE = $LOG_DIR . '/auto_billing.log';

// (বাংলা) লক ফাইল—ডবল রান আটকাবে (ক্রন overlap হলে)
$LOCK_DIR = $ROOT . '/storage/locks';
$LOCK_FILE= $LOCK_DIR . '/auto_billing.lock';

// (বাংলা) টাইমজোন সেট—ডিফল্ট ধরা হলো Asia/Dhaka
date_default_timezone_set('Asia/Dhaka');

// ==== Helpers ====
function log_line(string $msg): void {
  global $LOG_DIR, $LOG_FILE;
  if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0775, true);
  $ts = date('Y-m-d H:i:s');
  @file_put_contents($LOG_FILE, "[$ts] $msg\n", FILE_APPEND);
}

function with_lock(string $lockFile, callable $fn) {
  $dir = dirname($lockFile);
  if (!is_dir($dir)) @mkdir($dir, 0775, true);

  $fp = fopen($lockFile, 'c');
  if (!$fp) throw new RuntimeException("Cannot open lock: $lockFile");

  // (বাংলা) non-blocking—ব্যর্থ মানে অন্য রান চলছে
  if (!flock($fp, LOCK_EX | LOCK_NB)) {
    throw new RuntimeException("Another auto_billing run is in progress");
  }
  try {
    $result = $fn();
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $result;
  } catch (Throwable $e) {
    try { flock($fp, LOCK_UN); fclose($fp); } catch(Throwable $ee){}
    throw $e;
  }
}

/** (বাংলা) CLI ফ্ল্যাগ পার্সার: --force / --dry */
function cli_has_flag(string $flag): bool {
  global $argv;
  if (PHP_SAPI !== 'cli') return false;
  foreach ($argv as $a) {
    if ($a === $flag) return true;
  }
  return false;
}

/**
 * (বাংলা) কোন মাসের বিলিং হবে ঠিক করি:
 *  - CLI arg বা ?month=YYYY-MM থাকলে সেটি
 *  - না থাকলে current YYYY-MM
 */
function resolve_billing_month(): string {
  global $argv;
  if (PHP_SAPI === 'cli') {
    // e.g., php auto_billing.php 2025-09
    if (!empty($argv[1]) && preg_match('/^\d{4}-\d{2}$/', $argv[1])) {
      return $argv[1];
    }
  }
  if (!empty($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month'])) {
    return $_GET['month'];
  }
  return date('Y-m');
}

/** (বাংলা) শুধুমাত্র ১ তারিখে রান—backfill (month param) বা force হলে ছাড়। */
function must_run_today(string $ym, bool $is_force): bool {
  $today_is_first = ((int)date('d') === 1);
  $has_explicit_month = $ym !== date('Y-m'); // backfill
  // (বাংলা) যদি ব্যাকফিল/ফোর্স না হয়, তবে ১ তারিখেই চলবে
  if (!$today_is_first && !$is_force && !$has_explicit_month) {
    return false;
  }
  return true;
}

/**
 * (বাংলা) এই মাসের non-void ইনভয়েস আছে কি না—ডবল জেন এড়াই।
 * স্মার্ট fallback: billing_month (DATE) থাকলে সেটি, নাহলে invoice_date, নাহলে created_at।
 */
function invoices_already_generated(PDO $pdo, string $ym): bool {
  [$mStart, $mEnd] = [ $ym.'-01', date('Y-m-t', strtotime($ym.'-01')) ];

  // 1) billing_month (DATE) + is_void
  try {
    $q = "SELECT COUNT(*) FROM invoices
          WHERE DATE_FORMAT(billing_month,'%Y-%m') = :m
            AND COALESCE(is_void,0)=0";
    $st = $pdo->prepare($q);
    $st->execute([':m'=>$ym]);
    if ((int)$st->fetchColumn() > 0) return true;
  } catch (Throwable $e) { /* fallback */ }

  // 1b) billing_month + status!='void'
  try {
    $q = "SELECT COUNT(*) FROM invoices
          WHERE DATE_FORMAT(billing_month,'%Y-%m') = :m
            AND COALESCE(status,'') <> 'void'";
    $st = $pdo->prepare($q);
    $st->execute([':m'=>$ym]);
    if ((int)$st->fetchColumn() > 0) return true;
  } catch (Throwable $e) { /* fallback */ }

  // 2) invoice_date range + is_void
  try {
    $q = "SELECT COUNT(*) FROM invoices
          WHERE DATE(invoice_date) BETWEEN :s AND :e
            AND COALESCE(is_void,0)=0";
    $st = $pdo->prepare($q);
    $st->execute([':s'=>$mStart, ':e'=>$mEnd]);
    if ((int)$st->fetchColumn() > 0) return true;
  } catch (Throwable $e) { /* fallback */ }

  // 2b) invoice_date range + status!='void'
  try {
    $q = "SELECT COUNT(*) FROM invoices
          WHERE DATE(invoice_date) BETWEEN :s AND :e
            AND COALESCE(status,'') <> 'void'";
    $st = $pdo->prepare($q);
    $st->execute([':s'=>$mStart, ':e'=>$mEnd]);
    if ((int)$st->fetchColumn() > 0) return true;
  } catch (Throwable $e) { /* fallback */ }

  // 3) created_at range (last resort)
  try {
    $q = "SELECT COUNT(*) FROM invoices
          WHERE DATE(created_at) BETWEEN :s AND :e
            AND (COALESCE(is_void,0)=0 OR COALESCE(status,'') <> 'void')";
    $st = $pdo->prepare($q);
    $st->execute([':s'=>$mStart, ':e'=>$mEnd]);
    if ((int)$st->fetchColumn() > 0) return true;
  } catch (Throwable $e) {
    log_line("WARN already_generated check fell through: ".$e->getMessage());
  }

  return false;
}

/**
 * (বাংলা) ইনভয়েস জেন—বিদ্যমান public script (commit mode) include করি
 * invoice_generate.php নিজেই ledger += total / replace-safe হ্যান্ডেল করে।
 */
function run_generation_via_include(string $month, array $opts = []): void {
  $backup = $_GET; // (বাংলা) পরে restore করব

  // Bengali: internal-call flag (invoice_generate.php তে auth bypass)
  if (!defined('APP_INTERNAL_CALL')) define('APP_INTERNAL_CALL', true);

  // Bengali: desired flags (commit=1, replace=1 ডিফল্ট)
  $_GET['month']   = $month;
  $_GET['commit']  = (string)($opts['commit']  ?? '1');
  $_GET['replace'] = (string)($opts['replace'] ?? '1');

  // optional passthrough
  if (isset($opts['prorate']))          $_GET['prorate']          = (string)$opts['prorate'];
  if (isset($opts['router_id']))        $_GET['router_id']        = (int)$opts['router_id'];
  if (isset($opts['active_only']))      $_GET['active_only']      = (string)$opts['active_only'];
  if (isset($opts['include_disabled'])) $_GET['include_disabled'] = (string)$opts['include_disabled'];
  if (isset($opts['include_left']))     $_GET['include_left']     = (string)$opts['include_left'];

  ob_start();
  $script = __DIR__ . '/../public/invoice_generate.php';
  if (!is_file($script)) {
    throw new RuntimeException("invoice_generate.php not found at $script");
  }
  include $script;
  $out = ob_get_clean();

  // (বাংলা) include শেষে GET restore করি—global pollution রোধে
  $_GET = $backup;

  // (বাংলা) আউটপুট সংক্ষেপে লগে রাখি (ডিবাগিংয়ের জন্য)
  if ($out !== null) {
    $clip = mb_substr(trim(strip_tags($out)), 0, 600);
    if ($clip !== '') log_line("invoice_generate output: " . $clip);
  }
}

// ==== Main ====
try {
  with_lock($LOCK_FILE, function () {
    $pdo    = db();
    $ym     = resolve_billing_month();

    // Flags
    $force  = (PHP_SAPI === 'cli' && cli_has_flag('--force')) ? 1 : (int)($_GET['force'] ?? 0);
    $is_dry = (PHP_SAPI === 'cli' && cli_has_flag('--dry'))   ? 1 : (int)($_GET['dry'] ?? 0);

    log_line("== Auto billing start for {$ym} (force={$force}, dry={$is_dry}) ==");

    // (বাংলা) ১ তারিখ গার্ড (ব্যাকফিল বা force হলে ছাড়)
    if (!must_run_today($ym, (bool)$force)) {
      log_line("Skip: today is not 1st and no force/backfill (ym={$ym})");
      echo "Skip: today is not 1st; use ?force=1 or pass a month/--force for backfill.\n";
      return;
    }

    // (বাংলা) ডুপ্লিকেট গার্ড
    if (invoices_already_generated($pdo, $ym) && !$force) {
      log_line("Skip: invoices already exist (non-void) for {$ym}");
      echo "Skip: invoices already exist for {$ym}\n";
      return;
    }

    // Bengali: রান করাও (commit+replace ডিফল্ট অন; dry হলে commit=0)
    run_generation_via_include($ym, [
      'commit'  => $is_dry ? 0 : 1,
      'replace' => 1,
      // 'prorate' => 0,
      // 'router_id' => 0,
      // 'active_only' => 0,
      // 'include_disabled' => 0,
      // 'include_left' => 0,
    ]);

    // post-check (optional, সঠিক কাউন্ট নিয়ে লগ)
    try {
      $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM invoices
        WHERE DATE_FORMAT(COALESCE(billing_month, invoice_date, created_at),'%Y-%m') = :m
      ");
      $stmt->execute([':m'=>$ym]);
      $cnt = (int)$stmt->fetchColumn();
      log_line("Done: generated for {$ym}, total rows now={$cnt}");
    } catch (Throwable $e) {
      log_line("Post-check failed: ".$e->getMessage());
    }

    echo ($is_dry ? "DRY: " : "") . "OK: auto billing completed for {$ym}\n";
  });
} catch (Throwable $e) {
  log_line("ERROR: " . $e->getMessage());
  http_response_code(500);
  echo "ERROR: " . $e->getMessage() . "\n";
  exit(1);
}
