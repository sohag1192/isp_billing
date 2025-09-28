<?php
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$client_id = intval($_GET['id'] ?? 0);
if ($client_id <= 0) { header("Location: /public/clients.php"); exit; }

/* Load client basic info for display */
$stmt = db()->prepare("SELECT id, pppoe_id, name, client_code FROM clients WHERE id = ? LIMIT 1");
$stmt->execute([$client_id]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$client) { header("Location: /public/clients.php"); exit; }

$pppoe_id = trim((string)($client['pppoe_id'] ?? ''));
$display_id = $pppoe_id !== '' ? $pppoe_id : ('#'.$client_id); // fallback

$title = "Live Graph — " . ($pppoe_id !== '' ? $pppoe_id : "Client #{$client_id}");
include __DIR__ . '/../partials/partials_header.php';
?>
<style>
.container-slim{ max-width: 1024px; margin: 0 auto; }
.card-block{ border:1px solid #e5e7eb; border-radius:.75rem; background:#fff; }
.card-title{ font-weight:700; padding:.65rem .9rem; border-bottom:1px solid #eef1f4; background:#f8f9fa; }
/* আপনার বর্তমান (প্যাস্টেল) গ্রাফ স্টাইল */
#liveChart{
  width:100%; height:340px; display:block;
  background: linear-gradient(180deg, #ffffff 0%, #fafbfc 100%);
  border:1px solid #eaeef3; border-radius:12px;
  box-shadow: 0 6px 20px rgba(31, 41, 55, 0.06);
}
.legend{ display:inline-block; width:12px; height:12px; border-radius:2px; vertical-align:middle; margin-right:6px; }
.legend-rx{ background:#5b8def; } /* RX – প্যাস্টেল নীল */
.legend-tx{ background:#5ccc9a; } /* TX – প্যাস্টেল সবুজ */
</style>

<div class="container-fluid py-3 text-start">
  <div class="container-slim">
    <div class="d-flex align-items-center gap-2 mb-3">
      <a href="/public/clients.php" class="btn btn-light btn-sm"><i class="bi bi-arrow-left"></i> Back</a>
      <h5 class="mb-0">
        Live Graph for user
        <span class="text-muted">-<?= h($display_id) ?></span>
        <?php if (!empty($client['name'])): ?>
          <small class="text-muted"> (<?= h($client['name']) ?>)</small>
        <?php endif; ?>
      </h5>
      <div class="ms-auto d-flex gap-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnPauseChart"><i class="bi bi-pause-fill"></i> Pause</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnResumeChart" disabled><i class="bi bi-play-fill"></i> Resume</button>
        <button type="button" class="btn btn-outline-danger btn-sm" id="btnResetChart"><i class="bi bi-arrow-counterclockwise"></i> Reset</button>
      </div>
    </div>

    <div class="card-block">
      <div class="card-title">Live Rate</div>
      <div class="p-3">
        <div class="live-chart-wrap">
          <canvas id="liveChart" width="1024" height="340"></canvas>
          <div class="small mt-2 d-flex flex-wrap align-items-center gap-3">
            <span id="chartUnit" class="text-muted">Unit: Kbps</span>
            <span>
              <span class="legend legend-rx"></span> RX
              <span class="legend legend-tx ms-3"></span> TX
            </span>
            <span class="text-muted">Window: <span id="chartWindow">~2 মিনিট</span></span>
            <span class="text-muted">Polling: 1s</span>
          </div>
        </div>

        <div class="row g-2 mt-3">
          <div class="col-6">
            <div class="border rounded p-2">
              <div class="text-muted small">Latest RX</div>
              <div class="fs-5" id="rxLabel">0 Kbps</div>
            </div>
          </div>
          <div class="col-6">
            <div class="border rounded p-2">
              <div class="text-muted small">Latest TX</div>
              <div class="fs-5" id="txLabel">0 Kbps</div>
            </div>
			
			
			<div class="small mt-2 d-flex flex-wrap align-items-center gap-3">
  <span id="chartUnit" class="text-muted">Unit: Kbps</span>
  <span>
    <span class="legend legend-rx"></span> RX
    <span class="legend legend-tx ms-3"></span> TX
  </span>
  <span class="text-muted">Window: <span id="chartWindow">~2 মিনিট</span></span>
  <span class="text-muted">Polling: 1s</span>

  <!-- ⬇️ নতুন Peak labels -->
  <span class="ms-3">Peak RX: <b id="rxPeakLabel">0 Kbps</b></span>
  <span>Peak TX: <b id="txPeakLabel">0 Kbps</b></span>
</div>

          </div>
        </div>

      </div>
    </div>

  </div>
</div>

<script>
  // গ্রাফের কনফিগ: API এখনো client_id দিয়েই ডেটা নেয়, শুধু হেডারে PPPoE ID দেখাচ্ছে
  window.LIVE_GRAPH = {
    clientId: <?= (int)$client_id ?>,
    apiUrl: '/api/client_live_status.php',
    intervalMs: 1000,  // আপনি আগেই ১ সেকেন্ডে সেট করেছেন
    maxPoints: 120     // ~২ মিনিট (1s ইন্টারভাল)
  };
</script>
<script src="/assets/js/live_graph.js"></script>
<?php include __DIR__ . '/../partials/partials_footer.php'; ?>
