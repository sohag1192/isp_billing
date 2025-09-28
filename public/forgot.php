<?php
// /public/forgot.php
// UI: English; Comments: বাংলা — Request password reset (email)

declare(strict_types=1);

$ROOT = dirname(__DIR__);
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','domain'=>'','secure'=>$https,'httponly'=>true,'samesite'=>'Lax']);
session_start();

// Already logged in? optional redirect
if (isset($_SESSION['user_id'])) {
  header('Location: /public/index.php'); exit;
}

// CSRF
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];

// Messages
$msg = (string)($_GET['msg'] ?? '');
$err = (string)($_GET['err'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Forgot Password - ISP Billing</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.10.5/font/bootstrap-icons.min.css">
</head>
<body class="bg-light">
<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
      <div class="card shadow-sm">
        <div class="card-body p-4">
          <h1 class="h5 mb-3"><i class="bi bi-key me-1"></i> Reset your password</h1>
          <p class="text-muted small">Enter your email. We’ll send a verification link and a 6-digit OTP.</p>

          <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
          <?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

          <form method="post" action="/public/forgot_submit.php" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <div class="mb-3">
              <label class="form-label">Email <span class="text-danger">*</span></label>
              <input type="email" name="email" class="form-control" required maxlength="200" placeholder="you@example.com" autocomplete="email">
            </div>
            <div class="d-grid gap-2">
              <button class="btn btn-primary" type="submit">
                <i class="bi bi-envelope-check me-1"></i> Send reset email
              </button>
              <a class="btn btn-outline-secondary" href="/public/login.php">
                <i class="bi bi-box-arrow-in-left me-1"></i> Back to sign in
              </a>
            </div>
          </form>

          <hr class="my-4">
          <p class="small text-muted mb-0">For security, we’ll show a generic success even if email doesn’t exist.</p>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
