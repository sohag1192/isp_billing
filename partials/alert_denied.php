<?php
// /partials/alert_denied.php
// UI: English; Comment: বাংলা
// Reusable "Access Denied / Not Permitted" card for include()

if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// Caller can set these before include:
$perm_key     = $perm_key     ?? ''; // e.g. 'add.router'
$admin_email  = $admin_email  ?? (defined('ADMIN_EMAIL') ? ADMIN_EMAIL : (defined('CONFIG_ADMIN_EMAIL') ? CONFIG_ADMIN_EMAIL : 'swapon9124@gmail.com'));
$warning_img  = $warning_img  ?? null; // e.g. '/assets/img/warning.png' (web path). If null -> show inline SVG
$return_href  = $return_href  ?? '/public/index.php';

$subject = rawurlencode('Access Request: ' . $perm_key);
?>
<div class="container my-4">
  <div class="alert alert-warning bg-warning-subtle border-warning-subtle shadow-sm" role="alert">
    <div class="d-flex align-items-start">
      <div class="me-3">
        <?php if (!empty($warning_img)): ?>
          <img src="<?= h($warning_img) ?>" width="56" height="56" class="img-fluid rounded shadow-sm" style="object-fit:contain" alt="Warning">
        <?php else: ?>
          <!-- Inline SVG fallback -->
          <svg xmlns="http://www.w3.org/2000/svg" width="56" height="56" viewBox="0 0 24 24" aria-hidden="true">
            <path fill="#f59f00" d="M1 21h22L12 2 1 21z"/>
            <path fill="#fff" d="M13 16h-2v2h2zm0-8h-2v6h2z"/>
          </svg>
        <?php endif; ?>
      </div>
      <div class="flex-grow-1">
        <h5 class="mb-1">Action not permitted</h5>
        <p class="mb-2">
          You are not permitted to perform this action. Please contact your administrator.<br>
          <?php if ($perm_key !== ''): ?>
            <small class="text-muted">Required permission: <code><?= h($perm_key) ?></code></small>
          <?php endif; ?>
        </p>
        <div class="d-flex flex-wrap gap-2">
          <a href="<?= h($return_href) ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-house"></i> Back
          </a>
          <a href="/public/login.php" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-box-arrow-in-right"></i> Sign in
          </a>
          <a href="mailto:<?= h($admin_email) ?>?subject=<?= $subject ?>"
             class="btn btn-sm btn-warning">
            <i class="bi bi-key"></i> Request access
          </a>
        </div>
      </div>
    </div>
  </div>
</div>
