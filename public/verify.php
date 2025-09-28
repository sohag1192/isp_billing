<?php
// /public/verify.php
// বাংলা: টোকেন/OTP ভেরিফাই → users টেবিলে admin ইউজার তৈরি → ACL roles থাকলে ম্যাপ → সেশন লগইন

declare(strict_types=1);
require_once dirname(__DIR__) . '/app/db.php';

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','domain'=>'','secure'=>$https,'httponly'=>true,'samesite'=>'Lax']);
session_start();

$token = trim((string)($_GET['token'] ?? ''));
$otp   = trim((string)($_POST['otp'] ?? ($_GET['otp'] ?? '')));

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // Load pending registration
  if ($token === '' && $otp === '') throw new RuntimeException('Missing token/OTP');

  if ($token !== '') {
    $st = $pdo->prepare("SELECT * FROM user_registrations WHERE token=? AND consumed=0");
    $st->execute([$token]);
  } else {
    $st = $pdo->prepare("SELECT * FROM user_registrations WHERE otp=? AND consumed=0");
    $st->execute([$otp]);
  }
  $reg = $st->fetch(PDO::FETCH_ASSOC);
  if (!$reg) throw new RuntimeException('Invalid or already used verification.');

  if (new DateTime($reg['expires_at']) < new DateTime()) {
    throw new RuntimeException('OTP expired. Please register again.');
  }

  // === Create user in `users` (schema-aware) ===
  $usersCols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);

  // Pick columns
  $usernameCol = in_array('username',$usersCols,true) ? 'username' : (in_array('user_name',$usersCols,true) ? 'user_name' : null);
  $emailCol    = in_array('email',$usersCols,true)    ? 'email'    : null;
  $nameCol     = in_array('name',$usersCols,true)     ? 'name'     : (in_array('full_name',$usersCols,true) ? 'full_name' : null);
  $passCol     = in_array('password',$usersCols,true) ? 'password' : (in_array('pass_hash',$usersCols,true) ? 'pass_hash' : null);
  $roleCol     = in_array('role',$usersCols,true)     ? 'role'     : null;
  $isAdminCol  = in_array('is_admin',$usersCols,true) ? 'is_admin' : null;
  $isActiveCol = in_array('is_active',$usersCols,true)? 'is_active': (in_array('status',$usersCols,true) ? 'status' : null);
  $createdCol  = in_array('created_at',$usersCols,true)?'created_at': (in_array('created',$usersCols,true) ? 'created' : null);

  if (!$passCol) throw new RuntimeException('users table needs password/pass_hash column.');

  // Prepare values
  $username = $emailCol ? $reg['email'] : $reg['email']; // fallback: email as username
  $email    = $reg['email'];
  $name     = $reg['name'];
  $hash     = $reg['pass_hash'];
  $now      = (new DateTime())->format('Y-m-d H:i:s');

  // Build INSERT dynamically
  $cols = []; $phs = []; $vals = [];
  if ($usernameCol){ $cols[]="`$usernameCol`"; $phs[]='?'; $vals[]=$username; }
  if ($emailCol)  { $cols[]="`$emailCol`";   $phs[]='?'; $vals[]=$email; }
  if ($nameCol)   { $cols[]="`$nameCol`";    $phs[]='?'; $vals[]=$name; }
  $cols[]="`$passCol`"; $phs[]='?'; $vals[]=$hash;
  if ($roleCol)   { $cols[]="`$roleCol`";    $phs[]='?'; $vals[]='admin'; }
  if ($isAdminCol){ $cols[]="`$isAdminCol`"; $phs[]='?'; $vals[]=1; }
  if ($isActiveCol){$cols[]="`$isActiveCol`";$phs[]='?'; $vals[]=( ($isActiveCol==='status') ? 'active' : 1 );}
  if ($createdCol){ $cols[]="`$createdCol`"; $phs[]='?'; $vals[]=$now; }

  $sql = "INSERT INTO users (".implode(',',$cols).") VALUES (".implode(',',$phs).")";
  $stI = $pdo->prepare($sql);
  $stI->execute($vals);
  $userId = (int)$pdo->lastInsertId();

  // === Grant admin role/permissions if ACL tables exist ===
  try {
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $hasRoles = in_array('roles',$tables,true);
    $hasUserRoles = in_array('user_roles',$tables,true);
    if ($hasRoles && $hasUserRoles) {
      // Ensure admin role exists
      $st = $pdo->prepare("SELECT id FROM roles WHERE slug='admin' OR name='admin' LIMIT 1");
      $st->execute(); $roleId = (int)($st->fetchColumn() ?: 0);
      if (!$roleId) {
        $pdo->prepare("INSERT INTO roles(name,slug) VALUES('Admin','admin')")->execute();
        $roleId = (int)$pdo->lastInsertId();
      }
      // Map user to admin role
      $st = $pdo->prepare("INSERT IGNORE INTO user_roles(user_id, role_id) VALUES(?,?)");
      $st->execute([$userId, $roleId]);
      // Optionally, if permissions table exists, ensure wildcard admin
      if (in_array('permissions',$tables,true) && in_array('role_permissions',$tables,true)) {
        // Insert a '*' permission if missing
        $st = $pdo->prepare("SELECT id FROM permissions WHERE slug='*' LIMIT 1");
        $st->execute(); $permId = (int)($st->fetchColumn() ?: 0);
        if (!$permId) {
          $pdo->prepare("INSERT INTO permissions(name,slug) VALUES('All','*')")->execute();
          $permId = (int)$pdo->lastInsertId();
        }
        $pdo->prepare("INSERT IGNORE INTO role_permissions(role_id, permission_id) VALUES(?,?)")->execute([$roleId, $permId]);
      }
    }
  } catch (Throwable $e) {
    // বাংলা: ACL না থাকলেও সমস্যা নয়; users.is_admin থাকলে সেটাই যথেষ্ট
  }

  // Consume registration
  $pdo->prepare("UPDATE user_registrations SET consumed=1 WHERE id=?")->execute([$reg['id']]);

  // Login session
  $_SESSION['user_id'] = $userId;
  $_SESSION['username'] = $username;
  $_SESSION['role'] = 'admin';

  header('Location: /public/index.php');
  exit;

} catch (Throwable $e) {
  // Minimal UI for OTP input if token missing or user wants OTP method
  $err = $e->getMessage();
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Verify - ISP Billing</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
      <div class="card shadow-sm">
        <div class="card-body p-4">
          <h1 class="h5 mb-3">Email Verification</h1>
          <?php if (!empty($err)): ?>
            <div class="alert alert-danger"><?= h($err) ?></div>
          <?php endif; ?>
          <form method="post" action="/public/verify.php">
            <div class="mb-3">
              <label class="form-label">Enter OTP</label>
              <input type="text" name="otp" class="form-control" maxlength="6" placeholder="6-digit code" required>
            </div>
            <button class="btn btn-primary" type="submit">Verify</button>
            <a href="/public/register.php" class="btn btn-outline-secondary">Back</a>
          </form>
          <p class="small text-muted mt-3">You can also verify using the link in your email.</p>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
