<?php
require_once __DIR__ . '/../../app/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$err = '';

// (বাংলা) খুব হালকা CSRF সুরক্ষা — রিফ্রেশে নতুন টোকেন
if (empty($_SESSION['portal_csrf'])) {
  $_SESSION['portal_csrf'] = bin2hex(random_bytes(16));
}

/*
  Login rule:
  - Username  = clients.pppoe_id
  - Password  = clients.pppoe_pass  (plaintext match)
  - Optional: only allow active/pending? (here: active OR pending allowed; inactive is blocked)
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // (বাংলা) CSRF চেক
    if (!hash_equals($_SESSION['portal_csrf'] ?? '', $_POST['csrf'] ?? '')) {
        $err = 'Invalid session. Please retry.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $err = 'Please enter both PPPoE ID and Password.';
        } else {
            // find client by pppoe_id
            $st = db()->prepare("SELECT id, name, pppoe_id, pppoe_pass, status FROM clients WHERE pppoe_id = ? LIMIT 1");
            $st->execute([$username]);
            $c = $st->fetch(PDO::FETCH_ASSOC);

            if (!$c) {
                $err = 'This PPPoE ID was not found.';
            } else {
                // status check (allow active & pending; block inactive)
                $status = strtolower($c['status'] ?? 'active');
                if ($status === 'inactive') {
                    $err = 'Your connection is currently inactive.';
                } elseif ($c['pppoe_pass'] !== $password) {
                    $err = 'Incorrect PPPoE password.';
                } else {
                    // login ok
                    $_SESSION['portal_client_id'] = (int)$c['id'];
                    $_SESSION['portal_username']  = $c['pppoe_id']; // for header display

                    // optional last_login field in clients (if you want)
                    // db()->prepare("UPDATE clients SET last_login = NOW() WHERE id=?")->execute([$c['id']]);

                    header("Location: /public/portal/index.php");
                    exit;
                }
            }
        }
    }
    // (বাংলা) সাবমিটের পর নতুন টোকেন ইস্যু
    $_SESSION['portal_csrf'] = bin2hex(random_bytes(16));
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Customer Portal - Login</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
  <style>
    /* ---------- Aesthetic tweaks (বাংলা: নরম গ্রেডিয়েন্ট + গ্লাস কার্ড) ---------- */
    :root{
      --brand: #635BFF;
      --brand-2: #22A6F0;
      --card-glass: rgba(255,255,255,.8);
      --ring: conic-gradient(from 90deg, var(--brand), var(--brand-2), var(--brand));
    }
    body{
      min-height: 100vh;
      background:
        radial-gradient(1200px 400px at 10% -20%, rgba(99,91,255,.20), rgba(99,91,255,0)),
        linear-gradient(180deg, #f7f9fc 0%, #eef3ff 100%);
      display:flex; align-items:center;
    }
    .login-card{
      background: var(--card-glass);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      border: 1px solid rgba(255,255,255,.7);
      border-radius: 16px;
      box-shadow: 0 16px 40px rgba(0,0,0,.12);
      overflow:hidden;
    }
    .login-header{
      background: linear-gradient(90deg, rgba(99,91,255,.15), rgba(34,166,240,.15));
      border-bottom: 1px solid #e8ecf6;
    }
    .brand-mark{
      width:64px; height:64px; border-radius:50%; padding:3px; background: var(--ring);
      display:flex; align-items:center; justify-content:center;
      box-shadow: 0 10px 24px rgba(0,0,0,.12);
    }
    .brand-mark i{
      width:100%; height:100%;
      display:flex; align-items:center; justify-content:center;
      background:#fff; border-radius:50%;
      color: var(--brand);
      font-size: 28px;
    }
    .form-control:focus { box-shadow: 0 0 0 .2rem rgba(99,91,255,.15); border-color:#b9bfff; }
    .btn-brand{
      background: linear-gradient(90deg, var(--brand) 0%, var(--brand-2) 100%);
      border: none;
    }
    .btn-brand:hover{ filter: brightness(0.97); }
    .input-group-text { background:#f6f7fb; }
    .small-muted { color:#6b7280; font-size:.9rem; }
  </style>
</head>
<body>

<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-sm-10 col-md-8 col-lg-5">
      <div class="login-card shadow-sm">

        <div class="login-header p-3 d-flex align-items-center gap-3">
          <div class="brand-mark"><i class="bi bi-person-lines-fill"></i></div>
          <div>
            <h5 class="mb-0">Customer Portal</h5>
            <div class="small-muted">Sign in with your PPPoE credentials</div>
          </div>
        </div>

        <div class="p-4 p-md-5">
          <?php if ($err): ?>
            <div class="alert alert-danger d-flex align-items-start" role="alert">
              <i class="bi bi-exclamation-triangle-fill me-2"></i>
              <div><?= h($err) ?></div>
            </div>
          <?php endif; ?>

          <form method="post" autocomplete="off" novalidate>
            <input type="hidden" name="csrf" value="<?= h($_SESSION['portal_csrf'] ?? '') ?>">

            <div class="mb-3">
              <label class="form-label fw-semibold">PPPoE ID</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-person-badge"></i></span>
                <input type="text" name="username" class="form-control" placeholder="e.g. johndoe123" required autofocus>
              </div>
            </div>

            <div class="mb-2">
              <label class="form-label fw-semibold">PPPoE Password</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-shield-lock"></i></span>
                <input type="password" name="password" id="pwd" class="form-control" placeholder="••••••••" required>
                <button class="btn btn-outline-secondary" type="button" id="togglePwd" title="Show/Hide">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-4">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" value="" id="remember" disabled>
                <label class="form-check-label small-muted" for="remember">Remember me (coming soon)</label>
              </div>
              <a href="#" class="small text-decoration-none" onclick="alert('Please contact your ISP support to reset PPPoE password.');return false;">Forgot password?</a>
            </div>

            <button class="btn btn-brand btn-primary w-100 py-2">
              <i class="bi bi-box-arrow-in-right me-1"></i> Login
            </button>
          </form>

          <hr class="my-4">
          <div class="small-muted">
            Need help? Contact +8801732197767.
          </div>
        </div>

      </div>
    </div>
  </div>
</div>

<script>
  // (বাংলা) পাসওয়ার্ড শো/হাইড টগ্‌ল
  document.getElementById('togglePwd')?.addEventListener('click', function(){
    const i = document.getElementById('pwd');
    const icon = this.querySelector('i');
    if(!i) return;
    if(i.type === 'password'){ i.type='text'; icon.classList.remove('bi-eye'); icon.classList.add('bi-eye-slash'); }
    else { i.type='password'; icon.classList.remove('bi-eye-slash'); icon.classList.add('bi-eye'); }
  });
</script>
</body>
</html>
