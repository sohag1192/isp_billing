<?php
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$q      = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = max(10, min(100, (int)($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

/* WHERE */
$where = ['1=1'];
$params = [];
if ($q !== '') {
  $where[] = '(client_code LIKE ? OR name LIKE ? OR pppoe_id LIKE ? OR mobile LIKE ? OR status LIKE ? OR original_id = ?)';
  $like = "%$q%";
  array_push($params, $like, $like, $like, $like, $like, (ctype_digit($q)?(int)$q:0));
}
$where_sql = implode(' AND ', $where);

/* COUNT */
$stc = db()->prepare("SELECT COUNT(*) FROM deleted_clients WHERE $where_sql");
$stc->execute($params);
$total = (int)$stc->fetchColumn();
$total_pages = max(1, (int)ceil($total / $limit));

/* FETCH */
$sql = "SELECT id, original_id, client_code, name, pppoe_id, mobile, status, package_id, router_id, deleted_at, deleted_by, data_json
        FROM deleted_clients
        WHERE $where_sql
        ORDER BY id DESC
        LIMIT $limit OFFSET $offset";
$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<title>Deleted Clients (Archive)</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="../assets/css/style.css">
<style>
:root{ --card-pad:.85rem; --br:12px; }
.card-soft{ border:1px solid #eef0f2; border-radius:var(--br); background:#fff; box-shadow:0 6px 20px rgba(0,0,0,.06); }
.card-soft .card-title{ font-weight:700; padding:var(--card-pad); border-bottom:1px solid #f1f3f5; background:linear-gradient(180deg,#fafbfc,#f7f8fa); }
.table-compact>:not(caption)>*>*{ padding:.45rem .6rem; }
.table-compact th,.table-compact td{ vertical-align:middle; }
th.sticky{ position:sticky; top:0; z-index:1; }
.col-action{ width:190px; white-space:nowrap; }
.meta-mini{ color:#6c757d; font-size:.9rem; }
.badge-status.inactive{ background:#dc3545; }
.badge-status.pending { background:#ffc107; color:#111; }
.badge-status.active  { background:#198754; }

.app-toast{
  position:fixed; left:50%; top:50%; transform:translate(-50%,-50%);
  z-index:9999; border:1px solid #cfd8e3; border-radius:12px;
  box-shadow:0 12px 30px rgba(0,0,0,.18); padding:12px 16px; min-width:260px; text-align:center;
  transition:opacity .25s, transform .25s; background:#fff;
}
.app-toast.success{ background:#e9f7ef; color:#155724; border-color:#c3e6cb; }
.app-toast.error  { background:#fdecea; color:#721c24; border-color:#f5c6cb; }
.app-toast.hide   { opacity:0; transform:translate(-50%,-60%); }

/* Modal */
#snapModal .modal-dialog{ max-width: 760px; }
#snapJson{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; 
           font-size: 13px; white-space: pre-wrap; background: #f8fafc; border:1px solid #eef2f7; border-radius: 8px; padding:10px; }
</style>
</head>
<body>
<?php include __DIR__ . '/../partials/partials_header.php'; ?>

<div class="main-content p-4">

  <div class="d-flex align-items-center flex-wrap gap-2 mb-3">
    <h5 class="mb-0"><i class="bi bi-archive"></i> Deleted Clients (Archive)</h5>
    <span class="badge bg-secondary ms-2"><?= (int)$total ?></span>
    <div class="ms-auto"></div>
  </div>

  <!-- Filters -->
  <form class="card card-soft mb-3" method="get" action="">
    <div class="card-body">
      <div class="row g-2 align-items-end">
        <div class="col-12 col-sm-6 col-lg-4">
          <label class="form-label">সার্চ</label>
          <input name="q" value="<?= h($q) ?>" class="form-control form-control-sm"
                 placeholder="Name / PPPoE / Client Code / Mobile / Status / Original ID">
        </div>
        <div class="col-6 col-sm-3 col-lg-2">
          <label class="form-label">প্রতি পেজ</label>
          <select name="limit" class="form-select form-select-sm">
            <?php foreach([10,20,30,50,100] as $L): ?>
              <option value="<?= $L ?>" <?= $limit==$L?'selected':'' ?>><?= $L ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-sm-3 col-lg-2 d-grid">
          <button class="btn btn-dark btn-sm"><i class="bi bi-search"></i> ফিল্টার</button>
        </div>
      </div>
    </div>
  </form>

  <!-- Bulk bar -->
  <div class="d-flex align-items-center gap-2 mb-2">
    <input type="checkbox" id="select-all">
    <label for="select-all" class="me-2 mb-0">সব সিলেক্ট</label>

    <div class="btn-group">
      <button id="bulk-restore" class="btn btn-success btn-sm" disabled>
        <i class="bi bi-arrow-counterclockwise"></i> Bulk Restore
      </button>
      <button type="button" class="btn btn-success btn-sm dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false"></button>
      <ul class="dropdown-menu">
        <li><a class="dropdown-item" href="#" id="bulk-restore-inactive">Restore as Inactive</a></li>
      </ul>
    </div>

    <button id="bulk-purge" class="btn btn-danger btn-sm" disabled>
      <i class="bi bi-trash"></i> Bulk Purge
    </button>

    <span class="text-muted small" id="sel-counter">(0 selected)</span>
  </div>

  <!-- Table -->
  <div class="card card-soft">
    <div class="card-title d-flex align-items-center justify-content-between">
      <span><i class="bi bi-list-check"></i> Archive List</span>
      <a href="/public/clients.php" class="btn btn-light btn-sm"><i class="bi bi-arrow-left"></i> Back</a>
    </div>

    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover table-compact align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th class="sticky" style="width:38px;"><input type="checkbox" id="select-all-2"></th>
              <th class="sticky">#</th>
              <th class="sticky d-none d-sm-table-cell">Original ID</th>
              <th class="sticky">Client Code</th>
              <th class="sticky">Name</th>
              <th class="sticky d-none d-md-table-cell">PPPoE</th>
              <th class="sticky">Mobile</th>
              <th class="sticky d-none d-lg-table-cell">Status</th>
              <th class="sticky d-none d-lg-table-cell">Deleted At</th>
              <th class="sticky d-none d-xl-table-cell">Deleted By</th>
              <th class="sticky text-end col-action">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($rows): foreach($rows as $r): 
              $status = strtolower($r['status'] ?? '');
              $badgeClass = 'badge-status active';
              if ($status==='inactive') $badgeClass='badge-status inactive';
              elseif ($status==='pending') $badgeClass='badge-status pending';
          ?>
            <tr>
              <td><input type="checkbox" class="row-check" value="<?= (int)$r['id'] ?>"></td>
              <td><?= (int)$r['id'] ?></td>
              <td class="d-none d-sm-table-cell"><?= (int)$r['original_id'] ?></td>
              <td><?= h($r['client_code'] ?? '') ?></td>
              <td>
                <div class="fw-semibold text-truncate" style="max-width:220px;"><?= h($r['name'] ?? '') ?></div>
                <div class="meta-mini d-sm-none">PPPoE: <?= h($r['pppoe_id'] ?? '') ?></div>
              </td>
              <td class="d-none d-md-table-cell"><?= h($r['pppoe_id'] ?? '') ?></td>
              <td><a class="text-decoration-none" href="tel:<?= h($r['mobile'] ?? '') ?>"><?= h($r['mobile'] ?? '') ?></a></td>
              <td class="d-none d-lg-table-cell"><span class="badge <?= $badgeClass ?>"><?= $r['status'] ? ucfirst($r['status']) : '—' ?></span></td>
              <td class="d-none d-lg-table-cell"><?= h($r['deleted_at']) ?></td>
              <td class="d-none d-xl-table-cell"><?= h($r['deleted_by'] ?? '') ?></td>
              <td class="text-end">
                <div class="btn-group btn-group-sm" role="group">
                  <!-- snapshot -->
                  <button class="btn btn-outline-primary" title="View Snapshot" onclick="openSnapshot(<?= (int)$r['id'] ?>)"><i class="bi bi-eye"></i></button>
                  <!-- restore dropdown (default & as inactive) -->
                  <div class="btn-group">
                    <button class="btn btn-outline-success" title="Restore" onclick="restoreClient(<?= (int)$r['id'] ?>, this)"><i class="bi bi-arrow-counterclockwise"></i></button>
                    <button type="button" class="btn btn-outline-success dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown"></button>
                    <ul class="dropdown-menu">
                      <li><a class="dropdown-item" href="#" onclick="restoreClient(<?= (int)$r['id'] ?>, this, 'inactive'); return false;">Restore as Inactive</a></li>
                    </ul>
                  </div>
                  <!-- purge -->
                  <button class="btn btn-outline-danger" title="Purge" onclick="purgeArchive(<?= (int)$r['id'] ?>, this)"><i class="bi bi-trash"></i></button>
                </div>
                <!-- keep snapshot JSON in DOM for modal -->
                <script type="application/json" id="snap-<?= (int)$r['id'] ?>"><?= h($r['data_json']) ?></script>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="12" class="text-center text-muted py-4">কিছু পাওয়া যায়নি</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Pagination -->
  <?php if($total_pages>1): ?>
    <nav class="mt-3">
      <ul class="pagination pagination-sm justify-content-center">
        <?php
          $qsPrev = $_GET; $qsPrev['page'] = max(1,$page-1);
          $qsNext = $_GET; $qsNext['page'] = min($total_pages,$page+1);
        ?>
        <li class="page-item <?= $page<=1?'disabled':'' ?>">
          <a class="page-link" href="?<?= http_build_query($qsPrev) ?>">Previous</a>
        </li>
        <?php
          $start = max(1,$page-2); $end=min($total_pages,$page+2);
          if(($end-$start)<4){ $end=min($total_pages,$start+4); $start=max(1,$end-4); }
          for($i=$start;$i<=$end;$i++):
            $qsi = $_GET; $qsi['page']=$i; ?>
            <li class="page-item <?= $i==$page?'active':'' ?>">
              <a class="page-link" href="?<?= http_build_query($qsi) ?>"><?= $i ?></a>
            </li>
        <?php endfor; ?>
        <li class="page-item <?= $page>=$total_pages?'disabled':'' ?>">
          <a class="page-link" href="?<?= http_build_query($qsNext) ?>">Next</a>
        </li>
      </ul>
    </nav>
  <?php endif; ?>

</div>

<!-- Snapshot Modal -->
<div class="modal fade" id="snapModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title"><i class="bi bi-filetype-json"></i> Client Snapshot (JSON)</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body"><pre id="snapJson">{}</pre></div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../partials/partials_footer.php'; ?>











<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
<script>
const API_RESTORE      = '/api/client_restore.php';
const API_PURGE        = '/api/deleted_client_purge.php';
const API_BULK_RESTORE = '/api/client_restore_bulk.php';
const API_BULK_PURGE   = '/api/deleted_client_purge_bulk.php';

function showToast(msg, type='success', ms=3000){
  const t=document.createElement('div');
  t.className='app-toast '+(type==='success'?'success':'error');
  t.textContent=msg||'Done';
  document.body.appendChild(t);
  setTimeout(()=> t.classList.add('hide'), ms-250);
  setTimeout(()=> t.remove(), ms);
}

// ---- fetch helper: JSON try → fallback to text ----
async function safeFetch(url, opts){
  const res = await fetch(url, opts);
  const txt = await res.text();
  try{ return { ok: res.ok, json: JSON.parse(txt), raw: txt }; }
  catch{ return { ok: res.ok, json: null, raw: txt }; }
}

/* Snapshot modal */
let snapModal;
document.addEventListener('DOMContentLoaded', ()=>{ snapModal = new bootstrap.Modal(document.getElementById('snapModal')); });
function openSnapshot(id){
  const el = document.getElementById('snap-'+id);
  let data = el ? el.textContent : '{}';
  try{ data = JSON.stringify(JSON.parse(data), null, 2); }catch(e){}
  document.getElementById('snapJson').textContent = data || '{}';
  snapModal.show();
}

/* Selection */
const setSel = new Set();
const boxAll  = document.getElementById('select-all');
const boxAll2 = document.getElementById('select-all-2');
const boxes   = () => Array.from(document.querySelectorAll('.row-check'));
const btnBR   = document.getElementById('bulk-restore');
const btnBRi  = document.getElementById('bulk-restore-inactive');
const btnBP   = document.getElementById('bulk-purge');
const counter = document.getElementById('sel-counter');

function syncSel(){
  const ids = boxes().map(b=>b.value);
  for (const v of Array.from(setSel)) if(!ids.includes(v)) setSel.delete(v);
  const n=setSel.size; counter.textContent=`(${n} selected)`;
  const dis=(n===0); [btnBR,btnBP].forEach(b=> b.disabled=dis);
  const allLen = boxes().length;
  const setTri = (el)=>{
    if(!el) return;
    if(n===0){ el.indeterminate=false; el.checked=false; }
    else if(n===allLen){ el.indeterminate=false; el.checked=true; }
    else { el.indeterminate=true; el.checked=false; }
  };
  setTri(boxAll); setTri(boxAll2);
}
function bindRowChecks(){ boxes().forEach(b=> b.addEventListener('change', ()=>{ if(b.checked) setSel.add(b.value); else setSel.delete(b.value); syncSel(); })); }
bindRowChecks(); syncSel();
[boxAll, boxAll2].forEach(el=>{
  el?.addEventListener('change', ()=>{
    const c=el.checked; boxes().forEach(b=>{ b.checked=c; if(c) setSel.add(b.value); else setSel.delete(b.value); });
    (el===boxAll?boxAll2:boxAll).checked=c; syncSel();
  });
});

/* Single restore/purge */
async function restoreClient(archiveId, btn, forceStatus){
  if(!archiveId) return;
  if(!confirm(forceStatus==='inactive'?'Restore as INACTIVE?':'Restore করবেন?')) return;
  if(btn) btn.disabled = true;

  try{
    const { ok, json, raw } = await safeFetch(API_RESTORE, {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ id: archiveId, status: forceStatus||'' })
    });
    if(ok && json && json.status==='success'){
      showToast(json.message || 'Restored', 'success');
      const tr = btn ? btn.closest('tr') : null; if(tr) tr.remove();
      const badge = document.querySelector('.badge.bg-secondary');
      if (badge) { const n = parseInt(badge.textContent||'0',10); if(!isNaN(n)&&n>0) badge.textContent = (n-1); }
      syncSel();
    } else {
      showToast((json && json.message) || raw || 'Restore failed', 'error');
      if(btn) btn.disabled = false;
    }
  }catch(e){ showToast('Network error', 'error'); if(btn) btn.disabled = false; }
}

async function purgeArchive(archiveId, btn){
  if(!archiveId) return;
  if(!confirm('স্থায়ীভাবে ডিলিট করবেন? (Archive থেকেও মুছে যাবে)')) return;
  if(btn) btn.disabled = true;

  try{
    const { ok, json, raw } = await safeFetch(API_PURGE, {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ id: archiveId })
    });
    if(ok && json && json.status==='success'){
      showToast(json.message || 'Purged', 'success');
      const tr = btn ? btn.closest('tr') : null; if(tr) tr.remove();
      const badge = document.querySelector('.badge.bg-secondary');
      if (badge) { const n = parseInt(badge.textContent||'0',10); if(!isNaN(n)&&n>0) badge.textContent = (n-1); }
      syncSel();
    } else {
      showToast((json && json.message) || raw || 'Purge failed', 'error');
      if(btn) btn.disabled = false;
    }
  }catch(e){ showToast('Network error', 'error'); if(btn) btn.disabled = false; }
}

/* Bulk handlers */
async function bulkRestore(forceStatus){
  const ids = Array.from(setSel).map(v=>parseInt(v,10)).filter(Boolean);
  if(!ids.length) return;
  if(!confirm(forceStatus==='inactive'
      ? `Restore as INACTIVE — ${ids.length} টি?`
      : `Bulk Restore — ${ids.length} টি?`)) return;

  try{
    const { ok, json, raw } = await safeFetch(API_BULK_RESTORE, {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ ids, status: forceStatus||'' })
    });
    if(ok && json && json.status==='success'){
      showToast(json.message || `Restored: ${json.succeeded||ids.length}/${json.processed||ids.length}`, json.failed?'error':'success');
      boxes().forEach(b=>{ if(ids.includes(parseInt(b.value,10))){ const tr=b.closest('tr'); if(tr) tr.remove(); } });
      setSel.clear(); syncSel();
      const badge = document.querySelector('.badge.bg-secondary');
      if (badge) {
        const n = parseInt(badge.textContent||'0',10);
        const newN = Math.max(0, n - (json.succeeded || ids.length));
        badge.textContent = newN;
      }
    } else {
      showToast((json && json.message) || raw || 'Bulk restore failed', 'error');
    }
  }catch(e){ showToast('Network error', 'error'); }
}

async function bulkPurge(){
  const ids = Array.from(setSel).map(v=>parseInt(v,10)).filter(Boolean);
  if(!ids.length) return;
  if(!confirm(`Bulk Purge — ${ids.length} টি? (স্থায়ীভাবে মুছে যাবে)`)) return;

  try{
    const { ok, json, raw } = await safeFetch(API_BULK_PURGE, {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ ids })
    });
    if(ok && json && json.status==='success'){
      showToast(json.message || `Purged: ${json.succeeded||ids.length}/${json.processed||ids.length}`, json.failed?'error':'success');
      boxes().forEach(b=>{ if(ids.includes(parseInt(b.value,10))){ const tr=b.closest('tr'); if(tr) tr.remove(); } });
      setSel.clear(); syncSel();
      const badge = document.querySelector('.badge.bg-secondary');
      if (badge) {
        const n = parseInt(badge.textContent||'0',10);
        const newN = Math.max(0, n - (json.succeeded || ids.length));
        badge.textContent = newN;
      }
    } else {
      showToast((json && json.message) || raw || 'Bulk purge failed', 'error');
    }
  }catch(e){ showToast('Network error', 'error'); }
}

document.getElementById('bulk-restore')?.addEventListener('click', ()=> bulkRestore(''));
document.getElementById('bulk-restore-inactive')?.addEventListener('click', (e)=>{ e.preventDefault(); bulkRestore('inactive'); });
document.getElementById('bulk-purge')?.addEventListener('click', bulkPurge);
</script>





















</body>
</html>
