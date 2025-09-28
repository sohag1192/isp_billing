<?php
// /app/csrf_compat.php
// Code: English; Comments: Bangla
// Goal: CSRF compatibility across different token names/keys

if (session_status() === PHP_SESSION_NONE) session_start();

function _csrf_session_candidates(): array { return ['csrf','csrf_token','_csrf','token']; }
function _csrf_request_candidates(): array { return ['_csrf','csrf_token','csrf','token']; }

// বর্তমান সেশনের টোকেন (যে নামেই থাকুক) ফেরত দাও
function csrf_session_token(): ?string {
  foreach (_csrf_session_candidates() as $k) {
    if (!empty($_SESSION[$k]) && is_string($_SESSION[$k])) return $_SESSION[$k];
  }
  return null;
}

// দরকার হলে নতুন টোকেন বানিয়ে সেশনে বসাও (canonical: $_SESSION['csrf'])
function csrf_ensure_token(): string {
  $t = csrf_session_token();
  if (!$t) {
    $t = bin2hex(random_bytes(16));
    $_SESSION['csrf'] = $t;
  }
  return $t;
}

// ফর্ম/রিকোয়েস্ট থেকে আসা টোকেন নাও (যে নামেই আসুক)
function csrf_request_token(): ?string {
  foreach (_csrf_request_candidates() as $k) {
    if (isset($_POST[$k]) && is_string($_POST[$k]) && $_POST[$k] !== '') return $_POST[$k];
    if (isset($_GET[$k])  && is_string($_GET[$k])  && $_GET[$k]  !== '') return $_GET[$k];
  }
  return null;
}

// Hidden input (canonical name: csrf_token)
function csrf_input_html(): string {
  $t = csrf_ensure_token();
  return '<input type="hidden" name="csrf_token" value="'.htmlspecialchars($t, ENT_QUOTES, 'UTF-8').'">';
}

// true/false verify
function csrf_verify(): bool {
  $given  = csrf_request_token();
  $stored = csrf_session_token();
  return ($given && $stored && hash_equals((string)$stored, (string)$given));
}

// verify or redirect back with flash
function csrf_verify_or_redirect(string $redirectUrl='/'): void {
  if (csrf_verify()) return;
  $_SESSION['flash_error'] = 'Invalid CSRF token.';
  header('Location: '.$redirectUrl);
  exit;
}
