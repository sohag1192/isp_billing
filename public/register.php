<?php
// /public/register.php
// UI: English; Comments: বাংলা — Self-registration with email verification

declare(strict_types=1);

$ROOT = dirname(__DIR__);
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','domain'=>'','secure'=>$https,'httponly'=>true,'samesite'=>'Lax']);
session_start();

// Already logged in? go dashboard
if (isset($_SESSION['user_id'])) {
  header('Location: /public/index.php'); exit;
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Optional error/success
$msg  = (string)($_GET['msg']  ?? '');
$err  = (string)($_GET['err']  ?? '');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Register - ISP Billing</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.10.5/font/bootstrap-icons.min.css">
</head>
<body class="bg-light">
<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
      <div class="card shadow-sm">
        <div class="card-body p-4">
          <h1 class="h4 mb-3"><i class="bi bi-person-plus me-1"></i> Create Admin Account</h1>
          <p class="text-muted small mb-4">An email verification is required to activate your account.</p>

          <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg,ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>
          <?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err,ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>

          <form method="post" action="/public/register_submit.php" autocomplete="on" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf,ENT_QUOTES,'UTF-8') ?>">

            <div class="mb-3">
              <label class="form-label">Full Name <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" required maxlength="150" placeholder="Your name">
            </div>

            <div class="mb-3">
              <label class="form-label">Email <span class="text-danger">*</span></label>
              <input type="email" name="email" class="form-control" required maxlength="200" placeholder="you@example.com" autocomplete="email">
            </div>

            <div class="mb-3">
              <label class="form-label">Password <span class="text-danger">*</span></label>
              <input type="password" name="password" class="form-control" required minlength="6" maxlength="200" autocomplete="new-password">
              <div class="form-text">Minimum 6 characters.</div>
            </div>

            <div class="d-grid gap-2">
              <button class="btn btn-primary" type="submit">
                <i class="bi bi-envelope-check me-1"></i> Register & Send Verification
              </button>
              <a href="/public/login.php" class="btn btn-outline-secondary">
                <i class="bi bi-box-arrow-in-left me-1"></i> Back to Sign in
              </a>
            </div>
          </form>

          <hr class="my-4">
          <p class="small text-muted">
            Didn’t get the email? Check spam folder. OTP is valid for 15 minutes.
          </p>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
