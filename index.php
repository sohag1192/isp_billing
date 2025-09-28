<?php
// /index.php
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ISP Billing — Login Portal</title>
  <meta name="theme-color" content="#0b132b">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{
      --fg:#e2e8f0;
      --muted:#94a3b8;
      --glass:rgba(255,255,255,.08);
      --stroke:rgba(255,255,255,.15);
      --shadow:0 20px 50px rgba(0,0,0,.4);
    }
    html,body{height:100%;}
    body{
      margin:0; min-height:100vh;
      display:flex; align-items:center; justify-content:center; /* 👈 এখন center */
      padding-top:2vh;  /* 👈 সামান্য নিচে */
      color:var(--fg);
      background:
        linear-gradient(rgba(0,0,0,.55), rgba(0,0,0,.55)),
        url("/assets/images/bg.jpg") center/cover no-repeat fixed;
    }
    .glass{
      background:var(--glass);
      border:1px solid var(--stroke);
      border-radius:1.2rem;
      box-shadow:var(--shadow);
      backdrop-filter: blur(4px);
      -webkit-backdrop-filter: blur(4px);
    }
    .brand{
      font-weight:800; letter-spacing:.2px;
      background: linear-gradient(90deg,#d1d5db,#a5b4fc,#67e8f9);
      -webkit-background-clip:text; background-clip:text; color:transparent;
    }
    .btn-tile{
      border-radius:1rem; padding:.9rem 1.1rem; border-width:2px;
      transition:transform .08s ease, box-shadow .2s ease, background-color .2s ease, border-color .2s ease;
    }
    .btn-tile:active{ transform:translateY(1px) scale(.99); }
    .btn-admin{
      color:#0b132b; background:#e2e8f0; border-color:#e2e8f0;
    }
    .btn-admin:hover{ background:#cbd5e1; border-color:#cbd5e1; }
    .btn-client{
      color:#e2e8f0; border-color:#6b7280;
      background:rgba(255,255,255,.03);
    }
    .btn-client:hover{ background:rgba(255,255,255,.07); border-color:#94a3b8; }
    .kbd{
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      border:1px solid var(--stroke); border-radius:.5rem; padding:.05rem .4rem; font-size:.8rem;
      color:var(--muted);
    }
    .foot{ color:var(--muted); font-size:.85rem; }
  </style>
</head>
<body>

  <main class="container px-3">
    <div class="row justify-content-center">
      <div class="col-12 col-sm-10 col-md-8 col-lg-6">
        <section class="glass p-4 p-md-4 text-center">
          <div class="mb-2">
            <i class="bi bi-wifi" style="font-size:2rem;opacity:.9;"></i>
          </div>
          <h3 class="brand mb-2">ISP Billing & Client Management</h3>
    

          <div class="d-grid gap-3">
            <a href="/public/login.php" class="btn btn-tile btn-admin d-flex align-items-center justify-content-center gap-2">
              <i class="bi bi-shield-lock"></i>
              <span class="fw-semibold">Admin Login</span>
              <span class="kbd d-none d-sm-inline">A</span>
            </a>

            <a href="/public/portal/" class="btn btn-tile btn-client d-flex align-items-center justify-content-center gap-2">
              <i class="bi bi-person-badge"></i>
              <span class="fw-semibold">Client Login</span>
              <span class="kbd d-none d-sm-inline">C</span>
            </a>
          </div>


  <div class="d-flex justify-content-between align-items-center mt-3">
        <a class="btn btn-sm btn-outline-light" href="/public/register.php">
          <i class="bi bi-person-plus me-1"></i>Register admin account
        </a>
        <a class="btn btn-sm btn-outline-light" href="/public/login.php">
          <i class="bi bi-shield-lock me-1"></i> Sign in
        </a>
     


    <a class="btn btn-sm btn-outline-light" href="/public/reset.php">
          <i class="bi bi-shield-lock me-1"></i> Reset Password
        </a>
      </div>
 
    </div>
  </main>
  

  <script>
    // Keyboard shortcuts: A (Admin), C (Client)
    document.addEventListener('keydown', function(e){
      const k = (e.key || '').toLowerCase();
      if (k === 'a') { window.location.href = '/public/'; }
      if (k === 'c') { window.location.href = '/public/portal/'; }
    });
  </script>
</body>
</html>
