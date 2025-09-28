<?php
// /public/packages.php
// (বাংলা) Packages: list + search + sort + pagination + add/edit + delete + import + export
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

/* ------- Inputs ------- */
$search = trim($_GET['search'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = max(10, (int)($_GET['limit'] ?? 20));
$offset = ($page - 1) * $limit;

$sort = strtolower($_GET['sort'] ?? 'name');            // name | price | id
$dir  = strtolower($_GET['dir']  ?? 'asc');             // asc | desc
$dir  = in_array($dir, ['asc','desc'], true) ? $dir : 'asc';
$map  = ['name'=>'p.name','price'=>'p.price','id'=>'p.id'];
$orderBy = $map[$sort] ?? 'p.name';
$orderSql= $orderBy . ' ' . strtoupper($dir) . ', p.id ASC';

/* ------- Helpers ------- */
function hascol(PDO $pdo, string $tbl, string $col): bool {
  $st = $pdo->prepare("SHOW COLUMNS FROM `$tbl` LIKE ?");
  $st->execute([$col]);
  return (bool)$st->fetchColumn();
}

/* ------- DB ------- */
$pdo = db();
$has_is_deleted = hascol($pdo,'packages','is_deleted');

/* Routers for Import modal */
$rstmt   = $pdo->query("SELECT id, name FROM routers ORDER BY name ASC");
$routers = $rstmt->fetchAll(PDO::FETCH_ASSOC);

/* WHERE */
$where = $has_is_deleted ? "COALESCE(p.is_deleted,0)=0" : "1=1";
$params = [];
if ($search !== '') {
  $where .= " AND (p.name LIKE ? OR CAST(p.price AS CHAR) LIKE ?)";
  $like = "%$search%";
  array_push($params, $like, $like);
}

/* Count */
$sqlCount = "SELECT COUNT(*) FROM packages p WHERE $where";
$stc = $pdo->prepare($sqlCount); $stc->execute($params);
$total = (int)$stc->fetchColumn();
$total_pages = max(1, (int)ceil($total / $limit));

/* Data */
$sql = "SELECT p.id, p.name, p.price, COALESCE(cnt.cnt,0) AS clients
        FROM packages p
        LEFT JOIN (
          SELECT package_id, COUNT(*) cnt
          FROM clients
          WHERE (is_left IS NULL OR is_left=0)
          GROUP BY package_id
        ) AS cnt ON cnt.package_id = p.id
        WHERE $where
        ORDER BY $orderSql
        LIMIT $limit OFFSET $offset";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

/* Sort link helper */
function sort_link($key, $label, $currentSort, $currentDir){
  $qs = $_GET;
  $next = ($currentSort === $key && $currentDir === 'asc') ? 'desc' : 'asc';
  $qs['sort'] = $key; $qs['dir'] = $next; $qs['page']=1;
  $href = '?' . http_build_query($qs);
  $icon = ' <i class="bi bi-arrow-down-up"></i>';
  if ($currentSort === $key) {
    $icon = ($currentDir === 'asc') ? ' <i class="bi bi-caret-up-fill"></i>' : ' <i class="bi bi-caret-down-fill"></i>';
  }
  return '<a class="text-decoration-none" href="'.$href.'">'.$label.$icon.'</a>';
}

include __DIR__ . '/../partials/partials_header.php';
?>
<style>
.table-container{background:#fff;padding:15px;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.1);}
.app-toast{position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);z-index:9999;border:0;border-radius:120px;box-shadow:0 16px 40px rgba(0,0,0,.25);padding:14px 18px;min-width:280px;text-align:center;color:#fff;background:#0d6efd;transition:opacity .25s,transform .25s;}
.app-toast.success{background:#198754}.app-toast.error{background:#dc3545}.app-toast.hide{opacity:0;transform:translate(-50%,-60%)}
</style>

<div class="container-fluid py-3">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
    <div>
      <h5 class="mb-0">Packages</h5>
      <div class="text-muted small">Total: <?= number_format($total) ?></div>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-2">
      <form class="d-flex" method="get">
        <input type="hidden" name="limit" value="<?= (int)$limit ?>">
        <input type="text" name="search" class="form-control form-control-sm" placeholder="Search packages..."
               value="<?= htmlspecialchars($search) ?>">
        <button class="btn btn-primary btn-sm ms-2"><i class="bi bi-search"></i></button>
      </form>
      <a class="btn btn-outline-secondary btn-sm" href="/public/packages_export.php?<?= http_build_query(['search'=>$search]) ?>">
        <i class="bi bi-filetype-csv"></i> Export CSV
      </a>
      <button class="btn btn-outline-dark btn-sm" id="btn-import">
        <i class="bi bi-cloud-arrow-down"></i> Import from MikroTik
      </button>
      <button class="btn btn-success btn-sm" id="btn-add">
        <i class="bi bi-plus-circle"></i> New Package
      </button>
    </div>
  </div>

  <div class="table-responsive mt-3 table-container">
    <table class="table table-sm table-hover align-middle">
      <thead class="table-primary">
        <tr>
          <th style="width:80px"><?= sort_link('id','ID', $sort,$dir) ?></th>
          <th><?= sort_link('name','Package Name', $sort,$dir) ?> <span class="text-muted small">(= PPP profile name)</span></th>
          <th class="text-end" style="width:160px"><?= sort_link('price','Monthly Price', $sort,$dir) ?></th>
          <th class="text-end" style="width:130px">Clients</th>
          <th class="text-end" style="width:160px">Action</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="5" class="text-center text-muted">No data</td></tr>
      <?php else: foreach($rows as $r): ?>
        <tr data-id="<?= (int)$r['id'] ?>">
          <td><?= (int)$r['id'] ?></td>
          <td><?= htmlspecialchars($r['name']) ?></td>
          <td class="text-end"><?= number_format((float)$r['price'], 2) ?></td>
          <td class="text-end">
            <?php if ((int)$r['clients']>0): ?>
              <a class="btn btn-outline-secondary btn-sm" href="/public/clients.php?package_id=<?= (int)$r['id'] ?>">
                <?= (int)$r['clients'] ?>
              </a>
            <?php else: ?>
              <span class="text-muted">0</span>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <button class="btn btn-outline-primary btn-sm me-1 btn-edit"
                    data-id="<?= (int)$r['id'] ?>"
                    data-name="<?= htmlspecialchars($r['name']) ?>"
                    data-price="<?= htmlspecialchars($r['price']) ?>">
              <i class="bi bi-pencil-square"></i>
            </button>
            <button class="btn btn-outline-danger btn-sm btn-delete" data-id="<?= (int)$r['id'] ?>">
              <i class="bi bi-trash"></i>
            </button>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($total_pages > 1): ?>
    <nav class="mt-3">
      <ul class="pagination pagination-sm justify-content-center">
        <li class="page-item <?= $page<=1?'disabled':'' ?>">
          <a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>$page-1])) ?>">Prev</a>
        </li>
        <?php
          $start = max(1,$page-2); $end=min($total_pages,$page+2);
          if (($end-$start+1)<5){ if($start==1){$end=min($total_pages,$start+4);} elseif($end==$total_pages){$start=max(1,$end-4);} }
          for($i=$start;$i<=$end;$i++):
        ?>
          <li class="page-item <?= $i==$page?'active':'' ?>">
            <a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>$i])) ?>"><?= $i ?></a>
          </li>
        <?php endfor; ?>
        <li class="page-item <?= $page>=$total_pages?'disabled':'' ?>">
          <a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>$page+1])) ?>">Next</a>
        </li>
      </ul>
    </nav>
  <?php endif; ?>
</div>

<!-- Modal: Add/Edit -->
<div class="modal fade" id="pkgModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" id="pkgForm" autocomplete="off">
      <div class="modal-header">
        <h6 class="modal-title">Package</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id" id="pkg-id">

        <!-- (বাংলা) Router + PPP profile selector (for autofill only) -->
        <div class="mb-2">
          <label class="form-label">MikroTik Router</label>
          <select class="form-select" id="router-id">
            <option value="">— Select router —</option>
            <?php foreach($routers as $rt): ?>
              <option value="<?= (int)$rt['id'] ?>"><?= htmlspecialchars($rt['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Profiles will be loaded for name autofill.</div>
        </div>

        <div class="mb-2">
          <label class="form-label d-flex justify-content-between align-items-center">
            <span>PPP Profile (from router)</span>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-load-ppp" disabled>
              <i class="bi bi-arrow-repeat"></i> Load
            </button>
          </label>
          <select id="ppp-profile" class="form-select" disabled>
            <option value="">— Select router first —</option>
          </select>
        </div>

        <div class="mb-2">
          <label class="form-label">Package Name <small class="text-muted">(1:1 with PPP profile)</small></label>
          <input type="text" class="form-control" name="name" id="pkg-name" required>
        </div>
        <div>
          <label class="form-label">Monthly Price</label>
          <input type="number" step="0.01" min="0" class="form-control" name="price" id="pkg-price" required>
        </div>
        <div class="form-text mt-1">
          <i class="bi bi-info-circle"></i>
          <span class="text-muted">Changing package name may require updating client PPP profiles.</span>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" type="submit">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Import from MikroTik -->
<div class="modal fade" id="importModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" id="importForm" autocomplete="off">
      <div class="modal-header">
        <h6 class="modal-title">Import Packages from MikroTik PPP Profiles</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-2">
          <div class="col-md-4">
            <label class="form-label">Router</label>
            <select class="form-select" id="imp-router">
              <option value="">— Select router —</option>
              <?php foreach($routers as $rt): ?>
                <option value="<?= (int)$rt['id'] ?>"><?= htmlspecialchars($rt['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Default Price</label>
            <input type="number" step="0.01" min="0" class="form-control" id="imp-price" placeholder="0">
          </div>
          <div class="col-md-5 d-flex align-items-end">
            <button type="button" class="btn btn-outline-secondary me-2" id="imp-load" disabled>
              <i class="bi bi-arrow-repeat"></i> Load Profiles
            </button>
            <button type="submit" class="btn btn-primary" id="imp-create" disabled>
              <i class="bi bi-plus-circle"></i> Create Missing
            </button>
          </div>
        </div>

        <div class="mt-3">
          <div class="table-responsive" style="max-height:50vh; overflow:auto;">
            <table class="table table-sm table-hover align-middle">
              <thead>
                <tr>
                  <th style="width:38px"><input type="checkbox" id="imp-checkall" disabled></th>
                  <th>PPP Profile</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody id="imp-body">
                <tr class="text-muted"><td colspan="3">Load profiles…</td></tr>
              </tbody>
            </table>
          </div>
          <div class="small text-muted mt-1">
            “Create Missing” will create new packages for profiles that don’t exist yet.
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </form>
  </div>
</div>

<script>
const API_UPSERT = '/api/package_upsert.php';
const API_DELETE = '/api/package_delete.php';
const API_PPP    = '/api/ppp_profiles.php';
const API_IMPORT = '/api/package_bulk_import.php';

/* Toast */
function showToast(message, type='success', timeout=2500){
  const box = document.createElement('div');
  box.className = 'app-toast ' + (type==='success'?'success':'error');
  box.textContent = message || '';
  document.body.appendChild(box);
  setTimeout(()=> box.classList.add('hide'), timeout - 250);
  setTimeout(()=> box.remove(), timeout);
}
function simpleConfirm(msg){ return new Promise(r=> r(confirm(msg))); }
function enable(el,on=true){ if(el) el.disabled=!on; }
function clearSelect(sel, ph=''){ if(!sel) return; sel.innerHTML=''; const o=document.createElement('option'); o.value=''; o.textContent=ph; sel.appendChild(o); }
function esc(s){ return (s||'').replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m])); }

/* ===== Add/Edit Modal ===== */
document.addEventListener('DOMContentLoaded', ()=>{
  const modalEl = document.getElementById('pkgModal');
  const modal = new bootstrap.Modal(modalEl);
  const form  = document.getElementById('pkgForm');

  document.getElementById('btn-add').addEventListener('click', ()=>{
    form.reset();
    form.querySelector('#pkg-id').value = '';
    const rsel = document.getElementById('router-id');
    const psel = document.getElementById('ppp-profile');
    const btn  = document.getElementById('btn-load-ppp');
    if (rsel && psel && btn){
      rsel.value='';
      enable(btn,false); clearSelect(psel,'— Select router first —'); enable(psel,false);
    }
    modal.show();
  });

  document.querySelectorAll('.btn-edit').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      form.reset();
      form.querySelector('#pkg-id').value   = btn.dataset.id || '';
      form.querySelector('#pkg-name').value = btn.dataset.name || '';
      form.querySelector('#pkg-price').value= btn.dataset.price || '';
      modal.show();
    });
  });

  // (বাংলা) PPP loader (autofill name)
  const rsel = document.getElementById('router-id');
  const psel = document.getElementById('ppp-profile');
  const btnL = document.getElementById('btn-load-ppp');
  const nameInput = document.getElementById('pkg-name');

  rsel?.addEventListener('change', ()=>{
    enable(btnL, !!rsel.value);
    clearSelect(psel, rsel.value ? 'Click Load…' : '— Select router first —');
    enable(psel, false);
  });

  btnL?.addEventListener('click', async ()=>{
    if (!rsel.value) return;
    btnL.disabled=true; btnL.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span>Loading';
    try{
      const res = await fetch(`${API_PPP}?router_id=${encodeURIComponent(rsel.value)}`);
      const j   = await res.json();
      const list = (j.profiles||j.items||[]).map(x=> typeof x==='string'? x : (x.name||'')).filter(Boolean);
      clearSelect(psel, '— Select PPP profile —');
      list.sort((a,b)=>a.localeCompare(b)).forEach(n=>{
        const o=document.createElement('option'); o.value=n; o.textContent=n; psel.appendChild(o);
      });
      enable(psel,true);
      showToast(`Loaded ${list.length} profile(s)`, 'success');
    }catch(e){ clearSelect(psel,'Load failed'); showToast('Load failed','error'); enable(psel,false); }
    finally{ btnL.disabled=false; btnL.innerHTML='<i class="bi bi-arrow-repeat"></i> Load'; }
  });

  psel?.addEventListener('change', ()=>{ if (psel.value && nameInput) nameInput.value = psel.value; });

  form.addEventListener('submit', async (e)=>{
    e.preventDefault();
    const fd = new FormData(form);
    const data = Object.fromEntries(fd.entries());
    const name = (data.name||'').trim();
    const price= parseFloat(data.price||'0');
    if (!name) return showToast('Package name is required','error');
    if (isNaN(price) || price<0) return showToast('Enter a valid price (>= 0)','error');

    try{
      const res = await fetch(API_UPSERT, {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ id: data.id||null, name, price })
      });
      const j = await res.json();
      if (j.ok){ showToast(j.message||'Saved'); setTimeout(()=> location.reload(), 500); }
      else { showToast(j.error||'Failed','error'); }
    }catch(_){ showToast('Request failed','error'); }
  });

  /* ===== Delete ===== */
  document.querySelectorAll('.btn-delete').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      const id = parseInt(btn.dataset.id,10);
      if (!id) return;
      const ok = await simpleConfirm('Are you sure? If clients are assigned, delete will be blocked.');
      if (!ok) return;
      try{
        const res = await fetch(API_DELETE, {
          method:'POST', headers:{'Content-Type':'application/json'},
          body: JSON.stringify({ id })
        });
        const j = await res.json();
        if (j.ok){ showToast(j.message||'Deleted'); setTimeout(()=> location.reload(), 400); }
        else { showToast(j.error||'Failed','error'); }
      }catch(_){ showToast('Request failed','error'); }
    });
  });

  /* ===== Import from MikroTik ===== */
  const impModalEl = document.getElementById('importModal');
  const impModal   = new bootstrap.Modal(impModalEl);
  const btnImport  = document.getElementById('btn-import');
  const impRouter  = document.getElementById('imp-router');
  const impLoad    = document.getElementById('imp-load');
  const impBody    = document.getElementById('imp-body');
  const impCreate  = document.getElementById('imp-create');
  const impCheckAll= document.getElementById('imp-checkall');
  const impPrice   = document.getElementById('imp-price');

  btnImport?.addEventListener('click', ()=>{
    impRouter.value=''; impPrice.value='';
    impLoad.disabled = true; impCreate.disabled = true;
    impCheckAll.checked=false; impCheckAll.disabled=true;
    impBody.innerHTML = '<tr class="text-muted"><td colspan="3">Load profiles…</td></tr>';
    impModal.show();
  });

  impRouter?.addEventListener('change', ()=>{
    impLoad.disabled = !impRouter.value;
    impCreate.disabled = true;
    impCheckAll.disabled = true; impCheckAll.checked=false;
    impBody.innerHTML = '<tr class="text-muted"><td colspan="3">Load profiles…</td></tr>';
  });

  impLoad?.addEventListener('click', async ()=>{
    if (!impRouter.value) return;
    impLoad.disabled = true; impLoad.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Loading';
    try{
      const r1 = await fetch(`${API_PPP}?router_id=${encodeURIComponent(impRouter.value)}`);
      const j1 = await r1.json();
      const profiles = (j1.profiles||j1.items||[]).map(x=> typeof x==='string'? x : (x.name||'')).filter(Boolean);

      const r2 = await fetch(API_IMPORT, {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ router_id: parseInt(impRouter.value,10), profiles, dry:1 })
      });
      const j2 = await r2.json();
      if (!j2.ok) throw new Error(j2.error||'Failed');

      if (!j2.items || !j2.items.length){
        impBody.innerHTML = '<tr><td colspan="3" class="text-center text-muted">No profiles found</td></tr>';
        impCreate.disabled = true; impCheckAll.disabled = true;
      } else {
        let html = '';
        j2.items.forEach(it=>{
          html += `<tr>
            <td><input type="checkbox" class="imp-check" data-name="${esc(it.name)}" ${it.exists?'disabled':''}></td>
            <td>${esc(it.name)}</td>
            <td>${it.exists?'<span class="badge text-bg-secondary">exists</span>':'<span class="badge text-bg-success">new</span>'}</td>
          </tr>`;
        });
        impBody.innerHTML = html;
        const hasNew = j2.items.some(x=>!x.exists);
        impCreate.disabled = !hasNew;
        impCheckAll.disabled = !hasNew;
        impCheckAll.checked = hasNew;
        document.querySelectorAll('.imp-check').forEach(ch=>{
          if (!ch.disabled) ch.checked = true;
        });
      }
      showToast(`Loaded ${profiles.length} profile(s)`);
    }catch(e){
      impBody.innerHTML = `<tr><td colspan="3" class="text-danger">Load failed: ${esc(e.message||'Failed')}</td></tr>`;
      impCreate.disabled = true; impCheckAll.disabled = true;
    }finally{
      impLoad.disabled = false; impLoad.innerHTML = '<i class="bi bi-arrow-repeat"></i> Load Profiles';
    }
  });

  impCheckAll?.addEventListener('change', ()=>{
    document.querySelectorAll('.imp-check').forEach(ch=>{
      if (!ch.disabled) ch.checked = impCheckAll.checked;
    });
  });

  document.getElementById('importForm')?.addEventListener('submit', async (e)=>{
    e.preventDefault();
    const names = Array.from(document.querySelectorAll('.imp-check'))
      .filter(ch=> ch.checked && !ch.disabled)
      .map(ch=> ch.dataset.name);
    if (!names.length) return showToast('Select at least one profile','error');

    try{
      impCreate.disabled = true;
      const res = await fetch(API_IMPORT, {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({
          router_id: parseInt(impRouter.value,10),
          profiles: names,
          default_price: parseFloat(impPrice.value||'0'),
          dry: 0
        })
      });
      const j = await res.json();
      if (j.ok){
        showToast(`Created ${j.created}, skipped ${j.skipped}`);
        setTimeout(()=> location.reload(), 600);
      } else {
        showToast(j.error||'Import failed','error');
        impCreate.disabled = false;
      }
    }catch(_){ showToast('Request failed','error'); impCreate.disabled=false; }
  });
});
</script>

<?php include __DIR__ . '/../partials/partials_footer.php'; ?>
