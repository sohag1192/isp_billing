<?php
// /public/hr/employee_add.php
// UI: English only; Comments: Bangla.
// Feature: Manual/Auto Employee Code + optional User creation (schema-aware)

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','0'); // প্রোডাকশনে error screen বন্ধ রাখুন
ini_set('log_errors','1');

$page_title = 'Add Employee';
require_once __DIR__ . '/../../partials/partials_header.php';
require_once __DIR__ . '/../../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------------- helpers ---------------- */
// বাংলা: XSS-safe encoder
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// বাংলা: টেবিল/কলাম এক্সিস্টেন্স চেক
function tbl_exists(PDO $pdo, string $t): bool {
  try {
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $q  = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
    $q->execute([$db, $t]);
    return (bool)$q->fetchColumn();
  } catch (Throwable $e) { return false; }
}
function col_exists(PDO $pdo, string $t, string $c): bool {
  try {
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $q  = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
    $q->execute([$db, $t, $c]);
    return (bool)$q->fetchColumn();
  } catch (Throwable $e) { return false; }
}

/* ---------------- target tables ---------------- */
$T_EMP  = tbl_exists($pdo,'emp_info') ? 'emp_info' : (tbl_exists($pdo,'employees') ? 'employees' : null);
$T_DEPT = tbl_exists($pdo,'department_info') ? 'department_info' : (tbl_exists($pdo,'departments') ? 'departments' : null);

/* ---------------- load departments (schema-aware) ---------------- */
$dept_rows = [];
if ($T_DEPT === 'department_info') {
  $dept_rows = $pdo->query("SELECT dept_id AS id, dept_name FROM department_info ORDER BY dept_name")->fetchAll(PDO::FETCH_ASSOC);
} elseif ($T_DEPT === 'departments') {
  $dept_rows = $pdo->query("SELECT id, name AS dept_name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}
$hasDept = !empty($dept_rows);

// বাংলা: CSRF টোকেন (থাকলে ব্যবহার)
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$csrf = $_SESSION['csrf'] ?? '';
?>
<script>
// বাংলা: Salary → Net gross হিসাব
function updatesum(){
  const f = document.form;
  if(!f) return;
  const get = k => parseFloat((f[k]?.value || 0)) || 0;
  const total = (get('basic_salary') + get('mobile_bill') + get('house_rent') + get('medical') + get('food') + get('others'))
              - (get('provident_fund') + get('professional_tax') + get('income_tax'));
  if (f.gross_total) f.gross_total.value = (isFinite(total) ? total : 0).toFixed(2);
}

document.addEventListener('DOMContentLoaded', () => {
  const f = document.form;
  if (!f) return;

  // বাংলা: salary fields change => recompute
  ['basic_salary','mobile_bill','house_rent','medical','food','others','provident_fund','professional_tax','income_tax']
    .forEach(n => { const el = f.elements[n]; if (el) el.addEventListener('input', updatesum); });
  updatesum();

  // বাংলা: form reset => validation state clear + recompute
  f.addEventListener('reset', () => {
    setTimeout(() => { f.classList.remove('was-validated'); updatesum(); }, 0);
  });

  // বাংলা: name থেকে username hint (server will ensure uniqueness)
  const nameEl = f.elements['e_name'];
  const userEl = f.elements['user_username'];
  if (nameEl && userEl) {
    nameEl.addEventListener('input', () => {
      if (!userEl.dataset.touched || userEl.value === '') {
        const base = (nameEl.value || '').toLowerCase().trim()
          .replace(/[^a-z0-9\s._-]+/g,'')
          .replace(/\s+/g,'.')
          .replace(/\.{2,}/g,'.')
          .replace(/^\.+|\.+$/g,'');
        userEl.value = base || '';
      }
    });
    userEl.addEventListener('input', () => { userEl.dataset.touched = '1'; });
  }

  // বাংলা: login account block toggle
  const cb = f.elements['create_user'];
  const blk = document.getElementById('loginAccountBlock');
  if (cb && blk) {
    const sync = () => blk.style.display = cb.checked ? '' : 'none';
    cb.addEventListener('change', sync);
    sync();
  }

  // বাংলা: manual/auto employee code toggle
  const cbManual = document.getElementById('useManualCode');
  const codeInp  = document.getElementById('emp_code_manual');
  if (cbManual && codeInp) {
    const syncCode = () => { codeInp.disabled = !cbManual.checked; if (!cbManual.checked) codeInp.value=''; };
    cbManual.addEventListener('change', syncCode);
    syncCode();
    // বাংলা: ক্লায়েন্ট-সাইড স্যানিটাইজ (সার্ভার-সাইডও আছে)
    codeInp.addEventListener('input', () => { codeInp.value = codeInp.value.replace(/[^A-Za-z0-9._-]/g,''); });
  }
});
</script>

<div class="container my-3">
  <div class="d-flex align-items-center gap-2 mb-3">
    <a href="/public/hr/employees.php" class="btn btn-outline-secondary btn-sm">← Back</a>
    <h5 class="mb-0">Add Employee</h5>
  </div>

  <?php if (!empty($_SESSION['flash'])): ?>
    <div class="alert alert-info py-2"><?= h($_SESSION['flash']); unset($_SESSION['flash']); ?></div>
  <?php endif; ?>

  <form method="post" id="form" name="form"
        action="/public/hr/employee_add_query.php"
        enctype="multipart/form-data"
        class="needs-validation" novalidate>

    <!-- বাংলা: client-side max file size hint (server enforce থাকবে) -->
    <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo 5 * 1024 * 1024; ?>"><!-- ~5MB -->
    <?php if ($csrf): ?>
      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
    <?php endif; ?>

    <!-- Employee Code (Manual / Auto) -->
    <div class="card shadow-sm">
      <div class="card-header bg-light d-flex align-items-center justify-content-between">
        <strong>Employee Code</strong>
        <div class="form-check m-0">
          <input class="form-check-input" type="checkbox" value="1" id="useManualCode" name="use_manual_code" checked>
          <label class="form-check-label" for="useManualCode">Set manually</label>
        </div>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-12 col-md-6">
            <label class="form-label">Employee Code (e.g. 202509.1)</label>
            <input type="text" name="emp_code_manual" id="emp_code_manual" class="form-control"
                   maxlength="64" placeholder="Type code, or uncheck to auto-generate">
            <div class="form-text">Allowed: letters, digits, dot (.), underscore (_), dash (-). Leave empty to auto.</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Identity & Basic -->
    <div class="card shadow-sm mt-3">
      <div class="card-header bg-light"><strong>Identity & Basic</strong></div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-12 col-md-4">
            <label class="form-label">Employee Name <span class="text-danger">*</span></label>
            <input type="text" name="e_name" class="form-control" required maxlength="120" autocomplete="name">
            <div class="invalid-feedback">Employee name is required.</div>
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">Designation <span class="text-danger">*</span></label>
            <input type="text" name="e_des" class="form-control" required maxlength="80" autocomplete="organization-title">
            <div class="invalid-feedback">Designation is required.</div>
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">
              Department<?php if($hasDept): ?> <span class="text-danger">*</span><?php endif; ?>
            </label>
            <?php if ($hasDept): ?>
              <select name="e_dept" class="form-select" required>
                <option value="">Choose Department</option>
                <?php foreach($dept_rows as $d): ?>
                  <option value="<?php echo (int)$d['id']; ?>"><?php echo h($d['dept_name']); ?></option>
                <?php endforeach; ?>
              </select>
              <div class="invalid-feedback">Please select a department.</div>
            <?php else: ?>
              <input type="text" name="e_dept_text" class="form-control" placeholder="Department (optional)" maxlength="80" autocomplete="organization">
              <div class="form-text">No department table detected; free text allowed.</div>
            <?php endif; ?>
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label d-block">Gender</label>
            <div class="btn-group" role="group" aria-label="Gender">
              <input type="radio" class="btn-check" name="e_gender" id="gM" value="Male">
              <label class="btn btn-outline-primary" for="gM">Male</label>
              <input type="radio" class="btn-check" name="e_gender" id="gF" value="Female">
              <label class="btn btn-outline-primary" for="gF">Female</label>
            </div>
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label d-block">Marital Status</label>
            <div class="btn-group" role="group" aria-label="Marital Status">
              <input type="radio" class="btn-check" name="married_stu" id="mU" value="Unmarried">
              <label class="btn btn-outline-secondary" for="mU">Unmarried</label>
              <input type="radio" class="btn-check" name="married_stu" id="mM" value="Married">
              <label class="btn btn-outline-secondary" for="mM">Married</label>
            </div>
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label">Date of Birth</label>
            <input type="date" name="e_b_date" class="form-control" autocomplete="bday">
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label">Joining Date</label>
            <input type="date" name="e_j_date" class="form-control" autocomplete="off">
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label">National ID</label>
            <input type="text" name="n_id" class="form-control" maxlength="40" inputmode="numeric" autocomplete="off" placeholder="NID number">
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label">Blood Group</label>
            <select name="bgroup" class="form-select" autocomplete="off">
              <option value="">Choose</option>
              <option>AB+</option><option>AB-</option>
              <option>A+</option><option>A-</option>
              <option>B+</option><option>B-</option>
              <option>O+</option><option>O-</option>
            </select>
          </div>

        </div>
      </div>
    </div>

    <!-- Salary & Deductions -->
    <div class="card shadow-sm mt-3">
      <div class="card-header bg-light"><strong>Salary & Deductions</strong></div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-12 col-lg-6">
            <div class="mb-3">
              <label class="form-label">Basic Salary <span class="text-danger">*</span></label>
              <div class="input-group">
                <span class="input-group-text">৳</span>
                <input type="number" step="0.01" min="0" name="basic_salary" class="form-control" required inputmode="decimal">
              </div>
              <div class="invalid-feedback">Basic salary is required.</div>
            </div>

            <div class="row g-3">
              <div class="col-6 col-md-4">
                <label class="form-label">Mobile Bill</label>
                <div class="input-group">
                  <span class="input-group-text">৳</span>
                  <input type="number" step="0.01" min="0" name="mobile_bill" class="form-control" inputmode="decimal">
                </div>
              </div>
              <div class="col-6 col-md-4">
                <label class="form-label">House Rent</label>
                <div class="input-group">
                  <span class="input-group-text">৳</span>
                  <input type="number" step="0.01" min="0" name="house_rent" class="form-control" inputmode="decimal">
                </div>
              </div>
              <div class="col-6 col-md-4">
                <label class="form-label">Medical</label>
                <div class="input-group">
                  <span class="input-group-text">৳</span>
                  <input type="number" step="0.01" min="0" name="medical" class="form-control" inputmode="decimal">
                </div>
              </div>
              <div class="col-6 col-md-4">
                <label class="form-label">Food</label>
                <div class="input-group">
                  <span class="input-group-text">৳</span>
                  <input type="number" step="0.01" min="0" name="food" class="form-control" inputmode="decimal">
                </div>
              </div>
              <div class="col-6 col-md-4">
                <label class="form-label">Others</label>
                <div class="input-group">
                  <span class="input-group-text">৳</span>
                  <input type="number" step="0.01" min="0" name="others" class="form-control" inputmode="decimal">
                </div>
              </div>
            </div>

            <div class="mt-3">
              <label class="form-label">Net Gross Salary</label>
              <div class="input-group">
                <span class="input-group-text">৳</span>
                <input type="text" name="gross_total" class="form-control" readonly>
              </div>
              <div class="form-text">Auto calculated.</div>
            </div>
          </div>

          <div class="col-12 col-lg-6">
            <div class="row g-3">
              <div class="col-6 col-md-4">
                <label class="form-label">Provident Fund</label>
                <div class="input-group">
                  <span class="input-group-text">৳</span>
                  <input type="number" step="0.01" min="0" name="provident_fund" class="form-control" inputmode="decimal">
                </div>
              </div>
              <div class="col-6 col-md-4">
                <label class="form-label">Professional Tax</label>
                <div class="input-group">
                  <span class="input-group-text">৳</span>
                  <input type="number" step="0.01" min="0" name="professional_tax" class="form-control" inputmode="decimal">
                </div>
              </div>
              <div class="col-6 col-md-4">
                <label class="form-label">Income Tax</label>
                <div class="input-group">
                  <span class="input-group-text">৳</span>
                  <input type="number" step="0.01" min="0" name="income_tax" class="form-control" inputmode="decimal">
                </div>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>

    <!-- Contact & Address -->
    <div class="card shadow-sm mt-3">
      <div class="card-header bg-light"><strong>Contact & Address</strong></div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-12 col-lg-6">
            <label class="form-label">Present Address</label>
            <textarea name="pre_address" class="form-control" rows="2" maxlength="500" autocomplete="street-address"></textarea>
          </div>
          <div class="col-12 col-lg-6">
            <label class="form-label">Permanent Address</label>
            <textarea name="per_address" class="form-control" rows="2" maxlength="500" autocomplete="street-address"></textarea>
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label">Personal Contact</label>
            <input name="e_cont_per" class="form-control" inputmode="tel" maxlength="20" autocomplete="tel">
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label">Office Contact</label>
            <input name="e_cont_office" class="form-control" inputmode="tel" maxlength="20" autocomplete="tel">
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label">Family Contact</label>
            <input name="e_cont_family" class="form-control" inputmode="tel" maxlength="20" autocomplete="tel">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" placeholder="abcd@example.com" maxlength="120" autocomplete="email">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label">Facebook</label>
            <input name="skype" class="form-control" placeholder="Facebook ID" maxlength="64" autocomplete="off">
          </div>
        </div>
      </div>
    </div>

    <!-- Login Account (optional) -->
    <div class="card shadow-sm mt-3">
      <div class="card-header bg-light d-flex align-items-center justify-content-between">
        <strong>Login Account</strong>
        <div class="form-check m-0">
          <input class="form-check-input" type="checkbox" value="1" id="create_user" name="create_user" checked>
          <label class="form-check-label" for="create_user">Create login for this employee</label>
        </div>
      </div>
      <div class="card-body" id="loginAccountBlock">
        <div class="row g-3">
          <div class="col-12 col-md-4">
            <label class="form-label">Username <span class="text-danger">*</span></label>
            <input type="text" name="user_username" class="form-control" maxlength="60" autocomplete="username" placeholder="auto from name if empty">
            <div class="form-text">If empty, will be auto-generated from name.</div>
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label">Role</label>
            <select name="user_role" class="form-select">
              <option value="employee">employee</option>
              <option value="manager">manager</option>
              <option value="hr">hr</option>
              <option value="admin">admin</option>
            </select>
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label">Password (optional)</label>
            <input type="text" name="user_password" class="form-control" maxlength="80" autocomplete="new-password" placeholder="leave empty to auto-generate">
          </div>
        </div>
      </div>
    </div>

    <!-- Documents -->
    <div class="card shadow-sm mt-3">
      <div class="card-header bg-light"><strong>Documents</strong></div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-12">
            <div class="form-text">Max 5 MB each. Allowed: JPG/PNG/WEBP (Photo), JPG/PNG/WEBP/PDF (NID).</div>
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label">Employee Photo</label>
            <input type="file" name="photo" class="form-control"
                   accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label">NID (Front/Scan)</label>
            <input type="file" name="nid_file" class="form-control"
                   accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf">
          </div>
        </div>
      </div>
    </div>

    <div class="d-flex justify-content-end gap-2 my-3">
      <button class="btn btn-light" type="reset">Reset</button>
      <button class="btn btn-primary" type="submit">Submit</button>
    </div>
  </form>
</div>

<script>
// Bootstrap 5 validation (বাংলা: ক্লায়েন্ট-সাইড ফর্ম ভ্যালিডেশন)
(function(){
  'use strict';
  const forms = document.querySelectorAll('.needs-validation');
  Array.prototype.slice.call(forms).forEach(function (f) {
    f.addEventListener('submit', function(e){
      if(!f.checkValidity()){
        e.preventDefault();
        e.stopPropagation();
      }
      f.classList.add('was-validated');
    }, false);
  });
})();
</script>

<?php require_once __DIR__ . '/../../partials/partials_footer.php'; ?>
