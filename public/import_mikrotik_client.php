<?php
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

$pdo = db();
$routers = $pdo->query("SELECT id,name,ip,api_port FROM routers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// packages
$pkgRows = $pdo->query("SELECT id,name,price FROM packages ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$pkgOptions = array_map(fn($p)=>['id'=>(int)$p['id'],'name'=>$p['name'],'price'=>(float)$p['price']], $pkgRows);

include __DIR__ . '/../partials/partials_header.php';
?>
<div class="container py-4">
  <h3>Import Mikrotik Users (PPPoE)</h3>

  <div class="card mb-3"><div class="card-body">
    <form id="frmLoad">
      <div class="row g-3 align-items-end">
        <div class="col-md-4">
          <label class="form-label">Router *</label>
          <select id="router_id" class="form-select" required>
            <option value="">-- Select Router --</option>
            <?php foreach($routers as $r): ?>
              <option value="<?= (int)$r['id'] ?>"><?= htmlspecialchars($r['name'].' ('.$r['ip'].')') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Profile *</label>
          <select id="profile" class="form-select" required disabled>
            <option value="">-- Select Profile --</option>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Invoice Month</label>
          <input type="month" id="invoice_month" class="form-control" value="<?= date('Y-m') ?>">
        </div>
        <div class="col-12">
          <div class="form-check"><input type="checkbox" class="form-check-input" id="opt_do_not_overwrite" checked>
            <label class="form-check-label" for="opt_do_not_overwrite">Do not overwrite existing client name/mobile</label></div>
          <div class="form-check"><input type="checkbox" class="form-check-input" id="opt_save_password">
            <label class="form-check-label" for="opt_save_password">Save PPPoE passwords</label></div>
          <div class="form-check"><input type="checkbox" class="form-check-input" id="opt_generate_invoice">
            <label class="form-check-label" for="opt_generate_invoice">Generate first invoice</label></div>
        </div>
        <div class="col-12"><button class="btn btn-primary" type="submit">Load Preview</button></div>
      </div>
    </form>
  </div></div>

  <div id="previewArea" class="d-none">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h5>Preview</h5>
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" id="toggleShowPwd">
        <label class="form-check-label" for="toggleShowPwd">Show passwords</label>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table table-sm table-bordered" id="tblPreview">
        <thead><tr>
          <th>#</th><th>PPPoE ID</th><th>Password</th><th>Client</th>
          <th>Mobile</th><th>Profile</th><th>Package</th><th>Price</th>
          <th>Status</th><th>Action</th>
        </tr></thead>
        <tbody></tbody>
      </table>
    </div>
    <div class="alert alert-warning d-none" id="warnUnmatched">Some profiles did not match any package.</div>
    <div class="text-end"><button class="btn btn-success" id="btnCommit">Commit Selected</button></div>
  </div>
</div>

<script>
const PKG_OPTIONS = <?= json_encode($pkgOptions) ?>;
let LAST_PREVIEW=[], SHOW_PASSWORDS=false; const MASK='••••';

function pkgSelectHtml(id){
  const opt=PKG_OPTIONS.map(p=>`<option value="${p.id}"${p.id==id?'selected':''}>${p.name} (${p.price})</option>`).join('');
  return `<select class="form-select form-select-sm pkg-select"><option value="">--Select--</option>${opt}</select>`;
}

// Router→profiles
document.getElementById('router_id').addEventListener('change',async e=>{
  const rid=e.target.value; const sel=document.getElementById('profile');
  sel.innerHTML='<option value="">-- Select Profile --</option>'; sel.disabled=true;
  if(!rid) return;
  const res=await fetch('/api/mt_list_profiles.php?router_id='+rid); const data=await res.json();
  if(data.ok){ for(const p of data.profiles){ sel.innerHTML+=`<option>${p}</option>`; } sel.disabled=false; }
});

// Load Preview
document.getElementById('frmLoad').addEventListener('submit',async ev=>{
  ev.preventDefault();
  const rid=document.getElementById('router_id').value, prof=document.getElementById('profile').value;
  if(!rid||!prof) return alert('Select router and profile');
  const res=await fetch(`/api/mt_list_secrets.php?router_id=${rid}&profile=${prof}&sensitive=1`);
  const data=await res.json();
  if(!data.ok) return alert(data.msg||'fail');
  LAST_PREVIEW=data.rows||[];
  const tb=document.querySelector('#tblPreview tbody'); tb.innerHTML='';
  let i=0,unmatched=false;
  for(const row of LAST_PREVIEW){
    const raw=row.password||''; const show=SHOW_PASSWORDS?raw:(raw?MASK:'');
    const pkg=row.package_matched?`<span class="badge bg-success">${row.package_name}</span>`:pkgSelectHtml('');
    if(!row.package_matched) unmatched=true;
    const tr=document.createElement('tr'); tr.dataset.idx=i;
    tr.innerHTML=`<td><input type="checkbox" class="row-select" checked></td>
      <td>${row.pppoe_id}</td><td data-col="pwd">${show}</td><td>${row.client_name}</td>
      <td>${row.mobile||''}</td><td>${row.profile||''}</td><td>${pkg}</td>
      <td>${row.package_price||''}</td><td>${row.status}</td>
      <td>${row.is_existing?'Update':'New'}</td>`;
    tb.appendChild(tr); i++;
  }
  if(unmatched) document.getElementById('warnUnmatched').classList.remove('d-none');
  document.getElementById('previewArea').classList.remove('d-none');
});

// Toggle show/hide password
document.getElementById('toggleShowPwd').addEventListener('change',e=>{
  SHOW_PASSWORDS=e.target.checked;
  document.querySelectorAll('#tblPreview tbody tr').forEach(tr=>{
    const idx=tr.dataset.idx; const raw=LAST_PREVIEW[idx].password||'';
    tr.querySelector('[data-col="pwd"]').textContent=SHOW_PASSWORDS?raw:(raw?MASK:'');
  });
});

// Commit
document.getElementById('btnCommit').addEventListener('click',async()=>{
  const rid=document.getElementById('router_id').value, prof=document.getElementById('profile').value;
  const rows=[]; document.querySelectorAll('#tblPreview tbody tr').forEach(tr=>{
    const idx=tr.dataset.idx, src=LAST_PREVIEW[idx];
    const selected=tr.querySelector('.row-select').checked;
    let pkgId=src.package_id||null;
    if(!src.package_matched){ const sel=tr.querySelector('.pkg-select'); if(sel&&sel.value) pkgId=Number(sel.value); }
    rows.push({...src, selected, package_id:pkgId});
  });
  const payload={router_id:Number(rid),profile:prof,options:{
    do_not_overwrite:document.getElementById('opt_do_not_overwrite').checked,
    save_password:document.getElementById('opt_save_password').checked,
    generate_invoice:document.getElementById('opt_generate_invoice').checked,
    invoice_month:document.getElementById('invoice_month').value
  },rows};
  const res=await fetch('/api/mt_import_clients.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
  const data=await res.json();
  if(!data.ok) return alert('Commit failed: '+(data.msg||''));
  const s=data.summary||{}; alert(`Done.\nCreated: ${s.created||0}\nUpdated: ${s.updated||0}\nSkipped: ${s.skipped||0}\nInvoices: ${s.invoices_created||0}`);
});
</script>
<?php include __DIR__ . '/../partials/partials_footer.php'; ?>
