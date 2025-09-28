<?php
// /public/403.php
http_response_code(403);
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Forbidden</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="bg-light">
<div class="container py-5">
  <div class="alert alert-danger shadow-sm">
    <h5 class="mb-1"><i class="bi bi-shield-lock"></i> Access denied</h5>
    <div>You do not have permission to perform this action.</div>
  </div>
  <a class="btn btn-outline-secondary" href="javascript:history.back()">Go back</a>
</div>
</body></html>
