<?= <<<HTML
<div class="container my-4">
  <div class="alert alert-warning bg-warning-subtle border-warning-subtle shadow-sm" role="alert">
    <div class="d-flex align-items-start">
      <div class="me-3">
        <svg xmlns="http://www.w3.org/2000/svg" width="56" height="56" viewBox="0 0 24 24" aria-hidden="true">
          <path fill="#f59f00" d="M1 21h22L12 2 1 21z"/>
          <path fill="#fff" d="M13 16h-2v2h2zm0-8h-2v6h2z"/>
        </svg>
      </div>
      <div class="flex-grow-1">
        <h5 class="mb-1">Action not permitted</h5>
        <p class="mb-2">
          You are not permitted to perform this action. Please contact your administrator.<br>
          
        <div class="d-flex flex-wrap gap-2">
          <a href="/public/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-house"></i> Back to Dashboard
          </a>
          <a href="/public/login.php" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-box-arrow-in-right"></i> Sign in as different user
          </a>
          <a href="mailto:swapon9124@gmail.com?subject=Access%20Request%3A%20add.router"
             class="btn btn-sm btn-warning">
            <i class="bi bi-key"></i> Request access
          </a>
        </div>
      </div>
    </div>
  </div>
</div>
HTML
?>
