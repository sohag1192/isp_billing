<?php
// /public/login.php
// বাংলা নোট: সিকিউর সেশন + CSRF অপরিবর্তিত; UI = medium tone with softer gradients & higher image visibility

/* Secure session cookie params */
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

/* Already logged in? go dashboard */
if (isset($_SESSION['user_id'])) {
    header('Location: /public/index.php');
    exit;
}

/* CSRF token */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

/* Optional redirect after login */
$redir = isset($_GET['redir']) ? (string)$_GET['redir'] : '';

/* Optional error */
$error = isset($_GET['error']) ? (string)$_GET['error'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Login - ISP Billing</title>

<!-- Bootstrap & Icons -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.10.5/font/bootstrap-icons.min.css">

<!-- Medium, eye-comfort theme (DEDUPED & CLEANED) -->
<style>
/* ====== Tunable Variables (medium tone, softer gradients, more image) ====== */
:root{
  /* optional BG image (will blend softly if exists) */
  --bg-image-url: url('/assets/img/login-bg.jpg');

  /* Base + semi-transparent gradients so the image is more visible */
  --base: #101729;
  --g1: rgba(26, 90, 112, .38);   /* muted teal */
  --g2: rgba(59, 78, 150, .32);   /* muted indigo */
  --g3: rgba(36, 101, 72, .30);   /* muted green */

  /* Glass surface (slightly clearer) */
  --glass-bg: rgba(255, 255, 255, 0.18);
  --glass-border: rgba(255, 255, 255, 0.24);
  --shadow: 0 10px 24px rgba(0,0,0,.26);

  /* Text colors */
  --text-strong: #e9edf7;
  --text-soft: #c9d1e3;

  /* Inputs */
  --input-bg: rgba(255,255,255,.18);
  --input-border: rgba(255,255,255,.28);
  --input-focus: rgba(99,102,241,.20);

  /* Button (medium contrast) */
  --btn-g1: #6a7ecf;
  --btn-g2: #5a8fb8;
  --btn-g3: #57a884;
  --btn-shadow: 0 8px 18px rgba(87,168,132,.22);

  /* Aurora accents (very soft) */
  --aurora-1: rgba(255,255,255,.06);
  --aurora-2: rgba(255,255,255,.05);

  /* Show more of the image */
  --image-opacity: .32;

  /* Gradient link palette */
  --link-c1: #8ec5ff;
  --link-c2: #9ad0ff;
  --link-c3: #7ee7be;
  --link-underline: rgba(142,197,255,.65);
}

/* ====== Background ====== */
body.login-page{
  min-height: 100vh;
  margin: 0;
  color: var(--text-strong);
  background:
    radial-gradient(1100px 720px at 12% 12%, var(--g1), transparent 60%),
    radial-gradient(900px 640px at 88% 18%, var(--g2), transparent 60%),
    radial-gradient(1000px 700px at 50% 88%, var(--g3), transparent 60%),
    var(--base);
  background-attachment: fixed, fixed, fixed, fixed;
  position: relative;
  overflow: hidden;
}
body.login-page::before{
  content: "";
  position: fixed; inset: 0;
  background-image: var(--bg-image-url);
  background-size: cover;
  background-position: center;
  background-repeat: no-repeat;
  opacity: var(--image-opacity);
  mix-blend-mode: soft-light;
  pointer-events: none;
  z-index: 0;
}
body.login-page::after{
  content: "";
  position: fixed; inset: -20%;
  background:
    radial-gradient(55% 38% at 30% 12%, var(--aurora-1), transparent 70%),
    radial-gradient(42% 30% at 70% 82%, var(--aurora-2), transparent 70%);
  filter: blur(30px);
  animation: floaty 16s ease-in-out infinite alternate;
  pointer-events: none;
  z-index: 0;
}
@keyframes floaty{
  0%   { transform: translate3d(-1%, -0.6%, 0) scale(1); }
  100% { transform: translate3d(1%, 0.6%, 0)  scale(1.015); }
}

/* ====== Layout ====== */
.login-wrapper{
  position: relative;
  z-index: 1;
  min-height: 100vh;
  display: grid;
  place-items: center;
  padding: 24px;
}

/* ====== Glass Card ====== */
.card-glass{
  width: 100%;
  max-width: 440px;
  background: var(--glass-bg);
  border: 1px solid var(--glass-border);
  border-radius: 18px;
  box-shadow: var(--shadow);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  overflow: hidden;
  transition: transform .2s ease, box-shadow .2s ease;
}
.card-glass:hover{
  transform: translateY(-1px);
  box-shadow: 0 16px 34px rgba(0,0,0,.34);
}

/* Header badge/logo */
.brand-badge{
  width: 68px; height: 68px;
  border-radius: 50%;
  display: grid; place-items: center;
  background: linear-gradient(135deg, rgba(255,255,255,.16), rgba(255,255,255,.06));
  border: 1px solid rgba(255,255,255,.24);
  box-shadow: inset 0 1px 6px rgba(255,255,255,.18), 0 8px 16px rgba(0,0,0,.22);
  backdrop-filter: blur(6px);
  margin: 0 auto 10px auto;
}
.brand-badge i{ font-size: 26px; color: #f2f6ff; }

/* Titles */
h1.title{
  font-size: 1.28rem;
  font-weight: 700;
  color: var(--text-strong);
  text-align: center;
  margin: 6px 0 4px 0;
  letter-spacing: .25px;
}
p.sub{
  text-align: center; margin: 0 0 14px 0;
  color: var(--text-soft); font-size: .925rem;
}

/* Form controls */
.form-control, .input-group-text{
  background: var(--input-bg);
  color: var(--text-strong);
  border: 1px solid var(--input-border);
}
.form-control::placeholder{ color: #a7b1c7; opacity: .95; }
.form-control:focus{
  color: #f7f9ff;
  background: rgba(255,255,255,.24);
  border-color: #93a0de;
  box-shadow: 0 0 0 .2rem var(--input-focus);
}
.input-group-text{ color: #eef2fe; }

/* Button */
.btn-gradient{
  border: 0;
  color: #0f1627;
  font-weight: 700;
  letter-spacing: .25px;
  background-image: linear-gradient(135deg, var(--btn-g1), var(--btn-g2), var(--btn-g3));
  box-shadow: var(--btn-shadow);
}
.btn-gradient:hover{
  filter: brightness(1.02);
  transform: translateY(-1px);
}

/* Error alert */
.alert-custom{
  background: rgba(220,53,69,.12);
  color: #ffe1e6;
  border: 1px solid rgba(220,53,69,.24);
}

/* Eye button */
.eye-btn{
  background: var(--input-bg);
  color: var(--text-strong);
  border-left: 0;
}

/* Helper text */
.helper{ text-align:center; font-size:.85rem; color: var(--text-soft); }

/* Colorful gradient link (helper) */
.helper a{
  position: relative;
  font-weight: 700;
  text-decoration: none;
  color: #8ec5ff; /* fallback */
  background-image: linear-gradient(90deg, var(--link-c1), var(--link-c2), var(--link-c3));
  background-size: 200% 100%;
  transition: background-position .35s ease, transform .15s ease, color .2s ease;
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
}
.helper a::after{
  content:"";
  position:absolute; left:0; right:0; bottom:-2px; height:2px;
  border-radius:2px;
  background: linear-gradient(90deg, var(--link-c1), var(--link-c2), var(--link-c3));
  opacity:.55;
  transition: opacity .25s ease, transform .25s ease;
  transform: translateY(2px) scaleX(.9);
  transform-origin: left;
}
.helper a:hover,
.helper a:focus{
  background-position: 100% 0;
}
.helper a:hover::after,
.helper a:focus::after{
  opacity:.95;
  transform: translateY(0) scaleX(1);
}
/* Accessible focus ring */
.helper a:focus{
  outline: none;
  box-shadow: 0 0 0 .2rem rgba(142,197,255,.25);
  border-radius: 4px;
}

/* Mobile */
@media (max-width: 480px){
  .card-glass{ border-radius: 16px; }
}
</style>
</head>
<body class="login-page">

  <div class="login-wrapper container">
    <div class="card-glass p-4 p-md-4">

      <div class="brand-badge">
        <i class="bi bi-router-fill"></i>
      </div>
      <h1 class="title">ISP Billing</h1>
      <p class="sub">Sign in to continue</p>

      <?php if ($error !== ''): ?>
        <div class="alert alert-custom mb-3" role="alert">
          <i class="bi bi-exclamation-triangle-fill me-1"></i>
          <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
      <?php endif; ?>

      <form action="/app/auth.php" method="POST" class="login-form" autocomplete="on">
        <!-- CSRF -->
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <!-- Redirect hint (optional) -->
        <input type="hidden" name="redir" value="<?= htmlspecialchars($redir, ENT_QUOTES, 'UTF-8') ?>">

        <div class="mb-3 input-group">
          <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
          <input type="text" name="username" class="form-control" placeholder="Username" required autofocus autocomplete="username" maxlength="150">
        </div>

        <div class="mb-3 input-group">
          <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
          <input type="password" id="passwordField" name="password" class="form-control" placeholder="Password" required autocomplete="current-password" maxlength="200">
          <button class="btn eye-btn" type="button" id="toggleEye" aria-label="Toggle password visibility">
            <i class="bi bi-eye-slash" id="eyeIcon"></i>
          </button>
        </div>

        <button type="submit" class="btn btn-gradient w-100 py-2">
          <i class="bi bi-box-arrow-in-right me-1"></i> Login
        </button>
      </form>

      <div class="mt-3 helper">
        Need access? Contact with
        <a href="https://fb.com/bapa.swapon" target="_blank" rel="noopener noreferrer" aria-label="Open BAPA on Facebook">
          BAPA
        </a>
      </div>

    </div><!-- /.card-glass -->
  </div><!-- /.login-wrapper -->

<script>
/* বাংলা নোট: পাসওয়ার্ড শো/হাইড টগল */
(function(){
  const field = document.getElementById('passwordField');
  const btn   = document.getElementById('toggleEye');
  const icon  = document.getElementById('eyeIcon');
  if(!field || !btn || !icon) return;
  btn.addEventListener('click', function(){
    const isPass = field.getAttribute('type') === 'password';
    field.setAttribute('type', isPass ? 'text' : 'password');
    icon.classList.toggle('bi-eye');
    icon.classList.toggle('bi-eye-slash');
  });
})();
</script>
</body>
</html>
