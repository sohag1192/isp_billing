<?php
/**
 * /public/router_mac_audit.php
 * UI for PPPoE vs Router MAC audit (uses /api/router_mac_audit.php)
 */
require_once __DIR__ . '/../app/require_login.php';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Router MAC Audit</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <style>
    .status-OK{ background:#e9f7ef; }
    .status-MISMATCH{ background:#fdecea; }
    .status-MISSING_EXPECTED, .status-MISSING_LIVE, .status-BOTH_MAC_MISSING{ background:#fff3cd; }
    .status-UNKNOWN_SESSION{ background:#e8eaf6; }
    .status-ROUTER_UNREACHABLE{ background:#f8d7da; }
    .table-tight td, .table-tight th { padding:.5rem; vertical-align:middle; }
    code { font-size:.875rem; }
  </style>
</head>
<body class="bg-light">
<div class="container py-3">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0"><i class="bi bi-hdd-network"></i> Router MAC Audit</h4>
    <div class="d-flex gap-2">
      <a href="#" class="btn btn-sm btn-outline-secondary" id="refreshBtn"><i class="bi bi-arrow-repeat"></i> Refresh</a>
      <a class="btn btn-sm btn-outline-primary" href="/api/router_mac_audit.php" target="_blank"><i class="bi bi-braces"></i> Raw JSON</a>
    </div>
  </div>

  <form class="row g-2 align-items-end mb-3" id="filterForm">
    <div class="col-auto">
      <label class="form-label mb-0">Router ID</label>
      <input type="number" class="form-control" name="router_id" placeholder="optional">
    </div>
    <div class="col-auto">
      <label class="form-label mb-0">Show</label>
      <select class="form-select" name="show">
        <option value="all">All</option>
        <option value="mismatch">Mismatch</option>
        <option value="missing">Missing</option>
        <option value="unknown">Unknown</option>
      </select>
    </div>
    <div class="col-auto form-check mt-4">
      <input class="form-check-input" type="checkbox" value="1" id="include_left" name="include_left">
      <label class="form-check-label" for="include_left">Include left clients</label>
    </div>
    <div class="col-auto">
      <button class="btn btn-primary"><i class="bi bi-search"></i> Run</button>
    </div>
  </form>

  <div id="summary" class="mb-2 small text-muted"></div>

  <div class="table-responsive">
    <table class="table table-striped table-bordered table-tight bg-white" id="resultTable">
      <thead class="table-light">
        <tr>
          <th>Router</th>
          <th>PPPoE</th>
          <th>Client</th>
          <th>Expected MAC</th>
          <th>Live MAC</th>
          <th>Caller-ID</th>
          <th>IP</th>
          <th>Status</th>
          <th>Note</th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>
  </div>
</div>

<script>
const form = document.getElementById('filterForm');
const tbody = document.querySelector('#resultTable tbody');
const summary = document.getElementById('summary');

document.getElementById('refreshBtn').addEventListener('click', (e)=>{ e.preventDefault(); form.requestSubmit(); });

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(form);
  const qs = new URLSearchParams(fd).toString();
  tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">Loading…</td></tr>';
  try{
    const res = await fetch('/api/router_mac_audit.php?' + qs, {cache:'no-store'});
    const j = await res.json();
    const s = j.summary || {};
    summary.textContent = `Routers: ${j.router_count} | Live sessions: ${j.session_count} | OK: ${s.OK||0}, Mismatch: ${s.MISMATCH||0}, Missing: ${(s.MISSING_EXPECTED||0)+(s.MISSING_LIVE||0)+(s.BOTH_MAC_MISSING||0)}, Unknown: ${s.UNKNOWN_SESSION||0}, Unreachable: ${s.ROUTER_UNREACHABLE||0}`;

    const rows = j.rows || [];
    if(!rows.length){
      tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">No data.</td></tr>';
      return;
    }
    tbody.innerHTML = rows.map(r=>{
      const cls = 'status-' + (r.status || 'OK');
      return `<tr class="${cls}">
        <td>${escapeHtml(r.router_name || '')} <span class="text-muted">#${r.router_id ?? ''}</span></td>
        <td>${escapeHtml(r.pppoe_id ?? '')}</td>
        <td>${escapeHtml(r.client_name || '')} ${r.client_id?'<span class="text-muted">#'+r.client_id+'</span>':''}</td>
        <td><code>${escapeHtml(r.expected_mac || '')}</code></td>
        <td><code>${escapeHtml(r.live_mac || '')}</code></td>
        <td>${escapeHtml(r.caller_id || '')}</td>
        <td>${escapeHtml(r.address || '')}</td>
        <td><span class="badge bg-dark-subtle text-dark">${escapeHtml(r.status || '')}</span></td>
        <td>${escapeHtml(r.note || '')}</td>
      </tr>`;
    }).join('');
  }catch(err){
    tbody.innerHTML = '<tr><td colspan="9" class="text-danger text-center py-4">Failed to load</td></tr>';
  }
});

function escapeHtml(s){ return (s||'').toString().replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m])); }

// auto-run on load
form.requestSubmit();
</script>
</body>
</html>
