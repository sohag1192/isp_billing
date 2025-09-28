<?php
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

$recent = db()->query("
  SELECT c.id, c.name, c.pppoe_id, c.caller_mac, c.olt_port, c.olt_onu, c.last_linked_at,
         o.name AS olt_name
  FROM clients c
  LEFT JOIN olts o ON o.id = c.olt_id
  WHERE c.last_linked_at IS NOT NULL
  ORDER BY c.last_linked_at DESC
  LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);
?>
<?php require_once __DIR__ . '/../partials/partials_header.php'; ?>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Admin Tools</h5>
    <div class="d-flex gap-2">
      <a href="/public/olts.php" class="btn btn-light btn-sm"><i class="bi bi-hdd-network"></i> OLTs</a>
      <a href="/public/olt_mac_table.php" class="btn btn-light btn-sm"><i class="bi bi-table"></i> OLT MAC Table</a>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-md-4">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <h6 class="mb-2">Telnet Refresh</h6>
          <p class="text-muted small mb-3">সব Active OLT স্ক্যান করে সকল ONU-এর MAC নিয়ে cache আপডেট করবে।</p>
          <button id="btn-telnet" class="btn btn-warning w-100">
            <i class="bi bi-plug"></i> Run Telnet Refresh
          </button>
          <div class="form-text mt-2">লম্বা চলতে পারে—ফিনিশ হলে রিপোর্ট দেখাবে।</div>
        </div>
      </div>
    </div>

    <div class="col-md-4">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <h6 class="mb-2">Link PPPoE → ONU</h6>
          <p class="text-muted small mb-3">Active PPPoE ইউজারদের caller MAC ↔ MAC cache মিলিয়ে ক্লায়েন্টে OLT/ONU বসাবে।</p>
          <button id="btn-link" class="btn btn-primary w-100">
            <i class="bi bi-link-45deg"></i> Run Linker
          </button>
          <div class="form-text mt-2">MAC cache আপডেট করা থাকলে দ্রুত রেজাল্ট পাবেন।</div>
        </div>
      </div>
    </div>

    <div class="col-md-4">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <h6 class="mb-2">Purge Old Cache</h6>
          <p class="text-muted small mb-3">পুরোনো MAC cache এন্ট্রি মুছুন (ডিফল্ট ৭ দিন পুরোনো)।</p>
          <div class="input-group">
            <input type="number" id="days" class="form-control" value="7" min="1">
            <button id="btn-purge" class="btn btn-outline-danger"><i class="bi bi-trash"></i> Purge</button>
          </div>
          <div class="form-text mt-2">শুধু cache টেবিলে ইফেক্ট—ক্লায়েন্ট ডেটা অপরিবর্তিত।</div>
        </div>
      </div>
    </div>
  </div>

  <div class="card shadow-sm mt-4">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
          <thead class="table-light">
            <tr>
              <th>Linked At</th>
              <th>Client</th>
              <th>PPPoE</th>
              <th>MAC</th>
              <th>OLT</th>
              <th>PON/ONU</th>
              <th class="text-end">Tools</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($recent as $r): ?>
              <tr>
                <td><?=htmlspecialchars($r['last_linked_at'])?></td>
                <td>#<?=intval($r['id'])?> — <?=htmlspecialchars($r['name'])?></td>
                <td><?=htmlspecialchars($r['pppoe_id'])?></td>
                <td><code><?=htmlspecialchars($r['caller_mac'] ?: '-')?></code></td>
                <td><?=htmlspecialchars($r['olt_name'] ?: '-')?></td>
                <td><?=htmlspecialchars(($r['olt_port'] ?: '-') . ($r['olt_onu'] ? ' (ONU '.$r['olt_onu'].')' : ''))?></td>
                <td class="text-end">
                  <a class="btn btn-outline-warning btn-sm" href="/public/onu_tools.php?client_id=<?=intval($r['id'])?>">
                    <i class="bi bi-tools"></i> ONU Tools
                  </a>
                </td>
              </tr>
            <?php endforeach; if(empty($recent)): ?>
              <tr><td colspan="7" class="text-center text-muted py-4">এখনো কোনো লিংক রেকর্ড নেই</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
async function hit(url, body=null){
  const opt = body ? {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:new URLSearchParams(body)} : {};
  const r = await fetch(url, opt);
  return r.json();
}
document.getElementById('btn-telnet').addEventListener('click', async ()=>{
  Swal.fire({title:'Telnet Refreshing...', allowOutsideClick:false, didOpen:()=>Swal.showLoading()});
  try{
    const j = await hit('/api/olt_mac_refresh_telnet.php');
    Swal.close();
    if(!j.ok) return Swal.fire('ব্যর্থ', j.error||'Error', 'error');
    let per = '';
    if (j.per_olt) per = Object.entries(j.per_olt).map(([id,c])=>`OLT ${id}: ${c}`).join('\\n');
    Swal.fire('সম্পন্ন', `Seen: ${j.seen}\\nInserted: ${j.inserted}\\nUpdated: ${j.updated}\\n${per}`, 'success')
      .then(()=>location.reload());
  }catch(e){ Swal.fire('ত্রুটি','নেটওয়ার্ক/Telnet সমস্যা','error'); }
});

document.getElementById('btn-link').addEventListener('click', async ()=>{
  Swal.fire({title:'Linking PPPoE → ONU...', allowOutsideClick:false, didOpen:()=>Swal.showLoading()});
  try{
    const j = await hit('/api/link_all.php');
    Swal.close();
    if(!j.ok) return Swal.fire('ব্যর্থ', j.error||'Error', 'error');
    Swal.fire('সম্পন্ন', `Linked: ${j.linked||0}\\nMissed: ${j.missed||0}`, 'success')
      .then(()=>location.reload());
  }catch(e){ Swal.fire('ত্রুটি','লিঙ্কিং চলেনি','error'); }
});

document.getElementById('btn-purge').addEventListener('click', async ()=>{
  const days = document.getElementById('days').value || 7;
  Swal.fire({title:'Purging...', allowOutsideClick:false, didOpen:()=>Swal.showLoading()});
  try{
    const j = await hit('/api/olt_mac_cache_purge.php', {days});
    Swal.close();
    if(!j.ok) return Swal.fire('ব্যর্থ', j.error||'Error', 'error');
    Swal.fire('সম্পন্ন', `Deleted: ${j.deleted} (older than ${j.days} days)`, 'success')
      .then(()=>location.reload());
  }catch(e){ Swal.fire('ত্রুটি','পূর্জ করতে সমস্যা','error'); }
});
</script>
<?php require_once __DIR__ . '/../partials/partials_footer.php'; ?>
